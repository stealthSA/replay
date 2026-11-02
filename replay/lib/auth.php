<?php
function session_boot(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params(['lifetime' => 60 * 60 * 24 * 30, 'path' => '/', 'secure' => $secure,
        'httponly' => true, 'samesite' => 'Lax']);
    session_name('replay_sid');
    session_start();
}

function current_user(): ?array {
    session_boot();
    if (empty($_SESSION['uid'])) return null;
    $s = db()->prepare('SELECT * FROM users WHERE id=?');
    $s->execute([$_SESSION['uid']]);
    $u = $s->fetch();
    if (!$u || $u['disabled']) return null;
    return $u;
}

function is_loopback(): bool {
    return in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
}

function upsert_user(string $sub, string $email, string $name, string $picture): array {
    $db = db();
    $email = strtolower(trim($email));
    $role = ($email === strtolower(ADMIN_EMAIL)) ? 'admin' : 'user';
    $s = $db->prepare('SELECT * FROM users WHERE google_sub=? OR email=?');
    $s->execute([$sub, $email]);
    $u = $s->fetch();
    $now = time();
    if ($u) {
        $db->prepare('UPDATE users SET google_sub=?, email=?, name=?, picture=?, role=?, last_login=? WHERE id=?')
           ->execute([$sub, $email, $name, $picture, $role === 'admin' ? 'admin' : $u['role'], $now, $u['id']]);
        $id = (int)$u['id'];
    } else {
        $db->prepare('INSERT INTO users (google_sub,email,name,picture,role,created_at,last_login) VALUES (?,?,?,?,?,?,?)')
           ->execute([$sub, $email, $name, $picture, $role, $now, $now]);
        $id = (int)$db->lastInsertId();
    }
    $b = retention_bounds();
    $db->prepare('INSERT OR IGNORE INTO user_settings (user_id, retention_hours) VALUES (?,?)')->execute([$id, $b['default']]);
    $s = $db->prepare('SELECT * FROM users WHERE id=?');
    $s->execute([$id]);
    return $s->fetch();
}

function login_user(array $u): void {
    session_boot();
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
}
