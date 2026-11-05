<?php
/*
 * TDC Supply Room – data API for the page (index.html)
 *
 * Every request is a POST with a JSON body {op: ..., ...}. Everything except
 * "status" and "login" needs the sign-in cookie this file hands out.
 */

declare(strict_types=1);
require __DIR__ . '/lib.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

load_config();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(405, 'method', 'Use POST.');
// Only the Supply Room page sends this header; a form on another site cannot.
if (($_SERVER['HTTP_X_TDC'] ?? '') !== '1') fail(400, 'bad_request', 'Missing request header.');
$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 24 * 1024 * 1024) fail(413, 'too_large', 'Request too large.');
$req = json_decode($raw, true);
if (!is_array($req) || !isset($req['op']) || !is_string($req['op'])) fail(400, 'bad_request', 'Bad request.');
$op = $req['op'];

$pdo = connect();

if ($op === 'status') {
    out(200, ['ok' => true, 'signedIn' => signed_in($pdo), 'ai' => ai_ready()]);
}

if ($op === 'login') {
    $ip = client_ip(); $now = time();
    $pdo->prepare('DELETE FROM sr_fails WHERE ts < ?')->execute([$now - FAIL_WINDOW]);
    $st = $pdo->prepare('SELECT COUNT(*) AS n FROM sr_fails WHERE ip = ?');
    $st->execute([$ip]);
    if ((int)$st->fetch()['n'] >= MAX_FAILS) {
        fail(429, 'too_many', 'Too many wrong passwords. Wait 15 minutes and try again.');
    }
    $pw = $req['password'] ?? '';
    if (!is_string($pw) || !hash_equals((string)OFFICE_PASSWORD, $pw)) {
        $pdo->prepare('INSERT INTO sr_fails (ip, ts) VALUES (?, ?)')->execute([$ip, $now]);
        usleep(600000);
        fail(401, 'wrong_password', 'That password isn\'t right.');
    }
    $token = bin2hex(random_bytes(32));
    $exp = $now + TOKEN_DAYS * 86400;
    $pdo->prepare('DELETE FROM sr_tokens WHERE expires < ?')->execute([$now]);
    $pdo->prepare('INSERT INTO sr_tokens (token_hash, pw_fp, expires) VALUES (?, ?, ?)')
        ->execute([hash('sha256', $token), pw_fp(), $exp]);
    set_cookie($token, $exp);
    out(200, ['ok' => true]);
}

if ($op === 'logout') {
    $t = $_COOKIE[COOKIE] ?? '';
    if (is_string($t) && $t !== '') {
        $pdo->prepare('DELETE FROM sr_tokens WHERE token_hash = ?')->execute([hash('sha256', $t)]);
    }
    set_cookie('', 1);
    out(200, ['ok' => true]);
}

if (!signed_in($pdo)) fail(401, 'signed_out', 'Please sign in again.');

try {
    switch ($op) {
        case 'rev':
            out(200, ['ok' => true, 'rev' => current_rev($pdo)]);

        case 'get':
            out(200, ['ok' => true] + read_get($pdo, $req) + ['rev' => current_rev($pdo)]);

        case 'query':
            out(200, ['ok' => true] + read_query($pdo, $req) + ['rev' => current_rev($pdo)]);

        case 'multi': {
            // Several reads in one request (one database connection instead of many).
            $reads = $req['reads'] ?? null;
            if (!is_array($reads) || count($reads) > 30) fail(400, 'invalid_argument', 'Bad read list.');
            $res = [];
            foreach ($reads as $r) {
                if (!is_array($r)) fail(400, 'invalid_argument', 'Bad read.');
                $res[] = (($r['kind'] ?? '') === 'get') ? read_get($pdo, $r) : read_query($pdo, $r);
            }
            out(200, ['ok' => true, 'results' => $res, 'rev' => current_rev($pdo)]);
        }

        case 'batch': {
            // Many writes in one request and one transaction (used by Restore).
            $writes = $req['writes'] ?? null;
            if (!is_array($writes) || count($writes) === 0 || count($writes) > 250) fail(400, 'invalid_argument', 'Bad write list.');
            $pdo->beginTransaction();
            $rev = 0;
            foreach ($writes as $w) {
                if (!is_array($w)) fail(400, 'invalid_argument', 'Bad write.');
                $col = col_ok($w['col'] ?? null); $id = id_ok($w['id'] ?? null);
                switch ($w['op'] ?? '') {
                    case 'set':    $rev = save($pdo, $col, $id, data_ok($w['data'] ?? null)); break;
                    case 'update': $cur = load($pdo, $col, $id);
                                   if ($cur === null) break;
                                   foreach (data_ok($w['data'] ?? null) as $k => $v) $cur[$k] = $v;
                                   $rev = save($pdo, $col, $id, $cur); break;
                    case 'delete': $rev = remove($pdo, $col, $id); break;
                    default: fail(400, 'invalid_argument', 'Bad write.');
                }
            }
            $pdo->commit();
            out(200, ['ok' => true, 'count' => count($writes), 'rev' => $rev]);
        }

        case 'set': {
            $col = col_ok($req['col'] ?? null); $id = id_ok($req['id'] ?? null); $d = data_ok($req['data'] ?? null);
            out(200, ['ok' => true, 'rev' => save($pdo, $col, $id, $d)]);
        }

        case 'add': {
            $col = col_ok($req['col'] ?? null); $d = data_ok($req['data'] ?? null);
            $id = new_id();
            out(200, ['ok' => true, 'id' => $id, 'rev' => save($pdo, $col, $id, $d)]);
        }

        case 'update': {
            $col = col_ok($req['col'] ?? null); $id = id_ok($req['id'] ?? null); $patch = data_ok($req['data'] ?? null);
            $pdo->beginTransaction();
            $cur = load($pdo, $col, $id);
            if ($cur === null) { $pdo->rollBack(); fail(404, 'not_found', 'That item no longer exists.'); }
            foreach ($patch as $k => $v) {
                if (is_array($v) && ($v['__delete__'] ?? false) === true) unset($cur[$k]);
                else $cur[$k] = $v;   // top-level fields replace
            }
            $rev = save($pdo, $col, $id, $cur);
            $pdo->commit();
            out(200, ['ok' => true, 'rev' => $rev]);
        }

        case 'delete': {
            $col = col_ok($req['col'] ?? null); $id = id_ok($req['id'] ?? null);
            $pdo->beginTransaction();
            $rev = remove($pdo, $col, $id);
            $pdo->commit();
            out(200, ['ok' => true, 'rev' => $rev]);
        }

        /* ----- product photos ----- */
        case 'blob_put': {
            $data = $req['data'] ?? null;
            if (!is_string($data)) fail(400, 'invalid_argument', 'No picture sent.');
            $bytes = base64_decode($data, true);
            if ($bytes === false) fail(400, 'invalid_argument', 'The picture couldn\'t be read.');
            $id = blob_put($pdo, $bytes, (string)($req['type'] ?? ''), is_string($req['id'] ?? null) ? $req['id'] : null);
            out(200, ['ok' => true, 'id' => $id]);
        }

        case 'blob_delete': {
            $id = (string)($req['id'] ?? '');
            if (preg_match('/^[a-f0-9]{32}$/', $id)) $pdo->prepare('DELETE FROM sr_blobs WHERE id = ?')->execute([$id]);
            out(200, ['ok' => true]);
        }

        case 'blob_list': {
            $ids = array_column($pdo->query('SELECT id FROM sr_blobs')->fetchAll(), 'id');
            out(200, ['ok' => true, 'ids' => $ids]);
        }

        /* ----- Claude ----- */
        case 'ai': {
            $prompt = $req['prompt'] ?? '';
            if (!is_string($prompt) || $prompt === '' || strlen($prompt) > 200000) fail(400, 'invalid_argument', 'Bad prompt.');
            $images = is_array($req['images'] ?? null) ? $req['images'] : [];
            $r = ai_json($prompt, $images, (string)($req['tier'] ?? ''));
            if (!$r['ok']) fail($r['error'] === 'no_key' ? 409 : 502, $r['error'], $r['message']);
            out(200, ['ok' => true, 'value' => $r['value']]);
        }

        case 'readlink': {
            $url = trim((string)($req['url'] ?? ''));
            if (!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
            $ctx = is_array($req['ctx'] ?? null) ? $req['ctx'] : [];
            $r = read_product_link($url, $ctx);
            if (!$r['ok']) fail(502, $r['error'], $r['message']);
            out(200, $r);
        }

        case 'findproduct': {
            $q = is_array($req['q'] ?? null) ? $req['q'] : [];
            $ctx = is_array($req['ctx'] ?? null) ? $req['ctx'] : [];
            $r = find_product_page($q, $ctx);
            if (!$r['ok']) fail(502, $r['error'] ?? 'no_match', $r['message']);
            out(200, $r);
        }

        case 'readdoc': {
            $files = is_array($req['files'] ?? null) ? $req['files'] : [];
            $ctx = is_array($req['ctx'] ?? null) ? $req['ctx'] : [];
            $r = read_document($pdo, $files, $ctx);
            if (!$r['ok']) fail($r['error'] === 'no_key' ? 409 : ($r['error'] === 'invalid_argument' ? 400 : 502), $r['error'], $r['message']);
            out(200, $r);
        }

        default:
            fail(400, 'bad_request', 'Unknown operation.');
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('tdc supply api: ' . $e->getMessage());
    fail(503, 'unavailable', 'The server had a problem saving or loading. Try again.');
}
