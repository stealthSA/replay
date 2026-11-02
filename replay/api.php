<?php
require __DIR__ . '/config.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/platforms.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/firebase.php';
require __DIR__ . '/lib/ml.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function out($data, int $code = 200): never { http_response_code($code); echo json_encode($data); exit; }
function fail(int $code, string $msg): never { out(['error' => $msg], $code); }

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST' && ($_SERVER['HTTP_X_REPLAY'] ?? '') !== '1') fail(400, 'Bad request');
$in = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];

try {
    // ---------- public ----------
    if ($action === 'config') {
        out(['app' => APP_NAME, 'firebase' => FIREBASE_API_KEY && FIREBASE_PROJECT_ID ? ['apiKey' => FIREBASE_API_KEY, 'authDomain' => FIREBASE_AUTH_DOMAIN, 'projectId' => FIREBASE_PROJECT_ID, 'appId' => FIREBASE_APP_ID] : null,
             'dev_login' => DEV_LOGIN && is_loopback(), 'platforms' => platforms(),
             'types' => CONTENT_TYPES, 'bounds' => retention_bounds(), 'ml_min_samples' => ML_MIN_SAMPLES]);
    }
    if ($action === 'login_firebase' && $method === 'POST') {
        $p = verify_firebase_token((string)($in['idToken'] ?? ''));
        if (!$p) fail(401, 'Google sign-in could not be verified');
        $u = upsert_user($p['sub'], $p['email'], $p['name'] ?? $p['email'], $p['picture'] ?? '');
        if ($u['disabled']) fail(403, 'This account has been disabled');
        login_user($u);
        out(['user' => public_user($u)]);
    }
    if ($action === 'login_dev' && $method === 'POST') {
        if (!DEV_LOGIN || !is_loopback()) fail(403, 'Disabled');
        $email = strtolower(trim((string)($in['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail(400, 'Invalid email');
        $u = upsert_user('dev-' . $email, $email, $in['name'] ?? $email, '');
        login_user($u);
        out(['user' => public_user($u)]);
    }
    if ($action === 'logout' && $method === 'POST') { session_boot(); $_SESSION = []; session_destroy(); out(['ok' => true]); }

    // ---------- authenticated ----------
    $user = current_user();
    if ($action === 'me') out(['user' => $user ? public_user($user) : null]);
    if (!$user) fail(401, 'Not signed in');
    $uid = (int)$user['id'];
    $db = db();

    // Opportunistic cleanup so things stay tidy even without cron.
    $st0 = user_settings($uid);
    if (time() - (int)$st0['last_cleanup_at'] > 900) cleanup_user($uid);

    switch ($action) {
        case 'history': {
            $st = user_settings($uid);
            $sql = 'SELECT * FROM interactions WHERE user_id=?';
            $args = [$uid];
            if (!empty($_GET['platform']) && in_array($_GET['platform'], platform_ids(), true)) { $sql .= ' AND platform=?'; $args[] = $_GET['platform']; }
            if (!empty($_GET['q'])) { $sql .= ' AND (title LIKE ? OR url LIKE ?)'; $like = '%' . $_GET['q'] . '%'; $args[] = $like; $args[] = $like; }
            $sql .= ' ORDER BY last_opened_at DESC LIMIT ? OFFSET ?';
            $args[] = min(200, max(1, (int)($_GET['limit'] ?? 60)));
            $args[] = max(0, (int)($_GET['offset'] ?? 0));
            $s = $db->prepare($sql);
            $s->execute($args);
            $items = $s->fetchAll();
            foreach ($items as &$it) {
                $keep = $it['pinned'] || $it['open_count'] > 1;
                $it['expires_at'] = $keep ? null : (int)$it['last_opened_at'] + $st['retention_hours'] * 3600;
                $it['status'] = $it['pinned'] ? 'pinned' : ($it['open_count'] > 1 ? 'rewatched' : 'expiring');
            }
            out(['items' => $items, 'now' => time()]);
        }
        case 'stats': {
            $st = user_settings($uid);
            $s = $db->prepare('SELECT platform, COUNT(*) n, SUM(open_count>1) rewatched FROM interactions WHERE user_id=? GROUP BY platform');
            $s->execute([$uid]);
            $by = [];
            foreach ($s->fetchAll() as $r) $by[$r['platform']] = ['count' => (int)$r['n'], 'rewatched' => (int)$r['rewatched']];
            $soon = $db->prepare('SELECT COUNT(*) FROM interactions WHERE user_id=? AND pinned=0 AND open_count<=1 AND last_opened_at + ? <= ?');
            $soon->execute([$uid, $st['retention_hours'] * 3600, time() + 6 * 3600]);
            $cl = $db->prepare('SELECT COALESCE(SUM(deleted),0) FROM cleanup_log WHERE user_id=?');
            $cl->execute([$uid]);
            $tot = $db->prepare('SELECT COUNT(*) FROM interactions WHERE user_id=?');
            $tot->execute([$uid]);
            out(['by_platform' => $by, 'total' => (int)$tot->fetchColumn(), 'expiring_6h' => (int)$soon->fetchColumn(),
                 'auto_cleaned' => (int)$cl->fetchColumn()]);
        }
        case 'log': {
            $url = trim((string)($in['url'] ?? ''));
            if (!preg_match('#^https?://#i', $url) || strlen($url) > 2000) fail(400, 'Please enter a valid link');
            $platform = $in['platform'] ?? null;
            if (!in_array($platform, platform_ids(), true)) $platform = detect_platform($url);
            if (!$platform) fail(400, 'That link is not from a Meta platform');
            $type = in_array($in['content_type'] ?? '', CONTENT_TYPES, true) ? $in['content_type'] : detect_type($url, $platform);
            $key = normalize_url($url);
            $title = mb_substr(trim((string)($in['title'] ?? '')), 0, 200);
            $dwell = max(0, min(86400, (int)($in['dwell'] ?? 0)));
            $now = time();
            $s = $db->prepare('SELECT * FROM interactions WHERE user_id=? AND url_key=?');
            $s->execute([$uid, $key]);
            if ($row = $s->fetch()) {
                $db->prepare('UPDATE interactions SET open_count=open_count+1, last_opened_at=?, dwell_seconds=dwell_seconds+?,
                              title=CASE WHEN ?<>\'\' THEN ? ELSE title END WHERE id=?')
                   ->execute([$now, $dwell, $title, $title, $row['id']]);
                out(['id' => (int)$row['id'], 'rewatch' => true, 'open_count' => (int)$row['open_count'] + 1]);
            }
            $db->prepare('INSERT INTO interactions (user_id,platform,url,url_key,title,content_type,first_opened_at,last_opened_at,dwell_seconds)
                          VALUES (?,?,?,?,?,?,?,?,?)')
               ->execute([$uid, $platform, $url, $key, $title !== '' ? $title : null, $type, $now, $now, $dwell]);
            out(['id' => (int)$db->lastInsertId(), 'rewatch' => false, 'platform' => $platform, 'content_type' => $type], 201);
        }
        case 'pin': {
            $db->prepare('UPDATE interactions SET pinned=? WHERE id=? AND user_id=?')
               ->execute([!empty($in['pinned']) ? 1 : 0, (int)($in['id'] ?? 0), $uid]);
            out(['ok' => true]);
        }
        case 'delete': {
            $db->prepare('DELETE FROM interactions WHERE id=? AND user_id=?')->execute([(int)($in['id'] ?? 0), $uid]);
            out(['ok' => true]);
        }
        case 'clear': {
            if (!empty($in['platform']) && in_array($in['platform'], platform_ids(), true)) {
                $s = $db->prepare('DELETE FROM interactions WHERE user_id=? AND platform=? AND pinned=0'); $s->execute([$uid, $in['platform']]);
            } else {
                $s = $db->prepare('DELETE FROM interactions WHERE user_id=? AND pinned=0'); $s->execute([$uid]);
            }
            out(['deleted' => $s->rowCount()]);
        }
        case 'settings': {
            if ($method === 'POST') {
                $b = retention_bounds();
                $hours = max($b['min'], min($b['max'], (int)($in['retention_hours'] ?? 24)));
                $thr = max(0.2, min(0.8, (float)($in['ml_threshold'] ?? 0.5)));
                $db->prepare('UPDATE user_settings SET retention_hours=?, auto_clean=?, ml_enabled=?, ml_threshold=? WHERE user_id=?')
                   ->execute([$hours, !empty($in['auto_clean']) ? 1 : 0, !empty($in['ml_enabled']) ? 1 : 0, $thr, $uid]);
            }
            $m = ml_model($uid);
            $n = $db->prepare('SELECT COUNT(*) FROM training_samples WHERE user_id=?');
            $n->execute([$uid]);
            out(['settings' => user_settings($uid), 'bounds' => retention_bounds(),
                 'model' => $m ? ['samples' => (int)$m['samples'], 'accuracy' => round((float)$m['accuracy'], 3), 'trained_at' => (int)$m['trained_at']] : null,
                 'samples' => (int)$n->fetchColumn()]);
        }
        case 'cleanup': {
            if ($method !== 'POST') fail(405, 'POST only');
            out(cleanup_user($uid, true));
        }
    }

    // ---------- admin ----------
    if (str_starts_with($action, 'admin_')) {
        if ($user['role'] !== 'admin') fail(403, 'Admins only');
        switch ($action) {
            case 'admin_overview': {
                $users = $db->query('SELECT u.id,u.email,u.name,u.picture,u.role,u.disabled,u.created_at,u.last_login,
                                     (SELECT COUNT(*) FROM interactions i WHERE i.user_id=u.id) items
                                     FROM users u ORDER BY u.created_at DESC')->fetchAll();
                $log = $db->query('SELECT l.*, u.email FROM cleanup_log l LEFT JOIN users u ON u.id=l.user_id ORDER BY l.id DESC LIMIT 25')->fetchAll();
                out(['users' => $users, 'log' => $log, 'bounds' => retention_bounds(),
                     'totals' => ['users' => count($users), 'items' => (int)$db->query('SELECT COUNT(*) FROM interactions')->fetchColumn(),
                                  'cleaned' => (int)$db->query('SELECT COALESCE(SUM(deleted),0) FROM cleanup_log')->fetchColumn()]]);
            }
            case 'admin_toggle_user': {
                $id = (int)($in['id'] ?? 0);
                if ($id === $uid) fail(400, 'You cannot disable your own account');
                $db->prepare('UPDATE users SET disabled=? WHERE id=?')->execute([!empty($in['disabled']) ? 1 : 0, $id]);
                out(['ok' => true]);
            }
            case 'admin_settings': {
                $min = max(1, (int)($in['min'] ?? 1));
                $max = max($min, min(8760, (int)($in['max'] ?? 720)));
                $def = max($min, min($max, (int)($in['default'] ?? 24)));
                $q = $db->prepare('UPDATE app_settings SET value=? WHERE key=?');
                $q->execute([$min, 'min_retention_hours']);
                $q->execute([$max, 'max_retention_hours']);
                $q->execute([$def, 'default_retention_hours']);
                out(['bounds' => retention_bounds()]);
            }
            case 'admin_cleanup_all': {
                $d = 0; $k = 0;
                foreach ($db->query('SELECT id FROM users WHERE disabled=0')->fetchAll() as $r) {
                    $res = cleanup_user((int)$r['id'], true); $d += $res['deleted']; $k += $res['kept_by_ml'];
                }
                out(['deleted' => $d, 'kept_by_ml' => $k]);
            }
        }
    }
    fail(404, 'Unknown action');
} catch (Throwable $e) {
    error_log('[replay] ' . $e->getMessage());
    fail(500, 'Server error');
}

function public_user(array $u): array {
    return ['id' => (int)$u['id'], 'email' => $u['email'], 'name' => $u['name'], 'picture' => $u['picture'], 'role' => $u['role']];
}
