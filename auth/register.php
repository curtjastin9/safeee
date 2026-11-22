<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/partials/ui.php';
require_once __DIR__ . '/../includes/image_helper.php';
require_once __DIR__ . '/../includes/face_recognition.php';
require_once __DIR__ . '/../includes/id_screening.php';

if (is_logged_in()) {
    redirect('/dashboard.php');
}

function validate_registration_data(string $firstName, string $lastName, string $email, string $phone, string $password, string $passwordConfirm): array
{
    $errors = [];
    $firstName = clean_input($firstName);
    $lastName  = clean_input($lastName);
    $email     = strtolower(clean_input($email));
    $phone     = clean_input($phone);
    if (!is_valid_name($firstName)) $errors[] = 'Please enter a valid first name.';
    if (!is_valid_name($lastName))  $errors[] = 'Please enter a valid last name.';
    if (!is_valid_email($email))    $errors[] = 'Please enter a valid email address.';
    if (!is_valid_phone($phone))    $errors[] = 'Please enter a valid phone number.';
    if ($password !== $passwordConfirm) $errors[] = 'Passwords do not match.';
    $errors = array_merge($errors, password_policy_errors($password));
    if ($errors) return ['success' => false, 'errors' => $errors];
    $conn = db();
    $stmt = $conn->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    if ($stmt->get_result()->fetch_assoc()) {
        $stmt->close();
        return ['success' => false, 'errors' => ['An account with that email already exists.']];
    }
    $stmt->close();
    return ['success' => true, 'errors' => []];
}

function save_face_snapshot(int $userId, mixed $dataUrl): ?array
{
    if (!is_string($dataUrl) || $dataUrl === '') return null;
    if (!preg_match('/^data:image\/(jpeg|jpg|png|webp);base64,/', $dataUrl, $m)) return null;
    $commaPos = strpos($dataUrl, ',');
    if ($commaPos === false) return null;
    $binary = base64_decode(substr($dataUrl, $commaPos + 1), true);
    if ($binary === false || strlen($binary) === 0 || strlen($binary) > MAX_UPLOAD_MB * 1024 * 1024) return null;
    $tmpPath = tempnam(sys_get_temp_dir(), 'facesnap_');
    if ($tmpPath === false || file_put_contents($tmpPath, $binary) === false) return null;
    $result = compress_image_to_blob($tmpPath);
    @unlink($tmpPath);
    return $result['success'] ? ['data' => $result['data'], 'mime' => $result['mime']] : null;
}

function save_verification_photo(array $file, int $userId, string $destDir): array
{
    $allowedMime = ['image/jpeg', 'image/png', 'image/webp'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)
        return ['success' => false, 'data' => null, 'mime' => null, 'error' => 'upload failed'];
    $size = (int)($file['size'] ?? 0);
    $maxBytes = MAX_UPLOAD_MB * 1024 * 1024;
    if ($size <= 0 || $size > $maxBytes)
        return ['success' => false, 'data' => null, 'mime' => null, 'error' => 'file is over the ' . MAX_UPLOAD_MB . 'MB limit'];
    $info = @getimagesize($file['tmp_name']);
    if ($info === false || !in_array($info['mime'], $allowedMime, true))
        return ['success' => false, 'data' => null, 'mime' => null, 'error' => 'not a recognized JPG/PNG/WEBP image'];
    $result = compress_image_to_blob($file['tmp_name']);
    if (!$result['success'])
        return ['success' => false, 'data' => null, 'mime' => null, 'error' => $result['error'] ?? 'could not be processed'];
    return ['success' => true, 'data' => $result['data'], 'mime' => $result['mime'], 'error' => null];
}

$errors   = [];
$idErrors = [];
$old = ['first_name' => '', 'last_name' => '', 'email' => '', 'phone' => ''];

$requestedStep = $_GET['step'] ?? '';
$step = 'form';
if ($requestedStep === 'face' && !empty($_SESSION['pending_registration_data']) && !empty($_SESSION['reg_email_verified'])) {
    $step = 'face';
} elseif ($requestedStep === 'id' && !empty($_SESSION['pending_registration_data']) && !empty($_SESSION['reg_email_verified'])) {
    if (empty($_SESSION['pending_face_embedding'])) {
        flash_set('error', 'Please verify your face first — it\'s a required step.');
        redirect('/auth/register.php?step=face');
    }
    $step = 'id';
} elseif ($requestedStep === 'id' && id_screen_resume_user(db()) !== null) {
    // Account already exists and its ID was auto-rejected (or an admin asked for a re-upload) — allow re-upload.
    $step = 'id';
}

const REG_OTP_TTL = 180; // 3 minutes
const REG_OTP_MAX = 3;   // 3 attempts

function reg_otp_abort(string $msg): never
{
    unset($_SESSION['pending_registration_data'], $_SESSION['reg_otp'], $_SESSION['reg_email_verified'],
          $_SESSION['pending_face_embedding'], $_SESSION['pending_face_snapshot']);
    flash_set('error', $msg);
    redirect('/auth/register.php');
}

function reg_otp_send(array $d): bool
{
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $_SESSION['reg_otp'] = [
        'hash'     => password_hash($code, PASSWORD_BCRYPT, ['cost' => 10]),
        'expires'  => time() + REG_OTP_TTL,
        'attempts' => 0,
    ];
    unset($_SESSION['reg_email_verified']);
    return send_branded_email(
        $d['email'], $d['first_name'], 'Your SafeTrade verification code', 'Verify your email',
        'Hi ' . e($d['first_name']) . ',<br><br>Use this code to finish creating your account. It expires in '
        . '<strong>3 minutes</strong> and can be entered <strong>' . REG_OTP_MAX . ' times</strong>:<br>'
        . '<div style="font-family:monospace;font-size:30px;font-weight:700;letter-spacing:.3em;color:#155943;margin:14px 0;">'
        . e($code) . '</div>If you didn\'t try to register, ignore this email.'
    );
}

$otpError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['reg_otp_code']) || isset($_POST['reg_otp_cancel']))) {
    require_csrf();
    if (isset($_POST['reg_otp_cancel'])) reg_otp_abort('Registration cancelled.');
    $o = $_SESSION['reg_otp'] ?? null;
    if (empty($_SESSION['pending_registration_data']) || !$o) reg_otp_abort('Your registration session expired. Please start over.');
    if (time() >= $o['expires']) reg_otp_abort('The code expired. Registration was cancelled — please start again.');
    $code = preg_replace('/\D/', '', (string) $_POST['reg_otp_code']);
    if (strlen($code) === 6 && password_verify($code, $o['hash'])) {
        $_SESSION['reg_email_verified'] = true;
        unset($_SESSION['reg_otp']);
        audit_log(0, 'user.register.email_verified', 'system', 0);
        redirect('/auth/register.php?step=face');
    }
    $_SESSION['reg_otp']['attempts']++;
    if ($_SESSION['reg_otp']['attempts'] >= REG_OTP_MAX) reg_otp_abort('Too many incorrect codes. Registration was cancelled.');
    $otpError = 'Incorrect code. ' . (REG_OTP_MAX - $_SESSION['reg_otp']['attempts']) . ' attempt(s) left.';
}

/* ===================== Step 1: account form ===================== */
if ($step === 'form' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    // If user declined T&C, block registration entirely
    if (($_POST['tc_agreed'] ?? '') !== '1') {
        $errors[] = 'You must agree to the Terms & Conditions to create an account.';
    } else {
        $old['first_name'] = clean_input($_POST['first_name'] ?? '');
        $old['last_name']  = clean_input($_POST['last_name'] ?? '');
        $old['email']      = clean_input($_POST['email'] ?? '');
        $old['phone']      = clean_input($_POST['phone'] ?? '');
        $password          = (string)($_POST['password'] ?? '');
        $passwordConfirm   = (string)($_POST['password_confirm'] ?? '');
        $result = validate_registration_data($old['first_name'], $old['last_name'], $old['email'], $old['phone'], $password, $passwordConfirm);
        if ($result['success']) {
            $_SESSION['pending_registration_data'] = [
                'first_name' => $old['first_name'],
                'last_name'  => $old['last_name'],
                'email'      => $old['email'],
                'phone'      => $old['phone'],
                'password'   => $password,
            ];
            if (!reg_otp_send($_SESSION['pending_registration_data'])) {
                reg_otp_abort("We couldn't send the verification email: " . (smtp_last_error() ?? 'unknown error'));
            }
        }
        $errors = $result['errors'];
    }
}

/* ===================== Step 3: government ID (with automatic screening) ===================== */
$idOld        = ['id_type' => '', 'id_number' => '', 'id_expiry' => '', 'mrz' => ''];
$idRetryNotes = [];     // quality-gate problems: "please retake" — not a rejection and NOT counted as an attempt
$idResume     = null;   // set when an existing account that was auto-rejected is re-uploading
if ($step === 'id') {
    $idResume = id_screen_resume_user(db());
}

/* ---- Appeal: "please have an admin review this" ---- */
if ($step === 'id' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id_appeal'])) {
    require_csrf();
    $appealMsg = trim(clean_input($_POST['appeal_message'] ?? ''));
    if ($idResume === null) {
        $idErrors[] = 'Your session expired. Please log in again to continue.';
    } elseif (mb_strlen($appealMsg) < 10) {
        $idErrors[] = 'Please write at least a short sentence explaining why this decision should be reviewed.';
    } else {
        try {
            $conn = db();
            $appealUid  = (int) $idResume['id'];
            $lastReject = id_screening_latest($conn, $appealUid, 'reject');
            $screeningId = $lastReject ? (int) $lastReject['id'] : null;
            $appealMsg  = mb_substr($appealMsg, 0, 1000);
            $stmt = $conn->prepare('INSERT INTO id_appeals (user_id, screening_id, message) VALUES (?,?,?)');
            $stmt->bind_param('iis', $appealUid, $screeningId, $appealMsg);
            $stmt->execute();
            $stmt->close();
            // Back into the admin Verifications queue.
            $stmt = $conn->prepare("UPDATE users SET verification_status = 'pending' WHERE id = ? AND status = 'pending_verification'");
            $stmt->bind_param('i', $appealUid);
            $stmt->execute();
            $stmt->close();
            audit_log($appealUid, 'user.id_appeal.submitted', 'user', $appealUid, mb_substr($appealMsg, 0, 300));
            if (function_exists('inbox_push_staff')) {
                try {
                    inbox_push_staff('account', 'ID review requested: ' . trim($idResume['first_name'] . ' ' . $idResume['last_name']),
                        'A user appealed an automatic ID rejection.', '/admin/dashboard.php?tab=verifications', $appealUid, 'staff_id_appeal');
                } catch (Throwable $e) { error_log('[SafeTrade] appeal inbox push skipped: ' . $e->getMessage()); }
            }
            unset($_SESSION['reg_id_user_id']);
            flash_set('success', 'Your request was sent to an admin. You can log in once your account is approved.');
            redirect('/auth/login.php');
        } catch (Throwable $e) {
            error_log('[SafeTrade] id appeal failed: ' . $e->getMessage());
            $idErrors[] = 'Something went wrong sending your request. Please try again.';
        }
    }
}

/* ---- Upload / re-upload ---- */
if ($step === 'id' && $_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['id_appeal'])) {
    require_csrf();
    $conn = db();
    $isResume     = $idResume !== null;
    $attemptsUsed = $isResume ? (int) $idResume['id_attempts'] : 0;
    if ($isResume && $attemptsUsed >= ID_MAX_ATTEMPTS) {
        $idErrors[] = 'You have used all ' . ID_MAX_ATTEMPTS . ' upload attempts. Please request a manual review below.';
    }

    $idOld['id_type']   = clean_input($_POST['id_type'] ?? '');
    $idOld['id_number'] = clean_input($_POST['id_number'] ?? '');
    $idOld['id_expiry'] = clean_input($_POST['id_expiry'] ?? '');
    $idOld['mrz']       = trim((string) ($_POST['mrz'] ?? ''));
    $typeKey   = id_format_key_for_label($idOld['id_type']);
    $fmt       = $typeKey !== null ? id_formats()[$typeKey] : null;
    $idType    = $fmt['label'] ?? '';
    $normNumber = id_normalize_number($idOld['id_number']);
    $mrzLines  = [];

    if ($fmt === null) $idErrors[] = 'Please select an ID type.';
    if (($_POST['dpa_consent'] ?? '') !== '1') $idErrors[] = 'Please tick the consent box so we can process your ID and face data.';
    if (empty($_FILES['id_front']['name'])) $idErrors[] = 'Please upload the front of your ID.';
    if ($fmt !== null) {
        if ($fmt['requires_number'] && $normNumber === '') $idErrors[] = 'Please enter your ' . $fmt['number_label'] . '.';
        if (strlen($normNumber) > 40) $idErrors[] = 'The ID number you entered is too long.';
        if ($idOld['id_expiry'] === '') {
            if ($fmt['requires_expiry']) $idErrors[] = 'Please enter the expiry date shown on your ID.';
        } elseif (id_parse_date($idOld['id_expiry']) === null) {
            $idErrors[] = 'The expiry date is not a valid date.';
        }
        if ($fmt['requires_mrz']) {
            $mrzLines = id_mrz_clean_lines($idOld['mrz']);
            foreach (id_mrz_structure_errors($mrzLines) as $mrzErr) $idErrors[] = $mrzErr;
        }
    }

    $frontResult = $backResult = null;
    if (!$idErrors) {
        $frontResult = save_verification_photo($_FILES['id_front'], 0, '');
        if (!$frontResult['success']) $idErrors[] = 'Front photo: ' . $frontResult['error'];
    }
    if (!$idErrors && !empty($_FILES['id_back']['name'])) {
        $backResult = save_verification_photo($_FILES['id_back'], 0, '');
        if (!$backResult['success']) $idErrors[] = 'Back photo: ' . $backResult['error'];
    }

    // STEP 0 — quality gate (before any account is created). Not fraud, not counted.
    if (!$idErrors && ID_SCREEN_ENABLED) {
        $gate = id_quality_gate($_FILES['id_front']['tmp_name']);
        if (!$gate['ok']) {
            $idRetryNotes = $gate['issues'];
            try {
                id_screening_record_retry($conn, $isResume ? (int) $idResume['id'] : null, $attemptsUsed + 1, $gate);
            } catch (Throwable $e) { error_log('[SafeTrade] retry log failed: ' . $e->getMessage()); }
        }
    }

    if (!$idErrors && !$idRetryNotes) {
        $hashes    = id_image_hashes($_FILES['id_front']['tmp_name']);
        $forensics = id_file_forensics($_FILES['id_front']['tmp_name']);   // Phase 2: raw-file metadata signals (weak, local)
        $created = false;
        $targetId = 0;
        $govRowId = 0;
        try {
            if (!$isResume) {
                $regData = $_SESSION['pending_registration_data'] ?? null;
                if (!is_array($regData)) throw new RuntimeException('registration session expired');
                $createResult = register_user($regData['first_name'], $regData['last_name'], $regData['email'], $regData['phone'], $regData['password'], $regData['password']);
                if (!$createResult['success']) {
                    $idErrors = $createResult['errors'];
                } else {
                    $targetId = (int) $createResult['user_id'];
                    $created  = true;
                    if (!empty($_SESSION['pending_face_embedding'])) {
                        try {
                            save_face_embedding($conn, $targetId, $_SESSION['pending_face_embedding']);
                            if (!empty($_SESSION['pending_face_snapshot'])) {
                                $snapshot = save_face_snapshot($targetId, $_SESSION['pending_face_snapshot']);
                                if ($snapshot !== null) {
                                    try {
                                        $stmt = $conn->prepare('UPDATE face_verifications SET selfie_data = ?, selfie_mime = ? WHERE user_id = ?');
                                        $stmt->bind_param('ssi', $snapshot['data'], $snapshot['mime'], $targetId);
                                        $stmt->execute();
                                        $stmt->close();
                                    } catch (Throwable $e) { error_log('[SafeTrade] face snapshot update failed: ' . $e->getMessage()); }
                                }
                            }
                            try {
                                $stmt = $conn->prepare('UPDATE users SET face_verified_at = NOW() WHERE id = ?');
                                $stmt->bind_param('i', $targetId);
                                $stmt->execute();
                                $stmt->close();
                            } catch (Throwable $e) { error_log('[SafeTrade] face_verified_at update failed: ' . $e->getMessage()); }
                            audit_log($targetId, 'user.face.registered', 'user', $targetId);
                        } catch (Throwable $e) { error_log('[SafeTrade] Face embedding save failed: ' . $e->getMessage()); }
                    }
                }
            } else {
                $targetId = (int) $idResume['id'];
            }

            if (!$idErrors) {
                // A re-upload REPLACES the previous row (delete + insert in one transaction).
                $emptyPath = '';
                $frontData = $frontResult['data'];
                $frontMime = $frontResult['mime'];
                $backData  = $backResult['data'] ?? null;
                $backMime  = $backResult['mime'] ?? null;
                $imgHash   = $hashes['sha256'] !== '' ? $hashes['sha256'] : null;
                $imgPhash  = $hashes['phash'];
                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare('DELETE FROM government_ids WHERE user_id = ?');
                    $stmt->bind_param('i', $targetId);
                    $stmt->execute();
                    $stmt->close();
                    $stmt = $conn->prepare('INSERT INTO government_ids (user_id, id_type, front_path, back_path, front_data, front_mime, back_data, back_mime, image_hash, image_phash) VALUES (?,?,?,?,?,?,?,?,?,?)');
                    $stmt->bind_param('isssssssss', $targetId, $idType, $emptyPath, $emptyPath, $frontData, $frontMime, $backData, $backMime, $imgHash, $imgPhash);
                    $stmt->execute();
                    $govRowId = (int) $conn->insert_id;
                    $stmt->close();
                    $stmt = $conn->prepare("UPDATE users SET verification_status = 'pending' WHERE id = ? AND status = 'pending_verification'");
                    $stmt->bind_param('i', $targetId);
                    $stmt->execute();
                    $stmt->close();
                    $conn->commit();
                } catch (Throwable $e) {
                    $conn->rollback();
                    throw $e;
                }
                audit_log($targetId, 'user.id_verification.submitted', 'user', $targetId);

                // ---- screening ----
                $regName = $isResume
                    ? trim($idResume['first_name'] . ' ' . $idResume['last_name'])
                    : trim(($_SESSION['pending_registration_data']['first_name'] ?? '') . ' ' . ($_SESSION['pending_registration_data']['last_name'] ?? ''));
                try {
                    $screen = screen_government_id($conn, $targetId, $govRowId, [
                        'declared'        => ['id_number' => $idOld['id_number'], 'expiry' => $idOld['id_expiry'], 'mrz' => $mrzLines],
                        'attempt_no'      => $attemptsUsed + 1,
                        'registered_name' => $regName,
                        'forensics'       => $forensics,
                        'id_face'         => id_face_parse_post($_POST),   // Phase 3: descriptor of the face ON the ID (from the browser)
                    ]);
                    $outcome = $screen['outcome'];
                } catch (Throwable $e) {
                    // Screening must never lose a submission: fall back to plain manual review.
                    error_log('[SafeTrade] screen_government_id failed: ' . $e->getMessage());
                    $outcome = 'admin';
                }

                // The account now exists — the registration session data is no longer needed.
                unset($_SESSION['pending_registration_data'], $_SESSION['pending_face_user_id'], $_SESSION['pending_face_embedding'], $_SESSION['pending_face_snapshot']);

                if ($outcome === 'reject') {
                    $_SESSION['reg_id_user_id'] = $targetId;   // lets this browser re-upload (reasons are loaded from the DB)
                    redirect('/auth/register.php?step=id');
                }
                unset($_SESSION['reg_id_user_id']);
                if ($outcome === 'pass') {
                    try {
                        $stmt = $conn->prepare('SELECT email, first_name FROM users WHERE id = ? LIMIT 1');
                        $stmt->bind_param('i', $targetId);
                        $stmt->execute();
                        $nu = $stmt->get_result()->fetch_assoc();
                        $stmt->close();
                        if ($nu) {
                            send_branded_email($nu['email'], $nu['first_name'], "You're verified on SafeTrade!", "You're verified!",
                                'Hi ' . e($nu['first_name']) . ',<br><br>Your ID passed our automatic checks and your account is now active.',
                                app_url('auth/login.php'), 'Log in to SafeTrade');
                        }
                    } catch (Throwable $e) { error_log('[SafeTrade] auto-pass email skipped: ' . $e->getMessage()); }
                    flash_set('success', 'Your ID passed our automatic checks — your account is active. You can log in now.');
                } else {
                    flash_set('success', "Account created! We're reviewing your ID and face verification — you'll be able to log in once an admin approves your account.");
                }
                redirect('/auth/login.php');
            }
        } catch (Throwable $e) {
            error_log('[SafeTrade] ID step failed: ' . $e->getMessage());
            if ($created && $targetId > 0 && $govRowId === 0) {
                // Account was created in this request but the ID could not be stored: undo it so the user can resubmit cleanly.
                try {
                    $delStmt = $conn->prepare('DELETE FROM users WHERE id = ? AND status = "pending_verification"');
                    $delStmt->bind_param('i', $targetId);
                    $delStmt->execute();
                    $delStmt->close();
                } catch (Throwable $e2) { error_log('[SafeTrade] cleanup failed: ' . $e2->getMessage()); }
            }
            $idErrors[] = 'Something went wrong while saving your ID. Please try again.';
        }
    }
}

$showOtp = $step === 'form' && !empty($_SESSION['reg_otp']) && !empty($_SESSION['pending_registration_data']) && empty($_SESSION['reg_email_verified']);
if ($showOtp && time() >= $_SESSION['reg_otp']['expires']) reg_otp_abort('The code expired. Registration was cancelled — please start again.');
$otpSecondsLeft = $showOtp ? max(0, $_SESSION['reg_otp']['expires'] - time()) : 0;
$otpAttemptsLeft = $showOtp ? REG_OTP_MAX - (int) $_SESSION['reg_otp']['attempts'] : 0;

/* ===================== Render ===================== */
if ($step === 'form') {
    $pageTitle = 'Create your account · SafeTrade';
    $assetBase = '..';
    require __DIR__ . '/../includes/partials/head.php';
?>
<style>
/* ---- Terms Modal Styles ---- */
.tc-modal-backdrop {
  display: none; position: fixed; inset: 0; z-index: 9000;
  background: rgba(10, 50, 33, 0.60); backdrop-filter: blur(4px);
  align-items: center; justify-content: center; padding: 16px;
}
.tc-modal-backdrop.show { display: flex; }
.tc-modal {
  background: var(--cream); border-radius: 22px;
  width: 100%; max-width: 560px; max-height: 90vh;
  display: flex; flex-direction: column;
  box-shadow: 0 24px 64px rgba(9,44,33,.30);
  overflow: hidden; animation: tc-rise 280ms cubic-bezier(.22,.75,.28,1) both;
}
@keyframes tc-rise { from { opacity:0; transform:translateY(14px) scale(.97); } to { opacity:1; transform:none; } }
.tc-modal-header {
  padding: 24px 26px 16px;
  border-bottom: 1px solid var(--line);
  flex-shrink: 0;
}
.tc-modal-header .tc-badge {
  display: inline-flex; align-items: center; gap: 7px;
  background: var(--brand-light); color: var(--brand-dark);
  font-size: 11px; font-weight: 700; letter-spacing: .05em;
  text-transform: uppercase; padding: 5px 11px; border-radius: 999px;
  margin-bottom: 12px;
}
.tc-modal-header h2 {
  font-family: 'Fraunces', serif; font-size: 20px;
  color: var(--ink); margin: 0; line-height: 1.2;
}
.tc-modal-header .tc-date {
  font-size: 11.5px; color: var(--ink-soft); margin-top: 5px;
}
.tc-modal-body {
  flex: 1; overflow-y: auto; padding: 20px 26px;
  scrollbar-width: thin; scrollbar-color: var(--line-strong) transparent;
}
.tc-modal-body section { margin-bottom: 20px; }
.tc-modal-body h3 {
  font-family: 'Fraunces', serif; font-size: 14px; color: var(--brand-dark);
  margin: 0 0 7px; display: flex; align-items: center; gap: 7px;
}
.tc-modal-body h3 .num {
  background: var(--brand); color: var(--cream);
  width: 22px; height: 22px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-family: 'IBM Plex Mono', monospace; font-size: 10px; font-weight: 700;
  flex-shrink: 0;
}
.tc-modal-body p, .tc-modal-body li {
  font-size: 13px; color: var(--ink-soft); line-height: 1.65; margin: 0;
}
.tc-modal-body ul { padding-left: 18px; margin: 6px 0 0; }
.tc-modal-body li { margin-bottom: 4px; }
.tc-scroll-hint {
  text-align: center; font-size: 11px; color: var(--ink-faint);
  padding: 8px 0 0; display: flex; align-items: center; justify-content: center; gap: 5px;
}
.tc-modal-footer {
  padding: 16px 26px 20px; border-top: 1px solid var(--line);
  flex-shrink: 0; display: flex; justify-content: flex-end;
}
.tc-proceed-btn {
  background: var(--brand); color: var(--cream); border: none;
  padding: 12px 24px; border-radius: 999px; font-weight: 600; font-size: 14px;
  cursor: pointer; transition: background .15s ease, transform .15s ease;
  box-shadow: 0 2px 8px rgba(9,44,33,.18);
}
.tc-proceed-btn:hover { background: var(--brand-dark); transform: translateY(-1px); }

/* ---- Second confirmation modal ---- */
.tc2-modal-backdrop {
  display: none; position: fixed; inset: 0; z-index: 9100;
  background: rgba(10, 50, 33, 0.65); backdrop-filter: blur(5px);
  align-items: center; justify-content: center; padding: 16px;
}
.tc2-modal-backdrop.show { display: flex; }
.tc2-modal {
  background: var(--cream); border-radius: 22px;
  width: 100%; max-width: 500px; max-height: 90vh;
  display: flex; flex-direction: column;
  box-shadow: 0 24px 64px rgba(9,44,33,.30);
  overflow: hidden; animation: tc-rise 260ms cubic-bezier(.22,.75,.28,1) both;
}
.tc2-modal-header { padding: 24px 26px 14px; border-bottom: 1px solid var(--line); flex-shrink: 0; }
.tc2-modal-header h2 { font-family: 'Fraunces', serif; font-size: 19px; color: var(--ink); margin: 0; }
.tc2-modal-header p { font-size: 12.5px; color: var(--ink-soft); margin-top: 6px; line-height: 1.55; }
.tc2-modal-body { flex: 1; overflow-y: auto; padding: 18px 26px; }
.tc2-check-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 10px; }
.tc2-check-list li {
  display: flex; align-items: flex-start; gap: 10px;
  font-size: 12.5px; color: var(--ink-soft); line-height: 1.55;
  background: var(--paper); border-radius: 10px; padding: 10px 13px;
  border: 1px solid var(--line);
}
.tc2-check-list li .chk {
  color: var(--brand); font-size: 14px; flex-shrink: 0; margin-top: 1px; font-weight: 700;
}
.tc2-modal-footer {
  padding: 14px 26px 20px; border-top: 1px solid var(--line);
  flex-shrink: 0; display: flex; gap: 10px;
}
.tc2-agree-btn {
  flex: 1; background: var(--brand); color: var(--cream); border: none;
  padding: 13px; border-radius: 999px; font-weight: 700; font-size: 14px;
  cursor: pointer; transition: background .15s ease, transform .15s ease;
  box-shadow: 0 2px 8px rgba(9,44,33,.18);
}
.tc2-agree-btn:hover { background: var(--brand-dark); transform: translateY(-1px); }
.tc2-cancel-btn {
  flex: 1; background: transparent; color: var(--danger-dark);
  border: 1.5px solid rgba(179,69,47,.28); padding: 13px;
  border-radius: 999px; font-weight: 600; font-size: 14px;
  cursor: pointer; transition: all .15s ease;
}
.tc2-cancel-btn:hover { background: var(--danger-bg); border-color: var(--danger); }
</style>

<!-- ===================== FIRST T&C MODAL ===================== -->
<div class="tc-modal-backdrop" id="tc-modal-1">
  <div class="tc-modal">
    <div class="tc-modal-header">
      <div class="tc-badge">📋 Legal Agreement</div>
      <h2>SafeTrade Terms and Conditions</h2>
      <div class="tc-date">Last Updated: September 2026</div>
    </div>
    <div class="tc-modal-body" id="tc-scroll-body">
      <p style="font-size:13.5px;color:var(--ink);line-height:1.65;margin-bottom:18px;">
        Welcome to <strong>SafeTrade</strong> — a platform that helps people buy and sell items
        safely. Our goal is to make meetups and transactions safer by connecting verified buyers and
        sellers. Please read these terms carefully before creating an account.
      </p>

      <section>
        <h3><span class="num">1</span> SafeTrade is Only a Platform</h3>
        <p>SafeTrade connects buyers and sellers. We do not own, sell, or deliver the items listed.
        The deal is between buyer and seller — SafeTrade is not responsible for the item, payment,
        or agreement between users.</p>
      </section>

      <section>
        <h3><span class="num">2</span> Meetups and Payment</h3>
        <p>We encourage users to meet in a safe, public place. Buyers should check the item carefully
        before paying. Sellers should confirm payment is received before handing over the item. Once
        completed, any disputes (refunds, exchanges) are handled between buyer and seller.</p>
      </section>

      <section>
        <h3><span class="num">3</span> Be Honest</h3>
        <p>Provide truthful information at all times. Sellers must honestly describe their items,
        including defects. Buyers should ask questions and inspect items before paying. Do not use
        SafeTrade to scam, steal, or mislead others.</p>
      </section>

      <section>
        <h3><span class="num">4</span> Account Verification</h3>
        <p>To prevent bots, fake accounts, and scams, SafeTrade requires identity verification. This
        may include your name, phone number, email, face scan, or government ID. You agree to provide
        accurate information. Your data is handled per our Privacy Policy and applicable laws.</p>
        <p style="margin-top:8px;"><em>Verification reduces fake accounts — it does not guarantee
        that a user is trustworthy.</em></p>
      </section>

      <section>
        <h3><span class="num">5</span> Prohibited Use</h3>
        <p>Do not use SafeTrade to:</p>
        <ul>
          <li>Scam or steal from other users</li>
          <li>Sell stolen, fake, or illegal items</li>
          <li>Create fake accounts or impersonate someone else</li>
          <li>Use bots or automated methods to abuse the platform</li>
          <li>Provide false information during registration or listings</li>
          <li>Do anything illegal through SafeTrade</li>
        </ul>
        <p style="margin-top:8px;">We may remove listings or suspend/ban accounts that break these rules.</p>
      </section>

      <section>
        <h3><span class="num">6</span> Stay Safe</h3>
        <p>When meeting another user, use common sense. Meet in a public place, tell someone where
        you are going, and leave if something feels unsafe. Never send money before you are
        comfortable with the transaction.</p>
      </section>

      <section>
        <h3><span class="num">7</span> SafeTrade's Responsibility</h3>
        <p>We work to keep SafeTrade safe, but we cannot guarantee every user or transaction.
        SafeTrade is not responsible for scams, fake items, fake payments, damaged items,
        disagreements, or problems between users. Report suspicious activity so we can review it.</p>
      </section>

      <section>
        <h3><span class="num">8</span> Your Agreement</h3>
        <p>By creating an account you confirm that you agree to follow these Terms, will provide
        truthful information, understand transactions are between buyers and sellers, agree to
        complete required verification, and are responsible for making safe decisions when dealing
        with other users.</p>
        <p style="margin-top:8px;font-weight:600;color:var(--ink);">If you do not agree, please do
        not use SafeTrade.</p>
      </section>
      <div class="tc-scroll-hint" id="tc-scroll-hint">↓ Scroll down to read all terms</div>
    </div>
    <div class="tc-modal-footer">
      <button type="button" class="tc-proceed-btn" id="tc-proceed-btn">
        I have read these Terms — Proceed →
      </button>
    </div>
  </div>
</div>

<?php if ($showOtp): ?>
<style>
.otp-backdrop{position:fixed;inset:0;z-index:9500;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(10,50,33,.35);backdrop-filter:blur(6px);}
.otp-pop{background:rgba(251,247,239,.96);border-radius:22px;width:100%;max-width:400px;padding:30px 26px;text-align:center;box-shadow:0 24px 64px rgba(9,44,33,.3);animation:tc-rise 260ms cubic-bezier(.22,.75,.28,1) both;}
.otp-pop h2{font-family:'Fraunces',serif;font-size:21px;margin:0;}
.otp-pop .sub{font-size:13px;color:var(--ink-soft);margin-top:8px;line-height:1.55;}
.otp-inputs{display:flex;gap:9px;justify-content:center;margin-top:22px;}
.otp-box{width:42px;height:52px;text-align:center;font-size:22px;font-weight:700;border-radius:12px;border:1.5px solid var(--line-strong);background:var(--cream);font-family:'IBM Plex Mono',monospace;outline:none;}
.otp-box:focus{border-color:var(--brand);box-shadow:0 0 0 3px rgba(21,89,67,.15);}
.otp-meta{margin-top:12px;font-size:12.5px;font-weight:600;color:var(--ink-soft);}
.otp-cancel{margin-top:12px;background:none;border:none;color:var(--danger-dark);font-weight:600;font-size:13px;cursor:pointer;}
</style>
<div class="otp-backdrop" id="otp-modal">
  <div class="otp-pop">
    <h2>Check your email</h2>
    <p class="sub">We sent a 6-digit code to <strong><?= e($_SESSION['pending_registration_data']['email']) ?></strong>. Enter it to continue.</p>
    <?php if ($otpError): ?><div class="alert error" style="justify-content:center;"><?= e($otpError) ?></div><?php endif; ?>
    <form method="post" id="otp-form" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="reg_otp_code" id="otp-hidden">
      <div class="otp-inputs">
        <?php for ($i = 0; $i < 6; $i++): ?><input type="text" inputmode="numeric" maxlength="1" class="otp-box" autocomplete="one-time-code" aria-label="Digit <?= $i + 1 ?>"><?php endfor; ?>
      </div>
      <div class="otp-meta"><?= (int) $otpAttemptsLeft ?> attempt<?= $otpAttemptsLeft === 1 ? '' : 's' ?> left · expires in <span id="otp-time">3:00</span></div>
      <button type="submit" class="btn-primary" style="margin-top:20px;">Verify &amp; continue</button>
    </form>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="reg_otp_cancel" value="1"><button type="submit" class="otp-cancel">Cancel registration</button></form>
  </div>
</div>
<script>
(function () {
  var boxes = [].slice.call(document.querySelectorAll('.otp-box')), hid = document.getElementById('otp-hidden'),
      form = document.getElementById('otp-form'), secs = <?= (int) $otpSecondsLeft ?>, t = document.getElementById('otp-time');
  function sync() { hid.value = boxes.map(function (b) { return b.value; }).join(''); }
  boxes.forEach(function (b, i) {
    b.addEventListener('input', function () {
      var d = b.value.replace(/\D/g, '');
      if (d.length > 1) { d.split('').forEach(function (c, k) { if (boxes[i + k]) boxes[i + k].value = c; }); boxes[Math.min(i + d.length, 5)].focus(); }
      else { b.value = d; if (d && boxes[i + 1]) boxes[i + 1].focus(); }
      sync();
    });
    b.addEventListener('keydown', function (e) { if (e.key === 'Backspace' && !b.value && boxes[i - 1]) boxes[i - 1].focus(); });
  });
  boxes[0].focus(); form.addEventListener('submit', sync);
  function tick() {
    t.textContent = Math.floor(secs / 60) + ':' + ('0' + secs % 60).slice(-2);
    if (secs-- <= 0) { hid.value = ''; form.submit(); return; } // server aborts the registration
    setTimeout(tick, 1000);
  }
  tick();
})();
</script>
<?php endif; ?>

<!-- ===================== SECOND T&C CONFIRMATION MODAL ===================== -->
<div class="tc2-modal-backdrop" id="tc-modal-2">
  <div class="tc2-modal">
    <div class="tc2-modal-header">
      <h2>Before you continue…</h2>
      <p>Please confirm you understand and accept the following before creating your SafeTrade account.</p>
    </div>
    <div class="tc2-modal-body">
      <ul class="tc2-check-list">
        <li><span class="chk">✓</span><span>I will provide <strong>true and correct information</strong> when creating my account.</span></li>
        <li><span class="chk">✓</span><span>SafeTrade may ask for my <strong>name, email, phone, photo, or ID</strong> to confirm I am a real person. My information will be handled per the Privacy Policy.</span></li>
        <li><span class="chk">✓</span><span>SafeTrade is <strong>only a platform</strong> that connects buyers and sellers. SafeTrade is not the seller, buyer, or owner of listed items.</span></li>
        <li><span class="chk">✓</span><span>I am responsible for <strong>checking the item, confirming payment, and ensuring my safety</strong> before completing any meetup.</span></li>
        <li><span class="chk">✓</span><span>SafeTrade <strong>does not guarantee</strong> that every user, item, or transaction is legitimate.</span></li>
        <li><span class="chk">✓</span><span>I will <strong>not use SafeTrade</strong> for scams, fraud, stolen or illegal items, fake accounts, or illegal activities.</span></li>
        <li><span class="chk">✓</span><span>SafeTrade may <strong>suspend or remove my account</strong> if I break these rules or provide false information.</span></li>
        <li><span class="chk">✓</span><span>I will <strong>report suspicious activity</strong> to SafeTrade and meet other users in safe, public places.</span></li>
      </ul>
    </div>
    <div class="tc2-modal-footer">
      <button type="button" class="tc2-cancel-btn" id="tc2-cancel-btn">✕ No, Cancel</button>
      <button type="button" class="tc2-agree-btn" id="tc2-agree-btn">✓ Yes, I Agree</button>
    </div>
  </div>
</div>

<div class="auth-shell">
  <div class="auth-card">
    <a href="../Index.html" class="auth-logo"><?= seal_svg() ?> SafeTrade</a>
    <h1>Create your account</h1>
    <p class="auth-sub">Next we'll verify your face on camera (required) and ask for a government ID before an admin approves your account.</p>

    <?php if ($errors): ?>
      <div class="alert error">Please fix the following:
        <ul class="err-list"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>
    <?php render_flashes(); ?>

    <form method="post" novalidate id="reg-form">
      <?= csrf_field() ?>
      <!-- hidden flag set by the T&C flow; the server rejects the POST if it's not "1" -->
      <input type="hidden" name="tc_agreed" id="tc-agreed-field" value="0">
      <div class="field-row">
        <div class="field"><label>First name</label><input type="text" name="first_name" value="<?= e($old['first_name']) ?>" required></div>
        <div class="field"><label>Last name</label><input type="text" name="last_name" value="<?= e($old['last_name']) ?>" required></div>
      </div>
      <div class="field"><label>Email</label><input type="email" name="email" value="<?= e($old['email']) ?>" required></div>
      <div class="field"><label>Phone</label><input type="tel" name="phone" value="<?= e($old['phone']) ?>"></div>
      <div class="field">
        <label>Password</label>
        <div class="pw-field-wrap">
          <input type="password" name="password" id="reg-pw" minlength="10" required>
          <button type="button" class="pw-toggle" data-target="reg-pw" aria-label="Show password">👁</button>
        </div>
      </div>
      <div class="field">
        <label>Confirm password</label>
        <div class="pw-field-wrap">
          <input type="password" name="password_confirm" id="reg-pw-confirm" minlength="10" required>
          <button type="button" class="pw-toggle" data-target="reg-pw-confirm" aria-label="Show password">👁</button>
        </div>
      </div>
      <!-- This button triggers the T&C modal, not a direct form submit -->
      <button type="button" id="open-tc-btn" class="btn-primary" style="margin-top:26px;">
        Create account
      </button>
    </form>
    <div class="auth-foot">Already have an account? <a href="login.php">Log in</a></div>
  </div>
</div>

<style>
.pw-field-wrap{position:relative;}
.pw-field-wrap input{padding-right:44px;}
.pw-toggle{
  position:absolute;top:50%;right:6px;transform:translateY(-50%);
  background:none;border:none;cursor:pointer;font-size:16px;line-height:1;
  padding:8px;border-radius:8px;opacity:.6;transition:opacity .15s ease,background .15s ease;
}
.pw-toggle:hover{opacity:1;background:rgba(21,89,67,.08);}
</style>
<script>
document.querySelectorAll('.pw-toggle').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var input = document.getElementById(btn.getAttribute('data-target'));
    var showing = input.type === 'text';
    input.type = showing ? 'password' : 'text';
    btn.textContent = showing ? '👁' : '🙈';
    btn.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
  });
});
</script>
<script>
(function () {
  var modal1    = document.getElementById('tc-modal-1');
  var modal2    = document.getElementById('tc-modal-2');
  var openBtn   = document.getElementById('open-tc-btn');
  var proceedBtn= document.getElementById('tc-proceed-btn');
  var agreeBtn  = document.getElementById('tc2-agree-btn');
  var cancelBtn = document.getElementById('tc2-cancel-btn');
  var agreedFld = document.getElementById('tc-agreed-field');
  var regForm   = document.getElementById('reg-form');
  var scrollBody= document.getElementById('tc-scroll-body');
  var scrollHint= document.getElementById('tc-scroll-hint');

  // Hide scroll hint when user has scrolled far enough
  if (scrollBody && scrollHint) {
    scrollBody.addEventListener('scroll', function () {
      var pct = scrollBody.scrollTop / (scrollBody.scrollHeight - scrollBody.clientHeight);
      if (pct > 0.85) scrollHint.style.display = 'none';
    });
  }

  // "Create account" -> open first T&C modal
  openBtn.addEventListener('click', function () {
    modal1.classList.add('show');
    // Reset scroll position each time
    if (scrollBody) scrollBody.scrollTop = 0;
    if (scrollHint) scrollHint.style.display = 'flex';
  });

  // "Proceed" button in first modal -> close first, open second
  proceedBtn.addEventListener('click', function () {
    modal1.classList.remove('show');
    modal2.classList.add('show');
  });

  // "Yes, I Agree" in second modal -> set flag and submit
  agreeBtn.addEventListener('click', function () {
    agreedFld.value = '1';
    modal2.classList.remove('show');
    regForm.submit();
  });

  // "No, Cancel" -> close both modals; user stays on page, can't proceed
  cancelBtn.addEventListener('click', function () {
    modal2.classList.remove('show');
    // Show a brief inline notice
    var note = document.createElement('div');
    note.className = 'alert error';
    note.style.marginTop = '14px';
    note.textContent = 'You must agree to the Terms & Conditions to create a SafeTrade account.';
    var existing = document.querySelector('.tc-declined-note');
    if (!existing) {
      note.classList.add('tc-declined-note');
      openBtn.parentNode.insertBefore(note, openBtn.nextSibling);
      setTimeout(function () { if (note.parentNode) note.parentNode.removeChild(note); }, 6000);
    }
  });

  // Click backdrop of modal 1 to close
  modal1.addEventListener('click', function (e) {
    if (e.target === modal1) modal1.classList.remove('show');
  });

  // Clicking backdrop of modal 2 doesn't close (user must explicitly choose Yes or No)
}());
</script>
</body>
</html>
<?php
    return;
}

if ($step === 'face') {
    $pageTitle = 'Verify your face · SafeTrade';
    $assetBase = '..';
    require __DIR__ . '/../includes/partials/head.php';
    ?>
    <div class="auth-shell">
      <div class="auth-card">
        <a href="../Index.html" class="auth-logo"><?= seal_svg() ?> SafeTrade</a>
        <h1>Step 2 of 3 — Verify your face</h1>
        <p class="auth-sub">Required. This uses your camera only — nothing is uploaded as a photo. The next step is a government ID.</p>
        <?php render_flashes(); ?>
        <div class="alert error" id="register-face-error" style="display:none;"></div>
        <button type="button" class="face-trigger-btn" id="face-register-btn" style="margin-top:10px;">
          📷 Verify my face
        </button>
      </div>
    </div>
    <script>window.SAFETRADE_CSRF = '<?= csrf_token() ?>';</script>
    <?php require __DIR__ . '/../includes/partials/face-modal.php'; ?>
    <script>
    document.getElementById('face-register-btn').addEventListener('click', function () {
      document.getElementById('register-face-error').style.display = 'none';
      openFaceModal('register', {
        onSuccess: function () {
          window.location.href = '<?= e(BASE_URL) ?>/auth/register.php?step=id';
        },
        onFail: function (msg) {
          var box = document.getElementById('register-face-error');
          box.textContent = msg;
          box.style.display = 'block';
        },
      });
    });
    </script>
    </body>
    </html>
    <?php
    return;
}

// ===================== Step 3: government ID =====================
$pageTitle = 'Verify your ID · SafeTrade';
$assetBase = '..';
require __DIR__ . '/../includes/partials/head.php';

$idOrder   = ['philsys', 'drivers_license', 'passport', 'umid', 'sss', 'philhealth', 'postal', 'voters', 'other'];
$idFormats = id_formats();
$idAttemptsUsed = $idResume ? (int) $idResume['id_attempts'] : 0;
$idExhausted    = $idResume !== null && $idAttemptsUsed >= ID_MAX_ATTEMPTS;
$idLastReject   = $idResume ? id_screening_latest(db(), (int) $idResume['id'], 'reject') : null;
$idJs = [];
foreach ($idFormats as $k => $f) {
    $idJs[$f['label']] = ['number_label' => $f['number_label'], 'example' => $f['number_example'], 'expiry' => (bool) $f['requires_expiry'],
                          'has_expiry' => (bool) $f['has_expiry'], 'mrz' => (bool) $f['requires_mrz'], 'number' => (bool) $f['requires_number']];
}
?>
<div class="auth-shell">
  <div class="auth-card">
    <a href="../Index.html" class="auth-logo"><?= seal_svg() ?> SafeTrade</a>
    <h1>Step 3 of 3 — Government ID</h1>
    <p class="auth-sub">Required before your account can be approved. Upload a clear photo of a valid government-issued ID. We run automatic consistency checks first; anything unclear goes to an admin.</p>

    <?php if ($idResume): ?>
      <div class="alert error" style="display:block;">
        <strong>Your ID could not be accepted<?= $idLastReject ? '' : ' yet' ?>.</strong>
        <?php if ($idLastReject && trim((string) $idLastReject['reasons']) !== ''): ?>
          <ul class="err-list"><?php foreach (explode("\n", (string) $idLastReject['reasons']) as $why): if (trim($why) === '') continue; ?><li><?= e($why) ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
        <div style="margin-top:6px;font-weight:600;">
          <?= $idExhausted
              ? 'You have used all ' . ID_MAX_ATTEMPTS . ' upload attempts.'
              : 'Attempt ' . min($idAttemptsUsed + 1, ID_MAX_ATTEMPTS) . ' of ' . ID_MAX_ATTEMPTS . ' — your account has not been deleted, you can upload again.' ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($idRetryNotes): ?>
      <div class="alert error" style="display:block;">
        <strong>Please retake the photo.</strong> This is not a rejection and does not count as an attempt.
        <ul class="err-list"><?php foreach ($idRetryNotes as $n): ?><li><?= e($n) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>

    <?php if ($idErrors): ?>
      <div class="alert error" style="display:block;">Please fix the following:
        <ul class="err-list"><?php foreach ($idErrors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>

    <?php if (!$idExhausted): ?>
    <form method="post" enctype="multipart/form-data" novalidate id="id-form">
      <?= csrf_field() ?>
      <div class="field">
        <label>ID type</label>
        <select name="id_type" id="id_type" required>
          <option value="" disabled <?= $idOld['id_type'] === '' ? 'selected' : '' ?>>Choose an ID type…</option>
          <?php foreach ($idOrder as $k): $lbl = $idFormats[$k]['label']; ?>
            <option <?= $idOld['id_type'] === $lbl ? 'selected' : '' ?>><?= e($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label id="id_number_label">ID number</label>
        <input type="text" name="id_number" id="id_number" value="<?= e($idOld['id_number']) ?>" maxlength="40" autocomplete="off" placeholder="">
      </div>
      <div class="field" id="id_expiry_wrap">
        <label>Expiry date <span id="id_expiry_opt" style="font-weight:400;color:var(--ink-soft);"></span></label>
        <input type="date" name="id_expiry" value="<?= e($idOld['id_expiry']) ?>">
      </div>
      <div class="field" id="id_mrz_wrap" style="display:none;">
        <label>Passport MRZ <span style="font-weight:400;color:var(--ink-soft);">(the two lines of letters, numbers and &lt; at the bottom of the photo page)</span></label>
        <textarea name="mrz" rows="2" spellcheck="false" autocomplete="off" style="font-family:monospace;letter-spacing:.02em;" placeholder="P&lt;PHL…&#10;P1234567A…"><?= e($idOld['mrz']) ?></textarea>
      </div>
      <div class="field">
        <label>Front of ID <span style="font-weight:400;color:var(--ink-soft);">(whole card visible, sharp, no glare)</span></label>
        <input type="file" name="id_front" accept="image/jpeg,image/png,image/webp" required>
        <input type="hidden" name="id_face_status" id="id_face_status" value="">
        <input type="hidden" name="id_face_descriptor" id="id_face_descriptor" value="">
        <input type="hidden" name="id_face_score" id="id_face_score" value="">
        <input type="hidden" name="id_face_box" id="id_face_box" value="">
        <input type="hidden" name="id_face_count" id="id_face_count" value="">
        <div id="id_face_msg" style="font-size:12.5px;margin-top:6px;min-height:16px;"></div>
      </div>
      <div class="field">
        <label>Back of ID <span style="font-weight:400;color:var(--ink-soft);">(optional, if it has info on it)</span></label>
        <input type="file" name="id_back" accept="image/jpeg,image/png,image/webp">
      </div>

      <div style="margin-top:14px;padding:12px 14px;border-radius:12px;background:var(--brand-light);font-size:12.5px;line-height:1.55;color:var(--ink);">
        <strong>Data Privacy Notice (Republic Act No. 10173, Data Privacy Act of 2012).</strong>
        SafeTrade collects your government ID image, ID details and facial data only to verify your identity and prevent fraud and duplicate accounts.
        The face on your ID photo is read in your browser and only its numeric face data is sent, to compare with the face you registered.
        Your ID number is stored only as a salted hash; images and face data are visible only to authorised administrators and are deleted if your registration is rejected and removed.
        Automated checks may decide your verification, and you can always ask an admin to review it.
        <?php if (id_ocr_enabled()): ?>The text on your ID is read automatically on SafeTrade's own server.<?php endif; ?>
        <?php if (id_vision_enabled()): ?><strong>Your ID image is also sent to a third-party AI service outside SafeTrade's servers</strong> to read it and look for signs of editing.<?php endif; ?>
        You may access, correct or request deletion of your data by contacting support.
        <label style="display:flex;gap:8px;align-items:flex-start;margin-top:10px;font-weight:600;cursor:pointer;">
          <input type="checkbox" name="dpa_consent" value="1" style="width:auto;margin:3px 0 0;" <?= (($_POST['dpa_consent'] ?? '') === '1') ? 'checked' : '' ?>>
          <span>I consent to the processing of my ID and biometric (face) data for identity verification.</span>
        </label>
      </div>
      <button type="submit" class="btn-primary" style="margin-top:14px;">Submit for verification</button>
    </form>
    <?php endif; ?>

    <?php if ($idResume): ?>
      <details style="margin-top:18px;" <?= $idExhausted ? 'open' : '' ?>>
        <summary style="cursor:pointer;font-weight:600;font-size:13.5px;">Think this was a mistake? Ask an admin to review it</summary>
        <form method="post" style="margin-top:10px;">
          <?= csrf_field() ?>
          <input type="hidden" name="id_appeal" value="1">
          <div class="field">
            <label>Tell the admin what happened</label>
            <textarea name="appeal_message" rows="3" maxlength="1000" required placeholder="e.g. My ID number was typed correctly; it is my own ID."></textarea>
          </div>
          <button type="submit" class="btn-primary">Request manual review</button>
        </form>
      </details>
    <?php endif; ?>
  </div>
</div>
<script>window.SAFETRADE_BASE = '<?= e(BASE_URL) ?>';</script>
<script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>
<script src="<?= e($assetBase) ?>/assets/js/face-auth.js"></script>
<script src="<?= e($assetBase) ?>/assets/js/id-face.js"></script>
<script>
(function () {
  var cfg = <?= json_encode($idJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var sel = document.getElementById('id_type');
  if (!sel) return;
  function sync() {
    var c = cfg[sel.value];
    var numLabel = document.getElementById('id_number_label'), num = document.getElementById('id_number');
    var expWrap = document.getElementById('id_expiry_wrap'), expOpt = document.getElementById('id_expiry_opt');
    var mrz = document.getElementById('id_mrz_wrap');
    if (!c) { mrz.style.display = 'none'; return; }
    numLabel.textContent = c.number_label + (c.number ? '' : ' (optional)');
    num.placeholder = c.example ? 'e.g. ' + c.example : '';
    expWrap.style.display = (c.expiry || c.has_expiry) ? '' : 'none';
    expOpt.textContent = c.expiry ? '' : '(if your ID has one)';
    mrz.style.display = c.mrz ? '' : 'none';
  }
  sel.addEventListener('change', sync);
  sync();
})();
</script>
</body>
</html>
