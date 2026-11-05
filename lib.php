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

const COLLECTIONS = ['items', 'log', 'orders', 'reps', 'counts', 'supinfo', 'settings'];
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
// $images: list of ['type' => mime, 'data' => base64]
function ai_json(string $prompt, array $images = [], string $tier = ''): array {
    if (!ai_ready()) return ['ok' => false, 'error' => 'no_key', 'message' => 'Claude isn\'t set up on this server yet: add ANTHROPIC_API_KEY to config.php.'];
    $model = $tier === 'quick' ? (string)cfg('ANTHROPIC_MODEL_QUICK', 'claude-haiku-5-5') : (string)cfg('ANTHROPIC_MODEL', 'claude-sonnet-5-5');
    $content = [];
    foreach (array_slice($images, 0, 4) as $im) {
        $mime = strtolower((string)($im['type'] ?? ''));
        if (!in_array($mime, IMG_TYPES, true) || !is_string($im['data'] ?? null)) continue;
        $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $im['data']]];
    }
    $content[] = ['type' => 'text', 'text' => $prompt . "\n\nReply with only the JSON, no other text."];
    $body = json_encode([
        'model' => $model, 'max_tokens' => 4096,
        'messages' => [['role' => 'user', 'content' => $content]],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($body === false) return ['ok' => false, 'error' => 'bad_request', 'message' => 'The page text couldn\'t be sent to Claude.'];
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90,
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
function page_facts(string $html, string $base): array {
    $f = ['title' => '', 'description' => '', 'images' => [], 'jsonld' => [], 'text' => ''];
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
    // Large product-looking <img> tags, in page order.
    if (preg_match_all('/<img\s[^>]*>/i', $html, $ms)) {
        foreach ($ms[0] as $tag) {
            $src = attr($tag, 'data-zoom-image') ?: attr($tag, 'data-large') ?: attr($tag, 'data-src') ?: attr($tag, 'src');
            if ($src === '' || str_starts_with($src, 'data:') || preg_match('/logo|sprite|icon|badge|banner|pixel|spacer|\.svg/i', $src)) continue;
            $f['images'][] = abs_url(html_entity_decode($src, ENT_QUOTES | ENT_HTML5), $base);
            if (count($f['images']) > 30) break;
        }
    }
    $f['images'] = array_values(array_unique(array_filter($f['images'], fn($u) => preg_match('#^https?://#i', $u))));
    $f['images'] = array_slice($f['images'], 0, 25);
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
function fetch_image(string $url): ?array {
    $r = http_get($url, MAX_BLOB, 20, ['Accept: image/avif,image/webp,image/png,image/jpeg,image/*;q=0.8']);
    if (!$r['ok']) return null;
    $mime = sniff_mime($r['body']) ?? strtolower(trim(explode(';', $r['type'])[0]));
    if (!in_array($mime, IMG_TYPES, true) || strlen($r['body']) < 2500) return null;   // tiny = icon or tracking pixel
    return ['type' => $mime, 'bytes' => $r['body']];
}

/*
 * Reads a supplier's product page and returns what the Add item page fills in:
 * names[], sku, mfrNumber, price, units, description, supplier, url, photo (type + base64), note.
 */
function read_product_link(string $url, array $ctx = []): array {
    $am = amazon_url($url);
    if ($am !== null && apify_ready()) {
        // Amazon blocks servers from reading its pages, so Amazon links go through Apify's Amazon scraper.
        $got = amazon_facts($am);
        if (!$got['ok']) return $got;
        $f = $got['facts']; $finalUrl = $got['url'];
    } else {
        $page = http_get($url);
        if (!$page['ok']) {
            $blocked = in_array($page['code'] ?? 0, [401, 403, 429, 503], true);
            return ['ok' => false, 'error' => $blocked ? 'blocked' : 'unreachable',
                    'message' => $blocked ? 'The supplier\'s site didn\'t let the server read that page.' : ($page['error'] ?: 'Couldn\'t open that page.')];
        }
        if (stripos($page['type'], 'html') === false && stripos($page['type'], 'xml') === false) {
            return ['ok' => false, 'error' => 'not_page', 'message' => 'That link isn\'t a web page.'];
        }
        $f = page_facts($page['body'], $page['url']);
        $finalUrl = $page['url'];
        // Amazon's "Continue shopping" robot check instead of the product
        if ($am !== null && (stripos($page['body'], 'captcha') !== false || stripos($page['body'], 'Continue shopping') !== false) && stripos($page['body'], 'productTitle') === false) {
            return ['ok' => false, 'error' => 'blocked', 'message' => 'Amazon didn\'t let the server read that page. Add APIFY_TOKEN to config.php to read Amazon links.'];
        }
    }
    $res = ['url' => $finalUrl, 'pageTitle' => $f['title'], 'names' => [], 'sku' => '', 'mfrNumber' => '', 'price' => null,
            'units' => '', 'description' => $f['description'], 'supplier' => '', 'note' => '', 'photo' => null, 'photoNote' => ''];
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
            . (!empty($ctx['sku']) ? "Expected SKU: {$ctx['sku']}\n" : '')
            . "Structured product data on the page: " . mb_substr($ld ?: '[]', 0, 6000) . "\n"
            . "Pictures on the page (index: address):\n" . ($imgs ?: '(none)') . "\n\n"
            . "Page text:\n" . $f['text'] . "\n\n"
            . (!empty($ctx['nameRule']) ? $ctx['nameRule'] . "\n\n" : '')
            . "Reply with one JSON object: {\"names\": [up to 3 short inventory names, best first], \"sku\": \"the supplier's item / catalog number\", "
            . "\"mfrNumber\": \"manufacturer part number if shown\", \"price\": number or null (the price for one unit as sold; null if no price is shown or it needs sign-in), "
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
    if ($am !== null && apify_ready()) $res['supplier'] = 'Amazon';
    $order = $pick >= 0 && isset($f['images'][$pick]) ? array_merge([$f['images'][$pick]], $f['images']) : ($pick < 0 ? [] : $f['images']);
    foreach (array_slice(array_values(array_unique($order)), 0, 6) as $u) {
        $im = fetch_image($u);
        if ($im) { $res['photo'] = ['type' => $im['type'], 'data' => base64_encode($im['bytes'])]; break; }
    }
    if (!$res['photo']) $res['photoNote'] = 'The server couldn\'t download a product photo from that page.';
    if ($res['price'] === null) $res['priceNote'] = 'No price on the page (it may only show after signing in to your supplier account).';
    if (!$res['names'] && $f['title'] !== '') $res['names'][] = $f['title'];
    return ['ok' => true] + $res;
}

/* ---------- inventory rules (same as the page) ---------- */
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
             : ($code === 402 || stripos($m, 'credit') !== false || stripos($m, 'usage') !== false ? 'The Apify account is out of monthly credit.'
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
        'jsonld' => [['name' => (string)$it['title'], 'sku' => (string)($it['asin'] ?? ''), 'description' => is_string($it['description'] ?? null) ? $it['description'] : '',
                      'offers' => is_numeric($pv) ? ['price' => (float)$pv] : null]],
        'text' => mb_substr("Amazon product data:\n" . $text, 0, 24000),
    ]];
}
