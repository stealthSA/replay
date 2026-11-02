<?php
$v = max(filemtime(__DIR__ . '/assets/app.js'), filemtime(__DIR__ . '/assets/app.css'));
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Current – Stay Current</title>
<meta name="theme-color" content="#1668ff">
<meta name="description" content="Browse Meta's platforms with a smart, self-cleaning history.">
<link rel="manifest" href="manifest.json">
<link rel="icon" href="icons/icon-192.png">
<link rel="apple-touch-icon" href="icons/apple-touch-icon.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Current">
<link rel="stylesheet" href="assets/app.css?v=<?= $v ?>">
</head>
<body>
<div id="app" aria-live="polite"></div>
<div id="toast" role="status"></div>
<noscript>Current needs JavaScript.</noscript>
<script src="assets/app.js?v=<?= $v ?>"></script>
</body>
</html>
