<?php
/*
 * TDC Supply Room – serves a saved product photo: blob.php?id=<32 hex>
 * Needs the same sign-in cookie as the page.
 */

declare(strict_types=1);
require __DIR__ . '/lib.php';

load_config();
$id = (string)($_GET['id'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $id)) { http_response_code(404); exit; }
$pdo = connect();
if (!signed_in($pdo)) { http_response_code(401); exit; }

$st = $pdo->prepare('SELECT mime, bytes FROM sr_blobs WHERE id = ?');
$st->execute([$id]);
$row = $st->fetch();
if (!$row) { http_response_code(404); exit; }
$bytes = is_resource($row['bytes']) ? stream_get_contents($row['bytes']) : (string)$row['bytes'];

// Photo ids never change their picture, so browsers may keep them.
header('Content-Type: ' . (in_array($row['mime'], IMG_TYPES, true) ? $row['mime'] : 'application/octet-stream'));
header('Content-Length: ' . strlen($bytes));
header('Cache-Control: private, max-age=31536000, immutable');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
echo $bytes;
