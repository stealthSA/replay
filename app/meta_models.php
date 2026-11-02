<?php
declare(strict_types=1);
/**
 * The other Meta Model API models, all behind the same key (META_AI_KEY) and base URL (META_AI_BASE_URL, https://api.meta.ai/v1):
 *   Muse Image            POST /images/generations   ad picture mock-ups in the offer dialog
 *   Muse Voice Transcribe POST /asr/transcribe       words spoken in shared videos, used as extra context for tagging
 *   SAM 3.1               POST /responses            finds the products Muse Spark named inside a post picture (boxes drawn in the details view)
 * Every call is optional and fails soft: no key, no ffmpeg, or any API error just means that feature is skipped.
 * Request shapes follow dev.meta.ai/docs (image-generation, speech-to-text, media-segmentation). See META-MODELS.md.
 */

function ai_key(): string { return (string)cfg('META_AI_KEY'); }
function ai_cloud_base(): string { $b = rtrim((string)cfg('META_AI_BASE_URL'), '/'); return preg_match('#^https?://#i', $b) ? $b : ''; }

/** POST JSON to the Meta Model API. @return array{0:int,1:string} [http code, raw body] @throws RuntimeException */
function ai_post(string $path, array $body, int $timeout = 60, array $headers = []): array {
    $base = ai_cloud_base(); if ($base === '' || ai_key() === '') throw new RuntimeException('Meta Model API is not configured');
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => array_merge(['Authorization: Bearer ' . ai_key(), 'Content-Type: application/json'], $headers), CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $err = curl_error($ch); curl_close($ch);
    if ($raw === false) throw new RuntimeException('Meta Model API unreachable: ' . $err);
    return [$code, (string)$raw];
}
function ai_api_error(int $code, string $raw): string { $j = json_decode($raw, true); return 'Meta Model API error ' . $code . ': ' . mb_substr((string)($j['error']['message'] ?? $j['error'] ?? $raw), 0, 160); }

/* ============================================================= Muse Image */

/** Text to picture. @return string image bytes (JPEG). @throws RuntimeException */
function ai_image_generate(string $prompt, string $size = '1024x1024'): string {
    [$code, $raw] = ai_post('/images/generations', ['model' => (string)cfg('META_AI_IMAGE_MODEL'), 'prompt' => mb_substr($prompt, 0, 1500), 'n' => 1, 'size' => $size, 'response_format' => 'b64_json', 'output_format' => 'jpeg'], 90);
    $j = json_decode($raw, true); if ($code >= 400 || !is_array($j)) throw new RuntimeException(ai_api_error($code, $raw));
    $b = $j['data'][0]['b64_json'] ?? null; $bytes = is_string($b) ? base64_decode($b, true) : false;
    if (!$bytes && is_string($u = $j['data'][0]['url'] ?? null) && preg_match('#^https://#i', $u) && public_https_url_ok($u)) $bytes = ai_download($u, 8 * 1024 * 1024);
    if (!is_string($bytes) || strlen($bytes) < 200) throw new RuntimeException('Muse Image returned no picture');
    return $bytes;
}

/** An ad picture for this advertiser and post (one per pair; asking again makes a new one). @return array{url:string,path:string}|null */
function ai_ad_mockup(array $adv, int $contentId): ?array {
    if (ai_key() === '') return null; $db = db();
    $n = $db->prepare('SELECT COUNT(*) FROM ad_mockups WHERE advertiser_id=? AND at >= ?'); $n->execute([$adv['id'], time() - DAY]);
    if ((int)$n->fetchColumn() >= (int)cfg('META_AI_IMAGE_PER_DAY')) throw new RuntimeException('Daily limit for ad pictures reached. Try again tomorrow.');
    $s = $db->prepare('SELECT title, body, category FROM content WHERE id=?'); $s->execute([$contentId]); $c = $s->fetch(); if (!$c) return null;
    $about = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags(($c['title'] ?? '') . '. ' . ($c['body'] ?? '')))), 0, 300);
    $prompt = 'A clean, friendly sponsored-ad banner mock-up for the brand "' . mb_substr((string)$adv['brand'], 0, 60) . '", to sit beside a social post about: ' . $about . '. Bright, modern, product-focused, no real people\'s faces, no text or logos in the picture.';
    $bytes = ai_image_generate($prompt, '1024x1024');
    $img = @imagecreatefromstring($bytes); if (!$img) throw new RuntimeException('Muse Image returned an unusable picture');
    $dir = rtrim((string)cfg('STORAGE_DIR'), '/') . '/mockups'; if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $rel = (int)$adv['id'] . '_' . $contentId . '.jpg'; if (!imagejpeg($img, $dir . '/' . $rel, 85)) throw new RuntimeException('Could not save the picture');
    $db->prepare('INSERT INTO ad_mockups (advertiser_id, content_id, path, prompt, at) VALUES (?,?,?,?,?) ON CONFLICT(advertiser_id, content_id) DO UPDATE SET path=excluded.path, prompt=excluded.prompt, at=excluded.at')->execute([$adv['id'], $contentId, $rel, $prompt, time()]);
    return ['path' => $rel, 'url' => 'thumb.php?m=' . $contentId . '&v=' . time()];
}

/* ====================================================== SAM 3.1 (segmentation) */

/** Parses SAM's special-token text: <0f>0<|box;x1=..;y1=..;x2=..;y2=..;w=..;h=..|><|mask;..|>, ... @return list<array{0:float,1:float,2:float,3:float}> boxes in source pixels + [w,h] */
function sam_parse_boxes(string $text): array {
    $out = [];
    if (preg_match_all('/<\|box;([^|>]*)\|>/', $text, $all)) foreach ($all[1] as $kv) {
        $f = []; foreach (explode(';', $kv) as $p) { $x = explode('=', $p, 2); if (count($x) === 2) $f[trim($x[0])] = (float)$x[1]; }
        if (isset($f['x1'], $f['y1'], $f['x2'], $f['y2']) && $f['x2'] >= $f['x1'] && $f['y2'] >= $f['y1']) $out[] = ['x1' => $f['x1'], 'y1' => $f['y1'], 'x2' => $f['x2'], 'y2' => $f['y2'], 'w' => $f['w'] ?? 0.0, 'h' => $f['h'] ?? 0.0];
    }
    return $out;
}
/** Text of a Responses API reply, streamed (SSE) or not. */
function ai_responses_text(string $raw): string {
    $t = '';
    foreach (preg_split('/\r?\n/', $raw) as $line) {
        if (!str_starts_with($line, 'data:')) continue; $j = json_decode(trim(substr($line, 5)), true); if (!is_array($j)) continue;
        if (($j['type'] ?? '') === 'response.output_text.delta' && is_string($j['delta'] ?? null)) $t .= $j['delta'];
    }
    if ($t !== '') return $t;
    $j = json_decode($raw, true);
    if (is_array($j)) { if (is_string($j['output_text'] ?? null)) return $j['output_text']; foreach ($j['output'] ?? [] as $o) foreach ($o['content'] ?? [] as $p) if (is_string($p['text'] ?? null)) $t .= $p['text']; }
    return $t;
}

/** Boxes for one noun phrase in one picture. @return list<array{x1:float,y1:float,x2:float,y2:float}> as fractions 0..1 of the picture */
function ai_sam_find(string $imageDataUri, string $phrase, int $imgW, int $imgH): array {
    [$code, $raw] = ai_post('/responses', ['model' => (string)cfg('META_AI_SAM_MODEL'), 'stream' => true, 'input' => [['type' => 'message', 'role' => 'user', 'content' => [
        ['type' => 'input_text', 'text' => $phrase], ['type' => 'input_image', 'image_url' => $imageDataUri]]]]], 60, ['Accept: text/event-stream']);
    if ($code >= 400) throw new RuntimeException(ai_api_error($code, $raw));
    $boxes = []; foreach (sam_parse_boxes(ai_responses_text($raw)) as $b) {
        $W = $b['w'] > $b['x2'] ? $b['w'] : (float)$imgW; $H = $b['h'] > $b['y2'] ? $b['h'] : (float)$imgH; if ($W < 1 || $H < 1) continue;   // w/h = source size; fall back to the picture we sent
        $boxes[] = ['x1' => round(max(0, $b['x1']) / $W, 4), 'y1' => round(max(0, $b['y1']) / $H, 4), 'x2' => round(min($W, $b['x2'] + 1) / $W, 4), 'y2' => round(min($H, $b['y2'] + 1) / $H, 4)];
    }
    return array_slice($boxes, 0, 3);
}

/** Looks for each product phrase in the post picture. @return list<array{label:string,boxes:array}> only phrases that were found */
function ai_find_products(string $dataUri, array $products, string $thumbPath): array {
    $f = rtrim((string)cfg('STORAGE_DIR'), '/') . '/thumbs/' . ltrim($thumbPath, '/'); $sz = @getimagesize($f); $w = $sz[0] ?? 0; $h = $sz[1] ?? 0; $out = [];
    foreach (array_slice($products, 0, 2) as $p) {
        try { $b = ai_sam_find($dataUri, (string)$p, (int)$w, (int)$h); if ($b) $out[] = ['label' => mb_substr((string)$p, 0, 40), 'boxes' => $b]; }
        catch (Throwable $e) { error_log('[recap] SAM: ' . $e->getMessage()); break; }
    }
    return $out;
}

/* =========================================================== Muse Voice Transcribe */

/** Is this a public https address (not localhost / a private network)? Guards every server-side download of a link that came from outside. */
function public_https_url_ok(string $url): bool {
    $p = parse_url($url); if (!$p || ($p['scheme'] ?? '') !== 'https' || empty($p['host'])) return false;
    $h = $p['host']; $ips = filter_var($h, FILTER_VALIDATE_IP) ? [$h] : (@gethostbynamel($h) ?: []); if (!$ips) return false;
    foreach ($ips as $ip) if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
    return true;
}
function ai_download(string $url, int $maxBytes): ?string {
    $ch = curl_init($url); $got = '';
    curl_setopt_array($ch, [CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_USERAGENT => 'CurrentRecap/1.0', CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_WRITEFUNCTION => function ($ch, $d) use (&$got, $maxBytes) { $got .= $d; return strlen($got) > $maxBytes ? 0 : strlen($d); }]);
    $ok = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    return $ok !== false && $code === 200 && $got !== '' ? $got : null;
}
function ai_ffmpeg(): ?string {
    static $bin = false; if ($bin !== false) return $bin;
    $bin = null; $b = (string)cfg('META_AI_FFMPEG') ?: 'ffmpeg';
    if (function_exists('proc_open') && !preg_match('/[\s;|&<>$`]/', $b)) { $p = @proc_open([$b, '-version'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes); if (is_resource($p)) { stream_get_contents($pipes[1]); fclose($pipes[1]); fclose($pipes[2]); if (proc_close($p) === 0) $bin = $b; } }
    return $bin;
}
/** Video -> mono 16 kHz WAV of the first 90 seconds (the format Muse Voice Transcribe accepts). */
function ai_video_to_wav(string $videoPath, string $wavPath): bool {
    $bin = ai_ffmpeg(); if (!$bin) return false;
    $p = proc_open([$bin, '-nostdin', '-y', '-v', 'error', '-t', '90', '-i', $videoPath, '-vn', '-ac', '1', '-ar', '16000', '-c:a', 'pcm_s16le', $wavPath], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (!is_resource($p)) return false; $end = time() + 60;
    while (true) { $st = proc_get_status($p); if (!$st['running']) break; if (time() > $end) { proc_terminate($p, 9); proc_close($p); return false; } usleep(200000); }
    $code = $st['exitcode']; proc_close($p); return $code === 0 && is_file($wavPath) && filesize($wavPath) > 1000;
}
/** @throws RuntimeException */
function ai_transcribe_wav(string $wavPath, array $keywords = []): string {
    $base = ai_cloud_base(); if ($base === '' || ai_key() === '') throw new RuntimeException('Meta Model API is not configured');
    $req = ['mode' => 'PUSH_TO_TALK', 'model' => (string)cfg('META_AI_VOICE_MODEL'), 'audioEncoding' => 'WAV']; if ($keywords) $req['keywords'] = array_values(array_slice($keywords, 0, 50));
    $ch = curl_init($base . '/asr/transcribe');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . ai_key(), 'Accept: application/json'],
        CURLOPT_POSTFIELDS => ['request' => new CURLStringFile(json_encode($req), 'request.json', 'application/json'), 'audio' => new CURLFile($wavPath, 'audio/wav', 'audio.wav')]]);
    $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $err = curl_error($ch); curl_close($ch);
    if ($raw === false) throw new RuntimeException('Muse Voice Transcribe unreachable: ' . $err);
    $j = json_decode((string)$raw, true); if ($code >= 400 || !is_array($j)) throw new RuntimeException(ai_api_error($code, (string)$raw));
    return mb_substr(trim((string)($j['transcript'] ?? '')), 0, 4000);
}
/** Spoken words of a post's video, or null (no key, no ffmpeg, no speech, any error). Brand names from the lexicon are passed as keywords so they are spelled right. */
function ai_video_transcript(array $c): ?string {
    if (!cfg('META_AI_VOICE') || ai_key() === '' || !ai_ffmpeg() || empty($c['media_url']) || !public_https_url_ok((string)$c['media_url'])) return null;
    $tmp = sys_get_temp_dir() . '/recap_v_' . bin2hex(random_bytes(6)); $vid = $tmp . '.src'; $wav = $tmp . '.wav';
    try {
        $data = ai_download((string)$c['media_url'], 25 * 1024 * 1024); if (!$data) return null; file_put_contents($vid, $data); unset($data);
        if (!ai_video_to_wav($vid, $wav)) return null;
        $t = ai_transcribe_wav($wav, array_slice(array_keys(lexicon()['brands']), 0, 50)); return $t !== '' ? $t : null;
    } catch (Throwable $e) { error_log('[recap] voice: ' . $e->getMessage()); return null; }
    finally { @unlink($vid); @unlink($wav); }
}

/** For health.php and META-MODELS.md: which Meta models this install is set up to use. */
function ai_models_status(): array {
    $key = ai_key() !== ''; $local = (string)cfg('META_AI_LOCAL_URL') !== '';
    return [
        'muse_spark'      => ['model' => cfg('META_AI_TEXT_MODEL'), 'active' => $key, 'use' => 'tags shared posts, writes ad lines'],
        'muse_wonder'     => ['model' => cfg('META_AI_BRIEF_MODEL'), 'active' => $key, 'use' => 'advertiser trend briefing'],
        'muse_image'      => ['model' => cfg('META_AI_IMAGE_MODEL'), 'active' => $key, 'use' => 'ad picture mock-ups'],
        'muse_voice'      => ['model' => cfg('META_AI_VOICE_MODEL'), 'active' => $key && (bool)cfg('META_AI_VOICE') && ai_ffmpeg() !== null, 'use' => 'transcribes shared videos (needs ffmpeg)'],
        'sam_3_1'         => ['model' => cfg('META_AI_SAM_MODEL'), 'active' => $key && (bool)cfg('META_AI_SAM'), 'use' => 'finds products in post pictures'],
        'open_weights'    => ['model' => (string)cfg('META_AI_LOCAL_MODEL'), 'active' => $local, 'use' => 'Muse Glimmer / Muse Spark Open on your own server for tagging'],
    ];
}
