<?php
declare(strict_types=1);

/**
 * ---------------------------------------------------------------------
 * ID screening pipeline — PHASE 1 (deterministic checks only).
 *
 * What this does:   decides  pass | admin | reject  for a submitted government ID,
 *                   plus a separate image "quality gate" that yields  retry.
 * What this is NOT: proof an ID is real. No government database is queried.
 *                   "pass/valid" here only ever means "internally consistent".
 *
 * Phase 1 has no OCR, so the number / expiry / passport MRZ lines are the
 * values the USER TYPED on the ID step. Phase 2 (OCR) will cross-check them
 * against the photo. Until then the face and name checks report 'skip', and
 * because a 'skip' blocks auto-pass, NOTHING auto-passes in Phase 1 unless you
 * deliberately set ID_SCREEN_AUTOPASS_REQUIRES_FACE to false (localhost testing).
 *
 * Check result vocabulary (each check is ['name','result','severity','detail','user_msg']):
 *   pass  — verified clean
 *   fail  — severity 'hard'  => auto-reject candidate,   severity 'soft' => admin
 *   warn  — suspicious/uncertain => admin
 *   skip  — could not be verified (or phase not built yet) => admin, blocks auto-pass
 *   na    — not applicable to this ID type => ignored
 * ---------------------------------------------------------------------
 */

require_once __DIR__ . '/id_formats.php';
require_once __DIR__ . '/id_name_match.php';   // Phase 2
require_once __DIR__ . '/id_ocr.php';          // Phase 2 (optional OCR / vision / forensics)
require_once __DIR__ . '/id_tuning.php';       // Phase 4 (admin-tuned thresholds + labels)
id_settings_load();                            // must run before the files below define their defaults
require_once __DIR__ . '/id_face.php';         // Phase 3 (ID photo vs registered face)

/* ---------------- configuration (override with define() BEFORE including this file) ---------------- */
if (!defined('ID_SCREEN_ENABLED')) {
    // Master kill-switch. SAFETRADE_ID_SCREEN=0 => every submission just goes to an admin, like before.
    define('ID_SCREEN_ENABLED', getenv('SAFETRADE_ID_SCREEN') !== '0');
}
if (!defined('ID_MAX_ATTEMPTS'))                  define('ID_MAX_ATTEMPTS', 3);      // auto-rejects allowed before only an appeal is possible
if (!defined('ID_SCREEN_AUTOPASS_REQUIRES_FACE')) define('ID_SCREEN_AUTOPASS_REQUIRES_FACE', true); // false = TESTING ONLY
// Quality gate thresholds (heuristics — tune with real uploads; they only ever cause a re-upload request).
if (!defined('ID_Q_MIN_SHORT_SIDE'))  define('ID_Q_MIN_SHORT_SIDE', 500);   // px
if (!defined('ID_Q_MIN_LONG_SIDE'))   define('ID_Q_MIN_LONG_SIDE', 700);    // px
if (!defined('ID_Q_BLUR_MIN_VAR'))    define('ID_Q_BLUR_MIN_VAR', 60.0);    // Laplacian variance on a 640px-wide grey copy
if (!defined('ID_Q_BRIGHT_MIN'))      define('ID_Q_BRIGHT_MIN', 45.0);      // mean luma 0-255
if (!defined('ID_Q_BRIGHT_MAX'))      define('ID_Q_BRIGHT_MAX', 245.0);
if (!defined('ID_Q_ASPECT_MIN'))      define('ID_Q_ASPECT_MIN', 1.15);      // long/short; a bank-card ID is ~1.59
if (!defined('ID_Q_ASPECT_MAX'))      define('ID_Q_ASPECT_MAX', 2.10);
if (!defined('ID_Q_MAX_PIXELS'))      define('ID_Q_MAX_PIXELS', 40000000);  // memory guard
// Phase 2 authenticity thresholds (0-100 suspicion). Auto-reject on authenticity is OFF unless explicitly enabled.
if (!defined('ID_AUTH_SUSPICIOUS_MIN')) define('ID_AUTH_SUSPICIOUS_MIN', 40);
if (!defined('ID_AUTH_REJECT_MIN'))     define('ID_AUTH_REJECT_MIN', 95);
if (!defined('ID_AUTH_AUTOREJECT'))     define('ID_AUTH_AUTOREJECT', getenv('SAFETRADE_ID_AUTH_AUTOREJECT') === '1');
// Phase 3 auto-approval safety rails.
// Typed ID data and the ID-face descriptor both come from the user's device, so auto-approval also requires the
// photo itself to confirm the typed number + expiry (needs Tesseract or the vision model). Set to false only for local testing.
if (!defined('ID_AUTOPASS_REQUIRES_OCR')) define('ID_AUTOPASS_REQUIRES_OCR', getenv('SAFETRADE_ID_AUTOPASS_NEEDS_OCR') !== '0');
// Fraction of would-be auto-approvals that an admin double-checks anyway (also gives Phase 4 real labels).
if (!defined('ID_AUDIT_RATE')) define('ID_AUDIT_RATE', is_numeric(getenv('SAFETRADE_ID_AUDIT_RATE')) ? (float) getenv('SAFETRADE_ID_AUDIT_RATE') : 0.10);
if (!defined('ID_PHASH_NEAR_DISTANCE')) define('ID_PHASH_NEAR_DISTANCE', 4); // Hamming bits (of 64) = "near-identical photo"

/* ===================================================================
 * Small utilities
 * =================================================================== */

/** Secret salt for ID-number hashes: env SAFETRADE_ID_SALT, else a random key file created once. */
function id_number_salt(): string
{
    static $salt = null;
    if ($salt !== null) {
        return $salt;
    }
    $env = getenv('SAFETRADE_ID_SALT');
    if (is_string($env) && $env !== '') {
        return $salt = $env;
    }
    $file = __DIR__ . '/../config/id_salt.key';   // add to .gitignore; back it up — losing it breaks duplicate detection
    if (is_file($file)) {
        $v = trim((string) @file_get_contents($file));
        if ($v !== '') {
            return $salt = $v;
        }
    }
    $v = bin2hex(random_bytes(32));
    if (@file_put_contents($file, $v) === false) {
        error_log('[SafeTrade] could not write config/id_salt.key — set SAFETRADE_ID_SALT instead');
    }
    return $salt = $v;
}

/** HMAC-SHA256 of "type|NORMALISED-NUMBER". The plaintext number is never stored. */
function id_number_hash(string $typeKey, string $normalizedNumber): string
{
    return hash_hmac('sha256', $typeKey . '|' . $normalizedNumber, id_number_salt());
}

function id_mask_number(string $normalized): string
{
    $n = strlen($normalized);
    if ($n === 0) return '';
    return str_repeat('*', max(0, $n - 4)) . substr($normalized, -4);
}

/** Strict Y-m-d -> DateTimeImmutable|null (rejects 2025-02-30 etc.). */
function id_parse_date(string $s): ?DateTimeImmutable
{
    $s = trim($s);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        return null;
    }
    return new DateTimeImmutable($s);
}

function id_check(string $name, string $result, string $detail, string $severity = 'soft', string $userMsg = '', string $risk = ''): array
{
    return ['name' => $name, 'result' => $result, 'severity' => $severity, 'detail' => $detail, 'user_msg' => $userMsg, 'risk' => $risk];
}

/* ===================================================================
 * Image helpers (GD) — hashes and quality gate
 * =================================================================== */

function id_load_gd(string $path): ?GdImage
{
    if (!extension_loaded('gd')) return null;
    $info = @getimagesize($path);
    if ($info === false) return null;
    $img = match ($info[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
        IMAGETYPE_PNG  => @imagecreatefrompng($path),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
        default        => false,
    };
    return $img instanceof GdImage ? $img : null;
}

/**
 * Hashes of the RAW uploaded file (call before compression).
 * @return array{sha256:string, phash:?string}
 */
function id_image_hashes(string $path): array
{
    $sha = (string) @hash_file('sha256', $path);
    $phash = null;
    $info = @getimagesize($path);
    if ($info !== false && $info[0] * $info[1] <= ID_Q_MAX_PIXELS) {
        $img = id_load_gd($path);
        if ($img !== null) {
            // dHash: 9x8 grey thumbnail, 1 bit per "left pixel brighter than right pixel".
            $small = imagecreatetruecolor(9, 8);
            imagecopyresampled($small, $img, 0, 0, 0, 0, 9, 8, imagesx($img), imagesy($img));
            $bits = '';
            for ($y = 0; $y < 8; $y++) {
                $prev = null;
                for ($x = 0; $x < 9; $x++) {
                    $c = imagecolorat($small, $x, $y);
                    $l = (int) round(0.299 * (($c >> 16) & 255) + 0.587 * (($c >> 8) & 255) + 0.114 * ($c & 255));
                    if ($prev !== null) $bits .= ($prev > $l) ? '1' : '0';
                    $prev = $l;
                }
            }
            $hex = '';
            foreach (str_split($bits, 4) as $nibble) $hex .= dechex(bindec($nibble));
            $phash = $hex;
            unset($small, $img);
        }
    }
    return ['sha256' => $sha, 'phash' => $phash];
}

function id_hamming_hex(string $a, string $b): int
{
    if (strlen($a) !== strlen($b) || $a === '') return 64;
    $d = 0;
    for ($i = 0, $n = strlen($a); $i < $n; $i++) {
        $d += substr_count(decbin(hexdec($a[$i]) ^ hexdec($b[$i])), '1');
    }
    return $d;
}

/**
 * STEP 0 — quality gate on the raw upload. A failure means "please retake the photo":
 * it is NOT fraud and does NOT count toward the attempt limit.
 *
 * @return array{ok:bool, issues:string[], metrics:array}
 */
function id_quality_gate(string $path): array
{
    $issues = [];
    $metrics = [];
    $info = @getimagesize($path);
    if ($info === false) {
        return ['ok' => false, 'issues' => ['We could not read this image. Please upload a JPG, PNG or WEBP photo.'], 'metrics' => []];
    }
    [$w, $h] = $info;
    $metrics['width'] = $w;
    $metrics['height'] = $h;
    $short = min($w, $h);
    $long = max($w, $h);

    if ($short < ID_Q_MIN_SHORT_SIDE || $long < ID_Q_MIN_LONG_SIDE) {
        $issues[] = "The photo is too small ({$w}×{$h}px). Move closer and use your camera's normal photo mode.";
    }
    if ($w * $h > ID_Q_MAX_PIXELS) {
        $issues[] = 'The photo is extremely large. Please upload a normal phone-camera photo (under 40 megapixels).';
        return ['ok' => false, 'issues' => $issues, 'metrics' => $metrics];
    }
    $ratio = $short > 0 ? $long / $short : 0.0;
    $metrics['aspect'] = round($ratio, 2);
    if ($ratio < ID_Q_ASPECT_MIN || $ratio > ID_Q_ASPECT_MAX) {
        $issues[] = 'The ID looks cropped or not fully in frame. Show the whole card or page with all four edges visible.';
    }

    $img = id_load_gd($path);
    if ($img === null) {
        // GD missing/unreadable: we cannot judge blur or light — do not block the user for our own limitation.
        $metrics['analysis'] = 'skipped';
        return ['ok' => !$issues, 'issues' => $issues, 'metrics' => $metrics];
    }

    // Grey copy, max 640px wide.
    $tw = min(640, $w);
    $th = max(1, (int) round($h * $tw / $w));
    $g = imagecreatetruecolor($tw, $th);
    imagecopyresampled($g, $img, 0, 0, 0, 0, $tw, $th, $w, $h);
    unset($img);
    $grey = [];
    $sum = 0.0;
    for ($y = 0; $y < $th; $y++) {
        $row = [];
        for ($x = 0; $x < $tw; $x++) {
            $c = imagecolorat($g, $x, $y);
            $l = 0.299 * (($c >> 16) & 255) + 0.587 * (($c >> 8) & 255) + 0.114 * ($c & 255);
            $row[] = $l;
            $sum += $l;
        }
        $grey[] = $row;
    }
    unset($g);
    $mean = $sum / ($tw * $th);
    $metrics['brightness'] = round($mean, 1);

    // Variance of the Laplacian: low = few sharp edges = blurry.
    $n = 0; $s = 0.0; $s2 = 0.0;
    for ($y = 1; $y < $th - 1; $y++) {
        for ($x = 1; $x < $tw - 1; $x++) {
            $lap = $grey[$y - 1][$x] + $grey[$y + 1][$x] + $grey[$y][$x - 1] + $grey[$y][$x + 1] - 4 * $grey[$y][$x];
            $s += $lap; $s2 += $lap * $lap; $n++;
        }
    }
    $var = $n > 0 ? ($s2 / $n) - ($s / $n) ** 2 : 0.0;
    $metrics['sharpness'] = round($var, 1);

    if ($mean < ID_Q_BRIGHT_MIN) {
        $issues[] = 'The photo is too dark. Take it in good light, without shadows over the ID.';
    } elseif ($mean > ID_Q_BRIGHT_MAX) {
        $issues[] = 'The photo is washed out / overexposed. Avoid flash glare and strong direct light.';
    }
    // Very dark / washed-out photos always have low contrast, so only report blur when exposure is fine.
    if ($var < ID_Q_BLUR_MIN_VAR && $mean >= ID_Q_BRIGHT_MIN && $mean <= ID_Q_BRIGHT_MAX) {
        $issues[] = 'The photo is blurry. Hold the phone steady, let it focus, and make sure the text is readable.';
    }
    return ['ok' => !$issues, 'issues' => $issues, 'metrics' => $metrics];
}

/* ===================================================================
 * Passport MRZ (ICAO 9303, TD3: 2 lines x 44 chars)
 * =================================================================== */

/** Clean what the user typed: upper-case, drop spaces; returns exactly the non-empty lines (max 2). */
function id_mrz_clean_lines(string $raw): array
{
    $lines = [];
    foreach (preg_split('/\R/', strtoupper($raw)) ?: [] as $l) {
        $l = preg_replace('/\s+/', '', $l) ?? '';
        if ($l !== '') $lines[] = $l;
    }
    return array_slice($lines, 0, 3);
}

/** Structure only: two lines, 44 chars each, A-Z 0-9 '<'. Returns a list of problems (empty = OK). */
function id_mrz_structure_errors(array $lines): array
{
    if (count($lines) !== 2) return ['Enter exactly two MRZ lines (the two rows of < characters at the bottom of the photo page).'];
    $e = [];
    foreach ($lines as $i => $l) {
        if (strlen($l) !== 44) $e[] = 'MRZ line ' . ($i + 1) . ' must be exactly 44 characters (it has ' . strlen($l) . ').';
        elseif (!preg_match('/^[A-Z0-9<]{44}$/', $l)) $e[] = 'MRZ line ' . ($i + 1) . ' may only contain A–Z, 0–9 and the < character.';
    }
    return $e;
}

function id_mrz_check_digit(string $field): int
{
    $w = [7, 3, 1];
    $sum = 0;
    foreach (str_split($field) as $i => $ch) {
        if ($ch === '<') $v = 0;
        elseif ($ch >= '0' && $ch <= '9') $v = (int) $ch;
        else $v = ord($ch) - 55;               // A=10 … Z=35
        $sum += $v * $w[$i % 3];
    }
    return $sum % 10;
}

/** A TD3 check character is a digit, or '<' (=0) when the field it guards is entirely '<'. */
function id_mrz_digit_ok(string $field, string $checkChar): bool
{
    if ($checkChar === '<') {
        return preg_match('/^<*$/', $field) === 1;   // only valid for an all-filler optional field
    }
    return ctype_digit($checkChar) && (int) $checkChar === id_mrz_check_digit($field);
}

/** YYMMDD -> Y-m-d, or null if not a real date. $future: expiry dates (assume 20xx); DOB pivots on today. */
function id_mrz_date(string $yymmdd, bool $isExpiry): ?string
{
    if (!preg_match('/^\d{6}$/', $yymmdd)) return null;
    $yy = (int) substr($yymmdd, 0, 2);
    $mm = (int) substr($yymmdd, 2, 2);
    $dd = (int) substr($yymmdd, 4, 2);
    $curYY = (int) date('y');
    $year = $isExpiry ? 2000 + $yy : (($yy > $curYY) ? 1900 + $yy : 2000 + $yy);
    return checkdate($mm, $dd, $year) ? sprintf('%04d-%02d-%02d', $year, $mm, $dd) : null;
}

/**
 * Parse + verify a TD3 MRZ.
 * @return array{structure_errors:string[], digit_failures:string[], fields:array}
 */
function id_mrz_parse_td3(array $lines): array
{
    $out = ['structure_errors' => id_mrz_structure_errors($lines), 'digit_failures' => [], 'fields' => []];
    if ($out['structure_errors']) return $out;
    [$l1, $l2] = $lines;

    $docNo   = substr($l2, 0, 9);   $docNoCd = $l2[9];
    $dob     = substr($l2, 13, 6);  $dobCd   = $l2[19];
    $exp     = substr($l2, 21, 6);  $expCd   = $l2[27];
    $opt     = substr($l2, 28, 14); $optCd   = $l2[42];
    $compCd  = $l2[43];
    $composite = substr($l2, 0, 10) . substr($l2, 13, 7) . substr($l2, 21, 22);

    if (!id_mrz_digit_ok($docNo, $docNoCd))      $out['digit_failures'][] = 'document number';
    if (!id_mrz_digit_ok($dob, $dobCd))          $out['digit_failures'][] = 'date of birth';
    if (!id_mrz_digit_ok($exp, $expCd))          $out['digit_failures'][] = 'expiry date';
    if (!id_mrz_digit_ok($opt, $optCd))          $out['digit_failures'][] = 'optional data';
    if (!ctype_digit($compCd) || (int) $compCd !== id_mrz_check_digit($composite)) $out['digit_failures'][] = 'overall (composite)';

    $nameParts = explode('<<', rtrim(substr($l1, 5), '<'), 2);
    $out['fields'] = [
        'doc_code'    => substr($l1, 0, 2),
        'issuer'      => substr($l1, 2, 3),
        'surname'     => trim(str_replace('<', ' ', $nameParts[0] ?? '')),
        'given_names' => trim(str_replace('<', ' ', $nameParts[1] ?? '')),
        'doc_number'  => rtrim($docNo, '<'),
        'nationality' => substr($l2, 10, 3),
        'dob'         => id_mrz_date($dob, false),
        'sex'         => $l2[20],
        'expiry'      => id_mrz_date($exp, true),
    ];
    return $out;
}

/* ===================================================================
 * Individual checks — small, pure where possible, easy to unit test
 * =================================================================== */

/** Number present and shaped like this ID type's number. */
function check_number_format(string $typeKey, string $normalized): array
{
    $f = id_formats()[$typeKey] ?? null;
    if ($f === null) return id_check('number_format', 'skip', 'Unknown ID type.');
    if ($normalized === '') {
        return $f['requires_number']
            ? id_check('number_format', 'skip', 'No ID number was provided, so the format could not be checked.')
            : id_check('number_format', 'na', 'No ID number expected for this type.');
    }
    if ($f['number_regex'] === null) {
        return id_check('number_format', 'na', 'No known number format for this ID type.');
    }
    if (preg_match($f['number_regex'], $normalized) === 1) {
        return id_check('number_format', 'pass', 'Number matches the expected pattern for ' . $f['label'] . '.');
    }
    if ($f['enforce_format']) {
        return id_check('number_format', 'fail',
            'Number does not fit the ' . $f['label'] . ' pattern (' . strlen($normalized) . ' chars).', 'hard',
            'The ' . $f['number_label'] . ' you entered is not in a valid format for a ' . $f['label'] . '. Please re-check it against your ID.');
    }
    return id_check('number_format', 'fail',
        'Number does not fit the usual ' . $f['label'] . ' pattern (' . strlen($normalized) . ' chars). Format is not enforced, so an admin decides.', 'soft');
}

/** Does the number look like a DIFFERENT ID type than the one selected? (weak hint -> admin only) */
function check_id_type_match(string $typeKey, string $normalized): array
{
    $f = id_formats()[$typeKey] ?? null;
    if ($f === null || $normalized === '' || $f['number_regex'] === null) {
        return id_check('id_type_match', 'na', 'Not enough information to compare the number with other ID types.');
    }
    if (preg_match($f['number_regex'], $normalized) === 1) {
        return id_check('id_type_match', 'pass', 'Number is consistent with the selected ID type.');
    }
    $others = id_types_matching_number($normalized, $typeKey);
    if ($others) {
        $names = array_map(static fn(string $k): string => id_formats()[$k]['label'], $others);
        return id_check('id_type_match', 'warn', 'Selected "' . $f['label'] . '" but the number looks like: ' . implode(', ', $names) . '.');
    }
    return id_check('id_type_match', 'na', 'Number does not match any known ID type pattern.');
}

/** Expired ID -> hard reject. Missing expiry on a type that needs it -> admin. */
function check_expiry(string $expiryYmd, array $fmt, ?DateTimeImmutable $today = null): array
{
    $today ??= new DateTimeImmutable('today');
    $expiryYmd = trim($expiryYmd);
    if ($expiryYmd === '') {
        return $fmt['requires_expiry']
            ? id_check('expiry', 'skip', 'No expiry date available for an ID type that has one.')
            : id_check('expiry', 'na', 'No expiry date provided (not required for this ID type).');
    }
    $d = id_parse_date($expiryYmd);
    if ($d === null) return id_check('expiry', 'skip', 'Expiry date could not be read.');
    if ($d < $today) {
        return id_check('expiry', 'fail', 'ID expired on ' . $d->format('Y-m-d') . '.', 'hard',
            'This ID expired on ' . $d->format('M j, Y') . '. Please upload a valid ID that has not expired.');
    }
    if ($d > $today->modify('+25 years')) {
        return id_check('expiry', 'warn', 'Expiry date ' . $d->format('Y-m-d') . ' is more than 25 years away — unusual.');
    }
    return id_check('expiry', 'pass', 'Not expired (valid until ' . $d->format('Y-m-d') . ').');
}

/**
 * Passport MRZ check digits (hard) + consistency with what was typed (soft).
 * @return array{check:array, fields:array}
 */
function check_passport_mrz(array $mrzLines, string $declaredNormNumber): array
{
    if (!$mrzLines) {
        return ['check' => id_check('passport_mrz', 'skip', 'Passport MRZ lines were not provided, so check digits could not be verified.'), 'fields' => []];
    }
    $p = id_mrz_parse_td3($mrzLines);
    if ($p['structure_errors']) {
        return ['check' => id_check('passport_mrz', 'fail', 'MRZ structure invalid: ' . implode(' ', $p['structure_errors']), 'hard',
            'The passport MRZ lines are not in a valid format. Copy the two lines at the bottom of the photo page exactly as printed.'), 'fields' => []];
    }
    if ($p['digit_failures']) {
        return ['check' => id_check('passport_mrz', 'fail', 'MRZ check digit mismatch: ' . implode(', ', $p['digit_failures']) . '.', 'hard',
            'The passport MRZ check digits do not add up, so the MRZ was mistyped or the document is invalid. Re-check the two bottom lines character by character.'), 'fields' => $p['fields']];
    }
    $fl = $p['fields'];
    $notes = [];
    if ($declaredNormNumber !== '' && id_normalize_number($fl['doc_number']) !== $declaredNormNumber) $notes[] = 'MRZ document number differs from the typed passport number';
    if (!str_starts_with($fl['doc_code'], 'P'))  $notes[] = 'MRZ document code is "' . $fl['doc_code'] . '", not a passport (P)';
    if ($fl['issuer'] !== 'PHL')                 $notes[] = 'issuing country is "' . $fl['issuer'] . '", not PHL';
    if ($fl['dob'] === null || $fl['expiry'] === null) $notes[] = 'MRZ contains an impossible date';
    if ($notes) {
        return ['check' => id_check('passport_mrz', 'warn', 'MRZ check digits are valid, but: ' . implode('; ', $notes) . '.'), 'fields' => $fl];
    }
    return ['check' => id_check('passport_mrz', 'pass', 'All MRZ check digits are valid and consistent with the typed number.'), 'fields' => $fl];
}

/** Same ID number already on another account -> hard reject. */
function check_duplicate_number(mysqli $conn, int $userId, ?string $numberHash): array
{
    if ($numberHash === null) return id_check('duplicate_number', 'skip', 'No ID number, so duplicates could not be checked.');
    $stmt = $conn->prepare('SELECT user_id FROM government_ids WHERE id_number_hash = ? AND user_id <> ? LIMIT 1');
    $stmt->bind_param('si', $numberHash, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        return id_check('duplicate_number', 'fail', 'This ID number is already on account #' . (int) $row['user_id'] . '.', 'hard',
            'This ID number is already registered to another SafeTrade account. If you believe this is a mistake, request a manual review below.');
    }
    return id_check('duplicate_number', 'pass', 'ID number is not used by another account.');
}

/** Identical file on another account -> hard reject; near-identical picture -> admin. */
function check_duplicate_image(mysqli $conn, int $userId, ?string $sha256, ?string $phash): array
{
    if ($sha256 !== null && $sha256 !== '') {
        $stmt = $conn->prepare('SELECT user_id FROM government_ids WHERE image_hash = ? AND user_id <> ? LIMIT 1');
        $stmt->bind_param('si', $sha256, $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            return id_check('duplicate_image', 'fail', 'Identical ID image already used by account #' . (int) $row['user_id'] . '.', 'hard',
                'This exact ID photo has already been used on another account. If you believe this is a mistake, request a manual review below.');
        }
    }
    if ($phash !== null && $phash !== '') {
        $stmt = $conn->prepare('SELECT user_id, image_phash FROM government_ids WHERE image_phash IS NOT NULL AND user_id <> ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $d = id_hamming_hex($phash, (string) $r['image_phash']);
            if ($d <= ID_PHASH_NEAR_DISTANCE) {
                $stmt->close();
                return id_check('duplicate_image', 'warn', 'Photo is visually near-identical (distance ' . $d . '/64) to the ID image of account #' . (int) $r['user_id'] . '.');
            }
        }
        $stmt->close();
    }
    return id_check('duplicate_image', 'pass', 'Image is not used by another account.');
}

/** Weak / unknown ID types are never auto-decided. */
function check_id_strength(array $fmt): array
{
    if ($fmt['always_admin']) {
        return id_check('id_type_policy', 'warn', $fmt['label'] . ' is a weak/unstructured ID type — always reviewed by an admin.');
    }
    if ($fmt['strength'] !== 'strong') {
        return id_check('id_type_policy', 'skip', $fmt['label'] . ' cannot be auto-verified without OCR/QR checks (not built or not accredited) — admin review.');
    }
    return id_check('id_type_policy', 'pass', $fmt['label'] . ' is eligible for automatic approval when every other check is clean.');
}

/* ===================================================================
 * PHASE 2 checks — names, OCR/vision cross-checks, authenticity.
 * None of these can auto-reject except an extreme authenticity score, and even that is
 * OFF by default (SAFETRADE_ID_AUTH_AUTOREJECT=1 to enable after Phase 4 tuning).
 * =================================================================== */

/** Name on the ID vs registered name. Conservative: the LOWEST score of all available sources decides. */
function check_name_match(string $registered, ?array $mrzFields, ?array $vision, ?string $ocrText): array
{
    $registered = trim($registered);
    if ($registered === '') return id_check('name_match', 'skip', 'No registered name to compare.');
    $parts = [];
    $min = null;
    if ($mrzFields && ($mrzFields['surname'] ?? '') !== '') {
        $r = id_name_similarity($registered, trim($mrzFields['given_names'] . ' ' . $mrzFields['surname']));
        $parts[] = 'passport MRZ ' . number_format($r['score'] * 100) . '%';
        $min = $r['score'];
    }
    if ($vision) {
        $vn = $vision['full_name'] ?? trim((string) ($vision['given_names'] ?? '') . ' ' . (string) ($vision['surname'] ?? ''));
        if (trim((string) $vn) !== '') {
            $r = id_name_similarity($registered, (string) $vn);
            $parts[] = 'vision read "' . $vn . '" ' . number_format($r['score'] * 100) . '%';
            $min = $min === null ? $r['score'] : min($min, $r['score']);
        }
    }
    if ($ocrText !== null && trim($ocrText) !== '') {
        $r = id_name_in_text($registered, $ocrText);
        $parts[] = 'OCR text contains ' . count($r['found']) . '/' . (count($r['found']) + count($r['missing'])) . ' name parts' . ($r['missing'] ? ' (missing: ' . implode(', ', $r['missing']) . ')' : '');
        $min = $min === null ? $r['score'] : min($min, $r['score']);
    }
    if ($min === null) {
        return id_check('name_match', 'skip', 'Name on the ID could not be read (not a passport MRZ, and OCR/vision are off or failed).');
    }
    $detail = 'Registered "' . $registered . '" vs ID — ' . implode('; ', $parts) . '.';
    if ($min >= ID_NAME_PASS_MIN) return id_check('name_match', 'pass', $detail);
    if ($min >= ID_NAME_ADMIN_MIN) return id_check('name_match', 'warn', $detail . ' Could be a typo or a different spelling.', 'soft', '', 'medium');
    return id_check('name_match', 'warn', $detail . ' The names look different.', 'soft', '', 'high');
}

function id_alnum_upper(string $s): string
{
    return preg_replace('/[^A-Z0-9]/', '', strtoupper($s)) ?? '';
}

/** Is $needle in $hay exactly (0) or within 1 edit (1)? null = not found. */
function id_find_fuzzy(string $needle, string $hay): ?int
{
    $n = strlen($needle);
    if ($n < 5 || $hay === '') return null;
    if (str_contains($hay, $needle)) return 0;
    $len = strlen($hay);
    for ($i = 0; $i + $n - 1 <= $len; $i++) {
        foreach ([$n - 1, $n, $n + 1] as $w) {
            if ($i + $w > $len) continue;
            if (levenshtein($needle, substr($hay, $i, $w)) <= 1) return 1;
        }
    }
    return null;
}

/**
 * Typed number / expiry / ID type vs what OCR or the vision model read. Disagreement => admin flag only.
 * @return array[] checks
 */
function check_ocr_consistency(string $typeKey, string $typedNumberNorm, string $effectiveExpiry, ?array $vision, ?string $ocrText): array
{
    $checks = [];
    $haveEvidence = ($vision !== null) || ($ocrText !== null && trim($ocrText) !== '');
    if (!$haveEvidence) {
        return [id_check('ocr_cross_check', 'na', 'OCR / vision are off or unavailable — typed values were not compared with the photo.')];
    }
    $hay = $ocrText !== null ? id_alnum_upper($ocrText) : '';

    // --- number ---
    if ($typedNumberNorm === '') {
        $checks[] = id_check('ocr_number', 'na', 'No typed number to compare.');
    } else {
        $vNum = $vision && !empty($vision['id_number']) ? id_normalize_number((string) $vision['id_number']) : null;
        if ($vNum !== null && $vNum === $typedNumberNorm) {
            $checks[] = id_check('ocr_number', 'pass', 'Typed ID number matches the number read by the vision model.');
        } else {
            $fz = id_find_fuzzy($typedNumberNorm, $hay);
            if ($fz === 0) $checks[] = id_check('ocr_number', 'pass', 'Typed ID number was found in the OCR text.');
            elseif ($fz === 1) $checks[] = id_check('ocr_number', 'warn', 'Typed ID number is 1 character away from text on the ID — typo or OCR noise.');
            elseif ($vNum !== null) $checks[] = id_check('ocr_number', 'warn', 'Vision model read a different number than the one typed.', 'soft', '', 'medium');
            elseif ($hay !== '') $checks[] = id_check('ocr_number', 'warn', 'Typed ID number was not found in the OCR text (OCR is imperfect).');
            else $checks[] = id_check('ocr_number', 'skip', 'No readable text to compare the ID number with.');
        }
    }

    // --- expiry ---
    if ($effectiveExpiry === '') {
        $checks[] = id_check('ocr_expiry', 'na', 'No typed expiry to compare.');
    } else {
        $vExp = $vision['expiry_date'] ?? null;
        $dates = $ocrText !== null ? id_ocr_find_dates($ocrText) : [];
        if (($vExp !== null && $vExp === $effectiveExpiry) || in_array($effectiveExpiry, $dates, true)) {
            $checks[] = id_check('ocr_expiry', 'pass', 'Typed expiry date appears on the ID.');
        } elseif ($vExp !== null) {
            $past = id_parse_date($vExp) !== null && id_parse_date($vExp) < new DateTimeImmutable('today');
            $checks[] = id_check('ocr_expiry', 'warn',
                'Vision model read expiry ' . $vExp . ' but ' . $effectiveExpiry . ' was typed' . ($past ? ' — the ID may actually be EXPIRED.' : '.'),
                'soft', '', $past ? 'high' : 'medium');
        } elseif ($dates) {
            $checks[] = id_check('ocr_expiry', 'warn', 'Typed expiry date was not among the dates found on the ID.');
        } else {
            $checks[] = id_check('ocr_expiry', 'skip', 'No readable dates found on the ID.');
        }
    }

    // --- ID type ---
    $sel = id_formats()[$typeKey]['label'] ?? $typeKey;
    $detected = [];
    if ($vision && ($vision['id_type'] ?? 'unknown') !== 'unknown') $detected[] = (string) $vision['id_type'];
    if ($ocrText !== null) $detected = array_merge($detected, id_ocr_detect_types($ocrText));
    $detected = array_values(array_unique($detected));
    if ($typeKey === 'other' || !$detected) {
        $checks[] = id_check('ocr_id_type', 'na', 'Could not tell the ID type from the photo.');
    } elseif (in_array($typeKey, $detected, true)) {
        $checks[] = id_check('ocr_id_type', 'pass', 'The photo looks like the selected ID type (' . $sel . ').');
    } else {
        $names = array_map(static fn(string $k): string => id_formats()[$k]['label'] ?? $k, $detected);
        $checks[] = id_check('ocr_id_type', 'warn', 'Selected "' . $sel . '" but the photo looks like: ' . implode(', ', $names) . '.');
    }
    return $checks;
}

/**
 * Edit / AI / screenshot likelihood 0-100 (higher = more suspicious). NOT proof either way.
 * @return array{check:array, score:?int}
 */
function check_authenticity(?array $forensics, ?array $vision): array
{
    $f = $forensics['score'] ?? null;
    $v = $vision['risk'] ?? null;
    if ($f === null && $v === null) {
        return ['check' => id_check('authenticity', 'na', 'No forensic or vision analysis was available.'), 'score' => null];
    }
    $f ??= 0;
    $score = $v === null ? $f : min(100, max($v, $f) + (min($v, $f) >= 30 ? 10 : 0));
    $why = array_merge($forensics['reasons'] ?? [], $vision ? array_map(static fn(string $s): string => 'Vision model: ' . $s, $vision['tampering'] ?? []) : []);
    if ($vision && !empty($vision['notes'])) $why[] = 'Vision note: ' . $vision['notes'];
    $detail = 'Suspicion score ' . $score . '/100 (forensics ' . $f . ($v !== null ? ', vision ' . $v : ', vision off') . '). ' . ($why ? implode(' ', $why) : 'No red flags found.');

    if ($score >= ID_AUTH_REJECT_MIN) {
        if (ID_AUTH_AUTOREJECT) {
            return ['score' => $score, 'check' => id_check('authenticity', 'fail', $detail, 'hard',
                'Our automatic check found strong signs that this image was edited or computer-generated. Please photograph the physical ID again, or request a manual review below.')];
        }
        return ['score' => $score, 'check' => id_check('authenticity', 'warn', $detail . ' (Above the auto-reject line, but authenticity auto-reject is switched off.)', 'soft', '', 'high')];
    }
    if ($score >= ID_AUTH_SUSPICIOUS_MIN) {
        return ['score' => $score, 'check' => id_check('authenticity', 'warn', $detail, 'soft', '', $score >= 70 ? 'high' : 'medium')];
    }
    return ['score' => $score, 'check' => id_check('authenticity', 'pass', $detail . ' (No red flags is not proof the ID is genuine.)')];
}

/** Photo of a screen / photocopy => admin. */
function check_capture_type(?array $forensics, ?array $vision): array
{
    $hits = [];
    if (in_array('screenshot', $forensics['flags'] ?? [], true)) $hits[] = 'file metadata/size suggests a screenshot';
    if ($vision && !empty($vision['screen_photo'])) $hits[] = 'vision model thinks it is a photo of a screen';
    if ($vision && !empty($vision['photocopy'])) $hits[] = 'vision model thinks it is a photocopy/printout';
    if (!$hits) return id_check('capture_type', 'pass', 'No sign of a screenshot, screen photo or photocopy.');
    return id_check('capture_type', 'warn', 'Possible screen photo / photocopy: ' . implode('; ', $hits) . '.', 'soft', '', 'medium');
}

/* ===================================================================
 * Decision
 * =================================================================== */

/**
 * @param array[] $checks
 * @return array{outcome:string, risk_level:string, reasons:string[]}
 */
function id_decide(array $checks, array $fmt): array
{
    $hard = $needsAdmin = $softFlags = [];
    foreach ($checks as $c) {
        if ($c['result'] === 'fail' && $c['severity'] === 'hard') {
            $hard[] = $c;
        } elseif ($c['result'] === 'fail' || $c['result'] === 'warn') {
            $needsAdmin[] = $c;
            $softFlags[] = $c;
        } elseif ($c['result'] === 'skip') {
            $needsAdmin[] = $c;
        }
    }

    if ($hard) {
        $reasons = [];
        foreach ($hard as $c) $reasons[] = $c['user_msg'] !== '' ? $c['user_msg'] : $c['detail'];
        return ['outcome' => 'reject', 'risk_level' => 'high', 'reasons' => $reasons];
    }

    $reasons = [];
    foreach ($needsAdmin as $c) $reasons[] = $c['detail'];

    $eligible = !$needsAdmin && !$fmt['always_admin'] && $fmt['strength'] === 'strong';
    if ($eligible && ID_AUTOPASS_REQUIRES_OCR) {
        $by = [];
        foreach ($checks as $c) $by[$c['name']] = $c['result'];
        if (($by['ocr_number'] ?? '') !== 'pass' || ($by['ocr_expiry'] ?? '') !== 'pass') {
            $eligible = false;
            $reasons[] = 'Every check passed, but automatic approval needs the typed ID number and expiry confirmed by OCR/vision (switched off, or it could not read them).';
        }
    }
    if ($eligible) {
        return ['outcome' => 'pass', 'risk_level' => 'low', 'reasons' => ['All automatic checks passed.']];
    }
    $risk = ($softFlags || $fmt['always_admin']) ? 'medium' : 'low';
    foreach ($softFlags as $c) {
        if (($c['risk'] ?? '') === 'high') { $risk = 'high'; break; }
    }
    return ['outcome' => 'admin', 'risk_level' => $risk, 'reasons' => $reasons ?: ['Sent for manual review.']];
}

/* ===================================================================
 * Admin notifications for automatic decisions (in-app inbox + optional email)
 * =================================================================== */

/**
 * Tell staff (admins + managers) when the SYSTEM approved or declined someone, and when a high-risk
 * submission needs review. Never throws. Appears in the existing staff inbox and links to the
 * Verifications tab ("System decisions" list). Optional email: SAFETRADE_ID_NOTIFY_EMAIL=1.
 */
function id_notify_staff(mysqli $conn, int $userId, string $outcome, string $risk, array $reasons, int $attemptNo): void
{
    try {
        $stmt = $conn->prepare('SELECT first_name, last_name FROM users WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $u = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $name = $u ? trim($u['first_name'] . ' ' . $u['last_name']) : ('User #' . $userId);
        $why = mb_substr(implode(' • ', array_filter(array_map('trim', $reasons))), 0, 480);

        if ($outcome === 'pass') {
            $title = 'Auto-approved: ' . $name;
            $body  = 'The system approved this account automatically — every ID check passed (risk ' . $risk . '). You can revoke it under Verifications → System decisions.';
            $type  = 'staff_id_auto_pass';
        } elseif ($outcome === 'reject') {
            $title = 'Auto-declined: ' . $name . ' (attempt ' . $attemptNo . ' of ' . ID_MAX_ATTEMPTS . ')';
            $body  = 'The system declined this ID and kept the account so the user can retry. Reason: ' . $why . ' — you can approve it anyway under Verifications → System decisions.';
            $type  = 'staff_id_auto_reject';
        } elseif ($outcome === 'admin' && $risk === 'high') {
            $title = 'High-risk ID needs review: ' . $name;
            $body  = 'Sent to manual review. ' . $why;
            $type  = 'staff_id_high_risk';
        } else {
            return;   // ordinary "needs review" cases are already announced by the registration notification
        }
        if (function_exists('inbox_push_staff')) {
            inbox_push_staff('account', $title, $body, '/admin/dashboard.php?tab=verifications', $userId, $type);
        }
        if (getenv('SAFETRADE_ID_NOTIFY_EMAIL') === '1' && function_exists('send_branded_email') && function_exists('inbox_staff_ids')) {
            $ids = inbox_staff_ids();
            if ($ids) {
                $in = implode(',', array_map('intval', $ids));
                $res = $conn->query("SELECT email, first_name FROM users WHERE id IN ($in)");
                while ($r = $res->fetch_assoc()) {
                    try {
                        send_branded_email($r['email'], $r['first_name'], $title, $title, e($body), app_url('admin/dashboard.php?tab=verifications'), 'Open Verifications');
                    } catch (Throwable $e) { error_log('[SafeTrade] staff email skipped: ' . $e->getMessage()); }
                }
            }
        }
    } catch (Throwable $e) {
        error_log('[SafeTrade] id_notify_staff failed: ' . $e->getMessage());
    }
}

/* ===================================================================
 * Persistence
 * =================================================================== */

function id_screening_save(
    mysqli $conn, int $userId, int $govRowId, int $attemptNo, string $outcome, string $risk,
    ?string $detectedType, array $extracted, array $checks, array $reasons, string $source = 'auto', ?int $authScore = null, ?float $faceDistance = null
): int {
    $extJson  = json_encode($extracted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $chkJson  = json_encode($checks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $reasonTx = implode("\n", $reasons);
    $stmt = $conn->prepare(
        'INSERT INTO id_screenings (user_id, government_id_id, attempt_no, source, outcome, risk_level, detected_id_type, extracted_json, checks_json, reasons, authenticity_score, face_distance)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $stmt->bind_param('iiisssssssid', $userId, $govRowId, $attemptNo, $source, $outcome, $risk, $detectedType, $extJson, $chkJson, $reasonTx, $authScore, $faceDistance);
    $stmt->execute();
    $id = (int) $conn->insert_id;
    $stmt->close();
    return $id;
}

/** Quality-gate failure. $userId may be null when the account has not been created yet (then only the audit log gets it). */
function id_screening_record_retry(mysqli $conn, ?int $userId, int $attemptNo, array $gate): void
{
    $reasons = $gate['issues'];
    audit_log($userId, 'system.id_screening.retry', 'user', $userId, implode(' | ', $reasons) . ' ' . json_encode($gate['metrics']));
    if ($userId !== null && $userId > 0) {
        try {
            id_screening_save($conn, $userId, 0, $attemptNo, 'retry', 'low', null, ['quality' => $gate['metrics']],
                [id_check('quality_gate', 'fail', implode(' ', $reasons), 'soft', implode(' ', $reasons))], $reasons);
        } catch (Throwable $e) {
            error_log('[SafeTrade] id_screening retry log failed: ' . $e->getMessage());
        }
    }
}

function id_screening_latest(mysqli $conn, int $userId, ?string $outcome = null): ?array
{
    try {
        if ($outcome === null) {
            $stmt = $conn->prepare('SELECT * FROM id_screenings WHERE user_id = ? AND outcome <> \'retry\' ORDER BY id DESC LIMIT 1');
            $stmt->bind_param('i', $userId);
        } else {
            $stmt = $conn->prepare('SELECT * FROM id_screenings WHERE user_id = ? AND outcome = ? ORDER BY id DESC LIMIT 1');
            $stmt->bind_param('is', $userId, $outcome);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;   // migration not run yet
    }
}

/**
 * The account (if any) that is currently allowed to re-upload: the one stored in the session
 * (set after an auto-reject in the same session, or by a successful password login) whose
 * verification_status is 'rejected'.
 */
function id_screen_resume_user(mysqli $conn): ?array
{
    $uid = (int) ($_SESSION['reg_id_user_id'] ?? 0);
    if ($uid <= 0) return null;
    try {
        $stmt = $conn->prepare(
            "SELECT id, first_name, last_name, email, id_attempts FROM users
             WHERE id = ? AND status = 'pending_verification' AND verification_status = 'rejected' LIMIT 1"
        );
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/* ===================================================================
 * Entry point
 * =================================================================== */

/**
 * Screen one saved government_ids row.
 *
 * $context keys:
 *   declared        array{id_number:string, expiry:string(Y-m-d or ''), mrz:string[] (2 lines or [])}
 *   attempt_no      int   (1-based; which auto-reject attempt this is)
 *   registered_name string (for the admin "typed vs extracted name" display)
 *
 * Writes id_screenings + the audit log and updates users.status / verification_status / id_attempts.
 * Never deletes anything.
 *
 * @return array{outcome:string, risk_level:string, reasons:string[], checks:array[], screening_id:int}
 */
function screen_government_id(mysqli $conn, int $userId, int $govIdRow, array $context): array
{
    $attemptNo = max(1, (int) ($context['attempt_no'] ?? 1));
    $declared  = $context['declared'] ?? [];

    $stmt = $conn->prepare('SELECT id, id_type, image_hash, image_phash FROM government_ids WHERE id = ? AND user_id = ? LIMIT 1');
    $stmt->bind_param('ii', $govIdRow, $userId);
    $stmt->execute();
    $gov = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$gov) {
        throw new RuntimeException('government_ids row not found for screening.');
    }

    $typeKey = id_format_key_for_label((string) $gov['id_type']);
    $fmt     = $typeKey !== null ? id_formats()[$typeKey] : id_formats()['other'];
    $typeKey ??= 'other';

    $checks = [];
    $extracted = ['typed_name' => (string) ($context['registered_name'] ?? '')];
    $detectedType = null;
    $authScore = null;
    $faceDistance = null;

    if (!ID_SCREEN_ENABLED) {
        $checks[] = id_check('screening', 'skip', 'Automatic screening is switched off (SAFETRADE_ID_SCREEN=0) — manual review.');
        $decision = ['outcome' => 'admin', 'risk_level' => 'medium', 'reasons' => ['Automatic screening is switched off — manual review.']];
    } else {
        $normNumber = id_normalize_number((string) ($declared['id_number'] ?? ''));
        $numberHash = $normNumber !== '' ? id_number_hash($typeKey, $normNumber) : null;
        if ($numberHash !== null) {
            $stmt = $conn->prepare('UPDATE government_ids SET id_number_hash = ? WHERE id = ?');
            $stmt->bind_param('si', $numberHash, $govIdRow);
            $stmt->execute();
            $stmt->close();
        }
        $extracted['id_number_masked'] = id_mask_number($normNumber);
        $extracted['expiry_typed']     = (string) ($declared['expiry'] ?? '');

        $effectiveExpiry = (string) ($declared['expiry'] ?? '');

        // --- Passport MRZ (math) — also provides a stronger expiry date than the typed one ---
        $mrzFields = [];
        if ($typeKey === 'passport') {
            $m = check_passport_mrz((array) ($declared['mrz'] ?? []), $normNumber);
            $mrzFields = $m['fields'];
            $checks[] = $m['check'];
            if ($mrzFields) {
                $extracted['mrz'] = [
                    'surname' => $mrzFields['surname'], 'given_names' => $mrzFields['given_names'],
                    'dob' => $mrzFields['dob'], 'expiry' => $mrzFields['expiry'],
                    'issuer' => $mrzFields['issuer'], 'sex' => $mrzFields['sex'],
                ];
                if ($m['check']['result'] === 'pass' && $mrzFields['expiry'] !== null) {
                    if ($effectiveExpiry !== '' && $effectiveExpiry !== $mrzFields['expiry']) {
                        $checks[] = id_check('expiry_consistency', 'warn', 'Typed expiry ' . $effectiveExpiry . ' differs from the MRZ expiry ' . $mrzFields['expiry'] . '.');
                    }
                    $effectiveExpiry = $mrzFields['expiry'];   // check-digit-verified, so it wins
                }
            }
        }
        $extracted['expiry_used'] = $effectiveExpiry;

        $checks[] = check_number_format($typeKey, $normNumber);
        $checks[] = check_id_type_match($typeKey, $normNumber);
        $checks[] = check_expiry($effectiveExpiry, $fmt);
        $checks[] = check_duplicate_number($conn, $userId, $numberHash);
        $checks[] = check_duplicate_image($conn, $userId, (string) $gov['image_hash'], (string) $gov['image_phash']);
        $checks[] = check_id_strength($fmt);

        // ---------------- PHASE 2: names, OCR / vision cross-checks, authenticity ----------------
        // Optional evidence. Failure here must never stop the screening (everything is try/caught).
        $p2 = $context['phase2'] ?? null;                       // injectable for tests
        $forensics = $context['forensics'] ?? null;            // computed from the RAW upload in register.php
        if ($p2 === null && (id_ocr_enabled() || id_vision_enabled())) {
            try {
                $img = $conn->prepare('SELECT front_data, front_mime FROM government_ids WHERE id = ? LIMIT 1');
                $img->bind_param('i', $govIdRow);
                $img->execute();
                $imgRow = $img->get_result()->fetch_assoc();
                $img->close();
                if ($imgRow && !empty($imgRow['front_data'])) {
                    $p2 = id_phase2_gather((string) $imgRow['front_data'], (string) ($imgRow['front_mime'] ?: 'image/jpeg'), $fmt['label']);
                }
            } catch (Throwable $e) {
                error_log('[SafeTrade] phase2 gather failed: ' . $e->getMessage());
            }
        }
        $vision  = is_array($p2) ? ($p2['vision'] ?? null) : null;
        $ocrText = is_array($p2) ? ($p2['text'] ?? null) : null;

        $checks[] = check_name_match((string) ($context['registered_name'] ?? ''), $mrzFields ?: null, $vision, $ocrText);
        foreach (check_ocr_consistency($typeKey, $normNumber, $effectiveExpiry, $vision, $ocrText) as $c) $checks[] = $c;
        $auth = check_authenticity($forensics, $vision);
        $checks[] = $auth['check'];
        $checks[] = check_capture_type($forensics, $vision);
        $authScore = $auth['score'];

        // What the ID looks like it is (for the admin panel).
        $detKeys = [];
        if ($vision && ($vision['id_type'] ?? 'unknown') !== 'unknown') $detKeys[] = $vision['id_type'];
        if ($ocrText !== null) $detKeys = array_merge($detKeys, id_ocr_detect_types($ocrText));
        $detKeys = array_values(array_unique($detKeys));
        if ($detKeys) $detectedType = implode(', ', array_map(static fn(string $k): string => id_formats()[$k]['label'] ?? $k, $detKeys));

        $extracted['phase2'] = [
            'provider'   => is_array($p2) ? ($p2['provider'] ?? 'none') : 'none',
            'errors'     => is_array($p2) ? ($p2['errors'] ?? []) : [],
            'forensics'  => $forensics ? ['score' => $forensics['score'], 'reasons' => $forensics['reasons']] : null,
            'authenticity_score' => $authScore,
            'vision'     => $vision ? [
                'id_type' => $vision['id_type'], 'name' => $vision['full_name'] ?? trim((string) ($vision['given_names'] ?? '') . ' ' . (string) ($vision['surname'] ?? '')),
                'id_number_masked' => !empty($vision['id_number']) ? id_mask_number(id_normalize_number((string) $vision['id_number'])) : null,
                'expiry' => $vision['expiry_date'], 'risk' => $vision['risk'], 'tampering' => $vision['tampering'], 'notes' => $vision['notes'],
            ] : null,
        ];

        // ---------------- PHASE 3: face on the ID vs the registered face ----------------
        $idFace = $context['id_face'] ?? ['status' => 'missing', 'descriptor' => null, 'score' => null, 'box_w' => null, 'faces' => 0];
        $registeredFace = null;
        try { $registeredFace = get_face_embedding($conn, $userId); } catch (Throwable $e) { error_log('[SafeTrade] get_face_embedding failed: ' . $e->getMessage()); }
        if ($idFace['descriptor'] !== null) {
            try {   // kept for cross-account duplicate detection (needs the Phase 3 migration; harmless if missing)
                $dj = json_encode($idFace['descriptor']);
                $stmt = $conn->prepare('UPDATE government_ids SET id_face_descriptor = ? WHERE id = ?');
                $stmt->bind_param('si', $dj, $govIdRow);
                $stmt->execute();
                $stmt->close();
            } catch (Throwable $e) { /* column not created yet */ }
        }
        $faceRes = check_face_match($registeredFace, $idFace);
        if (!ID_SCREEN_AUTOPASS_REQUIRES_FACE && $faceRes['check']['result'] === 'skip') {
            $faceRes['check'] = id_check('face_match', 'na', 'Face matching not required (testing mode).');
        }
        $checks[] = $faceRes['check'];
        $faceDistance = $faceRes['distance'];
        $checks[] = check_face_other_accounts($conn, $userId, $idFace['descriptor']);
        $extracted['face'] = ['status' => $idFace['status'], 'faces_on_id' => $idFace['faces'], 'detector_score' => $idFace['score'], 'face_width_px' => $idFace['box_w']];

        $decision = id_decide($checks, $fmt);
    }

    // Random quality audit: a sample of would-be auto-approvals still goes to an admin.
    if ($decision['outcome'] === 'pass' && ID_AUDIT_RATE > 0) {
        $roll = isset($context['audit_roll']) ? (float) $context['audit_roll'] : (mt_rand() / mt_getrandmax());
        if ($roll < ID_AUDIT_RATE) {
            $checks[] = id_check('random_audit', 'warn', 'Selected for a random quality audit (' . round(ID_AUDIT_RATE * 100) . '% of would-be auto-approvals).');
            $decision = ['outcome' => 'admin', 'risk_level' => 'low', 'reasons' => ['Random quality audit: this submission passed every automatic check; an admin double-checks a sample.']];
        }
    }

    // ---- persist + apply ----
    $outcome = $decision['outcome'];
    $screeningId = id_screening_save($conn, $userId, $govIdRow, $attemptNo, $outcome, $decision['risk_level'],
        $detectedType, $extracted, $checks, $decision['reasons'], 'auto', $authScore, $faceDistance);

    if ($outcome === 'pass') {
        $stmt = $conn->prepare("UPDATE users SET status = 'active', verification_status = 'approved' WHERE id = ? AND status = 'pending_verification'");
    } elseif ($outcome === 'reject') {
        $stmt = $conn->prepare("UPDATE users SET verification_status = 'rejected', id_attempts = id_attempts + 1 WHERE id = ? AND status = 'pending_verification'");
    } else {
        $stmt = $conn->prepare("UPDATE users SET verification_status = 'pending' WHERE id = ? AND status = 'pending_verification'");
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();

    audit_log(null, 'system.id_screening.' . $outcome, 'user', $userId,
        'attempt=' . $attemptNo . ' risk=' . $decision['risk_level'] . ' type=' . $typeKey . ' | ' . implode(' | ', $decision['reasons']));

    id_notify_staff($conn, $userId, $outcome, $decision['risk_level'], $decision['reasons'], $attemptNo);

    return [
        'outcome'      => $outcome,
        'risk_level'   => $decision['risk_level'],
        'reasons'      => $decision['reasons'],
        'checks'       => $checks,
        'screening_id' => $screeningId,
    ];
}
