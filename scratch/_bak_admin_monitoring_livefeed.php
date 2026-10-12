<?php
session_start();
include("dbconnection.php");

$user_role = $_SESSION['role'] ?? '';
$is_admin = ($user_role === 'admin');
$is_owner = ($user_role === 'clinic_owner' || $user_role === 'owner');
$is_staff = ($user_role === 'staff');
$is_doctor = ($user_role === 'doctor');

if(!isset($_SESSION['user_id']) || (!$is_admin && !$is_owner && !$is_staff && !$is_doctor)){
    header("Location: index.php");
    exit();
}

// ── Pets boarding today, with their cage camera ──
$boarded = [];
$bq = $conn->query("
    SELECT br.id as res_id, br.cage_number, br.pet_name, br.appointment_date, br.end_date,
           br.status, br.cctv_link, br.cctv_enabled, u.fullname as owner_name
    FROM boarding_reservations br
    JOIN users u ON br.user_id = u.id
    WHERE br.service_type = 'Pet Boarding Reservation'
      AND (br.status = 'Checked In'
           OR (br.status = 'Approved' AND br.appointment_date <= CURDATE() AND br.end_date >= CURDATE()))
    ORDER BY br.cage_number ASC
");
if ($bq) while ($b = $bq->fetch_assoc()) $boarded[] = $b;

// Camera stream URL as the client page plays it (IP-camera apps serve MJPEG at /video)
function lf_stream($link) {
    $link = trim((string)$link);
    if (preg_match('#:(8080|4747|8000|8888|1234)/?$#', $link)) $link = rtrim($link, '/') . '/video';
    return $link;
}
function lf_is_mjpeg($link) {
    return strpos($link, '/video') !== false || strpos($link, '.mjpg') !== false || strpos($link, 'videofeed') !== false;
}
$cam_live = 0; $cam_off = 0; $cam_none = 0;
foreach ($boarded as $b) {
    if (empty($b['cctv_link'])) $cam_none++;
    elseif ((int)$b['cctv_enabled'] === 1) $cam_live++;
    else $cam_off++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script src="theme.js?v=<?=time()?>"></script>
<link rel="stylesheet" href="theme.css?v=<?=time()?>">

<title>Live Feed | VetAdmin</title>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

<style>
:root {
    --primary: #2563eb; --secondary: #3b82f6; --dark: #1e40af; --light-bg: #f0f4f8;
    --sidebar-width:260px;
}
body { background: var(--light-bg); font-family: 'Inter', sans-serif; color: #2D3748; }
.main-content { margin-left: var(--sidebar-width); padding: 32px 36px; }
@media (max-width: 768px) { .main-content { margin-left: 0; padding: 16px; } }

.lf-head h2 { font-weight:800; font-size:26px; color:#0f172a; margin:0; letter-spacing:-.6px; -webkit-text-fill-color:#0f172a; background:none; }
.lf-head p { color:#64748b; font-size:14px; margin:4px 0 0; }
.lf-btn { border:1.5px solid #e2e8f0; border-radius:12px; padding:9px 16px; font-weight:700; font-size:13px; display:inline-flex; align-items:center; gap:8px; text-decoration:none; background:#fff !important; color:#334155 !important; }
.lf-btn:hover { border-color:#2563eb; color:#2563eb !important; }

.lf-stat { background:#fff; border-radius:16px; padding:16px 18px; box-shadow:0 2px 12px rgba(15,23,42,.05); border:1px solid #eef2f6; display:flex; align-items:center; gap:14px; height:100%; }
.lf-stat .ic { width:44px; height:44px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0; }
.lf-stat .v { font-size:22px; font-weight:800; color:#0f172a; line-height:1.1; }
.lf-stat .l { font-size:12px; color:#64748b; font-weight:600; }

.lf-card { background:#fff; border-radius:18px; overflow:hidden; box-shadow:0 4px 20px rgba(15,23,42,.06); border:1px solid #eef2f6; height:100%; display:flex; flex-direction:column; }
.lf-screen { position:relative; background:#0b1220; aspect-ratio:16/10; display:flex; align-items:center; justify-content:center; overflow:hidden; }
.lf-screen img, .lf-screen iframe { width:100%; height:100%; object-fit:contain; border:0; display:block; }
.lf-badge { position:absolute; top:12px; left:12px; display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border-radius:20px; font-size:11px; font-weight:800; letter-spacing:.05em; z-index:2; }
.lf-badge.live { background:#dc2626; color:#fff; }
.lf-badge.off  { background:rgba(255,255,255,.12); color:#cbd5e1; }
.lf-badge .dot { width:7px; height:7px; border-radius:50%; background:#fff; animation:lfPulse 1.4s infinite; }
@keyframes lfPulse { 0%,100%{opacity:1} 50%{opacity:.3} }
.lf-cage { position:absolute; top:12px; right:12px; background:rgba(15,23,42,.65); color:#fff; padding:4px 10px; border-radius:8px; font-size:11.5px; font-weight:700; z-index:2; }
.lf-full { position:absolute; bottom:12px; right:12px; width:34px; height:34px; border-radius:10px; border:none; background:rgba(15,23,42,.65) !important; color:#fff !important; display:flex; align-items:center; justify-content:center; z-index:2; cursor:pointer; }
.lf-full:hover { background:#2563eb !important; }
.lf-empty { text-align:center; color:#94a3b8; padding:20px; }
.lf-empty i { font-size:34px; opacity:.5; display:block; margin-bottom:10px; }
.lf-empty b { color:#e2e8f0; display:block; font-size:14px; margin-bottom:4px; }
.lf-empty span { font-size:12.5px; }
.lf-err { position:absolute; inset:0; display:none; align-items:center; justify-content:center; background:#0b1220; }
.lf-info { padding:14px 16px; display:flex; justify-content:space-between; align-items:center; gap:10px; }
.lf-pet { font-weight:800; color:#0f172a; font-size:15px; }
.lf-sub { font-size:12.5px; color:#64748b; }
.lf-dates { font-size:12px; color:#475569; font-weight:600; text-align:right; white-space:nowrap; }
.lf-dates .left { display:inline-block; margin-top:3px; background:#eff6ff; color:#1d4ed8; border-radius:20px; padding:1px 8px; font-size:11px; font-weight:700; }

.lf-none { background:#fff; border-radius:18px; border:1px solid #eef2f6; padding:56px 20px; text-align:center; box-shadow:0 4px 20px rgba(15,23,42,.05); }
.lf-none .ic { width:72px; height:72px; border-radius:20px; background:#eff6ff; color:#2563eb; display:inline-flex; align-items:center; justify-content:center; font-size:28px; margin-bottom:14px; }


        /* ====== WHITE SIDEBAR THEME ====== */
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
            padding: 12px 16px !important;
            border-radius: 12px !important;
            color: #334155 !important;
            text-decoration: none !important;
            margin-bottom: 4px !important;
            font-weight: 600 !important;
            font-size: 14.5px !important;
            transition: all 0.2s ease !important;
            letter-spacing: -0.1px !important;
        }

        .sidebar a i {
            width: 22px !important;
            text-align: center !important;
            font-size: 16px !important;
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

        .sidebar::-webkit-scrollbar { width: 5px; }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.06); border-radius: 10px; }
        .sidebar::-webkit-scrollbar-track { background: transparent; }
</style>
    <link rel="stylesheet" href="assets/pragmavet.css">
    <link href="assets/global-style.css" rel="stylesheet">
</head>
<body>

<?php include("includes/admin_sidebar.php"); ?>

<div class="main-content">

    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4 lf-head">
        <div>
            <h2><i class="fa fa-video me-2" style="font-size:22px;color:#dc2626;-webkit-text-fill-color:#dc2626;"></i>Live Feed</h2>
            <p>Live cage cameras of the pets boarding today.</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <button class="btn btn-sm btn-outline-secondary rounded-circle shadow-sm" id="themeToggle" style="width: 40px; height: 40px;">
                <i class="fa fa-moon" id="themeIcon"></i>
            </button>
            <a href="admin_boarding.php" class="lf-btn"><i class="fa fa-hotel"></i> Boarding Monitoring</a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3"><div class="lf-stat"><div class="ic" style="background:#eff6ff;color:#2563eb;"><i class="fa fa-paw"></i></div><div><div class="v"><?= count($boarded) ?></div><div class="l">Boarding today</div></div></div></div>
        <div class="col-6 col-xl-3"><div class="lf-stat"><div class="ic" style="background:#fef2f2;color:#dc2626;"><i class="fa fa-circle-dot"></i></div><div><div class="v"><?= $cam_live ?></div><div class="l">Cameras live</div></div></div></div>
        <div class="col-6 col-xl-3"><div class="lf-stat"><div class="ic" style="background:#f1f5f9;color:#475569;"><i class="fa fa-video-slash"></i></div><div><div class="v"><?= $cam_off ?></div><div class="l">Cameras turned off</div></div></div></div>
        <div class="col-6 col-xl-3"><div class="lf-stat"><div class="ic" style="background:#fff7ed;color:#ea580c;"><i class="fa fa-link-slash"></i></div><div><div class="v"><?= $cam_none ?></div><div class="l">No camera set</div></div></div></div>
    </div>

    <?php if ($boarded): ?>
    <div class="row g-4">
        <?php foreach ($boarded as $b):
            $days_left = max(0, (int)ceil((strtotime($b['end_date'] . ' 23:59:59') - time()) / 86400));
            $has_link  = !empty($b['cctv_link']);
            $is_live   = $has_link && (int)$b['cctv_enabled'] === 1;
            $stream    = $has_link ? lf_stream($b['cctv_link']) : '';
        ?>
        <div class="col-md-6 col-xxl-4">
            <div class="lf-card">
                <div class="lf-screen" id="lfScreen<?= (int)$b['res_id'] ?>">
                    <span class="lf-badge <?= $is_live ? 'live' : 'off' ?>"><?php if ($is_live): ?><span class="dot"></span> LIVE<?php else: ?><i class="fa fa-video-slash"></i> OFFLINE<?php endif; ?></span>
                    <?php if ($b['cage_number']): ?><span class="lf-cage"><i class="fa fa-hotel me-1"></i>Cage <?= str_pad((int)$b['cage_number'], 2, '0', STR_PAD_LEFT) ?></span><?php endif; ?>
                    <?php if ($is_live): ?>
                        <?php if (lf_is_mjpeg($stream)): ?>
                            <img src="<?= htmlspecialchars($stream) ?>" alt="Live camera — <?= htmlspecialchars($b['pet_name']) ?>" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                        <?php else: ?>
                            <iframe src="<?= htmlspecialchars($stream) ?>" allow="autoplay; fullscreen" title="Live camera — <?= htmlspecialchars($b['pet_name']) ?>"></iframe>
                        <?php endif; ?>
                        <div class="lf-err"><div class="lf-empty"><i class="fa fa-plug-circle-xmark"></i><b>Can't reach the camera</b><span>Check that the camera is on and on the clinic network.</span></div></div>
                        <button type="button" class="lf-full" title="Full screen" onclick="lfFull(<?= (int)$b['res_id'] ?>)"><i class="fa fa-expand"></i></button>
                    <?php elseif ($has_link): ?>
                        <div class="lf-empty"><i class="fa fa-video-slash"></i><b>Camera turned off</b><span>Vet staff can turn it on in Pet Boarding Care.</span></div>
                    <?php else: ?>
                        <div class="lf-empty"><i class="fa fa-link-slash"></i><b>No camera assigned</b><span>Vet staff can add the camera link in Pet Boarding Care.</span></div>
                    <?php endif; ?>
                </div>
                <div class="lf-info">
                    <div style="min-width:0;">
                        <div class="lf-pet"><i class="fa fa-paw text-primary me-1"></i><?= htmlspecialchars($b['pet_name']) ?></div>
                        <div class="lf-sub"><i class="fa fa-user me-1"></i><?= htmlspecialchars($b['owner_name']) ?></div>
                    </div>
                    <div class="lf-dates">
                        <?= date("M d", strtotime($b['appointment_date'])) ?> <i class="fa fa-arrow-right mx-1 opacity-50"></i> <?= date("M d", strtotime($b['end_date'])) ?><br>
                        <span class="left"><?= $days_left ?> day<?= $days_left === 1 ? '' : 's' ?> left</span>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="lf-none">
        <div class="ic"><i class="fa fa-video"></i></div>
        <h5 class="fw-bold text-dark mb-1">No pets are boarding today</h5>
        <p class="text-muted mb-3" style="font-size:14px;">Camera feeds appear here while a pet is staying at the clinic.</p>
        <a href="admin_boarding.php" class="lf-btn"><i class="fa fa-calendar-days"></i> View Boarding Schedule</a>
    </div>
    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function lfFull(id) {
    var el = document.getElementById('lfScreen' + id);
    if (!el) return;
    if (document.fullscreenElement) document.exitFullscreen();
    else if (el.requestFullscreen) el.requestFullscreen();
}
</script>
    <script src="mousetrail.js"></script>
</body>
</html>