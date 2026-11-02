<?php
// PRODUCTION config. Fill in the values marked CHANGE, then keep this file private (it is blocked from the web by .htaccess).
return [
    'APP_NAME'      => 'Current Recap',
    'BASE_URL'      => 'https://www.bizpages.online',   // address of the folder holding index.html: https, no trailing slash, e.g. https://example.com/recap
    'CONTACT_EMAIL' => 'CHANGE@yourdomain.com',
    'STORAGE_DIR'   => __DIR__ . '/storage',               // must be writable by PHP; ideally move it outside the web root and put the full path here

    // Meta app: developers.facebook.com → your app → App settings → Basic
    'META_APP_ID'     => '2014918775837409',
    'META_APP_SECRET' => '43ce2a85e4aba27837c191cb58fbf83f',
    'INSTAGRAM_APP_ID' => '1126168846496343', 'INSTAGRAM_APP_SECRET' => '07141f922cab0f4e585fd1191875f45b',   // only if you added "Instagram API with Instagram Login"
    'THREADS_APP_ID'   => '1639316121016494', 'THREADS_APP_SECRET'   => '0c0457eff459c5fdd9c057662d378e7f',   // only if you added the Threads API

    // Secret for refresh.php over the web (so a free cron service can load F1, MotoGP, sport, music, entertainment, tech and fashion content). Use a long random string.
    'CRON_KEY' => 'q7Rk2xVn9TbLw4ZcA8mHs3PdY6eFj1Ug',

    'DEV_LOGIN' => false,            // never true on a live server
    'DEMO_DATA' => false,            // true = signed-in users get a "Load sample data" button (fictional + real Mastodon/Bluesky posts)
    'ENGAGEMENT_INSIGHTS' => false,  // true only after Meta approves the insights permissions

    // Live content (refresh.php). All optional; these are the values in use.
    'TOPIC_MEDIA' => true,                 // F1, MotoGP, sport, music, entertainment, fashion, tech, cars, gaming, travel videos + pictures
    'TOPIC_MEDIA_TOPICS' => '',            // limit topics, e.g. 'motorsport,sport,music,entertainment,tech,fashion' ('' = all)
    'TOPIC_MEDIA_PER_CHANNEL' => 4,        // videos taken from each channel (1-8)
    'TOPIC_MEDIA_EXTRA' => [],             // your own channels, e.g. ['motorsport' => ['@SomeChannel'], 'music' => ['@AnotherChannel']]
    'CLEAR_IMPORTED_ONCE' => true,         // the NEXT refresh.php run removes old imported posts outside your topics, ONCE; later runs ignore this line
    'TOPIC_PRIORITY' => 'motorsport,music,amapiano,hiphop,sport,entertainment,tech,fashion',   // shown first in Trending: F1/MotoGP, music, South African amapiano, international hip hop, sport, entertainment, tech, fashion
    'REFRESH_BUTTON' => true,              // "Get latest content" button on Trending (signed-in users; one fetch per 5 minutes for the whole site)
    'OPEN_TRENDS_TOPICS_ONLY' => true,     // general Mastodon/Bluesky posts are kept only when they are about those topics (no random chatter)
    'OPEN_TRENDS' => true,                 // trending posts from Mastodon and Bluesky
    'OPEN_TRENDS_MASTODON' => 'mastodon.social,mastodon.online,fosstodon.org,mstdn.social',
    'LIVE_MEDIA_COUNTRY' => 'za',          // Apple chart country (2 letters)
    'META_AI_KEY' => 'LLM_2254301295120449_hCTt1aEvGn7BcdXpaVfJSGPDJgw',
];
