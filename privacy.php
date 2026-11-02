<?php
require_once __DIR__ . '/app/bootstrap.php'; require_once __DIR__ . '/app/page.php';
$app = e((string)cfg('APP_NAME')); $mail = e((string)cfg('CONTACT_EMAIL'));
legal_page('Privacy Policy', <<<HTML
<h1>Privacy Policy</h1>
<p class="muted">Last updated: October 2026</p>
<p>$app helps you keep a recap of content you interact with on Facebook, Instagram and Threads — stories, podcasts and discussions — and organise it for later, including songs you want to listen to later. This policy explains what we collect, why, and how to remove it.</p>

<h2>What we collect</h2>
<ul>
<li><strong>Sign-in details.</strong> If you continue with Facebook: your name, profile picture and, if you allow it, your email address. If you sign up with email: your name, email and a salted password hash.</li>
<li><strong>Connected accounts (Instagram, Threads, and Facebook if you grant the permission).</strong> Your account name and username, profile picture, and the content that Meta's APIs let us read for the permissions you approve: your own posts, stories, threads and replies (links, captions, timestamps and a thumbnail picture). We copy a small thumbnail so it is still available after Meta's picture link expires.</li>
<li><strong>Links you add yourself.</strong> Links you paste, share to the app, or that the optional browser extension saves (the link, page title and time spent on it). The extension runs only on Facebook, Instagram, Threads and Meta Quest pages, never on Messenger, WhatsApp or Meta AI.</li>
<li><strong>Songs you save.</strong> Title, artist and the story they came from.</li>
</ul>
<p>We do <strong>not</strong> read your private messages, your friends list, or anyone else's private content, and we do not collect what you watched unless you add it or use the extension.</p>

<h2>How we use it</h2>
<p>By default, only to show you your own recap and let you search, organise and revisit it. We do not sell your data and we do not use Meta platform data to train AI models. The optional features below are <strong>off until you switch them on</strong>.</p>

<h2>Optional sharing of how your posts perform (Trending, My circle, Bid ads)</h2>
<p>If you connect an account, we read the engagement numbers Meta's APIs give us for <em>your own</em> posts (for example likes, comments, views, shares and watch time). They are private to you unless you choose otherwise in <em>Trending → My posts</em>:</p>
<ul>
<li><strong>My circle</strong> (on by default, only after both people accept an invitation): people in your circle can see how your posts perform and are told when one is taking off. Switch it off at any time.</li>
<li><strong>Appear in Trending</strong> (off by default): the text, link, thumbnail and engagement numbers of your posts can be shown to other signed-in users.</li>
<li><strong>Open to ad offers</strong> (off by default): advertisers who have signed up can be alerted that your post is getting a lot of engagement and can send you an ad offer. Nothing appears on your post unless you accept the offer, and you can decline any of them. Advertisers do not receive your name or email address from us; they see the post as shown in Trending.</li>
</ul>
<p><strong>AI analysis.</strong> Only for posts you have chosen to share (Trending or ad offers), and for licensed public imports, the post text (and, if the site owner turns it on, the picture) is sent to Meta's Model API, or to a model running on this site's own server, to work out its topic and brand and to write short summaries and ad suggestions for advertisers. Private posts are never sent. Meta's terms for the Model API apply to that processing.</p>
<p>Turning a switch off removes your posts from that feature straight away. Advertisers' own profile details (brand, keywords, offers) are visible to the creator who receives an offer. We do not process payments.</p>

<h2>Security</h2>
<p>Access tokens issued by Meta are stored encrypted. Extension tokens are stored only as hashes and can be revoked in Connections. Traffic is served over HTTPS.</p>

<h2>Retention and deletion</h2>
<p>Data is kept until you delete it. You can remove a single item, disconnect an account (optionally deleting what it synced), export everything as JSON, or delete your whole account from <em>Settings</em>. You can also remove $app in your Facebook, Instagram or Threads settings: Meta then notifies us and we revoke the access and remove the data synced from that account. See <a href="delete-data.php">how to delete your data</a>.</p>

<h2>Contact</h2>
<p>Questions or requests: <a href="mailto:$mail">$mail</a>.</p>
HTML);
