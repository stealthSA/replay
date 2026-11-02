<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/meta.php';
require_once __DIR__ . '/app/engage.php';   // loads the Meta Model API helpers
header('Content-Type: application/json'); header('Cache-Control: no-store');
$ext = []; foreach (['pdo_sqlite', 'curl', 'openssl', 'mbstring', 'gd'] as $x) $ext[$x] = extension_loaded($x);
$dir = rtrim((string)cfg('STORAGE_DIR'), '/');
$cf = APP_ROOT . '/config.local.php'; $missing = [];
foreach (['META_APP_ID','META_APP_SECRET','INSTAGRAM_APP_ID','INSTAGRAM_APP_SECRET','THREADS_APP_ID','THREADS_APP_SECRET'] as $k) { $v = (string)cfg($k); if ($v === '' || str_starts_with($v, 'PASTE_')) $missing[] = $k; }
echo json_encode([
    'config_file_found' => is_file($cf), 'config_values_missing' => $missing,
    'php' => PHP_VERSION, 'php_ok' => version_compare(PHP_VERSION, '8.1.0', '>='), 'extensions' => $ext,
    'storage_writable' => is_dir($dir) && is_writable($dir), 'https' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
    'meta' => ['facebook' => meta_configured('facebook'), 'instagram' => meta_configured('instagram'), 'threads' => meta_configured('threads'), 'mock' => (string)cfg('META_MOCK_BASE') !== ''],
    'meta_models' => array_map(fn($m) => $m + ['ffmpeg' => null], ai_models_status()) + ['ffmpeg_found' => ai_ffmpeg() !== null, 'note' => 'active = configured on this server; see META-MODELS.md'],
    'register_these_urls' => [
        'facebook_valid_oauth_redirect' => redirect_uri('facebook'), 'instagram_redirect' => redirect_uri('instagram'), 'threads_redirect' => redirect_uri('threads'),
        'deauthorize_callback' => base_url() . '/meta/deauthorize.php', 'data_deletion_request' => base_url() . '/meta/data-deletion.php',
        'privacy_policy' => base_url() . '/privacy.php', 'terms' => base_url() . '/terms.php', 'data_deletion_instructions' => base_url() . '/delete-data.php',
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
