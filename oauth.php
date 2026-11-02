<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/meta.php';

$platform = (string)($_GET['p'] ?? ''); $mode = ($_GET['mode'] ?? '') === 'login' ? 'login' : 'connect';
$home = base_url() . '/';
$go = function (string $hash) use ($home) { header('Location: ' . $home . '#/' . $hash); exit; };
if (!isset(PLATFORMS[$platform])) $go('connections');
if ($mode === 'login' && $platform !== 'facebook') $mode = 'connect';
if (!meta_configured($platform)) $go(($mode === 'login' ? 'login' : 'connections') . '?msg=' . rawurlencode(PLATFORMS[$platform]['name'] . ' is not set up on this server yet — add its app ID and secret to config.local.php.'));
if ($mode === 'connect' && !session_user()) $go('login?msg=' . rawurlencode('Sign in first, then connect your ' . PLATFORMS[$platform]['name'] . ' account.'));
header('Location: ' . oauth_start_url($platform, $mode)); exit;
