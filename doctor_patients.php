<?php
session_start();
include("dbconnection.php");
include_once("includes/rbac_guard.php");

if(!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// RBAC: Restrict strictly to doctor role
$user_role = rbac_get_role();
if($user_role !== 'doctor') {
    header("Location: " . rbac_home_url($user_role));
    exit();
}
// RBAC: the page needs "View patient records"; each record change needs its own task
include_once("includes/permissions.php");
if (!pv_can($conn, 'doctor.pet_medical_history')) {
    $_SESSION['message'] = pv_denied_message('doctor.pet_medical_history');
    $_SESSION['msg_type'] = 'danger';
    header("Location: doctor.php");
    exit();
}
$pv_need = ['save_record' => 'doctor.treatment_plans', 'save_vaccination' => 'doctor.vaccination_records'][$_POST['emr_action'] ?? ''] ?? null;
if ($pv_need && !pv_can($conn, $pv_need)) {
    $_SESSION['message'] = pv_denied_message($pv_need);
    $_SESSION['msg_type'] = 'danger';
    header("Location: doctor_patients.php");
    exit();
}

// ── EMR edits (doctor only) ──────────────────────────────────────────────
$doc_name = $_SESSION['user'] ?? 'Clinic Vet';
$is_date  = function($d) { return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d); };
$nz       = function($v) { $v = trim((string)$v); return $v === '' ? null : $v; };

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['emr_action'])) {
    $pet_id = (int)($_POST['pet_id'] ?? 0);
    $ok = false;
    $pet_exists = $conn->query("SELECT id FROM pets WHERE id = $pet_id AND deleted_at IS NULL")->num_rows === 1;

    if ($pet_exists && $_POST['emr_action'] === 'update_profile') {
        $species = $nz($_POST['species'] ?? '');
        $breed   = $nz($_POST['breed'] ?? '');
        $gender  = $nz($_POST['gender'] ?? '');
        $dob     = $is_date($_POST['date_of_birth'] ?? '') ? $_POST['date_of_birth'] : null;
        $age     = ($_POST['age'] ?? '') !== '' ? max(0, (int)$_POST['age']) : null;
        $weight  = ($_POST['weight'] ?? '') !== '' ? max(0, (float)$_POST['weight']) : null;
        $history = $nz($_POST['medical_history'] ?? '');
        // Vets record clinical details only (weight, medical history). Species, breed, gender and birth date belong to the
        // owner's pet profile (the admin can correct them), so they are kept as registered.
        $cur = $conn->query("SELECT species, breed, gender, date_of_birth FROM pets WHERE id = $pet_id")->fetch_assoc();
        $identity_changed = $cur && ((string)$species !== (string)$cur['species'] || (string)$breed !== (string)$cur['breed']
                            || (string)$gender !== (string)$cur['gender'] || (string)$dob !== (string)$cur['date_of_birth']);
        $st = $conn->prepare("UPDATE pets SET weight=?, medical_history=? WHERE id=?");
        $st->bind_param("dsi", $weight, $history, $pet_id);
        $ok = $st->execute();
        $_SESSION['message'] = !$ok ? 'Could not update health profile.'
            : 'Weight and medical history updated.' . ($identity_changed ? ' Species, breed, gender and birth date are kept as registered by the owner.' : '');

    } elseif ($pet_exists && $_POST['emr_action'] === 'save_record') {
        $types  = ['checkup','diagnosis','treatment','follow_up','surgery','lab_test','vaccination','other'];
        $states = ['Completed','Pending','In Progress','Cancelled'];
        $rec_id = (int)($_POST['record_id'] ?? 0);
        $type   = in_array($_POST['record_type'] ?? '', $types, true) ? $_POST['record_type'] : 'other';
        $status = in_array($_POST['status'] ?? '', $states, true) ? $_POST['status'] : 'Completed';
        $title  = trim($_POST['title'] ?? '');
        $desc   = $nz($_POST['description'] ?? '');
        $notes  = $nz($_POST['notes'] ?? '');
        $date   = $is_date($_POST['record_date'] ?? '') ? $_POST['record_date'] : date('Y-m-d');

        if ($title === '') {
            $_SESSION['message'] = 'Record title is required.';
        } elseif ($rec_id > 0) {
            $st = $conn->prepare("UPDATE medical_records SET record_type=?, title=?, description=?, record_date=?, status=?, notes=? WHERE id=? AND pet_id=?");
            $st->bind_param("ssssssii", $type, $title, $desc, $date, $status, $notes, $rec_id, $pet_id);
            $ok = $st->execute();
            $_SESSION['message'] = $ok ? 'Clinical record updated.' : 'Could not update record.';
        } else {
            $st = $conn->prepare("INSERT INTO medical_records (pet_id, record_type, title, description, doctor_name, record_date, status, notes) VALUES (?,?,?,?,?,?,?,?)");
            $st->bind_param("isssssss", $pet_id, $type, $title, $desc, $doc_name, $date, $status, $notes);
            $ok = $st->execute();
            $_SESSION['message'] = $ok ? 'Clinical record added.' : 'Could not add record.';
        }

    } elseif ($pet_exists && $_POST['emr_action'] === 'save_vaccination') {
        $vac_id = (int)($_POST['vaccination_id'] ?? 0);
        $name   = trim($_POST['vaccine_name'] ?? '');
        $given  = $is_date($_POST['date_given'] ?? '') ? $_POST['date_given'] : null;
        $due    = $is_date($_POST['next_due'] ?? '') ? $_POST['next_due'] : null;
        $batch  = $nz($_POST['batch_number'] ?? '');
        $vnotes = $nz($_POST['notes'] ?? '');
        $vstat  = (!$due) ? 'Administered' : ($due < date('Y-m-d') ? 'Overdue' : ($due <= date('Y-m-d', strtotime('+14 days')) ? 'Due soon' : 'Up to date'));

        if ($name !== '' && $given && $due && $due < $given) {
            $_SESSION['message'] = 'The next due date must be after the date given.';
            $_SESSION['msg_type'] = 'danger';
        } elseif ($name === '' || !$given) {
            $_SESSION['message'] = 'Vaccine name and date given are required.';
        } else {
            if ($vac_id > 0) {
                $st = $conn->prepare("UPDATE vaccination_records SET vaccine_name=?, date_given=?, next_due=?, batch_number=?, status=?, notes=? WHERE id=? AND pet_id=?");
                $st->bind_param("ssssssii", $name, $given, $due, $batch, $vstat, $vnotes, $vac_id, $pet_id);
            } else {
                $st = $conn->prepare("INSERT INTO vaccination_records (pet_id, vaccine_name, date_given, next_due, batch_number, administered_by, status, notes) VALUES (?,?,?,?,?,?,?,?)");
                $st->bind_param("isssssss", $pet_id, $name, $given, $due, $batch, $doc_name, $vstat, $vnotes);
            }
            $ok = $st->execute();
            if ($ok) {
                // Keep the pet's summary vaccination dates in sync with the latest entry
                $conn->query("UPDATE pets p SET
                    last_vaccination = (SELECT MAX(date_given) FROM vaccination_records WHERE pet_id = p.id),
                    next_vaccination = (SELECT next_due FROM vaccination_records WHERE pet_id = p.id ORDER BY date_given DESC, id DESC LIMIT 1)
                    WHERE p.id = $pet_id");
            }
            $_SESSION['message'] = $ok ? ($vac_id > 0 ? 'Vaccination updated.' : 'Vaccination added.') : 'Could not save vaccination.';
        }
    } elseif (!$pet_exists) {
        $_SESSION['message'] = 'Pet not found.';
    }

    $_SESSION['msg_type'] = $ok ? 'success' : 'danger';
    header("Location: doctor_patients.php?open=$pet_id&tab=" . urlencode($_POST['return_tab'] ?? 'profile'));
    exit();
}

// Fetch all registered pets with owner details
$pets_query = "
    SELECT p.*, u.fullname as owner_name, u.contact_number as owner_contact, u.email as owner_email
    FROM pets p
    LEFT JOIN users u ON p.user_id = u.id
    WHERE p.deleted_at IS NULL
    ORDER BY p.name ASC
";
$pets_res = $conn->query($pets_query);

// A pet in the clinic right now for this vet (checked in, consultation not yet recorded):
// its "View / Edit EMR" button takes the vet straight to that consultation.
include_once("includes/pet_history.php");
$pv_me = (int)$_SESSION['user_id'];
$pv_open_visit = function ($p) use ($conn, $pv_me) {
    $ids = pet_appointment_ids($conn, (int)$p['user_id'], (int)$p['id'], $p['name']);
    if (!$ids) return null;
    $r = $conn->query("SELECT a.id FROM appointments a WHERE a.id IN (" . implode(',', $ids) . ")
                       AND a.status IN ('Checked In', 'In Progress') AND a.appointment_date <= CURDATE()
                       AND (a.doctor_id = $pv_me OR a.doctor_id IS NULL)
                       AND NOT EXISTS (SELECT 1 FROM consultations c WHERE c.appointment_id = a.id)
                       ORDER BY a.appointment_date DESC, a.appointment_time DESC LIMIT 1")->fetch_assoc();
    return $r ? (int)$r['id'] : null;
};

// Fetch medical history records mapped by pet_id
$history_res = $conn->query("
    SELECT m.*, p.name as pet_name 
    FROM medical_records m
    JOIN pets p ON m.pet_id = p.id
    ORDER BY m.record_date DESC
");

$history_by_pet = [];
if($history_res && $history_res->num_rows > 0) {
    while($h = $history_res->fetch_assoc()) {
        $history_by_pet[$h['pet_id']][] = $h;
    }
}

$vacc_by_pet = [];
$vacc_res = $conn->query("SELECT * FROM vaccination_records ORDER BY date_given DESC, id DESC");
if ($vacc_res) while ($v = $vacc_res->fetch_assoc()) {
    $vacc_by_pet[$v['pet_id']][] = $v;
}
$all_pets_json = [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <script src="theme.js?v=<?= time() ?>"></script>
    <link rel="stylesheet" href="theme.css?v=<?= time() ?>">
    <title>Patient EMR Directory | Doctor Workstation</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">

    <style>
        :root {
            --primary: #2563eb;
            --secondary: #475569;
            --dark: #0f172a;
            --light-bg: #f8fafc;
            --sidebar-width: 260px;
        }

        body {
            background: #f8fafc !important;
            font-family: 'Inter', sans-serif;
            color: #334155;
            overflow-x: hidden;
        }

        .main-content {
            margin-left: 260px !important;
            width: calc(100% - 260px) !important;
            max-width: calc(100% - 260px) !important;
            padding: 24px 28px !important;
            min-height: 100vh;
            box-sizing: border-box !important;
        }

        @media (max-width: 1200px) {
            .main-content { padding: 20px 20px !important; }
        }

        @media (max-width: 768px) {
            .main-content { margin-left: 0 !important; width: 100% !important; max-width: 100% !important; padding: 16px !important; }
            .sidebar { position: relative !important; width: 100% !important; height: auto !important; }
        }

        /* ── EMR Lookup: PragmaVet blue ── */
        .ep-eyebrow { display:inline-flex; align-items:center; gap:6px; background:#eff6ff; color:#2563eb; font-size:11px; font-weight:800; letter-spacing:.4px; text-transform:uppercase; padding:4px 12px; border-radius:99px; margin-bottom:8px; }
        .ep-chip { display:inline-flex; align-items:center; gap:7px; background:#fff; border:1px solid #e8edf3; border-radius:99px; padding:8px 14px; font-size:13px; color:#475569; box-shadow:0 2px 8px rgba(15,23,42,.04); }
        .ep-chip b { color:#0f172a; }
        .ep-chip-green { background:#f0fdf4; border-color:#bbf7d0; color:#15803d; } .ep-chip-green b { color:#15803d; }
        .ep-card { border:1px solid #e8edf3 !important; border-radius:18px !important; box-shadow:0 2px 12px rgba(15,23,42,.04) !important; padding:8px 20px 18px !important; }
        #petsTable { border-collapse:separate; border-spacing:0; }
        #petsTable thead th { background:#fff !important; color:#94a3b8 !important; font-size:11px !important; font-weight:800 !important; letter-spacing:.06em; text-transform:uppercase; border-bottom:1px solid #eef2f6 !important; padding:14px 12px !important; }
        #petsTable tbody td { padding:12px !important; border-bottom:1px solid #f1f5f9; }
        #petsTable tbody tr:last-child td { border-bottom:0; }
        #petsTable tbody tr:hover td { background:#f8fbff; }
        table.dataTable thead > tr > th.sorting:before, table.dataTable thead > tr > th.sorting:after,
        table.dataTable thead > tr > th.sorting_asc:before, table.dataTable thead > tr > th.sorting_asc:after,
        table.dataTable thead > tr > th.sorting_desc:before, table.dataTable thead > tr > th.sorting_desc:after { font-size:9px; opacity:.25; }
        table.dataTable thead > tr > th.sorting_asc:before, table.dataTable thead > tr > th.sorting_desc:after { opacity:.9; color:#2563eb; }
        .ep-av { width:40px; height:40px; border-radius:12px; background:linear-gradient(135deg,#dbeafe,#bfdbfe); color:#1d4ed8; font-weight:800; display:inline-flex; align-items:center; justify-content:center; flex-shrink:0; object-fit:cover; }
        .ep-id { font-family:ui-monospace,Consolas,monospace; font-size:11px; color:#94a3b8; }
        .ep-species { display:inline-block; background:#eff6ff; color:#1d4ed8; border:1px solid #dbeafe; border-radius:99px; padding:2px 10px; font-size:11.5px; font-weight:700; }
        .ep-recs { display:inline-flex; align-items:center; background:#f8fafc; color:#94a3b8; border:1px solid #e2e8f0; border-radius:99px; padding:4px 12px; font-size:12px; font-weight:700; }
        .ep-recs-has { background:#eff6ff; color:#2563eb; border-color:#bfdbfe; }
        .ep-view, .ep-start { background-image:none !important; border-radius:10px !important; padding:7px 14px !important; font-weight:700 !important; font-size:13px !important; white-space:nowrap; }
        .ep-view { background:#fff !important; color:#2563eb !important; border:1px solid #bfdbfe !important; box-shadow:none !important; }
        .ep-view:hover { background:#eff6ff !important; color:#1d4ed8 !important; }
        .ep-start { background:#2563eb !important; color:#fff !important; border:0 !important; box-shadow:0 4px 12px rgba(37,99,235,.22) !important; }
        .ep-start:hover { background:#1d4ed8 !important; color:#fff !important; }
        .dataTables_info { color:#64748b !important; font-size:13px; padding-top:16px !important; }
        .dataTables_paginate { padding-top:12px !important; }
        .dataTables_paginate .pagination { gap:4px; }
        .dataTables_paginate .page-link { border:1px solid #e2e8f0 !important; border-radius:10px !important; color:#475569 !important; font-weight:600; font-size:13px; min-width:36px; text-align:center; box-shadow:none !important; }
        .dataTables_paginate .page-item.active .page-link { background:#2563eb !important; border-color:#2563eb !important; color:#fff !important; }
        .dataTables_paginate .page-item.disabled .page-link { color:#cbd5e1 !important; background:#fff !important; }
        .ep-mhead { background:linear-gradient(135deg,#1d4ed8,#2563eb 60%,#3b82f6); color:#fff; padding:18px 24px; }
        .ep-mhead h5 { color:#fff !important; -webkit-text-fill-color:#fff; }
        .ep-mhead .btn-close { filter:invert(1) grayscale(1) brightness(2); }
        #emrModal .modal-content { overflow:hidden; }
        #emrModal .btn-primary { background:#2563eb !important; background-image:none !important; border:0 !important; color:#fff !important; box-shadow:0 4px 12px rgba(37,99,235,.22) !important; }
        #emrModal .btn-primary:hover { background:#1d4ed8 !important; }
        #emrModal .btn-light { background:#f1f5f9 !important; background-image:none !important; border:0 !important; color:#475569 !important; box-shadow:none !important; }
        #emrModal .btn-outline-primary { background:#fff !important; background-image:none !important; color:#2563eb !important; border:1px solid #bfdbfe !important; box-shadow:none !important; }
        #emrModal .nav-pills { background:#f1f5f9; padding:5px; border-radius:14px; gap:4px !important; }
        #emrModal .nav-pills .nav-link { background:transparent !important; background-image:none !important; color:#475569 !important; border:0 !important; border-radius:10px !important; box-shadow:none !important; opacity:1 !important; }
        #emrModal .nav-pills .nav-link:hover { color:#1d4ed8 !important; }
        #emrModal .nav-pills .nav-link.active { background:#fff !important; color:#1d4ed8 !important; box-shadow:0 2px 8px rgba(15,23,42,.08) !important; }
        #emrModal .nav-pills .nav-link .badge { background:#dbeafe !important; color:#1d4ed8 !important; }
        .ep-summary { background:linear-gradient(135deg,#eff6ff,#f8fbff); border:1px solid #dbeafe; border-radius:14px; }
        .custom-card {
            background: white;
            border: none;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
            padding: 24px;
        }

        .search-input-group {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 50px;
            padding: 2px 15px;
            display: flex;
            align-items: center;
            width: 300px;
            transition: 0.3s;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.02);
        }

        .search-input-group:focus-within {
            border-color: #2563eb;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.12);
            width: 340px;
        }

        .search-input-group i { color: #94a3b8; font-size: 14px; flex-shrink: 0; }
        .search-input-group:focus-within i { color: #2563eb; }
        /* the global form styles add their own border, background and shadow to inputs; keep this one flat inside the pill */
        .search-input-group input,
        .search-input-group input:focus {
            border: none !important;
            background: transparent !important;
            box-shadow: none !important;
            border-radius: 0 !important;
            padding: 9px 10px !important;
            height: auto !important;
            min-height: 0 !important;
            margin: 0 !important;
            font-size: 13.5px;
            font-weight: 500;
            color: #0f172a;
            width: 100%;
            outline: none !important;
        }
        .search-input-group input::placeholder { color: #94a3b8; }
        @media (max-width: 768px) { .search-input-group, .search-input-group:focus-within { width: 100%; } }

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

<div class="sidebar">
    <div class="sidebar-brand">
        <div class="sidebar-brand-icon"><i class="fa fa-user-md"></i></div>
        <span>PragmaVet</span>
    </div>
    <div class="sidebar-user">
        <div class="welcome">Welcome Doctor,</div>
        <div class="uname"><?php echo htmlspecialchars($_SESSION['user'] ?? $_SESSION['fullname'] ?? 'Veterinarian'); ?></div>
    </div>
    <a href="doctor.php" class=""><i class="fa fa-calendar-check"></i> <span>Schedule & Availability</span></a>
    <a href="doctor_consultations.php" class=""><i class="fa fa-stethoscope"></i> <span>Active Consultations</span></a>
    <a href="doctor_patients.php" class="active"><i class="fa fa-paw"></i> <span>Patient EMR Lookup</span></a>
    <a href="doctor_inventory.php" class=""><i class="fa fa-boxes"></i> <span>Pharmacy Stock</span></a>
    <?php $pv_cert_pending = (int)($conn->query("SELECT COUNT(*) c FROM certificate_requests WHERE status = 'Pending'")->fetch_assoc()['c'] ?? 0); ?>
    <a href="admin_certificates.php" class=""><i class="fa fa-file-contract"></i> <span>Cert Requests</span><?php if ($pv_cert_pending): ?><span style="margin-left:auto;background:#ef4444;color:#fff;font-size:11px;font-weight:800;min-width:22px;height:22px;border-radius:999px;display:inline-flex;align-items:center;justify-content:center;padding:0 7px;"><?= $pv_cert_pending ?></span><?php endif; ?></a>
    <div class="sidebar-logout">
        <a href="logout.php"><i class="fa fa-sign-out-alt"></i> <span>Logout</span></a>
    </div>
</div>

<?php include("includes/staff_bell.php"); ?>
<div class="main-content">
    <?php if (isset($_SESSION['message'])): ?>
    <div class="position-fixed top-0 end-0 p-4" style="z-index: 9999;" id="toastAlert">
        <div class="alert alert-<?= htmlspecialchars($_SESSION['msg_type'] ?? 'info') ?> shadow border-0" style="border-radius: 10px;">
            <i class="fa fa-info-circle me-2"></i><?= htmlspecialchars($_SESSION['message']) ?>
        </div>
    </div>
    <script>setTimeout(function(){ var t = document.getElementById('toastAlert'); if (t) t.remove(); }, 4000);</script>
    <?php unset($_SESSION['message'], $_SESSION['msg_type']); endif; ?>
    <div class="d-flex justify-content-between align-items-end flex-wrap gap-3 mb-3">
        <div>
            <span class="ep-eyebrow"><i class="fa fa-stethoscope"></i> Doctor Workstation</span>
            <h2 class="fw-bold m-0 text-dark">Patient EMR Lookup</h2>
            <p class="text-muted mb-0">Find a registered pet to see its medical records, vaccinations and health profile.</p>
        </div>
        <div class="search-input-group">
            <i class="fa fa-search"></i>
            <input type="text" id="customSearch" placeholder="Search Pet Name or Owner...">
        </div>
    </div>

    <div class="d-flex gap-2 flex-wrap mb-3">
        <span class="ep-chip"><i class="fa fa-paw" style="color:#2563eb;"></i> <b><?= $pets_res ? (int)$pets_res->num_rows : 0 ?></b> registered pets</span>
        <span class="ep-chip ep-chip-green" id="epChipIn" style="display:none;"><i class="fa fa-user-clock"></i> <b>0</b> checked in with you</span>
        <span class="ep-chip" id="epChipRec"><i class="fa fa-file-medical" style="color:#16a34a;"></i> <b>0</b> with medical records</span>
    </div>

    <div class="custom-card ep-card">
        <?php if($pets_res && $pets_res->num_rows > 0): ?>
        <div class="table-responsive">
            <table id="petsTable" class="table table-borderless align-middle mb-0">
                <thead>
                    <tr>
                        <th>Pet</th>
                        <th>Species & Breed</th>
                        <th>Age & Weight</th>
                        <th>Owner</th>
                        <th>Medical Records</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($p = $pets_res->fetch_assoc()): ?>
                    <?php 
                        $history_list = $history_by_pet[$p['id']] ?? [];
                        // Add the pet's own consultations and completed visits (matched by pet, not name) that have no medical record yet
                        $pv_ids = pet_appointment_ids($conn, (int)$p['user_id'], (int)$p['id'], $p['name']);
                        if ($pv_ids) {
                            $covered = array_flip(array_filter(array_map(fn($h) => (int)($h['appointment_id'] ?? 0), $history_list)));
                            $vq = $conn->query("SELECT a.id, a.service_type, a.appointment_date, a.appointment_time, d.fullname doctor,
                                                       c.diagnosis, c.treatment, c.prescription, c.vet_name
                                                FROM appointments a LEFT JOIN users d ON d.id = a.doctor_id
                                                LEFT JOIN consultations c ON c.appointment_id = a.id
                                                WHERE a.id IN (" . implode(',', $pv_ids) . ") AND (a.status = 'Completed' OR c.id IS NOT NULL)");
                            if ($vq) while ($v = $vq->fetch_assoc()) {
                                if (isset($covered[(int)$v['id']])) continue;
                                $covered[(int)$v['id']] = 1;
                                $history_list[] = [
                                    'record_date' => $v['appointment_date'], 'record_type' => 'visit', 'status' => 'Completed',
                                    'title' => $v['service_type'] ?: 'Clinic visit', 'doctor_name' => $v['vet_name'] ?: ($v['doctor'] ?: 'Clinic Vet'),
                                    'description' => $v['diagnosis'] ?: 'Completed visit. No consultation notes were recorded.',
                                    'notes' => trim(($v['treatment'] ?? '') . (!empty($v['prescription']) ? ' · Rx: ' . $v['prescription'] : '')),
                                    'readonly' => 1,
                                ];
                            }
                            usort($history_list, fn($x, $y) => strcmp((string)$y['record_date'], (string)$x['record_date']));
                        }
                        $pv_vaccs = $vacc_by_pet[$p['id']] ?? [];
                        foreach ($pv_vaccs as &$pvv) {
                            // Status from the due date (not a stored label); entries not recorded by a vet are marked as owner-reported
                            $pvv['status'] = empty($pvv['next_due']) ? 'Up to date' : ($pvv['next_due'] < date('Y-m-d') ? 'Overdue' : ($pvv['next_due'] <= date('Y-m-d', strtotime('+30 days')) ? 'Due soon' : 'Up to date'));
                            if (trim((string)($pvv['administered_by'] ?? '')) === '') $pvv['administered_by'] = 'Not clinic-verified';
                        }
                        unset($pvv);
                        if (!empty($p['date_of_birth'])) $p['age'] = (new DateTime($p['date_of_birth']))->diff(new DateTime())->y;
                        $rec_cnt = count($history_list);
                        $open_visit = $pv_open_visit($p);
                        $p_json = json_encode([
                            'id' => $p['id'],
                            'name' => $p['name'],
                            'species' => $p['species'] ?? 'N/A',
                            'breed' => $p['breed'] ?? 'N/A',
                            'age' => $p['age'] ?? 0,
                            'weight' => $p['weight'] ?? 0,
                            'owner_name' => $p['owner_name'] ?? 'N/A',
                            'owner_contact' => $p['owner_contact'] ?? 'N/A',
                            'owner_email' => $p['owner_email'] ?? 'N/A',
                            'medical_history' => $p['medical_history'] ?? '',
                            'gender' => $p['gender'] ?? '',
                            'gender_label' => $p['gender'] ?: 'Not recorded',
                            'date_of_birth' => $p['date_of_birth'] ?? '',
                            'raw_species' => $p['species'] ?? '',
                            'raw_breed' => $p['breed'] ?? '',
                            'raw_age' => $p['age'],
                            'raw_weight' => $p['weight'],
                            'history_records' => $history_list,
                            'vaccinations' => $pv_vaccs,
                            'open_visit' => $open_visit
                        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                        $all_pets_json[$p['id']] = $p_json;
                    ?>
                    <tr>
                        <td data-order="<?= htmlspecialchars(mb_strtolower(trim($p['name']))) ?>">
                            <div class="d-flex align-items-center gap-2">
                                <?php if (!empty($p['pet_picture']) && is_file(__DIR__ . '/uploads/pets/' . $p['pet_picture'])): ?>
                                <img class="ep-av" src="uploads/pets/<?= htmlspecialchars($p['pet_picture']) ?>" alt="">
                                <?php else: ?>
                                <span class="ep-av"><?= htmlspecialchars(mb_strtoupper(mb_substr(trim($p['name']), 0, 1))) ?></span>
                                <?php endif; ?>
                                <div><div class="fw-bold text-dark"><?= htmlspecialchars($p['name']) ?></div>
                                <span class="ep-id">#<?= str_pad($p['id'], 5, '0', STR_PAD_LEFT) ?></span></div>
                            </div>
                        </td>
                        <td>
                            <?php $sp_l = trim((string)($p['species'] ?? '')); $br_l = trim((string)($p['breed'] ?? '')); ?>
                            <span class="ep-species"><?= htmlspecialchars($sp_l !== '' ? ucfirst($sp_l) : 'Not recorded') ?></span>
                            <div class="small text-muted mt-1"><?= htmlspecialchars($br_l !== '' ? ucfirst($br_l) : 'Breed not recorded') ?></div>
                        </td>
                        <td>
                            <?php $ag = (int)($p['age'] ?? 0); $wt = (float)($p['weight'] ?? 0); ?>
                            <div class="fw-semibold text-dark"><?= $ag > 0 ? $ag . ' yr' . ($ag !== 1 ? 's' : '') : (!empty($p['date_of_birth']) ? 'Under 1 yr' : '<span class="text-muted fw-normal">Age not recorded</span>') ?></div>
                            <div class="small text-muted"><?= $wt > 0 ? rtrim(rtrim(number_format($wt, 2, '.', ''), '0'), '.') . ' kg' : 'Weight not recorded' ?></div>
                        </td>
                        <td>
                            <?php $oc = trim((string)($p['owner_contact'] ?? '')); $oc_ok = $oc !== '' && $oc !== '0' && strcasecmp($oc, 'N/A') !== 0; ?>
                            <div class="fw-semibold text-dark"><?= htmlspecialchars(ucwords($p['owner_name'] ?? 'Unregistered')) ?></div>
                            <div class="small text-muted"><i class="fa fa-phone me-1" style="font-size:11px;opacity:.6;"></i><?= $oc_ok ? htmlspecialchars($oc) : 'No contact number' ?></div>
                        </td>
                        <td>
                            <span class="ep-recs<?= $rec_cnt ? ' ep-recs-has' : '' ?>">
                                <i class="fa fa-file-medical me-1"></i><?= $rec_cnt ?> record<?= $rec_cnt !== 1 ? 's' : '' ?>
                            </span>
                        </td>
                        <td class="text-end">
                            <?php if ($open_visit): ?>
                            <a href="doctor_consultations.php?open=<?= (int)$open_visit ?>" class="btn btn-sm ep-start" title="This patient is checked in with you">
                                <i class="fa fa-stethoscope me-1"></i> Start Consultation
                            </a>
                            <?php else: ?>
                            <button class="btn btn-sm ep-view" onclick='openEmrModal(<?= htmlspecialchars($p_json, ENT_QUOTES, "UTF-8") ?>)'>
                                <i class="fa fa-folder-open me-1"></i> View EMR
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
            <div class="text-center py-5">
                <i class="fa fa-paw fa-4x text-muted opacity-25 mb-3"></i>
                <h5 class="text-muted">No pet patient records found.</h5>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- EMR Modal -->
<div class="modal fade" id="emrModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 20px;">
            <div class="modal-header border-0 ep-mhead">
                <h5 class="fw-bold m-0"><i class="fa fa-file-medical me-2"></i>Pet Electronic Medical Record (EMR)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="p-3 ep-summary mb-4">
                    <div class="row g-2 text-dark">
                        <div class="col-md-6"><h5 class="fw-bold m-0" id="emrPetName"></h5></div>
                        <div class="col-md-6 text-md-end"><span class="badge bg-primary" id="emrSpecies"></span></div>
                        <div class="col-md-6 small"><i class="fa fa-dna me-1 opacity-50"></i><b>Breed:</b> <span id="emrBreed"></span></div>
                        <div class="col-md-3 small"><i class="fa fa-calendar me-1 opacity-50"></i><b>Age:</b> <span id="emrAge"></span> yr(s)</div>
                        <div class="col-md-3 small"><i class="fa fa-weight me-1 opacity-50"></i><b>Weight:</b> <span id="emrWeight"></span> kg</div>
                        <div class="col-12 border-top pt-2 mt-2 small"><i class="fa fa-user me-1 opacity-50"></i><b>Owner:</b> <span id="emrOwner"></span></div>
                    </div>
                </div>

                <ul class="nav nav-pills nav-fill gap-2 mb-3" role="tablist" style="font-size:13px;">
                    <li class="nav-item"><button class="nav-link active fw-semibold" data-bs-toggle="pill" data-bs-target="#tabProfile" type="button" id="tabBtn-profile"><i class="fa fa-heart-pulse me-1"></i>Health Profile</button></li>
                    <li class="nav-item"><button class="nav-link fw-semibold" data-bs-toggle="pill" data-bs-target="#tabRecords" type="button" id="tabBtn-records"><i class="fa fa-notes-medical me-1"></i>Clinical Records <span class="badge bg-light text-primary ms-1" id="emrRecCount">0</span></button></li>
                    <li class="nav-item"><button class="nav-link fw-semibold" data-bs-toggle="pill" data-bs-target="#tabVaccines" type="button" id="tabBtn-vaccines"><i class="fa fa-syringe me-1"></i>Vaccinations <span class="badge bg-light text-primary ms-1" id="emrVacCount">0</span></button></li>
                </ul>

                <div class="tab-content">
                    <!-- Health Profile -->
                    <div class="tab-pane fade show active" id="tabProfile">
                        <form method="POST">
                            <input type="hidden" name="emr_action" value="update_profile">
                            <input type="hidden" name="return_tab" value="profile">
                            <input type="hidden" name="pet_id" class="emr-pet-id">
                            <div class="row g-3">
                                <div class="col-md-4"><label class="form-label small fw-bold text-muted">Species</label><input type="text" name="species" id="pfSpecies" class="form-control" readonly tabindex="-1" style="background:#f1f5f9;cursor:not-allowed;" title="Registered by the owner" maxlength="50"></div>
                                <div class="col-md-4"><label class="form-label small fw-bold text-muted">Breed</label><input type="text" name="breed" id="pfBreed" class="form-control" readonly tabindex="-1" style="background:#f1f5f9;cursor:not-allowed;" title="Registered by the owner" maxlength="50"></div>
                                <div class="col-md-4"><label class="form-label small fw-bold text-muted">Gender</label>
                                    <select name="gender" id="pfGender" class="form-select" style="background-color:#f1f5f9;cursor:not-allowed;pointer-events:none;" tabindex="-1" title="Registered by the owner"><option value="">Not recorded</option><option>Male</option><option>Female</option></select></div>
                                <div class="col-md-4"><label class="form-label small fw-bold text-muted">Date of Birth</label><input type="date" name="date_of_birth" id="pfDob" class="form-control" readonly tabindex="-1" style="background:#f1f5f9;cursor:not-allowed;" title="Registered by the owner" max="<?= date('Y-m-d') ?>"></div>
                                <div class="col-md-4"><label class="form-label small fw-bold text-muted">Age (years)</label><input type="number" name="age" id="pfAge" class="form-control" readonly tabindex="-1" style="background:#f1f5f9;cursor:not-allowed;" title="Registered by the owner" min="0" max="50"></div>
                                <div class="col-md-4"><label class="form-label small fw-bold text-muted">Weight (kg)</label><input type="number" name="weight" id="pfWeight" class="form-control" min="0" step="0.01"></div>
                                <div class="col-12"><label class="form-label small fw-bold text-muted">Medical History / Allergies / Chronic Conditions</label><textarea name="medical_history" id="pfHistory" class="form-control" rows="3"></textarea></div>
                            </div>
                            <button type="submit" class="btn btn-primary fw-bold rounded-pill px-4 mt-3"><i class="fa fa-save me-1"></i> Save Health Profile</button>
                        </form>
                    </div>

                    <!-- Clinical Records -->
                    <div class="tab-pane fade" id="tabRecords">
                        <div class="d-flex justify-content-end mb-3">
                            <button type="button" class="btn btn-sm btn-primary rounded-pill px-3 fw-bold" onclick="editRecord(null)"><i class="fa fa-plus me-1"></i> Add Record</button>
                        </div>
                        <form method="POST" id="recordForm" class="p-3 mb-3 rounded-3 border bg-white" style="display:none;">
                            <input type="hidden" name="emr_action" value="save_record">
                            <input type="hidden" name="return_tab" value="records">
                            <input type="hidden" name="pet_id" class="emr-pet-id">
                            <input type="hidden" name="record_id" id="rfId">
                            <h6 class="fw-bold mb-3" id="rfHeading">New Clinical Record</h6>
                            <div class="row g-2">
                                <div class="col-md-4"><label class="form-label small fw-bold text-muted">Type</label>
                                    <select name="record_type" id="rfType" class="form-select">
                                        <option value="checkup">Checkup</option><option value="diagnosis">Diagnosis</option><option value="treatment">Treatment</option>
                                        <option value="follow_up">Follow-up</option><option value="surgery">Surgery</option><option value="lab_test">Lab Test</option>
                                        <option value="vaccination">Vaccination</option><option value="other">Other</option>
                                    </select></div>
                                <div class="col-md-4"><label class="form-label small fw-bold text-muted">Date</label><input type="date" name="record_date" id="rfDate" class="form-control" required></div>
                                <div class="col-md-4"><label class="form-label small fw-bold text-muted">Status</label>
                                    <select name="status" id="rfStatus" class="form-select"><option>Completed</option><option>Pending</option><option>In Progress</option><option>Cancelled</option></select></div>
                                <div class="col-12"><label class="form-label small fw-bold text-muted">Title</label><input type="text" name="title" id="rfTitle" class="form-control" maxlength="200" required></div>
                                <div class="col-12"><label class="form-label small fw-bold text-muted">Findings / Diagnosis</label><textarea name="description" id="rfDesc" class="form-control" rows="2"></textarea></div>
                                <div class="col-12"><label class="form-label small fw-bold text-muted">Notes / Treatment</label><textarea name="notes" id="rfNotes" class="form-control" rows="2"></textarea></div>
                            </div>
                            <div class="d-flex gap-2 mt-3">
                                <button type="submit" class="btn btn-primary btn-sm fw-bold rounded-pill px-3"><i class="fa fa-save me-1"></i> Save Record</button>
                                <button type="button" class="btn btn-light btn-sm rounded-pill px-3" onclick="document.getElementById('recordForm').style.display='none'">Cancel</button>
                            </div>
                        </form>
                        <div id="emrHistoryList"></div>
                    </div>

                    <!-- Vaccinations -->
                    <div class="tab-pane fade" id="tabVaccines">
                        <div class="d-flex justify-content-end mb-3">
                            <button type="button" class="btn btn-sm btn-primary rounded-pill px-3 fw-bold" onclick="editVaccine(null)"><i class="fa fa-plus me-1"></i> Add Vaccination</button>
                        </div>
                        <form method="POST" id="vaccineForm" class="p-3 mb-3 rounded-3 border bg-white" style="display:none;">
                            <input type="hidden" name="emr_action" value="save_vaccination">
                            <input type="hidden" name="return_tab" value="vaccines">
                            <input type="hidden" name="pet_id" class="emr-pet-id">
                            <input type="hidden" name="vaccination_id" id="vfId">
                            <h6 class="fw-bold mb-3" id="vfHeading">New Vaccination</h6>
                            <div class="row g-2">
                                <div class="col-md-6"><label class="form-label small fw-bold text-muted">Vaccine Name</label><input type="text" name="vaccine_name" id="vfName" class="form-control" maxlength="100" required></div>
                                <div class="col-md-6"><label class="form-label small fw-bold text-muted">Batch Number</label><input type="text" name="batch_number" id="vfBatch" class="form-control" maxlength="50"></div>
                                <div class="col-md-6"><label class="form-label small fw-bold text-muted">Date Given</label><input type="date" name="date_given" id="vfGiven" class="form-control" required max="<?= date('Y-m-d') ?>"></div>
                                <div class="col-md-6"><label class="form-label small fw-bold text-muted">Next Due</label><input type="date" name="next_due" id="vfDue" class="form-control"></div>
                                <div class="col-12"><label class="form-label small fw-bold text-muted">Notes</label><textarea name="notes" id="vfNotes" class="form-control" rows="2"></textarea></div>
                            </div>
                            <div class="d-flex gap-2 mt-3">
                                <button type="submit" class="btn btn-primary btn-sm fw-bold rounded-pill px-3"><i class="fa fa-save me-1"></i> Save Vaccination</button>
                                <button type="button" class="btn btn-light btn-sm rounded-pill px-3" onclick="document.getElementById('vaccineForm').style.display='none'">Cancel</button>
                            </div>
                        </form>
                        <div id="emrVaccineList"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script>
$(document).ready(function() {
    var table = $('#petsTable').DataTable({
        "dom": "rtip",
        "language": { "search": "", "searchPlaceholder": "Search...", "info": "Showing _START_–_END_ of _TOTAL_ pets", "infoEmpty": "No pets found", "infoFiltered": "(filtered from _MAX_)", "zeroRecords": "No pet matches your search.", "paginate": { "previous": "‹", "next": "›" } }
    });
    $('#customSearch').on('keyup', function () {
        table.search(this.value).draw();
    });
    // Summary chips: count over all rows, not just the visible page
    var nodes = $(table.rows().nodes());
    var nIn = nodes.find('.ep-start').length, nRec = nodes.find('.ep-recs-has').length;
    $('#epChipRec b').text(nRec);
    if (nIn) $('#epChipIn').show().find('b').text(nIn);
});

// Pet checked in with this vet and not yet consulted → open its consultation; otherwise the EMR
function openEmrOrConsult(p) {
    if (p.open_visit) { location.href = 'doctor_consultations.php?open=' + p.open_visit; return; }
    openEmrModal(p);
}

function openEmrModal(p, tab) {
    document.getElementById('emrPetName').textContent = p.name;
    document.getElementById('emrSpecies').textContent = p.species + ' (' + p.breed + ')';
    document.getElementById('emrBreed').textContent = p.breed;
    document.getElementById('emrAge').textContent = p.age;
    document.getElementById('emrWeight').textContent = p.weight;
    document.getElementById('emrOwner').textContent = p.owner_name + ' | Contact: ' + p.owner_contact + ' | ' + p.owner_email;

    currentPet = p;
    document.querySelectorAll('.emr-pet-id').forEach(function(i) { i.value = p.id; });

    // Health profile form
    document.getElementById('pfSpecies').value = p.raw_species || '';
    document.getElementById('pfBreed').value   = p.raw_breed || '';
    document.getElementById('pfGender').value  = p.gender || '';
    document.getElementById('pfDob').value     = p.date_of_birth || '';
    document.getElementById('pfAge').value     = p.raw_age ?? '';
    document.getElementById('pfWeight').value  = p.raw_weight ?? '';
    document.getElementById('pfHistory').value = p.medical_history || '';

    // Clinical records
    document.getElementById('recordForm').style.display = 'none';
    document.getElementById('emrRecCount').textContent = (p.history_records || []).length;
    var container = document.getElementById('emrHistoryList');
    if (!p.history_records || p.history_records.length === 0) {
        container.innerHTML = '<div class="text-center text-muted py-4"><i class="fa fa-notes-medical fa-2x opacity-25 mb-2 d-block"></i>No prior clinical records on file for this pet.</div>';
    } else {
        var html = '<div class="timeline">';
        p.history_records.forEach(function(rec, i) {
            html += '<div class="card border-0 shadow-sm mb-3" style="border-radius:12px; background:#f8fafc;">'
                 + '<div class="card-body">'
                 + '<div class="d-flex justify-content-between align-items-center mb-2">'
                 + '<span class="fw-bold text-primary"><i class="fa fa-calendar me-1"></i>' + escHtml(rec.record_date || 'N/A')
                 + ' <span class="badge bg-primary bg-opacity-10 text-primary ms-1">' + escHtml((rec.record_type || '').replace('_', ' ')) + '</span>'
                 + ' <span class="badge bg-light text-secondary border ms-1">' + escHtml(rec.status || '') + '</span></span>'
                 + '<span><span class="badge bg-secondary me-2">' + escHtml(rec.doctor_name || 'Clinic Vet') + '</span>'
                 + (rec.readonly ? '<span class="badge bg-light text-muted border">From visit</span>' : '<button type="button" class="btn btn-sm btn-outline-primary rounded-pill py-0 px-2" onclick="editRecord(' + i + ')"><i class="fa fa-pen me-1"></i>Edit</button>') + '</span>'
                 + '</div>'
                 + '<div class="fw-semibold text-dark mb-1">' + escHtml(rec.title || '') + '</div>'
                 + '<div class="mb-1"><b>Findings:</b> ' + escHtml(rec.description || 'N/A') + '</div>'
                 + '<div class="small text-muted"><b>Notes / Treatment:</b> ' + escHtml(rec.notes || 'No extra notes') + '</div>'
                 + '</div></div>';
        });
        html += '</div>';
        container.innerHTML = html;
    }

    // Vaccinations
    document.getElementById('vaccineForm').style.display = 'none';
    var vaccs = p.vaccinations || [];
    document.getElementById('emrVacCount').textContent = vaccs.length;
    var vc = document.getElementById('emrVaccineList');
    if (vaccs.length === 0) {
        vc.innerHTML = '<div class="text-center text-muted py-4"><i class="fa fa-syringe fa-2x opacity-25 mb-2 d-block"></i>No vaccinations on file for this pet.</div>';
    } else {
        var vh = '<div class="table-responsive"><table class="table table-sm align-middle" style="font-size:13px;"><thead class="table-light"><tr><th>Vaccine</th><th>Given</th><th>Next Due</th><th>Status</th><th>By</th><th></th></tr></thead><tbody>';
        vaccs.forEach(function(v, i) {
            var cls = v.status === 'Overdue' ? 'danger' : (v.status === 'Due soon' ? 'warning' : 'success');
            vh += '<tr><td class="fw-semibold">' + escHtml(v.vaccine_name) + (v.batch_number ? '<div class="small text-muted">Batch ' + escHtml(v.batch_number) + '</div>' : '') + '</td>'
               + '<td>' + escHtml(v.date_given || '') + '</td><td>' + escHtml(v.next_due || '—') + '</td>'
               + '<td><span class="badge bg-' + cls + '-subtle text-' + cls + '">' + escHtml(v.status || '') + '</span></td>'
               + '<td class="small text-muted">' + escHtml(v.administered_by || '—') + '</td>'
               + '<td class="text-end"><button type="button" class="btn btn-sm btn-outline-primary rounded-pill py-0 px-2" onclick="editVaccine(' + i + ')"><i class="fa fa-pen me-1"></i>Edit</button></td></tr>';
        });
        vc.innerHTML = vh + '</tbody></table></div>';
    }

    bootstrap.Tab.getOrCreateInstance(document.getElementById('tabBtn-' + (tab || 'profile'))).show(); // records button opens the Records tab
    var modalEl = document.getElementById('emrModal');
    bootstrap.Modal.getOrCreateInstance(modalEl).show();
}

var currentPet = null;

function editRecord(i) {
    var r = (i === null) ? null : currentPet.history_records[i];
    document.getElementById('rfHeading').textContent = r ? 'Edit Clinical Record' : 'New Clinical Record';
    document.getElementById('rfId').value     = r ? r.id : '';
    document.getElementById('rfType').value   = r ? r.record_type : 'checkup';
    document.getElementById('rfDate').value   = r ? r.record_date : new Date().toISOString().slice(0, 10);
    document.getElementById('rfStatus').value = r ? (r.status || 'Completed') : 'Completed';
    document.getElementById('rfTitle').value  = r ? (r.title || '') : '';
    document.getElementById('rfDesc').value   = r ? (r.description || '') : '';
    document.getElementById('rfNotes').value  = r ? (r.notes || '') : '';
    var f = document.getElementById('recordForm');
    f.style.display = 'block';
    f.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function editVaccine(i) {
    var v = (i === null) ? null : currentPet.vaccinations[i];
    document.getElementById('vfHeading').textContent = v ? 'Edit Vaccination' : 'New Vaccination';
    document.getElementById('vfId').value    = v ? v.id : '';
    document.getElementById('vfName').value  = v ? v.vaccine_name : '';
    document.getElementById('vfBatch').value = v ? (v.batch_number || '') : '';
    document.getElementById('vfGiven').value = v ? v.date_given : new Date().toISOString().slice(0, 10);
    document.getElementById('vfDue').value   = v ? (v.next_due || '') : '';
    document.getElementById('vfNotes').value = v ? (v.notes || '') : '';
    var f = document.getElementById('vaccineForm');
    f.style.display = 'block';
    f.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

// Reopen the pet's EMR (on the same tab) after saving
document.addEventListener('DOMContentLoaded', function() {
    var ALL_PETS = <?= json_encode(array_map(function($j) { return json_decode($j, true); }, $all_pets_json), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    var params = new URLSearchParams(location.search);
    var open = params.get('open');
    if (open && ALL_PETS[open]) {
        openEmrModal(ALL_PETS[open]);
        var tabBtn = document.getElementById('tabBtn-' + (params.get('tab') || 'profile'));
        if (tabBtn) bootstrap.Tab.getOrCreateInstance(tabBtn).show();
        history.replaceState(null, '', 'doctor_patients.php');
    }
});

function escHtml(t) {
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(t || ''));
    return d.innerHTML;
}
</script>
</body>
</html>
