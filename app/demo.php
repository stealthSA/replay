<?php
declare(strict_types=1);
/** Sample recap for local exploration (DEV_LOGIN, loopback only). Pictures are generated gradients — no real people or content. */
require_once __DIR__ . '/content.php';

function demo_thumb(int|string $uid, int $itemId, int $seed): string {
    $palettes = [[[27, 95, 255], [1, 178, 253]], [[248, 4, 165], [255, 154, 0]], [[63, 177, 0], [1, 178, 253]], [[254, 206, 4], [255, 154, 0]], [[10, 42, 140], [27, 95, 255]], [[120, 40, 220], [248, 4, 165]]];
    [$a, $b] = $palettes[$seed % count($palettes)];
    if (!function_exists('imagecreatetruecolor')) return demo_thumb_nogd($uid, $itemId, $a, $b);   // PHP without the GD extension
    $w = 480; $h = 480; $im = imagecreatetruecolor($w, $h);
    for ($y = 0; $y < $h; $y++) { $t = $y / $h; $c = imagecolorallocate($im, (int)($a[0] + ($b[0] - $a[0]) * $t), (int)($a[1] + ($b[1] - $a[1]) * $t), (int)($a[2] + ($b[2] - $a[2]) * $t)); imageline($im, 0, $y, $w, $y, $c); }
    imagealphablending($im, true);
    $white = imagecolorallocatealpha($im, 255, 255, 255, 96); $white2 = imagecolorallocatealpha($im, 255, 255, 255, 78);
    mt_srand($seed * 7919);
    imagefilledellipse($im, mt_rand(80, 400), mt_rand(80, 400), mt_rand(220, 360), mt_rand(220, 360), $white);
    imagefilledellipse($im, mt_rand(80, 400), mt_rand(80, 400), mt_rand(120, 220), mt_rand(120, 220), $white2);
    imagefilledpolygon($im, [mt_rand(0, 200), $h, mt_rand(200, 480), $h, mt_rand(300, 480), mt_rand(240, 380)], $white);
    $dir = rtrim((string)cfg('STORAGE_DIR'), '/') . '/thumbs/' . $uid; if (!is_dir($dir)) @mkdir($dir, 0775, true);
    imagejpeg($im, $dir . '/' . $itemId . '.jpg', 85);
    return $uid . '/' . $itemId . '.jpg';
}

/** Gradient placeholder written as a tiny PNG with plain PHP (no GD, no zlib needed: stored deflate blocks). */
function demo_thumb_nogd(int|string $uid, int $itemId, array $a, array $b): string {
    $w = 64; $h = 64; $raw = '';
    for ($y = 0; $y < $h; $y++) { $t = $y / ($h - 1); $raw .= "\0" . str_repeat(chr((int)($a[0] + ($b[0] - $a[0]) * $t)) . chr((int)($a[1] + ($b[1] - $a[1]) * $t)) . chr((int)($a[2] + ($b[2] - $a[2]) * $t)), $w); }
    $z = "\x78\x01"; $len = strlen($raw); for ($o = 0; $o < $len; $o += 65535) { $c = substr($raw, $o, 65535); $z .= ($o + 65535 >= $len ? "\x01" : "\x00") . pack('v', strlen($c)) . pack('v', strlen($c) ^ 0xFFFF) . $c; }
    $A = 1; $B = 0; for ($i = 0; $i < $len; $i++) { $A = ($A + ord($raw[$i])) % 65521; $B = ($B + $A) % 65521; }
    $z .= pack('N', ($B << 16) | $A);
    $chunk = fn(string $type, string $d) => pack('N', strlen($d)) . $type . $d . pack('N', crc32($type . $d));
    $png = "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0)) . $chunk('IDAT', $z) . $chunk('IEND', '');
    $dir = rtrim((string)cfg('STORAGE_DIR'), '/') . '/thumbs/' . $uid; if (!is_dir($dir)) @mkdir($dir, 0775, true);
    file_put_contents($dir . '/' . $itemId . '.jpg', $png);
    return $uid . '/' . $itemId . '.jpg';
}

function load_demo(int $uid): int {
    $db = db(); $h = 3600; $d = 86400; $now = time();
    // [platform, kind, title, body, author, url, music_title, music_artist, ago, views, interaction, media]
    $rows = [
        ['instagram', 'story', 'Sunset run along the sea wall', 'Golden hour hits different 🌅 🎵 Golden Hour - JVKE', 'mia.moves', 'https://www.instagram.com/stories/mia.moves/3401', 'Golden Hour', 'JVKE', 3 * $h, 3, 'viewed'],
        ['instagram', 'story', 'Studio session with the band', 'Laying down the new single tonight', 'afrobeat.collective', 'https://www.instagram.com/stories/afrobeat.collective/3402', 'Water', 'Tyla', 9 * $h, 2, 'viewed'],
        ['instagram', 'story', 'Weekend market haul', 'Sunday market finds 🍑', 'thandi.cooks', 'https://www.instagram.com/stories/thandi.cooks/3403', null, null, 22 * $h, 1, 'viewed'],
        ['facebook', 'story', 'Cape Town road trip day 2', 'Chapman’s Peak with the windows down ♪ Sunroof - Nicky Youre', 'Lerato M.', 'https://www.facebook.com/stories/100001/3404', 'Sunroof', 'Nicky Youre', 1 * $d + 2 * $h, 1, 'viewed'],
        ['instagram', 'story', 'Coffee with the team', 'Monday reset ☕', 'bluebottle.joburg', 'https://www.instagram.com/stories/bluebottle.joburg/3405', null, null, 2 * $d, 1, 'viewed'],
        ['instagram', 'story', 'Rooftop party prep', 'Tonight!! 🎶 Calm Down - Rema', 'zee.events', 'https://www.instagram.com/stories/zee.events/3406', 'Calm Down', 'Rema', 3 * $d, 2, 'viewed'],
        ['facebook', 'story', 'School choir rehearsal', 'Almost ready for Saturday 🎶', 'Mdu Choir Club', 'https://www.facebook.com/stories/100002/3407', null, null, 4 * $d, 1, 'viewed'],
        ['instagram', 'story', 'New kicks unboxing', 'Finally here', 'sneakerhead.za', 'https://www.instagram.com/stories/sneakerhead.za/3408', 'Essence', 'Wizkid', 6 * $d, 1, 'viewed'],
        ['facebook', 'podcast', 'The Daily Podcast — Building in public with Musa', 'Episode 42: lessons from shipping small apps', 'Musa Mathebula', 'https://podcasters.spotify.com/pod/show/daily-podcast/episodes/42', null, null, 5 * $h, 2, 'viewed'],
        ['threads', 'podcast', 'How I Built This — Notion', 'New episode out #podcast', 'npr', 'https://open.spotify.com/episode/4h2Nn3p9Qz', null, null, 1 * $d, 1, 'viewed'],
        ['instagram', 'podcast', 'Mind the Gap: women in tech', 'Listen on Apple Podcasts', 'techsisters', 'https://podcasts.apple.com/za/podcast/mind-the-gap/id1700000042', null, null, 3 * $d, 1, 'viewed'],
        ['facebook', 'podcast', 'Money Talks SA — side hustles', 'Ep 118 with a guest from Durban', 'Money Talks SA', 'https://www.buzzsprout.com/1999000/episodes/15000000', null, null, 8 * $d, 1, 'viewed'],
        ['threads', 'discussion', 'What is the one productivity habit that actually stuck for you?', 'Replying to a thread with 214 replies — mine was a weekly review on Sunday evenings.', 'dev.diaries', 'https://www.threads.net/@dev.diaries/post/C9aaa1', null, null, 2 * $h, 4, 'replied'],
        ['threads', 'discussion', 'Hot take: small apps beat big platforms for focus', 'Genuinely curious what people think.', 'musa.codes', 'https://www.threads.net/@musa.codes/post/C9aaa2', null, null, 7 * $h, 2, 'posted'],
        ['facebook', 'discussion', 'Joburg Remote Workers — best co-working spaces?', 'Group discussion · 63 comments', 'Joburg Remote Workers', 'https://www.facebook.com/groups/jhbremote/posts/880011', null, null, 1 * $d + $h, 3, 'viewed'],
        ['threads', 'discussion', 'Anyone else tired of notification overload?', 'Replied: I turned off everything except calls. Life-changing.', 'calm.stack', 'https://www.threads.net/@calm.stack/post/C9aaa3', null, null, 2 * $d, 1, 'replied'],
        ['facebook', 'discussion', 'Neighbourhood watch — updates for this week', 'Community update and meeting times', 'Fourways Community', 'https://www.facebook.com/groups/fourwayscomm/posts/880012', null, null, 3 * $d + $h, 1, 'viewed'],
        ['threads', 'discussion', 'Show your desk setup 🖥️', 'Mine: one monitor, one plant.', 'desk.club', 'https://www.threads.net/@desk.club/post/C9aaa4', null, null, 5 * $d, 2, 'viewed'],
        ['instagram', 'discussion', 'Carousel: 7 books that changed how I work (comments are gold)', 'Read the comments — better than the post', 'booknook.za', 'https://www.instagram.com/p/C9bbb1', null, null, 6 * $d, 1, 'viewed'],
        ['facebook', 'discussion', 'Support group: starting a business in 2026', 'Lots of practical advice in this thread', 'SA Startup Starters', 'https://www.facebook.com/groups/sastart/posts/880013', null, null, 9 * $d, 1, 'viewed'],
        ['instagram', 'post', 'Reel: 30-second pasta that tastes like a restaurant', 'Save this for later 🍝', 'thandi.cooks', 'https://www.instagram.com/reel/C9ccc1', null, null, 4 * $h, 2, 'viewed'],
        ['instagram', 'post', 'Reel: Table Mountain timelapse', 'Clouds rolling in 🌫️', 'capetown.daily', 'https://www.instagram.com/reel/C9ccc2', null, null, 1 * $d + 6 * $h, 1, 'viewed'],
        ['facebook', 'post', 'Video: Friday night live set', 'Full set — worth the 40 minutes', 'Soweto Sessions', 'https://www.facebook.com/watch/?v=880014', null, null, 2 * $d + 3 * $h, 1, 'viewed'],
        ['instagram', 'post', 'Photo dump: Drakensberg weekend', 'Weekend away', 'mia.moves', 'https://www.instagram.com/p/C9ccc3', null, null, 4 * $d + 2 * $h, 1, 'viewed'],
    ];
    // ---- bulk generated sample items so every tab has plenty to browse (deterministic) ----
    mt_srand(4242);
    $authors = ['mia.moves','thandi.cooks','capetown.daily','zee.events','sneakerhead.za','booknook.za','techsisters','desk.club','calm.stack','dev.diaries','soweto.sessions','joburg.eats','durban.surf','kasi.kicks','pixel.pete','fit.with.neo','garden.gauteng','road.trip.za','Lerato M.','Mdu Choir Club'];
    $songs = [['Golden Hour','JVKE'],['Water','Tyla'],['Calm Down','Rema'],['Essence','Wizkid'],['Sunroof','Nicky Youre'],['Love Nwantiti','CKay'],['Unavailable','Davido'],['Flowers','Miley Cyrus']];
    $topics = ['sunrise hike','street food tour','new sneakers','studio session','beach day','market haul','road trip','birthday dinner','gym progress','garden update','coffee run','live set','sunset drive','book haul','desk setup','weekend braai'];
    $podShows = [['https://open.spotify.com/episode/','Spotify'],['https://podcasts.apple.com/za/podcast/ep/id17','Apple Podcasts'],['https://www.buzzsprout.com/2000/episodes/','Buzzsprout'],['https://podcasters.spotify.com/pod/show/demo/episodes/','Anchor']];
    $discQ = ['What is your best budget travel tip?','Which side hustle actually paid off?','Best local coffee shops?','How do you stay focused while working remotely?','Share your favourite playlist for running','Is it worth learning to code in 2026?','Tips for a first marathon?','Which phone has the best camera right now?','How do you plan meals for the week?','Hidden gems to visit this month?'];
    $gen = []; $quals = ['part 1','part 2','behind the scenes','day 3','the full story','quick update','weekend edition','golden hour','with the crew','take two','unfiltered','morning vibes'];
    for ($i = 0; $i < 120; $i++) { $t = $topics[$i % count($topics)]; $m = $songs[$i % count($songs)]; $pl = ['instagram','facebook'][$i % 2]; $au = $authors[($i * 3) % count($authors)];
        $gen[] = [$pl, 'story', ucfirst($t) . ' · ' . $quals[$i % count($quals)], ucfirst($t) . ($i % 3 === 0 ? ' 🎵 ' . $m[0] . ' - ' . $m[1] : ''), $au, "https://www.$pl.com/stories/$au/g" . (5000 + $i), $i % 3 === 0 ? $m[0] : null, $i % 3 === 0 ? $m[1] : null, mt_rand(0, 29) * $d + mt_rand(0, 20) * $h, mt_rand(1, 6), 'viewed']; }
    for ($i = 0; $i < 60; $i++) { $ps = $podShows[$i % count($podShows)]; $pl = ['facebook','instagram','threads'][$i % 3];
        $gen[] = [$pl, 'podcast', 'Episode ' . (30 + $i) . ': ' . ucfirst($topics[($i * 5) % count($topics)]) . ' · ' . $quals[$i % count($quals)], 'Listen on ' . $ps[1] . ' #podcast', $authors[($i * 7) % count($authors)], $ps[0] . (700000 + $i), null, null, mt_rand(0, 29) * $d + mt_rand(0, 20) * $h, mt_rand(1, 4), 'viewed']; }
    for ($i = 0; $i < 100; $i++) { $q = $discQ[$i % count($discQ)]; $pl = ['threads','facebook','instagram'][$i % 3]; $au = $authors[($i * 5) % count($authors)];
        $u = $pl === 'threads' ? "https://www.threads.net/@$au/post/G" . (900 + $i) : ($pl === 'facebook' ? 'https://www.facebook.com/groups/demo' . ($i % 5) . '/posts/' . (990000 + $i) : "https://www.instagram.com/p/G" . (800 + $i));
        $gen[] = [$pl, 'discussion', $q . ($i >= 10 ? ' (' . (intdiv($i, 10) + 1) . ')' : ''), 'Thread with ' . mt_rand(12, 400) . ' replies. ' . ['Great replies in here.', 'My take: start small and stay consistent.', 'Replied with my own experience.'][$i % 3], $au, $u, null, null, mt_rand(0, 29) * $d + mt_rand(0, 20) * $h, mt_rand(1, 5), ['viewed','replied','posted'][$i % 3]]; }
    for ($i = 0; $i < 120; $i++) { $pl = ['instagram','facebook','threads'][$i % 3]; $au = $authors[($i * 11) % count($authors)]; $t = $topics[($i * 3) % count($topics)];
        $u = $pl === 'instagram' ? ($i % 2 ? "https://www.instagram.com/reel/GR" . (100 + $i) : "https://www.instagram.com/p/GP" . (100 + $i)) : ($pl === 'facebook' ? 'https://www.facebook.com/watch/?v=' . (770000 + $i) : "https://www.threads.net/@$au/post/GT" . (100 + $i));
        $gen[] = [$pl, 'post', ($i % 2 ? 'Reel: ' : 'Photo: ') . ucfirst($t) . ' · ' . $quals[$i % count($quals)], ucfirst($t) . ' — worth a save', $au, $u, null, null, mt_rand(0, 29) * $d + mt_rand(0, 20) * $h, mt_rand(1, 5), 'viewed']; }
    $rows = array_merge($rows, $gen);
    $n = 0; $seed = 0;
    foreach ($rows as $r) {
        [$platform, $kind, $title, $body, $author, $url, $mt, $ma, $ago, $views, $inter] = $r; $seed++;
        $key = normalize_url($url);
        $s = $db->prepare('SELECT 1 FROM items WHERE user_id=? AND url_key=?'); $s->execute([$uid, $key]); if ($s->fetch()) continue;
        $detected = !$mt ? parse_music($body) : null;
        $db->prepare('INSERT INTO items (user_id, platform, kind, source, url, url_key, title, body, author, music_title, music_artist, music_source, interaction, interacted_at, first_seen_at, view_count) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
           ->execute([$uid, $platform, $kind, 'demo', $url, $key, $title, $body, $author, $mt ?: ($detected[0] ?? null), $ma ?: ($detected[1] ?? null), ($mt || $detected) ? ($mt ? 'user' : 'detected') : null, $inter, $now - $ago, $now - $ago, $views]);
        $id = (int)$db->lastInsertId();
        $db->prepare('UPDATE items SET thumb_path=? WHERE id=?')->execute([demo_thumb($uid, $id, $seed), $id]); $n++;
    }
    return $n;
}
