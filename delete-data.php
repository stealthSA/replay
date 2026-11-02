<?php
require_once __DIR__ . '/app/bootstrap.php'; require_once __DIR__ . '/app/page.php';
$app = e((string)cfg('APP_NAME')); $mail = e((string)cfg('CONTACT_EMAIL')); $base = e(base_url());
$status = '';
if (!empty($_GET['code'])) {
    $s = db()->prepare('SELECT * FROM deletion_requests WHERE code=?'); $s->execute([(string)$_GET['code']]); $r = $s->fetch();
    $status = $r ? '<div class="card"><strong>Deletion request ' . e($r['code']) . '</strong><p>Requested ' . e(date('j M Y H:i', (int)$r['requested_at'])) . ' UTC — '
        . ($r['completed_at'] ? '<strong>completed</strong>. ' . e((string)$r['note']) : 'in progress.') . '</p></div>'
        : '<div class="card"><strong>No request found for that code.</strong></div>';
}
legal_page('Delete my data', $status . <<<HTML
<h1>Delete your data</h1>
<p>You are in control of what $app keeps. Choose any of these:</p>
<ol>
<li><strong>In the app.</strong> Sign in, open <em>Settings</em> and choose <em>Delete my account</em> to erase your recap, saved songs, connections and tokens immediately. To remove just one platform, open <em>Connections</em> and use <em>Disconnect</em> (optionally with “also delete what it synced”).</li>
<li><strong>From Facebook, Instagram or Threads.</strong> Remove $app from the app/website settings of that platform (for Facebook: <em>Settings &amp; privacy → Settings → Apps and websites</em>, select $app, then <em>Remove</em>, tick “delete posts, photos and videos…” if shown). Meta tells us automatically; we revoke access and delete the data synced from that account, and you get a confirmation code that you can look up on this page.</li>
<li><strong>By email.</strong> Write to <a href="mailto:$mail">$mail</a> from the address on your account and we will delete it.</li>
</ol>
<p>Deletion is permanent and cannot be undone. Cached thumbnails are removed at the same time.</p>
<form method="get" class="card"><label>Have a confirmation code?<input name="code" placeholder="e.g. 3f9a1c0b7d2e4a55" required></label><button>Check status</button></form>
HTML);
