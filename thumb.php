<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
$u = session_user(); if (!$u) { http_response_code(401); exit; }
if (isset($_GET['m']) || isset($_GET['ad'])) {   // Muse Image ad pictures: the advertiser, the post's owner, or anyone allowed to see a sponsored post
    require_once __DIR__ . '/app/engage.php'; [$vis, $va] = visible_sql((int)$u['id']);
    if (isset($_GET['m'])) { $s = db()->prepare('SELECT m.path FROM ad_mockups m JOIN advertisers a ON a.id=m.advertiser_id WHERE m.content_id=? AND a.user_id=?'); $s->execute([(int)$_GET['m'], (int)$u['id']]); }
    else { $s = db()->prepare("SELECT b.mockup_path FROM bids b JOIN advertisers a ON a.id=b.advertiser_id JOIN content c ON c.id=b.content_id LEFT JOIN user_prefs p ON p.user_id=c.owner_user_id WHERE b.id=? AND b.mockup_path IS NOT NULL AND (a.user_id=? OR (b.status='accepted' AND $vis))"); $s->execute(array_merge([(int)$_GET['ad'], (int)$u['id']], $va)); }
    $rel = $s->fetchColumn(); $base = realpath(rtrim((string)cfg('STORAGE_DIR'), '/') . '/mockups'); $full = $rel && $base ? realpath($base . '/' . $rel) : false;
    if (!$full || !str_starts_with($full, $base . DIRECTORY_SEPARATOR) || !is_file($full)) { http_response_code(404); exit; }
    header('Content-Type: image/jpeg'); header('Content-Length: ' . filesize($full)); header('Cache-Control: private, max-age=3600'); header('X-Content-Type-Options: nosniff'); readfile($full); exit;
}
if (isset($_GET['c'])) {   // thumbnail of Trending / circle content: only if this user is allowed to see that content
    require_once __DIR__ . '/app/engage.php'; [$vis, $va] = visible_sql((int)$u['id']);
    $s = db()->prepare('SELECT c.thumb_path FROM content c LEFT JOIN user_prefs p ON p.user_id=c.owner_user_id WHERE c.id=? AND ' . $vis); $s->execute(array_merge([(int)$_GET['c']], $va)); $p = $s->fetchColumn();
} else { $s = db()->prepare('SELECT thumb_path FROM items WHERE id=? AND user_id=?'); $s->execute([(int)($_GET['id'] ?? 0), $u['id']]); $p = $s->fetchColumn(); }
$base = realpath(rtrim((string)cfg('STORAGE_DIR'), '/') . '/thumbs'); $full = $p ? realpath($base . '/' . $p) : false;
if (!$full || !$base || !str_starts_with($full, $base . DIRECTORY_SEPARATOR) || !is_file($full)) { http_response_code(404); exit; }
header('Content-Type: ' . (file_get_contents($full, false, null, 0, 4) === "\x89PNG" ? 'image/png' : 'image/jpeg')); header('Content-Length: ' . filesize($full)); header('Cache-Control: private, max-age=86400'); header('X-Content-Type-Options: nosniff');
readfile($full);
