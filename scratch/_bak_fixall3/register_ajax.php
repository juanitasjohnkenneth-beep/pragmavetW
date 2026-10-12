<?php
require_once __DIR__ . '/includes/mail_config.php';
/**
 * register_ajax.php
 * Handles AJAX-based registration with email OTP verification.
 *
 * Actions:
 *   step=send_otp   — Validate inputs, save pending user, send OTP
 *   step=verify_otp — Check code, mark account as verified
 *   step=resend_otp — Regenerate & resend OTP
 */
session_start();
include("dbconnection.php");
header('Content-Type: application/json');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

$step = $_POST['step'] ?? '';

// ─── Reusable mailer builder ────────────────────────────────────────────────
function buildMailer(): PHPMailer {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = PV_SMTP_USER;
    $mail->Password   = PV_SMTP_PASS;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ],
    ];
    return $mail;
}

// ─── OTP email template ──────────────────────────────────────────────────────
function otpEmailBody(string $fullname, int $otp): string {
    $name = htmlspecialchars($fullname);
    return "<!DOCTYPE html>
<html>
<head><meta charset='UTF-8'></head>
<body style='margin:0;padding:0;font-family:Arial,Helvetica,sans-serif;background:#f5f5f5;'>
<table width='100%' cellpadding='0' cellspacing='0'>
<tr><td align='center' style='padding:40px 20px;'>
<table width='600' cellpadding='0' cellspacing='0' style='background:#ffffff;border-radius:12px;overflow:hidden;max-width:600px;'>
  <tr><td style='background:#0E5A6B;padding:30px 40px;text-align:center;'>
    <h1 style='color:#ffffff;margin:0;font-size:24px;font-weight:700;'>PragmaVet</h1>
    <p style='color:#a8d8e8;margin:6px 0 0;font-size:13px;'>Veterinary Care System</p>
  </td></tr>
  <tr><td style='padding:40px;'>
    <h2 style='color:#1e293b;font-size:20px;margin:0 0 16px;'>Hello, {$name}!</h2>
    <p style='color:#475569;font-size:15px;line-height:1.6;margin:0 0 24px;'>
      Thank you for creating a PragmaVet account. Please use the code below to verify your email address:
    </p>
    <table width='100%' cellpadding='0' cellspacing='0'>
    <tr><td align='center' style='padding:24px;background:#f0f9ff;border-radius:10px;border:2px solid #0E5A6B;'>
      <p style='margin:0 0 6px;color:#64748b;font-size:12px;text-transform:uppercase;letter-spacing:2px;'>Your verification code</p>
      <span style='font-size:44px;font-weight:900;letter-spacing:14px;color:#0E5A6B;font-family:Courier,monospace;'>{$otp}</span>
    </td></tr>
    </table>
    <p style='color:#64748b;font-size:13px;line-height:1.6;margin:24px 0 0;'>
      This code is valid for <strong>15 minutes</strong>. Enter it on the registration page to complete your account setup.
    </p>
    <p style='color:#94a3b8;font-size:12px;margin:16px 0 0;border-top:1px solid #e2e8f0;padding-top:16px;'>
      If you did not create a PragmaVet account, you can safely ignore this email.
    </p>
  </td></tr>
  <tr><td style='background:#f8fafc;padding:20px 40px;text-align:center;border-top:1px solid #e2e8f0;'>
    <p style='color:#94a3b8;font-size:12px;margin:0;'>PragmaVet Veterinary Clinic &mdash; Secure Account System</p>
  </td></tr>
</table>
</td></tr>
</table>
</body>
</html>";
}

// ════════════════════════════════════════════════════════════════════════════
// STEP 1: Validate inputs → save pending user → send OTP
// ════════════════════════════════════════════════════════════════════════════
if ($step === 'send_otp') {
    $fullname = trim($_POST['fullname'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $address  = trim($_POST['address'] ?? '');
    $password = $_POST['password'] ?? '';

    // --- Basic validation ---
    if (empty($fullname) || empty($email) || empty($address) || empty($password)) {
        echo json_encode(['success' => false, 'message' => 'All fields are required.']);
        exit;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
        exit;
    }
    if (!preg_match('/^[a-z0-9]{8,}$/', $password)) {
        echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters using lowercase letters and numbers only.']);
        exit;
    }

    // --- Check if email already registered & verified ---
    $chk = $conn->prepare("SELECT id, is_verified FROM users WHERE email=?");
    $chk->bind_param("s", $email);
    $chk->execute();
    $existing = $chk->get_result()->fetch_assoc();

    if ($existing && $existing['is_verified'] == 1) {
        echo json_encode(['success' => false, 'message' => 'This email is already registered. Please log in instead.']);
        exit;
    }

    $hashed   = password_hash($password, PASSWORD_DEFAULT);
    $otp_code = rand(100000, 999999);
    $expires  = date('Y-m-d H:i:s', strtotime('+15 minutes'));

    if ($existing) {
        // Overwrite stale unverified account — refresh OTP
        $upd = $conn->prepare("UPDATE users SET fullname=?, password=?, address=?, is_verified=0, verification_token=?, token_expires_at=?, otp_attempts=0 WHERE email=?");
        $upd->bind_param("ssssss", $fullname, $hashed, $address, $otp_code, $expires, $email);
        $upd->execute();
    } else {
        // Insert new pending record (unverified until OTP confirmed)
        $ins = $conn->prepare("INSERT INTO users (fullname, email, password, address, verification_token, token_expires_at, is_verified, role) VALUES (?,?,?,?,?,?,0,'client')");
        $ins->bind_param("ssssss", $fullname, $email, $hashed, $address, $otp_code, $expires);
        $ins->execute();
    }

    // --- Send OTP email ---
    try {
        $mail = buildMailer();
        $mail->setFrom(PV_SMTP_USER, 'PragmaVet Accounts');
        $mail->addReplyTo(PV_SMTP_USER, 'PragmaVet');
        $mail->addAddress($email, $fullname);
        $mail->isHTML(true);
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->Subject = 'PragmaVet Account Registration - Email Verification';
        $mail->Body    = otpEmailBody($fullname, $otp_code);
        $mail->AltBody = "Hello $fullname,\n\nYour PragmaVet verification code is: $otp_code\n\nThis code expires in 15 minutes.\n\nIf you did not request this, please ignore this email.";
        $mail->send();

        $_SESSION['pending_reg_email']    = $email;
        $_SESSION['pending_reg_fullname'] = $fullname;

        echo json_encode(['success' => true, 'message' => "Verification code sent to $email"]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to send email. Error: ' . $mail->ErrorInfo]);
    }

    exit;
}

// ════════════════════════════════════════════════════════════════════════════
// STEP 2: Verify OTP
// ════════════════════════════════════════════════════════════════════════════
if ($step === 'verify_otp') {
    $email = trim($_POST['email'] ?? '');
    $otp   = trim($_POST['otp'] ?? '');

    if (empty($email) || empty($otp)) {
        echo json_encode(['success' => false, 'message' => 'Missing email or code.']);
        exit;
    }

    $now = date('Y-m-d H:i:s');
    $row = $conn->query("SELECT id, verification_token, token_expires_at FROM users WHERE email='" . $conn->real_escape_string($email) . "' AND is_verified=0")->fetch_assoc();

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'No pending registration found for this email.']);
        exit;
    }

    // Check expiry (if column exists)
    if (!empty($row['token_expires_at']) && $now > $row['token_expires_at']) {
        echo json_encode(['success' => false, 'message' => 'Verification code has expired. Please request a new one.']);
        exit;
    }

    if ((string)$row['verification_token'] === '') {
        echo json_encode(['success' => false, 'message' => 'This code is no longer valid. Please tap "Resend Code" to get a new one.']);
        exit;
    }
    if ((string)$row['verification_token'] !== (string)$otp) {
        // Count wrong codes on the account (not the browser), so they can't be guessed endlessly
        $conn->query("UPDATE users SET otp_attempts = otp_attempts + 1 WHERE id = " . (int)$row['id']);
        $tries = (int)($conn->query("SELECT otp_attempts FROM users WHERE id = " . (int)$row['id'])->fetch_assoc()['otp_attempts'] ?? 0);
        if ($tries >= 5) {
            $conn->query("UPDATE users SET verification_token = NULL, token_expires_at = NULL, otp_attempts = 0 WHERE id = " . (int)$row['id']);
            echo json_encode(['success' => false, 'message' => 'Too many incorrect codes. Please tap "Resend Code" to get a new one.']);
            exit;
        }
        echo json_encode(['success' => false, 'message' => 'Incorrect verification code. ' . (5 - $tries) . ' attempt' . (5 - $tries === 1 ? '' : 's') . ' left.']);
        exit;
    }

    // Mark verified
    $conn->query("UPDATE users SET is_verified=1, verification_token=NULL, token_expires_at=NULL, otp_attempts=0 WHERE id=" . (int)$row['id']);

    // Clear session
    unset($_SESSION['pending_reg_email'], $_SESSION['pending_reg_fullname']);

    echo json_encode(['success' => true, 'message' => 'Account verified! You can now log in.']);
    exit;
}

// ════════════════════════════════════════════════════════════════════════════
// STEP 3: Resend OTP
// ════════════════════════════════════════════════════════════════════════════
if ($step === 'resend_otp') {
    $email = trim($_POST['email'] ?? $_SESSION['pending_reg_email'] ?? '');
    if (empty($email)) {
        echo json_encode(['success' => false, 'message' => 'Session expired. Please start registration again.']);
        exit;
    }

    $row = $conn->query("SELECT id, fullname FROM users WHERE email='" . $conn->real_escape_string($email) . "' AND is_verified=0")->fetch_assoc();
    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'No pending registration found.']);
        exit;
    }

    $otp_code = rand(100000, 999999);
    $expires  = date('Y-m-d H:i:s', strtotime('+15 minutes'));
    $conn->query("UPDATE users SET verification_token='$otp_code', token_expires_at='$expires', otp_attempts=0 WHERE id=" . (int)$row['id']);

    try {
        $mail = buildMailer();
        $mail->setFrom(PV_SMTP_USER, 'PragmaVet Accounts');
        $mail->addAddress($email, $row['fullname']);
        $mail->isHTML(true);
        $mail->Subject = 'New Verification Code — ' . $otp_code;
        $mail->Body    = otpEmailBody($row['fullname'], $otp_code);
        $mail->AltBody = "Your new PragmaVet verification code is: $otp_code (expires in 15 minutes)";
        $mail->send();
        echo json_encode(['success' => true, 'message' => 'New code sent to ' . $email]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Resend failed: ' . $mail->ErrorInfo]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);
