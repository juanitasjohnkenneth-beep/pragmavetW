<?php
session_start();
include("dbconnection.php");

if(!isset($_SESSION['user_id'])){
    header("Location: index.php");
    exit();
}
include_once("includes/rbac_guard.php");
// RBAC: Client Portal access control
$user_role = rbac_get_role();
if (!in_array($user_role, ['user', 'client'])) {
    header("Location: " . rbac_home_url($user_role));
    exit();
}


$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user'];

$msg = "";
$msg_type = "";

// Handle Core Setting Updates
if(isset($_POST['update_profile'])) {
    $fullname = trim($_POST['fullname']);
    $contact = trim($_POST['contact_number']);
    $address = trim($_POST['home_address']);
    
    // Optional new profile picture (JPG, PNG or WEBP, up to 5 MB)
    $pic_sql = '';
    $pic_err = '';
    if (!empty($_FILES['profile_pic']['name'])) {
        $f = $_FILES['profile_pic'];
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if ($f['error'] !== 0 || !in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) || $f['size'] > 5 * 1024 * 1024 || !@getimagesize($f['tmp_name'])) {
            $pic_err = 'The profile picture must be a JPG, PNG or WEBP image up to 5 MB.';
        } else {
            if (!is_dir('uploads/profiles')) @mkdir('uploads/profiles', 0777, true);
            $pic_name = uniqid() . '.' . $ext;
            if (move_uploaded_file($f['tmp_name'], 'uploads/profiles/' . $pic_name)) $pic_sql = ", profile_picture='" . $conn->real_escape_string($pic_name) . "'";
            else $pic_err = 'The profile picture could not be uploaded. Please try again.';
        }
    }

    $stmt = $conn->prepare("UPDATE users SET fullname=?, contact_number=?, address=? $pic_sql WHERE id=?");
    $stmt->bind_param("sssi", $fullname, $contact, $address, $user_id);
    if ($fullname === '') {
        $msg = "Please enter your full name.";
        $msg_type = "warning";
    } elseif ($pic_err) {
        $msg = $pic_err;
        $msg_type = "warning";
    } elseif($stmt->execute()) {
        $_SESSION['user'] = $fullname; // Update session cache
        $user_name = $fullname;
        $msg = "Profile information updated securely.";
        $msg_type = "success";
    } else {
        $msg = "Warning: Failed to process update request.";
        $msg_type = "danger";
    }
}

// Handle Password Alterations
if(isset($_POST['update_password'])) {
    $old_pass = $_POST['old_password'];
    $new_pass = $_POST['new_password'];
    $confirm_pass = $_POST['confirm_password'];
    
    // Acquire current footprint
    $stmt = $conn->prepare("SELECT password FROM users WHERE id=?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $curr_hash = $stmt->get_result()->fetch_assoc()['password'];
    
    if(password_verify($old_pass, $curr_hash)) {
        if (!preg_match('/^(?=.*\d)(?=.*[a-z])(?=.*[A-Z])(?=.*[\W_]).{8,}$/', $new_pass)) {
            $msg = "Password must be at least 8 chars long with 1 uppercase, 1 lowercase, 1 number, and 1 special character.";
            $msg_type = "warning";
        } elseif($new_pass === $confirm_pass) {
            $new_hash = password_hash($new_pass, PASSWORD_DEFAULT);
            $up_stmt = $conn->prepare("UPDATE users SET password=? WHERE id=?");
            $up_stmt->bind_param("si", $new_hash, $user_id);
            if($up_stmt->execute()) {
                $msg = "Security clearance updated. New password mapped.";
                $msg_type = "success";
            }
        } else {
            $msg = "Integrity error: New passwords do not match.";
            $msg_type = "warning";
        }
    } else {
        $msg = "Authentication failure: Incorrect current password.";
        $msg_type = "danger";
    }
}

// Retrieve Fresh Data
$stmt = $conn->prepare("SELECT * FROM users WHERE id=?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$my_data = $stmt->get_result()->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script src="theme.js?v=<?=time()?>"></script>
<link rel="stylesheet" href="theme.css?v=<?=time()?>">

<title>Account Settings | PragmaVet</title>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

<style>
:root { --primary: #2563eb; --secondary: #3b82f6; --dark: #1e40af; --sidebar-width:260px; --bg-color: #F4F9FB; --card-bg: #ffffff; --text-main: #333333; --text-heading: #1e40af; --border-color: #edf2f6; --input-bg: #fafcff; }


body { background: var(--bg-color); font-family: 'Inter', sans-serif; color: var(--text-main); overflow-x: hidden; transition: background-color 0.4s ease, color 0.4s ease; }

/* SIDEBAR */
.sidebar { background:#0f172a; width:var(--sidebar-width); height:100vh; position:fixed; top:0; left:0; padding:24px 16px; z-index:1000; display:flex; flex-direction:column; box-shadow:5px 0 20px rgba(0,0,0,.2); overflow-y:auto; }
.sidebar h4 { color:#ffffff; font-weight:800; font-size:22px; margin-bottom:30px; letter-spacing:0.5px; display:flex; align-items:center; gap:10px; }
.sidebar-user { padding: 15px; background: rgba(255,255,255,0.05); border-radius: 12px; margin-bottom: 30px; margin-top: 20px; }
.sidebar a { display:flex; align-items:center; gap:10px; padding:12px 16px; border-radius:8px; color:#94a3b8; text-decoration:none; margin-bottom:4px; font-weight:600; font-size:15px; transition:.2s; }
.sidebar a i { width: 30px; font-size: 18px; }
.sidebar a:hover, .sidebar a.active { background:#2563eb; color:#ffffff; }
.sidebar .logout-link { margin-top: 50px; color: #ff6b6b; }

.main-content { margin-left: var(--sidebar-width); padding: 40px 50px; min-height: 100vh; }
.page-header h2 { font-weight: 800; color: var(--text-heading); margin: 0; letter-spacing: -0.5px; }

.card-premium { background: var(--card-bg); border: none; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.03); padding: 35px; height: 100%; transition: background-color 0.4s ease; }
.card-premium h5 { font-weight: 800; color: var(--text-heading); margin-bottom: 25px; display: flex; align-items: center; }
.card-premium h5 i { margin-right: 12px; color: var(--primary); }

.form-control { border-radius: 12px; padding: 14px 20px; border: 1px solid var(--border-color); font-size: 15px; background: var(--input-bg); color: var(--text-main); transition: 0.3s; }
.form-control:focus { box-shadow: 0 0 0 4px rgba(74, 127, 196,0.15); border-color: var(--secondary); background: white; color: #333; }
.form-control:disabled { opacity: 0.7; cursor: not-allowed; }

.btn-primary { background: var(--primary); border: none; padding: 14px 20px; font-weight: 600; border-radius: 50px; transition: 0.3s; box-shadow: 0 5px 15px rgba(37, 99, 235, 0.2); }
.btn-primary:hover { background: var(--dark); transform: translateY(-2px); box-shadow: 0 8px 25px rgba(32, 44, 61, 0.3); }

.alert-container { position: fixed; top: 30px; right: 40px; z-index: 9999; animation: slideDown 0.5s ease-out forwards; }
@keyframes slideDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }

.sidebar::-webkit-scrollbar { width: 5px; }
.sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 10px; }
.sidebar::-webkit-scrollbar-track { background: transparent; }

/* ====== WHITE SIDEBAR THEME (Reference Mockup Match) ====== */
.sidebar {
    background: #ffffff !important;
    width: var(--sidebar-width, 260px) !important;
    height: 100vh !important;
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    padding: 28px 20px 24px !important;
    z-index: 1000 !important;
    display: flex !important;
    flex-direction: column !important;
    border-right: 1px solid #eef2f6 !important;
    box-shadow: none !important;
    overflow-y: auto !important;
}

.sidebar-brand {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    margin-bottom: 24px !important;
    padding: 0 4px !important;
    text-decoration: none !important;
}

.sidebar-brand-icon {
    width: 44px !important;
    height: 44px !important;
    min-width: 44px !important;
    border-radius: 13px !important;
    background: #2563eb !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    color: #ffffff !important;
    font-size: 20px !important;
    flex-shrink: 0 !important;
    box-shadow: 0 4px 14px rgba(37, 99, 235, 0.28) !important;
}

.sidebar-brand span {
    font-weight: 800 !important;
    color: #182032 !important;
    font-size: 22px !important;
    letter-spacing: -0.4px !important;
}

.sidebar-user {
    margin-bottom: 24px !important;
    padding: 15px 18px !important;
    border-radius: 14px !important;
    background: #f8fafc !important;
    border: 1px solid #eef2f6 !important;
}

.sidebar-user .welcome {
    font-size: 13px !important;
    color: #64748b !important;
    font-weight: 500 !important;
    line-height: 1.3 !important;
}

.sidebar-user .uname {
    font-weight: 700 !important;
    color: #0f172a !important;
    font-size: 15.5px !important;
    margin-top: 3px !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
}

.sidebar a {
    display: flex !important;
    align-items: center !important;
    gap: 14px !important;
    padding: 13px 18px !important;
    border-radius: 12px !important;
    color: #334155 !important;
    text-decoration: none !important;
    margin-bottom: 4px !important;
    font-weight: 600 !important;
    font-size: 15px !important;
    transition: all 0.2s ease !important;
    letter-spacing: -0.1px !important;
}

.sidebar a i {
    width: 22px !important;
    text-align: center !important;
    font-size: 17px !important;
    color: #64748b !important;
    transition: color 0.2s !important;
    flex-shrink: 0 !important;
}

.sidebar a:hover {
    background: #f8fafc !important;
    color: #0f172a !important;
}

.sidebar a:hover i {
    color: #2563eb !important;
}

.sidebar a.active {
    background: #2563eb !important;
    color: #ffffff !important;
    font-weight: 700 !important;
    box-shadow: 0 4px 14px rgba(37, 99, 235, 0.28) !important;
}

.sidebar a.active i {
    color: #ffffff !important;
}

.sidebar .sidebar-logout {
    margin-top: auto !important;
    padding-top: 20px !important;
    border-top: 1px solid #f1f5f9 !important;
}

.sidebar .sidebar-logout a {
    color: #dc2626 !important;
    font-weight: 600 !important;
    margin-bottom: 0 !important;
}

.sidebar .sidebar-logout a i {
    color: #dc2626 !important;
}

.sidebar .sidebar-logout a:hover {
    background: #fef2f2 !important;
    color: #b91c1c !important;
}

.sidebar .sidebar-logout a:hover i {
    color: #b91c1c !important;
}

.sidebar::-webkit-scrollbar { width: 5px; }
.sidebar::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.06); border-radius: 10px; }
.sidebar::-webkit-scrollbar-track { background: transparent; }
</style>
    <link rel="stylesheet" href="assets/pragmavet.css">
    <link href="assets/global-style.css" rel="stylesheet">
</head>
<body>

<?php if($msg != ""): ?>
<div class="alert-container">
    <div class="alert alert-<?php echo $msg_type; ?> shadow-sm fw-bold" style="border-radius: 12px; border: none;">
        <i class="fa fa-info-circle me-2"></i><?php echo $msg; ?>
    </div>
</div>
<?php endif; ?>

<!-- SIDEBAR -->
<div class="sidebar">
        <div class="sidebar-brand">
            <div class="sidebar-brand-icon"><i class="fa fa-paw"></i></div>
            <span>PragmaVet</span>
        </div>
        <div class="sidebar-user">
            <div class="welcome">Welcome back,</div>
            <div class="uname"><?php echo htmlspecialchars($user_name ?? ''); ?></div>
        </div>
        <a href="dashboard.php"><i class="fa fa-home"></i> <span>Dashboard</span></a>
        <a href="appointments.php"><i class="fa fa-calendar-alt"></i> <span>Appointments</span></a>
        <a href="boarding.php"><i class="fa fa-hotel"></i> <span>Pet Boarding</span></a>
        <a href="pets.php"><i class="fa fa-notes-medical"></i> <span>Profile & Pets</span></a>
        <a href="billing.php"><i class="fa fa-file-invoice-dollar"></i> <span>Billing</span></a>
        <a href="settings.php" class="active"><i class="fa fa-cog"></i> <span>Account Settings</span></a>
        <div class="sidebar-logout"><a href="logout.php"><i class="fa fa-sign-out-alt"></i> <span>Logout</span></a></div>
    </div>

<div class="main-content">
    <div class="page-header mb-4 d-flex justify-content-between align-items-center">
        <div>
            <h2>User Settings</h2>
            <p class="text-muted mb-0">Manage security clearances and demographic payloads</p>
        </div>
        
        <!-- Extruded Dark Mode Logic to sync with System Config -->
        <button class="btn btn-sm btn-outline-secondary rounded-circle shadow-sm" id="themeToggle" style="width: 45px; height: 45px; font-size: 18px;">
            <i class="fa fa-moon" id="themeIcon"></i>
        </button>
    </div>

    <div class="row g-4">
        <!-- Demographics Form -->
        <div class="col-xl-7">
            <div class="card-premium">
                <h5><i class="fa fa-id-card"></i> Demographic Profile</h5>
                <form method="POST" enctype="multipart/form-data">
                    <div class="mb-3 d-flex align-items-center gap-3">
                        <?php $my_pic = !empty($my_data['profile_picture']) && file_exists('uploads/profiles/' . $my_data['profile_picture']) ? 'uploads/profiles/' . $my_data['profile_picture'] : ''; ?>
                        <?php if ($my_pic): ?>
                            <img src="<?php echo htmlspecialchars($my_pic); ?>" alt="Profile picture" class="border shadow-sm" style="width:64px; height:64px; border-radius:50%; object-fit:cover; flex-shrink:0;">
                        <?php endif; ?>
                        <div class="flex-grow-1">
                            <label class="form-label text-muted small fw-bold">PROFILE PICTURE</label>
                            <input type="file" name="profile_pic" class="form-control" accept="image/jpeg,image/png,image/webp">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-muted small fw-bold">REGISTERED EMAIL (Read-Only)</label>
                        <input type="email" class="form-control" value="<?php echo htmlspecialchars($my_data['email']); ?>" disabled>
                        <small class="text-muted d-block mt-1"><i class="fa fa-lock me-1"></i> Email cannot be changed natively.</small>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6 mb-3">
                            <label class="form-label text-muted small fw-bold">FULL NAME</label>
                            <input type="text" name="fullname" class="form-control" value="<?php echo htmlspecialchars($my_data['fullname']); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label text-muted small fw-bold">CONTACT NUMBER</label>
                            <input type="text" name="contact_number" class="form-control" value="<?php echo htmlspecialchars($my_data['contact_number'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label text-muted small fw-bold">HOME ADDRESS</label>
                        <textarea name="home_address" class="form-control" rows="3"><?php echo htmlspecialchars($my_data['address'] ?? ''); ?></textarea>
                    </div>
                    <button type="submit" name="update_profile" class="btn btn-primary w-100 py-3 shadow-sm rounded-pill" style="background:#5b21b6;color:#fff;border:none;box-shadow:0 4px 14px rgba(91,33,182,0.3);"><i class="fa fa-user-edit me-2"></i> Update Demographic Cache</button>
                </form>
            </div>
        </div>

        <!-- Security Gateway -->
        <div class="col-xl-5">
            <div class="card-premium border-top border-4 border-danger">
                <h5><i class="fa fa-shield-alt text-danger"></i> Vault Security</h5>
                <p class="text-muted small mb-4">Modify your password logic immediately if you suspect a breach.</p>
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label text-muted small fw-bold">CURRENT PASSWORD</label>
                        <input type="password" name="old_password" class="form-control" required placeholder="Verify identity...">
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-muted small fw-bold">NEW PASSWORD</label>
                        <input type="password" name="new_password" class="form-control" required placeholder="8+ chars, 1 uppercase, 1 number, 1 special sign" pattern="(?=.*\d)(?=.*[a-z])(?=.*[A-Z])(?=.*[\W_]).{8,}" title="Must contain at least 8 chars, one uppercase, one lowercase, one number and one special character">
                    </div>
                    <div class="mb-4">
                        <label class="form-label text-muted small fw-bold">CONFIRM NEW PASSWORD</label>
                        <input type="password" name="confirm_password" class="form-control" required placeholder="Repeat exact keystrokes...">
                    </div>
                    <button type="submit" name="update_password" class="btn btn-danger w-100 py-3 shadow-sm rounded-pill" style="background:#5b21b6;color:#fff;border:none;box-shadow:0 4px 14px rgba(91,33,182,0.3);"><i class="fa fa-key me-2"></i> Change Password</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// Dark Mode Setup
document.addEventListener('DOMContentLoaded', function() {
    const themeToggleBtn = document.getElementById("themeToggle");
    const themeIcon = document.getElementById("themeIcon");

    function setTheme(themeName) {
        localStorage.setItem('theme', themeName);
        document.documentElement.setAttribute('data-theme', themeName);
        if (themeName === 'dark') {
            themeIcon.classList.remove("fa-moon");
            themeIcon.classList.add("fa-sun");
        } else {
            themeIcon.classList.remove("fa-sun");
            themeIcon.classList.add("fa-moon");
        }
    }

    if (localStorage.getItem('theme') === 'dark') setTheme('dark');
    else setTheme('light');

    if(themeToggleBtn) {
        themeToggleBtn.addEventListener('click', () => {
            if (localStorage.getItem('theme') === 'dark') setTheme('light');
            else setTheme('dark');
        });
    }
});

// Auto-Dismiss Alerts
setTimeout(function() {
    let alerts = document.querySelectorAll('.alert-container');
    alerts.forEach(a => {
        a.style.transition = 'opacity 0.5s ease-out, transform 0.5s ease-out';
        a.style.opacity = '0';
        a.style.transform = 'translateY(-20px)';
        setTimeout(() => a.remove(), 500);
    });
}, 4500);
</script>

<?php include("chat_widget.php"); ?>
    <script src="mousetrail.js"></script>
    <?php include_once("includes/reminder_popup.php"); ?>
</body>
</html>

