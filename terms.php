<?php
require_once __DIR__ . '/app/bootstrap.php'; require_once __DIR__ . '/app/page.php';
$app = e((string)cfg('APP_NAME')); $mail = e((string)cfg('CONTACT_EMAIL'));
legal_page('Terms of Service', <<<HTML
<h1>Terms of Service</h1>
<p class="muted">Last updated: October 2026</p>
<p>By using $app you agree to these terms.</p>
<h2>The service</h2>
<p>$app gives you a personal recap of content you connect or add from Facebook, Instagram and Threads, organised into stories, podcasts, discussions and posts, with a list of songs you saved to listen to later. It is provided as is, without guarantees of availability. What can be synced depends on what Meta's APIs and your permissions allow and may change.</p>
<h2>Your responsibilities</h2>
<ul><li>Only connect accounts you own or are authorised to use, and follow the rules of the platforms you connect.</li>
<li>Do not use $app to collect other people's private information or to breach anyone's rights.</li>
<li>Keep your sign-in details and extension tokens private.</li></ul>
<h2>Your content</h2>
<p>Content in your recap remains yours or its original owners'. $app stores links, captions and small thumbnails only to show them back to you. Songs are saved as titles and artists; $app does not provide the music itself — listening happens in the streaming service you choose.</p>
<h2>Ending</h2>
<p>You can stop at any time by deleting your account in Settings. We may suspend accounts that abuse the service.</p>
<h2>Contact</h2>
<p><a href="mailto:$mail">$mail</a></p>
HTML);
