<?php
session_start();
include("dbconnection.php");

$token = $_GET['token'] ?? '';

if (empty($token)) {
    header("Location: index.php");
    exit();
}

$stmt = $conn->prepare("SELECT email, expires_at FROM password_resets WHERE token=?");
$stmt->bind_param("s", $token);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    $error = "The provided recovery token is completely invalid or corrupted.";
} else {
    $resetData = $result->fetch_assoc();
    if (strtotime($resetData['expires_at']) < time()) {
        $error = "This recovery link has expired. Please request a new one.";
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($error)) {
    $new_pass = $_POST['new_pass'];
    $confirm_pass = $_POST['confirm_pass'];
    
    if($new_pass !== $confirm_pass) {
        $error = "Cryptographic mismatch between standard and confirmation entry.";
    } elseif (!preg_match('/^(?=.*\d)(?=.*[a-z])(?=.*[A-Z])(?=.*[\W_]).{8,}$/', $new_pass)) {
        $error = "Security Policy: Password must be at least 8 chars long with 1 uppercase, 1 lowercase, 1 number, and 1 special character.";
    } else {
        $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
        $email = $resetData['email'];
        
        // Update user
        $update = $conn->prepare("UPDATE users SET password=? WHERE email=?");
        $update->bind_param("ss", $hashed, $email);
        
        if ($update->execute()) {
            // Delete Token & Reset Lockout counters so they can immediately login
            $conn->query("DELETE FROM password_resets WHERE email='$email'");
            $conn->query("UPDATE users SET failed_attempts=0, locked_until=NULL WHERE email='$email'");

            $_SESSION['message'] = "Password vault successfully resealed. Welcome back.";
            $_SESSION['msg_type'] = "success";
            $_SESSION['show_modal'] = 'login';
            header("Location: index.php");
            exit();
        } else {
            $error = "Database integrity check failed during commit.";
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reset Vault Password | PragmaVet</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

<style>
:root { --primary: #2563eb; --secondary: #3b82f6; --dark: #1e40af; --light-bg: #E6EEF8; }
body { background: var(--light-bg); font-family: 'Inter', sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; overflow: hidden; }

.auth-card {
    background: white;
    border-radius: 20px;
    padding: 50px 40px;
    width: 100%;
    max-width: 480px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.08);
    position: relative;
    z-index: 2;
    overflow: hidden;
}

/* Background blob */
.blobs { position: absolute; width: 500px; height: 500px; background: linear-gradient(135deg, rgba(37, 99, 235,0.1) 0%, rgba(74, 127, 196,0.05) 100%); border-radius: 50%; top: -250px; right: -250px; z-index: 1; filter: blur(40px); }

.form-control { border-radius: 12px; padding: 14px 20px; border: 1px solid #dbe5ec; font-size: 15px; background: #fafcff; }
.form-control:focus { outline: none; border-color: var(--secondary); box-shadow: 0 0 0 4px rgba(74, 127, 196,0.1); }
</style>
<link href="assets/global-style.css" rel="stylesheet">
</head>
<body>
<div class="blobs"></div>

<div class="auth-card">
    <div class="text-center mb-4 pb-2">
        <i class="fa fa-fingerprint fa-3x text-primary mb-3"></i>
        <h3 class="fw-bold" style="color: var(--dark); letter-spacing: -0.5px;">Reseal Vault Pattern</h3>
        <p class="text-muted small">Choose a new cryptographic passphrase to regain full access.</p>
    </div>

    <?php if(isset($error)): ?>
        <div class="alert alert-danger border-0 small fw-bold text-center mb-4" style="border-radius: 10px; background: rgba(250,82,82,0.1); color: #c92a2a;">
            <i class="fa fa-exclamation-triangle me-1"></i> <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <?php if(!isset($error) || (isset($error) && str_contains($error, 'Security Policy') || str_contains($error, 'Cryptographic mismatch'))): ?>
    <form method="POST">
        <div class="mb-3">
            <label class="form-label text-muted small fw-bold">PROPOSE SECURE PASSWORD</label>
            <input type="password" name="new_pass" class="form-control" placeholder="8+ chars, 1 uppercase, 1 numeric, 1 special tag" pattern="(?=.*\d)(?=.*[a-z])(?=.*[A-Z])(?=.*[\W_]).{8,}" title="Must contain at least 8 chars, one uppercase, one lowercase, one number and one special character" required autofocus>
        </div>
        <div class="mb-4">
            <label class="form-label text-muted small fw-bold">CONFIRM IDENTITY SEQUENCE</label>
            <input type="password" name="confirm_pass" class="form-control" placeholder="Identical sequence requirement" required>
        </div>
        <button type="submit" name="finalize_reset" class="btn btn-primary w-100 fw-bold shadow-sm rounded-pill mb-3" style="padding: 14px; background: var(--primary); border: none;">
            <i class="fa fa-key me-2"></i> Finalize Authorization Shift
        </button>
    </form>
    <?php else: ?>
        <div class="text-center">
            <a href="forgot_password.php" class="btn btn-outline-secondary rounded-pill px-4 fw-bold">Request New Code</a>
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
