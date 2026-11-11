<?php
/* The Supply Room browser extension's door.
 * The extension reads supplier product pages the office is signed in to (Henry Schein, Patterson…) and saves the
 * price it sees. It has its own key (EXT_KEY, made in Setup › Data collection › Browser extension), which can only
 * list products and record prices: nothing else in the Supply Room can be read or changed with it. */
define('TDC_TOOLS_ONLY', true);
require __DIR__ . '/mcp.php';   // the connector's tools (record_price), without its request handling

header('Content-Type: application/json');
// The extension calls from its own origin, with the key in a header and no cookies, so any origin may ask.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-TDC-Ext-Key');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
function ext_out(int $code, array $body): void { http_response_code($code); echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); exit; }

$key = (string)cfg('EXT_KEY');
$given = (string)($_SERVER['HTTP_X_TDC_EXT_KEY'] ?? '');
if (strlen($key) < 24 || !hash_equals($key, $given)) ext_out(401, ['ok' => false, 'error' => 'unauthorized', 'message' => 'The extension isn\'t paired with this Supply Room (or its code was changed). Copy a new code from Setup › Data collection › Browser extension.']);

$op = (string)($_GET['op'] ?? '');
$req = json_decode((string)file_get_contents('php://input'), true); if (!is_array($req)) $req = [];
try { $pdo = connect(); } catch (Throwable $e) { ext_out(500, ['ok' => false, 'message' => 'The Supply Room\'s database didn\'t answer.']); }

$host = fn(string $u) => strtolower(preg_replace('#^www\.#', '', (string)parse_url($u, PHP_URL_HOST)));

if ($op === 'ping') ext_out(200, ['ok' => true, 'name' => (string)cfg('OFFICE_NAME', 'Supply Room')]);

if ($op === 'index') {
    // What the extension needs to recognise a product page: links and item numbers per supplier, and the current price.
    $items = all_docs($pdo, 'items');
    $names = []; foreach ($items as $it) { $n = trim((string)($it['supplier'] ?? '')); if ($n !== '') $names[mb_strtolower($n)] = $n; }
    $sites = [];   // which supplier each website belongs to: saved websites, then the links already on products
    foreach (all_docs($pdo, 'supinfo') as $si) { $k = mb_strtolower(trim((string)($si['key'] ?? ''))); $w = trim((string)($si['website'] ?? '')); if ($k !== '' && $w !== '') { $h = $host(preg_match('#^https?://#i', $w) ? $w : 'https://' . $w); if ($h !== '') $sites[$h] = $names[$k] ?? $k; } }
    $out = [];
    foreach ($items as $docId => $it) {
        if (!empty($it['archived'])) continue;
        $urls = []; $codes = [];
        foreach (array_merge([['url' => $it['link'] ?? '', 'sku' => $it['sku'] ?? '', 'sup' => $it['supplier'] ?? '']], array_values((array)($it['links'] ?? [])), array_values(array_filter((array)($it['offers'] ?? []), 'is_array'))) as $l) {
            if (!is_array($l)) continue;
            $u = trim((string)($l['url'] ?? ''));
            if ($u !== '' && preg_match('#^https?://#i', $u)) {
                $h = $host($u); $urls[] = $h . rtrim((string)parse_url($u, PHP_URL_PATH), '/');
                $sup = trim((string)($l['sup'] ?? $l['src'] ?? ''));
                if ($sup !== '' && !isset($sites[$h])) $sites[$h] = $sup;
            }
            $c = trim((string)($l['sku'] ?? '')); if ($c !== '') $codes[] = $c;
        }
        if (trim((string)($it['mfr'] ?? '')) !== '') $codes[] = (string)$it['mfr'];
        $out[] = ['id' => (string)$docId, 'name' => (string)($it['name'] ?? ''), 'supplier' => (string)($it['supplier'] ?? ''), 'units' => (string)($it['units'] ?? ''),
                  'price' => (string)($it['unitCost'] ?? ''), 'urls' => array_values(array_unique($urls)), 'codes' => array_values(array_unique($codes))];
    }
    $sites = array_map(fn($h, $n) => ['host' => $h, 'name' => $n], array_keys($sites), array_values($sites));
    ext_out(200, ['ok' => true, 'at' => time(), 'sites' => $sites, 'items' => $out]);
}

if ($op === 'price') {
    $id = (string)($req['id'] ?? ''); $pr = (float)($req['price'] ?? 0);
    if ($id === '' || $pr <= 0 || $pr > 100000) ext_out(400, ['ok' => false, 'message' => 'Missing product or price.']);
    $url = (string)($req['url'] ?? ''); if (!preg_match('#^https://#i', $url)) ext_out(400, ['ok' => false, 'message' => 'The page address is missing.']);
    try {
        $pdo->beginTransaction();
        $res = call_tool($pdo, 'record_price', ['id' => $id, 'supplier' => mb_substr((string)($req['supplier'] ?? ''), 0, 80), 'price' => $pr,
            'pack' => mb_substr((string)($req['pack'] ?? ''), 0, 80), 'url' => mb_substr($url, 0, 500), 'sku' => mb_substr((string)($req['sku'] ?? ''), 0, 60),
            'product' => mb_substr((string)($req['product'] ?? ''), 0, 120), 'signed_in' => !empty($req['signedIn']),
            'list_price' => is_numeric($req['listPrice'] ?? null) ? (float)$req['listPrice'] : null, 'initials' => 'EXT']);
        $pdo->commit();
        ext_out(200, ['ok' => true] + json_decode((string)($res['content'][0]['text'] ?? '{}'), true));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        ext_out($e instanceof RuntimeException ? 400 : 500, ['ok' => false, 'message' => $e instanceof RuntimeException ? $e->getMessage() : 'The server had a problem saving the price.']);
    }
}

ext_out(400, ['ok' => false, 'message' => 'Unknown request.']);
