<?php
/*
 * TDC Supply Room – shared server code
 *
 * Used by api.php (the page), blob.php (product photos) and mcp.php (Claude).
 * Data is a small JSON document store on top of MySQL (or SQLite for local
 * testing). Settings live in config.php (copy config.sample.php). Tables are
 * created automatically on first use.
 */

declare(strict_types=1);

const COLLECTIONS = ['items', 'log', 'orders', 'reps', 'counts', 'supinfo', 'settings', 'prices'];
const COOKIE      = 'tdc_supply_auth';
const TOKEN_DAYS  = 90;
const MAX_FAILS   = 10;      // failed sign-ins per IP …
const FAIL_WINDOW = 900;     // … per 15 minutes
const MAX_DOC     = 262144;  // 256 KB per document
const MAX_BLOB    = 6291456; // 6 MB per photo

function out(int $code, array $body): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function fail(int $code, string $error, string $message): void {
    out($code, ['ok' => false, 'error' => $error, 'message' => $message]);
}

/* ---------- configuration ---------- */
function load_config(): void {
    $cfgFile = __DIR__ . '/config.php';
    if (!is_file($cfgFile)) {
        fail(500, 'not_configured', 'The Supply Room is not set up yet: create config.php from config.sample.php.');
    }
    require_once $cfgFile;
    if (!defined('OFFICE_PASSWORD') || (string)OFFICE_PASSWORD === '' || OFFICE_PASSWORD === 'change-me') {
        fail(500, 'not_configured', 'Set OFFICE_PASSWORD in config.php.');
    }
    date_default_timezone_set((string)cfg('TIMEZONE', 'America/Los_Angeles'));
}
function cfg(string $name, $default = '') { return defined($name) ? constant($name) : $default; }

/* ---------- database ---------- */
// Shared hosting limits how many database connections can be open at once, so a
// busy moment is retried a few times before giving up.
function connect(): PDO {
    $pdo = null; $lastErr = null;
    for ($try = 0; $try < 6 && $pdo === null; $try++) {
        if ($try > 0) usleep(random_int(150000, 350000) * $try);
        try {
            if (cfg('DB_DRIVER') === 'sqlite') {
                $pdo = new PDO('sqlite:' . DB_SQLITE_PATH);
            } else {
                $pdo = new PDO(
                    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                    DB_USER, DB_PASS,
                    [PDO::ATTR_EMULATE_PREPARES => false]
                );
            }
        } catch (Throwable $e) { $lastErr = $e; $pdo = null; }
    }
    if ($pdo === null) {
        $msg = $lastErr ? $lastErr->getMessage() : '';
        // 1040 too many connections / 1203 max_user_connections: busy, not misconfigured
        if (preg_match('/\b(1040|1203)\b|max_user_connections|Too many connections/i', $msg)) {
            fail(503, 'db_busy', 'The database is busy. Try again in a moment.');
        }
        fail(500, 'db_connect', 'Could not connect to the database. Check the DB_ settings in config.php.');
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    try { ensure_schema($pdo); }
    catch (Throwable $e) { error_log('tdc schema: ' . $e->getMessage()); fail(500, 'db_schema', 'Could not set up the database tables.'); }
    return $pdo;
}
function is_sqlite(PDO $pdo): bool { return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'; }

function ensure_schema(PDO $pdo): void {
    $cs = is_sqlite($pdo) ? '' : ' DEFAULT CHARSET=utf8mb4';
    $blob = is_sqlite($pdo) ? 'BLOB' : 'LONGBLOB';
    $pdo->exec("CREATE TABLE IF NOT EXISTS sr_docs (
        col VARCHAR(32) NOT NULL,
        id VARCHAR(191) NOT NULL,
        data LONGTEXT NOT NULL,
        at_key VARCHAR(64) NULL,
        rev BIGINT NOT NULL DEFAULT 0,
        PRIMARY KEY (col, id)
    )$cs");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sr_meta (
        k VARCHAR(32) NOT NULL PRIMARY KEY,
        v BIGINT NOT NULL DEFAULT 0
    )$cs");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sr_tokens (
        token_hash CHAR(64) NOT NULL PRIMARY KEY,
        pw_fp CHAR(16) NOT NULL,
        expires BIGINT NOT NULL
    )$cs");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sr_fails (
        ip VARCHAR(64) NOT NULL,
        ts BIGINT NOT NULL
    )$cs");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sr_blobs (
        id CHAR(32) NOT NULL PRIMARY KEY,
        mime VARCHAR(64) NOT NULL,
        bytes $blob NOT NULL,
        created BIGINT NOT NULL
    )$cs");
    // Indexes: ignore "already exists" so this stays safe to run every time.
    foreach (['CREATE INDEX sr_docs_at ON sr_docs (col, at_key)',
              'CREATE INDEX sr_fails_ip ON sr_fails (ip, ts)'] as $sql) {
        try { $pdo->exec($sql); } catch (Throwable $e) { /* exists */ }
    }
    $has = $pdo->query("SELECT COUNT(*) AS n FROM sr_meta WHERE k = 'rev'")->fetch();
    if ((int)$has['n'] === 0) $pdo->exec("INSERT INTO sr_meta (k, v) VALUES ('rev', 0)");
}

/* ---------- sign-in (page) ---------- */
function pw_fp(): string { return substr(hash('sha256', 'tdc-supply|' . OFFICE_PASSWORD), 0, 16); }
function client_ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64); }
function is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}
function set_cookie(string $value, int $expires): void {
    setcookie(COOKIE, $value, [
        'expires' => $expires, 'path' => '/', 'secure' => is_https(),
        'httponly' => true, 'samesite' => 'Strict',
    ]);
}
function signed_in(PDO $pdo): bool {
    $t = $_COOKIE[COOKIE] ?? '';
    if (!is_string($t) || !preg_match('/^[a-f0-9]{64}$/', $t)) return false;
    $st = $pdo->prepare('SELECT pw_fp, expires FROM sr_tokens WHERE token_hash = ?');
    $st->execute([hash('sha256', $t)]);
    $row = $st->fetch();
    // A changed office password signs every device out (pw_fp no longer matches).
    return $row && $row['pw_fp'] === pw_fp() && (int)$row['expires'] > time();
}

/* ---------- documents ---------- */
function col_ok($c): string {
    if (!is_string($c) || !in_array($c, COLLECTIONS, true)) fail(400, 'invalid_argument', 'Unknown collection.');
    return $c;
}
function id_ok($id): string {
    if (!is_string($id) || !preg_match('/^[A-Za-z0-9_\-.~:@+]{1,191}$/', $id) || $id === '.' || $id === '..') {
        fail(400, 'invalid_argument', 'Bad document id.');
    }
    return $id;
}
function data_ok($d): array {
    if (!is_array($d) || (count($d) > 0 && array_is_list($d))) fail(400, 'invalid_argument', 'Document must be an object.');
    return $d;
}
function enc(array $d): string {
    $j = json_encode((object)$d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($j === false || strlen($j) > MAX_DOC) fail(400, 'invalid_argument', 'Document too large.');
    return $j;
}
// "at" (a time stamp, number or text) is the one field that can be sorted and filtered.
function at_key(array $d): ?string {
    if (!isset($d['at'])) return null;
    if (is_int($d['at']) || is_float($d['at'])) return sprintf('%020d', (int)$d['at']);
    if (is_string($d['at'])) return substr($d['at'], 0, 64);
    return null;
}
function at_arg($v): string { return (is_int($v) || is_float($v)) ? sprintf('%020d', (int)$v) : (string)$v; }
function bump(PDO $pdo): int {
    $pdo->exec("UPDATE sr_meta SET v = v + 1 WHERE k = 'rev'");
    return (int)$pdo->query("SELECT v FROM sr_meta WHERE k = 'rev'")->fetch()['v'];
}
function current_rev(PDO $pdo): int { return (int)$pdo->query("SELECT v FROM sr_meta WHERE k = 'rev'")->fetch()['v']; }
function load(PDO $pdo, string $col, string $id): ?array {
    $st = $pdo->prepare('SELECT data FROM sr_docs WHERE col = ? AND id = ?');
    $st->execute([$col, $id]);
    $r = $st->fetch();
    return $r ? (json_decode($r['data'], true) ?: []) : null;
}
function save_raw(PDO $pdo, string $col, string $id, array $d): int {
    $rev = bump($pdo);
    $st = $pdo->prepare('SELECT COUNT(*) AS n FROM sr_docs WHERE col = ? AND id = ?');
    $st->execute([$col, $id]);
    if ((int)$st->fetch()['n'] > 0) {
        $pdo->prepare('UPDATE sr_docs SET data = ?, at_key = ?, rev = ? WHERE col = ? AND id = ?')->execute([enc($d), at_key($d), $rev, $col, $id]);
    } else {
        $pdo->prepare('INSERT INTO sr_docs (col, id, data, at_key, rev) VALUES (?, ?, ?, ?, ?)')->execute([$col, $id, enc($d), at_key($d), $rev]);
    }
    return $rev;
}
function save(PDO $pdo, string $col, string $id, array $d): int {
    if ($pdo->inTransaction()) return save_raw($pdo, $col, $id, $d);
    $pdo->beginTransaction();
    try { $rev = save_raw($pdo, $col, $id, $d); $pdo->commit(); return $rev; }
    catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}
function remove(PDO $pdo, string $col, string $id): int {
    $rev = bump($pdo);
    $pdo->prepare('DELETE FROM sr_docs WHERE col = ? AND id = ?')->execute([$col, $id]);
    return $rev;
}
function new_id(): string { return substr(bin2hex(random_bytes(10)), 0, 20); }
function all_docs(PDO $pdo, string $col): array {
    $st = $pdo->prepare('SELECT id, data FROM sr_docs WHERE col = ? ORDER BY id');
    $st->execute([$col]);
    $res = [];
    foreach ($st->fetchAll() as $row) $res[$row['id']] = json_decode($row['data'], true) ?: [];
    return $res;
}

function read_get(PDO $pdo, array $r): array {
    $col = col_ok($r['col'] ?? null); $id = id_ok($r['id'] ?? null);
    $d = load($pdo, $col, $id);
    return ['exists' => $d !== null, 'data' => $d === null ? null : (object)$d];
}
function read_query(PDO $pdo, array $r): array {
    $col = col_ok($r['col'] ?? null);
    $sql = 'SELECT id, data FROM sr_docs WHERE col = ?'; $args = [$col];
    foreach (($r['where'] ?? []) as $w) {
        if (!is_array($w) || count($w) !== 3 || $w[0] !== 'at') fail(400, 'invalid_argument', 'Unsupported filter.');
        $ops = ['<' => '<', '<=' => '<=', '>' => '>', '>=' => '>=', '==' => '='];
        if (!isset($ops[$w[1]])) fail(400, 'invalid_argument', 'Unsupported filter.');
        $sql .= ' AND at_key IS NOT NULL AND at_key ' . $ops[$w[1]] . ' ?'; $args[] = at_arg($w[2]);
    }
    $ob = $r['orderBy'] ?? null;
    if ($ob !== null) {
        if (!is_array($ob) || ($ob[0] ?? '') !== 'at') fail(400, 'invalid_argument', 'Unsupported order.');
        $sql .= ' ORDER BY at_key ' . ((($ob[1] ?? 'asc') === 'desc') ? 'DESC' : 'ASC') . ', id';
    } else {
        $sql .= ' ORDER BY id';
    }
    $lim = max(1, min(2000, (int)($r['limit'] ?? 2000)));
    $sql .= ' LIMIT ' . $lim;
    $st = $pdo->prepare($sql); $st->execute($args);
    $docs = [];
    foreach ($st->fetchAll() as $row) $docs[] = ['id' => $row['id'], 'data' => (object)(json_decode($row['data'], true) ?: [])];
    return ['docs' => $docs];
}

/* ---------- photos ---------- */
const IMG_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
function blob_put(PDO $pdo, string $bytes, string $mime, ?string $id = null): string {
    if ($bytes === '' || strlen($bytes) > MAX_BLOB) fail(400, 'invalid_argument', 'That photo is too large (6 MB at most).');
    $mime = strtolower(trim(explode(';', $mime)[0]));
    if (!in_array($mime, IMG_TYPES, true)) $mime = sniff_mime($bytes) ?? '';
    if (!in_array($mime, IMG_TYPES, true)) fail(400, 'invalid_argument', 'Only JPEG, PNG, WebP or GIF pictures can be saved.');
    if ($id === null || !preg_match('/^[a-f0-9]{32}$/', $id)) $id = bin2hex(random_bytes(16));
    $pdo->prepare('DELETE FROM sr_blobs WHERE id = ?')->execute([$id]);
    $st = $pdo->prepare('INSERT INTO sr_blobs (id, mime, bytes, created) VALUES (?, ?, ?, ?)');
    $st->bindValue(1, $id); $st->bindValue(2, $mime); $st->bindValue(3, $bytes, PDO::PARAM_LOB); $st->bindValue(4, time());
    $st->execute();
    return $id;
}
function sniff_mime(string $b): ?string {
    if (str_starts_with($b, "\xFF\xD8\xFF")) return 'image/jpeg';
    if (str_starts_with($b, "\x89PNG")) return 'image/png';
    if (str_starts_with($b, 'GIF8')) return 'image/gif';
    if (substr($b, 0, 4) === 'RIFF' && substr($b, 8, 4) === 'WEBP') return 'image/webp';
    return null;
}

/* ---------- fetching web pages and pictures (server side) ---------- */
// Only public http(s) addresses: never the server itself or the local network.
function public_url(string $url): bool {
    $p = parse_url($url);
    if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host'])) return false;
    $host = trim($p['host'], '[]');
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
    if (!$ips) return false;
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
    }
    return true;
}
function http_get(string $url, int $max = 4194304, int $timeout = 20, array $headers = []): array {
    $hops = 0;
    while (true) {
        $host = (string)(parse_url($url, PHP_URL_HOST) ?? '');
        if ($host !== '' && !filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) && !gethostbynamel($host)) return ['ok' => false, 'error' => 'Couldn\'t find the web address ' . $host . '.'];
        if (!public_url($url)) return ['ok' => false, 'error' => 'That address isn\'t a public web page.'];
        $ch = curl_init($url);
        $buf = '';
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_ENCODING => '',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36',
            CURLOPT_HTTPHEADER => array_merge(['Accept: text/html,application/xhtml+xml,image/avif,image/webp,image/*,*/*;q=0.8', 'Accept-Language: en-US,en;q=0.9'], $headers),
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$buf, $max) {
                $buf .= $chunk;
                return strlen($buf) > $max ? 0 : strlen($chunk);
            },
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $loc = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $err = curl_errno($ch) && strlen($buf) <= $max ? curl_error($ch) : '';
        curl_close($ch);
        if ($code >= 300 && $code < 400 && $loc !== '' && $hops++ < 5) { $url = $loc; continue; }
        if ($err !== '') return ['ok' => false, 'error' => 'Couldn\'t reach that page (' . $err . ').'];
        return ['ok' => $code >= 200 && $code < 300, 'code' => $code, 'type' => $type, 'body' => $buf, 'url' => $url,
                'error' => $code >= 200 && $code < 300 ? '' : "The site answered with error $code."];
    }
}

/* ---------- Claude (Anthropic API) ---------- */
function ai_ready(): bool { return trim((string)cfg('ANTHROPIC_API_KEY')) !== ''; }
// $images: list of ['type' => mime, 'data' => base64]; PDFs ('application/pdf') are sent as documents
// $opt: maxFiles (default 4), maxTokens (default 4096), timeout in seconds (default 90)
function ai_json(string $prompt, array $images = [], string $tier = '', array $opt = []): array {
    if (!ai_ready()) return ['ok' => false, 'error' => 'no_key', 'message' => 'Claude isn\'t set up on this server yet: add ANTHROPIC_API_KEY to config.php.'];
    $model = $tier === 'quick' ? (string)cfg('ANTHROPIC_MODEL_QUICK', 'claude-haiku-5-5') : (string)cfg('ANTHROPIC_MODEL', 'claude-sonnet-5-5');
    $content = [];
    foreach (array_slice($images, 0, (int)($opt['maxFiles'] ?? 4)) as $im) {
        $mime = strtolower((string)($im['type'] ?? ''));
        if (!is_string($im['data'] ?? null)) continue;
        if ($mime === 'application/pdf') $content[] = ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $im['data']]];
        elseif (in_array($mime, IMG_TYPES, true)) $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $im['data']]];
    }
    $content[] = ['type' => 'text', 'text' => $prompt . "\n\nReply with only the JSON, no other text."];
    // A long fixed part (instructions + our inventory) goes first and is cached, so a run of invoices only pays for it once.
    if (is_string($opt['cache'] ?? null) && $opt['cache'] !== '') array_unshift($content, ['type' => 'text', 'text' => $opt['cache'], 'cache_control' => ['type' => 'ephemeral']]);
    $body = json_encode([
        'model' => $model, 'max_tokens' => (int)($opt['maxTokens'] ?? 4096),
        'messages' => [['role' => 'user', 'content' => $content]],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($body === false) return ['ok' => false, 'error' => 'bad_request', 'message' => 'The page text couldn\'t be sent to Claude.'];
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => (int)($opt['timeout'] ?? 90),
        CURLOPT_HTTPHEADER => ['content-type: application/json', 'anthropic-version: 2023-06-01', 'x-api-key: ' . cfg('ANTHROPIC_API_KEY')],
    ]);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    if ($res === false) return ['ok' => false, 'error' => 'unavailable', 'message' => 'Couldn\'t reach Claude (' . $cerr . ').'];
    $j = json_decode((string)$res, true);
    if ($code !== 200 || !is_array($j)) {
        $m = is_array($j) ? (string)($j['error']['message'] ?? '') : '';
        error_log("tdc ai: $code $m");
        $map = [401 => 'Claude rejected the API key in config.php.', 429 => 'Claude is busy right now. Try again in a minute.', 529 => 'Claude is busy right now. Try again in a minute.'];
        return ['ok' => false, 'error' => $code === 429 || $code === 529 ? 'rate_limited' : 'unavailable', 'message' => $map[$code] ?? ('Claude couldn\'t answer' . ($m ? ": $m" : '.'))];
    }
    $text = '';
    foreach ($j['content'] ?? [] as $c) if (($c['type'] ?? '') === 'text') $text .= $c['text'];
    $val = parse_json_reply($text);
    if ($val === null) return ['ok' => false, 'error' => 'bad_reply', 'message' => 'Claude\'s answer couldn\'t be read.'];
    return ['ok' => true, 'value' => $val];
}
function parse_json_reply(string $t) {
    $t = trim($t);
    $t = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $t);
    $v = json_decode($t, true);
    if ($v !== null) return $v;
    foreach (['{' => '}', '[' => ']'] as $o => $c) {
        $a = strpos($t, $o); $b = strrpos($t, $c);
        if ($a !== false && $b !== false && $b > $a) { $v = json_decode(substr($t, $a, $b - $a + 1), true); if ($v !== null) return $v; }
    }
    return null;
}

/* ---------- reading a product page ---------- */
/* Pictures that are never the product: site chrome, award / rating badges, payment and certification seals. */
function junk_image(string $u): bool {
    $p = strtolower(rawurldecode((string)parse_url($u, PHP_URL_PATH)));
    if (preg_match('/\.svg$|5[_-]?star|five[_-]?star|great[_-]?place|no[_-]?image|reality_20\d\d/', $p) || preg_match('/no[_-]?image|placeholder/i', (string)parse_url($u, PHP_URL_QUERY))) return true;
    $w = preg_split('/[^a-z]+/', $p, -1, PREG_SPLIT_NO_EMPTY);
    return (bool)array_intersect($w, ['logo', 'logos', 'sprite', 'sprites', 'icon', 'icons', 'favicon', 'badge', 'badges', 'banner', 'banners', 'pixel', 'spacer',
        'award', 'awards', 'rating', 'ratings', 'townie', 'certified', 'trustpilot', 'payment', 'payments', 'paypal', 'visa', 'mastercard', 'flag', 'flags', 'placeholder', 'noimage']);
}
/* Image servers that resize by query (?w=80): ask for the full-size picture instead of a thumbnail. */
function full_size_image(string $u): string {
    if (preg_match('#^https?://images\.ctfassets\.net/#i', $u)) return preg_replace('/\?.*$/', '', $u) . '?w=1000&fm=jpg';
    return $u;
}
function page_facts(string $html, string $base, string $sku = ''): array {
    $f = ['title' => '', 'description' => '', 'images' => [], 'primary' => [], 'jsonld' => [], 'text' => '', 'skus' => [], 'skuImages' => []];
    // Some supplier pages aren't valid UTF-8; clean them so the text tools below don't fail.
    if (!mb_check_encoding($html, 'UTF-8')) {
        $cs = preg_match('/<meta[^>]+charset=["\']?([A-Za-z0-9_-]+)/i', $html, $cm) ? strtoupper($cm[1]) : 'Windows-1252';
        $conv = @mb_convert_encoding($html, 'UTF-8', in_array($cs, mb_list_encodings(), true) ? $cs : 'Windows-1252');
        $html = is_string($conv) && mb_check_encoding($conv, 'UTF-8') ? $conv : mb_scrub($html, 'UTF-8');
    }
    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) $f['title'] = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5));
    if (preg_match_all('/<meta\s+[^>]*>/i', $html, $ms)) {
        foreach ($ms[0] as $tag) {
            $name = strtolower(attr($tag, 'property') ?: attr($tag, 'name') ?: attr($tag, 'itemprop'));
            $val = html_entity_decode(attr($tag, 'content'), ENT_QUOTES | ENT_HTML5);
            if ($val === '') continue;
            if (in_array($name, ['og:image', 'og:image:secure_url', 'twitter:image', 'image'], true)) $f['images'][] = abs_url($val, $base);
            if (in_array($name, ['og:description', 'description', 'twitter:description'], true) && $f['description'] === '') $f['description'] = $val;
            if ($name === 'og:title' && $f['title'] === '') $f['title'] = $val;
            if (in_array($name, ['product:price:amount', 'og:price:amount', 'price'], true)) $f['jsonld'][] = ['metaPrice' => $val];
        }
    }
    if (preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $ms)) {
        foreach ($ms[1] as $raw) {
            $j = json_decode(trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5)), true) ?? json_decode(trim($raw), true);
            if (!is_array($j)) continue;
            foreach (flatten_ld($j) as $node) {
                $t = $node['@type'] ?? '';
                $t = is_array($t) ? implode(',', $t) : (string)$t;
                if (stripos($t, 'Product') === false) continue;
                $keep = array_intersect_key($node, array_flip(['name', 'sku', 'mpn', 'gtin', 'gtin12', 'gtin13', 'brand', 'description', 'offers', 'image', 'model']));
                $f['jsonld'][] = $keep;
                foreach ((array)($node['image'] ?? []) as $im) {
                    $u = is_array($im) ? ($im['url'] ?? $im['contentUrl'] ?? '') : $im;
                    if (is_string($u) && $u !== '') $f['images'][] = abs_url($u, $base);
                }
            }
        }
    }
    $f['primary'] = array_values(array_unique(array_filter($f['images'], fn($u) => preg_match('#^https?://#i', $u))));
    // Large product-looking <img> tags, in page order.
    if (preg_match_all('/<img\s[^>]*>/i', $html, $ms)) {
        foreach ($ms[0] as $tag) {
            $set = attr($tag, 'data-srcset') ?: attr($tag, 'srcset');
            $big = $set !== '' ? trim(explode(' ', trim(explode(',', $set)[count(explode(',', $set)) - 1]))[0]) : '';
            $src = attr($tag, 'data-zoom-image') ?: attr($tag, 'data-large') ?: attr($tag, 'data-original') ?: attr($tag, 'data-lazy') ?: attr($tag, 'data-src') ?: $big ?: attr($tag, 'src');
            if ($src === '' || str_starts_with($src, 'data:') || junk_image(abs_url($src, $base))) continue;
            $f['images'][] = abs_url(html_entity_decode($src, ENT_QUOTES | ENT_HTML5), $base);
            if (count($f['images']) > 30) break;
        }
    }
    // Picture addresses anywhere in the page (galleries often load them from scripts) that contain the
    // product's number from the link, e.g. .../7770454 -> .../7770454_US_Large.jpg
    if (preg_match('#(\d{5,})(?:[/?\#]|$)#', $base, $pm) && preg_match_all('#(?:https?:)?//[^\s"\'<>()\\\\]+?\.(?:jpe?g|png|webp)(?:\?[^\s"\'<>()\\\\]*)?|/[A-Za-z0-9_\-./%]+?\.(?:jpe?g|png|webp)#i', $html, $am)) {
        foreach ($am[0] as $u) {
            if (!str_contains($u, $pm[1]) || junk_image($u)) continue;
            $u = abs_url(html_entity_decode(str_starts_with($u, '//') ? 'https:' . $u : $u, ENT_QUOTES | ENT_HTML5), $base);
            if (!in_array($u, $f['primary'], true)) $f['primary'][] = $u;
            if (count($f['primary']) > 12) break;
        }
        // larger versions first when the site offers sizes in the name
        usort($f['primary'], fn($a, $b) => (preg_match('/large|zoom|xl|big|original|1200|1000|800/i', $b) <=> preg_match('/large|zoom|xl|big|original|1200|1000|800/i', $a)));
    }
    // Pictures named after the item we want (…/HJH4305.jpg), wherever they are on the page: on pages listing a
    // whole size range they are the only way to tell this row's photo from the others.
    $f['skus'] = sku_tokens($html);
    // some stores (Safco) list the variants' manufacturer part numbers only in the keywords meta tag
    if (preg_match('#<meta\s+name=["\']keywords["\']\s+content=["\']([^"\']*)#i', $html, $kw))
        foreach (preg_split('/[\s,;]+/', html_entity_decode($kw[1], ENT_QUOTES)) as $k) { $n = sku_norm($k); if (strlen($n) >= 4 && preg_match('/\d/', $n)) $f['skus'][$n] = true; }
    $want = sku_norm($sku);
    if ($want !== '' && preg_match_all('#(?:https?:)?//[^\s"\'<>()]+?\.(?:jpe?g|png|webp)(?:\?[^\s"\'<>()]*)?|/[A-Za-z0-9_\-./%]+?\.(?:jpe?g|png|webp)#i', $html, $sm)) {
        foreach ($sm[0] as $u) {
            $stem = preg_replace('/\.\w+$/', '', rawurldecode(basename((string)parse_url($u, PHP_URL_PATH))));
            $parts = array_map('sku_norm', preg_split('/[_\s]+/', $stem) ?: []);
            if (sku_norm($stem) !== $want && !in_array($want, $parts, true)) continue;
            $f['skuImages'][] = abs_url(html_entity_decode(str_starts_with($u, '//') ? 'https:' . $u : $u, ENT_QUOTES | ENT_HTML5), $base);
        }
        $f['skuImages'] = array_values(array_unique($f['skuImages']));
        $f['primary'] = array_values(array_unique(array_merge($f['skuImages'], $f['primary'])));
        $f['images'] = array_merge($f['skuImages'], $f['images']);
    }
    $f['images'] = array_values(array_unique(array_map('full_size_image', array_filter($f['images'], fn($u) => preg_match('#^https?://#i', $u) && !junk_image($u)))));
    $f['images'] = array_slice($f['images'], 0, 25);
    $f['primary'] = array_values(array_unique(array_map('full_size_image', array_filter($f['primary'], fn($u) => !junk_image($u)))));
    $t = preg_replace('/<(script|style|noscript|svg|template|iframe)\b.*?<\/\1>/is', ' ', $html) ?? $html;
    $t = preg_replace('/<(br|\/p|\/div|\/li|\/tr|\/h\d)[^>]*>/i', "\n", $t) ?? $t;
    $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5);
    $t = preg_replace("/[ \t\x{00A0}]+/u", ' ', $t) ?? $t;
    $t = preg_replace("/\s*\n\s*/", "\n", $t) ?? $t;
    $f['text'] = mb_substr(trim($t), 0, 24000);
    return $f;
}
function flatten_ld($j): array {
    $out = [];
    if (!is_array($j)) return $out;
    if (array_is_list($j)) { foreach ($j as $x) $out = array_merge($out, flatten_ld($x)); return $out; }
    $out[] = $j;
    if (isset($j['@graph'])) $out = array_merge($out, flatten_ld($j['@graph']));
    return $out;
}
function attr(string $tag, string $name): string {
    if (!preg_match('/\s' . preg_quote($name, '/') . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $tag, $m)) return '';
    return (string)(($m[1] ?? '') !== '' ? $m[1] : ((($m[2] ?? '') !== '') ? $m[2] : ($m[3] ?? '')));
}
function abs_url(string $u, string $base): string {
    $u = trim($u);
    if (preg_match('#^https?://#i', $u)) return $u;
    $b = parse_url($base);
    if (!$b || empty($b['host'])) return $u;
    $root = ($b['scheme'] ?? 'https') . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
    if (str_starts_with($u, '//')) return ($b['scheme'] ?? 'https') . ':' . $u;
    if (str_starts_with($u, '/')) return $root . $u;
    $dir = preg_replace('#/[^/]*$#', '/', $b['path'] ?? '/');
    return $root . $dir . $u;
}
function fetch_image(string $url, string $referer = '', ?string &$why = null): ?array {
    // Ask for formats we can store; some image servers (Henry Schein's) send AVIF to anyone who lists it or image/*.
    $h = ['Accept: image/jpeg,image/png,image/webp;q=0.9,image/gif;q=0.8'];
    if ($referer !== '') $h[] = 'Referer: ' . $referer;
    $r = http_get($url, MAX_BLOB, 20, $h);
    if (!$r['ok']) { $why = $r['error'] ?: 'no answer'; return null; }
    $mime = sniff_mime($r['body']) ?? strtolower(trim(explode(';', $r['type'])[0]));
    if ($mime === 'image/avif' || $mime === 'image/heic') {
        $r2 = http_get($url, MAX_BLOB, 20, array_merge(['Accept: image/jpeg'], array_slice($h, 1)));
        $m2 = $r2['ok'] ? (sniff_mime($r2['body']) ?? strtolower(trim(explode(';', $r2['type'])[0]))) : '';
        if (in_array($m2, IMG_TYPES, true)) { $r = $r2; $mime = $m2; }
        elseif ($mime === 'image/avif' && function_exists('imagecreatefromavif')) {
            $tmp = tempnam(sys_get_temp_dir(), 'av'); file_put_contents($tmp, $r['body']);
            $gd = @imagecreatefromavif($tmp); @unlink($tmp);
            if ($gd) { ob_start(); imagejpeg($gd, null, 88); $r['body'] = (string)ob_get_clean(); imagedestroy($gd); $mime = 'image/jpeg'; }
        }
    }
    if (!in_array($mime, IMG_TYPES, true)) { $why = 'not a picture (' . ($mime ?: 'unknown') . ')'; return null; }
    if (strlen($r['body']) < 2500) { $why = 'too small (' . strlen($r['body']) . ' bytes)'; return null; }   // tiny = icon or tracking pixel
    return ['type' => $mime, 'bytes' => $r['body']];
}

/*
 * Reads a supplier's product page and returns what the Add item page fills in:
 * names[], sku, mfrNumber, price, units, description, supplier, url, photo (type + base64), note.
 */
// Product page reads are kept for a day, so a retry or the same item in another batch doesn't pay twice.
function read_product_link(string $url, array $ctx = [], bool $quick = false): array {
    $dir = sys_get_temp_dir() . '/tdc-pages';
    $key = $dir . '/' . hash('sha256', 'v7|' . $url . '|' . json_encode($ctx) . '|' . (int)$quick) . '.json';
    if (is_file($key) && filemtime($key) > time() - 86400) { $c = json_decode((string)@file_get_contents($key), true); if (is_array($c) && !empty($c['ok'])) return $c; }
    $r = read_product_link_live($url, $ctx, $quick);
    if (!empty($r['ok'])) { if (!is_dir($dir)) @mkdir($dir, 0700, true); @file_put_contents($key, json_encode($r, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)); }
    if (mt_rand(1, 50) === 1) foreach ((array)@glob($dir . '/*.json') as $f) if (@filemtime($f) < time() - 86400) @unlink($f);
    return $r;
}
function read_product_link_live(string $url, array $ctx = [], bool $quick = false): array {
    $am = amazon_url($url);
    if ($am !== null && apify_ready()) {
        // Amazon blocks servers from reading its pages, so Amazon links go through Apify's Amazon scraper.
        $got = amazon_facts($am);
        if (!$got['ok']) return $got;
        $f = $got['facts']; $finalUrl = $got['url'];
    } elseif (($bz = brasseler_facts($url)) !== null) {
        $f = $bz; $finalUrl = $url;
    } else {
        $page = http_get($url);
        $looksBlocked = !$page['ok'] ? in_array($page['code'] ?? 0, [401, 403, 429, 503], true) : bot_wall($page['body']);
        if ($looksBlocked && apify_ready()) {
            // The site turned the server away: open it in a real browser through Apify instead.
            $got = apify_page($url, $quick);
            if (!$got['ok']) return $got;
            $page = ['ok' => true, 'body' => $got['html'], 'url' => $got['url'], 'type' => 'text/html'];
        } elseif (!$page['ok']) {
            return ['ok' => false, 'error' => $looksBlocked ? 'blocked' : 'unreachable',
                    'message' => $looksBlocked ? 'The supplier\'s site didn\'t let the server read that page.' : ($page['error'] ?: 'Couldn\'t open that page.')];
        } elseif ($looksBlocked) {
            return ['ok' => false, 'error' => 'blocked', 'message' => 'The supplier\'s site showed a robot check instead of the product. Add APIFY_TOKEN to config.php to read sites like this.'];
        }
        if (stripos($page['type'], 'html') === false && stripos($page['type'], 'xml') === false) {
            return ['ok' => false, 'error' => 'not_page', 'message' => 'That link isn\'t a web page.'];
        }
        $f = page_facts($page['body'], $page['url'], (string)($ctx['sku'] ?? ''));
        $finalUrl = $page['url'];
    }
    $res = ['url' => $finalUrl, 'pageTitle' => $f['title'], 'names' => [], 'sku' => '', 'mfrNumber' => '', 'price' => null,
            'units' => '', 'description' => $f['description'], 'supplier' => '', 'note' => '', 'photo' => null, 'photoNote' => '', 'skuCheck' => ''];
    // Does the page actually show the SKU we're after? ('' when we weren't given one, or for Amazon)
    $wantSku = trim((string)($ctx['sku'] ?? ''));
    if ($wantSku !== '' && $am === null) $res['skuCheck'] = sku_in($f['skus'] ?? [], $wantSku) ? 'found' : 'missing';
    // Without Claude: use what the page states in its product data.
    foreach ($f['jsonld'] as $p) {
        if (!empty($p['name']) && !$res['names']) $res['names'][] = is_string($p['name']) ? $p['name'] : '';
        if (!empty($p['sku']) && !$res['sku']) $res['sku'] = (string)$p['sku'];
        if (!empty($p['mpn']) && !$res['mfrNumber']) $res['mfrNumber'] = (string)$p['mpn'];
        if (!empty($p['description']) && is_string($p['description'])) $res['description'] = $p['description'];
        $o = $p['offers'] ?? null;
        if (is_array($o)) { $o = array_is_list($o) ? ($o[0] ?? []) : $o; $pr = $o['price'] ?? $o['lowPrice'] ?? null; if (is_numeric($pr) && $res['price'] === null) $res['price'] = (float)$pr; }
        if (isset($p['metaPrice']) && is_numeric($p['metaPrice']) && $res['price'] === null) $res['price'] = (float)$p['metaPrice'];
    }
    $pick = 0;
    if (ai_ready()) {
        $ld = json_encode(array_slice($f['jsonld'], 0, 4), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $imgs = implode("\n", array_map(fn($i, $u) => "$i: $u", array_keys($f['images']), $f['images']));
        $prompt = "You are adding a product to a dental office's supply inventory from a supplier's product web page.\n"
            . "Page address: {$finalUrl}\nPage title: {$f['title']}\n"
            . (!empty($ctx['name']) ? "The office calls it: \"{$ctx['name']}\"\n" : '')
            . (!empty($ctx['sku']) ? "Expected SKU: {$ctx['sku']}\n"
                . "The page may list several sizes or variants (for example a table of item numbers with prices). Describe ONLY the variant whose item number is {$ctx['sku']} (ignore dashes, spaces and leading zeros): its name, size, price, units and photo. "
                . ($res['skuCheck'] === 'missing' ? "Item {$ctx['sku']} does not appear on this page: say so in note, and leave sku empty unless the page is clearly this exact product under another number.\n" : "\n") : '')
            . "Structured product data on the page: " . mb_substr($ld ?: '[]', 0, 6000) . "\n"
            . "Pictures on the page (index: address):\n" . ($imgs ?: '(none)') . "\n"
            . (!empty($f['primary']) ? "The page itself marks these as the product picture: " . implode(', ', array_slice($f['primary'], 0, 4)) . "\n" : '') . "\n"
            . "Page text:\n" . $f['text'] . "\n\n"
            . (!empty($ctx['nameRule']) ? $ctx['nameRule'] . "\n\n" : '')
            . "Reply with one JSON object: {\"names\": [up to 3 short inventory names, best first], \"sku\": \"the supplier's item / catalog number\", "
            . "\"mfrNumber\": \"manufacturer part number if shown\", \"price\": number or null (the price for one unit as sold; " . (!empty($ctx['mpn']) ? "if the page shows a list or regular price and says your own price needs sign-in, give the list price; null only if no price is shown at all" : "null if no price is shown or it needs sign-in") . "), "
            . "\"units\": \"what one unit is, e.g. Box of 100, Each, 5 mL vial\", \"description\": \"one or two plain sentences on what it is and what it's used for\", "
            . "\"supplier\": \"the store's name\", \"photo\": index of the main product photo from the list, or -1, \"note\": \"anything the office should double-check, or empty\"}";
        $a = ai_json($prompt);
        if ($a['ok'] && is_array($a['value'])) {
            $v = $a['value'];
            if (!empty($v['names']) && is_array($v['names'])) $res['names'] = array_values(array_filter(array_map('strval', $v['names'])));
            foreach (['sku', 'mfrNumber', 'units', 'description', 'supplier', 'note'] as $k) if (isset($v[$k]) && is_scalar($v[$k]) && trim((string)$v[$k]) !== '') $res[$k] = trim((string)$v[$k]);
            if (isset($v['price']) && is_numeric($v['price'])) $res['price'] = (float)$v['price'];
            if (isset($v['photo']) && is_numeric($v['photo'])) $pick = (int)$v['photo'];
        } else {
            $res['note'] = $a['message'] ?? '';
        }
    }
    // Check what Claude read against the SKU we asked for.
    if ($res['skuCheck'] === 'found') {
        if ($res['sku'] === '') $res['sku'] = $wantSku;
        elseif (sku_norm($res['sku']) !== sku_norm($wantSku) && stripos(sku_norm($res['mfrNumber']), sku_norm($wantSku)) === false)
            $res['note'] = trim('This page lists item ' . $wantSku . ', but the details read are for ' . $res['sku'] . '; check the size and price. ' . $res['note']);
    } elseif ($res['skuCheck'] === 'missing') {
        $res['note'] = trim('Item ' . $wantSku . ' isn\'t shown on this page, so it may be a different product or size. ' . $res['note']);
    }
    // Price comparison: does the page show the manufacturer part number we're matching on?
    $wantMpn = trim((string)($ctx['mpn'] ?? ''));
    if ($wantMpn !== '') {
        $n = sku_norm($wantMpn);
        $read = sku_norm($res['mfrNumber']);
        if ($n !== '' && ($read === $n || sku_norm($res['sku']) === $n)) $res['mpnCheck'] = 'found';
        // the page's own product has a different part number (the one we want only shows in "related products"): not this product
        elseif ($read !== '' && !str_contains($read, $n) && !str_contains($n, $read)) $res['mpnCheck'] = 'missing';
        else $res['mpnCheck'] = (sku_in($f['skus'] ?? [], $wantMpn) || sku_in(sku_tokens(json_encode($f['jsonld'] ?? []) . ' ' . ($f['title'] ?? '')), $wantMpn)) ? 'found' : 'missing';
    }
    if ($am !== null && apify_ready()) $res['supplier'] = 'Amazon';
    // Claude's pick first, then the pictures the page marks as the product's, then the rest of the page's pictures.
    $primary = $f['primary'] ?? [];
    // The page's own product picture (og:image / product data) wins over Claude's pick from the gallery, which on some
    // sites is a clinical or lifestyle shot; Claude's pick still comes before the rest of the gallery.
    $order = $pick >= 0 && isset($f['images'][$pick]) ? array_merge($primary, [$f['images'][$pick]], $f['images']) : array_merge($primary, $pick < 0 ? [] : $f['images']);
    // Front-of-box shots first (Henry Schein names them ..._front_01), back/side shots last; Claude's pick leads its group.
    $skuImgs = array_flip($f['skuImages'] ?? []);
    $rank = fn($u) => isset($skuImgs[$u]) ? -1 : (preg_match('/front|main|hero|primary/i', basename((string)parse_url($u, PHP_URL_PATH))) ? 0 : (preg_match('/back|side|top|bottom|label|detail|group|lifestyle|spec|clinical|step[-_]?\d|history|procedure|technique|before|after|case[-_]stud/i', basename((string)parse_url($u, PHP_URL_PATH))) ? 2 : 1));
    $order = array_values(array_unique($order));
    $pos = array_flip($order);
    usort($order, fn($a, $b) => [$rank($a), $pos[$a]] <=> [$rank($b), $pos[$b]]);
    // the same picture is often listed twice (7770454_US_Top_05.jpg and 7770454_US_Top_05_-Name.jpg)
    $seen = []; $order = array_values(array_filter($order, function($u) use (&$seen) { $k = strtolower(preg_replace('/_-[^\/]*(\.\w+)(\?.*)?$/', '$1', $u)); if (isset($seen[$k])) return false; return $seen[$k] = true; }));
    $tried = [];
    $order = array_slice($order, 0, 12);
    foreach ($order as $k => $u) {
        $why = '';
        $im = fetch_image($u, $url, $why);
        if ($im) { $res['photo'] = ['type' => $im['type'], 'data' => base64_encode($im['bytes'])]; $res['photoUrl'] = $u; $res['morePhotos'] = array_slice($order, $k + 1, 10); break; }
        $tried[] = mb_substr($u, 0, 160) . ' → ' . $why;
        if (count($tried) >= 10) break;
    }
    $res['pageUrl'] = $url;
    if (!$res['photo']) {
        $res['photoNote'] = 'The server couldn\'t download a product photo from that page.';
        $res['photoDiag'] = $tried ? 'Pictures tried: ' . implode('; ', $tried) : 'The page didn\'t list any product pictures the server could see (they may be loaded by the page\'s scripts).';
    }
    if ($res['price'] === null) $res['priceNote'] = 'No price on the page (it may only show after signing in to your supplier account).';
    if (!$res['names'] && $f['title'] !== '') $res['names'][] = $f['title'];
    return ['ok' => true] + $res;
}

/* ---------- inventory rules (same as the page) ---------- */
/* Brasseler's shop (shop.brasselerusa.com/Product/<item>) draws its pages with scripts, so the server reads
   the shop's own product API instead: name, item number, pack and the 900x900 product photo. Prices need sign-in. */
function brasseler_facts(string $url): ?array {
    if (!preg_match('#^https?://shop\.brasselerusa\.com/Product/([^/?\#]+)#i', $url, $m)) return null;
    $seg = rawurldecode($m[1]);
    $r = http_get('https://shop.brasselerusa.com/api/v1/products?query=' . rawurlencode($seg), 2097152, 20, ['Accept: application/json']);
    $j = $r['ok'] ? json_decode($r['body'], true) : null;
    if (!is_array($j) || empty($j['products'])) return null;
    $p = null;
    foreach ($j['products'] as $c) if (strcasecmp((string)($c['urlSegment'] ?? ''), $seg) === 0 || strcasecmp((string)($c['erpNumber'] ?? ''), $seg) === 0) { $p = $c; break; }
    if (!$p) return null;
    $title = trim(preg_replace('/\s+/', ' ', (string)($p['shortDescription'] ?? '')));
    $imgs = [];
    foreach ((array)($p['productImages'] ?? []) as $im) if (!empty($im['largeImagePath'])) $imgs[] = (string)$im['largeImagePath'];
    foreach (['largeImagePath', 'mediumImagePath'] as $k) if (!empty($p[$k])) $imgs[] = (string)$p[$k];
    $imgs = array_values(array_unique(array_filter($imgs, fn($u) => preg_match('#^https?://#i', $u) && !junk_image($u))));
    $price = (float)($p['pricing']['unitNetPrice'] ?? 0) ?: (float)($p['basicListPrice'] ?? 0);
    $ld = ['name' => $title, 'sku' => (string)($p['erpNumber'] ?? $seg), 'mpn' => (string)($p['manufacturerItem'] ?? '')];
    if ($price > 0) $ld['offers'] = ['price' => $price];
    $text = implode("\n", array_filter([$title, 'Item number: ' . ($p['erpNumber'] ?? $seg), ($p['erpDescription'] ?? '') ? 'Catalog description: ' . $p['erpDescription'] : '',
        ($p['unitOfMeasureDisplay'] ?? '') ? 'Sold by: ' . $p['unitOfMeasureDisplay'] : '', ($p['packDescription'] ?? '') ? 'Pack: ' . $p['packDescription'] : '',
        ($p['modelNumber'] ?? '') ? 'Product line: ' . $p['modelNumber'] : '', strip_tags((string)($p['htmlContent'] ?? '')),
        $price > 0 ? '' : 'Price: shown only after signing in.']));
    return ['title' => $title, 'description' => '', 'images' => $imgs, 'primary' => $imgs, 'jsonld' => [$ld], 'text' => $text, 'skus' => [sku_norm((string)($p['erpNumber'] ?? $seg))], 'skuImages' => []];
}
function numv($v): ?float {
    if ($v === null || $v === '') return null;
    if (is_numeric($v)) return (float)$v;
    if (is_string($v) && preg_match('/-?\d+(\.\d+)?/', str_replace(',', '', $v), $m)) return (float)$m[0];
    return null;
}
function derive(array $it, int $expDays = 60): array {
    $q = numv($it['qty'] ?? null); $des = numv($it['desired'] ?? null); $urg = numv($it['urgent'] ?? null);
    $is911 = $urg !== null && $urg > 0 && $q !== null && $q <= $urg;
    $short = $q !== null && (($des !== null && $q < $des) || $is911);
    $manual = (numv($it['orderReq'] ?? null) ?? 0) > 0;
    $onOrder = !empty($it['ordered']);
    $need = ($short || $manual) && !$onOrder;
    $oq = 0;
    if ($need) $oq = max($short && $des !== null ? $des - $q : 0, $short && $is911 ? $urg - $q + 1 : 0, $manual ? numv($it['orderReq']) : 0, 1);
    if ($need && (numv($it['orderAmt'] ?? null) ?? 0) > 0) $oq = numv($it['orderAmt']);   // amount set with Order +/- on the Order list
    $exp = parse_exp((string)($it['exp'] ?? ''));
    $days = $exp ? (int)round(($exp - strtotime('today')) / 86400) : null;
    return ['need' => $need, 'orderQty' => round((float)$oq, 2), 'is911' => $is911, 'onOrder' => $onOrder,
            'expDays' => $days, 'expiring' => $days !== null && $days <= $expDays];
}
function parse_exp(string $v): ?int {
    $v = trim($v);
    if ($v === '' || strtolower($v) === 'n/a') return null;
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{2,4})$#', $v, $m)) { $y = (int)$m[3]; if ($y < 100) $y += 2000; return mktime(0, 0, 0, (int)$m[1], (int)$m[2], $y) ?: null; }
    if (preg_match('#^(\d{1,2})/(\d{2,4})$#', $v, $m)) { $y = (int)$m[2]; if ($y < 100) $y += 2000; return mktime(0, 0, 0, (int)$m[1] + 1, 0, $y) ?: null; }
    if (preg_match('#^(\d{4})-(\d{1,2})(?:-(\d{1,2}))?$#', $v, $m)) return isset($m[3]) ? mktime(0, 0, 0, (int)$m[2], (int)$m[3], (int)$m[1]) : mktime(0, 0, 0, (int)$m[2] + 1, 0, (int)$m[1]);
    $t = strtotime($v); return $t ?: null;
}
function today_str(): string { return date('n/j/Y'); }

/* ---------- Amazon, through Apify (apify.com, actor junglee/amazon-crawler) ---------- */
function apify_ready(): bool { return trim((string)cfg('APIFY_TOKEN')) !== ''; }
// Returns a clean https://www.amazon.<tld>/dp/<ASIN> for Amazon product links (short a.co / amzn.to links are kept as given).
function amazon_url(string $url): ?string {
    $p = parse_url(preg_match('#^https?://#i', $url) ? $url : 'https://' . $url);
    $host = strtolower($p['host'] ?? '');
    if (in_array($host, ['a.co', 'amzn.to', 'amzn.com', 'www.amzn.com'], true)) return 'https://' . $host . ($p['path'] ?? '');
    if (!preg_match('/(^|\.)amazon\.([a-z.]{2,6})$/', $host, $hm)) return null;
    if (preg_match('#/(?:dp|gp/product|gp/aw/d|product)/([A-Z0-9]{10})(?:[/?]|$)#i', ($p['path'] ?? '') . '/', $m)) return 'https://www.amazon.' . $hm[2] . '/dp/' . strtoupper($m[1]);
    return 'https://' . $host . ($p['path'] ?? '');
}
function amazon_facts(string $url): array {
    @set_time_limit(200);
    $actor = (string)cfg('APIFY_ACTOR', 'junglee~amazon-crawler');
    $input = [
        'categoryOrProductUrls' => [['url' => $url]],
        'maxItemsPerStartUrl' => 1,
        'maxOffers' => 0,
        'maxProductVariantsAsSeparateResults' => 0,
        'scrapeSellers' => false,
        'scrapeProductVariantPrices' => false,
        'scrapeProductDetails' => true,
        'proxyCountry' => 'AUTO_SELECT_PROXY_COUNTRY',
    ];
    $ch = curl_init('https://api.apify.com/v2/acts/' . rawurlencode($actor) . '/run-sync-get-dataset-items?timeout=150&format=json&clean=true');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 170, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_POSTFIELDS => json_encode($input, JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . cfg('APIFY_TOKEN')],
    ]);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) return ['ok' => false, 'error' => 'unreachable', 'message' => 'Couldn\'t reach Apify (' . $err . ').'];
    $j = json_decode((string)$res, true);
    if ($code < 200 || $code >= 300 || !is_array($j)) {
        $m = is_array($j) ? (string)($j['error']['message'] ?? '') : '';
        error_log("tdc apify: $code $m");
        $msg = $code === 401 ? 'Apify rejected the APIFY_TOKEN in config.php.'
             : ($code === 402 || stripos($m, 'credit') !== false || stripos($m, 'usage') !== false ? 'Apify refused the run (' . $code . ($m ? ': ' . $m : '') . ').'
             : ($code === 408 ? 'Amazon took too long to answer. Try again.' : 'Apify couldn\'t read that Amazon page' . ($m ? ": $m" : '.')));
        return ['ok' => false, 'error' => 'unavailable', 'message' => $msg];
    }
    $it = (array_is_list($j) ? ($j[0] ?? null) : $j);
    if (!is_array($it) || empty($it['title'])) return ['ok' => false, 'error' => 'no_match', 'message' => 'Apify didn\'t find a product at that Amazon link.'];
    // Biggest picture first; Amazon image addresses carry a size code (._AC_SX300_.) that can be dropped for the full size.
    $imgs = [];
    foreach (['highResolutionImages', 'galleryThumbnails', 'images'] as $k) foreach ((array)($it[$k] ?? []) as $u) if (is_string($u)) $imgs[] = $u;
    if (!empty($it['thumbnailImage']) && is_string($it['thumbnailImage'])) { $imgs[] = preg_replace('/\._[A-Za-z0-9,_-]+_\.(jpg|jpeg|png|webp)$/i', '.$1', $it['thumbnailImage']); $imgs[] = $it['thumbnailImage']; }
    $price = $it['price'] ?? null;
    $pv = is_array($price) ? ($price['value'] ?? null) : $price;
    $keep = array_intersect_key($it, array_flip(['title', 'asin', 'brand', 'price', 'listPrice', 'description', 'features', 'breadCrumbs', 'productOverview', 'attributes', 'variantAttributes', 'manufacturer', 'url']));
    $text = json_encode($keep, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    return ['ok' => true, 'url' => (string)($it['url'] ?? $url), 'facts' => [
        'title' => (string)$it['title'],
        'description' => is_string($it['description'] ?? null) ? $it['description'] : '',
        'images' => array_values(array_unique($imgs)),
        'primary' => array_values(array_unique($imgs)),
        'jsonld' => [['name' => (string)$it['title'], 'sku' => (string)($it['asin'] ?? ''), 'description' => is_string($it['description'] ?? null) ? $it['description'] : '',
                      'offers' => is_numeric($pv) ? ['price' => (float)$pv] : null]],
        'text' => mb_substr("Amazon product data:\n" . $text, 0, 24000),
    ]];
}

/* Robot checks served with a normal "200 OK" (Cloudflare, Akamai, Amazon and similar). */
function bot_wall(string $html): bool {
    if (strlen($html) > 400000) return false;
    $markers = ['cf-browser-verification', 'challenge-platform', 'Just a moment...', 'Attention Required! | Cloudflare', 'Access Denied</title>', '/_Incapsula_Resource', 'px-captcha', 'Robot or human?', 'Request unsuccessful. Incapsula', 'Type the characters you see in this image', 'api-services-support@amazon.com', 'Continue shopping'];
    foreach ($markers as $m) if (stripos($html, $m) !== false) return stripos($html, 'productTitle') === false;
    return false;
}

/* Any blocked page, opened in a real browser by Apify's RAG Web Browser (apify/rag-web-browser). */
function apify_page(string $url, bool $quick = false): array {
    // First a quick plain fetch through Apify's proxies (a few seconds); only if that is
    // blocked too, a full browser (slower, 30-60 seconds). $quick skips the browser.
    // Residential proxies look like a home internet connection, which most store sites let in.
    $r = apify_page_run($url, 'raw-http', 40, true);
    if ($r['ok'] || $quick) return $r;
    $b = apify_page_run($url, 'browser-playwright', 110, true);
    return $b['ok'] ? $b : $r;
}
function apify_page_run(string $url, string $tool, int $secs, bool $residential = false): array {
    @set_time_limit($secs + 40);
    $actor = (string)cfg('APIFY_BROWSER_ACTOR', 'apify~rag-web-browser');
    $input = ['query' => $url, 'maxResults' => 1, 'outputFormats' => ['html'], 'scrapingTool' => $tool,
              'htmlTransformer' => 'none', 'removeCookieWarnings' => true, 'maxRequestRetries' => 1,
              'proxyConfiguration' => $residential ? ['useApifyProxy' => true, 'apifyProxyGroups' => ['RESIDENTIAL'], 'apifyProxyCountry' => 'US'] : ['useApifyProxy' => true]];
    if ($tool !== 'raw-http') $input['dynamicContentWaitSecs'] = 5;
    $ch = curl_init('https://api.apify.com/v2/acts/' . rawurlencode($actor) . '/run-sync-get-dataset-items?timeout=' . $secs . '&format=json&clean=true');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $secs + 15, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_POSTFIELDS => json_encode($input, JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . cfg('APIFY_TOKEN')],
    ]);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) return ['ok' => false, 'error' => 'unreachable', 'message' => 'Couldn\'t reach Apify (' . $err . ').'];
    $j = json_decode((string)$res, true);
    if ($code < 200 || $code >= 300 || !is_array($j)) {
        $m = is_array($j) ? (string)($j['error']['message'] ?? '') : '';
        error_log("tdc apify page ($tool): $code $m");
        return ['ok' => false, 'error' => 'blocked', 'message' => $code === 401 ? 'Apify rejected the APIFY_TOKEN in config.php.'
            : ($code === 402 ? 'Apify refused the run (402' . ($m ? ': ' . $m : '') . ').' : 'The supplier\'s site blocked the server, and Apify couldn\'t open it either' . ($m ? ": $m" : '.'))];
    }
    $it = array_is_list($j) ? ($j[0] ?? null) : $j;
    $html = is_array($it) ? (string)($it['html'] ?? '') : '';
    $status = is_array($it) ? (int)($it['crawl']['httpStatusCode'] ?? 200) : 0;
    if ($html === '' || $status >= 400 || bot_wall($html)) {
        return ['ok' => false, 'error' => 'blocked', 'message' => 'The supplier\'s site blocked the server, and Apify couldn\'t get past it either. Add it from the Claude desktop app instead.'];
    }
    return ['ok' => true, 'html' => $html, 'url' => (string)($it['metadata']['url'] ?? $url)];
}

/* ---------- supplier catalogs: find the page that lists a SKU by walking the supplier's own store ---------- */
// Many stores (Blue Sky Bio, for example) list a whole size range on one page as a table of item numbers, and web
// search doesn't index those numbers, so searching for the SKU finds nothing (or other companies with similar names).
// Instead the server walks the store's own pages, notes every item number it sees and on which page, and keeps that
// index for a week per site. A lookup that runs out of time picks up where the last one stopped.
const KNOWN_SUPPLIER_SITES = [
    'blue sky' => 'blueskybio.com', 'henry schein' => 'henryschein.com', 'schein' => 'henryschein.com',
    'net32' => 'net32.com', 'benco' => 'shop.benco.com', 'patterson' => 'pattersondental.com',
    'darby' => 'darbydental.com', 'safco' => 'safcodental.com', 'dental city' => 'dentalcity.com',
];
const CATALOG_MAX_PAGES = 1500;   // per site, per week
const CATALOG_BUDGET    = 35;     // seconds of page reading per lookup
// Used when the supplier has no website saved under Setup and none of its items has a link yet.
function supplier_site_guess(string $sup): string {
    $s = ' ' . trim(strtolower(preg_replace('/[^a-z0-9]+/i', ' ', $sup))) . ' ';
    foreach (KNOWN_SUPPLIER_SITES as $k => $site) if (str_contains($s, ' ' . $k . ' ') || str_contains(str_replace(' ', '', $s), str_replace(' ', '', $k))) return $site;
    return '';
}
function sku_norm(string $s): string { return strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', $s)); }
// Every item-number-looking word on a page (letters and digits, dashes and dots ignored), plus picture file names,
// which stores often name after the item (…/store/HJH4302.jpg). Returned as a set: [NORMALIZED => true].
function sku_tokens(string $html): array {
    $t = preg_replace('/<(script|style|noscript|svg|template)\b.*?<\/\1>/is', ' ', $html) ?? $html;
    preg_match_all('#/([A-Za-z0-9][A-Za-z0-9_\-.]{2,40})\.(?:jpe?g|png|webp|gif)\b#i', $html, $im);
    $txt = html_entity_decode(strip_tags(str_replace('<', ' <', $t)), ENT_QUOTES | ENT_HTML5) . ' ' . str_replace('_', ' ', implode(' ', $im[1] ?? []));
    preg_match_all('/[A-Za-z0-9](?:[A-Za-z0-9]|[\-.\/](?=[A-Za-z0-9])){2,30}/', $txt, $m);
    $out = [];
    foreach ($m[0] as $tok) {
        $n = sku_norm($tok);
        if (strlen($n) < 4 || strlen($n) > 24 || !preg_match('/\d/', $n)) continue;
        if (ctype_digit($n) && strlen($n) < 5) continue;          // years, sizes, quantities
        $out[$n] = true;
        if (ctype_digit($n)) $out[ltrim($n, '0')] = true;
    }
    return $out;
}
function sku_in(array $set, string $sku): bool {
    $n = sku_norm($sku);
    return $n !== '' && (isset($set[$n]) || (ctype_digit($n) && isset($set[ltrim($n, '0')])));
}
function name_words(string $name): array {
    $stop = ['the', 'and', 'for', 'with', 'blue', 'sky', 'bio', 'each', 'box', 'boxes', 'pack', 'case', 'size', 'platform', 'system', 'collar', 'dental'];
    $w = preg_split('/[^a-z]+/', strtolower($name)) ?: [];
    return array_values(array_unique(array_filter($w, fn($x) => strlen($x) >= 3 && !in_array($x, $stop, true))));
}
// Several pages at once (same site only). Redirects are not followed blindly: a same-site redirect is returned so the
// caller can queue it. Returns [url => ['ok', 'code', 'type', 'body', 'loc']].
function http_get_many(array $urls, int $max = 2097152, int $timeout = 15): array {
    $mh = curl_multi_init(); $hs = []; $bufs = []; $out = [];
    foreach (array_values($urls) as $i => $u) {
        $bufs[$i] = '';
        $ch = curl_init($u);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_ENCODING => '', CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36',
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.5', 'Accept-Language: en-US,en;q=0.9'],
            CURLOPT_WRITEFUNCTION => function ($c, $chunk) use (&$bufs, $i, $max) { $bufs[$i] .= $chunk; return strlen($bufs[$i]) > $max ? 0 : strlen($chunk); },
        ]);
        curl_multi_add_handle($mh, $ch); $hs[$i] = [$ch, $u];
    }
    do { $st = curl_multi_exec($mh, $running); if ($running) curl_multi_select($mh, 1.0); } while ($running && $st === CURLM_OK);
    foreach ($hs as $i => [$ch, $u]) {
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $out[$u] = ['ok' => $code >= 200 && $code < 300, 'code' => $code, 'type' => (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE),
                    'body' => $bufs[$i], 'loc' => $code >= 300 && $code < 400 ? (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL) : ''];
        curl_multi_remove_handle($mh, $ch); curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}
function catalog_host(string $site): string {
    $h = strtolower(preg_replace('#^(https?://)?(www\.)?#i', '', trim($site)));
    return (string)preg_replace('#[/:?\#].*$#', '', $h);
}
// Same-site page address in one canonical form, or '' for anything that isn't a catalog page.
function catalog_url(string $u, string $host): string {
    $p = parse_url($u);
    if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true)) return '';
    if (preg_replace('/^www\./', '', strtolower($p['host'] ?? '')) !== $host) return '';
    $path = $p['path'] ?? '/';
    if (preg_match('#\.(pdf|jpe?g|png|gif|webp|svg|ico|zip|css|js|xml|json|mp4|mov|docx?|xlsx?)$#i', $path)) return '';
    if (preg_match('#/(cart|checkout|login|logout|signin|sign-in|register|account|my-account|password|forgot|wishlist|compare|contact|privacy|terms|careers|cdn-cgi|wp-admin|wp-json|feed)(/|$|[-_])#i', $path)) return '';
    $q = '';
    if (isset($p['query']) && preg_match('/(?:^|&)((?:page|p|pg|pagenumber)=\d{1,3})(?:&|$)/i', $p['query'], $qm)) $q = '?' . $qm[1];   // keep paging only
    return strtolower($p['scheme']) . '://' . strtolower($p['host']) . $path . $q;
}
function catalog_links(string $html, string $base, string $host): array {
    $out = [];
    if (!preg_match_all('#<a\b[^>]*?\bhref\s*=\s*["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', $html, $m, PREG_SET_ORDER)) return $out;
    foreach ($m as $a) {
        $href = html_entity_decode(trim($a[1]), ENT_QUOTES | ENT_HTML5);
        if ($href === '' || preg_match('#^(mailto|tel|javascript):#i', $href)) continue;
        $u = catalog_url(abs_url(preg_replace('/#.*$/', '', $href), $base), $host);
        if ($u === '') continue;
        $txt = mb_substr(trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($a[2]), ENT_QUOTES | ENT_HTML5))), 0, 100);
        if (!isset($out[$u]) || strlen($txt) > strlen($out[$u])) $out[$u] = $txt;
    }
    return $out;
}
function catalog_sitemap(string $host): array {
    $urls = []; $maps = [];
    $r = http_get('https://' . $host . '/robots.txt', 262144, 10);
    if ($r['ok'] && preg_match_all('/^\s*sitemap:\s*(\S+)/im', $r['body'], $m)) $maps = $m[1];
    if (!$maps) $maps = ['https://' . $host . '/sitemap.xml'];
    for ($i = 0; $i < count($maps) && $i < 8; $i++) {
        $s = http_get($maps[$i], 8388608, 15, ['Accept: application/xml,text/xml,*/*']);
        if (!$s['ok']) continue;
        $body = $s['body'];
        if (str_starts_with($body, "\x1f\x8b")) $body = (string)@gzdecode($body);
        if (!preg_match_all('#<loc>\s*(?:<!\[CDATA\[)?\s*([^<\]\s]+)#i', $body, $lm)) continue;
        foreach ($lm[1] as $loc) {
            $loc = html_entity_decode($loc, ENT_QUOTES | ENT_XML1);
            if (stripos($body, '<sitemapindex') !== false) { if (count($maps) < 8) $maps[] = $loc; continue; }
            $u = catalog_url($loc, $host);
            if ($u !== '') $urls[$u] = '';
            if (count($urls) >= 4000) break 2;
        }
    }
    return $urls;
}
// Returns ['ok' => found, 'url' => page that lists the SKU, 'pages' => pages read so far, 'done' => whole site read, 'blocked' => site refused].
function catalog_find(string $site, string $sku, string $name = '', int $budget = CATALOG_BUDGET): array {
    $host = catalog_host($site); $want = sku_norm($sku);
    if ($host === '' || strlen($want) < 3) return ['ok' => false, 'url' => '', 'pages' => 0, 'done' => false, 'blocked' => false];
    $dir = sys_get_temp_dir() . '/tdc-catalog';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $fh = @fopen($dir . '/' . preg_replace('/[^a-z0-9.\-]/', '_', $host) . '.json', 'c+');
    if ($fh) flock($fh, LOCK_EX);
    $st = $fh ? json_decode((string)stream_get_contents($fh), true) : null;
    if (!is_array($st) || ($st['v'] ?? 0) !== 1 || ($st['started'] ?? 0) < time() - 7 * 86400) {
        $st = ['v' => 1, 'host' => $host, 'started' => time(), 'seeded' => false, 'seen' => [], 'queue' => [], 'index' => [], 'pages' => 0, 'done' => false, 'blocked' => false];
    }
    $look = function () use (&$st, $want) {
        if (isset($st['index'][$want])) return $st['index'][$want];
        if (ctype_digit($want) && isset($st['index'][ltrim($want, '0')])) return $st['index'][ltrim($want, '0')];
        return null;
    };
    $hit = $look();
    if ($hit === null && !$st['done'] && public_url('https://' . $host . '/')) {
        @set_time_limit($budget + 120);
        $t0 = microtime(true);
        $enqueue = function (array $links) use (&$st) {
            foreach ($links as $u => $txt) { if (isset($st['seen'][$u])) continue; $st['seen'][$u] = 1; $st['queue'][] = [$u, (string)$txt]; }
        };
        if (!$st['seeded']) {
            $st['seeded'] = true;
            $enqueue(['https://' . $host . '/' => '']);
            $enqueue(catalog_sitemap($host));
        }
        // Pages whose address or link text share words with the item's name go first (healing, abutment, implant…).
        $words = name_words($name);
        $score = function (array $q) use ($words): float {
            $hay = strtolower(urldecode($q[0]) . ' ' . $q[1]); $s = 0.0;
            foreach ($words as $w) if (str_contains($hay, $w)) $s += 2;
            if (preg_match('#/(store|shop|products?|catalog|category|categories|collections?|p|c)(/|$)#i', $q[0])) $s += 1;
            return $s - substr_count((string)parse_url($q[0], PHP_URL_PATH), '/') * 0.1;
        };
        $wall = 0;
        while ($hit === null && $st['queue'] && $st['pages'] < CATALOG_MAX_PAGES && microtime(true) - $t0 < $budget) {
            usort($st['queue'], fn($a, $b) => $score($b) <=> $score($a));
            $take = array_splice($st['queue'], 0, 6);
            $got = http_get_many(array_column($take, 0));
            foreach ($got as $u => $r) {
                $st['pages']++;
                if ($r['loc'] !== '') { $nu = catalog_url(abs_url($r['loc'], $u), $st['host']); if ($nu !== '') $enqueue([$nu => '']); continue; }
                if (in_array($r['code'], [401, 403, 429, 503], true) || ($r['ok'] && bot_wall($r['body']))) { $wall++; continue; }   // turned away
                if (!$r['ok'] || stripos($r['type'], 'html') === false) continue;
                foreach (array_keys(sku_tokens($r['body'])) as $tok) if (!isset($st['index'][$tok]) && count($st['index']) < 80000) $st['index'][$tok] = $u;
                $enqueue(catalog_links($r['body'], $u, $st['host']));
            }
            if ($wall >= 3 && $wall * 2 >= $st['pages']) { $st['blocked'] = true; $st['done'] = true; break; }   // the store turns servers away
            $hit = $look();
        }
        if (!$st['queue'] || $st['pages'] >= CATALOG_MAX_PAGES) $st['done'] = true;
    }
    if ($fh) { ftruncate($fh, 0); rewind($fh); fwrite($fh, (string)json_encode($st, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)); flock($fh, LOCK_UN); fclose($fh); }
    return ['ok' => $hit !== null, 'url' => (string)$hit, 'pages' => (int)$st['pages'], 'done' => (bool)$st['done'], 'blocked' => (bool)$st['blocked']];
}

/* ---------- finding a product page by name / SKU (web search through Apify's RAG Web Browser) ---------- */
// $q: name, sku, supplier, site (the supplier's web address, e.g. henryschein.com)
function find_product_page(array $q, array $ctx = []): array {
    $name = trim((string)($q['name'] ?? '')); $sku = trim((string)($q['sku'] ?? '')); $sup = trim((string)($q['supplier'] ?? ''));
    $site = strtolower(preg_replace('#^(https?://)?(www\.)?#i', '', trim((string)($q['site'] ?? ''))));
    $site = preg_replace('#/.*$#', '', $site);
    if ($site === '') $site = supplier_site_guess($sup);   // no website saved for the supplier yet: use the one we know
    if ($name === '' && $sku === '') return ['ok' => false, 'error' => 'no_match', 'message' => 'Give a product name or SKU to look up.'];
    // Henry Schein: the product page address is just the item number (900-7438 -> /p/x/9007438), so go straight there.
    $hsNum = preg_replace('/[\s\-\.\/_]+/', '', $sku);
    if (preg_match('/^\d{6,8}$/', $hsNum) && (stripos($sup, 'schein') !== false || str_contains($site, 'henryschein'))) {
        $direct = read_product_link('https://www.henryschein.com/en-us/p/x/' . $hsNum, $ctx);
        if ($direct['ok'] && preg_replace('/\D/', '', (string)($direct['sku'] ?? '')) === $hsNum) {
            $direct['note'] = trim('Opened Henry Schein\'s page for item ' . $sku . '. ' . ($direct['note'] ?? ''));
            return $direct;
        }
    }
    // Benco: the page address is the item number too (3855778 or 3855-778 -> /Product/3855-778).
    $bnNum = preg_replace('/\D+/', '', $sku);
    if (preg_match('/^\d{6,8}$/', $bnNum) && (stripos($sup, 'benco') !== false || str_contains($site, 'benco'))) {
        $bn = substr($bnNum, 0, -3) . '-' . substr($bnNum, -3);
        $direct = read_product_link('https://shop.benco.com/Product/' . $bn, $ctx);
        if ($direct['ok'] && (preg_replace('/\D/', '', (string)($direct['sku'] ?? '')) === $bnNum || str_contains((string)($direct['url'] ?? ''), '/Product/' . $bn))) {
            $direct['note'] = trim('Opened Benco\'s page for item ' . $sku . '. ' . ($direct['note'] ?? ''));
            return $direct;
        }
    }
    // Darby: the page address is the 7-digit item number (906742 -> /0906742.html).
    $dbNum = preg_replace('/\D+/', '', $sku);
    if (preg_match('/^\d{5,7}$/', $dbNum) && (stripos($sup, 'darby') !== false || str_contains($site, 'darby'))) {
        $dbNum = str_pad($dbNum, 7, '0', STR_PAD_LEFT);
        $direct = read_product_link('https://www.darbydental.com/' . $dbNum . '.html', $ctx);
        if ($direct['ok'] && (str_contains(preg_replace('/\D/', '', (string)($direct['sku'] ?? '')), ltrim($dbNum, '0')) || stripos((string)($direct['pageTitle'] ?? ''), $dbNum) !== false)) {
            $direct['note'] = trim('Opened Darby\'s page for item ' . $sku . '. ' . ($direct['note'] ?? ''));
            return $direct;
        }
    }
    // Any other supplier with a known website: look for the SKU on the store's own pages (see catalog_find).
    $cat = null;
    if ($sku !== '' && $site !== '') {
        $cat = catalog_find($site, $sku, $name);
        if ($cat['ok']) {
            $direct = read_product_link($cat['url'], array_merge($ctx, ['sku' => $sku]));
            if ($direct['ok'] && ($direct['skuCheck'] ?? '') !== 'missing') {
                $direct['note'] = trim('Found item ' . $sku . ' on ' . $site . '. ' . ($direct['note'] ?? ''));
                $direct['catalog'] = true;
                return $direct;
            }
        }
    }
    $catMsg = '';
    if ($cat !== null && !$cat['ok']) {
        $catMsg = $cat['blocked'] ? $site . ' turns the server away, so its pages couldn\'t be checked. Update this item from the Claude desktop app, or import a screenshot of its page.'
            : ($cat['done'] ? 'Item ' . $sku . ' isn\'t listed anywhere on ' . $site . ' (' . $cat['pages'] . ' pages checked).'
            : 'Item ' . $sku . ' wasn\'t on the ' . $cat['pages'] . ' pages of ' . $site . ' checked so far; run it again to keep looking.');
    }
    if (!apify_ready()) return ['ok' => false, 'error' => 'no_match', 'message' => $catMsg !== '' ? $catMsg . ' Or add the product\'s web link.' : 'Looking products up online needs APIFY_TOKEN in config.php. Add the product\'s web link instead.'];
    // Suppliers write the same number differently (123-4567, 1234567, 123 4567), so also search without the separators,
    // then without quotes, and finally by name alone.
    $bare = preg_replace('/[\s\-\.\/_]+/', '', $sku);
    $skus = array_values(array_unique(array_filter([$sku, $bare])));
    $where = $site !== '' ? ' site:' . $site : '';
    $tail = $sup !== '' ? $sup . ' dental' : 'dental supply';   // "dental" keeps e.g. Blue Sky Bio from matching biotech companies
    $queries = [];
    foreach ($skus as $v) $queries[] = trim('"' . $v . '" ' . $name . $where);          // exact number on the supplier's site
    foreach (array_reverse($skus) as $v) { $queries[] = trim($v . ' ' . $name . ' ' . $tail); break; }   // number without separators, anywhere
    if ($name !== '' && $sku !== '') $queries[] = trim($name . ($where !== '' ? $where : ' ' . $tail));  // name alone
    if (!$skus) $queries = $where !== '' ? [trim($name . $where), trim($name . ' ' . $sup)] : [trim($name . ' ' . $tail)];
    $queries = array_slice(array_values(array_unique($queries)), 0, 4);
    $cands = []; $lastErr = '';
    foreach ($queries as $query) {
        $r = apify_search($query);
        if (!$r['ok']) { $lastErr = $r['message']; continue; }
        foreach ($r['results'] as $c) $cands[$c['url']] = $c;
        if ($cands) break;
    }
    if (!$cands) return ['ok' => false, 'error' => 'no_match', 'message' => trim($catMsg . ' ' . ($lastErr ?: 'No product page turned up in a web search either. Add the product\'s web link instead.'))];
    $cands = array_values($cands);
    // Prefer the office's supplier; then let Claude pick the page that is this exact product.
    usort($cands, fn($a, $b) => (int)($site !== '' && str_contains(strtolower((string)parse_url($b['url'], PHP_URL_HOST)), $site)) <=> (int)($site !== '' && str_contains(strtolower((string)parse_url($a['url'], PHP_URL_HOST)), $site)));
    $pick = 0;
    if (ai_ready() && count($cands) > 1) {
        $list = implode("\n", array_map(fn($i, $c) => "$i: {$c['title']} — {$c['url']}\n   {$c['description']}", array_keys($cands), $cands));
        $a = ai_json("A dental office wants the supplier's product page for: " . ($name !== '' ? "\"$name\"" : '') . ($sku !== '' ? " SKU/catalog # $sku (it may be written with or without dashes or spaces, e.g. $bare)" : '') . ($sup !== '' ? " from $sup" : '')
            . ".\nSearch results:\n$list\n\nReply with {\"pick\": index of the result that is the product page for this exact item (prefer $sup" . ($site !== '' ? " / $site" : '') . "), or -1 if none is}.", [], 'quick');
        if ($a['ok'] && isset($a['value']['pick']) && is_numeric($a['value']['pick'])) $pick = (int)$a['value']['pick'];
    }
    if ($pick < 0 || !isset($cands[$pick])) return ['ok' => false, 'error' => 'no_match', 'message' => trim($catMsg . ' The web search didn\'t find this exact product either. Add its web link instead.')];
    $res = read_product_link($cands[$pick]['url'], $ctx);
    if (!$res['ok']) return $res;
    $res['searched'] = true;
    $res['candidates'] = array_slice(array_map(fn($c) => ['url' => $c['url'], 'title' => $c['title']], $cands), 0, 5);
    if ($sku !== '' && ($res['sku'] ?? '') !== '' && strcasecmp(preg_replace('/[\W_]/', '', $res['sku']), preg_replace('/[\W_]/', '', $sku)) !== 0 && stripos(preg_replace('/[\W_]/', '', (string)($res['mfrNumber'] ?? '')), preg_replace('/[\W_]/', '', $sku)) === false) {
        $res['note'] = trim('Found by search: the page\'s SKU (' . $res['sku'] . ') differs from ' . $sku . ', so check it\'s the right product. ' . ($res['note'] ?? ''));
    } else {
        $res['note'] = trim('Found by search, so check it\'s the right product. ' . ($res['note'] ?? ''));
    }
    return $res;
}
/* Price comparison: product pages that may carry this exact manufacturer part number, at one supplier's site
   ($host) or anywhere else on the web ($host === '', leaving out $skip hosts). The caller reads each page and keeps it
   only when the page shows the part number. */
/* product pages from a supplier's own search results for a part number ([] when the site isn't known or found nothing).
   Henry Schein and Safco build their results in the browser, so those pages go through Apify's real browser. */
function supplier_site_search(string $host, string $q): array {
    $S = [
        'shop.benco.com'  => ['https://shop.benco.com/Search/Search?Terms=%s', '#(?:https://shop\.benco\.com)?/Product/\d+-\d+/[a-z0-9\-]+#i', false],
        'darbydental.com' => ['https://www.darbydental.com/catalogsearch/result/?q=%s', '#(?:https://www\.darbydental\.com)?/\d{5,}[0-9\-]*\.html#', false],
    ];
    $cfg = null; foreach ($S as $k => $v) if ($host === $k || str_ends_with($host, '.' . $k) || str_ends_with($k, $host)) { $cfg = $v; $base = 'https://' . ($k === 'shop.benco.com' ? 'shop.benco.com' : 'www.' . $k); break; }
    if (!$cfg) return [];
    $url = sprintf($cfg[0], rawurlencode($q));
    $html = '';
    if (!$cfg[2]) { $g = http_get($url, 4194304, 25); if ($g['ok'] && !bot_wall($g['body'])) $html = $g['body']; }
    if ($html === '' && apify_ready()) { $a = apify_page_run($url, 'browser-playwright', 90, true); if ($a['ok']) $html = $a['html']; }
    if ($html === '' || !preg_match_all($cfg[1], $html, $m, PREG_OFFSET_CAPTURE)) return [];
    // results first: links closest to where the page shows the part number (menus and "you may also like" come later)
    $hits = []; $p = 0; $ql = strtolower($q); $lh = strtolower($html);
    while (($p = strpos($lh, $ql, $p)) !== false) { $hits[] = $p; $p += strlen($ql); }
    $links = array_map(fn($x) => [$x[0], $hits ? min(array_map(fn($y) => abs($y - $x[1]), $hits)) : $x[1]], $m[0]);
    usort($links, fn($a, $b) => $a[1] <=> $b[1]);
    $out = []; $seen = [];
    foreach (array_column($links, 0) as $u) {
        if (!str_starts_with($u, 'http')) $u = $base . $u;
        $u = rtrim($u, '/');
        if (isset($seen[$u]) || preg_match('#clearance-item#', $u)) continue; $seen[$u] = 1;
        $out[] = ['url' => $u, 'title' => '', 'host' => $host];
        if (count($out) >= 4) break;
    }
    return $out;
}
function mpn_search(string $mpn, string $host, array $skip = []): array {
    if (!apify_ready()) return ['ok' => false, 'error' => 'no_search', 'message' => 'Searching needs APIFY_TOKEN in config.php.'];
    $mpn = trim($mpn); if (strlen(sku_norm($mpn)) < 3) return ['ok' => false, 'error' => 'invalid_argument', 'message' => 'The manufacturer part number is too short to search for.'];
    $host = strtolower(preg_replace('#^(https?://)?(www\.)?#i', '', trim($host))); $host = explode('/', $host)[0];
    // The supplier's own site search first: web search rarely indexes a supplier page by part number.
    if ($host !== '') { $own = supplier_site_search($host, $mpn); if ($own) return ['ok' => true, 'candidates' => $own, 'via' => 'site']; }
    $r = apify_search('"' . $mpn . '"' . ($host !== '' ? ' site:' . $host : ' dental'), 8);
    if (!$r['ok']) return ['ok' => false, 'error' => 'search', 'message' => $r['message']];
    $skip = array_map(fn($s) => strtolower(preg_replace('#^www\.#', '', (string)$s)), $skip);
    $out = []; $seen = [];
    foreach ($r['results'] as $c) {
        $h = strtolower(preg_replace('#^www\.#', '', (string)parse_url($c['url'], PHP_URL_HOST)));
        if ($host !== '' && !($h === $host || $h === 'www.' . $host)) continue;
        // Henry Schein: any link that carries the 7-digit item number becomes its product page
        if (str_contains($host, 'henryschein') && preg_match('#(?:productid(?:%3D|=)|/p/(?:[^/]+/)*)(\d{7})(?!\d)#i', $c['url'], $hm)) $c['url'] = 'https://www.henryschein.com/en-us/p/x/' . $hm[1];
        if ($host === '' && array_filter($skip, fn($s) => $s !== '' && ($h === $s || str_ends_with($h, '.' . $s)))) continue;
        if (preg_match('#/(search|s|cart|account|login|blog|category|categories|c)(/|\?|$)#i', (string)parse_url($c['url'], PHP_URL_PATH))) continue;
        if ($host === '' && isset($seen[$h])) continue; $seen[$h] = 1;
        $out[] = ['url' => $c['url'], 'title' => $c['title'], 'host' => $h];
        if (count($out) >= 5) break;
    }
    return ['ok' => true, 'candidates' => $out];
}
function apify_search(string $query, int $max = 5): array {
    @set_time_limit(120);
    $actor = (string)cfg('APIFY_BROWSER_ACTOR', 'apify~rag-web-browser');
    $input = ['query' => $query, 'maxResults' => max(1, min(10, $max)), 'outputFormats' => ['markdown'], 'scrapingTool' => 'raw-http', 'maxRequestRetries' => 0, 'removeCookieWarnings' => true];
    $ch = curl_init('https://api.apify.com/v2/acts/' . rawurlencode($actor) . '/run-sync-get-dataset-items?timeout=60&format=json&clean=true');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 75, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_POSTFIELDS => json_encode($input, JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . cfg('APIFY_TOKEN')],
    ]);
    $res = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $err = curl_error($ch); curl_close($ch);
    if ($res === false) return ['ok' => false, 'message' => 'Couldn\'t reach Apify (' . $err . ').'];
    $j = json_decode((string)$res, true);
    if ($code < 200 || $code >= 300 || !is_array($j)) {
        $m = is_array($j) ? (string)($j['error']['message'] ?? '') : '';
        error_log("tdc apify search: $code $m");
        return ['ok' => false, 'message' => $code === 401 ? 'Apify rejected the APIFY_TOKEN in config.php.' : ($code === 402 ? 'Apify refused the run (402' . ($m ? ': ' . $m : '') . ').' : 'The web search didn\'t work' . ($m ? ": $m" : '.'))];
    }
    $out = [];
    foreach ((array_is_list($j) ? $j : [$j]) as $it) {
        if (!is_array($it)) continue;
        $s = is_array($it['searchResult'] ?? null) ? $it['searchResult'] : [];
        $u = (string)($s['url'] ?? ($it['metadata']['url'] ?? ''));
        if (!preg_match('#^https?://#i', $u)) continue;
        $out[] = ['url' => $u, 'title' => mb_substr((string)($s['title'] ?? ($it['metadata']['title'] ?? '')), 0, 200), 'description' => mb_substr((string)($s['description'] ?? ($it['metadata']['description'] ?? '')), 0, 300)];
    }
    return ['ok' => true, 'results' => $out];
}

/* ---------- price comparison: find this product's pages at the office's suppliers, or anywhere ---------- */
// $q: name, sku, mfr, units, supplier; $sites: [['name' => supplier, 'site' => host]]; $scope: 'mine' | 'web'.
// Returns candidate product pages (url, supplier, title, sure). The page reads (price, pack) are done one by one by the caller.
function price_search(array $q, array $sites, string $scope = 'mine', array $skip = []): array {
    if (!apify_ready()) return ['ok' => false, 'error' => 'no_search', 'message' => 'Searching the web needs APIFY_TOKEN in config.php.'];
    $name = trim((string)($q['name'] ?? '')); $mfr = trim((string)($q['mfr'] ?? '')); $sku = trim((string)($q['sku'] ?? ''));
    if ($name === '') return ['ok' => false, 'error' => 'invalid_argument', 'message' => 'The item needs a name.'];
    $hosts = [];
    foreach ($sites as $s) { $site = trim((string)($s['site'] ?? '')) ?: supplier_site_guess((string)($s['name'] ?? '')); $h = strtolower(preg_replace('#^(https?://)?(www\.)?#i', '', $site)); $h = explode('/', $h)[0]; if ($h !== '' && !preg_match('/blueskybio/i', $h)) $hosts[$h] = (string)($s['name'] ?? $h); }
    $short = implode(' ', array_slice(preg_split('/\s+/', preg_replace('/[\(\)\[\],#]+/', ' ', $name)), 0, 7));
    $code = $mfr !== '' ? $mfr : '';
    $queries = [];
    if ($scope === 'alt') {
        // alternatives: search the product type, not the brand or part number
        if (!$hosts) return ['ok' => false, 'error' => 'no_sites', 'message' => 'None of your suppliers has a website saved (Setup › Suppliers).'];
        $g = ai_ready() ? ai_json("A dental office wants substitutes for \"$name\"" . (!empty($q['units']) ? " (sold as {$q['units']})" : '') . " from other brands. Give a short generic search phrase for this kind of product, without the brand or part number (e.g. \"bendable micro applicator fine\").\nReply with JSON: {\"phrase\": \"...\"}", [], 'quick') : ['ok' => false];
        $phrase = trim((string)(($g['ok'] ?? false) ? ($g['value']['phrase'] ?? '') : '')) ?: $short;
        $where = '(' . implode(' OR ', array_map(fn($h) => 'site:' . $h, array_slice(array_keys($hosts), 0, 10))) . ')';
        $queries[] = $phrase . ' ' . $where;
        $queries[] = $phrase . ' dental';
    } elseif ($scope === 'web') {
        if ($code !== '') $queries[] = '"' . $code . '" ' . $short . ' price';
        $queries[] = $short . ' dental supply price';
    } else {
        if (!$hosts) return ['ok' => false, 'error' => 'no_sites', 'message' => 'None of your suppliers has a website saved (Setup › Suppliers).'];
        $where = '(' . implode(' OR ', array_map(fn($h) => 'site:' . $h, array_slice(array_keys($hosts), 0, 10))) . ')';
        if ($code !== '') $queries[] = '"' . $code . '" ' . $where;
        $queries[] = $short . ' ' . $where;
    }
    $cands = []; $err = '';
    foreach (array_slice($queries, 0, 2) as $qq) {
        $r = apify_search($qq, 10);
        if (!$r['ok']) { $err = $r['message']; continue; }
        foreach ($r['results'] as $c) {
            $host = strtolower(preg_replace('#^www\.#', '', (string)parse_url($c['url'], PHP_URL_HOST)));
            $mine = '';
            foreach ($hosts as $h => $sn) if ($host === $h || str_ends_with($host, '.' . $h) || str_ends_with($h, '.' . $host)) { $mine = $sn; break; }
            if (($scope === 'mine' || $scope === 'alt') && $mine === '') continue;
            if ($scope === 'web' && $mine !== '') continue;
            if (in_array($c['url'], $skip, true)) continue;
            $cands[$c['url']] = $c + ['supplier' => $mine, 'host' => $host];
        }
    }
    $cands = array_values($cands);
    if (!$cands) return ['ok' => true, 'candidates' => [], 'message' => $err ?: 'No product pages turned up.'];
    if ($scope === 'alt') {
        if (!ai_ready()) return ['ok' => false, 'error' => 'no_ai', 'message' => 'Finding alternatives needs Claude (ANTHROPIC_API_KEY in config.php).'];
        $list = implode("\n", array_map(fn($i, $c) => "$i: [{$c['supplier']}] {$c['title']} — {$c['url']}\n   {$c['description']}", array_keys($cands), $cands));
        $a = ai_json("A dental office uses \"$name\"" . ($mfr !== '' ? " (manufacturer # $mfr)" : '') . (!empty($q['units']) ? ", sold as " . $q['units'] : '') . " and wants alternatives: other products that do the same job and could replace it (another brand, or the same brand's equivalent).\nSearch results from their suppliers:\n$list\n\n"
            . "Pick product pages (not category, search or article pages) that are good substitutes. Leave out the exact same product. Prefer the same size, shade or strength. Up to 8, best first, at most 2 per store. For each, say in a few words how it differs (brand, size, pack).\n"
            . "Reply with JSON: {\"picks\": [{\"i\": index, \"name\": \"product name\", \"why\": \"e.g. Different brand, same size\"}]}", [], 'quick');
        $keep = []; $per = [];
        foreach ((array)(($a['ok'] ?? false) ? ($a['value']['picks'] ?? []) : []) as $p) { $i = (int)($p['i'] ?? -1); if (!isset($cands[$i])) continue; $h = $cands[$i]['host']; if (($per[$h] = ($per[$h] ?? 0) + 1) > 2) continue;
            $keep[] = ['url' => $cands[$i]['url'], 'title' => mb_substr(trim((string)($p['name'] ?? '')) ?: $cands[$i]['title'], 0, 140), 'supplier' => $cands[$i]['supplier'], 'mine' => true, 'sure' => false, 'why' => mb_substr((string)($p['why'] ?? ''), 0, 80)]; if (count($keep) >= 8) break; }
        return ['ok' => true, 'candidates' => $keep, 'message' => $keep ? '' : 'No alternatives turned up at your suppliers.'];
    }
    $keep = []; $seenH = []; foreach ($cands as $c) { if (isset($seenH[$c['host']])) continue; $seenH[$c['host']] = 1; $keep[] = $c + ['sure' => false]; if (count($keep) >= 6) break; }
    if (ai_ready()) {
        $list = implode("\n", array_map(fn($i, $c) => "$i: {$c['title']} — {$c['url']}\n   {$c['description']}", array_keys($cands), $cands));
        $a = ai_json("A dental office wants to compare prices for this exact product: \"$name\"" . ($mfr !== '' ? " (manufacturer # $mfr)" : '') . ($sku !== '' ? " (their supplier's SKU $sku)" : '')
            . (!empty($q['units']) ? ", sold as " . $q['units'] : '') . ".\nSearch results:\n$list\n\nPick the results that are a product page (not a category, search or article page) selling this same product. "
            . "Different pack sizes of the same product are fine; a different shade, size, strength or a different brand is not. At most one page per store, best first, up to 6. "
            . "Reply with JSON: {\"picks\": [{\"i\": index, \"sure\": true if it is clearly the same product, false if it might be a different variant}]}", [], 'quick');
        if ($a['ok'] && is_array($a['value']['picks'] ?? null)) {
            $keep = []; $seenHost = [];
            foreach ($a['value']['picks'] as $p) { $i = (int)($p['i'] ?? -1); if (!isset($cands[$i]) || isset($seenHost[$cands[$i]['host']])) continue; $seenHost[$cands[$i]['host']] = 1; $keep[] = $cands[$i] + ['sure' => !empty($p['sure'])]; if (count($keep) >= 6) break; }
        }
    }
    return ['ok' => true, 'candidates' => array_map(fn($c) => ['url' => $c['url'], 'title' => $c['title'], 'supplier' => $c['supplier'] ?: $c['host'], 'mine' => $c['supplier'] !== '', 'sure' => $c['sure']], $keep)];
}

/* ---------- usage: how fast each item gets used, from the activity log ----------
   Counting down (the - button, a count, Claude's set_count) is what records use; a delivery (Received) is not use.
   Delivery time per supplier comes from orders: the gap between Mark ordered and the first Received for that item. */
function usage_stats(PDO $pdo): array {
    $now = (int)round(microtime(true) * 1000);
    $ev = [];
    foreach (all_docs($pdo, 'log') as $l) {
        $id = (string)($l['itemId'] ?? ''); $a = (string)($l['action'] ?? ''); $d = (string)($l['detail'] ?? ''); $at = (int)($l['at'] ?? 0);
        if ($id === '' || !$at) continue;
        if ($a === 'Count' && preg_match('/(–|-|[\d.]+)\s*→\s*([\d.]+)/u', $d, $m)) $ev[$id][] = [$at, 'c', is_numeric($m[1]) ? (float)$m[1] : null, (float)$m[2]];
        elseif ($a === 'Counted' && preg_match('/^([\d.]+) on hand/', $d, $m)) $ev[$id][] = [$at, 'c', null, (float)$m[1]];
        elseif ($a === 'Received' && preg_match('/\+([\d.]+), now ([\d.]+)/', $d, $m)) $ev[$id][] = [$at, 'r', (float)$m[2] - (float)$m[1], (float)$m[2]];
    }
    // delivery days per supplier
    $recv = []; foreach ($ev as $id => $L) foreach ($L as $e) if ($e[1] === 'r') $recv[$id][] = $e[0];
    $lead = [];
    foreach (all_docs($pdo, 'orders') as $o) {
        $oat = (int)($o['at'] ?? 0); if (!$oat) continue;
        foreach ((array)($o['lines'] ?? []) as $ln) {
            $id = (string)($ln['id'] ?? ''); $sup = trim((string)($ln['supplier'] ?? '')); if ($id === '' || $sup === '') continue;
            $first = null; foreach ($recv[$id] ?? [] as $t) if ($t > $oat && $t < $oat + 45 * 864e5 && ($first === null || $t < $first)) $first = $t;
            if ($first && $first - $oat >= 864e5 / 2) $lead[mb_strtolower($sup)][] = ($first - $oat) / 864e5;   // same-day "received" is a catch-up, not a delivery
        }
    }
    $leadDays = [];
    foreach ($lead as $s => $L) { sort($L); $leadDays[$s] = round($L[intdiv(count($L), 2)], 1); }
    $out = [];
    foreach (all_docs($pdo, 'items') as $id => $it) {
        if (empty($it['name']) || !empty($it['archived'])) continue;
        $L = $ev[$id] ?? []; usort($L, fn($x, $y) => $x[0] <=> $y[0]);
        $used = 0.0; $lvl = null; $n = 0;
        foreach ($L as [$at, $k, $before, $after]) {
            $b = $before ?? $lvl;
            if ($k === 'c' && $b !== null && $after < $b) { $used += $b - $after; $n++; }
            $lvl = $after;
        }
        $since = $L ? $L[0][0] : (int)($it['createdAt'] ?? $now);
        $days = max(0, ($now - $since) / 864e5);
        $sup = mb_strtolower(trim((string)($it['supplier'] ?? '')));
        $out[$id] = ['used' => round($used, 2), 'days' => round($days, 1), 'drops' => $n, 'perWeek' => $days >= 1 ? round($used / $days * 7, 2) : null, 'leadDays' => $leadDays[$sup] ?? null];
    }
    return ['ok' => true, 'items' => $out, 'leadDays' => $leadDays, 'since' => $ev ? min(array_map(fn($L) => min(array_column($L, 0)), $ev)) : null];
}

/* ---------- reading invoices, packing slips, order confirmations and screenshots ---------- */
// $files: list of ['type' => mime, 'data' => base64] (pictures or PDFs). Matches each product line to the inventory.
function read_document(PDO $pdo, array $files, array $ctx = []): array {
    $files = array_values(array_filter($files, fn($f) => is_array($f) && is_string($f['data'] ?? null)
        && (in_array(strtolower((string)($f['type'] ?? '')), IMG_TYPES, true) || strtolower((string)($f['type'] ?? '')) === 'application/pdf')));
    if (!$files) return ['ok' => false, 'error' => 'invalid_argument', 'message' => 'Add a photo, screenshot or PDF first.'];
    if (count($files) > 8) return ['ok' => false, 'error' => 'invalid_argument', 'message' => 'Up to 8 files at a time.'];
    @set_time_limit(240);
    $items = all_docs($pdo, 'items');
    $cat = [];
    foreach ($items as $id => $it) {
        $name = trim((string)($it['name'] ?? '')); if ($name === '') continue;
        $cat[] = $id . ' | ' . $name . ' | ' . trim((string)($it['sku'] ?? '')) . ' | ' . trim((string)($it['supplier'] ?? '')) . ' | ' . trim((string)($it['units'] ?? ''));
    }
    $nameRule = mb_substr(trim((string)($ctx['nameRule'] ?? '')), 0, 1500);
    $prompt = <<<P
These pictures or PDFs come from a dental office's supply ordering: an invoice, packing slip, order confirmation, quote, catalog page or a screenshot of a supplier's product or order page. Read every product line and match each one to our inventory below.

Reply with one JSON object:
{"kind": "invoice" | "packing_slip" | "order_confirmation" | "quote" | "product_page" | "order_history" | "other",
 "supplier": "the supplier/seller's name as we'd write it (for example Henry Schein, Patterson, Benco, Amazon, Net32), or empty",
 "date": "the document's order/invoice/ship date as YYYY-MM-DD, or empty",
 "number": "invoice or order number, or empty",
 "lines": [{
   "desc": "the product description as printed, shortened to under 120 characters",
   "sku": "the supplier's item/catalog number on the line, or empty",
   "mfr": "the manufacturer's part number if separately shown, or empty",
   "qty": number of packs on this line (for invoices and packing slips the quantity SHIPPED; for orders and confirmations the quantity ordered; for product pages and quotes 0 unless a quantity is shown),
   "backordered": true if the line says backordered / not shipped / cancelled,
   "pack": "the selling unit or package size the price and quantity refer to (for example Box of 100, Case of 10 boxes, Each), or empty",
   "price": price for ONE of that selling unit after discounts, as a number, or null (never the line total unless qty is 1),
   "match": "the id of our inventory item that is this same product, or empty if none is",
   "sure": "yes" when the SKU matches or the name, maker and size clearly match; "maybe" when it's probably the same product; "no" when there is no good match,
   "packDiffers": true if this line's selling unit is clearly different from the matched item's units (for example a case vs a box),
   "name": "when there is no match: a short, clear inventory name for it; otherwise empty"
 }],
 "notes": "anything the office should check, in one short sentence, or empty"}

Rules: skip shipping, tax, fees, discounts, totals and other non-product lines. Combine the same product listed twice on one document. Match by SKU first (ignore spaces, dashes and leading zeros), then by maker, product, size and shade. Different sizes, shades, gauges or strengths are different products. If several pages are of one document, treat them as one. Only use what the documents show; leave a field empty rather than guess.
$nameRule

OUR INVENTORY (id | name | SKU | supplier | units):
P;
    $prompt .= "\n" . implode("\n", $cat);
    $r = ai_json('Read the attached documents now and reply with the JSON described above.', $files, '', ['maxFiles' => 8, 'maxTokens' => 16000, 'timeout' => 200, 'cache' => $prompt]);
    if (!$r['ok']) return $r;
    $v = is_array($r['value']) ? $r['value'] : [];
    $lines = [];
    foreach ((array)($v['lines'] ?? []) as $l) {
        if (!is_array($l)) continue;
        $m = (string)($l['match'] ?? '');
        if ($m !== '' && !isset($items[$m])) $m = '';
        $price = numv($l['price'] ?? null);
        $qty = numv($l['qty'] ?? null);
        $lines[] = [
            'desc' => mb_substr(trim((string)($l['desc'] ?? '')), 0, 200),
            'sku' => mb_substr(trim((string)($l['sku'] ?? '')), 0, 60),
            'mfr' => mb_substr(trim((string)($l['mfr'] ?? '')), 0, 60),
            'qty' => $qty !== null && $qty >= 0 ? $qty : 0,
            'backordered' => !empty($l['backordered']),
            'pack' => mb_substr(trim((string)($l['pack'] ?? '')), 0, 80),
            'price' => $price !== null && $price >= 0 ? round($price, 2) : null,
            'match' => $m,
            'sure' => $m === '' ? 'no' : (in_array($l['sure'] ?? '', ['yes', 'maybe'], true) ? $l['sure'] : 'maybe'),
            'packDiffers' => $m !== '' && !empty($l['packDiffers']),
            'name' => mb_substr(trim((string)($l['name'] ?? '')), 0, 160),
        ];
    }
    $kinds = ['invoice', 'packing_slip', 'order_confirmation', 'quote', 'product_page', 'order_history', 'other'];
    return ['ok' => true,
        'kind' => in_array($v['kind'] ?? '', $kinds, true) ? $v['kind'] : 'other',
        'supplier' => mb_substr(trim((string)($v['supplier'] ?? '')), 0, 80),
        'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($v['date'] ?? '')) ? $v['date'] : '',
        'number' => mb_substr(trim((string)($v['number'] ?? '')), 0, 60),
        'notes' => mb_substr(trim((string)($v['notes'] ?? '')), 0, 400),
        'lines' => $lines];
}

// One more picture from a product page, for "Show another photo". $referer is the product page.
function fetch_photo(string $u, string $referer = ''): array {
    if (!preg_match('#^https?://#i', $u)) return ['ok' => false, 'error' => 'invalid_argument', 'message' => 'Bad picture address.'];
    $why = '';
    $im = fetch_image($u, preg_match('#^https?://#i', $referer) ? $referer : '', $why);
    if (!$im) return ['ok' => false, 'error' => 'no_photo', 'message' => 'That picture couldn\'t be downloaded (' . $why . ').'];
    return ['ok' => true, 'photo' => ['type' => $im['type'], 'data' => base64_encode($im['bytes'])]];
}

/*
 * Supplier logo for Setup › Suppliers › Get logo. Reads the supplier's home page and collects
 * likely logo pictures (touch icon, schema.org logo, <img> named "logo", og:image, site icon),
 * then Google's site-icon service as a fallback. Returns up to 4 pictures to pick from.
 */
function fetch_logo(string $site): array {
    $site = trim($site);
    if ($site === '') return ['ok' => false, 'error' => 'invalid_argument', 'message' => 'Add the supplier\'s website first.'];
    if (!preg_match('#^https?://#i', $site)) $site = 'https://' . $site;
    $host = strtolower((string)parse_url($site, PHP_URL_HOST));
    if ($host === '') return ['ok' => false, 'error' => 'invalid_argument', 'message' => 'That website address doesn\'t look right.'];
    $home = parse_url($site, PHP_URL_SCHEME) . '://' . $host . '/';
    $cands = [];
    $add = function (string $u, string $why) use (&$cands) { $u = html_entity_decode(trim($u), ENT_QUOTES); if ($u !== '' && !preg_match('#^data:#i', $u) && !isset($cands[$u])) $cands[$u] = $why; };
    $page = http_get($home, 2097152, 15, ['Accept: text/html']);
    if ($page['ok'] && stripos((string)$page['type'], 'html') !== false) {
        $html = $page['body']; $base = $page['url'] ?: $home;
        if (preg_match_all('#<link\b[^>]*>#i', $html, $m)) foreach ($m[0] as $tag) {
            if (!preg_match('#\brel\s*=\s*["\']([^"\']+)#i', $tag, $r) || !preg_match('#\bhref\s*=\s*["\']([^"\']+)#i', $tag, $h)) continue;
            $rel = strtolower($r[1]);
            if (str_contains($rel, 'apple-touch-icon')) $add(abs_url($h[1], $base), 'touch icon');
        }
        if (preg_match_all('#"logo"\s*:\s*(?:\{[^}]*?"url"\s*:\s*)?"([^"]+)"#i', $html, $m)) foreach ($m[1] as $u) $add(abs_url(stripslashes($u), $base), 'logo');
        if (preg_match_all('#<img\b[^>]*>#i', $html, $m)) foreach (array_slice($m[0], 0, 80) as $tag) {
            if (!preg_match('#logo#i', $tag)) continue;
            if (preg_match('#\b(?:data-src|src)\s*=\s*["\']([^"\']+)#i', $tag, $s) && !preg_match('#\.svg(\?|$)#i', $s[1])) $add(abs_url($s[1], $base), 'logo');
            if (preg_match('#\bsrcset\s*=\s*["\']([^"\'\s,]+)#i', $tag, $s) && !preg_match('#\.svg(\?|$)#i', $s[1])) $add(abs_url($s[1], $base), 'logo');
        }
        if (preg_match('#<meta\b[^>]*property\s*=\s*["\']og:image["\'][^>]*content\s*=\s*["\']([^"\']+)#i', $html, $s)
            || preg_match('#<meta\b[^>]*content\s*=\s*["\']([^"\']+)["\'][^>]*property\s*=\s*["\']og:image#i', $html, $s)) $add(abs_url($s[1], $base), 'page picture');
        if (preg_match_all('#<link\b[^>]*>#i', $html, $m)) foreach ($m[0] as $tag) {
            if (preg_match('#\brel\s*=\s*["\'][^"\']*\bicon\b#i', $tag) && preg_match('#\bhref\s*=\s*["\']([^"\']+)#i', $tag, $h) && !preg_match('#\.(svg|ico)(\?|$)#i', $h[1])) $add(abs_url($h[1], $base), 'site icon');
        }
    }
    $add('https://www.google.com/s2/favicons?domain=' . rawurlencode($host) . '&sz=128', 'site icon');
    $out = [];
    foreach (array_slice($cands, 0, 10, true) as $u => $why) {
        if (count($out) >= 4) break;
        if (!preg_match('#^https?://#i', $u)) continue;
        $r = http_get($u, 2097152, 12, ['Accept: image/png,image/jpeg,image/webp,image/gif;q=0.8', 'Referer: ' . $home]);
        if (!$r['ok']) continue;
        $mime = sniff_mime($r['body']) ?? strtolower(trim(explode(';', (string)$r['type'])[0]));
        if (!in_array($mime, IMG_TYPES, true) || strlen($r['body']) < 300) continue;
        $sz = @getimagesizefromstring($r['body']);
        if ($sz && ($sz[0] < 24 || $sz[1] < 24)) continue;
        $out[] = ['type' => $mime, 'data' => base64_encode($r['body']), 'why' => $why, 'w' => $sz[0] ?? 0, 'h' => $sz[1] ?? 0];
    }
    if (!$out) return ['ok' => false, 'error' => 'no_logo', 'message' => 'Couldn\'t find a logo on ' . $host . '. Upload one instead.'];
    return ['ok' => true, 'logos' => $out];
}

/*
 * Order online for Shopify stores (Zirc, TruAbutment, Common Sense Dental, American Dental Accessories…):
 * a Shopify store fills its cart from one link, /cart/VARIANT:QTY,VARIANT:QTY. The variant ids come from
 * each product page's public .js data; the ?variant= in a saved link, then a matching SKU, picks the right size.
 */
function shopify_cart(array $lines): array {
    $host = ''; $parts = []; $missed = [];
    foreach (array_slice($lines, 0, 60) as $l) {
        if (!is_array($l)) continue;
        $link = trim((string)($l['link'] ?? '')); $qty = max(1, (int)round((float)($l['qty'] ?? 1)));
        $sku = preg_replace('/[^a-z0-9]/', '', strtolower((string)($l['sku'] ?? '')));
        if (!preg_match('#^(https?://[^/]+)/(?:collections/[^/]+/)?products/([^/?\#]+)#i', $link, $m)) { $missed[] = $link; continue; }
        $h = strtolower($m[1]);
        if ($host === '') $host = $h; elseif ($host !== $h) { $missed[] = $link; continue; }
        $r = http_get($h . '/products/' . $m[2] . '.js', 1048576, 15, ['Accept: application/json']);
        $p = $r['ok'] ? json_decode((string)$r['body'], true) : null;
        $vs = is_array($p['variants'] ?? null) ? $p['variants'] : [];
        if (!$vs) { $missed[] = $link; continue; }
        $want = preg_match('/[?&]variant=(\d+)/', $link, $vm) ? $vm[1] : '';
        $pick = null;
        foreach ($vs as $v) if ($want !== '' && (string)($v['id'] ?? '') === $want) $pick = $v;
        if (!$pick && $sku !== '') foreach ($vs as $v) if (preg_replace('/[^a-z0-9]/', '', strtolower((string)($v['sku'] ?? ''))) === $sku) { $pick = $v; break; }
        if (!$pick) foreach ($vs as $v) if (!empty($v['available'])) { $pick = $v; break; }
        $pick = $pick ?: $vs[0];
        $parts[] = (int)$pick['id'] . ':' . $qty;
    }
    if (!$parts) return ['ok' => false, 'error' => 'no_match', 'message' => 'Couldn\'t look up these products on the store'];
    return ['ok' => true, 'url' => $host . '/cart/' . implode(',', $parts), 'missed' => $missed];
}
