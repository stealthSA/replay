<?php
declare(strict_types=1);
/**
 * Meta "Data Deletion Request URL". Meta POSTs a signed_request when a user asks Meta to delete the data
 * an app holds on them. We delete it and answer with a status URL + confirmation code (as Meta's docs require).
 */
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/meta.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
$v = verify_signed_request((string)($_POST['signed_request'] ?? ''));
if (!$v) { http_response_code(400); header('Content-Type: application/json'); echo json_encode(['error' => 'invalid signed_request']); exit; }
[$platform, $data] = $v; $accountId = (string)($data['user_id'] ?? '');
$db = db(); $code = bin2hex(random_bytes(8)); $note = 'Removed the connected ' . PLATFORMS[$platform]['name'] . ' account, its tokens and the items synced from it.';
if ($accountId !== '') {
    // Items that came from this account's API sync, then the connection itself.
    $s = $db->prepare('SELECT user_id FROM connections WHERE platform=? AND account_id=?'); $s->execute([$platform, $accountId]);
    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $uid) {
        $t = $db->prepare("SELECT thumb_path FROM items WHERE user_id=? AND platform=? AND source='api'"); $t->execute([$uid, $platform]);
        foreach ($t->fetchAll(PDO::FETCH_COLUMN) as $tp) if ($tp) @unlink(rtrim((string)cfg('STORAGE_DIR'), '/') . '/thumbs/' . $tp);
        $db->prepare("DELETE FROM items WHERE user_id=? AND platform=? AND source='api'")->execute([$uid, $platform]);
    }
    $db->prepare('DELETE FROM connections WHERE platform=? AND account_id=?')->execute([$platform, $accountId]);
    if ($platform === 'facebook') {   // an account created with "Continue with Facebook" is removed entirely
        $u = $db->prepare('SELECT id FROM users WHERE meta_id=?'); $u->execute([$accountId]);
        if ($uid = $u->fetchColumn()) { delete_user_data((int)$uid); $note = 'Deleted your Current Recap account and everything stored in it.'; }
    }
}
$db->prepare('INSERT INTO deletion_requests (code, platform, account_id, requested_at, completed_at, note) VALUES (?,?,?,?,?,?)')->execute([$code, $platform, $accountId ?: null, time(), time(), $note]);
header('Content-Type: application/json');
echo json_encode(['url' => base_url() . '/delete-data.php?code=' . $code, 'confirmation_code' => $code]);
