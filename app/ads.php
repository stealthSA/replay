<?php
declare(strict_types=1);
/**
 * Advertisers ("Bid ads"), bids, circle and notifications.
 *
 * How an ad "placement" works here: an advertiser sends a paid offer for a post that is already getting a lot of engagement.
 * Only posts whose owner switched on "open to ad offers" can receive one, and the owner approves or declines every offer.
 * An accepted offer shows a "Sponsored by" label with the advertiser's message and link on the post inside this app.
 * (Running a real Meta ad or partnership ad is done in Meta's own tools; this app handles discovery, consent and the agreement. No payments are processed.)
 */
require_once __DIR__ . '/engage.php';

/* ============================================================ notifications */

function notify(int $uid, string $type, string $title, ?string $body, ?int $contentId, ?string $topic, string $ref): bool {
    $s = db()->prepare('INSERT OR IGNORE INTO notifications (user_id, type, title, body, content_id, topic, ref, created_at) VALUES (?,?,?,?,?,?,?,?)');
    $s->execute([$uid, $type, mb_substr($title, 0, 160), $body !== null ? mb_substr($body, 0, 400) : null, $contentId, $topic, $ref, time()]);
    return $s->rowCount() > 0;
}
function notif_list(int $uid, int $limit = 40): array {
    $s = db()->prepare('SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC, id DESC LIMIT ' . (int)$limit); $s->execute([$uid]);
    $u = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=? AND read_at IS NULL'); $u->execute([$uid]);
    return ['unread' => (int)$u->fetchColumn(), 'items' => array_map(fn($r) => ['id' => (int)$r['id'], 'type' => $r['type'], 'title' => $r['title'], 'body' => $r['body'], 'content_id' => $r['content_id'] ? (int)$r['content_id'] : null,
        'topic' => $r['topic'], 'at' => (int)$r['created_at'], 'read' => $r['read_at'] !== null], $s->fetchAll())];
}
function notif_read(int $uid, ?array $ids): void {
    if ($ids === null) { db()->prepare('UPDATE notifications SET read_at=? WHERE user_id=? AND read_at IS NULL')->execute([time(), $uid]); return; }
    $q = db()->prepare('UPDATE notifications SET read_at=? WHERE user_id=? AND id=? AND read_at IS NULL'); foreach ($ids as $i) $q->execute([time(), $uid, (int)$i]);
}

/* ============================================================== advertisers */

function csv_list(string $s, int $max = 40, int $len = 60): array {
    $o = []; foreach (preg_split('/[,\n]+/u', $s) ?: [] as $p) { $p = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $p), " \t#")); if ($p !== '' && mb_strlen($p) <= $len) $o[$p] = true; }
    return array_slice(array_keys($o), 0, $max);
}
function adv_row(int $uid): ?array { $s = db()->prepare('SELECT * FROM advertisers WHERE user_id=?'); $s->execute([$uid]); return $s->fetch() ?: null; }
function adv_public(array $a): array {
    return ['id' => (int)$a['id'], 'brand' => $a['brand'], 'website' => $a['website'], 'keywords' => csv_list((string)$a['keywords']), 'categories' => csv_list((string)$a['categories']),
        'kinds' => csv_list((string)$a['kinds']), 'platforms' => csv_list((string)$a['platforms']), 'min_pct' => (int)$a['min_pct'], 'email_alerts' => (bool)$a['email_alerts']];
}
/** @return array{ok:bool,error?:string,advertiser?:array} */
function adv_save(int $uid, array $in): array {
    $brand = mb_substr(trim((string)($in['brand'] ?? '')), 0, 80); if ($brand === '') return ['ok' => false, 'error' => 'Enter your brand name.'];
    $web = trim((string)($in['website'] ?? '')); if ($web !== '' && !preg_match('#^https://[^\s]{3,200}$#i', $web)) return ['ok' => false, 'error' => 'Website must start with https://'];
    $kw = csv_list(is_array($in['keywords'] ?? null) ? implode(',', $in['keywords']) : (string)($in['keywords'] ?? ''));
    $cats = array_values(array_intersect(csv_list(is_array($in['categories'] ?? null) ? implode(',', $in['categories']) : (string)($in['categories'] ?? '')), categories()));
    if (!$kw && !$cats) return ['ok' => false, 'error' => 'Add at least one keyword (for example “nike sneakers”) or pick a category.'];
    $kinds = array_values(array_intersect(csv_list(is_array($in['kinds'] ?? null) ? implode(',', $in['kinds']) : (string)($in['kinds'] ?? '')), ENG_KINDS));
    $plats = array_values(array_intersect(csv_list(is_array($in['platforms'] ?? null) ? implode(',', $in['platforms']) : (string)($in['platforms'] ?? '')), array_keys(PLATFORMS)));
    $min = max(50, min(99, (int)($in['min_pct'] ?? 85)));
    $db = db(); $args = [$brand, $web ?: null, implode(',', $kw), implode(',', $cats), implode(',', $kinds), implode(',', $plats), $min, empty($in['email_alerts']) ? 0 : 1];
    if (adv_row($uid)) $db->prepare('UPDATE advertisers SET brand=?, website=?, keywords=?, categories=?, kinds=?, platforms=?, min_pct=?, email_alerts=? WHERE user_id=?')->execute(array_merge($args, [$uid]));
    else $db->prepare('INSERT INTO advertisers (brand, website, keywords, categories, kinds, platforms, min_pct, email_alerts, user_id, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute(array_merge($args, [$uid, time()]));
    return ['ok' => true, 'advertiser' => adv_public(adv_row($uid))];
}

/** Why does this content match the advertiser's interests? null = it does not. */
function adv_match(array $adv, array $c, array $topics): ?string {
    $kinds = csv_list((string)$adv['kinds']); if ($kinds && !in_array($c['kind'], $kinds, true)) return null;
    $plats = csv_list((string)$adv['platforms']); if ($plats && !in_array($c['platform'], $plats, true)) return null;
    $text = content_text($c); $tset = array_flip($topics);
    foreach (csv_list((string)$adv['keywords']) as $k) if (phrase_in($text, $k) || isset($tset[$k])) return 'Mentions “' . $k . '”';
    $cats = csv_list((string)$adv['categories']); if ($c['category'] && in_array($c['category'], $cats, true)) return 'Category: ' . $c['category'];
    return null;
}
function topics_of(array $ids): array {
    if (!$ids) return []; $in = implode(',', array_map('intval', $ids)); $o = [];
    foreach (db()->query("SELECT content_id, topic FROM content_topics WHERE content_id IN ($in)")->fetchAll() as $r) $o[(int)$r['content_id']][] = $r['topic'];
    return $o;
}

/** Biddable, high-engagement posts matching the advertiser. */
function ads_opportunities(array $adv, int $uid, int $limit = 30, ?int $now = null): array {
    $now ??= time();
    $s = db()->prepare(CONTENT_SELECT . ' WHERE ' . POOL_SQL . " AND c.owner_user_id IS NOT NULL AND c.owner_user_id != ? AND COALESCE(p.open_to_ads,0)=1 AND c.posted_at >= ? AND c.pct >= ?
        AND NOT EXISTS (SELECT 1 FROM bids b WHERE b.content_id=c.id AND b.status='accepted' AND b.ends_at >= ?) ORDER BY c.trend DESC LIMIT 200");
    $s->execute([$uid, $now - 7 * DAY, (int)$adv['min_pct'], $now]); $rows = $s->fetchAll(); $tp = topics_of(array_map(fn($r) => (int)$r['id'], $rows));
    $hit = []; foreach ($rows as $r) { $why = adv_match($adv, $r, $tp[(int)$r['id']] ?? []); if ($why) { $hit[] = [$r, $why]; if (count($hit) >= $limit) break; } }
    $pub = content_public(array_map(fn($h) => $h[0], $hit), $uid);
    $mine = db()->prepare("SELECT content_id, status FROM bids WHERE advertiser_id=? AND status IN ('pending','accepted')"); $mine->execute([$adv['id']]); $mine = array_column($mine->fetchAll(), 'status', 'content_id');
    foreach ($pub as $i => &$p) { $p['why'] = $hit[$i][1]; $p['my_bid'] = $mine[$p['id']] ?? null; } unset($p);
    return $pub;
}

function surge_matches(array $adv, array $s): bool {
    $t = mb_strtolower($s['topic']); $kws = csv_list((string)$adv['keywords']);
    foreach ($kws as $k) if ($t === $k || phrase_in($t, $k) || phrase_in($k, $t)) return true;
    return in_array($t, csv_list((string)$adv['categories']), true);
}

/** Creates every notification that is due. Safe to run often: each one is unique per (user, ref). @return int how many were created */
function ads_notify_all(int $now): int {
    $db = db(); $made = 0; $surges = topic_surges($now, 30);
    foreach ($db->query('SELECT * FROM advertisers')->fetchAll() as $adv) {
        $new = [];
        foreach (ads_opportunities($adv, (int)$adv['user_id'], 25, $now) as $o) {
            $why = $o['why']; $who = $o['author'] ?: 'A creator';
            if (notify((int)$adv['user_id'], 'opportunity', 'High-engagement post: ' . $why, $who . '’s ' . $o['kind'] . ' on ' . (PLATFORMS[$o['platform']]['name'] ?? $o['platform']) . ' is in the top ' . max(1, 100 - $o['pct']) . '% right now. You can send an ad offer.', $o['id'], null, 'opp:' . $adv['id'] . ':' . $o['id'])) { $made++; $new[] = $why . ' (' . $who . ')'; }
        }
        foreach ($surges as $s) if (surge_matches($adv, $s)) {
            if (notify((int)$adv['user_id'], 'surge', 'Rising topic: ' . $s['topic'], 'Engagement on “' . $s['topic'] . '” is ' . $s['ratio'] . '× its weekly norm across ' . $s['authors'] . ' creators.', $s['top_content'], $s['topic'], 'surge:' . $adv['id'] . ':' . $s['topic'] . ':' . gmdate('Ymd', $now))) { $made++; $new[] = 'topic ' . $s['topic']; }
        }
        if ($new && cfg('ALERT_EMAILS') && $adv['email_alerts']) {
            $u = $db->prepare('SELECT email FROM users WHERE id=?'); $u->execute([$adv['user_id']]); $to = (string)$u->fetchColumn();
            if (filter_var($to, FILTER_VALIDATE_EMAIL)) @mail($to, cfg('APP_NAME') . ': ' . count($new) . ' new ad opportunities', "New high-engagement content matches your interests:\n- " . implode("\n- ", array_slice($new, 0, 10)) . "\n\nOpen " . base_url() . "/#/bids", 'From: ' . cfg('CONTACT_EMAIL'));
        }
    }
    // circle breakouts: tell people when someone in their circle has a post taking off
    $s = $db->prepare(CONTENT_SELECT . ' WHERE c.owner_user_id IS NOT NULL AND COALESCE(p.share_circle,1)=1 AND c.posted_at >= ? AND c.breakout >= 1.5 AND c.engagement >= 2');
    $s->execute([$now - 3 * DAY]);
    foreach ($s->fetchAll() as $c) foreach (circle_ids((int)$c['owner_user_id']) as $member)
        if (notify($member, 'circle', ($c['owner_name'] ?: 'Someone in your circle') . '’s post is taking off', 'It is getting ' . round((float)$c['breakout'], 1) . '× the engagement they usually get.', (int)$c['id'], null, 'circle:' . $member . ':' . $c['id'])) $made++;
    return $made;
}

/* ================================================================== bids */

function bid_public(array $b): array {
    return ['id' => (int)$b['id'], 'content_id' => (int)$b['content_id'], 'amount_cents' => (int)$b['amount_cents'], 'currency' => $b['currency'], 'days' => (int)$b['days'], 'message' => $b['message'], 'link' => $b['link'], 'status' => $b['status'],
        'created_at' => (int)$b['created_at'], 'ends_at' => $b['ends_at'] ? (int)$b['ends_at'] : null, 'brand' => $b['brand'] ?? null, 'content_title' => $b['c_title'] ?? null, 'content_author' => $b['c_author'] ?? null, 'platform' => $b['c_platform'] ?? null, 'mockup' => !empty($b['mockup_path']) ? 'thumb.php?ad=' . (int)$b['id'] : null];
}

/** @return array{ok:bool,error?:string,bid?:array} */
function bid_place(int $uid, array $in): array {
    $adv = adv_row($uid); if (!$adv) return ['ok' => false, 'error' => 'Set up your advertiser profile first.'];
    $db = db(); $cid = (int)($in['content_id'] ?? 0);
    $s = $db->prepare(CONTENT_SELECT . ' WHERE c.id=?'); $s->execute([$cid]); $c = $s->fetch();
    if (!$c || $c['owner_user_id'] === null) return ['ok' => false, 'error' => 'Ad offers can only be sent for posts by people who use this app.'];
    if ((int)$c['owner_user_id'] === $uid) return ['ok' => false, 'error' => 'You cannot bid on your own post.'];
    if (!(int)$c['open_to_ads']) return ['ok' => false, 'error' => 'The owner of this post is not open to ad offers.'];
    $cents = (int)round(((float)($in['amount'] ?? 0)) * 100); $min = (int)cfg('BID_MIN_CENTS');
    if ($cents < $min) return ['ok' => false, 'error' => 'The minimum offer is ' . cfg('BID_CURRENCY') . ' ' . number_format($min / 100, 2) . '.'];
    if ($cents > 100000000) return ['ok' => false, 'error' => 'That amount is too large.'];
    $days = (int)($in['days'] ?? 7); if ($days < 1 || $days > 30) return ['ok' => false, 'error' => 'Choose between 1 and 30 days.'];
    $msg = mb_substr(trim((string)($in['message'] ?? '')), 0, 280); if ($msg === '') return ['ok' => false, 'error' => 'Add the short message that will appear with your ad.'];
    $link = trim((string)($in['link'] ?? '')); if ($link !== '' && !preg_match('#^https://[^\s<>"]{3,300}$#i', $link)) return ['ok' => false, 'error' => 'The link must start with https://'];
    $x = $db->prepare("SELECT COUNT(*) FROM bids WHERE advertiser_id=? AND content_id=? AND status IN ('pending','accepted')"); $x->execute([$adv['id'], $cid]);
    if ((int)$x->fetchColumn() > 0) return ['ok' => false, 'error' => 'You already have an offer on this post.'];
    $x = $db->prepare("SELECT COUNT(*) FROM bids WHERE content_id=? AND status='accepted' AND ends_at >= ?"); $x->execute([$cid, time()]);
    if ((int)$x->fetchColumn() > 0) return ['ok' => false, 'error' => 'This post already has a sponsor.'];
    $x = $db->prepare("SELECT COUNT(*) FROM bids WHERE advertiser_id=? AND status='pending'"); $x->execute([$adv['id']]);
    if ((int)$x->fetchColumn() >= 25) return ['ok' => false, 'error' => 'You have 25 offers waiting. Withdraw one first.'];
    $mk = $db->prepare('SELECT path FROM ad_mockups WHERE advertiser_id=? AND content_id=?'); $mk->execute([$adv['id'], $cid]); $mock = !empty($in['use_mockup']) ? ($mk->fetchColumn() ?: null) : null;   // Muse Image picture the advertiser chose to attach
    $db->prepare('INSERT INTO bids (content_id, advertiser_id, amount_cents, currency, days, message, link, mockup_path, created_at) VALUES (?,?,?,?,?,?,?,?,?)')
       ->execute([$cid, $adv['id'], $cents, cfg('BID_CURRENCY'), $days, $msg, $link ?: null, $mock, time()]);
    $id = (int)$db->lastInsertId();
    notify((int)$c['owner_user_id'], 'bid_received', $adv['brand'] . ' wants to run an ad on your post', cfg('BID_CURRENCY') . ' ' . number_format($cents / 100, 2) . ' for ' . $days . ' day' . ($days === 1 ? '' : 's') . '. Open “Bid ads” to accept or decline.', $cid, null, 'bid:' . $id . ':received');
    $b = $db->prepare('SELECT * FROM bids WHERE id=?'); $b->execute([$id]);
    return ['ok' => true, 'bid' => bid_public($b->fetch())];
}

const BID_JOIN = 'SELECT b.*, a.brand, a.user_id AS adv_user, c.title AS c_title, c.author AS c_author, c.platform AS c_platform, c.owner_user_id AS c_owner FROM bids b JOIN advertisers a ON a.id=b.advertiser_id JOIN content c ON c.id=b.content_id';

function bids_mine(int $uid): array {
    $s = db()->prepare(BID_JOIN . ' WHERE a.user_id=? ORDER BY b.created_at DESC LIMIT 100'); $s->execute([$uid]); return array_map('bid_public', $s->fetchAll());
}
function bids_offers(int $uid): array {
    $s = db()->prepare(BID_JOIN . " WHERE c.owner_user_id=? AND b.status IN ('pending','accepted') ORDER BY CASE b.status WHEN 'pending' THEN 0 ELSE 1 END, b.amount_cents DESC LIMIT 100"); $s->execute([$uid]); return array_map('bid_public', $s->fetchAll());
}

/** The post's owner accepts or declines an offer. Accepting makes it the sponsor and declines the rest. */
function bid_respond(int $uid, int $bidId, bool $accept): array {
    $db = db(); $s = $db->prepare(BID_JOIN . ' WHERE b.id=?'); $s->execute([$bidId]); $b = $s->fetch();
    if (!$b || (int)$b['c_owner'] !== $uid) return ['ok' => false, 'error' => 'Offer not found.'];
    if ($b['status'] !== 'pending') return ['ok' => false, 'error' => 'This offer was already answered.'];
    $now = time();
    if ($accept) {
        $x = $db->prepare("SELECT COUNT(*) FROM bids WHERE content_id=? AND status='accepted' AND ends_at >= ?"); $x->execute([$b['content_id'], $now]);
        if ((int)$x->fetchColumn() > 0) return ['ok' => false, 'error' => 'This post already has a sponsor.'];
        $db->beginTransaction();
        $db->prepare("UPDATE bids SET status='accepted', decided_at=?, starts_at=?, ends_at=? WHERE id=?")->execute([$now, $now, $now + (int)$b['days'] * DAY, $bidId]);
        $others = $db->prepare("SELECT b.id, a.user_id FROM bids b JOIN advertisers a ON a.id=b.advertiser_id WHERE b.content_id=? AND b.status='pending' AND b.id != ?"); $others->execute([$b['content_id'], $bidId]);
        foreach ($others->fetchAll() as $o) { $db->prepare("UPDATE bids SET status='outbid', decided_at=? WHERE id=?")->execute([$now, $o['id']]); notify((int)$o['user_id'], 'bid_declined', 'Your offer was not chosen', 'Another advertiser’s offer was accepted for that post.', (int)$b['content_id'], null, 'bid:' . $o['id'] . ':outbid'); }
        $db->commit();
        notify((int)$b['adv_user'], 'bid_accepted', 'Your offer was accepted', 'Your ad is live on the post for ' . $b['days'] . ' day' . ((int)$b['days'] === 1 ? '' : 's') . '.', (int)$b['content_id'], null, 'bid:' . $bidId . ':accepted');
    } else {
        $db->prepare("UPDATE bids SET status='declined', decided_at=? WHERE id=?")->execute([$now, $bidId]);
        notify((int)$b['adv_user'], 'bid_declined', 'Your offer was declined', 'The creator chose not to run the ad.', (int)$b['content_id'], null, 'bid:' . $bidId . ':declined');
    }
    return ['ok' => true];
}
function bid_withdraw(int $uid, int $bidId): array {
    $s = db()->prepare("UPDATE bids SET status='withdrawn', decided_at=? WHERE id=? AND status='pending' AND advertiser_id=(SELECT id FROM advertisers WHERE user_id=?)"); $s->execute([time(), $bidId, $uid]);
    return $s->rowCount() ? ['ok' => true] : ['ok' => false, 'error' => 'Only a waiting offer can be withdrawn.'];
}

/* ================================================================= circle */

function circle_invite(int $uid, string $email): void {
    $email = strtolower(trim($email)); if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid email address.');
    $db = db(); $s = $db->prepare('SELECT id, name FROM users WHERE email=? AND disabled=0'); $s->execute([$email]); $o = $s->fetch();
    if (!$o || (int)$o['id'] === $uid) return;     // same reply either way, so this cannot be used to find out who has an account
    $x = $db->prepare('SELECT * FROM circle WHERE (user_id=? AND member_id=?) OR (user_id=? AND member_id=?)'); $x->execute([$uid, $o['id'], $o['id'], $uid]); $ex = $x->fetch();
    if ($ex) { if ($ex['status'] === 'pending' && (int)$ex['user_id'] === (int)$o['id']) $db->prepare("UPDATE circle SET status='accepted' WHERE user_id=? AND member_id=?")->execute([$o['id'], $uid]); return; }
    $db->prepare('INSERT INTO circle (user_id, member_id, status, created_at) VALUES (?,?,\'pending\',?)')->execute([$uid, $o['id'], time()]);
    $me = $db->prepare('SELECT name, email FROM users WHERE id=?'); $me->execute([$uid]); $me = $me->fetch();
    notify((int)$o['id'], 'circle_invite', ($me['name'] ?: $me['email']) . ' invited you to their circle', 'Open “My circle” to accept. You will see how each other’s posts are performing.', null, null, 'cinv:' . $uid . ':' . $o['id']);
}
function circle_state(int $uid): array {
    $db = db();
    $m = $db->prepare("SELECT u.id, u.name, u.picture, c.created_at FROM circle c JOIN users u ON u.id = CASE WHEN c.user_id=? THEN c.member_id ELSE c.user_id END WHERE c.status='accepted' AND (c.user_id=? OR c.member_id=?) ORDER BY u.name"); $m->execute([$uid, $uid, $uid]);
    $in = $db->prepare("SELECT u.id, u.name, u.picture FROM circle c JOIN users u ON u.id=c.user_id WHERE c.status='pending' AND c.member_id=?"); $in->execute([$uid]);
    $out = $db->prepare("SELECT u.id, u.name, u.email FROM circle c JOIN users u ON u.id=c.member_id WHERE c.status='pending' AND c.user_id=?"); $out->execute([$uid]);
    return ['members' => array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'picture' => $r['picture']], $m->fetchAll()),
            'incoming' => array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'picture' => $r['picture']], $in->fetchAll()),
            'outgoing' => array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'email' => $r['email']], $out->fetchAll())];
}
function circle_respond(int $uid, int $fromId, bool $accept): bool {
    $db = db();
    if ($accept) { $s = $db->prepare("UPDATE circle SET status='accepted' WHERE user_id=? AND member_id=? AND status='pending'"); $s->execute([$fromId, $uid]); return $s->rowCount() > 0; }
    $s = $db->prepare("DELETE FROM circle WHERE user_id=? AND member_id=? AND status='pending'"); $s->execute([$fromId, $uid]); return $s->rowCount() > 0;
}
function circle_remove(int $uid, int $otherId): void { db()->prepare('DELETE FROM circle WHERE (user_id=? AND member_id=?) OR (user_id=? AND member_id=?)')->execute([$uid, $otherId, $otherId, $uid]); }
