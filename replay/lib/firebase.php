<?php
/**
 * Verifies a Firebase Authentication ID token (RS256 JWT) without any Google PHP SDK.
 * Follows Firebase's documented checks: signature (against Google's rotating public certs), alg, kid,
 * exp/iat/auth_time, aud == project id, iss == https://securetoken.google.com/<project id>, non-empty sub.
 */
const FIREBASE_CERT_URL = 'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com';

function b64url_decode(string $s): string|false {
    return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
}

/** Returns [kid => PEM cert], cached on disk for as long as Google's Cache-Control max-age says. */
function firebase_certs(bool $forceRefresh = false): array {
    $file = DATA_DIR . '/firebase_certs.json';
    if (!$forceRefresh && is_file($file)) {
        $c = json_decode((string)file_get_contents($file), true);
        if (is_array($c) && ($c['expires'] ?? 0) > time() && !empty($c['certs'])) return $c['certs'];
    }
    $ch = curl_init(FIREBASE_CERT_URL);
    $maxAge = 3600;
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$maxAge) {
            if (preg_match('/max-age=(\d+)/i', $line, $m)) $maxAge = (int)$m[1];
            return strlen($line);
        }]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $certs = ($res !== false && $code === 200) ? json_decode($res, true) : null;
    if (!is_array($certs) || !$certs) {
        // fall back to a stale cache rather than locking everyone out
        if (is_file($file)) { $c = json_decode((string)file_get_contents($file), true); if (!empty($c['certs'])) return $c['certs']; }
        return [];
    }
    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0775, true);
    file_put_contents($file, json_encode(['expires' => time() + $maxAge, 'certs' => $certs]), LOCK_EX);
    return $certs;
}

/** @return array|null verified token claims, or null if anything is wrong */
function verify_firebase_token(string $jwt): ?array {
    if (FIREBASE_PROJECT_ID === '') return null;
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) return null;
    [$h64, $p64, $s64] = $parts;
    $header = json_decode((string)b64url_decode($h64), true);
    $claims = json_decode((string)b64url_decode($p64), true);
    $sig = b64url_decode($s64);
    if (!is_array($header) || !is_array($claims) || $sig === false) return null;
    if (($header['alg'] ?? '') !== 'RS256' || empty($header['kid'])) return null;

    $certs = firebase_certs();
    if (!isset($certs[$header['kid']])) $certs = firebase_certs(true);   // key may have just rotated
    if (!isset($certs[$header['kid']])) return null;
    $pub = openssl_pkey_get_public($certs[$header['kid']]);
    if (!$pub || openssl_verify("$h64.$p64", $sig, $pub, OPENSSL_ALGO_SHA256) !== 1) return null;

    $now = time();
    $skew = 60;
    if (($claims['exp'] ?? 0) < $now - $skew) return null;
    if (($claims['iat'] ?? PHP_INT_MAX) > $now + $skew) return null;
    if (($claims['auth_time'] ?? PHP_INT_MAX) > $now + $skew) return null;
    if (($claims['aud'] ?? '') !== FIREBASE_PROJECT_ID) return null;
    if (($claims['iss'] ?? '') !== 'https://securetoken.google.com/' . FIREBASE_PROJECT_ID) return null;
    if (!is_string($claims['sub'] ?? null) || $claims['sub'] === '') return null;
    if (empty($claims['email']) || empty($claims['email_verified'])) return null;
    if (($claims['firebase']['sign_in_provider'] ?? '') !== 'google.com') return null;
    return $claims;
}
