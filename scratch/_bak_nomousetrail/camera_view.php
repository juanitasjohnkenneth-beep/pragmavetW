<?php
// camera_view.php — Standalone fullscreen camera viewer
// Access: http://yoursite/pragmavet/camera_view.php?id=RESERVATION_ID
// Works in any browser/app — no login required for viewing (read-only stream)
include("dbconnection.php");

$res_id = (int)($_GET['id'] ?? 0);

$row = null;
if($res_id){
    $r = $conn->query("
        SELECT br.id, br.user_id, br.status, br.pet_name, br.cage_number, br.cctv_link, br.cctv_enabled,
               br.appointment_date, br.end_date
        FROM boarding_reservations br
        WHERE br.id = $res_id
        LIMIT 1
    ");
    if($r) $row = $r->fetch_assoc();
}

// Only clinic staff, or the pet's owner while the stay is active, may watch (same rule as the Pet Boarding page)
$viewer_id = (int)($_SESSION['user_id'] ?? 0);
if (!$viewer_id) { header("Location: index.php"); exit(); }
if ($row && !in_array($_SESSION['role'] ?? '', ['admin', 'staff', 'doctor', 'clinic_owner', 'owner'], true)) {
    $stay_live = $row['status'] === 'Checked In'
              || ($row['status'] === 'Approved' && strtotime($row['appointment_date']) <= time() && strtotime($row['end_date'] . ' 23:59:59') >= time());
    if ((int)$row['user_id'] !== $viewer_id || !$stay_live) $row = null;
}

$stream_url = $row['cctv_link'] ?? '';
// Auto-normalize: bare port URL (IP Webcam style) → append /video for direct MJPEG stream
if($stream_url && preg_match('/:(?:8080|4747|8000|8888|1234)(\/?)?$/', $stream_url)){
    $stream_url = rtrim($stream_url,'/').'/video';
}
$pet_name   = $row['pet_name']  ?? 'Unknown';
$cage       = $row['cage_number'] ?? '?';
$enabled    = (int)($row['cctv_enabled'] ?? 0);
$is_mjpeg   = $stream_url && (
                strpos($stream_url, '/video')    !== false ||
                strpos($stream_url, '.mjpg')     !== false ||
                strpos($stream_url, '.cgi')      !== false ||
                strpos($stream_url, 'mjpeg')     !== false ||
                strpos($stream_url, 'videofeed') !== false
              );
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= htmlspecialchars($pet_name) ?> — Live Camera | PragmaVet</title>
<meta name="theme-color" content="#0a0a0a">
<style>
*{box-sizing:border-box;margin:0;padding:0;}
html,body{
    width:100%;height:100%;
    background:#0a0a0a;
    font-family:'Inter',system-ui,sans-serif;
    overflow:hidden;
}
.screen{
    width:100vw;height:100vh;
    display:flex;flex-direction:column;
}
/* Top bar */
.topbar{
    background:rgba(0,0,0,.7);
    padding:10px 18px;
    display:flex;align-items:center;justify-content:space-between;
    flex-shrink:0;
    z-index:10;
    backdrop-filter:blur(6px);
    -webkit-backdrop-filter:blur(6px);
}
.topbar-left{display:flex;align-items:center;gap:10px;}
.live-dot{width:10px;height:10px;border-radius:50%;background:#22c55e;
    box-shadow:0 0 0 0 rgba(34,197,94,.4);
    animation:pulse 1.5s infinite;}
@keyframes pulse{0%{box-shadow:0 0 0 0 rgba(34,197,94,.4);}70%{box-shadow:0 0 0 8px rgba(34,197,94,0);}100%{box-shadow:0 0 0 0 rgba(34,197,94,0);}}
.pet-label{color:#fff;font-size:14px;font-weight:700;}
.cage-label{color:#94a3b8;font-size:12px;}
.offline-dot{width:10px;height:10px;border-radius:50%;background:#ef4444;}

/* Camera area — fills remaining space */
.cam-area{
    flex:1;
    position:relative;
    display:flex;align-items:center;justify-content:center;
    background:#0a0a0a;
    overflow:hidden;
}
.cam-area img,
.cam-area iframe{
    position:absolute;
    top:0;left:0;
    width:100%;height:100%;
    object-fit:contain;
    border:0;
}
.offline-msg{
    text-align:center;color:#64748b;
}
.offline-msg i{font-size:60px;margin-bottom:16px;display:block;}
.offline-msg h3{color:#94a3b8;font-size:18px;font-weight:700;margin-bottom:8px;}
.offline-msg p{font-size:13px;}

/* Bottom bar */
.bottombar{
    background:rgba(0,0,0,.7);
    padding:10px 18px;
    display:flex;align-items:center;justify-content:space-between;
    flex-shrink:0;
    backdrop-filter:blur(6px);
    -webkit-backdrop-filter:blur(6px);
}
.date-label{color:#64748b;font-size:11px;}
.btn-fs{
    background:#1d4ed8;color:#fff;border:none;
    padding:7px 16px;border-radius:8px;font-size:12px;font-weight:700;
    cursor:pointer;display:flex;align-items:center;gap:6px;
}
.btn-fs:hover{background:#2563eb;}
</style>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/pragmavet.css">
    <link href="assets/global-style.css" rel="stylesheet">
</head>
<body>
<div class="screen">

    <!-- Top bar -->
    <div class="topbar">
        <div class="topbar-left">
            <?php if($stream_url && $enabled): ?>
                <div class="live-dot"></div>
            <?php else: ?>
                <div class="offline-dot"></div>
            <?php endif; ?>
            <div>
                <div class="pet-label"><i class="fa fa-paw me-1"></i><?= htmlspecialchars($pet_name) ?></div>
                <div class="cage-label">Cage <?= htmlspecialchars($cage) ?> · Live CCTV</div>
            </div>
        </div>
        <div style="color:#475569;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;">
            <i class="fa fa-shield-halved me-1 text-info"></i>PragmaVet Boarding
        </div>
    </div>

    <!-- Camera area -->
    <div class="cam-area">
        <?php if($stream_url && $enabled): ?>
            <?php if($is_mjpeg): ?>
                <img src="<?= htmlspecialchars($stream_url) ?>" alt="Live Camera Feed" id="camImg">
            <?php else: ?>
                <iframe src="<?= htmlspecialchars($stream_url) ?>" scrolling="no"
                        allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture"
                        allowfullscreen id="camFrame"></iframe>
            <?php endif; ?>
        <?php elseif($stream_url && !$enabled): ?>
            <div class="offline-msg">
                <i class="fa fa-pause-circle"></i>
                <h3>Camera on Standby</h3>
                <p>The camera for <?= htmlspecialchars($pet_name) ?>'s cage is currently paused by staff.</p>
            </div>
        <?php else: ?>
            <div class="offline-msg">
                <i class="fa fa-video-slash"></i>
                <h3>No Camera Configured</h3>
                <p>Ask clinic staff to add a camera stream for this cage.</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Bottom bar -->
    <div class="bottombar">
        <div class="date-label" id="clockLabel"></div>
        <?php if($stream_url): ?>
        <button class="btn-fs" onclick="goFullscreen()">
            <i class="fa fa-expand"></i> Fullscreen
        </button>
        <?php endif; ?>
    </div>

</div>

<script>
// Live clock
function tick(){
    var now = new Date();
    document.getElementById('clockLabel').textContent =
        now.toLocaleDateString('en-PH',{weekday:'short',month:'short',day:'numeric'})
        + ' · ' + now.toLocaleTimeString('en-PH',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
}
tick(); setInterval(tick, 1000);

// Fullscreen
function goFullscreen(){
    var el = document.documentElement;
    if(el.requestFullscreen) el.requestFullscreen();
    else if(el.webkitRequestFullscreen) el.webkitRequestFullscreen();
    else if(el.mozRequestFullScreen) el.mozRequestFullScreen();
}
</script>
    <script src="mousetrail.js"></script>
</body>
</html>
