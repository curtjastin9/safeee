<?php
declare(strict_types=1);

/**
 * ---------------------------------------------------------------------
 * ID screening — PHASE 3: face on the ID vs the registered face.
 *
 * How it works: the browser (face-api.js, the SAME models already used for face login,
 * assets/models) finds the face on the uploaded ID photo and posts its 128-float descriptor.
 * This file compares it with the registered descriptor using face_euclidean_distance().
 *
 * !!! TRUST NOTE !!!  The descriptor is computed on the USER'S device, exactly like the
 * registration/login face descriptors already are. A determined attacker can post any
 * numbers. So this check is evidence for the admin and a filter for honest mistakes —
 * it is NOT proof. That is why auto-approval additionally needs OCR/vision confirmation
 * (ID_AUTOPASS_REQUIRES_OCR), a random audit sample, and the cross-account face checks below.
 * Server-side face descriptors (dlib / a Node face-api worker) are the real fix; see README.
 * ---------------------------------------------------------------------
 */

require_once __DIR__ . '/face_recognition.php';

// Distances are Euclidean on face-api descriptors (login uses 0.50). ID photos are small, old and
// compressed, so same-person distances run higher than selfie-vs-selfie. TUNE IN PHASE 4.
if (!defined('ID_FACE_STRONG_MAX'))   define('ID_FACE_STRONG_MAX', 0.45);   // <= strong match (auto-pass eligible)
if (!defined('ID_FACE_BORDER_MAX'))   define('ID_FACE_BORDER_MAX', 0.65);   // <= borderline -> admin (medium)
if (!defined('ID_FACE_REJECT_MIN'))   define('ID_FACE_REJECT_MIN', 0.80);   // >= clearly different person
if (!defined('ID_FACE_TOO_PERFECT'))  define('ID_FACE_TOO_PERFECT', 0.08);  // < this = identical numbers: a real ID photo never matches a live selfie this closely
if (!defined('ID_FACE_MIN_SCORE'))    define('ID_FACE_MIN_SCORE', 0.60);    // detector confidence needed before a face verdict can reject
if (!defined('ID_FACE_MIN_BOX'))      define('ID_FACE_MIN_BOX', 70);        // px width of the face on the ID needed before it can reject
if (!defined('ID_FACE_AUTOREJECT'))   define('ID_FACE_AUTOREJECT', getenv('SAFETRADE_ID_FACE_AUTOREJECT') !== '0');

/**
 * Read what id-face.js posted.
 * @return array{status:string, descriptor:?array, score:?float, box_w:?int, faces:int}
 *   status: ok | no_face | error | missing   (missing = the browser sent nothing, e.g. JS blocked)
 */
function id_face_parse_post(array $post): array
{
    $out = ['status' => 'missing', 'descriptor' => null, 'score' => null, 'box_w' => null, 'faces' => 0];
    $st = (string) ($post['id_face_status'] ?? '');
    if (!in_array($st, ['ok', 'no_face', 'error'], true)) {
        return $out;
    }
    $out['status'] = $st;
    $out['faces'] = max(0, min(20, (int) ($post['id_face_count'] ?? 0)));
    if (is_numeric($post['id_face_score'] ?? null)) $out['score'] = max(0.0, min(1.0, (float) $post['id_face_score']));
    if (is_numeric($post['id_face_box'] ?? null))   $out['box_w'] = max(0, min(20000, (int) $post['id_face_box']));
    if ($st === 'ok') {
        $d = json_decode((string) ($post['id_face_descriptor'] ?? ''), true);
        $out['descriptor'] = face_normalize_embedding($d);
        if ($out['descriptor'] === null) $out['status'] = 'error';
    }
    return $out;
}

/**
 * Face on the ID vs the registered face.
 * @return array{check:array, distance:?float}
 */
function check_face_match(?array $registered, array $idFace): array
{
    if ($registered === null) {
        return ['check' => id_check('face_match', 'skip', 'No registered face descriptor on this account, so the ID photo could not be compared.'), 'distance' => null];
    }
    if ($idFace['status'] === 'missing' || $idFace['status'] === 'error') {
        return ['check' => id_check('face_match', 'skip', 'The browser did not send a face descriptor for the ID photo (JavaScript/model problem) — compare the photos by eye.'), 'distance' => null];
    }
    if ($idFace['status'] === 'no_face') {
        return ['check' => id_check('face_match', 'warn', 'No face was found on the ID photo (unclear photo, face covered, or an ID type without a photo).', 'soft', '', 'medium'), 'distance' => null];
    }

    $d = round(face_euclidean_distance($registered, (array) $idFace['descriptor']), 4);
    $quality = ($idFace['score'] === null || $idFace['score'] >= ID_FACE_MIN_SCORE)
            && ($idFace['box_w'] === null || $idFace['box_w'] >= ID_FACE_MIN_BOX);
    $qNote = $quality ? '' : ' (the face on the ID is small or unclear, so this distance is unreliable)';
    $base = 'Distance ' . number_format($d, 3) . ' (strong ≤ ' . ID_FACE_STRONG_MAX . ', borderline ≤ ' . ID_FACE_BORDER_MAX . ', different ≥ ' . ID_FACE_REJECT_MIN . ')' . $qNote . '.';

    if ($d < ID_FACE_TOO_PERFECT) {
        return ['check' => id_check('face_match', 'warn', $base . ' Suspiciously identical to the registered face — a real ID photo never matches a live selfie this closely (reused data?).', 'soft', '', 'high'), 'distance' => $d];
    }
    if ($d <= ID_FACE_STRONG_MAX) {
        return ['check' => id_check('face_match', $quality ? 'pass' : 'warn', $quality ? $base . ' Strong match.' : $base, 'soft', '', $quality ? '' : 'medium'), 'distance' => $d];
    }
    if ($d <= ID_FACE_BORDER_MAX) {
        return ['check' => id_check('face_match', 'warn', $base . ' Borderline — an admin should compare the photos.', 'soft', '', 'medium'), 'distance' => $d];
    }
    if ($d >= ID_FACE_REJECT_MIN && $quality && ID_FACE_AUTOREJECT) {
        return ['check' => id_check('face_match', 'fail', $base . ' Clearly a different face.', 'hard',
            'The face on your ID does not match the face you registered with. Photograph your ID again with the photo on it clearly visible, or request a manual review below.'), 'distance' => $d];
    }
    return ['check' => id_check('face_match', 'warn', $base . ' Probably a different person' . ($quality ? '' : ' (or a poor photo)') . '.', 'soft', '', 'high'), 'distance' => $d];
}

/**
 * Does this ID face match ANOTHER account (its registered face, or the face on its ID)?
 * Catches one person on several accounts and a stolen ID whose owner is also registered. Admin only, high risk.
 */
function check_face_other_accounts(mysqli $conn, int $userId, ?array $idDescriptor): array
{
    if ($idDescriptor === null) {
        return id_check('face_duplicate', 'na', 'No ID face descriptor to compare with other accounts.');
    }
    $hits = [];
    try {
        $m = find_closest_face_match($conn, $idDescriptor, ID_FACE_STRONG_MAX, $userId);
        if ($m) $hits[] = 'the registered face of account #' . $m['user_id'] . ' (distance ' . number_format($m['distance'], 3) . ')';
    } catch (Throwable $e) {
        error_log('[SafeTrade] face_duplicate registered scan failed: ' . $e->getMessage());
    }
    try {
        $stmt = $conn->prepare('SELECT user_id, id_face_descriptor FROM government_ids WHERE id_face_descriptor IS NOT NULL AND user_id <> ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $o = face_normalize_embedding(json_decode((string) $r['id_face_descriptor'], true));
            if ($o === null) continue;
            $d = face_euclidean_distance($idDescriptor, $o);
            if ($d <= ID_FACE_STRONG_MAX) $hits[] = 'the face on the ID of account #' . (int) $r['user_id'] . ' (distance ' . number_format($d, 3) . ')';
        }
        $stmt->close();
    } catch (Throwable $e) {
        // column missing (Phase 3 migration not run) — registered-face scan above still ran
    }
    if ($hits) {
        return id_check('face_duplicate', 'warn', 'The face on this ID matches ' . implode('; ', array_slice($hits, 0, 3)) . '. Same person on several accounts, or a stolen ID?', 'soft', '', 'high');
    }
    return id_check('face_duplicate', 'pass', 'The face on this ID does not match any other account.');
}
