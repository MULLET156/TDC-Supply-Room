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

// ext.php reuses the tools below (record_price) without the connector's request handling
if (!defined('TDC_TOOLS_ONLY')) {
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
}


/* ---------- tools ---------- */
const FIELDS = ['name', 'sku', 'supplier', 'link', 'unitCost', 'units', 'desc', 'cat', 'sub', 'loc', 'qty', 'desired', 'urgent', 'notes', 'exp', 'countEvery'];
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
        'countEvery' => ['type' => 'string', 'enum' => ['', '7', '14', '30', '91', '182', '365', '0'], 'description' => 'How often to count it, in days: 7 weekly, 14 biweekly, 30 monthly, 91 quarterly, 182 every 6 months, 365 yearly, 0 = never count; "" = the office default'],
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
        ['name' => 'mark_ordered', 'description' => 'Mark items as ordered (moves them to On the way). qty defaults to the suggested order amount.',
         'inputSchema' => ['type' => 'object', 'properties' => ['items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['id' => $s('Item id'), 'qty' => $n('How many were ordered')], 'required' => ['id']]]] + $who, 'required' => ['items']]],
        ['name' => 'mark_received', 'description' => 'Mark an ordered item as received and add it to stock. qty defaults to the amount that was ordered.',
         'inputSchema' => ['type' => 'object', 'properties' => ['id' => $s('Item id'), 'qty' => $n('How many arrived')] + $who, 'required' => ['id']]],
        ['name' => 'find_price_pages', 'description' => 'Search for an item\'s product pages at the office\'s suppliers (scope "mine") or anywhere online (scope "web"), for price comparison. Returns page addresses; read each with read_product_page, or open it signed in, then save prices with record_price.',
         'inputSchema' => ['type' => 'object', 'properties' => ['id' => $s('Item id'), 'scope' => ['type' => 'string', 'enum' => ['mine', 'web'], 'description' => 'mine = the office\'s suppliers (default); web = other stores']], 'required' => ['id']], 'annotations' => ['readOnlyHint' => true]],
        ['name' => 'record_price', 'description' => 'Save a price found for an item at a supplier (for example one you read from a supplier page you are signed in to). It becomes that supplier\'s current price in the item\'s Prices table and is added to the item\'s price history, so prices can be compared over time. Returns the previous price from the same supplier and the change.',
         'inputSchema' => ['type' => 'object', 'properties' => ['id' => $s('Item id'), 'supplier' => $s('Store name (use list_options names for the office\'s suppliers)'), 'price' => $n('Price for one unit as sold'), 'pack' => $s('What one unit is, e.g. Box of 100'), 'url' => $s('Product page address'), 'sku' => $s('The store\'s item number'), 'signed_in' => ['type' => 'boolean', 'description' => 'true only if read from a signed-in (account) page showing the account\'s own price. Signed-out pages show the list price: pass false, and a special price already saved for the account is kept.'], 'list_price' => $n('The list (regular) price, if the page shows it beside a lower account, special or sale price'), 'promo' => $s('If the lower price is a temporary promotion or sale, what it is (e.g. "Buy 2 get 1" or "Sale ends 12/31")'), 'similar' => ['type' => 'boolean', 'description' => 'true if the supplier doesn\'t sell this exact product and this is a similar substitute (different brand, size or version)'], 'product' => $s('The product name on the supplier page (always give it for a similar product)')] + $who, 'required' => ['id', 'supplier', 'price']]],
        ['name' => 'price_history', 'description' => 'An item\'s price history: for each supplier the latest, previous, lowest and highest price and when it was checked, plus every recorded price check, newest first. Prices for similar substitutes are kept apart (similarProduct names the product), since they are not this exact item.',
         'inputSchema' => ['type' => 'object', 'properties' => ['id' => $s('Item id'), 'supplier' => $s('Only this supplier'), 'limit' => $n('Most price checks to list (default 100)')], 'required' => ['id']]],
        ['name' => 'find_by_part_number', 'description' => 'Find a supplier\'s product page for an exact manufacturer part number (the same product): searches that supplier\'s site, reads the candidate pages, and returns only pages that show the part number, with the price read. Leave host empty to search the whole web.',
         'inputSchema' => ['type' => 'object', 'properties' => ['mpn' => $s('Manufacturer part number'), 'host' => $s('Supplier website host, e.g. net32.com; empty for the whole web')], 'required' => ['mpn']], 'annotations' => ['readOnlyHint' => true]],
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
        'status' => $d['onOrder'] ? 'on the way, ordered ' . $it['ordered'] . ' (' . ($it['orderedQty'] ?? '') . ')' : ($d['need'] ? ($d['is911'] ? 'URGENT (911) — order ' : 'to order — ') . $d['orderQty'] : 'ok'),
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

/* ---------- price history ----------
 * Collection "prices": one document per price check, never replaced, so prices can be followed over time.
 * Fields: at (ms), itemId, src (supplier), price, pack, sku, url, kind (live = signed in, public, invoice), by;
 * similar = true (and title) when the price is for a similar substitute, not this exact product.
 * The item's "offers" list still holds only the latest price per supplier (that is what the page's Prices table shows).
 */
function hist_day($ms): string { return date('n/j/Y', intdiv((int)$ms, 1000)); }
function hist_sort(array $rows): array { usort($rows, fn($a, $b) => [(int)($a['at'] ?? 0), (string)($a['src'] ?? '')] <=> [(int)($b['at'] ?? 0), (string)($b['src'] ?? '')]); return $rows; }
function hist_rows(PDO $pdo, string $itemId): array {
    $rows = [];
    foreach (all_docs($pdo, 'prices') as $hid => $h) if (($h['itemId'] ?? '') === $itemId) $rows[] = $h + ['_id' => (string)$hid];
    return hist_sort($rows);
}
function hist_similar(array $h): bool { return !empty($h['similar']); }
/* a history row as stored (without the helper id) */
function hist_doc(array $h): array { unset($h['_id']); return $h; }
// Prices saved on the item before the history existed, or entered in the page's Prices table, are copied in once,
// so a price is not lost when a newer one from the same supplier replaces it.
function sync_history(PDO $pdo, string $itemId, array $it): array {
    $rows = hist_rows($pdo, $itemId);
    $seen = [];
    foreach ($rows as $n => $h) $seen[mb_strtolower((string)($h['src'] ?? '')) . '|' . (int)($h['at'] ?? 0)] = $n;
    foreach ((array)($it['offers'] ?? []) as $o) {
        if (!is_array($o)) continue;
        $pr = numv($o['price'] ?? null); $at = (int)($o['at'] ?? 0); $src = trim((string)($o['src'] ?? ''));
        if (!$pr || $pr <= 0 || $at <= 0 || $src === '') continue;
        $sim = ($o['sure'] ?? true) === false; $title = mb_substr(trim((string)($o['title'] ?? '')), 0, 120);
        $k = mb_strtolower($src) . '|' . $at;
        if (isset($seen[$k])) {
            // saved before similar products were marked: mark it now
            $n = $seen[$k];
            if ($sim && !hist_similar($rows[$n]) && !empty($rows[$n]['_id'])) {
                $rows[$n]['similar'] = true; if ($title !== '') $rows[$n]['title'] = $title;
                save($pdo, 'prices', $rows[$n]['_id'], hist_doc($rows[$n]));
            }
            continue;
        }
        $h = ['at' => $at, 'itemId' => $itemId, 'src' => $src, 'price' => (float)$pr, 'pack' => trim((string)($o['pack'] ?? '')), 'sku' => trim((string)($o['sku'] ?? '')),
              'url' => trim((string)($o['url'] ?? '')), 'kind' => (string)($o['kind'] ?? ''), 'by' => (string)($o['by'] ?? '')];
        if ($sim) { $h['similar'] = true; if ($title !== '') $h['title'] = $title; }
        $hid = new_id(); save($pdo, 'prices', $hid, $h);
        $rows[] = $h + ['_id' => $hid]; $seen[$k] = count($rows) - 1;
    }
    return hist_sort($rows);
}

function call_tool(PDO $pdo, string $name, array $a): array {
    switch ($name) {
        case 'search_items': {
            $ed = exp_days($pdo); $q = mb_strtolower(trim((string)($a['query'] ?? ''))); $st = (string)($a['status'] ?? 'all');
            $words = $q === '' ? [] : preg_split('/\s+/', $q);
            $res = [];
            foreach (all_docs($pdo, 'items') as $id => $it) {
                if (empty($it['name']) || !empty($it['archived'])) continue;
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
            foreach (['cat', 'sub', 'loc'] as $cf) if (isset($p[$cf]) && $p[$cf] !== '') $p[$cf] = canon_name($pdo, $cf, (string)$p[$cf]);
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
            foreach (['cat', 'sub', 'loc'] as $cf) if (isset($p[$cf]) && $p[$cf] !== '') $p[$cf] = canon_name($pdo, $cf, (string)$p[$cf]);
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
                $pr = numv(preg_replace('/[$,\s]/', '', (string)($it['unitCost'] ?? '')));
                $lines[] = ['id' => $id, 'name' => $it['name'] ?? '', 'qty' => $q, 'units' => $it['units'] ?? '', 'supplier' => $it['supplier'] ?? '', 'cat' => $it['cat'] ?? ''] + ($pr && $pr > 0 ? ['price' => $pr] : []);
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
        case 'find_price_pages': {
            $id = (string)($a['id'] ?? ''); $it = item_or_fail($pdo, $id);
            $sites = [];
            $info = []; foreach (all_docs($pdo, 'supinfo') as $si) { $k = mb_strtolower(trim((string)($si['key'] ?? $si['name'] ?? ''))); if ($k !== '' && trim((string)($si['website'] ?? '')) !== '') $info[$k] = trim((string)$si['website']); }
            $names = []; foreach (all_docs($pdo, 'items') as $x) { $n = trim((string)($x['supplier'] ?? '')); if ($n !== '') $names[mb_strtolower($n)] = $n; }
            foreach ($names as $k => $n) { $w = $info[$k] ?? supplier_site_guess($n); if ($w !== '') $sites[] = ['name' => $n, 'site' => $w]; }
            $r = price_search(['name' => $it['name'] ?? '', 'sku' => $it['sku'] ?? '', 'mfr' => $it['mfr'] ?? '', 'units' => $it['units'] ?? ''], $sites, ($a['scope'] ?? '') === 'web' ? 'web' : 'mine');
            if (!$r['ok']) throw new RuntimeException($r['message']);
            return text_result($r + ['item' => ['name' => $it['name'] ?? '', 'supplier' => $it['supplier'] ?? '', 'price' => $it['unitCost'] ?? '', 'units' => $it['units'] ?? '', 'link' => $it['link'] ?? '']]);
        }
        case 'record_price': {
            $id = (string)($a['id'] ?? ''); $it = item_or_fail($pdo, $id); $w = who($a);
            $pr = numv($a['price'] ?? null); if (!$pr || $pr <= 0) throw new RuntimeException('price must be more than 0.');
            $sup = canon_supplier($pdo, (string)($a['supplier'] ?? '')); if ($sup === '') throw new RuntimeException('supplier is required.');
            // Copy any price already on the item into the history first, so the one about to be replaced is kept.
            $hist = sync_history($pdo, $id, $it);
            $prev = null; foreach ($hist as $h) if (strcasecmp((string)($h['src'] ?? ''), $sup) === 0 && hist_similar($h) === !empty($a['similar'])) $prev = $h;
            $acct = !empty($a['signed_in']); $sim = !empty($a['similar']);
            $offer = ['src' => $sup, 'price' => $pr, 'pack' => trim((string)($a['pack'] ?? '')), 'url' => trim((string)($a['url'] ?? '')), 'sku' => trim((string)($a['sku'] ?? '')),
                      'kind' => $acct ? 'live' : 'public', 'at' => (int)round(microtime(true) * 1000), 'sure' => !$sim, 'by' => $w];
            if (trim((string)($a['product'] ?? '')) !== '') $offer['title'] = mb_substr(trim((string)$a['product']), 0, 120);
            $lp = numv($a['list_price'] ?? null);
            if ($lp && $lp > $pr + 0.004) { $offer['listPrice'] = round($lp, 2); $offer['tag'] = trim((string)($a['promo'] ?? '')) !== '' ? 'promo' : 'sp'; if ($offer['tag'] === 'promo') $offer['promo'] = mb_substr(trim((string)$a['promo']), 0, 80); }
            // Account prices (signed in, price lists, invoices) and website prices are kept apart, as in the Prices table:
            // a reading only replaces the earlier one of its own kind. A price typed in by the office is never replaced.
            $grp = fn($k) => in_array($k, ['live', 'list', 'invoice'], true) ? 'acct' : 'web';
            $sameSup = fn($o) => is_array($o) && strcasecmp((string)($o['src'] ?? ''), $sup) === 0 && (($o['sure'] ?? true) === false) === $sim;
            $spOld = null; foreach ((array)($it['offers'] ?? []) as $o) if ($sameSup($o) && $grp($o['kind'] ?? '') === 'acct' && in_array($o['tag'] ?? '', ['sp', 'promo'], true) && (!$spOld || ($o['at'] ?? 0) > ($spOld['at'] ?? 0))) $spOld = $o;
            if ($spOld && $acct && empty($offer['tag'])) {
                $oldLp = (float)($spOld['listPrice'] ?? 0);
                if ($oldLp > 0 && abs($pr - $oldLp) <= max(0.01, $oldLp * 0.005)) {
                    // the "signed-in" reading is the list price: the page was read signed out, or only the list price was found
                    logit($pdo, 'Price checked', $id, $it['name'] ?? '', $sup . ': list price $' . number_format($pr, 2) . ' seen; special price $' . number_format((float)$spOld['price'], 2) . ' kept', $w);
                    return text_result(['saved' => false, 'kept' => true, 'id' => $id, 'special' => (float)$spOld['price'], 'listPrice' => $oldLp,
                        'message' => 'That is the list price; the special price of $' . number_format((float)$spOld['price'], 2) . ' was kept. Sign in to the supplier\'s website to see your account price.']);
                }
                if (abs($pr - (float)$spOld['price']) < 0.005) { $offer['tag'] = $spOld['tag']; if ($oldLp > 0) $offer['listPrice'] = $oldLp; if (!empty($spOld['promo'])) $offer['promo'] = $spOld['promo']; }
            }
            $offers = array_values(array_filter((array)($it['offers'] ?? []), fn($o) => !($sameSup($o) && ($o['kind'] ?? '') !== 'invoice' && ($o['kind'] ?? '') !== 'typed' && $grp($o['kind'] ?? '') === $grp($offer['kind']))));
            $offers[] = $offer;
            $it['offers'] = array_slice($offers, -20);
            save($pdo, 'items', $id, $it);
            save($pdo, 'prices', new_id(), ['at' => $offer['at'], 'itemId' => $id, 'src' => $sup, 'price' => (float)$pr, 'pack' => $offer['pack'], 'sku' => $offer['sku'], 'url' => $offer['url'], 'kind' => $offer['kind'], 'by' => $w]
                + (!empty($a['similar']) ? ['similar' => true] + (isset($offer['title']) ? ['title' => $offer['title']] : []) : []));
            $res = ['saved' => true, 'id' => $id, 'offer' => $offer, 'previous' => null];
            $chg = '';
            if ($prev) {
                $pp = (float)$prev['price']; $d = round($pr - $pp, 2);
                $res['previous'] = ['price' => $pp, 'pack' => (string)($prev['pack'] ?? ''), 'checked' => hist_day($prev['at'] ?? 0)];
                $res['change'] = $d;
                if ($pp > 0) $res['changePercent'] = round($d / $pp * 100, 1);
                if (($prev['pack'] ?? '') !== '' && $offer['pack'] !== '' && strcasecmp((string)$prev['pack'], $offer['pack']) !== 0) $res['note'] = 'The pack size differs from the previous check, so the change may not compare like with like.';
                $chg = $d == 0 ? ', unchanged' : ', ' . ($d > 0 ? 'up' : 'down') . ' $' . number_format(abs($d), 2) . ' since ' . hist_day($prev['at'] ?? 0);
            }
            logit($pdo, 'Price checked', $id, $it['name'] ?? '', $sup . ': $' . number_format($pr, 2) . ($offer['pack'] ? ' / ' . $offer['pack'] : '') . ($offer['kind'] === 'live' ? ' (signed in)' : '') . $chg, $w);
            return text_result($res);
        }
        case 'price_history': {
            $id = (string)($a['id'] ?? ''); $it = item_or_fail($pdo, $id);
            $rows = sync_history($pdo, $id, $it);
            $sf = trim((string)($a['supplier'] ?? ''));
            if ($sf !== '') { $sf = canon_supplier($pdo, $sf); $rows = array_values(array_filter($rows, fn($h) => strcasecmp((string)($h['src'] ?? ''), $sf) === 0)); }
            $by = [];
            foreach ($rows as $h) $by[(string)($h['src'] ?? '') . (hist_similar($h) ? '|similar' : '')][] = $h;
            $sum = [];
            foreach ($by as $s => $hs) {
                $sim = str_ends_with($s, '|similar'); $s = $sim ? substr($s, 0, -8) : $s;
                $ps = array_map(fn($h) => (float)($h['price'] ?? 0), $hs);
                $last = $hs[count($hs) - 1]; $prev = count($hs) > 1 ? $hs[count($hs) - 2] : null;
                $sum[] = array_filter([
                    'supplier' => $s, 'similarProduct' => $sim ? ((string)($last['title'] ?? '') ?: 'yes, not this exact item') : null, 'latest' => (float)$last['price'], 'pack' => (string)($last['pack'] ?? ''), 'checked' => hist_day($last['at'] ?? 0),
                    'signedIn' => ($last['kind'] ?? '') === 'live',
                    'previous' => $prev ? (float)$prev['price'] : null, 'previousChecked' => $prev ? hist_day($prev['at'] ?? 0) : null,
                    'change' => $prev ? round((float)$last['price'] - (float)$prev['price'], 2) : null,
                    'lowest' => min($ps), 'highest' => max($ps), 'checks' => count($hs), 'firstChecked' => hist_day($hs[0]['at'] ?? 0),
                ], fn($v) => $v !== '' && $v !== null);
            }
            usort($sum, fn($x, $y) => [isset($x['similarProduct']), $x['latest']] <=> [isset($y['similarProduct']), $y['latest']]);
            $lim = max(1, min(500, (int)($a['limit'] ?? 100)));
            $list = array_map(fn($h) => array_filter([
                'date' => hist_day($h['at'] ?? 0), 'supplier' => (string)($h['src'] ?? ''), 'price' => (float)($h['price'] ?? 0), 'pack' => (string)($h['pack'] ?? ''),
                'similarProduct' => hist_similar($h) ? ((string)($h['title'] ?? '') ?: 'yes, not this exact item') : null,
                'sku' => (string)($h['sku'] ?? ''), 'signedIn' => ($h['kind'] ?? '') === 'live', 'by' => (string)($h['by'] ?? ''), 'url' => (string)($h['url'] ?? ''),
            ], fn($v) => $v !== '' && $v !== null), array_reverse($rows));
            return text_result(['item' => ['id' => $id, 'name' => $it['name'] ?? '', 'units' => $it['units'] ?? '', 'unitCost' => $it['unitCost'] ?? '', 'supplier' => $it['supplier'] ?? ''],
                                'suppliers' => $sum, 'checks' => count($list), 'shown' => min(count($list), $lim), 'history' => array_slice($list, 0, $lim)]);
        }
        case 'find_by_part_number': {
            @set_time_limit(240);
            $mpn = trim((string)($a['mpn'] ?? '')); $r = mpn_search($mpn, (string)($a['host'] ?? ''));
            if (!$r['ok']) throw new RuntimeException($r['message']);
            $out = [];
            foreach (array_slice($r['candidates'], 0, 4) as $c) {
                $p = read_product_link($c['url'], ['mpn' => $mpn]);
                $out[] = ['url' => $c['url'], 'title' => $c['title'], 'read' => $p['ok'] ?? false, 'mpnCheck' => $p['mpnCheck'] ?? '', 'price' => $p['price'] ?? null, 'units' => $p['units'] ?? '', 'sku' => $p['sku'] ?? '', 'mfrNumber' => $p['mfrNumber'] ?? '', 'error' => $p['message'] ?? ''];
                if (($p['mpnCheck'] ?? '') === 'found') break;
            }
            return text_result(['candidates' => $out]);
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

if (!defined('TDC_TOOLS_ONLY')) {
switch ($m) {
    case 'initialize':
        rpc_out($id, [
            'protocolVersion' => in_array($params['protocolVersion'] ?? '', ['2025-06-18', '2025-03-26', '2024-11-05'], true) ? $params['protocolVersion'] : '2025-06-18',
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => 'tdc-supply-room', 'title' => 'TDC Supply Room', 'version' => '1.1.0'],
            'instructions' => 'Toledo Dental Care supply inventory. Find items with search_items before changing them. Use list_options for exact supplier, category and location names. Every change is recorded in the activity log. Every record_price is kept in the price history; use price_history to see how an item\'s prices changed over time.',
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
}

// One spelling per supplier: "henry schein", "HENRY SCHEIN" and "Henry-Schein" all become the name already in use.
/* category / subcategory / location: reuse the existing spelling in any case; new ones get a capital first letter, rest lowercase */
function canon_name(PDO $pdo, string $field, string $v): string {
    $v = trim(preg_replace('/\s+/', ' ', $v)); if ($v === '') return '';
    $k = mb_strtolower($v); $seen = [];
    foreach (all_docs($pdo, 'items') as $it) { $n = trim((string)($it[$field] ?? '')); if ($n !== '' && mb_strtolower($n) === $k && preg_match('/[A-Z]/', $n)) $seen[$n] = ($seen[$n] ?? 0) + 1; }
    if ($seen) { arsort($seen); return (string)array_key_first($seen); }
    $s = ($v === mb_strtolower($v) || $v === mb_strtoupper($v)) ? mb_strtolower($v) : $v;
    return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
}
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
