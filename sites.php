<?php
/*
 * TDC Supply Room – how to read each supplier's website ("site profiles")
 *
 * Every store is different: some build their search results on the server, some only in the browser; some let the
 * server read product pages, some turn servers away. A profile records, per website:
 *   search: how to find a product by part number or name
 *           server  = read the store's own search page on the server (free)
 *           browser = open the store's search page in a real browser through Apify (costs Apify credit)
 *           web     = Google results for site:host (a cheap Apify run)
 *           none    = don't search this site automatically
 *   page:   how to read a product page
 *           server  = plain server read (free)
 *           proxy   = Apify's quick fetch through home-internet proxies (cheap)
 *           browser = Apify's full browser (slowest, most credit)
 *           blocked = the site turns away servers and Apify; Claude reads it in the office's browser, or a price list
 *           signin  = prices only show when signed in (Henry Schein)
 * Built-in profiles cover the stores the office uses; "Check site" (Setup › Supplier websites) works out the rest,
 * and every read teaches the profile (a site that starts blocking is skipped for a week instead of paying Apify to fail).
 * Only public pages are read, the same ones a person sees: no hidden site APIs, no getting around robot checks.
 */

declare(strict_types=1);

const SITE_DEFAULTS = [
    'henryschein.com'    => ['name' => 'Henry Schein', 'search' => 'web', 'page' => 'signin',
                             'note' => 'Your price only shows when signed in, so Claude reads Henry Schein in the office\'s browser.'],
    'shop.benco.com'     => ['name' => 'Benco', 'search' => 'server', 'page' => 'server',
                             'searchUrl' => 'https://shop.benco.com/Search/Search?Terms=%s', 'link' => '#(?:https://shop\.benco\.com)?/Product/\d+-\d+/[a-z0-9\-]+#i'],
    'darbydental.com'    => ['name' => 'Darby Dental', 'search' => 'server', 'page' => 'server',
                             'searchUrl' => 'https://www.darbydental.com/catalogsearch/result/?q=%s', 'link' => '#(?:https://www\.darbydental\.com)?/\d{5,}[0-9\-]*\.html#'],
    'net32.com'          => ['name' => 'Net32', 'search' => 'web', 'page' => 'server',
                             'note' => 'Net32\'s search is behind bot protection for servers; Google finds its pages.'],
    'safcodental.com'    => ['name' => 'Safco Dental Supply', 'search' => 'browser', 'page' => 'server',
                             'searchUrl' => 'https://www.safcodental.com/catalogsearch/result/?q=%s', 'link' => '#(?:https://www\.safcodental\.com)?(?<!catalog)/product/(?!clearance-item)[a-z0-9\-]{3,}(?=["\'?\#\s<])#i',
                             'note' => 'Search results are built in the browser. Part numbers are listed without dashes (01A1400).'],
    'dentalcity.com'     => ['name' => 'Dental City', 'search' => 'browser', 'page' => 'server',
                             'searchUrl' => 'https://www.dentalcity.com/search?filter=&search=%s&type=q&keywordoption=ALL&cid=0&fltrdesc=', 'link' => '#(?:https://www\.dentalcity\.com)?/product/\d+/[a-z0-9\-]+#i',
                             'note' => 'Search results are built in the browser; product pages list SKU, Mfg Part # and the sale price.'],
    'frontierdental.com' => ['name' => 'Frontier Dental', 'search' => 'web', 'page' => 'blocked',
                             'searchUrl' => 'https://www.frontierdental.com/us/en/products?search=%s', 'link' => '#(?:https://www\.frontierdental\.com)?/us/en/product/[a-z0-9\-]+#i',
                             'note' => 'Turns away servers and Apify. Claude reads it in the office\'s browser, or import a Frontier price list.'],
    'blueskybio.com'     => ['name' => 'Blue Sky Bio', 'search' => 'web', 'page' => 'server'],
    'ultradent.com'      => ['name' => 'Ultradent', 'search' => 'web', 'page' => 'server'],
];
const SITE_BLOCK_DAYS = 7;   // a site that blocked everything is not retried through Apify for a week

function site_host(string $h): string {
    $h = strtolower(trim($h)); $h = preg_replace('#^[a-z]+://#', '', $h); $h = explode('/', $h)[0]; $h = preg_replace('#^www\.#', '', $h);
    return $h;
}
function site_pdo(): ?PDO {
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo'];
    static $p = null; if ($p) return $p;
    try { $p = connect(); } catch (Throwable $e) { return null; }
    return $p;
}
function site_stored(): array {
    static $cache = null; if ($cache !== null && empty($GLOBALS['tdc_site_stored_reset'])) return $cache;
    $GLOBALS['tdc_site_stored_reset'] = false;
    $pdo = site_pdo(); $cache = $pdo ? ((load($pdo, 'settings', 'siteprofiles') ?? [])['sites'] ?? []) : [];
    return is_array($cache) ? $cache : [];
}
function site_default_key(string $host): ?string {
    foreach (SITE_DEFAULTS as $k => $_) if ($host === $k || str_ends_with($host, '.' . $k) || str_ends_with($k, '.' . $host)) return $k;
    return null;
}
/* the profile for a website: what was learned (or checked) wins over the built-in one */
function site_profile(string $host): array {
    $host = site_host($host); if ($host === '') return [];
    $dk = site_default_key($host);
    $p = ($dk !== null ? SITE_DEFAULTS[$dk] : []) + ['search' => 'web', 'page' => 'server'];
    $st = site_stored(); $learned = $st[$dk ?? $host] ?? $st[$host] ?? null;
    if (is_array($learned)) foreach ($learned as $k => $v) if ($v !== null && $v !== '') $p[$k] = $v;
    // a block is remembered for a week, then tried once more (sites change)
    if (($p['page'] ?? '') === 'blocked' && !empty($p['blockedAt']) && $p['blockedAt'] < time() - SITE_BLOCK_DAYS * 86400 && ($dk === null || SITE_DEFAULTS[$dk]['page'] !== 'blocked')) $p['page'] = 'server';
    $p['host'] = $dk ?? $host; $p['builtIn'] = $dk !== null;
    return $p;
}
/* remember what a read or search found out about a site (written only when something changed) */
function site_learn(string $host, array $patch): void {
    $host = site_host($host); if ($host === '') return;
    $key = site_default_key($host) ?? $host;
    $pdo = site_pdo(); if (!$pdo) return;
    $doc = load($pdo, 'settings', 'siteprofiles') ?? []; $sites = is_array($doc['sites'] ?? null) ? $doc['sites'] : [];
    $cur = $sites[$key] ?? []; $next = array_merge($cur, $patch);
    $same = true; foreach ($patch as $k => $v) if (!in_array($k, ['checkedAt', 'blockedAt', 'learnedAt'], true) && ($cur[$k] ?? null) !== $v) $same = false;
    if ($same && $cur) return;
    $next['learnedAt'] = time();
    $sites[$key] = $next;
    try { save($pdo, 'settings', 'siteprofiles', ['sites' => $sites]); } catch (Throwable $e) { error_log('tdc site_learn: ' . $e->getMessage()); }
    $GLOBALS['tdc_site_stored_reset'] = true;
}
function site_forget(string $host): void {
    $key = site_default_key(site_host($host)) ?? site_host($host);
    $pdo = site_pdo(); if (!$pdo) return;
    $doc = load($pdo, 'settings', 'siteprofiles') ?? []; $sites = is_array($doc['sites'] ?? null) ? $doc['sites'] : [];
    if (!isset($sites[$key])) return;
    unset($sites[$key]);
    try { save($pdo, 'settings', 'siteprofiles', ['sites' => $sites]); } catch (Throwable $e) {}
    $GLOBALS['tdc_site_stored_reset'] = true;
}
function site_profiles_all(array $hosts = []): array {
    $out = []; foreach (array_unique(array_merge(array_keys(SITE_DEFAULTS), array_keys(site_stored()), array_map('site_host', $hosts))) as $h) if ($h !== '') $out[$h] = site_profile($h);
    return $out;
}

/* ---------- Apify meter: every Apify run is counted per kind and site, so the cost of each strategy shows up ---------- */
function apify_meter(string $kind, string $host = '', bool $ok = true): void {
    $dir = sys_get_temp_dir() . '/tdc-meter'; if (!is_dir($dir)) @mkdir($dir, 0700, true);
    @file_put_contents($dir . '/' . date('Y-m') . '.log', date('c') . "\t$kind\t" . site_host($host) . "\t" . ($ok ? 1 : 0) . "\n", FILE_APPEND | LOCK_EX);
}
function apify_meter_summary(): array {
    $out = []; $dir = sys_get_temp_dir() . '/tdc-meter';
    foreach ([date('Y-m'), date('Y-m', strtotime('first day of last month'))] as $m) {
        $f = "$dir/$m.log"; if (!is_file($f)) continue;
        foreach (file($f, FILE_IGNORE_NEW_LINES) as $ln) { $p = explode("\t", $ln); if (count($p) < 4) continue; $k = $m . '|' . $p[1] . '|' . $p[2]; $out[$k] = ($out[$k] ?? ['month' => $m, 'kind' => $p[1], 'host' => $p[2], 'runs' => 0, 'failed' => 0]); $out[$k]['runs']++; if ($p[3] !== '1') $out[$k]['failed']++; }
    }
    usort($out, fn($a, $b) => strcmp($b['month'], $a['month']) ?: $b['runs'] <=> $a['runs']);
    return array_values($out);
}
/* what Apify itself billed, per actor, from the account's run history (the office's own Apify account) */
function apify_account_usage(int $days = 30): array {
    if (!apify_ready()) return ['ok' => false, 'message' => 'No Apify token.'];
    $since = time() - $days * 86400; $by = []; $total = 0.0; $n = 0;
    for ($offset = 0; $offset < 3000; $offset += 1000) {
        $ch = curl_init('https://api.apify.com/v2/actor-runs?desc=1&limit=1000&offset=' . $offset);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . cfg('APIFY_TOKEN')]]);
        $j = json_decode((string)curl_exec($ch), true); curl_close($ch);
        $items = $j['data']['items'] ?? null; if (!is_array($items)) return ['ok' => false, 'message' => 'Apify didn\'t return the run history.'];
        $stop = false;
        foreach ($items as $r) {
            $t = strtotime((string)($r['startedAt'] ?? '')) ?: 0; if ($t < $since) { $stop = true; break; }
            $a = (string)($r['actId'] ?? '?'); $u = (float)($r['usageTotalUsd'] ?? 0);
            $by[$a] = $by[$a] ?? ['actId' => $a, 'runs' => 0, 'usd' => 0.0, 'failed' => 0];
            $by[$a]['runs']++; $by[$a]['usd'] += $u; if (($r['status'] ?? '') !== 'SUCCEEDED') $by[$a]['failed']++;
            $total += $u; $n++;
        }
        if ($stop || count($items) < 1000) break;
    }
    // actor ids -> names
    foreach ($by as $id => &$row) {
        $ch = curl_init('https://api.apify.com/v2/acts/' . rawurlencode($id));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . cfg('APIFY_TOKEN')]]);
        $a = json_decode((string)curl_exec($ch), true); curl_close($ch);
        $row['name'] = trim(($a['data']['username'] ?? '') . '/' . ($a['data']['name'] ?? ''), '/') ?: $id;
        $row['usd'] = round($row['usd'], 3);
    }
    unset($row);
    usort($by, fn($a, $b) => $b['usd'] <=> $a['usd']);
    return ['ok' => true, 'days' => $days, 'runs' => $n, 'usd' => round($total, 2), 'actors' => array_values($by)];
}

/* ---------- searching a store's own site, the way its profile says ---------- */
function site_links(string $html, string $re, string $host, string $q): array {
    if (!preg_match_all($re, $html, $m, PREG_OFFSET_CAPTURE)) return [];
    $base = 'https://' . (str_contains($host, '.') && substr_count($host, '.') >= 2 ? $host : 'www.' . $host);
    // results first: links closest to where the page shows what was searched (menus and "you may also like" come later)
    $hits = []; $p = 0; $ql = strtolower($q); $lh = strtolower($html);
    while ($ql !== '' && ($p = strpos($lh, $ql, $p)) !== false) { $hits[] = $p; $p += strlen($ql); }
    $links = array_map(fn($x) => [$x[0], $hits ? min(array_map(fn($y) => abs($y - $x[1]), $hits)) : $x[1]], $m[0]);
    usort($links, fn($a, $b) => $a[1] <=> $b[1]);
    $out = []; $seen = [];
    foreach (array_column($links, 0) as $u) {
        if (!str_starts_with($u, 'http')) $u = $base . $u;
        $u = rtrim($u, '/'); if (isset($seen[$u])) continue; $seen[$u] = 1;
        $out[] = ['url' => $u, 'title' => '', 'host' => $host];
        if (count($out) >= 4) break;
    }
    return $out;
}
/* null = this site has no own-search route (use the web search); [] = searched, nothing found */
function site_search(string $host, string $q, bool $allowBrowser = true): ?array {
    $p = site_profile($host); $mode = $p['search'] ?? 'web';
    if (empty($p['searchUrl']) || empty($p['link']) || !in_array($mode, ['server', 'browser'], true)) return null;
    if ($mode === 'browser' && (!$allowBrowser || !page_service_ready())) return null;
    $url = sprintf($p['searchUrl'], rawurlencode($q)); $html = '';
    if ($mode === 'server') {
        $g = http_get($url, 4194304, 25);
        if ($g['ok'] && !bot_wall($g['body'])) $html = $g['body'];
        elseif (!$g['ok'] && in_array($g['code'] ?? 0, [401, 403, 429, 503], true)) site_learn($host, ['search' => 'web', 'note' => 'Its search started turning the server away, so Google is used instead.']);
        return $html === '' ? [] : site_links($html, $p['link'], $p['host'], $q);
    }
    $a = apify_page_run($url, 'browser-playwright', 90, true);
    return $a['ok'] ? site_links($a['html'], $p['link'], $p['host'], $q) : [];
}

/* ---------- Check site: work out a new store's profile ---------- */
function site_probe(string $host, string $mpn = '', string $term = 'nitrile gloves'): array {
    @set_time_limit(300);
    $host = site_host($host); if ($host === '') return ['ok' => false, 'message' => 'No website.'];
    $log = []; $say = function ($t) use (&$log) { $log[] = $t; };
    $base = 'https://' . (substr_count($host, '.') >= 2 ? $host : 'www.' . $host);
    $home = http_get($base . '/', 3145728, 20);
    $blockedHome = !$home['ok'] ? in_array($home['code'] ?? 0, [401, 403, 429, 503], true) : bot_wall($home['body']);
    $prof = ['checkedAt' => time()];
    if (!$home['ok'] && !$blockedHome) return ['ok' => false, 'message' => 'Couldn\'t open ' . $base . ': ' . ($home['error'] ?? 'no answer'), 'log' => $log];
    $say($blockedHome ? 'The site turns the server away (robot check).' : 'The server can open the site.');
    // 1. the search address: the site's own search box, else the usual patterns
    $tpls = [];
    if (!$blockedHome && preg_match_all('#<form\b[^>]*>[\s\S]{0,3000}?</form>#i', $home['body'], $forms)) {
        foreach ($forms[0] as $f) {
            if (!preg_match('#<input\b[^>]*\bname=["\']([a-z_]*(?:q|search|text|terms?|keywords?|query|k|s|searchterm|txtsearch))["\'][^>]*>#i', $f, $in)) continue;
            if (preg_match('#type=["\'](hidden|password|email|number)["\']#i', $in[0])) continue;
            $act = preg_match('#\baction=["\']([^"\']*)["\']#i', $f, $am) ? html_entity_decode($am[1]) : '';
            if ($act === '' || $act === '#') $act = '/search';
            if (!preg_match('#^https?://#', $act)) $act = $base . '/' . ltrim($act, '/');
            if (site_host((string)parse_url($act, PHP_URL_HOST)) !== $host) continue;
            $tpls[] = $act . (str_contains($act, '?') ? '&' : '?') . rawurlencode($in[1]) . '=%s';
        }
    }
    foreach (['/search?q=%s', '/catalogsearch/result/?q=%s', '/search?search=%s', '/search?query=%s', '/search?text=%s', '/search?keyword=%s', '/?s=%s&post_type=product', '/products?search=%s', '/search?type=product&q=%s'] as $t) $tpls[] = $base . $t;
    // a fresh check starts from the built-in profile (or nothing), not from what earlier reads learned
    site_forget($host);
    $cur = site_profile($host);
    if (!empty($cur['searchUrl'])) array_unshift($tpls, $cur['searchUrl']);   // the address already known to work goes first
    $tpls = array_values(array_unique($tpls));
    // 2. which template returns product links on the server, and what product links look like
    $shapes = ['#(?:https?://[^"\'\s<>]*)?/products?/[a-z0-9][a-z0-9\-_%]{2,}(?:/[a-z0-9\-_%]+)?(?=["\'?\#\s<])#i', '#(?:https?://[^"\'\s<>]*)?/p/[a-z0-9\-_%]{3,}(?:/[a-z0-9\-_%]+)?(?=["\'?\#\s<])#i',
               '#(?:https?://[^"\'\s<>]*)?/(?:item|items|ec)/[a-z0-9\-_%]{3,}(?=["\'?\#\s<])#i', '#(?:https?://[^"\'\s<>]*)?/[a-z0-9\-]{8,}-(?:d-)?\d{4,}(?:\.html)?(?=["\'?\#\s<])#i', '#(?:https?://[^"\'\s<>]*)?/\d{5,}[0-9\-]*\.html(?=["\'?\#\s<])#i'];
    if (!empty($cur['link'])) array_unshift($shapes, $cur['link']);           // the store's known product-link pattern first
    $pickShape = function (string $html) use ($shapes, $host): array {
        $best = ['re' => '', 'n' => 0, 'urls' => []];
        foreach ($shapes as $re) {
            if (!@preg_match_all($re, $html, $m)) continue;
            $u = array_values(array_unique(array_filter($m[0], fn($x) => !preg_match('#\.(jpe?g|png|gif|webp|svg|css|js)$#i', $x) && (!str_starts_with($x, 'http') || site_host((string)parse_url($x, PHP_URL_HOST)) === $host))));
            if (count($u) > $best['n']) $best = ['re' => $re, 'n' => count($u), 'urls' => $u];
        }
        return $best;
    };
    // A part number the store doesn't sell gives an empty result page, which says nothing about the search itself,
    // so common supplies every dental store carries are tried too.
    $terms = array_values(array_unique(array_filter([$mpn, $term, 'cotton rolls'])));
    $found = null; $serverPages = 0; $browserTry = null;
    if (!$blockedHome) foreach (array_slice($tpls, 0, 6) as $t) {
        foreach ($terms as $tt) {
            $g = http_get(sprintf($t, rawurlencode($tt)), 4194304, 20);
            if (!$g['ok'] || bot_wall($g['body'])) continue 2;
            $hasQ = stripos($g['body'], $tt) !== false; if (!$hasQ) continue 2;   // not a search page
            $serverPages++;
            $s = $pickShape($g['body']);
            if ($s['n'] >= 2) { $found = ['tpl' => $t, 'mode' => 'server', 'term' => $tt] + $s; break 2; }
            $browserTry = $browserTry ?? ['tpl' => $t, 'term' => $term];
        }
    }
    if ($found) $say("Its own search works on the server ({$found['n']} product links for \"{$found['term']}\").");
    elseif ($browserTry && page_service_ready()) {
        // one browser run, with a common supply, to see whether results only appear in a real browser
        $a = apify_page_run(sprintf($browserTry['tpl'], rawurlencode($browserTry['term'])), 'browser-playwright', 90, true);
        $s = $a['ok'] ? $pickShape($a['html']) : ['n' => 0, 're' => '', 'urls' => []];
        if ($s['n'] >= 2) { $found = ['tpl' => $browserTry['tpl'], 'mode' => 'browser', 'term' => $browserTry['term']] + $s; $say("Its search only shows results in a real browser ({$s['n']} product links through Apify)."); }
        else $say('Its search page didn\'t show product links, even in a browser.');
    } else $say($blockedHome ? 'Its search can\'t be read from the server.' : 'Couldn\'t find a search page that lists products.');
    if ($found) { $prof['search'] = $found['mode']; $prof['searchUrl'] = $found['tpl']; $prof['link'] = $found['re']; }
    elseif ($blockedHome || empty($cur['searchUrl'])) $prof['search'] = 'web';
    else $say('Kept the way it was already searched (' . ($cur['search'] ?? 'web') . '), since the test couldn\'t confirm a better one.');
    // 3. a product page: free server read, else Apify's quick proxy, else its browser
    $sample = $found['urls'][0] ?? '';
    if ($sample === '' && apify_ready()) { $r = apify_serp($term . ' site:' . $host, 5); foreach ((array)($r['results'] ?? []) as $c) if (site_host((string)parse_url($c['url'], PHP_URL_HOST)) === $host && !preg_match('#/(search|category|c|catalog)(/|\?|$)#i', (string)parse_url($c['url'], PHP_URL_PATH))) { $sample = $c['url']; break; } }
    if (!str_starts_with($sample, 'http') && $sample !== '') $sample = $base . $sample;
    if ($sample !== '') {
        $g = http_get($sample, 4194304, 20); $blk = !$g['ok'] ? in_array($g['code'] ?? 0, [401, 403, 429, 503], true) : bot_wall($g['body']);
        if ($g['ok'] && !$blk) {
            $prof['page'] = ($cur['page'] ?? '') === 'signin' ? 'signin' : 'server';
            $hasLd = preg_match('#"@type"\s*:\s*"Product(Group)?"#', $g['body']) || preg_match('#itemtype="https?://schema\.org/Product"#i', $g['body']);
            $say('Product pages open on the server' . ($hasLd ? ', with product data (name, SKU, price) built in.' : '; Claude reads the page text for price and SKU.'));
        } elseif (page_service_ready()) {
            $r = apify_page_run($sample, 'raw-http', 40, true);
            if ($r['ok'] && !bot_wall($r['html'])) { $prof['page'] = 'proxy'; $say('Product pages turn the server away but open through Apify\'s quick proxy (cheap).'); }
            else { $b = apify_page_run($sample, 'browser-playwright', 90, true);
                if ($b['ok'] && !bot_wall($b['html'])) { $prof['page'] = 'browser'; $say('Product pages only open in Apify\'s full browser (the most expensive kind of read).'); }
                else { $prof['page'] = 'blocked'; $prof['blockedAt'] = time(); $say('Product pages turn away the server and Apify. Prices for this store come from Claude reading it in your browser, or from a price list.'); } }
        } else { $prof['page'] = $blk ? 'blocked' : 'server'; }
    } else $say('Couldn\'t find a product page to test.');
    if ($blockedHome && empty($prof['page'])) { $prof['page'] = 'blocked'; $prof['blockedAt'] = time(); }
    $prof['note'] = implode(' ', $log);
    site_learn($host, $prof);
    return ['ok' => true, 'host' => $host, 'profile' => site_profile($host), 'log' => $log];
}

/* ---------- small caches, so the same search isn't paid for twice ---------- */
function cache_get(string $kind, string $key, int $days) { $f = sys_get_temp_dir() . "/tdc-cache/$kind-" . hash('sha256', $key) . '.json'; if (is_file($f) && filemtime($f) > time() - $days * 86400) { $v = json_decode((string)@file_get_contents($f), true); if (is_array($v)) return $v; } return null; }
function cache_put(string $kind, string $key, array $v): void { $d = sys_get_temp_dir() . '/tdc-cache'; if (!is_dir($d)) @mkdir($d, 0700, true); @file_put_contents("$d/$kind-" . hash('sha256', $key) . '.json', json_encode($v, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)); if (mt_rand(1, 80) === 1) foreach ((array)@glob("$d/*.json") as $x) if (@filemtime($x) < time() - 40 * 86400) @unlink($x); }
