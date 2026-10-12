<?php
session_start();
include("dbconnection.php");

$user_role = $_SESSION['role'] ?? ($_SESSION['user_role'] ?? '');
if(!isset($_SESSION['user_id']) || !in_array($user_role, ['admin', 'clinic_owner', 'owner'])){
    header("Location: index.php");
    exit();
}
// Prices and service types are set by the administrator; the clinic owner views them
$pv_owner_ro = in_array($user_role, ['clinic_owner', 'owner'], true);
if ($pv_owner_ro && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION['message'] = "System settings are managed by the administrator. Your access to this page is view only.";
    $_SESSION['msg_type'] = "warning";
    header("Location: admin_settings.php");
    exit();
}

$active_nav = 'settings';
$msg = "";
$msg_type = "";

include_once("includes/service_prices.php");
sp_ensure_table($conn);

// Add or remove a sub-type (e.g. a lab test) — any price edits in the same form are saved too
$type_msg = '';
if (!empty($_POST['add_type'])) {
    $cat  = (string)$_POST['add_type'];
    $name = trim((string)($_POST['new_type'][$cat] ?? ''));
    $okcat = $conn->prepare("SELECT MAX(sort_order) so, MAX(unit) u FROM service_prices WHERE category = ?");
    $okcat->bind_param("s", $cat); $okcat->execute(); $cr = $okcat->get_result()->fetch_assoc(); $okcat->close();
    $dup = $conn->prepare("SELECT 1 FROM service_prices WHERE LOWER(service_name) = LOWER(?)");
    $dup->bind_param("s", $name); $dup->execute(); $is_dup = $dup->get_result()->num_rows > 0; $dup->close(); // read now: the connection must be free for the next query
    if ($cr['so'] === null) $type_msg = "Unknown service group.";
    elseif ($name === '' || mb_strlen($name) > 100) $type_msg = "Enter a name for the new type.";
    elseif ($is_dup) $type_msg = "\"" . htmlspecialchars($name) . "\" already exists.";
    else {
        $np = round(max(0, (float)($_POST['new_type_price'][$cat] ?? 0)), 2);
        $so = (int)$cr['so'] + 1; $unit = $cat === 'Laboratory Test' ? 'per test' : ($cr['u'] ?: 'per visit');
        $ins = $conn->prepare("INSERT INTO service_prices (service_name, price, unit, sort_order, category) VALUES (?, ?, ?, ?, ?)");
        $ins->bind_param("sdsis", $name, $np, $unit, $so, $cat); $ins->execute();
        $type_msg = "\"" . htmlspecialchars($name) . "\" added to $cat (₱" . number_format($np, 2) . ").";
    }
    $_POST['update_prices'] = 1;
}
if (!empty($_POST['remove_type'])) {
    $rid = (int)$_POST['remove_type'];
    $row = $conn->query("SELECT service_name, category FROM service_prices WHERE id = $rid")->fetch_assoc();
    // A group keeps at least one type, and the base "Laboratory Test" price (used for booking) can't be removed
    $left = $row && $row['category'] ? (int)$conn->query("SELECT COUNT(*) c FROM service_prices WHERE category = '" . $conn->real_escape_string($row['category']) . "'")->fetch_assoc()['c'] : 0;
    if (!$row || !$row['category'] || $row['service_name'] === $row['category'] || $left <= 1) $type_msg = "That type can't be removed.";
    else { $conn->query("DELETE FROM service_prices WHERE id = $rid"); unset($_POST['price'][$rid]); $type_msg = "\"" . htmlspecialchars($row['service_name']) . "\" removed. Invoices already issued keep it."; }
    $_POST['update_prices'] = 1;
}

// Save service prices
if (isset($_POST['update_prices'])) {
    $prices = $_POST['price'] ?? [];
    $bad = 0; $changed = 0;
    $st = $conn->prepare("UPDATE service_prices SET price=? WHERE id=?");
    foreach ($prices as $id => $val) {
        $val = trim((string)$val);
        if ($val === '' || !is_numeric($val) || (float)$val < 0 || (float)$val > 1000000) { $bad++; continue; }
        $p = round((float)$val, 2);
        $sid = (int)$id;
        $st->bind_param("di", $p, $sid);
        $st->execute();
        $changed += $st->affected_rows;
    }
    if ($bad > 0) {
        $msg = "$bad price(s) were not saved — enter an amount of 0 or more.";
        $msg_type = "warning";
    } else {
        $msg = $type_msg !== '' ? $type_msg . ($changed > 0 ? ' Price changes saved.' : '') : ($changed > 0 ? "Service prices updated." : "No prices were changed.");
        $msg_type = "success";
    }
}

$services = sp_all($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<script src="theme.js?v=<?=time()?>"></script>
<link rel="stylesheet" href="theme.css?v=<?=time()?>">

<title>System Settings | VetAdmin</title>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root { --primary: #2563eb; --secondary: #3b82f6; --dark: #1e40af; --light-bg: #E6EEF8; --sidebar-width:260px; }
        body { background: var(--light-bg); font-family: 'Inter', sans-serif; color: #2D3748; }

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

        .main-content { margin-left: var(--sidebar-width); padding: 40px; }
        .card-premium { background: white; border: none; border-radius: 15px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); padding: 30px; margin-bottom: 25px; }

        .form-control { border-radius: 10px; border: 1px solid #E2E8F0; padding: 12px 16px; font-size: 15px; background: #fafcff; }
        .form-control:focus { border-color: var(--secondary); box-shadow: 0 0 0 3px rgba(43, 108, 176, 0.1); }
        .form-label { font-weight: 600; color: var(--primary); font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; }
        .btn-primary { background: var(--secondary); border: none; padding: 12px 25px; border-radius: 10px; font-weight: 600; }
    </style>
    <link rel="stylesheet" href="assets/pragmavet.css">
    <link href="assets/global-style.css" rel="stylesheet">
</head>
<body>

<?php if($msg != ""): ?>
<div class="position-fixed top-0 end-0 p-3" style="z-index: 9999" id="spToast">
    <div class="alert alert-<?php echo $msg_type; ?> shadow-sm fw-bold" style="transition:opacity .4s ease, transform .4s ease;">
        <i class="fa fa-info-circle me-2"></i><?php echo $msg; ?>
    </div>
</div>
<script>
// The message fades out after 3 seconds
setTimeout(function () { var t = document.getElementById('spToast'); if (!t) return; var a = t.firstElementChild; a.style.opacity = '0'; a.style.transform = 'translateY(-8px)'; setTimeout(function () { t.remove(); }, 450); }, 3000);
</script>
<?php endif; ?>

<!-- Admin Sidebar -->
<?php include("includes/admin_sidebar.php"); ?>

<div class="main-content">
    <div class="mb-4">
        <h2 class="fw-bold m-0 text-dark">System Settings</h2>
            <?php if ($pv_owner_ro): ?><div style="display:inline-flex;align-items:center;gap:8px;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:99px;padding:8px 16px;font-size:13px;font-weight:600;margin:10px 0 4px;"><i class="fa fa-eye"></i> View only · service prices and settings are managed by the administrator</div><?php endif; ?>
        <p class="text-muted">Set the price of each clinic service. New invoices use these prices.</p>
    </div>

    <?php
    // Icons/colours per service; vaccine types are grouped under one "Vaccination" card
    $sp_style = [
        'Consultation' => ['fa-stethoscope', '#7c3aed', '#f3e8ff'], 'General Checkup' => ['fa-heart-pulse', '#db2777', '#fce7f3'],
        'Vaccination' => ['fa-syringe', '#16a34a', '#dcfce7'], 'Deworming' => ['fa-capsules', '#ea580c', '#ffedd5'],
        'Vaccination and Deworming' => ['fa-shield-virus', '#0d9488', '#ccfbf1'], 'Laboratory Test' => ['fa-flask', '#0284c7', '#e0f2fe'],
        'Dental Cleaning' => ['fa-tooth', '#0891b2', '#cffafe'], 'Dental Prophylaxis' => ['fa-teeth', '#0e7490', '#ecfeff'],
        'Grooming' => ['fa-bath', '#c026d3', '#fae8ff'], 'Surgery' => ['fa-kit-medical', '#dc2626', '#fee2e2'],
        'Confinement' => ['fa-bed-pulse', '#b45309', '#fef3c7'], 'Pet Boarding' => ['fa-hotel', '#2563eb', '#dbeafe'],
    ];
    // One card per service; options with a category (vaccine types) share one card.
    // Vaccination keeps its old spot after General Checkup.
    $cards = []; $group_idx = [];
    foreach ($services as $svc) {
        $cat = $svc['category'] ?? '';
        if ($cat === '') { $cards[] = ['svc' => $svc]; continue; }
        if (!isset($group_idx[$cat])) {
            $after = array_search('General Checkup', array_map(fn($c) => $c['svc']['service_name'] ?? '', $cards));
            $pos = ($cat === 'Vaccination' && $after !== false) ? $after + 1 : count($cards);
            array_splice($cards, $pos, 0, [['group' => $cat, 'items' => []]]);
            foreach ($group_idx as $g => $i) if ($i >= $pos) $group_idx[$g] = $i + 1;
            $group_idx[$cat] = $pos;
        }
        $cards[$group_idx[$cat]]['items'][] = $svc;
    }
    $fmt_date = fn($d) => $d ? date('M d, Y g:i A', strtotime($d)) : '';
    $price_val = fn($p) => htmlspecialchars(number_format((float)$p, 2, '.', ''));
    ?>

    <style>
        .sp-toolbar { display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:18px; }
        .sp-search { flex:1; min-width:220px; max-width:360px; position:relative; }
        .sp-search i { position:absolute; left:14px; top:50%; transform:translateY(-50%); color:#94a3b8; }
        .sp-search input { width:100%; padding:11px 14px 11px 38px !important; border-radius:12px !important; border:1px solid #e2e8f0 !important; background:#fff !important; font-size:14px; }
        .sp-count { font-size:13px; color:#64748b; }
        .sp-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(290px, 1fr)); gap:18px; }
        .sp-card { background:#fff; border:1.5px solid #eef2f6; border-radius:18px; padding:20px; box-shadow:0 2px 10px rgba(15,23,42,.04); transition:transform .15s, box-shadow .15s, border-color .15s; }
        .sp-card:hover { transform:translateY(-2px); box-shadow:0 10px 24px rgba(15,23,42,.08); }
        .sp-card.changed { border-color:#f59e0b; box-shadow:0 0 0 3px rgba(245,158,11,.15); }
        .sp-head { display:flex; align-items:center; gap:12px; margin-bottom:16px; }
        .sp-icon { width:46px; height:46px; border-radius:14px; display:flex; align-items:center; justify-content:center; font-size:19px; flex-shrink:0; }
        .sp-name { font-weight:800; color:#0f172a; font-size:15.5px; line-height:1.2; }
        .sp-unit { display:inline-block; margin-top:4px; font-size:11px; font-weight:700; color:#64748b; background:#f1f5f9; border-radius:999px; padding:2px 10px; text-transform:uppercase; letter-spacing:.04em; }
        .sp-price { display:flex; align-items:center; border:1.5px solid #e2e8f0; border-radius:14px; background:#f8fafc; overflow:hidden; transition:border-color .15s, background .15s; }
        .sp-price:focus-within { border-color:#2563eb; background:#fff; box-shadow:0 0 0 4px rgba(37,99,235,.10); }
        .sp-price .cur { padding:0 4px 0 16px; font-weight:800; color:#64748b; font-size:18px; }
        .sp-price input { flex:1; min-width:0; border:none !important; background:transparent !important; box-shadow:none !important; font-size:20px !important; font-weight:800; color:#0f172a; padding:10px 4px !important; outline:none; width:100%; }
        .sp-step { border:none !important; background:transparent !important; box-shadow:none !important; width:34px; min-width:34px; padding:0 !important; flex-shrink:0; align-self:stretch; color:#475569 !important; font-size:12px; cursor:pointer; transition:background .15s; transform:none !important; }
        .sp-step:hover { background:#e2e8f0 !important; }
        .sp-meta { display:flex; justify-content:space-between; align-items:center; margin-top:10px; font-size:11.5px; color:#94a3b8; min-height:18px; }
        .sp-was { color:#b45309; font-weight:700; display:none; }
        .sp-card.changed .sp-was { display:inline; }
        .sp-subnav { display:flex; flex-wrap:wrap; gap:6px; background:#f1f5f9; border-radius:12px; padding:4px; margin-bottom:14px; }
        .sp-subnav button { flex:1 1 auto; border:none; background:transparent; border-radius:9px; padding:8px 10px; font-weight:700; font-size:13px; color:#475569; cursor:pointer; transition:all .15s; }
        .sp-subnav button.active { background:#fff; color:#16a34a; box-shadow:0 2px 6px rgba(15,23,42,.08); }
        .sp-subnav button .dot { display:inline-block; width:7px; height:7px; border-radius:50%; background:#f59e0b; margin-left:6px; vertical-align:middle; visibility:hidden; }
        .sp-subnav button.changed .dot { visibility:visible; }
        .sp-subpane { display:none; animation:spFade .18s ease; }
        .sp-subpane.active { display:block; }
        @keyframes spFade { from { opacity:0; transform:translateY(4px); } to { opacity:1; transform:none; } }
        .sp-savebar { position:sticky; bottom:18px; z-index:20; margin-top:22px; display:flex; align-items:center; justify-content:space-between; gap:12px;
                      background:#0f172a; color:#fff; border-radius:16px; padding:14px 18px; box-shadow:0 12px 30px rgba(15,23,42,.25);
                      transform:translateY(20px); opacity:0; pointer-events:none; transition:all .2s; }
        .sp-savebar.show { transform:none; opacity:1; pointer-events:auto; }
        .sp-savebar .btn-reset { background:transparent !important; border:1px solid rgba(255,255,255,.3) !important; color:#fff !important; border-radius:10px; padding:8px 16px; font-weight:600; }
        .sp-remove { border:none !important; background:none !important; background-image:none !important; box-shadow:none !important; color:#dc2626 !important; font-size:12px; font-weight:600; padding:0; margin-top:8px; }
        .sp-remove:hover { text-decoration:underline; }
        .sp-add { border:1.5px solid #2563eb !important; background:#fff !important; background-image:none !important; box-shadow:none !important; color:#2563eb !important; border-radius:10px; font-size:13px; font-weight:700; padding:4px 12px; white-space:nowrap; }
        .sp-add:hover { background:#eff6ff !important; }
        .sp-card { display:flex; flex-direction:column; }
        .sp-addrow { margin-top:auto !important; }
        .sp-typelbl { font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.05em; margin:0 0 6px; }
        .sp-typesel { position:relative; margin-bottom:12px; }
        .sp-typesel select { width:100%; appearance:none; -webkit-appearance:none; border:1.5px solid #e2e8f0; border-radius:12px; background:#fff; padding:10px 38px 10px 14px; font-weight:700; font-size:14px; color:#0f172a; cursor:pointer; }
        .sp-typesel select:focus { outline:none; border-color:#2563eb; box-shadow:0 0 0 4px rgba(37,99,235,.10); }
        .sp-typesel i { position:absolute; right:14px; top:50%; transform:translateY(-50%); color:#94a3b8; font-size:12px; pointer-events:none; }
        .sp-empty { display:none; text-align:center; color:#94a3b8; padding:40px 0; }
    </style>

    <form method="POST" id="spForm">
        <div class="sp-toolbar">
            <div class="sp-search"><i class="fa fa-search"></i><input type="text" id="spSearch" placeholder="Search a service…" autocomplete="off"></div>
            <span class="sp-count"><i class="fa fa-tags me-1"></i><span id="spVisible"><?= count($cards) ?></span> services</span>
        </div>

        <div class="sp-grid" id="spGrid">
            <?php foreach ($cards as $c):
                if (isset($c['group'])):
                    $g = $c['group']; [$ic, $col, $bg] = $sp_style[$g] ?? ['fa-tag', '#475569', '#f1f5f9']; ?>
            <div class="sp-card" data-name="<?= htmlspecialchars(strtolower($g . ' ' . implode(' ', array_column($c['items'], 'service_name')))) ?>">
                <div class="sp-head">
                    <div class="sp-icon" style="color:<?= $col ?>;background:<?= $bg ?>;"><i class="fa <?= $ic ?>"></i></div>
                    <div>
                        <div class="sp-name"><?= htmlspecialchars($g) ?></div>
                        <span class="sp-unit"><?= count($c['items']) ?> type<?= count($c['items']) === 1 ? '' : 's' ?><?= $c['items'][0]['unit'] !== 'per visit' ? ' · ' . htmlspecialchars($c['items'][0]['unit']) : '' ?></span>
                    </div>
                </div>
                <label class="sp-typelbl" for="sp-sel-<?= md5($g) ?>">Type</label>
                <div class="sp-typesel">
                    <select id="sp-sel-<?= md5($g) ?>" class="sp-subsel" aria-label="<?= htmlspecialchars($g) ?> type">
                        <?php foreach ($c['items'] as $k => $it): ?>
                        <option value="sp-pane-<?= (int)$it['id'] ?>" data-pane="sp-pane-<?= (int)$it['id'] ?>" data-label="<?= htmlspecialchars($it['service_name'] === $g ? 'Standard' : str_replace(' Vaccine', '', $it['service_name'])) ?>" <?= $k === 0 ? 'selected' : '' ?>><?= htmlspecialchars($it['service_name'] === $g ? 'Standard' : str_replace(' Vaccine', '', $it['service_name'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <i class="fa fa-chevron-down"></i>
                </div>
                <?php foreach ($c['items'] as $k => $it): $removable = $it['service_name'] !== $g && count($c['items']) > 1; ?>
                <div class="sp-subpane <?= $k === 0 ? 'active' : '' ?>" id="sp-pane-<?= (int)$it['id'] ?>">
                    <div class="sp-price">
                        <span class="cur">₱</span>
                        <input type="number" name="price[<?= (int)$it['id'] ?>]" value="<?= $price_val($it['price']) ?>" data-original="<?= $price_val($it['price']) ?>" min="0" max="1000000" step="0.01" required aria-label="<?= htmlspecialchars($it['service_name']) ?> price">
                        <button type="button" class="sp-step" data-step="-50" title="−₱50"><i class="fa fa-minus"></i></button>
                        <button type="button" class="sp-step" data-step="50" title="+₱50"><i class="fa fa-plus"></i></button>
                    </div>
                    <div class="sp-meta"><span><?= htmlspecialchars($it['service_name']) ?> · updated <?= $fmt_date($it['updated_at']) ?></span><span class="sp-was">was ₱<?= number_format((float)$it['price'], 2) ?></span></div>
                    <?php if ($removable): ?><button type="submit" name="remove_type" value="<?= (int)$it['id'] ?>" class="sp-remove"><i class="fa fa-trash-can me-1"></i>Remove this type</button><?php endif; ?>
                </div>
                <?php endforeach; ?>
                <?php if (in_array($g, ['Laboratory Test', 'Vaccination', 'Surgery'], true)): ?>
                <div class="d-flex flex-wrap gap-2 mt-3 pt-3 sp-addrow" style="border-top:1px dashed #e2e8f0;">
                    <input type="text" name="new_type[<?= htmlspecialchars($g) ?>]" class="form-control form-control-sm w-100" maxlength="100" placeholder="<?= ['Vaccination' => 'New vaccine (e.g. Anti-Rabies)', 'Surgery' => 'New surgery (e.g. Spay / Neuter)'][$g] ?? 'New test (e.g. Biopsy)' ?>">
                    <input type="number" name="new_type_price[<?= htmlspecialchars($g) ?>]" class="form-control form-control-sm" style="flex:1;min-width:0;" min="0" step="0.01" placeholder="Price (₱)">
                    <button type="submit" name="add_type" value="<?= htmlspecialchars($g) ?>" class="sp-add"><i class="fa fa-plus me-1"></i>Add</button>
                </div>
                <?php endif; ?>
            </div>
            <?php else:
                    $svc = $c['svc']; [$ic, $col, $bg] = $sp_style[$svc['service_name']] ?? ['fa-tag', '#475569', '#f1f5f9']; ?>
            <div class="sp-card" data-name="<?= htmlspecialchars(strtolower($svc['service_name'])) ?>">
                <div class="sp-head">
                    <div class="sp-icon" style="color:<?= $col ?>;background:<?= $bg ?>;"><i class="fa <?= $ic ?>"></i></div>
                    <div>
                        <div class="sp-name"><?= htmlspecialchars($svc['service_name']) ?></div>
                        <?php if ($svc['unit'] !== 'per visit'): ?><span class="sp-unit"><?= htmlspecialchars($svc['unit']) ?></span><?php endif; ?>
                    </div>
                </div>
                <div class="sp-price">
                    <span class="cur">₱</span>
                    <input type="number" name="price[<?= (int)$svc['id'] ?>]" value="<?= $price_val($svc['price']) ?>" data-original="<?= $price_val($svc['price']) ?>" min="0" max="1000000" step="0.01" required aria-label="<?= htmlspecialchars($svc['service_name']) ?> price">
                    <button type="button" class="sp-step" data-step="-50" title="−₱50"><i class="fa fa-minus"></i></button>
                    <button type="button" class="sp-step" data-step="50" title="+₱50"><i class="fa fa-plus"></i></button>
                </div>
                <div class="sp-meta"><span>Updated <?= $fmt_date($svc['updated_at']) ?></span><span class="sp-was">was ₱<?= number_format((float)$svc['price'], 2) ?></span></div>
            </div>
            <?php endif; endforeach; ?>
        </div>
        <div class="sp-empty" id="spEmpty"><i class="fa fa-magnifying-glass fa-2x mb-2 d-block opacity-50"></i>No service matches your search.</div>

        <p class="text-muted small mt-3 mb-0"><i class="fa fa-circle-info me-1"></i>Changes apply to new invoices only; invoices already issued keep their amounts. The ₱150 reservation fee is separate from these prices.</p>

        <div class="sp-savebar" id="spSaveBar">
            <span><i class="fa fa-pen-to-square me-2 text-warning"></i><b id="spChangedCount">0</b> unsaved price change(s)</span>
            <span class="d-flex gap-2">
                <button type="button" class="btn-reset" id="spReset">Reset</button>
                <button type="submit" name="update_prices" class="btn btn-primary shadow-sm px-4"><i class="fa fa-save me-2"></i> Save Prices</button>
            </span>
        </div>
    </form>

    <script>
    (function () {
        const form = document.getElementById('spForm');
        const inputs = [...form.querySelectorAll('input[data-original]')];

        function refresh() {
            let changed = 0;
            inputs.forEach(inp => {
                const isChanged = parseFloat(inp.value || 0).toFixed(2) !== parseFloat(inp.dataset.original).toFixed(2);
                if (isChanged) changed++;
                const pane = inp.closest('.sp-subpane');
                if (pane) {
                    const tab = form.querySelector('option[data-pane="' + pane.id + '"]');
                    if (tab) tab.textContent = tab.dataset.label + (isChanged ? '  •' : '');
                }
            });
            form.querySelectorAll('.sp-card').forEach(card => {
                card.classList.toggle('changed', [...card.querySelectorAll('input[data-original]')].some(i => parseFloat(i.value || 0).toFixed(2) !== parseFloat(i.dataset.original).toFixed(2)));
            });
            document.getElementById('spChangedCount').textContent = changed;
            document.getElementById('spSaveBar').classList.toggle('show', changed > 0);
        }

        inputs.forEach(inp => inp.addEventListener('input', refresh));

        // −/+ ₱50 steppers
        form.querySelectorAll('.sp-step').forEach(btn => btn.addEventListener('click', () => {
            const inp = btn.parentElement.querySelector('input');
            inp.value = Math.max(0, (parseFloat(inp.value) || 0) + parseFloat(btn.dataset.step)).toFixed(2);
            refresh();
        }));

        // Vaccine type sub-nav
        form.querySelectorAll('.sp-subsel').forEach(sel => sel.addEventListener('change', () => {
            const card = sel.closest('.sp-card');
            card.querySelectorAll('.sp-subpane').forEach(p => p.classList.toggle('active', p.id === sel.value));
        }));

        // Search
        document.getElementById('spSearch').addEventListener('input', function () {
            const q = this.value.trim().toLowerCase();
            let shown = 0;
            form.querySelectorAll('.sp-card').forEach(card => {
                const ok = !q || card.dataset.name.includes(q);
                card.style.display = ok ? '' : 'none';
                if (ok) shown++;
            });
            document.getElementById('spVisible').textContent = shown;
            document.getElementById('spEmpty').style.display = shown ? 'none' : 'block';
        });

        document.getElementById('spReset').addEventListener('click', () => {
            inputs.forEach(inp => inp.value = inp.dataset.original);
            refresh();
        });

        // Warn before leaving with unsaved changes
        let submitting = false;
        form.addEventListener('submit', () => submitting = true);
        window.addEventListener('beforeunload', e => {
            if (!submitting && inputs.some(i => parseFloat(i.value || 0).toFixed(2) !== parseFloat(i.dataset.original).toFixed(2))) { e.preventDefault(); e.returnValue = ''; }
        });
    })();
    </script>
</div>


    <script src="mousetrail.js"></script>
<?php if ($pv_owner_ro): ?>
<script>document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('form[method="POST"], form[method="post"]').forEach(function (f) {
        f.querySelectorAll('input:not([type=hidden]), select, textarea').forEach(function (e) { e.disabled = true; });
        f.querySelectorAll('button[type=submit], button:not([type]), input[type=submit], .sp-step').forEach(function (b) { b.style.display = 'none'; });
    });
    // "add a new type" rows are for editing only (type selectors stay so every price can be viewed)
    document.querySelectorAll('button[name=add_type]').forEach(function (b) { if (b.parentElement) b.parentElement.style.setProperty('display', 'none', 'important'); });
});</script>
<?php endif; ?>
</body>
</html>
