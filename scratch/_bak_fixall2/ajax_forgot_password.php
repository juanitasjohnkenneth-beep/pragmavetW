<?php
session_start();
header('Content-Type: application/json');

require_once 'dbconnection.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

$action = $_POST['action'] ?? '';

// ============================================================
// ACTION: send_code — Verify email, generate OTP, send via SMTP
// ============================================================
if ($action === 'send_code') {
    $email = trim($_POST['email'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['status' => 'error', 'message' => 'Please enter a valid email address.']);
        exit();
    }

    $stmt = $conn->prepare("SELECT id, fullname FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if ($user) {
        // Generate 6-digit OTP
        $otp     = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires = date('Y-m-d H:i:s', strtotime('+15 minutes'));

        // Remove any existing resets for this email
        $del = $conn->prepare("DELETE FROM password_resets WHERE email = ?");
        $del->bind_param("s", $email);
        $del->execute();

        // Insert new OTP
        $ins = $conn->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)");
        $ins->bind_param("sss", $email, $otp, $expires);

        if ($ins->execute()) {
            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host       = 'smtp.gmail.com';
                $mail->SMTPAuth   = true;
                $mail->Username   = 'timferrer1@gmail.com';
                $mail->Password   = 'azrpmoxxzvhucetu';
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = 587;
                $mail->setFrom('timferrer1@gmail.com', 'PragmaVet Security');
                $mail->addAddress($email, $user['fullname']);
                $mail->isHTML(true);
                $mail->Subject = 'PragmaVet - Your Verification Code';
                $mail->Body = '
                    <div style="font-family:Inter,Arial,sans-serif;max-width:560px;margin:auto;padding:40px 32px;background:#ffffff;border-radius:16px;border:1px solid #e2e8f0;">
                        <div style="text-align:center;margin-bottom:28px;">
                            <div style="display:inline-block;background:#EFF6FF;border-radius:50%;width:64px;height:64px;line-height:64px;font-size:28px;">&#128274;</div>
                        </div>
                        <h2 style="color:#0f172a;font-size:20px;margin:0 0 8px;text-align:center;">Account Recovery Code</h2>
                        <p style="color:#64748b;font-size:14px;text-align:center;margin:0 0 32px;">Hi <strong>' . htmlspecialchars($user['fullname']) . '</strong>, use the code below to recover your account. It expires in <strong>15 minutes</strong>.</p>
                        <div style="text-align:center;margin:0 0 32px;">
                            <span style="display:inline-block;background:#2563eb;color:#fff;font-size:36px;font-weight:800;letter-spacing:12px;padding:18px 32px;border-radius:14px;">' . $otp . '</span>
                        </div>
                        <p style="color:#94a3b8;font-size:12px;text-align:center;margin:0;">If you did not request this, you can safely ignore this email. Do not share this code with anyone.</p>
                    </div>
                ';
                $mail->send();

                // Store in session
                $_SESSION['fp_step']     = 2;
                $_SESSION['fp_email']    = $email;
                $_SESSION['fp_verified'] = false;
                $_SESSION['fp_attempts'] = 0;

                echo json_encode([
                    'status'  => 'success',
                    'message' => 'A 6-digit code has been sent to your email. Check your inbox.',
                    'step'    => 2,
                    'email'   => $email
                ]);
                exit();
            } catch (Exception $e) {
                echo json_encode(['status' => 'error', 'message' => 'Failed to send email. Please try again later.']);
                exit();
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database error. Please try again.']);
            exit();
        }
    } else {
        // Prevent email enumeration — show same success message
        $_SESSION['fp_step']     = 2;
        $_SESSION['fp_email']    = $email;
        $_SESSION['fp_verified'] = false;
        $_SESSION['fp_attempts'] = 0;

        echo json_encode([
            'status'  => 'success',
            'message' => 'If that email is registered, a code has been sent to your inbox.',
            'step'    => 2,
            'email'   => $email
        ]);
        exit();
    }
}

// ============================================================
// ACTION: verify_code — Verify the 6-digit OTP
// ============================================================
if ($action === 'verify_code') {
    $otp   = trim($_POST['otp'] ?? '');
    $email = $_SESSION['fp_email'] ?? '';

    if (empty($email)) {
        echo json_encode(['status' => 'error', 'message' => 'Session expired. Please start over.', 'step' => 1]);
        exit();
    }

    if (!preg_match('/^\d{6}$/', $otp)) {
        echo json_encode(['status' => 'error', 'message' => 'Please enter a valid 6-digit code.']);
        exit();
    }

    $stmt = $conn->prepare("SELECT token, expires_at FROM password_resets WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $row    = $result->fetch_assoc();

    if (!$row) {
        echo json_encode(['status' => 'error', 'message' => 'No recovery request found. Please start over.', 'step' => 1]);
        exit();
    }

    if (strtotime($row['expires_at']) < time()) {
        // Clean up expired record
        $del = $conn->prepare("DELETE FROM password_resets WHERE email = ?");
        $del->bind_param("s", $email);
        $del->execute();
        unset($_SESSION['fp_step'], $_SESSION['fp_email'], $_SESSION['fp_verified'], $_SESSION['fp_attempts']);
        echo json_encode(['status' => 'error', 'message' => 'Code has expired. Please start over.', 'step' => 1]);
        exit();
    }

    if (!hash_equals($row['token'], $otp)) {
        // Limit guesses: after 5 wrong attempts, invalidate the code
        $_SESSION['fp_attempts'] = ($_SESSION['fp_attempts'] ?? 0) + 1;
        if ($_SESSION['fp_attempts'] >= 5) {
            $del = $conn->prepare("DELETE FROM password_resets WHERE email = ?");
            $del->bind_param("s", $email);
            $del->execute();
            unset($_SESSION['fp_step'], $_SESSION['fp_email'], $_SESSION['fp_verified'], $_SESSION['fp_attempts']);
            echo json_encode(['status' => 'error', 'message' => 'Too many incorrect attempts. Please request a new code.', 'step' => 1]);
            exit();
        }
        $left = 5 - $_SESSION['fp_attempts'];
        echo json_encode(['status' => 'error', 'message' => "Incorrect code. You have $left attempt(s) left."]);
        exit();
    }

    // Code is valid
    $_SESSION['fp_step']     = 3;
    $_SESSION['fp_verified'] = true;

    echo json_encode([
        'status'  => 'success',
        'message' => 'Code verified! Set a new password.',
        'step'    => 3
    ]);
    exit();
}

// ============================================================
// ACTION: reset_password — Update password in database
// ============================================================
if ($action === 'reset_password') {
    $new_pass     = $_POST['new_pass'] ?? '';
    $confirm_pass = $_POST['confirm_pass'] ?? '';
    $email        = $_SESSION['fp_email'] ?? '';

    if (empty($email) || !($_SESSION['fp_verified'] ?? false)) {
        echo json_encode(['status' => 'error', 'message' => 'Session expired or verification missing. Please start over.', 'step' => 1]);
        exit();
    }

    if ($new_pass !== $confirm_pass) {
        echo json_encode(['status' => 'error', 'message' => 'Passwords do not match.']);
        exit();
    }

    if (strlen($new_pass) < 8) {
        echo json_encode(['status' => 'error', 'message' => 'Password must be at least 8 characters long.']);
        exit();
    }

    $hashed = password_hash($new_pass, PASSWORD_DEFAULT);

    $update = $conn->prepare("UPDATE users SET password = ? WHERE email = ?");
    $update->bind_param("ss", $hashed, $email);

    if ($update->execute()) {
        // Clean up: remove reset token, unlock account, reset failed attempts
        $del = $conn->prepare("DELETE FROM password_resets WHERE email = ?");
        $del->bind_param("s", $email);
        $del->execute();

        $unlock = $conn->prepare("UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE email = ?");
        $unlock->bind_param("s", $email);
        $unlock->execute();

        // Clear session
        unset($_SESSION['fp_step'], $_SESSION['fp_email'], $_SESSION['fp_verified'], $_SESSION['fp_attempts']);

        echo json_encode([
            'status'  => 'success',
            'message' => 'Password changed successfully! You can now log in.'
        ]);
        exit();
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Something went wrong. Please try again.']);
        exit();
    }
}

// ============================================================
// ACTION: restart — Clear forgot password session
// ============================================================
if ($action === 'restart') {
    unset($_SESSION['fp_step'], $_SESSION['fp_email'], $_SESSION['fp_verified'], $_SESSION['fp_attempts']);
    echo json_encode(['status' => 'success', 'step' => 1]);
    exit();
}

// Invalid action
echo json_encode(['status' => 'error', 'message' => 'Invalid request.']);
exit();