<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/face_recognition.php';

function jexit(array $payload, int $code = 200): never
{
    while (ob_get_level() > 0) { @ob_end_clean(); }   // nothing but JSON may leave this file
    http_response_code($code);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($payload);
    exit;
}

set_error_handler(function ($no, $str, $file, $line) {
    error_log("[Face API] $str in $file:$line");
    return true;
});

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jexit(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

$raw = [];
if (strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $raw = json_decode((string) file_get_contents('php://input'), true) ?: [];
} else {
    $raw = $_POST;
}

if (!verify_csrf($raw['csrf_token'] ?? null)) {
    jexit(['ok' => false, 'error' => 'csrf',
           'message' => 'Your session expired. Please refresh the page and try again.'], 419);
}

$action    = (string) ($raw['action'] ?? '');
$embedding = face_normalize_embedding($raw['embedding'] ?? null);

try {
    $conn = db();

    switch ($action) {

        /* ---------- registration: face step ---------- */
        case 'register_face': {
            if (empty($_SESSION['pending_registration_data']) || empty($_SESSION['reg_email_verified'])) {
                jexit(['ok' => false, 'error' => 'no_pending_registration',
                       'message' => 'Your registration session expired — please sign up again.'], 400);
            }
            if ($embedding === null) {
                jexit(['ok' => false, 'error' => 'bad_embedding',
                       'message' => 'The face scan was not valid. Please try again.'], 422);
            }

            // Is this face already registered on ANY account (pending or approved)?
            if (find_closest_face_match($conn, $embedding, FACE_DUPLICATE_THRESHOLD)) {
                unset($_SESSION['pending_face_embedding'], $_SESSION['pending_face_snapshot']);
                jexit(['ok' => false, 'error' => 'duplicate_face',
                       'message' => 'Face already registered. This face is already associated with another SafeTrade account.']);
            }

            $snapshot = $raw['snapshot'] ?? null;
            if (!is_string($snapshot) || strlen($snapshot) > 4 * 1024 * 1024
                || !preg_match('/^data:image\/(jpeg|jpg|png|webp);base64,/', $snapshot)) {
                $snapshot = null;
            }

            $_SESSION['pending_face_embedding'] = $embedding;
            $_SESSION['pending_face_snapshot']  = $snapshot;
            audit_log(0, 'user.face.registered_step', 'system', 0);
            jexit(['ok' => true]);
        }

        /* ---------- login by face ---------- */
        case 'login_with_face': {
            if ($embedding === null) {
                jexit(['ok' => false, 'error' => 'bad_embedding',
                       'message' => 'The face scan was not valid. Please try again.'], 422);
            }

            $rateKey = 'face:' . client_ip();
            if (is_login_rate_limited($rateKey, false)) {
                jexit(['ok' => false, 'error' => 'rate_limited',
                       'message' => 'Too many failed attempts. Please wait a few minutes, or use email + password.']);
            }

            $match = find_closest_face_match($conn, $embedding, FACE_MATCH_THRESHOLD);

            // Two different accounts almost equally close -> don't guess.
            if ($match && $match['runner_up'] !== null
                && $match['runner_up'] <= FACE_MATCH_THRESHOLD
                && ($match['runner_up'] - $match['distance']) < FACE_AMBIGUITY_MARGIN) {
                $match = null;
            }

            if (!$match) {
                record_login_attempt($rateKey, false, false);
                jexit(['ok' => false, 'error' => 'no_match',
                       'message' => 'Face not recognized. Make sure you registered your face, look straight at the camera in good light, or use email + password.']);
            }

            $stmt = $conn->prepare(
                'SELECT id, first_name, last_name, email, role, status, verification_status
                 FROM users WHERE id = ? LIMIT 1'
            );
            $stmt->bind_param('i', $match['user_id']);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$user) {
                record_login_attempt($rateKey, false, false);
                jexit(['ok' => false, 'error' => 'no_match', 'message' => 'Face not recognized.']);
            }

            $statusError = login_status_error($user);
            if ($statusError !== null) {
                record_login_attempt($rateKey, false, false);
                jexit(['ok' => false, 'error' => 'account_blocked', 'message' => $statusError]);
            }

            record_login_attempt($rateKey, true, false);
            login_start_session($user);
            audit_log((int) $user['id'], 'user.login.face', 'user', (int) $user['id'],
                      'distance=' . round($match['distance'], 4));

            jexit(['ok' => true, 'redirect' => home_path_for($user)]);
        }

        default:
            jexit(['ok' => false, 'error' => 'unknown_action'], 400);
    }
} catch (Throwable $e) {
    error_log('[Face API] ' . $action . ' failed: ' . $e->getMessage());
    jexit(['ok' => false, 'error' => 'exception',
           'message' => 'A server error occurred during face verification. Please try again.'], 500);
}