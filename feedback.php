<?php
session_start();
include("dbconnection.php");

if(!isset($_SESSION['user_id'])){
    header("Location: index.php");
    exit();
}
include_once("includes/rbac_guard.php");
include_once("includes/client_notify.php");
// RBAC: Client Portal access control
$user_role = rbac_get_role();
if (!in_array($user_role, ['user', 'client'])) {
    header("Location: " . rbac_home_url($user_role));
    exit();
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user'];

// Submit Feedback
if(isset($_POST['submit_review'])){
    $apt_id = (int)($_POST['appointment_id'] ?? 0);
    $rating = (int)($_POST['rating'] ?? 0);
    $comments = trim($_POST['comments'] ?? '');

    // Only a 1-5 rating, for the client's own completed visit, once
    $own = $conn->prepare("SELECT 1 FROM (
                               SELECT id, user_id, status FROM appointments
                               UNION ALL
                               SELECT id, user_id, status FROM boarding_reservations
                           ) a
                           WHERE a.id = ? AND a.user_id = ? AND a.status = 'Completed'
                             AND a.id NOT IN (SELECT appointment_id FROM feedbacks)");
    $own->bind_param("ii", $apt_id, $user_id);
    $own->execute();

    if ($rating >= 1 && $rating <= 5 && $own->get_result()->num_rows > 0) {
        $stmt = $conn->prepare("INSERT INTO feedbacks(appointment_id, user_id, rating, comments) VALUES(?,?,?,?)");
        $stmt->bind_param("iiis", $apt_id, $user_id, $rating, $comments);
        if($stmt->execute()){
            $_SESSION['message'] = "Thank you for your valuable feedback!";
            notify_appt_vet($conn, $apt_id, "$user_name rated visit #$apt_id $rating/5" . ($comments !== '' ? ": \"" . mb_strimwidth($comments, 0, 140, '...') . "\"" : '.'));
            $_SESSION['msg_type'] = "success";
        }
    }
    header("Location: feedback.php");
    exit();
}

$pending_reviews = $conn->query("
    SELECT a.* 
    FROM (
        SELECT id, user_id, pet_name, service_type, appointment_date, status FROM appointments
        UNION ALL
        SELECT id, user_id, pet_name, service_type, appointment_date, status FROM boarding_reservations
    ) a 
    WHERE a.user_id = $user_id AND a.status='Completed' AND a.id NOT IN (SELECT appointment_id FROM feedbacks)
    ORDER BY a.appointment_date DESC
");


?>
<!DOCTYPE html>
<html lang="en">
<head>
<script src="theme.js?v=<?=time()?>"></script>
<link rel="stylesheet" href="theme.css?v=<?=time()?>">

<title>Reviews & Feedback | PragmaVet</title>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<style>
:root { --primary: #2563eb; --secondary: #3b82f6; --dark: #1e40af; --sidebar-width:260px; }
body { background: #F4F9FB; font-family: 'Inter', sans-serif; }
.sidebar { background:#0f172a; width:var(--sidebar-width); height:100vh; position:fixed; top:0; left:0; padding:24px 16px; z-index:1000; display:flex; flex-direction:column; box-shadow:5px 0 20px rgba(0,0,0,.2); overflow-y:auto; }
.sidebar h4 { color:#ffffff; font-weight:800; font-size:22px; margin-bottom:30px; letter-spacing:0.5px; display:flex; align-items:center; gap:10px; }
.sidebar-user { padding: 15px; background: rgba(255,255,255,0.05); border-radius: 12px; margin-bottom: 30px; margin-top: 20px; }
.sidebar a { display:flex; align-items:center; gap:10px; padding:12px 16px; border-radius:8px; color:#94a3b8; text-decoration:none; margin-bottom:4px; font-weight:600; font-size:15px; transition:.2s; }
.sidebar a i { width: 30px; font-size: 18px; }
.sidebar a:hover, .sidebar a.active { background:#2563eb; color:#ffffff; }
.sidebar .logout-link { position: absolute; bottom: 30px; width: calc(100% - 40px); color: #ff6b6b; }
.main-content { margin-left: var(--sidebar-width); padding: 40px 50px; min-height: 100vh; }
.card-premium { background: white; border: none; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.03); padding: 35px; margin-bottom: 30px;}
.star-rating i { color: #CBD5E1; font-size: 24px; cursor: pointer; transition: 0.2s; }
.star-rating i:hover, .star-rating i.active { color: #F59E0B; transform: scale(1.1); }
.alert-container { position: fixed; top: 30px; right: 40px; z-index: 9999; animation: slideDown 0.5s ease; }
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

<?php if(isset($_SESSION['message'])): ?>
<div class="alert-container">
    <div class="alert alert-<?php echo $_SESSION['msg_type']; ?> shadow-sm">
        <?php echo $_SESSION['message']; unset($_SESSION['message'], $_SESSION['msg_type']); ?>
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
        <a href="settings.php"><i class="fa fa-cog"></i> <span>Account Settings</span></a>
        <div class="sidebar-logout"><a href="logout.php"><i class="fa fa-sign-out-alt"></i> <span>Logout</span></a></div>
    </div>

<div class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold text-dark m-0">Leave Feedback</h2>
            <p class="text-muted">Rate your recent clinic visits to help us improve</p>
        </div>
        <div class="d-flex align-items-center">
            <div class="notif-bell" onclick="toggleNotifications()">
                <i class="fa fa-bell"></i>
                <span class="notif-badge" id="notifBadge" style="display:none;">0</span>
                <div class="notif-dropdown" id="notifDropdown">
                    <div class="notif-header">
                        Notifications
                        <button class="btn btn-sm btn-light p-1" onclick="markAllRead(event)"><i class="fa fa-check-double text-success"></i></button>
                    </div>
                    <div class="notif-body" id="notifList">
                        <div class="text-center text-muted small p-3">Loading...</div>
                    </div>
                </div>

            </div>

            <!-- Dark Mode Toggle -->
            <button class="btn btn-sm btn-outline-secondary ms-3 rounded-circle shadow-sm" id="themeToggle" style="width: 40px; height: 40px; z-index: 100;">
                <i class="fa fa-moon" id="themeIcon"></i>
            </button>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-8 col-lg-10 mx-auto">
            <div class="card-premium">
                <h5 class="fw-bold mb-4"><i class="fa fa-clipboard-list text-primary me-2"></i> Pending Reviews</h5>
                <?php if($pending_reviews->num_rows > 0): ?>
                    <?php while($row = $pending_reviews->fetch_assoc()): ?>
                    <form method="POST" class="border rounded p-4 mb-3 bg-light">
                        <input type="hidden" name="appointment_id" value="<?php echo $row['id']; ?>">
                        
                        <div class="d-flex justify-content-between align-items-center mb-3 border-bottom pb-2">
                            <h6 class="fw-bold m-0"><i class="fa fa-paw me-2 text-secondary"></i> <?php echo htmlspecialchars($row['pet_name']); ?></h6>
                            <span class="text-muted small"><?php echo date("M d, Y", strtotime($row['appointment_date'])); ?></span>
                        </div>
                        
                        <div class="mb-3 text-center">
                            <label class="form-label small fw-bold text-muted d-block">Overall Rating (1-5)</label>
                            <!-- Simple radio implementation for standard HTML forms -->
                            <div class="d-flex justify-content-center gap-3">
                                <label><input type="radio" name="rating" value="1" required> 1 <i class="fa fa-star text-warning"></i></label>
                                <label><input type="radio" name="rating" value="2"> 2 <i class="fa fa-star text-warning"></i></label>
                                <label><input type="radio" name="rating" value="3"> 3 <i class="fa fa-star text-warning"></i></label>
                                <label><input type="radio" name="rating" value="4"> 4 <i class="fa fa-star text-warning"></i></label>
                                <label><input type="radio" name="rating" value="5"> 5 <i class="fa fa-star text-warning"></i></label>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <textarea name="comments" class="form-control" rows="3" placeholder="Tell us about your experience..."></textarea>
                        </div>
                        
                        <button type="submit" name="submit_review" class="btn btn-primary w-100 fw-bold">Submit Review</button>
                    </form>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="text-center py-5">
                        <i class="fa fa-check-circle fa-4x text-success opacity-25 mb-3"></i>
                        <h6 class="text-muted">You're all caught up!</h6>
                        <p class="text-muted small">No pending appointments to review.</p>
                    </div>
                <?php endif; ?>
            </div>

    </div>
</div>

<?php include("chat_widget.php"); ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<!-- Auto Dismiss Alerts -->
<script>
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
    <?php include_once("includes/reminder_popup.php"); ?>
</body>
</html>
