<?php
/**
 * Per-user "will I watch this again?" model.
 *
 * - Pure-PHP logistic regression, trained on this user's own history only.
 * - Label: an item was rewatched (opened 2+ times) by the time its retention window ended.
 * - Features (no label leakage): platform, content type, hour of day, weekend, time spent.
 * - Items older than the retention window that were NOT rewatched are deleted, unless the
 *   model says they are likely to be rewatched (probability >= threshold) – those get one more window.
 * - Until MIN_SAMPLES examples exist, plain rule-based cleanup is used.
 */
const ML_MIN_SAMPLES = 20;

function ml_vector(string $platform, string $type, int $ts, int $dwell): array {
    $v = [];
    foreach (platform_ids() as $p) $v[] = $p === $platform ? 1.0 : 0.0;
    foreach (CONTENT_TYPES as $t)  $v[] = $t === $type ? 1.0 : 0.0;
    $ang = 2 * M_PI * ((int)date('G', $ts)) / 24;
    $v[] = sin($ang);
    $v[] = cos($ang);
    $v[] = min(1.0, log(1 + max(0, $dwell)) / 7.0);
    $v[] = ((int)date('N', $ts) >= 6) ? 1.0 : 0.0;
    $v[] = 1.0; // bias
    return $v;
}

function ml_sigmoid(float $z): float { return 1.0 / (1.0 + exp(-max(-30.0, min(30.0, $z)))); }

function ml_predict(array $w, array $x): float {
    $z = 0.0;
    foreach ($x as $i => $xi) $z += ($w[$i] ?? 0.0) * $xi;
    return ml_sigmoid($z);
}

/** Class-balanced batch gradient descent with L2 regularisation. */
function ml_train(array $X, array $y): array {
    $n = count($X);
    $d = count($X[0]);
    $pos = array_sum($y);
    $neg = $n - $pos;
    $wp = $pos ? $n / (2 * $pos) : 1.0;
    $wn = $neg ? $n / (2 * $neg) : 1.0;
    $w = array_fill(0, $d, 0.0);
    $l2 = 0.01;
    for ($it = 0; $it < 400; $it++) {
        $g = array_fill(0, $d, 0.0);
        for ($i = 0; $i < $n; $i++) {
            $err = (ml_predict($w, $X[$i]) - $y[$i]) * ($y[$i] ? $wp : $wn);
            for ($j = 0; $j < $d; $j++) $g[$j] += $err * $X[$i][$j];
        }
        $lr = 0.8 / (1 + $it * 0.01);
        for ($j = 0; $j < $d; $j++) {
            $reg = ($j === $d - 1) ? 0 : $l2 * $w[$j]; // don't regularise bias
            $w[$j] -= $lr * ($g[$j] / $n + $reg);
        }
    }
    $correct = 0;
    for ($i = 0; $i < $n; $i++) $correct += ((ml_predict($w, $X[$i]) >= 0.5 ? 1 : 0) === (int)$y[$i]) ? 1 : 0;
    return ['weights' => $w, 'accuracy' => $correct / $n];
}

function user_settings(int $uid): array {
    $db = db();
    $s = $db->prepare('SELECT * FROM user_settings WHERE user_id=?');
    $s->execute([$uid]);
    $r = $s->fetch();
    if (!$r) {
        $b = retention_bounds();
        $db->prepare('INSERT INTO user_settings (user_id, retention_hours) VALUES (?,?)')->execute([$uid, $b['default']]);
        return user_settings($uid);
    }
    $b = retention_bounds();
    $r['retention_hours'] = max($b['min'], min($b['max'], (int)$r['retention_hours']));
    return $r;
}

function ml_model(int $uid): ?array {
    $s = db()->prepare('SELECT * FROM ml_models WHERE user_id=?');
    $s->execute([$uid]);
    $m = $s->fetch();
    if (!$m) return null;
    $m['weights'] = json_decode($m['weights'], true);
    return $m;
}

/** Runs one cleanup pass for a user. Returns a summary. */
function cleanup_user(int $uid, bool $force = false): array {
    $db = db();
    $st = user_settings($uid);
    $now = time();
    if (!$force && !$st['auto_clean']) return ['deleted' => 0, 'kept_by_ml' => 0, 'mode' => 'off'];
    $cutoff = $now - $st['retention_hours'] * 3600;

    // 1) Record outcomes of matured items as training samples (once each).
    $mature = $db->prepare('SELECT * FROM interactions WHERE user_id=? AND sampled=0 AND last_opened_at<=?');
    $mature->execute([$uid, $cutoff]);
    $db->beginTransaction();
    $ins = $db->prepare('INSERT INTO training_samples (user_id,features,label,created_at) VALUES (?,?,?,?)');
    $mark = $db->prepare('UPDATE interactions SET sampled=1 WHERE id=?');
    foreach ($mature->fetchAll() as $r) {
        $vec = ml_vector($r['platform'], $r['content_type'], (int)$r['first_opened_at'], (int)$r['dwell_seconds']);
        $ins->execute([$uid, json_encode($vec), ((int)$r['open_count'] > 1 || $r['pinned']) ? 1 : 0, $now]);
        $mark->execute([$r['id']]);
    }
    $db->commit();

    // 2) (Re)train when there is enough data and both outcomes have been seen.
    $model = null;
    if ($st['ml_enabled']) {
        $rows = $db->prepare('SELECT features,label FROM training_samples WHERE user_id=? ORDER BY id DESC LIMIT 5000');
        $rows->execute([$uid]);
        $X = []; $y = [];
        foreach ($rows->fetchAll() as $r) { $X[] = json_decode($r['features'], true); $y[] = (int)$r['label']; }
        if (count($X) >= ML_MIN_SAMPLES && array_sum($y) > 0 && array_sum($y) < count($y)) {
            $t = ml_train($X, $y);
            $db->prepare('INSERT OR REPLACE INTO ml_models (user_id,weights,samples,accuracy,trained_at) VALUES (?,?,?,?,?)')
               ->execute([$uid, json_encode($t['weights']), count($X), $t['accuracy'], $now]);
            $model = ['weights' => $t['weights']];
        }
        if (!$model) $model = ml_model($uid);
    }

    // 3) Candidates: not rewatched, not pinned, past the window, not recently given a reprieve.
    $c = $db->prepare('SELECT * FROM interactions WHERE user_id=? AND pinned=0 AND open_count<=1
                       AND last_opened_at<=? AND reviewed_at<=?');
    $c->execute([$uid, $cutoff, $cutoff]);
    $del = $db->prepare('DELETE FROM interactions WHERE id=?');
    $rev = $db->prepare('UPDATE interactions SET reviewed_at=? WHERE id=?');
    $deleted = 0; $kept = 0;
    $db->beginTransaction();
    foreach ($c->fetchAll() as $r) {
        if ($model) {
            $p = ml_predict($model['weights'], ml_vector($r['platform'], $r['content_type'], (int)$r['first_opened_at'], (int)$r['dwell_seconds']));
            if ($p >= (float)$st['ml_threshold']) { $rev->execute([$now, $r['id']]); $kept++; continue; }
        }
        $del->execute([$r['id']]);
        $deleted++;
    }
    $db->commit();

    $mode = $model ? 'ml' : 'rules';
    $db->prepare('UPDATE user_settings SET last_cleanup_at=? WHERE user_id=?')->execute([$now, $uid]);
    if ($deleted || $kept) {
        $db->prepare('INSERT INTO cleanup_log (user_id,run_at,deleted,kept_by_ml,mode) VALUES (?,?,?,?,?)')
           ->execute([$uid, $now, $deleted, $kept, $mode]);
    }
    return ['deleted' => $deleted, 'kept_by_ml' => $kept, 'mode' => $mode];
}
