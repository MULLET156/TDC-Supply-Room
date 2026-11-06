<?php
/*
 * TDC Supply Room – connector for Claude (Model Context Protocol, Streamable HTTP)
 *
 * Add it in Claude: Settings → Connectors → Add custom connector, with the URL
 *   https://<your site>/mcp.php?key=<MCP_KEY from config.php>
 * Anyone who has that full link can read and change the inventory, so keep it private.
 * Turning it off: set MCP_KEY to '' in config.php. Changing MCP_KEY disconnects old links.
 */

declare(strict_types=1);
require __DIR__ . '/lib.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
load_config();

function rpc_out($id, $result = null, ?array $error = null): void {
    header('Content-Type: application/json');
    $msg = ['jsonrpc' => '2.0', 'id' => $id];
    if ($error) $msg['error'] = $error; else $msg['result'] = $result;
    echo json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$key = (string)cfg('MCP_KEY');
$given = (string)($_GET['key'] ?? '');
if ($given === '' && preg_match('/^Bearer\s+(.+)$/i', (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''), $m)) $given = trim($m[1]);
if (strlen($key) < 24 || !hash_equals($key, $given)) { http_response_code(401); header('Content-Type: application/json'); echo '{"error":"unauthorized"}'; exit; }

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'GET' || $method === 'DELETE') { http_response_code(405); header('Allow: POST'); exit; }
if ($method !== 'POST') { http_response_code(405); exit; }

$raw = file_get_contents('php://input');
$msg = json_decode((string)$raw, true);
if (!is_array($msg)) rpc_out(null, null, ['code' => -32700, 'message' => 'Parse error']);
if (array_is_list($msg)) rpc_out(null, null, ['code' => -32600, 'message' => 'Batches are not supported']);
$id = $msg['id'] ?? null;
$m = (string)($msg['method'] ?? '');
$params = is_array($msg['params'] ?? null) ? $msg['params'] : [];

// Notifications (no id) get an empty "accepted".
if (!array_key_exists('id', $msg)) { http_response_code(202); exit; }

/* ---------- tools ---------- */
const FIELDS = ['name', 'sku', 'supplier', 'link', 'unitCost', 'units', 'desc', 'cat', 'sub', 'loc', 'qty', 'desired', 'urgent', 'notes', 'exp'];
function tools(): array {
    $s = fn(string $d) => ['type' => 'string', 'description' => $d];
    $n = fn(string $d) => ['type' => 'number', 'description' => $d];
    $itemProps = [
        'name' => $s('Short inventory name: brand + product + what tells it apart (shade, size, grit). Leave package counts out of the name.'),
        'sku' => $s('Supplier item / catalog number'),
        'supplier' => $s('Supplier name as the office uses it (see list_options)'),
        'link' => $s('Product page address'),
        'unitCost' => $s('Price for one unit as ordered, e.g. "$42.99"'),
        'units' => $s('What one unit is, e.g. "Box of 100", "Each"'),
        'desc' => $s('One or two sentences on what it is'),
        'cat' => $s('Category (see list_options)'), 'sub' => $s('Subcategory, if any'),
        'loc' => $s('Storage location (see list_options)'),
        'qty' => $n('On hand'), 'desired' => $n('Desired reserve: reorder when on hand drops below this'),
        'urgent' => $n('911 level: at or below this it is urgent'),
        'notes' => $s('Notes'), 'exp' => $s('Expiration date, M/D/YYYY'),
    ];
    $who = ['initials' => $s('Initials to record in the activity log (default "CL" for Claude)')];
    return [
        ['name' => 'search_items', 'description' => 'Find supply items. Returns id, name, on hand, reserve, 911 level, order status and supplier for each match.',
         'inputSchema' => ['type' => 'object', 'properties' => [
            'query' => $s('Words to match in the name, SKU, supplier, category, location or notes'),
            'status' => ['type' => 'string', 'enum' => ['all', 'to_order', 'on_order', 'urgent', 'expiring'], 'description' => 'Filter: to_order = needs ordering now; on_order = ordered, not received; urgent = at 911 level; expiring = expires soon'],
            'supplier' => $s('Only this supplier'), 'category' => $s('Only this category'), 'location' => $s('Only this location'),
            'limit' => $n('Most results to return (default 50)')]],
         'annotations' => ['readOnlyHint' => true]],
        ['name' => 'get_item', 'description' => 'Everything stored about one item, by id.',
         'inputSchema' => ['type' => 'object', 'properties' => ['id' => $s('Item id')], 'required' => ['id']], 'annotations' => ['readOnlyHint' => true]],
        ['name' => 'list_options', 'description' => 'The office\'s suppliers, categories (with subcategories), storage locations and team initials. Use these exact names when adding or updating items.',
         'inputSchema' => ['type' => 'object', 'properties' => new stdClass()], 'annotations' => ['readOnlyHint' => true]],
        ['name' => 'add_item', 'description' => 'Add a new product to the inventory. Check search_items first so it is not a duplicate. Give photo_url (a direct link to the product picture) to save a photo.',
         'inputSchema' => ['type' => 'object', 'properties' => $itemProps + ['photo_url' => $s('Direct address of the main product photo')] + $who, 'required' => ['name']]],
        ['name' => 'update_item', 'description' => 'Change an item\'s details (only the fields you give). photo_url replaces its photo.',
         'inputSchema' => ['type' => 'object', 'properties' => ['id' => $s('Item id')] + $itemProps + ['photo_url' => $s('Direct address of a new product photo')] + $who, 'required' => ['id']]],
        ['name' => 'set_count', 'description' => 'Record a new on-hand count for an item.',
         'inputSchema' => ['type' => 'object', 'properties' => ['id' => $s('Item id'), 'qty' => $n('Counted on hand')] + $who, 'required' => ['id', 'qty']]],
        ['name' => 'add_to_order_list', 'description' => 'Put an item on the Order list by hand (even if stock looks fine), or change how many to order. qty 0 takes it off.',
         'inputSchema' => ['type' => 'object', 'properties' => ['id' => $s('Item id'), 'qty' => $n('How many to order')] + $who, 'required' => ['id', 'qty']]],
        ['name' => 'mark_ordered', 'description' => 'Mark items as ordered (moves them to On order). qty defaults to the suggested order amount.',
         'inputSchema' => ['type' => 'object', 'properties' => ['items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['id' => $s('Item id'), 'qty' => $n('How many were ordered')], 'required' => ['id']]]] + $who, 'required' => ['items']]],
        ['name' => 'mark_received', 'description' => 'Mark an ordered item as received and add it to stock. qty defaults to the amount that was ordered.',
         'inputSchema' => ['type' => 'object', 'properties' => ['id' => $s('Item id'), 'qty' => $n('How many arrived')] + $who, 'required' => ['id']]],
        ['name' => 'read_product_page', 'description' => 'Have the Supply Room server read a supplier product page and suggest name, SKU, price, units, description and photo. Pages that need sign-in may not show prices; if you can open the page yourself (signed in), prefer what you see there.',
         'inputSchema' => ['type' => 'object', 'properties' => ['url' => $s('Product page address')], 'required' => ['url']], 'annotations' => ['readOnlyHint' => true]],
    ];
}

function text_result($v, bool $err = false): array {
    return ['content' => [['type' => 'text', 'text' => is_string($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)]], 'isError' => $err];
}
function brief(string $id, array $it, int $expDays): array {
    $d = derive($it, $expDays);
    return array_filter([
        'id' => $id, 'name' => $it['name'] ?? '', 'sku' => $it['sku'] ?? '', 'supplier' => $it['supplier'] ?? '',
        'onHand' => numv($it['qty'] ?? null), 'reserve' => numv($it['desired'] ?? null), 'level911' => numv($it['urgent'] ?? null),
        'units' => $it['units'] ?? '', 'unitCost' => $it['unitCost'] ?? '', 'location' => $it['loc'] ?? '', 'category' => trim(($it['cat'] ?? '') . ' › ' . ($it['sub'] ?? ''), ' ›'),
        'status' => $d['onOrder'] ? 'on order since ' . $it['ordered'] . ' (' . ($it['orderedQty'] ?? '') . ')' : ($d['need'] ? ($d['is911'] ? 'URGENT (911) — order ' : 'to order — ') . $d['orderQty'] : 'ok'),
        'expires' => ($it['exp'] ?? '') !== '' ? $it['exp'] . ($d['expiring'] ? ' (soon)' : '') : '',
        'link' => $it['link'] ?? '',
    ], fn($v) => $v !== '' && $v !== null);
}
function exp_days(PDO $pdo): int { $r = load($pdo, 'settings', 'rules'); $v = numv($r['expDays'] ?? null); return $v === null ? 60 : (int)max(0, min(365, $v)); }
function who(array $a): string { $w = strtoupper(trim((string)($a['initials'] ?? ''))); return preg_match('/^[A-Z0-9]{1,4}$/', $w) ? $w : 'CL'; }
function logit(PDO $pdo, string $action, string $itemId, string $name, string $detail, string $who): void {
    save($pdo, 'log', new_id(), ['at' => (int)round(microtime(true) * 1000), 'initials' => $who, 'action' => $action, 'itemId' => $itemId, 'name' => $name, 'detail' => $detail]);
}
function fmtq($v): string { $v = (float)$v; return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.'); }
function item_or_fail(PDO $pdo, string $id): array { $it = $id !== '' ? load($pdo, 'items', $id) : null; if ($it === null) throw new RuntimeException("No item with id \"$id\". Use search_items to find it."); return $it; }
function clean_fields(array $a): array {
    $p = [];
    foreach (FIELDS as $k) {
        if (!array_key_exists($k, $a)) continue;
        $v = $a[$k];
        if (in_array($k, ['qty', 'desired', 'urgent'], true)) { $p[$k] = $v === null || $v === '' ? null : numv($v); continue; }
        if (!is_scalar($v) && $v !== null) continue;
        $p[$k] = mb_substr(trim((string)$v), 0, $k === 'desc' || $k === 'notes' ? 2000 : 300);
    }
    if (isset($p['unitCost']) && $p['unitCost'] !== '' && is_numeric(str_replace('$', '', $p['unitCost']))) $p['unitCost'] = '$' . number_format((float)str_replace('$', '', $p['unitCost']), 2);
    return $p;
}
function save_photo(PDO $pdo, string $url): string {
    $im = fetch_image($url);
    if (!$im) throw new RuntimeException('Couldn\'t download a picture from photo_url (it must be a direct, public image link).');
    return blob_put($pdo, $im['bytes'], $im['type']);
}

function call_tool(PDO $pdo, string $name, array $a): array {
    switch ($name) {
        case 'search_items': {
            $ed = exp_days($pdo); $q = mb_strtolower(trim((string)($a['query'] ?? ''))); $st = (string)($a['status'] ?? 'all');
            $words = $q === '' ? [] : preg_split('/\s+/', $q);
            $res = [];
            foreach (all_docs($pdo, 'items') as $id => $it) {
                if (empty($it['name'])) continue;
                $d = derive($it, $ed);
                if ($st === 'to_order' && !$d['need']) continue;
                if ($st === 'on_order' && !$d['onOrder']) continue;
                if ($st === 'urgent' && !($d['is911'] && !$d['onOrder'])) continue;
                if ($st === 'expiring' && !$d['expiring']) continue;
                foreach (['supplier' => 'supplier', 'category' => 'cat', 'location' => 'loc'] as $arg => $f) {
                    if (!empty($a[$arg]) && mb_strtolower((string)($it[$f] ?? '')) !== mb_strtolower((string)$a[$arg])) continue 2;
                }
                $hay = mb_strtolower(implode(' ', array_map(fn($k) => (string)($it[$k] ?? ''), ['name', 'sku', 'supplier', 'cat', 'sub', 'loc', 'notes', 'units'])));
                foreach ($words as $w) if (!str_contains($hay, $w)) continue 2;
                $res[] = brief((string)$id, $it, $ed);
            }
            $lim = max(1, min(400, (int)($a['limit'] ?? 50)));
            return text_result(['count' => count($res), 'shown' => min(count($res), $lim), 'items' => array_slice($res, 0, $lim)]);
        }
        case 'get_item': {
            $id = (string)($a['id'] ?? ''); $it = item_or_fail($pdo, $id);
            unset($it['skuPhoto']);
            return text_result(['id' => $id] + $it + ['derived' => derive($it, exp_days($pdo))]);
        }
        case 'list_options': {
            $items = all_docs($pdo, 'items');
            $sup = []; $cats = []; $locs = [];
            foreach ($items as $it) {
                if (!empty($it['supplier'])) $sup[$it['supplier']] = true;
                if (!empty($it['cat'])) { $cats[$it['cat']] ??= []; if (!empty($it['sub'])) $cats[$it['cat']][$it['sub']] = true; }
                if (!empty($it['loc'])) $locs[$it['loc']] = true;
            }
            // 'order' holds lowercase sort keys, not names, so only the names in 'extra' are added; one entry per supplier, whatever the capitals.
            $s = load($pdo, 'settings', 'suppliers') ?? []; foreach ($s['extra'] ?? [] as $x) if (is_string($x) && $x !== '') $sup[$x] = true;
            $one = []; foreach (array_keys($sup) as $n) { $k = preg_replace('/[^a-z0-9]+/', '', mb_strtolower($n)); if (!isset($one[$k]) || (!preg_match('/[A-Z]/', $one[$k]) && preg_match('/[A-Z]/', $n))) $one[$k] = $n; }
            $sup = array_fill_keys(array_values($one), true);
            $c = load($pdo, 'settings', 'cats') ?? []; foreach ($c['extra'] ?? [] as $x) if (!empty($x['c'])) { $cats[$x['c']] ??= []; if (!empty($x['s'])) $cats[$x['c']][$x['s']] = true; }
            $l = load($pdo, 'settings', 'locs') ?? []; foreach ($l['extra'] ?? [] as $x) if (is_string($x) && $x !== '') $locs[$x] = true;
            $team = (load($pdo, 'settings', 'team') ?? [])['initials'] ?? [];
            ksort($cats);
            return text_result(['suppliers' => array_keys($sup), 'categories' => array_map(fn($v) => array_keys($v), $cats), 'locations' => array_keys($locs), 'team' => $team]);
        }
        case 'add_item': {
            $p = clean_fields($a);
            if (isset($p['supplier'])) $p['supplier'] = canon_supplier($pdo, (string)$p['supplier']);
            if (($p['name'] ?? '') === '') throw new RuntimeException('name is required.');
            $w = who($a);
            $doc = array_merge(['name' => '', 'sku' => '', 'supplier' => '', 'link' => '', 'unitCost' => '', 'units' => '', 'desc' => '', 'cat' => '', 'sub' => '', 'loc' => '', 'qty' => 0, 'desired' => null, 'urgent' => null, 'notes' => '', 'exp' => '', 'ordered' => '', 'orderedQty' => 0], $p);
            if (!empty($a['photo_url'])) $doc['photo'] = save_photo($pdo, (string)$a['photo_url']);
            $doc['updatedAt'] = (int)round(microtime(true) * 1000); $doc['updatedBy'] = $w;
            $id = new_id();
            save($pdo, 'items', $id, $doc);
            logit($pdo, 'Added', $id, $doc['name'], 'Added from Claude', $w);
            return text_result(['added' => true, 'id' => $id, 'photo' => !empty($doc['photo'])]);
        }
        case 'update_item': {
            $id = (string)($a['id'] ?? ''); $it = item_or_fail($pdo, $id); $w = who($a);
            $p = clean_fields($a);
            if (isset($p['supplier'])) $p['supplier'] = canon_supplier($pdo, (string)$p['supplier']);
            if (!empty($a['photo_url'])) { $old = $it['photo'] ?? ''; $p['photo'] = save_photo($pdo, (string)$a['photo_url']); if ($old) $pdo->prepare('DELETE FROM sr_blobs WHERE id = ?')->execute([$old]); }
            if (!$p) throw new RuntimeException('Nothing to change.');
            $changed = array_keys($p);
            $it = array_merge($it, $p, ['updatedAt' => (int)round(microtime(true) * 1000), 'updatedBy' => $w]);
            if (array_key_exists('qty', $p)) { $it['countedAt'] = $it['updatedAt']; $it['countedBy'] = $w; }
            save($pdo, 'items', $id, $it);
            logit($pdo, 'Edited', $id, $it['name'] ?? '', 'Changed ' . implode(', ', $changed) . ' from Claude', $w);
            return text_result(['updated' => true, 'id' => $id, 'fields' => $changed]);
        }
        case 'set_count': {
            $id = (string)($a['id'] ?? ''); $it = item_or_fail($pdo, $id); $w = who($a);
            $q = numv($a['qty'] ?? null); if ($q === null || $q < 0) throw new RuntimeException('qty must be 0 or more.');
            $was = numv($it['qty'] ?? null); $now = (int)round(microtime(true) * 1000);
            $it = array_merge($it, ['qty' => $q, 'updatedAt' => $now, 'updatedBy' => $w, 'countedAt' => $now, 'countedBy' => $w]);
            save($pdo, 'items', $id, $it);
            logit($pdo, 'Count', $id, $it['name'] ?? '', ($was === null ? '–' : fmtq($was)) . ' → ' . fmtq($q) . (!empty($it['units']) ? ' ' . $it['units'] : ''), $w);
            return text_result(['id' => $id, 'onHand' => $q] + ['status' => brief($id, $it, exp_days($pdo))['status']]);
        }
        case 'add_to_order_list': {
            $id = (string)($a['id'] ?? ''); $it = item_or_fail($pdo, $id); $w = who($a);
            $q = max(0, (float)(numv($a['qty'] ?? null) ?? 0));
            $it = array_merge($it, ['orderReq' => $q, 'orderAmt' => 0, 'updatedAt' => (int)round(microtime(true) * 1000), 'updatedBy' => $w]);
            save($pdo, 'items', $id, $it);
            logit($pdo, $q > 0 ? 'Added to order list' : 'Removed from order list', $id, $it['name'] ?? '', $q > 0 ? 'Order ' . fmtq($q) . ' (from Claude)' : 'from Claude', $w);
            return text_result(['id' => $id, 'orderReq' => $q]);
        }
        case 'mark_ordered': {
            $w = who($a); $ed = exp_days($pdo); $done = []; $lines = [];
            foreach ((array)($a['items'] ?? []) as $row) {
                $id = (string)($row['id'] ?? ''); $it = item_or_fail($pdo, $id);
                $q = numv($row['qty'] ?? null) ?? derive($it, $ed)['orderQty']; if (!$q) $q = 1;
                $it = array_merge($it, ['ordered' => today_str(), 'orderedQty' => $q, 'orderReq' => 0, 'orderAmt' => 0, 'updatedAt' => (int)round(microtime(true) * 1000), 'updatedBy' => $w]);
                save($pdo, 'items', $id, $it);
                logit($pdo, 'Ordered', $id, $it['name'] ?? '', fmtq($q) . (!empty($it['units']) ? ' × ' . $it['units'] : '') . (!empty($it['supplier']) ? ' from ' . $it['supplier'] : ''), $w);
                $lines[] = ['id' => $id, 'name' => $it['name'] ?? '', 'qty' => $q, 'units' => $it['units'] ?? '', 'supplier' => $it['supplier'] ?? ''];
                $done[] = ['id' => $id, 'name' => $it['name'] ?? '', 'qty' => $q];
            }
            if (!$done) throw new RuntimeException('Give at least one item.');
            save($pdo, 'orders', new_id(), ['at' => (int)round(microtime(true) * 1000), 'initials' => $w, 'lines' => $lines]);
            return text_result(['ordered' => $done]);
        }
        case 'mark_received': {
            $id = (string)($a['id'] ?? ''); $it = item_or_fail($pdo, $id); $w = who($a);
            $got = numv($a['qty'] ?? null) ?? (numv($it['orderedQty'] ?? null) ?: derive($it, exp_days($pdo))['orderQty']);
            if (!$got || $got <= 0) throw new RuntimeException('How many arrived? Give qty.');
            $now = (numv($it['qty'] ?? null) ?? 0) + $got;
            $it = array_merge($it, ['qty' => $now, 'ordered' => '', 'orderedQty' => 0, 'orderAmt' => 0, 'lastReceived' => today_str(), 'updatedAt' => (int)round(microtime(true) * 1000), 'updatedBy' => $w]);
            save($pdo, 'items', $id, $it);
            logit($pdo, 'Received', $id, $it['name'] ?? '', '+' . fmtq($got) . ', now ' . fmtq($now) . ' on hand', $w);
            return text_result(['id' => $id, 'received' => $got, 'onHand' => $now]);
        }
        case 'read_product_page': {
            $r = read_product_link((string)($a['url'] ?? ''), [], true);  // the connector has to answer quickly: skip Apify's slow full browser
            if (!$r['ok']) throw new RuntimeException($r['message']);
            if ($r['photo']) { $r['photoFound'] = true; unset($r['photo']); }
            return text_result($r);
        }
    }
    throw new RuntimeException("Unknown tool $name");
}

switch ($m) {
    case 'initialize':
        rpc_out($id, [
            'protocolVersion' => in_array($params['protocolVersion'] ?? '', ['2025-06-18', '2025-03-26', '2024-11-05'], true) ? $params['protocolVersion'] : '2025-06-18',
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => 'tdc-supply-room', 'title' => 'TDC Supply Room', 'version' => '1.0.0'],
            'instructions' => 'Toledo Dental Care supply inventory. Find items with search_items before changing them. Use list_options for exact supplier, category and location names. Every change is recorded in the activity log.',
        ]);
    case 'ping':
        rpc_out($id, new stdClass());
    case 'tools/list':
        rpc_out($id, ['tools' => tools()]);
    case 'tools/call': {
        $name = (string)($params['name'] ?? '');
        $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        try {
            $pdo = connect();
            $pdo->beginTransaction();
            $res = call_tool($pdo, $name, $args);
            $pdo->commit();
            rpc_out($id, $res);
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            if (!($e instanceof RuntimeException)) error_log('tdc mcp: ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
            rpc_out($id, text_result($e instanceof RuntimeException ? $e->getMessage() : 'The server had a problem (' . get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 200) . ' at ' . basename($e->getFile()) . ':' . $e->getLine() . '). Try again.', true));
        }
    }
    default:
        rpc_out($id, null, ['code' => -32601, 'message' => 'Method not found']);
}

// One spelling per supplier: "henry schein", "HENRY SCHEIN" and "Henry-Schein" all become the name already in use.
function canon_supplier(PDO $pdo, string $v): string {
    $v = trim(preg_replace('/\s+/', ' ', $v)); if ($v === '') return '';
    $key = fn($x) => preg_replace('/[^a-z0-9]+/', '', mb_strtolower($x));
    $k = $key($v); $seen = [];
    foreach (all_docs($pdo, 'items') as $it) { $n = trim((string)($it['supplier'] ?? '')); if ($n !== '' && $key($n) === $k) $seen[$n] = ($seen[$n] ?? 0) + 1; }
    $st = load($pdo, 'settings', 'suppliers') ?? []; foreach ((array)($st['extra'] ?? []) as $n) if (is_string($n) && $key($n) === $k) $seen[$n] = ($seen[$n] ?? 0) + 1;
    if ($seen) { uksort($seen, fn($a, $b) => [preg_match('/[A-Z]/', $b), $seen[$b]] <=> [preg_match('/[A-Z]/', $a), $seen[$a]]); $best = array_key_first($seen); }
    else $best = $v;
    return preg_match('/[A-Z]/', $best) ? $best : ucwords($best);
}
