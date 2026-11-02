<?php
const CONTENT_TYPES = ['video', 'reel', 'post', 'story', 'chat', 'other'];

function platforms(): array {
    return [
        ['id' => 'facebook',   'name' => 'Facebook',   'color' => '#1877F2', 'url' => 'https://www.facebook.com/',
         'hosts' => ['facebook.com', 'fb.com', 'fb.watch', 'fb.me']],
        ['id' => 'instagram',  'name' => 'Instagram',  'color' => '#E1306C', 'url' => 'https://www.instagram.com/',
         'hosts' => ['instagram.com', 'instagr.am']],
        ['id' => 'messenger',  'name' => 'Messenger',  'color' => '#0A7CFF', 'url' => 'https://www.messenger.com/',
         'hosts' => ['messenger.com', 'm.me']],
        ['id' => 'whatsapp',   'name' => 'WhatsApp',   'color' => '#25D366', 'url' => 'https://web.whatsapp.com/',
         'hosts' => ['whatsapp.com', 'wa.me', 'whatsapp.net']],
        ['id' => 'threads',    'name' => 'Threads',    'color' => '#222222', 'url' => 'https://www.threads.com/',
         'hosts' => ['threads.net', 'threads.com']],
        ['id' => 'meta_ai',    'name' => 'Meta AI',    'color' => '#6A5CFF', 'url' => 'https://www.meta.ai/',
         'hosts' => ['meta.ai']],
        ['id' => 'meta_quest', 'name' => 'Meta Quest', 'color' => '#0064E0', 'url' => 'https://www.meta.com/experiences/',
         'hosts' => ['meta.com', 'oculus.com']],
    ];
}

function platform_ids(): array { return array_column(platforms(), 'id'); }

function detect_platform(string $url): ?string {
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    $host = preg_replace('/^www\./', '', $host);
    if ($host === '') return null;
    foreach (platforms() as $p) {
        foreach ($p['hosts'] as $h) {
            if ($host === $h || str_ends_with($host, '.' . $h)) return $p['id'];
        }
    }
    return null;
}

function detect_type(string $url, string $platform): string {
    $u = strtolower($url);
    if (str_contains($u, '/reel')) return 'reel';
    if (str_contains($u, '/stories') || str_contains($u, '/story')) return 'story';
    if (preg_match('#(fb\.watch|/watch|/videos?/|/tv/)#', $u)) return 'video';
    if (preg_match('#(/p/|/posts?/|/permalink|/status/|/@[^/]+/post)#', $u)) return 'post';
    if (in_array($platform, ['messenger', 'whatsapp'], true)) return 'chat';
    return 'other';
}

function normalize_url(string $url): string {
    $parts = parse_url($url);
    if (!$parts || empty($parts['host'])) return $url;
    $q = [];
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $q);
        foreach (array_keys($q) as $k) {
            if (preg_match('/^(utm_|fbclid|igsh|igshid|mibextid|ref$|__)/i', $k)) unset($q[$k]);
        }
    }
    $hostN = preg_replace('/^(www|m|mobile|web)\./', '', strtolower($parts['host']));
    $out = 'https://' . $hostN;
    if (!empty($parts['port'])) $out .= ':' . $parts['port'];
    $out .= rtrim($parts['path'] ?? '/', '/') ?: '/';
    if ($q) $out .= '?' . http_build_query($q);
    return $out;
}
