<?php
declare(strict_types=1);
/** Password reset by e-mail. The link holds a random token; only its SHA-256 is stored. One use, valid for 1 hour. */

function reset_table(): void { db()->exec('CREATE TABLE IF NOT EXISTS password_resets (h TEXT PRIMARY KEY, user_id INTEGER NOT NULL, expires INTEGER NOT NULL, created_at INTEGER NOT NULL)'); }

/** Sends the reset e-mail when a password account with that address exists. Always silent about whether it does. @return string|null the link (only so local testing can show it) */
function reset_request(string $email): ?string {
    reset_table(); $db = db(); $now = time();
    $db->prepare('DELETE FROM password_resets WHERE expires < ?')->execute([$now]);
    $s = $db->prepare('SELECT id, name, password_hash, disabled FROM users WHERE email=?'); $s->execute([$email]); $u = $s->fetch();
    if (!$u || !$u['password_hash'] || $u['disabled']) return null;
    $r = $db->prepare('SELECT MAX(created_at) FROM password_resets WHERE user_id=?'); $r->execute([$u['id']]); if ($now - (int)$r->fetchColumn() < 60) return null;   // at most one e-mail a minute
    $db->prepare('DELETE FROM password_resets WHERE user_id=?')->execute([$u['id']]);
    $token = bin2hex(random_bytes(32));
    $db->prepare('INSERT INTO password_resets (h, user_id, expires, created_at) VALUES (?,?,?,?)')->execute([hash('sha256', $token), $u['id'], $now + 3600, $now]);
    $link = base_url() . '/#/reset?token=' . $token; $app = (string)cfg('APP_NAME');
    $host = (string)parse_url(base_url(), PHP_URL_HOST); $from = trim((string)cfg('MAIL_FROM')) ?: 'no-reply@' . preg_replace('/^www\./', '', $host ?: 'localhost');
    $body = "Hi " . ($u['name'] ?: 'there') . ",\r\n\r\nSomeone asked to reset the password for your $app account. Open this link to choose a new one (it works once and expires in 1 hour):\r\n\r\n$link\r\n\r\nIf it was not you, ignore this e-mail; your password stays the same.\r\n";
    $ok = @mail($email, "$app: reset your password", $body, "From: $app <$from>\r\nReply-To: $from\r\nContent-Type: text/plain; charset=UTF-8\r\nX-Mailer: CurrentRecap");
    if (!$ok) error_log('[recap] password reset e-mail could not be sent (mail() failed). Check the host mail setup or set MAIL_FROM.');
    return $link;
}

/** @return string|null an error message, or null when the password was changed */
function reset_apply(string $token, string $password): ?string {
    if (strlen($password) < 8) return 'Password must be at least 8 characters.';
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return 'This reset link is not valid. Ask for a new one.';
    reset_table(); $db = db(); $h = hash('sha256', $token);
    $s = $db->prepare('SELECT user_id FROM password_resets WHERE h=? AND expires >= ?'); $s->execute([$h, time()]); $uid = $s->fetchColumn();
    if ($uid === false) return 'This reset link has expired or was already used. Ask for a new one.';
    $db->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($password, PASSWORD_DEFAULT), $uid]);
    $db->prepare('DELETE FROM password_resets WHERE user_id=?')->execute([$uid]);
    return null;
}
