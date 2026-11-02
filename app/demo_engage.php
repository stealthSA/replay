<?php
declare(strict_types=1);
/** Sample data for Trending / My circle / Bid ads (DEV_LOGIN on localhost, or DEMO_DATA on a live server). Fictional creators, generated pictures, no real content. */
require_once __DIR__ . '/ads.php';
require_once __DIR__ . '/demo.php';

function load_demo_engagement(int $me): int {
    $db = db(); $now = time(); $H = HOUR; $added = 0;
    $mk = function (string $key, string $name, bool $pool = true, bool $ads = true) use ($db): int {
        $email = "demo.$key@example.invalid"; $s = $db->prepare('SELECT id FROM users WHERE email=?'); $s->execute([$email]); $id = (int)$s->fetchColumn();
        if (!$id) { $db->prepare('INSERT INTO users (email, name, created_at) VALUES (?,?,?)')->execute([$email, $name, time()]); $id = (int)$db->lastInsertId(); }
        save_prefs($id, ['share_pool' => $pool, 'share_circle' => true, 'open_to_ads' => $ads]); return $id;
    };
    $u = ['me' => $me, 'mia' => $mk('mia', 'Mia Moves'), 'thandi' => $mk('thandi', 'Thandi Cooks'), 'sneak' => $mk('sneak', 'Sneakerhead ZA'), 'kasi' => $mk('kasi', 'Kasi Kicks'), 'carmeet' => $mk('carmeet', 'Cape Car Meet'),
        'gear' => $mk('gear', 'Gearhead Gauteng', true, false), 'zee' => $mk('zee', 'Zee Events'), 'tech' => $mk('tech', 'Tech Sisters'), 'pixel' => $mk('pixel', 'Pixel Pete'), 'fit' => $mk('fit', 'Fit with Lindiwe'), 'wander' => $mk('wander', 'Wander SA')];
    save_prefs($me, ['share_pool' => true, 'share_circle' => true, 'open_to_ads' => true]);
    foreach (['mia', 'thandi', 'sneak'] as $k) $db->prepare("INSERT OR REPLACE INTO circle (user_id, member_id, status, created_at) VALUES (?,?,'accepted',?)")->execute([$me, $u[$k], $now - 9 * DAY]);
    $db->prepare("INSERT OR IGNORE INTO circle (user_id, member_id, status, created_at) VALUES (?,?,'pending',?)")->execute([$u['zee'], $me, $now - 2 * $H]);

    // [owner, platform, kind, author, text, ageHours, metrics, curve]   curve: flat | surge
    $rows = [
        // sneakers: several creators, all rising
        ['sneak', 'instagram', 'video', 'sneakerhead.za', 'Unboxing the new Nike Air Max colourway 🔥 Wait for the reveal. #sneakerhead #nike', 9, ['views' => 182000, 'watch_seconds' => 540000, 'shares' => 4200, 'downloads' => 1900], 'surge'],
        ['kasi', 'instagram', 'image', 'kasi.kicks', 'Nike sneakers haul from Sandton. Which pair would you cop? #sneakers #kicks', 14, ['likes' => 9200, 'comments' => 640, 'saves' => 1500], 'surge'],
        ['mia', 'instagram', 'video', 'mia.moves', 'Sunrise 10k in my new Nikes. Run club was full today #running #nike', 6, ['views' => 64000, 'watch_seconds' => 190000, 'shares' => 800, 'downloads' => 300], 'surge'],
        ['zee', 'facebook', 'image', 'Zee Events', 'Adidas vs Nike: settle it in the comments #sneakerhead', 20, ['likes' => 3100, 'comments' => 1100], 'surge'],
        ['pixel', 'threads', 'text', 'pixel.pete', 'Hot take: nobody needs more than 3 pairs of sneakers. Fight me. #sneakers', 11, ['likes' => 5400, 'comments' => 2300, 'shares' => 700], 'surge'],
        // exhausts: rising too
        ['carmeet', 'instagram', 'video', 'cape.carmeet', 'RCP exhaust on a Golf GTI, that pop on the overrun 😮‍💨 #carmeet #exhaust', 7, ['views' => 240000, 'watch_seconds' => 800000, 'shares' => 6100, 'downloads' => 2400], 'surge'],
        ['gear', 'facebook', 'video', 'Gearhead Gauteng', 'RCP exhaust vs stock: full dyno run, 14 kW difference', 11, ['views' => 98000, 'watch_seconds' => 360000, 'shares' => 2200], 'surge'],
        ['pixel', 'threads', 'text', 'pixel.pete', 'Is an RCP exhaust worth it on a daily driver? #carmeet', 8, ['likes' => 3900, 'comments' => 1800, 'shares' => 410], 'surge'],
        ['carmeet', 'instagram', 'image', 'cape.carmeet', 'Track day pictures. Turbo, rims, and a loud exhaust', 30, ['likes' => 2100, 'comments' => 90, 'saves' => 150], 'flat'],
        // circle breakout: Thandi's pasta video is far above her usual
        ['thandi', 'instagram', 'video', 'thandi.cooks', '30-second braai pasta with peri-peri butter 🍝 #recipe #braai', 5, ['views' => 150000, 'watch_seconds' => 420000, 'shares' => 3800, 'downloads' => 2900], 'surge'],
        ['thandi', 'instagram', 'image', 'thandi.cooks', 'Sunday market haul, fresh peaches', 60, ['likes' => 1800, 'comments' => 75, 'saves' => 90], 'flat'],
        ['fit', 'instagram', 'video', 'fit.lindiwe', '20-minute home workout, no equipment #fitness #gym', 40, ['views' => 41000, 'watch_seconds' => 120000, 'shares' => 500, 'downloads' => 700], 'flat'],
        ['fit', 'threads', 'text', 'fit.lindiwe', 'What is your protein breakfast? Mine: eggs and oats #fitness', 26, ['likes' => 900, 'comments' => 480, 'shares' => 40], 'flat'],
        ['zee', 'instagram', 'video', 'zee.events', 'Live set from Saturday night, amapiano all the way 🎶 #amapiano #festival', 16, ['views' => 88000, 'watch_seconds' => 310000, 'shares' => 1700, 'downloads' => 640], 'flat'],
        ['tech', 'facebook', 'image', 'Tech Sisters', 'Samsung Galaxy review: three weeks later', 52, ['likes' => 2300, 'comments' => 410, 'downloads' => 120], 'flat'],
        ['tech', 'threads', 'text', 'tech.sisters', 'Which AI tools do you actually use every day? #ai #tech', 18, ['likes' => 2600, 'comments' => 1500, 'shares' => 260], 'flat'],
        ['wander', 'instagram', 'image', 'wander.sa', 'Chapman’s Peak at golden hour. Road trip day 2 #travel', 33, ['likes' => 6100, 'comments' => 230, 'saves' => 880], 'flat'],
        ['wander', 'instagram', 'video', 'wander.sa', 'Safari sunrise, hotel tour and the best coffee #travel', 22, ['views' => 52000, 'watch_seconds' => 150000, 'shares' => 900, 'downloads' => 220], 'flat'],
        ['pixel', 'facebook', 'text', 'Pixel Pete', 'Neighbourhood braai on Saturday, who is coming?', 70, ['likes' => 300, 'comments' => 190, 'shares' => 12], 'flat'],
        // you (dev user)
        ['me', 'instagram', 'video', 'you', 'Behind the scenes with my sneaker collection. Nike, Puma and a rare pair 👟 #sneakers #nike', 12, ['views' => 76000, 'watch_seconds' => 230000, 'shares' => 1500, 'downloads' => 520], 'surge'],
        ['me', 'threads', 'text', 'you', 'Hot take: the best apps do one thing well. What is yours?', 20, ['likes' => 700, 'comments' => 330, 'shares' => 60], 'flat'],
        ['me', 'instagram', 'image', 'you', 'Desk setup update: one monitor, one plant', 48, ['likes' => 540, 'comments' => 38, 'saves' => 25], 'flat'],
    ];

    // ---- bulk generated posts: many creators, topics and brands so Trending, topic surges and circle have plenty ----
    mt_srand(777);
    $owners = ['mia', 'thandi', 'sneak', 'kasi', 'carmeet', 'gear', 'zee', 'tech', 'pixel', 'fit', 'wander'];
    $handle = ['mia' => 'mia.moves', 'thandi' => 'thandi.cooks', 'sneak' => 'sneakerhead.za', 'kasi' => 'kasi.kicks', 'carmeet' => 'cape.carmeet', 'gear' => 'Gearhead Gauteng', 'zee' => 'zee.events', 'tech' => 'tech.sisters', 'pixel' => 'pixel.pete', 'fit' => 'fit.lindiwe', 'wander' => 'wander.sa'];
    $topicsG = [
        ['sneakers', 'Nike Air Max drop is everywhere today. Which colourway wins? #sneakers #nike'], ['sneakers', 'Adidas Samba restock, queue was wild #sneakerhead #adidas'], ['sneakers', 'Puma vs Nike: my honest wear test #sneakers'],
        ['exhaust', 'RCP exhaust cold start, that crackle 🔥 #exhaust #carmeet'], ['exhaust', 'Stock vs aftermarket exhaust sound test #exhaust'], ['exhaust', 'Dyno day: exhaust and tune results #carmeet'],
        ['food', 'Braai masterclass: the peri-peri chicken everyone asks about #recipe #braai'], ['food', 'Street food tour in Braamfontein, 10 stalls ranked #food'], ['food', 'Five-minute pasta that tastes like a restaurant #recipe'], ['food', 'Bunny chow showdown in Durban #food #recipe'],
        ['fitness', '20-minute home workout, no equipment #fitness #gym'], ['fitness', 'Run club 10k sunrise #running #fitness'], ['fitness', 'Gym progress: 12 weeks #gym #fitness'],
        ['travel', 'Cape Town road trip, Chapman’s Peak golden hour #travel'], ['travel', 'Kruger safari sunrise, lions at the waterhole #travel #safari'], ['travel', 'Drakensberg weekend hike #travel #hiking'],
        ['music', 'Amapiano live set, the crowd lost it 🎶 #amapiano #festival'], ['music', 'Studio session: new single teaser #afrobeats #music'],
        ['tech', 'Samsung Galaxy vs iPhone camera test #tech #samsung'], ['tech', 'Which AI tools do you actually use daily? #ai #tech'], ['tech', 'Laptop under R15k: worth it? #tech'],
        ['fashion', 'Thrift haul: winter fits under R300 #fashion #style'], ['fashion', 'Streetwear picks from Sandton #fashion #sneakers'],
        ['gaming', 'PS5 vs PC for the same game, side by side #gaming'], ['gaming', 'Speedrun attempt, 2 minutes off the record #gaming'],
        ['money', 'Side hustles that actually paid off this year #money #business'], ['money', 'How I budget on a student income #money'],
    ];
    $platG = ['instagram', 'facebook', 'threads']; $kindG = ['video', 'image', 'text'];
    for ($g = 0; $g < 170; $g++) {
        $tp = $topicsG[$g % count($topicsG)]; $own = $owners[($g * 7 + intdiv($g, 5)) % count($owners)]; $kind = $kindG[$g % 3]; $plat = $kind === 'text' ? 'threads' : ($kind === 'video' ? 'instagram' : $platG[$g % 2]);
        $mag = mt_rand(1, 100) / 100; $big = $mag > 0.85 ? 8 : ($mag > 0.5 ? 3 : 1);
        $mt = match ($kind) {
            'video' => ['views' => 9000 * $big + mt_rand(0, 9000), 'watch_seconds' => 25000 * $big + mt_rand(0, 20000), 'shares' => 120 * $big + mt_rand(0, 200), 'downloads' => 60 * $big + mt_rand(0, 150)],
            'image' => ['likes' => 700 * $big + mt_rand(0, 900), 'comments' => 40 * $big + mt_rand(0, 80), 'saves' => 50 * $big + mt_rand(0, 120)],
            default => ['likes' => 300 * $big + mt_rand(0, 500), 'comments' => 90 * $big + mt_rand(0, 300), 'shares' => 15 * $big + mt_rand(0, 60)],
        };
        $rows[] = [$own, $plat, $kind, $handle[$own], $tp[1] . ($g >= count($topicsG) ? ' (#' . intdiv($g, count($topicsG)) . ')' : ''), mt_rand(2, 150), $mt, mt_rand(1, 100) <= 30 ? 'surge' : 'flat'];
    }
    // older history (baseline for the breakout model)
    foreach (['mia' => 'Morning stretch routine', 'thandi' => 'Quick lunch ideas', 'sneak' => 'Sneaker cleaning tips', 'me' => 'Weekend notes'] as $k => $t)
        foreach ([5, 8, 11, 14] as $i => $d) $rows[] = [$k, 'instagram', 'image', $k, "$t #$i", $d * 24 + 3, ['likes' => 180 + 40 * $i, 'comments' => 12 + $i * 3, 'saves' => 8 + $i], 'flat'];

    $steps = ['flat' => [0.50, 0.78, 0.92, 0.98, 1.0], 'surge' => [0.04, 0.10, 0.28, 0.62, 1.0]]; $at = [-26 * $H, -16 * $H, -8 * $H, -3 * $H, 0]; $seed = 40;
    foreach ($rows as $i => [$owner, $plat, $kind, $author, $text, $age, $m, $curve]) {
        $posted = $now - $age * $H; $ext = 'demo-' . $owner . '-' . $i; $id = null;
        foreach ($at as $k => $off) {
            $t = $now + $off; if ($t < $posted + 600) continue;
            $f = $steps[$curve][$k]; $mm = array_map(fn($v) => (int)round($v * $f), $m);
            [$id, $new] = upsert_content(['owner_user_id' => $u[$owner], 'platform' => $plat, 'external_id' => $ext, 'url' => "https://www.$plat.com/demo/$ext", 'kind' => $kind, 'author' => $author, 'title' => mb_substr($text, 0, 120), 'body' => $text,
                'posted_at' => $posted, 'source' => 'demo', 'm' => $mm, 'now' => $t]);
            if ($new) $added++;
        }
        if ($id === null) { [$id, $new] = upsert_content(['owner_user_id' => $u[$owner], 'platform' => $plat, 'external_id' => $ext, 'url' => "https://www.$plat.com/demo/$ext", 'kind' => $kind, 'author' => $author, 'title' => mb_substr($text, 0, 120), 'body' => $text, 'posted_at' => $posted, 'source' => 'demo', 'm' => $m, 'now' => $now]); if ($new) $added++; }
        $r = $db->prepare('SELECT thumb_path FROM content WHERE id=?'); $r->execute([$id]);
        if (!$r->fetchColumn()) $db->prepare('UPDATE content SET thumb_path=? WHERE id=?')->execute([demo_thumb('c', (int)$id, ++$seed), $id]);
    }
    // a demo advertiser with an offer on one of your posts (so "Offers for me" has something to answer)
    $adv = $mk('soleco', 'Sole Society', false, false);
    adv_save($adv, ['brand' => 'Sole Society', 'website' => 'https://example.com', 'keywords' => 'sneakers, kicks, nike', 'categories' => ['sneakers'], 'min_pct' => 70]);
    $c = $db->prepare("SELECT id FROM content WHERE owner_user_id=? AND external_id LIKE 'demo-me-%' AND title LIKE '%sneaker collection%'"); $c->execute([$me]); $cid = (int)$c->fetchColumn();
    if ($cid) bid_place($adv, ['content_id' => $cid, 'amount' => 450, 'days' => 7, 'message' => 'Sole Society: free shipping on rare kicks this week', 'link' => 'https://example.com/shop']);
    trend_run();
    return $added;
}
