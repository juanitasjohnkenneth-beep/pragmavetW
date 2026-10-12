<?php
// Registration is now handled via the modal in index.php
// This page redirects to index.php with the register modal open
header("Location: index.php");
exit();
// Legacy code kept below for reference:
session_start();
include("dbconnection.php");

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

// Handle Registration
if (isset($_POST['register'])) {
    $fullname = $_POST['fullname'];
    $email = $_POST['email'];
    $raw_password = $_POST['password'];
    $address = $_POST['address'];

    if (!preg_match('/^[a-z0-9]{8,}$/', $raw_password)) {
        $_SESSION['message'] = "Password must be at least 8 characters and use only lowercase letters and numbers.";
        $_SESSION['msg_type'] = "danger";
        $_SESSION['reg_email'] = $email;
        $_SESSION['reg_fullname'] = $fullname;
    } else {
        $password = password_hash($raw_password, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("SELECT id FROM users WHERE email=?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $_SESSION['message'] = "Email already exists!";
            $_SESSION['msg_type'] = "danger";
            $_SESSION['reg_email'] = $email;
            $_SESSION['reg_fullname'] = $fullname;
        } else {
            $otp_code = rand(100000, 999999);
            $stmt = $conn->prepare("INSERT INTO users(fullname,email,password,address,verification_token,is_verified) VALUES(?,?,?,?,?,0)");
            $stmt->bind_param("sssss", $fullname, $email, $password, $address, $otp_code);

            if ($stmt->execute()) {
                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host = 'smtp.gmail.com';
                    $mail->SMTPAuth = true;
                    $mail->Username = 'timferrer1@gmail.com';
                    $mail->Password = 'azrpmoxxzvhucetu';
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port = 587;

                    $mail->setFrom('timferrer1@gmail.com', 'PragmaVet Security');
                    $mail->addAddress($email, $fullname);
                    $mail->isHTML(true);
                    $mail->Subject = 'Your 2FA Security Code';
                    $mail->Body = "
                        <div style='font-family: Helvetica, Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 30px; text-align: center; border: 1px solid #e1e8ed; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); background: #ffffff;'>
                            <h2 style='color: #2563eb; margin-bottom: 5px;'>PragmaVet Accounts</h2>
                            <h3 style='color: #333; font-weight: 500;'>Hello, $fullname</h3>
                            <p style='color: #666; font-size: 15px; line-height: 1.6;'>Account security is our highest priority. To complete your registration request, please enter the following Two-Factor Authentication code into your device:</p>
                            <div style='background: #F4F9FB; padding: 25px; margin: 30px 0; border-radius: 10px; border-left: 5px solid #3b82f6;'>
                                <h1 style='margin: 0; font-size: 38px; font-weight: bold; letter-spacing: 8px; color: #2563eb;'>$otp_code</h1>
                            </div>
                            <p style='color: #888; font-size: 13px;'>This code will expire shortly. Do not share your 6-digit code with anyone.</p>
                        </div>";
                    $mail->send();
                    header("Location: otp.php?email=" . urlencode($email));
                    exit();
                } catch (Exception $e) {
                    $_SESSION['message'] = "Account created but email failed. System Log: " . $mail->ErrorInfo;
                    $_SESSION['msg_type'] = "danger";
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Join PragmaVet | Secure Registration</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        /* ===== EXACT INDEX.PHP VARIABLES ===== */
        :root {
            --primary: #0E5A6B;
            --primary-dark: #083D4A;
            --primary-light: #1E7FA8;
            --accent: #5EC4E6;
            --accent-light: #8FD8F0;
            --dark: #1A2332;
            --gray-100: #F8FAFC;
            --gray-200: #F1F5F9;
            --gray-300: #E2E8F0;
            --gray-400: #94A3B8;
            --gray-500: #64748B;
            --gray-600: #475569;
            --radius-sm: 12px;
            --radius-md: 20px;
            --radius-lg: 24px;
            --radius-xl: 32px;
            --shadow-sm: 0 4px 6px -1px rgba(0,0,0,0.06), 0 2px 4px -1px rgba(0,0,0,0.03);
            --shadow-md: 0 10px 25px -5px rgba(0,0,0,0.08), 0 8px 10px -6px rgba(0,0,0,0.02);
            --shadow-lg: 0 20px 45px -10px rgba(14,90,107,0.15);
            --shadow-xl: 0 30px 60px -10px rgba(14,90,107,0.2);
            --shadow-glow: 0 0 25px rgba(94,196,230,0.3);
            --gradient-primary: linear-gradient(135deg,#0E5A6B 0%,#1E7FA8 50%,#5EC4E6 100%);
            --gradient-dark: linear-gradient(135deg,#1A2332 0%,#0E5A6B 100%);
            --gradient-accent: linear-gradient(135deg,#5EC4E6 0%,#1E7FA8 100%);
            --gradient-hero: linear-gradient(135deg,#0E5A6B 0%,#1E7FA8 50%,#0E5A6B 100%);
            --transition: all 0.4s cubic-bezier(0.25,0.46,0.45,0.94);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--gradient-hero);
            background-size: 300% 300%;
            animation: gradientShift 10s ease infinite;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow-x: hidden;
        }
        @keyframes gradientShift { 0% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } 100% { background-position: 0% 50%; } }
        body::before {
            content: ''; position: absolute; inset: 0;
            background: radial-gradient(ellipse at 20% 50%, rgba(94,196,230,0.15) 0%, transparent 60%),
                        radial-gradient(ellipse at 80% 20%, rgba(30,127,168,0.2) 0%, transparent 50%),
                        radial-gradient(ellipse at 50% 100%, rgba(14,90,107,0.3) 0%, transparent 50%);
            pointer-events: none;
        }
        ::selection { background: var(--accent); color: var(--primary-dark); }
        ::-webkit-scrollbar { width: 8px; } ::-webkit-scrollbar-track { background: var(--gray-200); }
        ::-webkit-scrollbar-thumb { background: var(--primary-light); border-radius: 10px; }

        /* ===== PREMIUM AUTH CARD ===== */
        .auth-wrapper { width: 100%; max-width: 480px; position: relative; z-index: 2; animation: slideUp 0.6s cubic-bezier(0.16, 1, 0.3, 1); }
        @keyframes slideUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
        .auth-card {
            background: rgba(255,255,255,0.97);
            backdrop-filter: blur(20px);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-xl);
            border: 1px solid rgba(255,255,255,0.2);
            overflow: hidden;
        }
        .auth-brand { padding: 40px 40px 0; text-align: center; }
        .auth-brand .brand-icon {
            width: 72px; height: 72px; background: var(--gradient-accent); border-radius: 20px;
            display: flex; align-items: center; justify-content: center;
            font-size: 32px; color: white; margin: 0 auto 20px;
            box-shadow: 0 10px 25px rgba(94,196,230,0.3);
        }
        .auth-brand h1 {
            font-weight: 900; font-size: 28px; letter-spacing: -0.8px;
            background: var(--gradient-accent); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
            margin-bottom: 4px;
        }
        .auth-brand p { color: var(--gray-500); font-size: 14px; margin-bottom: 0; }

        /* ===== FORM BODY ===== */
        .auth-body { padding: 28px 40px 40px; }

        .saas-label {
            font-size: 11px; font-weight: 800; text-transform: uppercase;
            letter-spacing: 0.05em; color: var(--gray-400); margin-bottom: 6px;
            display: flex; align-items: center; gap: 6px;
        }
        .form-control {
            border-radius: var(--radius-sm); padding: 14px 20px;
            border: 1px solid var(--gray-300); font-size: 15px;
            background: white; transition: var(--transition); font-family: 'Inter', sans-serif;
        }
        .form-control:focus {
            box-shadow: 0 0 0 4px rgba(94,196,230,0.12);
            border-color: var(--accent); background: white;
        }
        textarea.form-control { resize: vertical; min-height: 80px; }
        .password-hint { font-size: 12px; color: var(--gray-400); margin-top: 6px; display: flex; align-items: center; gap: 6px; }

        /* ===== BUTTON (EXACT INDEX.PHP STYLE) ===== */
        .btn-gradient {
            background: var(--gradient-accent); color: white; border: none;
            padding: 16px 40px; font-weight: 700; border-radius: 50px; font-size: 16px;
            transition: var(--transition); box-shadow: 0 8px 25px rgba(94,196,230,0.35);
            position: relative; overflow: hidden; cursor: pointer;
            display: inline-flex; align-items: center; justify-content: center; gap: 10px;
            text-decoration: none; width: 100%;
        }
        .btn-gradient::before {
            content: ''; position: absolute; top: 0; left: -100%;
            width: 100%; height: 100%;
            background: linear-gradient(90deg,transparent,rgba(255,255,255,0.2),transparent);
            transition: left 0.6s;
        }
        .btn-gradient:hover::before { left: 100%; }
        .btn-gradient:hover { transform: translateY(-3px) scale(1.02); box-shadow: 0 12px 35px rgba(94,196,230,0.5); color: white; }
        .btn-gradient:active { transform: translateY(0) scale(0.98); }
        .btn-gradient:disabled { opacity: 0.5; cursor: not-allowed; transform: none !important; box-shadow: none !important; }
        .btn-gradient:disabled::before { display: none; }

        /* ===== CLICKWRAP CHECKBOX ===== */
        .terms-checkbox {
            display: flex; align-items: flex-start; gap: 10px;
            padding: 16px 18px; margin-bottom: 18px;
            background: rgba(94,196,230,0.05);
            border: 1px solid rgba(94,196,230,0.15);
            border-radius: var(--radius-sm); transition: var(--transition);
        }
        .terms-checkbox:hover { background: rgba(94,196,230,0.08); border-color: rgba(94,196,230,0.25); }
        .terms-checkbox input[type="checkbox"] { width: 20px; height: 20px; min-width: 20px; margin-top: 1px; accent-color: var(--primary-light); cursor: pointer; }
        .terms-checkbox label { font-size: 13px; color: var(--gray-600); line-height: 1.5; cursor: pointer; user-select: none; }
        .terms-checkbox label a { color: var(--primary-light); font-weight: 700; text-decoration: none; transition: var(--transition); }
        .terms-checkbox label a:hover { color: var(--primary); text-decoration: underline; }

        /* ===== SWITCH PROMPT ===== */
        .switch-prompt {
            text-align: center; margin-top: 20px; padding-top: 20px;
            border-top: 1px solid var(--gray-200);
            font-size: 14px; color: var(--gray-500);
        }
        .switch-prompt a {
            color: var(--primary-light); font-weight: 700; text-decoration: none;
            transition: var(--transition); cursor: pointer;
        }
        .switch-prompt a:hover { color: var(--primary); text-decoration: underline; }

        /* ===== ALERT ===== */
        .auth-alert { padding: 12px 16px; border-radius: var(--radius-sm); font-size: 13px; font-weight: 700; margin-bottom: 20px; display: flex; align-items: center; gap: 8px; border: none; }
        .auth-alert.danger { background: #fff1f2; color: #be123c; }
        .auth-alert.success { background: #f0fdf4; color: #15803d; }


        /* ===== FOOTER ===== */
        .auth-footer {
            text-align: center; padding: 20px 40px 24px;
            border-top: 1px solid var(--gray-200);
        }
        .auth-footer p { margin: 0; color: var(--gray-400); font-size: 12px; }
        .auth-footer .footer-links { display: flex; justify-content: center; gap: 20px; margin-bottom: 10px; flex-wrap: wrap; }
        .auth-footer .footer-links a {
            color: var(--gray-500); font-size: 13px; font-weight: 600;
            text-decoration: none; transition: var(--transition); position: relative;
        }
        .auth-footer .footer-links a::before {
            content: ''; position: absolute; bottom: -2px; left: 0;
            width: 0; height: 2px; background: var(--gradient-accent); transition: var(--transition);
        }
        .auth-footer .footer-links a:hover { color: var(--primary); }
        .auth-footer .footer-links a:hover::before { width: 100%; }
        .auth-footer .footer-divider { border: none; height: 1px; background: linear-gradient(90deg,transparent,var(--gray-200),transparent); margin: 12px 0; }
        .auth-footer .copyright { font-size: 11px; color: var(--gray-400); }
    </style>
    <link rel="stylesheet" href="assets/pragmavet.css">
    <link href="assets/global-style.css" rel="stylesheet">
</head>
<body>

<div class="auth-wrapper">
    <?php if (isset($_SESSION['message'])): ?>
        <div class="auth-alert <?php echo $_SESSION['msg_type'] == 'success' ? 'success' : 'danger'; ?>" style="margin-bottom:16px;">
            <i class="fa fa-<?php echo $_SESSION['msg_type'] == 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
            <?php echo $_SESSION['message']; unset($_SESSION['message']); unset($_SESSION['msg_type']); ?>
        </div>
    <?php endif; ?>

    <div class="auth-card">
        <div class="auth-brand">
            <div class="brand-icon"><i class="fa fa-paw"></i></div>
            <h1>PragmaVet</h1>
            <p>Create your secure account</p>
        </div>

        <div class="auth-body">
            <form method="POST">
                <div class="mb-3">
                    <label class="saas-label"><i class="fa fa-user"></i> Full Name</label>
                    <input type="text" name="fullname" class="form-control" placeholder="E.g. John Doe"
                        value="<?php echo isset($_SESSION['reg_fullname']) ? htmlspecialchars($_SESSION['reg_fullname']) : ''; ?>" required>
                </div>
                <div class="mb-3">
                    <label class="saas-label"><i class="fa fa-envelope"></i> Email Address</label>
                    <input type="email" name="email" class="form-control" placeholder="you@example.com"
                        value="<?php echo isset($_SESSION['reg_email']) ? htmlspecialchars($_SESSION['reg_email']) : ''; ?>" required>
                </div>
                <div class="mb-3">
                    <label class="saas-label"><i class="fa fa-map-marker-alt"></i> Home Address</label>
                    <textarea name="address" class="form-control" placeholder="123 Main St, City" rows="2" required></textarea>
                </div>
                <div class="mb-3">
                    <label class="saas-label"><i class="fa fa-key"></i> Create Password</label>
                    <input type="password" name="password" class="form-control"
                        placeholder="8+ characters, lowercase & numbers only"
                        pattern="[a-z0-9]{8,}"
                        title="At least 8 characters using lowercase letters and numbers only" required>
                    <div class="password-hint"><i class="fa fa-circle-info"></i> Min. 8 characters (lowercase letters & numbers only)</div>
                </div>

                <!-- CLICKWRAP TERMS CHECKBOX -->
                <div class="terms-checkbox">
                    <input type="checkbox" id="agreeTerms" onchange="toggleRegisterButton()">
                    <label for="agreeTerms">
                        I agree to the Terms & Conditions and Privacy Policy.
                    </label>
                </div>

                <button type="submit" name="register" class="btn-gradient ripple" id="registerBtn" disabled>
                    <i class="fa fa-user-plus me-1"></i> Create Account
                </button>

                <div class="switch-prompt">
                    Already have an account?
                    <a href="index.php">Login Here</a>
                </div>
            </form>
        </div>

        <!-- FOOTER WITH LEGAL LINKS -->
        <div class="auth-footer">
            
            <hr class="footer-divider">
            <p class="copyright">&copy; <?php echo date('Y'); ?> PragmaVet Veterinary Management System</p>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleRegisterButton() {
    document.getElementById('registerBtn').disabled = !document.getElementById('agreeTerms').checked;
}
function validateRegistration() {
    if (!document.getElementById('agreeTerms').checked) {
        alert('You must agree to the Terms & Conditions and Privacy Policy to create an account.');
        return false;
    }
    return true;
}

// Ripple effect (from index.php)
document.addEventListener('click',function(e){const t=e.target.closest('.ripple');if(!t)return;const r=t.getBoundingClientRect(),s=Math.max(r.width,r.height),sp=document.createElement('span');sp.className='ripple-effect';sp.style.width=sp.style.height=s+'px';sp.style.left=(e.clientX-r.left-s/2)+'px';sp.style.top=(e.clientY-r.top-s/2)+'px';t.appendChild(sp);setTimeout(function(){sp.remove();},600);});
</script>
<style>
.ripple { position: relative; overflow: hidden; }
.ripple-effect { position: absolute; border-radius: 50%; background: rgba(255,255,255,0.4); transform: scale(0); animation: rippleAnim 0.6s linear; pointer-events: none; }
@keyframes rippleAnim { to { transform: scale(4); opacity: 0; } }
</style>
    <script src="mousetrail.js"></script>
</body>
</html>