<?php
/* Tiles for the TDC Dental Dashboard (TDC-Office-Plan CONTRACT.md §5b).
 *   GET /tdc/tiles/to-order   products at or below their reorder point (the Order list)
 *   GET /tdc/tiles/waiting    products ordered and not yet received (On the way)
 * Only the Dashboard's server calls this, with "Authorization: Bearer <TILE_TOKEN>". TILE_TOKEN is made in
 * Setup › Office › Dashboard tiles (or set in config.php). Counts only: no product names, prices or notes.
 * No token set = tiles are off (404). Wrong or missing token = 401. More than 60 calls a minute = 429. */
define('TDC_TOOLS_ONLY', true);
require __DIR__ . '/mcp.php';   // shared code (connect, all_docs, derive, cfg), without the connector's request handling

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function tile_out(int $code, array $body): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
const TILE_IDS = ['to-order', 'waiting'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') tile_out(405, ['error' => 'method']);
$id = (string)($_GET['id'] ?? '');
$token = trim((string)cfg('TILE_TOKEN'));
if (strlen($token) < 32) tile_out(404, ['error' => 'off']);                 // tiles turned off
$auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
$given = preg_match('/^Bearer\s+(\S+)$/i', trim($auth), $m) ? $m[1] : '';
if ($given === '' || !hash_equals($token, $given)) tile_out(401, ['error' => 'unauthorized']);
if (!in_array($id, TILE_IDS, true)) tile_out(404, ['error' => 'no_tile']);

try { $pdo = connect(); } catch (Throwable $e) { tile_out(503, ['error' => 'database']); }

// About 60 calls a minute, shared by all tiles (kept in the sign-in failures table under a name of its own)
$now = time();
try {
    $pdo->prepare('DELETE FROM sr_fails WHERE ip = ? AND ts < ?')->execute(['tdc-tiles', $now - 60]);
    $st = $pdo->prepare('SELECT COUNT(*) AS n FROM sr_fails WHERE ip = ? AND ts >= ?'); $st->execute(['tdc-tiles', $now - 60]);
    if ((int)$st->fetch()['n'] >= 60) { header('Retry-After: 60'); tile_out(429, ['error' => 'rate_limited']); }
    $pdo->prepare('INSERT INTO sr_fails (ip, ts) VALUES (?, ?)')->execute(['tdc-tiles', $now]);
} catch (Throwable $e) { /* the limit is a guard, not a reason to fail the tile */ }

$base = 'https://' . preg_replace('/[^A-Za-z0-9.\-:]/', '', (string)($_SERVER['HTTP_HOST'] ?? '')) . rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/tiles.php'))), '/') . '/';
$need = 0; $urgent = 0; $waiting = 0;
foreach (all_docs($pdo, 'items') as $it) {
    if (!is_array($it) || !empty($it['archived']) || trim((string)($it['name'] ?? '')) === '') continue;
    $d = derive($it);
    if ($d['need']) { $need++; if ($d['is911']) $urgent++; }
    if ($d['onOrder']) $waiting++;
}
$updated = date('c');
$plural = fn(int $n, string $one, string $many) => $n . ' ' . ($n === 1 ? $one : $many);
if ($id === 'to-order') {
    tile_out(200, ['title' => 'To order', 'value' => $plural($need, 'product', 'products') . ($urgent ? ' · ' . $urgent . ' at 911' : ''),
        'status' => $urgent ? 'alert' : ($need ? 'warn' : 'ok'), 'items' => [], 'link' => $base . '#need', 'updated' => $updated]);
}
tile_out(200, ['title' => 'Waiting to arrive', 'value' => $plural($waiting, 'product', 'products'),
    'status' => 'ok', 'items' => [], 'link' => $base . '#ordered', 'updated' => $updated]);
