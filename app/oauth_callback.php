<?php
declare(strict_types=1);
/** Shared OAuth callback. callback-{facebook,instagram,threads}.php set $platform and include this. */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/meta.php';

$home = base_url() . '/';
$back = fn(string $q) => (function () use ($home, $q) { header('Location: ' . $home . '#/' . $q); exit; })();
session_boot();
$o = $_SESSION['oauth'] ?? null; unset($_SESSION['oauth']);

if (isset($_GET['error']) || isset($_GET['error_reason'])) {
    $o = $o ?: oauth_state_check((string)($_GET['state'] ?? ''), $platform); $mode = $o['mode'] ?? 'login';
    $back(($mode === 'login' ? 'login' : 'connections') . '?msg=' . rawurlencode('Access was not granted — nothing was connected.'));
}
$sessionOk = $o && $o['platform'] === $platform && hash_equals((string)$o['state'], (string)($_GET['state'] ?? '')) && time() - (int)$o['at'] <= 900;
if (!$sessionOk) $o = oauth_state_check((string)($_GET['state'] ?? ''), $platform);   // no session cookie (common on phones): trust the signed state instead
if (!$o) {
    $back('login?msg=' . rawurlencode('That sign-in link expired. Please try again.'));
}
$code = (string)($_GET['code'] ?? ''); $code = preg_replace('/#_$/', '', $code);
if ($code === '') $back('connections?msg=' . rawurlencode('Meta did not return an authorization code.'));

try {
    [$profile, $token, $expires, $scopes] = oauth_complete($platform, $code);
} catch (Throwable $e) {
    error_log('[recap] oauth ' . $platform . ': ' . $e->getMessage());
    $back(($o['mode'] === 'login' ? 'login' : 'connections') . '?msg=' . rawurlencode('Meta sign-in failed: ' . $e->getMessage()));
}

$db = db();
if ($platform === 'facebook' && $o['mode'] === 'login') {
    $s = $db->prepare('SELECT * FROM users WHERE meta_id=?'); $s->execute([$profile['id']]); $u = $s->fetch();
    if (!$u) {
        $email = !empty($profile['email']) ? strtolower($profile['email']) : null;
        if ($email) { $x = $db->prepare('SELECT 1 FROM users WHERE email=?'); $x->execute([$email]); if ($x->fetch()) $email = null; }  // never merge into an existing password account
        $db->prepare('INSERT INTO users (meta_id, email, name, picture, created_at) VALUES (?,?,?,?,?)')->execute([$profile['id'], $email, $profile['name'] ?: 'Facebook user', $profile['picture'], time()]);
        $s->execute([$profile['id']]); $u = $s->fetch();
    } elseif ($u['disabled']) { $back('login?msg=' . rawurlencode('This account has been disabled.')); }
    else { $db->prepare('UPDATE users SET name=?, picture=COALESCE(?, picture) WHERE id=?')->execute([$profile['name'] ?: $u['name'], $profile['picture'], $u['id']]); }
    login_user($u);
    $hs = oauth_state_check((string)($_GET['state'] ?? ''), $platform); if (!empty($hs['h'])) handoff_store($hs['h'], (int)$u['id']);   // lets the installed app pick up this login
    $cid = save_connection((int)$u['id'], 'facebook', $profile, $token, $expires, $scopes);
    $c = $db->prepare('SELECT * FROM connections WHERE id=?'); $c->execute([$cid]); sync_connection($c->fetch());
    $back('');
}

$user = session_user();
if (!$user) $back('login?msg=' . rawurlencode('Sign in first, then connect your ' . PLATFORMS[$platform]['name'] . ' account.'));
$uid = (int)$user['id'];
if ($platform === 'facebook' && empty($user['meta_id'])) { $x = $db->prepare('SELECT 1 FROM users WHERE meta_id=?'); $x->execute([$profile['id']]); if (!$x->fetch()) $db->prepare('UPDATE users SET meta_id=? WHERE id=?')->execute([$profile['id'], $uid]); }
// An account can only belong to one person here.
$x = $db->prepare('SELECT user_id FROM connections WHERE platform=? AND account_id=? AND user_id<>?'); $x->execute([$platform, $profile['id'], $uid]);
if ($x->fetch()) $back('connections?msg=' . rawurlencode('That ' . PLATFORMS[$platform]['name'] . ' account is already connected to another Current Recap user.'));
$cid = save_connection($uid, $platform, $profile, $token, $expires, $scopes);
$c = $db->prepare('SELECT * FROM connections WHERE id=?'); $c->execute([$cid]); [$n, $err] = sync_connection($c->fetch());
$back('connections?connected=' . $platform . '&n=' . $n);
