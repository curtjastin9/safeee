<?php
declare(strict_types=1);

/**
 * Face recognition helpers. Embeddings (128 floats) come from face-api.js in
 * the browser; this file stores and COMPARES them. All decisions are made here.
 */

const FACE_EMBEDDING_LENGTH    = 128;
const FACE_MATCH_THRESHOLD     = 0.50; // login: lower = stricter
const FACE_DUPLICATE_THRESHOLD = 0.55; // "face already registered" check (a bit looser on purpose)
const FACE_AMBIGUITY_MARGIN    = 0.03; // login refused if 2 accounts are this close

/** Returns a clean float[128] or null if the input is not a valid embedding. */
function face_normalize_embedding(mixed $e): ?array
{
    if (!is_array($e) || count($e) !== FACE_EMBEDDING_LENGTH) {
        return null;
    }
    $out = [];
    $nonzero = false;
    foreach (array_values($e) as $v) {
        if (!is_int($v) && !is_float($v) && !(is_string($v) && is_numeric($v))) {
            return null;
        }
        $f = (float) $v;
        if (!is_finite($f) || abs($f) > 10.0) {
            return null;
        }
        if (abs($f) > 1e-6) {
            $nonzero = true;
        }
        $out[] = $f;
    }
    return $nonzero ? $out : null;
}

function face_embedding_is_valid(mixed $embedding): bool
{
    return face_normalize_embedding($embedding) !== null;
}

/** Euclidean distance — lower = more similar. */
function face_euclidean_distance(array $a, array $b): float
{
    $sum = 0.0;
    $n = min(count($a), count($b));
    for ($i = 0; $i < $n; $i++) {
        $d = (float) $a[$i] - (float) $b[$i];
        $sum += $d * $d;
    }
    return sqrt($sum);
}

function get_face_embedding(mysqli $conn, int $userId): ?array
{
    $stmt = $conn->prepare('SELECT embedding FROM face_verifications WHERE user_id = ? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || !$row['embedding']) {
        return null;
    }
    return face_normalize_embedding(json_decode((string) $row['embedding'], true));
}

/** Insert or replace the user's embedding (one per account). */
function save_face_embedding(mysqli $conn, int $userId, array $embedding): void
{
    $clean = face_normalize_embedding($embedding);
    if ($clean === null) {
        throw new InvalidArgumentException('Invalid face embedding.');
    }
    $json = json_encode($clean);
    $stmt = $conn->prepare(
        'INSERT INTO face_verifications (user_id, embedding, created_at)
         VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE embedding = VALUES(embedding), created_at = NOW()'
    );
    $stmt->bind_param('is', $userId, $json);
    $stmt->execute();
    $stmt->close();
}

/**
 * Scans every stored embedding (pending AND approved accounts) and returns the
 * closest one within $threshold, or null.
 *
 * @return array{user_id:int, distance:float, runner_up:?float}|null
 */
function find_closest_face_match(mysqli $conn, array $embedding, float $threshold = FACE_MATCH_THRESHOLD, ?int $excludeUserId = null): ?array
{
    $embedding = face_normalize_embedding($embedding);
    if ($embedding === null) {
        return null;
    }

    $sql = 'SELECT user_id, embedding FROM face_verifications WHERE embedding IS NOT NULL';
    if ($excludeUserId !== null) {
        $sql .= ' AND user_id <> ' . (int) $excludeUserId;
    }
    $res = $conn->query($sql);

    $best = null;
    $second = null;
    while ($row = $res->fetch_assoc()) {
        $stored = face_normalize_embedding(json_decode((string) $row['embedding'], true));
        if ($stored === null) {
            continue;
        }
        $dist = face_euclidean_distance($embedding, $stored);
        if ($best === null || $dist < $best['distance']) {
            if ($best !== null) {
                $second = $second === null ? $best['distance'] : min($second, $best['distance']);
            }
            $best = ['user_id' => (int) $row['user_id'], 'distance' => $dist];
        } else {
            $second = $second === null ? $dist : min($second, $dist);
        }
    }

    if ($best === null || $best['distance'] > $threshold) {
        return null;
    }
    $best['runner_up'] = $second;
    return $best;
}