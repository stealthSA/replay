<?php
// Current – configuration. Override anything in config.local.php (not committed / not web-readable secrets).
if (is_file(__DIR__ . '/config.local.php')) require __DIR__ . '/config.local.php';

defined('APP_NAME')        || define('APP_NAME', 'Current');
defined('ADMIN_EMAIL')     || define('ADMIN_EMAIL', 'softwarefanatics@gmail.com');
// Firebase web app config (Firebase console → Project settings → Your apps → Web app). These values are public by design.
defined('FIREBASE_API_KEY')     || define('FIREBASE_API_KEY', getenv('FIREBASE_API_KEY') ?: '');
defined('FIREBASE_PROJECT_ID')  || define('FIREBASE_PROJECT_ID', getenv('FIREBASE_PROJECT_ID') ?: '');
defined('FIREBASE_AUTH_DOMAIN') || define('FIREBASE_AUTH_DOMAIN', getenv('FIREBASE_AUTH_DOMAIN') ?: (FIREBASE_PROJECT_ID ? FIREBASE_PROJECT_ID . '.firebaseapp.com' : ''));
defined('FIREBASE_APP_ID')      || define('FIREBASE_APP_ID', getenv('FIREBASE_APP_ID') ?: '');
// Loopback-only test login (never works from a non-local address). Keep false in production.
defined('DEV_LOGIN')       || define('DEV_LOGIN', false);
defined('DATA_DIR')        || define('DATA_DIR', __DIR__ . '/data');

date_default_timezone_set('UTC');
