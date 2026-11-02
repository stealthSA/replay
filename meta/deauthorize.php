<?php
declare(strict_types=1);
/**
 * Meta "Deauthorize Callback URL". Meta POSTs a signed_request when a user removes the app from their
 * Facebook / Instagram / Threads settings. We verify the signature with the app secret, then drop that
 * account's tokens so no further data can be fetched.
 */
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/meta.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
$v = verify_signed_request((string)($_POST['signed_request'] ?? ''));
if (!$v) { http_response_code(400); echo json_encode(['error' => 'invalid signed_request']); exit; }
[$platform, $data] = $v; $accountId = (string)($data['user_id'] ?? '');
if ($accountId !== '') db()->prepare("UPDATE connections SET status='revoked', token_enc=NULL, last_error='You removed this app from your Meta settings.' WHERE platform=? AND account_id=?")->execute([$platform, $accountId]);
header('Content-Type: application/json'); echo '{}';
