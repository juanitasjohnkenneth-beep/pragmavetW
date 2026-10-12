<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include("dbconnection.php");
include_once("includes/pet_data_helper.php");

if (!isset($_SESSION['user_id'])) {
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


$user_id = (int)$_SESSION['user_id'];
$user_name = $_SESSION['user'] ?? 'Pet Owner';

// Utility: File Upload Helper
function uploadImage($file_array, $destination_dir) {
    if (isset($file_array) && $file_array['error'] === 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        $filename = $file_array['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if (in_array($ext, $allowed)) {
            $new_name = uniqid() . '.' . $ext;
            if (!is_dir($destination_dir)) {
                @mkdir($destination_dir, 0777, true);
            }
            $dest = $destination_dir . '/' . $new_name;
            if (move_uploaded_file($file_array['tmp_name'], $dest)) {
                return $new_name;
            }
        }
    }
    return null;
}

// --- 1. ACTION: ADD NEW PET ---
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_pet') {
    $name = trim($_POST['name']);
    $species = trim($_POST['species']);
    $breed = empty($_POST['breed']) ? 'Unknown' : trim($_POST['breed']);
    $gender = empty($_POST['gender']) ? 'Female' : trim($_POST['gender']);
    $dob = !empty($_POST['date_of_birth']) ? $_POST['date_of_birth'] : null;
    $age = empty($_POST['age']) ? null : (int)$_POST['age'];
    if ($dob && !$age) {
        $dob_dt = new DateTime($dob);
        $now_dt = new DateTime();
        $age = $dob_dt->diff($now_dt)->y;
    }
    $weight = empty($_POST['weight']) ? null : (float)$_POST['weight'];
    $last_vax = empty($_POST['last_vaccination']) ? null : $_POST['last_vaccination'];
    $next_vax = empty($_POST['next_vaccination']) ? null : $_POST['next_vaccination'];
    $pet_pic = uploadImage($_FILES['pet_pic'], 'uploads/pets');

    $stmt = $conn->prepare("INSERT INTO pets (user_id, name, species, breed, gender, date_of_birth, age, weight, pet_picture, last_vaccination, next_vaccination) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isssssidsss", $user_id, $name, $species, $breed, $gender, $dob, $age, $weight, $pet_pic, $last_vax, $next_vax);
    
    if ($stmt->execute()) {
        $new_pet_id = $conn->insert_id;


        // Initialize default vaccine record if dates provided
        if ($last_vax) {
            $stmt_vax = $conn->prepare("INSERT INTO vaccination_records (pet_id, vaccine_name, date_given, next_due, status) VALUES (?, 'Core Vaccination', ?, ?, 'Up to date')");
            $stmt_vax->bind_param("iss", $new_pet_id, $last_vax, $next_vax);
            $stmt_vax->execute();
        }

        $_SESSION['message'] = "Pet profile for <b>" . htmlspecialchars($name) . "</b> created successfully!";
        $_SESSION['msg_type'] = "success";
        header("Location: pets.php?pet_id=" . $new_pet_id);
        exit();
    } else {
        $_SESSION['message'] = "Error adding pet: " . $conn->error;
        $_SESSION['msg_type'] = "danger";
        header("Location: pets.php");
        exit();
    }
}

// --- 2. ACTION: EDIT PET PROFILE ---
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_pet_profile') {
    $edit_pet_id = (int)$_POST['pet_id'];
    $name = trim($_POST['name']);
    $species = trim($_POST['species']);
    $breed = empty($_POST['breed']) ? 'Unknown' : trim($_POST['breed']);
    $gender = trim($_POST['gender']);
    $dob = !empty($_POST['date_of_birth']) ? $_POST['date_of_birth'] : null;
    $weight = empty($_POST['weight']) ? null : (float)$_POST['weight'];
    
    $calc_age = null;
    if ($dob) {
        $dob_dt = new DateTime($dob);
        $now_dt = new DateTime();
        $calc_age = $dob_dt->diff($now_dt)->y;
    }

    $check = $conn->query("SELECT id FROM pets WHERE id = $edit_pet_id AND user_id = $user_id");
    if ($check && $check->num_rows > 0) {
        $pic_query = "";
        $uploaded_pic = uploadImage($_FILES['pet_pic'], 'uploads/pets');
        if ($uploaded_pic) {
            $pic_query = ", pet_picture = '$uploaded_pic' ";
        }

        $stmt = $conn->prepare("UPDATE pets SET name = ?, species = ?, breed = ?, gender = ?, date_of_birth = ?, age = ?, weight = ? $pic_query WHERE id = ? AND user_id = ?");
        $stmt->bind_param("sssssidii", $name, $species, $breed, $gender, $dob, $calc_age, $weight, $edit_pet_id, $user_id);
        
        if ($stmt->execute()) {
            if (function_exists('syncPetProfileAcrossAppointments')) {
                syncPetProfileAcrossAppointments($conn, $edit_pet_id);
            }
            $_SESSION['message'] = "Pet profile for <b>" . htmlspecialchars($name) . "</b> updated successfully!";
            $_SESSION['msg_type'] = "success";
        } else {
            $_SESSION['message'] = "Error updating pet: " . $conn->error;
            $_SESSION['msg_type'] = "danger";
        }
    }
    header("Location: pets.php?pet_id=" . $edit_pet_id);
    exit();
}

// --- 3. ACTION: UPDATE IMPORTANT NOTES ---
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_notes') {
    $notes_pet_id = (int)$_POST['pet_id'];
    $allergies = trim($_POST['allergies']);
    $special_instructions = trim($_POST['special_instructions']);

    $check = $conn->query("SELECT id FROM pets WHERE id = $notes_pet_id AND user_id = $user_id");
    if ($check && $check->num_rows > 0) {
        $stmt = $conn->prepare("INSERT INTO pet_notes (pet_id, allergies, special_instructions) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE allergies = VALUES(allergies), special_instructions = VALUES(special_instructions)");
        $stmt->bind_param("iss", $notes_pet_id, $allergies, $special_instructions);
        $stmt->execute();
        $_SESSION['message'] = "Important notes updated successfully!";
        $_SESSION['msg_type'] = "success";
    }
    header("Location: pets.php?pet_id=" . $notes_pet_id);
    exit();
}

// --- 5. Owner profile is edited in Account Settings (settings.php) ---

// Fetch ALL pets belonging to this user
$stmt_all_pets = $conn->prepare("SELECT * FROM pets WHERE user_id = ? ORDER BY id ASC");
$stmt_all_pets->bind_param("i", $user_id);
$stmt_all_pets->execute();
$all_pets_res = $stmt_all_pets->get_result();
$all_user_pets = [];
while ($p = $all_pets_res->fetch_assoc()) {
    $all_user_pets[] = $p;
}

// Request a Health & Vaccination Certificate (reviewed by the admin in Certificates)
if (isset($_POST['request_certificate'])) {
    $req_pet = (int)($_POST['pet_id'] ?? 0);
    $purpose = trim($_POST['purpose'] ?? '');
    $own = $conn->prepare("SELECT name FROM pets WHERE id = ? AND user_id = ?");
    $own->bind_param("ii", $req_pet, $user_id);
    $own->execute();
    $own_pet = $own->get_result()->fetch_assoc();
    if (!$own_pet || $purpose === '') {
        $_SESSION['message'] = "Please choose the purpose of the certificate.";
        $_SESSION['msg_type'] = "danger";
    } elseif ($conn->query("SELECT 1 FROM certificate_requests WHERE pet_id = $req_pet AND user_id = " . (int)$user_id . " AND status = 'Pending'")->num_rows) {
        $_SESSION['message'] = "You already have a pending certificate request for {$own_pet['name']}. We'll notify you once it's reviewed.";
        $_SESSION['msg_type'] = "warning";
    } else {
        $ins = $conn->prepare("INSERT INTO certificate_requests (user_id, pet_id, purpose, status) VALUES (?, ?, ?, 'Pending')");
        $ins->bind_param("iis", $user_id, $req_pet, $purpose);
        $ins->execute();
        $_SESSION['message'] = "Certificate request sent for {$own_pet['name']}. We'll notify you when it's ready.";
        $_SESSION['msg_type'] = "success";
    }
    header("Location: pets.php?pet_id=" . $req_pet);
    exit();
}

// Determine active pet_id
$pet_id = isset($_GET['pet_id']) ? (int)$_GET['pet_id'] : 0;
$active_pet = null;

if ($pet_id > 0) {
    foreach ($all_user_pets as $p) {
        if ($p['id'] == $pet_id) {
            $active_pet = $p;
            break;
        }
    }
}

if (!$active_pet && !empty($all_user_pets)) {
    $active_pet = $all_user_pets[0];
    $pet_id = (int)$active_pet['id'];
}

if (!$active_pet) {
    // New user with no pets — show empty state (no fallback to other users' pets)
    $active_pet = null;
    $pet_id = 0;
}

// Fetch Owner Data
$stmt_owner = $conn->prepare("SELECT id, fullname, email, contact_number, address, profile_picture, created_at FROM users WHERE id = ?");
$stmt_owner->bind_param("i", $user_id);
$stmt_owner->execute();
$owner = $stmt_owner->get_result()->fetch_assoc();

$owner_name = !empty($owner['fullname']) ? $owner['fullname'] : 'Juan Dela Cruz';
$owner_email = !empty($owner['email']) ? $owner['email'] : 'owner@pragmavet.com';
$owner_phone = !empty($owner['contact_number']) && $owner['contact_number'] !== '0' ? $owner['contact_number'] : '+63 912 345 6789';
$owner_address = !empty($owner['address']) ? $owner['address'] : '123 Sunshine St., Manila';
$owner_pic = !empty($owner['profile_picture']) && file_exists('uploads/profiles/' . $owner['profile_picture']) ? 'uploads/profiles/' . $owner['profile_picture'] : '';

// Pet Demographics
$pet_name = !empty($active_pet['name']) ? $active_pet['name'] : 'Coco';
$pet_species = !empty($active_pet['species']) ? ucfirst($active_pet['species']) : '';
$pet_breed = !empty($active_pet['breed']) ? $active_pet['breed'] : '';
$pet_gender = !empty($active_pet['gender']) ? ucfirst($active_pet['gender']) : '';
$pet_dob = $active_pet['date_of_birth'] ?? null;
$pet_age = $active_pet['age'] ?? null;

$pet_age_display = 'Age not specified';
if ($pet_dob) {
    $dob_dt = new DateTime($pet_dob);
    $now_dt = new DateTime();
    $diff = $dob_dt->diff($now_dt);
    $yrs = $diff->y;
    $pet_age_display = $yrs . ' year' . ($yrs != 1 ? 's' : '') . ' (DOB: ' . date('M j, Y', strtotime($pet_dob)) . ')';
} elseif ($pet_age !== null && $pet_age > 0) {
    $pet_age_display = $pet_age . ' year' . ($pet_age != 1 ? 's' : '');
}

$pet_pic_url = '';
if (!empty($active_pet['pet_picture']) && file_exists('uploads/pets/' . $active_pet['pet_picture'])) {
    $pet_pic_url = 'uploads/pets/' . $active_pet['pet_picture'];
}

// This pet's appointments: the owner's own bookings, linked to this pet by ID, or — for older bookings
// without a pet link — by the pet's exact name (not a partial match, and never another owner's pet)
$pet_appt_ids = [];
if ($pet_id > 0) {
    $stmt_pa = $conn->prepare("
        SELECT a.id FROM appointments a
        WHERE a.user_id = ?
          AND ( EXISTS (SELECT 1 FROM appointment_pets ap WHERE ap.appointment_id = a.id AND ap.pet_id = ?)
                OR ( NOT EXISTS (SELECT 1 FROM appointment_pets ap WHERE ap.appointment_id = a.id AND ap.pet_id IS NOT NULL)
                     AND ( FIND_IN_SET(LOWER(?), LOWER(REPLACE(a.pet_name, ', ', ','))) > 0
                           OR EXISTS (SELECT 1 FROM appointment_pets ap WHERE ap.appointment_id = a.id AND LOWER(ap.pet_name) = LOWER(?)) ) ) )
    ");
    $stmt_pa->bind_param("iiss", $user_id, $pet_id, $pet_name, $pet_name);
    $stmt_pa->execute();
    $pa_res = $stmt_pa->get_result();
    while ($row = $pa_res->fetch_assoc()) $pet_appt_ids[] = (int)$row['id'];
}
$pet_appt_in = $pet_appt_ids ? implode(',', $pet_appt_ids) : '0';

// Fetch Next Upcoming Appointment (null when there is none)
$next_appointment = null;
$next_res = $conn->query("
    SELECT * FROM appointments
    WHERE id IN ($pet_appt_in)
      AND appointment_date >= CURDATE()
      AND status IN ('Approved', 'Pending', 'Scheduled')
    ORDER BY appointment_date ASC, appointment_time ASC
    LIMIT 1
");
if ($next_res && $next_res->num_rows > 0) {
    $next_appointment = $next_res->fetch_assoc();
}
// Fetch Medical Records
$medical_records = [];
$stmt_mr = $conn->prepare("SELECT * FROM medical_records WHERE pet_id = ? ORDER BY record_date DESC, record_time DESC");
$stmt_mr->bind_param("i", $pet_id);
$stmt_mr->execute();
$mr_res = $stmt_mr->get_result();
while ($row = $mr_res->fetch_assoc()) {
    $medical_records[] = $row;
}

// Completed visits of this pet that have no medical record yet (e.g. closed without consultation notes)
$visits = $conn->query("
    SELECT a.id, a.service_type, a.appointment_date, a.appointment_time, d.fullname AS doctor_name
    FROM appointments a
    LEFT JOIN users d ON d.id = a.doctor_id
    WHERE a.id IN ($pet_appt_in) AND a.status = 'Completed'
");
$recorded_appts = [];
$recorded_dates = [];
foreach ($medical_records as $mr) {
    if (!empty($mr['appointment_id'])) $recorded_appts[(int)$mr['appointment_id']] = true;
    $recorded_dates[$mr['record_date']] = true;
}
if ($visits) while ($v = $visits->fetch_assoc()) {
    if (isset($recorded_appts[(int)$v['id']]) || isset($recorded_dates[$v['appointment_date']])) continue;
    $medical_records[] = [
        'id' => 'appt-' . $v['id'],
        'appointment_id' => $v['id'],
        'record_type' => 'checkup',
        'title' => $v['service_type'] ?: 'Clinic Visit',
        'doctor_name' => $v['doctor_name'] ?? '',
        'description' => 'Completed visit. No consultation notes were recorded for this visit.',
        'record_date' => $v['appointment_date'],
        'record_time' => $v['appointment_time'],
        'status' => 'Completed',
        'notes' => '',
    ];
}
usort($medical_records, function ($x, $y) {
    return strcmp($y['record_date'] . ' ' . ($y['record_time'] ?? ''), $x['record_date'] . ' ' . ($x['record_time'] ?? ''));
});
// Fetch Vaccination Records
$vaccination_records = [];
$stmt_vax = $conn->prepare("SELECT * FROM vaccination_records WHERE pet_id = ? ORDER BY date_given DESC");
$stmt_vax->bind_param("i", $pet_id);
$stmt_vax->execute();
$vax_res = $stmt_vax->get_result();
while ($row = $vax_res->fetch_assoc()) {
    $vaccination_records[] = $row;
}

// Fetch Notes
$pet_notes = [
    'allergies' => '',
    'special_instructions' => ''
];
$stmt_notes = $conn->prepare("SELECT * FROM pet_notes WHERE pet_id = ?");
$stmt_notes->bind_param("i", $pet_id);
$stmt_notes->execute();
$notes_res = $stmt_notes->get_result();
if ($notes_res && $notes_res->num_rows > 0) {
    $db_notes = $notes_res->fetch_assoc();
    if (!empty($db_notes['allergies'])) $pet_notes['allergies'] = $db_notes['allergies'];
    if (!empty($db_notes['special_instructions'])) $pet_notes['special_instructions'] = $db_notes['special_instructions'];
}

// Fetch Consultations
$consultations = [];
$stmt_c = $conn->prepare("
    SELECT c.*, a.service_type, a.appointment_date, a.appointment_time
    FROM consultations c
    JOIN appointments a ON c.appointment_id = a.id
    WHERE c.appointment_id IN ($pet_appt_in)
    ORDER BY c.created_at DESC
");
$stmt_c->execute();
$c_res = $stmt_c->get_result();
while ($row = $c_res->fetch_assoc()) {
    $consultations[] = $row;
}

$prescriptions = [];
$lab_results = [];
foreach ($consultations as $c) {
    if (!empty($c['prescription'])) $prescriptions[] = $c;
    if (!empty($c['lab_results_url'])) $lab_results[] = $c;
}

// Active Tab
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'medical_history';
$valid_tabs = ['overview', 'medical_history', 'vaccinations', 'consultations', 'lab_results', 'prescriptions'];
if (!in_array($active_tab, $valid_tabs)) $active_tab = 'medical_history';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile & Pets | PragmaVet</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        :root {
            --sidebar-width: 260px;
            --primary: #1d61d8;
            --primary-hover: #154eb5;
            --primary-light: #e8f1fd;
            --text-dark: #0f172a;
            --text-muted: #64748b;
            --text-subtle: #94a3b8;
            --border-color: #eef2f6;
            --card-bg: #ffffff;
            --body-bg: #f4f8fc;
            --radius-md: 16px;
            --radius-lg: 20px;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Plus Jakarta Sans', 'Inter', -apple-system, sans-serif;
            background-color: var(--body-bg);
            color: var(--text-dark);
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }

        /* ====== WHITE SIDEBAR (STANDARD NAVIGATION THEME) ====== */
        .sidebar {
            background: #ffffff;
            width: var(--sidebar-width);
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            padding: 24px 16px;
            z-index: 1000;
            display: flex;
            flex-direction: column;
            box-shadow: 2px 0 16px rgba(0, 0, 0, 0.04);
            border-right: 1px solid var(--border-color);
            overflow-y: auto;
        }

        .sidebar-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 22px;
            padding-left: 4px;
            text-decoration: none;
        }

        .sidebar-brand-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: #2563eb;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-size: 18px;
            box-shadow: 0 4px 10px rgba(37, 99, 235, 0.25);
            flex-shrink: 0;
        }

        .sidebar-brand span {
            font-weight: 800;
            color: #0f172a;
            font-size: 19px;
            letter-spacing: -0.3px;
        }

        .sidebar-user {
            margin-bottom: 20px;
            padding: 12px 14px;
            border-radius: 10px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
        }

        .sidebar-user .welcome {
            font-size: 12px;
            color: #64748b;
            font-weight: 500;
        }

        .sidebar-user .uname {
            font-weight: 700;
            color: #0f172a;
            font-size: 15px;
            margin-top: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .sidebar a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 14px;
            border-radius: 8px;
            color: #475569;
            text-decoration: none;
            margin-bottom: 4px;
            font-weight: 600;
            font-size: 14.5px;
            transition: all 0.2s ease;
        }

        .sidebar a i {
            width: 24px;
            text-align: center;
            font-size: 16px;
            color: #64748b;
            transition: color 0.2s;
        }

        .sidebar a:hover {
            background: #f1f5f9;
            color: #0f172a;
        }

        .sidebar a:hover i {
            color: #2563eb;
        }

        .sidebar a.active {
            background: #2563eb;
            color: #ffffff !important;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);
        }

        .sidebar a.active i {
            color: #ffffff !important;
        }

        .sidebar .sidebar-logout {
            margin-top: auto;
            padding-top: 14px;
            border-top: 1px solid #f1f5f9;
        }

        .sidebar .sidebar-logout a {
            color: #dc2626 !important;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            font-size: 14.5px;
            transition: all 0.2s;
        }

        .sidebar .sidebar-logout a i {
            color: #dc2626 !important;
            width: 24px;
            text-align: center;
            font-size: 16px;
        }

        .sidebar .sidebar-logout a:hover {
            background: #fee2e2;
            color: #b91c1c !important;
        }

        /* ====== MAIN CONTENT AREA ====== */
        .main-content {
            margin-left: var(--sidebar-width);
            padding: 36px 40px 60px;
            min-height: 100vh;
        }

        .emr-container {
            max-width: 1320px;
            margin: 0 auto;
        }

        /* Topbar actions (Notif bell, Owner profile pill) */
        .emr-notif-btn {
            position: relative;
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: #ffffff;
            border: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #64748b;
            font-size: 16px;
            cursor: pointer;
            box-shadow: 0 2px 6px rgba(0,0,0,0.03);
            transition: all 0.2s;
        }
        .emr-notif-btn:hover {
            color: var(--primary);
            border-color: #bfdbfe;
        }
        .emr-notif-badge {
            position: absolute;
            top: 2px;
            right: 2px;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: #ef4444;
            color: white;
            font-size: 10px;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #ffffff;
        }

        .emr-user-pill {
            display: flex;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            padding: 6px 14px;
            border-radius: 40px;
            background: #ffffff;
            border: 1px solid var(--border-color);
            box-shadow: 0 2px 6px rgba(0,0,0,0.03);
        }
        .emr-user-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
        }
        .emr-user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .emr-user-info {
            display: flex;
            flex-direction: column;
            text-align: left;
        }
        .emr-user-name {
            font-size: 13px;
            font-weight: 700;
            color: var(--text-dark);
            line-height: 1.2;
        }
        .emr-user-role {
            font-size: 11px;
            color: var(--text-subtle);
            font-weight: 500;
        }

        /* Pet Switcher Bar */
        .pet-switcher-bar {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 12px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 12px;
        }
        .pet-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 16px;
            border-radius: 50px;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            transition: all 0.2s;
            border: 1px solid transparent;
        }
        .pet-pill.active {
            background: #e8f1fd;
            color: var(--primary);
            border-color: #bfdbfe;
        }
        .pet-pill:not(.active) {
            background: #f8fafc;
            color: #64748b;
            border-color: #e2e8f0;
        }
        .pet-pill:not(.active):hover {
            background: #f1f5f9;
            color: var(--text-dark);
        }

        /* Two-Column Grid */
        .emr-grid {
            display: grid;
            grid-template-columns: 1fr 340px;
            gap: 24px;
            align-items: start;
        }

        /* Common Cards */
        .emr-card {
            background: #ffffff;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-color);
            box-shadow: 0 4px 18px rgba(15, 23, 42, 0.03);
            margin-bottom: 24px;
        }

        /* Pet Header Card */
        .emr-pet-header-card {
            padding: 28px 32px;
        }
        .pet-header-inner {
            display: flex;
            align-items: center;
            gap: 24px;
        }
        .pet-avatar-large {
            width: 105px;
            height: 105px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid #ffffff;
            box-shadow: 0 6px 16px rgba(0,0,0,0.08);
            flex-shrink: 0;
        }
        .pet-avatar-placeholder-large {
            width: 105px;
            height: 105px;
            border-radius: 50%;
            background: linear-gradient(135deg, #bfdbfe, #93c5fd);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #1d4ed8;
            font-size: 38px;
            font-weight: 800;
            border: 4px solid #ffffff;
            box-shadow: 0 6px 16px rgba(0,0,0,0.08);
            flex-shrink: 0;
        }
        .pet-header-info {
            flex: 1;
            min-width: 0;
        }
        .pet-title-row {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 10px;
        }
        .pet-title-row h1 {
            font-size: 26px;
            font-weight: 800;
            color: var(--text-dark);
            margin: 0;
            letter-spacing: -0.4px;
        }
        .badge-active {
            background: #e8f1fd;
            color: var(--primary);
            font-size: 12px;
            font-weight: 700;
            padding: 3px 12px;
            border-radius: 50px;
            border: 1px solid #bfdbfe;
        }
        .pet-details-row {
            display: flex;
            align-items: center;
            gap: 18px;
            flex-wrap: wrap;
            margin-bottom: 12px;
            font-size: 13px;
            color: #475569;
            font-weight: 600;
        }
        .pet-details-row .item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .pet-details-row i {
            color: var(--text-subtle);
            font-size: 13px;
        }
        .pet-details-row .divider {
            color: #cbd5e1;
            font-weight: 300;
        }
        .pet-owner-row {
            display: flex;
            align-items: center;
            gap: 24px;
            flex-wrap: wrap;
            font-size: 13px;
            color: #64748b;
            font-weight: 500;
        }
        .pet-owner-row .owner-item {
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .pet-owner-row i {
            color: var(--primary);
            font-size: 13px;
        }

        /* Tabs Nav */
        .emr-tabs-nav {
            display: flex;
            align-items: center;
            gap: 8px;
            border-bottom: 1px solid var(--border-color);
            padding: 0 24px;
            overflow-x: auto;
            background: #ffffff;
            border-top-left-radius: var(--radius-lg);
            border-top-right-radius: var(--radius-lg);
        }
        .emr-tab-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 16px 18px;
            font-size: 13px;
            font-weight: 600;
            color: #64748b;
            text-decoration: none;
            border-bottom: 3px solid transparent;
            transition: all 0.2s ease;
            white-space: nowrap;
            background: none;
            border-top: none;
            border-left: none;
            border-right: none;
            cursor: pointer;
        }
        .emr-tab-button i {
            font-size: 14px;
        }
        .emr-tab-button:hover {
            color: var(--primary);
        }
        .emr-tab-button.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
            font-weight: 700;
        }
        .emr-tab-body {
            padding: 28px;
        }

        /* Section Title Row inside Tab */
        .section-header-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }
        .section-header-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .section-icon-box {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: #e8f1fd;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }
        .section-title-wrap h3 {
            font-size: 17px;
            font-weight: 800;
            color: var(--text-dark);
            margin: 0;
            letter-spacing: -0.2px;
        }
        .section-title-wrap p {
            font-size: 13px;
            color: var(--text-muted);
            margin: 2px 0 0;
        }

        .filter-dropdown-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            color: #475569;
            cursor: pointer;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            transition: all 0.2s;
        }
        .filter-dropdown-btn:hover {
            border-color: #cbd5e1;
            color: var(--text-dark);
        }

        /* Timeline Items */
        .timeline-container {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }
        .timeline-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 20px 24px;
            display: flex;
            align-items: center;
            gap: 20px;
            transition: all 0.2s ease;
        }
        .timeline-card:hover {
            border-color: #cbd5e1;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.04);
        }
        .timeline-date-col {
            min-width: 105px;
            text-align: left;
            flex-shrink: 0;
        }
        .timeline-date-col .t-date {
            font-size: 13px;
            font-weight: 700;
            color: var(--text-dark);
        }
        .timeline-date-col .t-time {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .timeline-icon-circle {
            width: 46px;
            height: 46px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
        }
        .circle-checkup { background: #e0f2fe; color: #0284c7; }
        .circle-diagnosis { background: #f3e8ff; color: #9333ea; }
        .circle-treatment { background: #fef3c7; color: #d97706; }
        .circle-followup { background: #ccfbf1; color: #0d9488; }
        .circle-surgery { background: #fee2e2; color: #dc2626; }
        .circle-default { background: #f1f5f9; color: #475569; }

        .timeline-content-col {
            flex: 1;
            min-width: 0;
        }
        .timeline-content-col .t-title {
            font-size: 15px;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 3px;
        }
        .timeline-content-col .t-vet {
            font-size: 13px;
            color: var(--text-muted);
            margin-bottom: 2px;
        }
        .timeline-content-col .t-desc {
            font-size: 13px;
            color: #475569;
            line-height: 1.5;
        }
        .timeline-extra-details {
            display: none;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px dashed var(--border-color);
            font-size: 13px;
            color: #64748b;
        }

        .timeline-action-col {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-shrink: 0;
        }
        .badge-completed {
            background: #ecfdf5;
            color: #059669;
            font-size: 12px;
            font-weight: 700;
            padding: 4px 14px;
            border-radius: 50px;
            border: 1px solid #a7f3d0;
        }
        .t-expand-btn {
            width: 28px;
            height: 28px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background: #ffffff;
            color: #94a3b8;
            font-size: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
        }
        .t-expand-btn:hover {
            color: var(--primary);
            border-color: #bfdbfe;
        }

        /* Vaccination Section */
        .emr-vax-card {
            padding: 28px;
        }
        .emr-vax-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
        }
        .emr-vax-table th {
            text-align: left;
            font-size: 12px;
            font-weight: 700;
            color: var(--text-muted);
            padding: 12px 18px;
            border-bottom: 1px solid var(--border-color);
        }
        .emr-vax-table td {
            padding: 16px 18px;
            font-size: 13px;
            color: var(--text-dark);
            font-weight: 600;
            border-bottom: 1px solid #f8fafc;
        }
        .badge-uptodate {
            background: #ecfdf5;
            color: #059669;
            font-size: 12px;
            font-weight: 700;
            padding: 4px 14px;
            border-radius: 50px;
            border: 1px solid #a7f3d0;
            display: inline-block;
        }
        .btn-view-all {
            display: inline-flex;
            align-items: center;
            padding: 7px 18px;
            border: 1px solid #bfdbfe;
            border-radius: 50px;
            color: var(--primary);
            background: #ffffff;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            transition: all 0.2s;
        }
        .btn-view-all:hover {
            background: #e8f1fd;
            color: var(--primary-hover);
        }

        /* Right Column Cards */
        .card-next-appt {
            padding: 24px;
        }
        .next-appt-top {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 20px;
        }
        .next-appt-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: #e8f1fd;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }
        .next-appt-details h6 {
            font-size: 11px;
            font-weight: 800;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 4px;
        }
        .next-appt-details .appt-datetime {
            font-size: 15px;
            font-weight: 800;
            color: var(--text-dark);
            line-height: 1.3;
        }
        .next-appt-details .appt-service {
            font-size: 13px;
            color: var(--text-muted);
            margin-top: 3px;
        }
        .btn-view-appt {
            display: block;
            width: 100%;
            padding: 13px;
            background: var(--primary);
            color: #ffffff;
            text-align: center;
            border-radius: 12px;
            font-weight: 700;
            font-size: 14px;
            text-decoration: none;
            box-shadow: 0 4px 14px rgba(29, 97, 216, 0.25);
            transition: all 0.2s;
            border: none;
        }
        .btn-view-appt:hover {
            background: var(--primary-hover);
            color: #ffffff;
            transform: translateY(-1px);
        }

        /* Pet Profile Card */
        .card-pet-profile {
            padding: 24px;
        }
        .sidebar-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 18px;
        }
        .sidebar-card-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 15px;
            font-weight: 800;
            color: var(--text-dark);
        }
        .sidebar-card-title i {
            color: var(--primary);
            font-size: 15px;
        }
        .sidebar-edit-link {
            font-size: 13px;
            font-weight: 700;
            color: var(--primary);
            text-decoration: none;
            cursor: pointer;
        }
        .sidebar-edit-link:hover {
            text-decoration: underline;
        }
        .profile-pet-flex {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 18px;
        }
        .profile-pet-img {
            width: 72px;
            height: 72px;
            border-radius: 16px;
            object-fit: cover;
            border: 2px solid var(--border-color);
            flex-shrink: 0;
        }
        .profile-pet-placeholder {
            width: 72px;
            height: 72px;
            border-radius: 16px;
            background: #e8f1fd;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            font-weight: 800;
            flex-shrink: 0;
        }
        .profile-pet-meta h5 {
            font-size: 15px;
            font-weight: 800;
            color: var(--text-dark);
            margin: 0 0 2px;
        }
        .profile-pet-meta p {
            font-size: 12px;
            color: var(--text-muted);
            margin: 0;
            line-height: 1.45;
        }

        .profile-owner-box {
            background: #f8fafc;
            border-radius: 12px;
            padding: 14px 16px;
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .owner-avatar-sm {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #64748b;
            font-size: 15px;
            flex-shrink: 0;
            overflow: hidden;
        }
        .owner-avatar-sm img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .owner-meta-sm .label {
            font-size: 11px;
            color: var(--text-subtle);
            font-weight: 600;
        }
        .owner-meta-sm .name {
            font-size: 13px;
            font-weight: 700;
            color: var(--text-dark);
        }
        .owner-meta-sm .phone {
            font-size: 12px;
            color: var(--text-muted);
        }

        /* Important Notes */
        .card-important-notes {
            padding: 24px;
        }
        .notes-list {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        .note-row {
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }
        .note-icon-wrap {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
            margin-top: 2px;
        }
        .note-icon-wrap.allergies {
            background: #fee2e2;
            color: #ef4444;
        }
        .note-icon-wrap.instructions {
            background: #e0f2fe;
            color: #0284c7;
        }
        .note-content h6 {
            font-size: 13px;
            font-weight: 700;
            color: var(--text-dark);
            margin: 0 0 2px;
        }
        .note-content p {
            font-size: 12px;
            color: #64748b;
            margin: 0;
            line-height: 1.4;
        }

        /* Quick Actions */
        .card-quick-actions {
            padding: 24px;
        }
        .quick-actions-btns {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .btn-action-outline {
            display: flex;
            align-items: center;
            gap: 12px;
            width: 100%;
            padding: 12px 18px;
            background: #f0f6ff;
            border: 1px solid #bfdbfe;
            border-radius: 12px;
            color: #1d4ed8;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            transition: all 0.2s ease;
            cursor: pointer;
        }
        .btn-action-outline i {
            font-size: 15px;
            width: 18px;
            text-align: center;
        }
        .btn-action-outline:hover {
            background: #dbeafe;
            color: #1e40af;
            transform: translateY(-1px);
        }

        /* Modals */
        .modal-content {
            border-radius: 20px;
            border: none;
            box-shadow: 0 20px 40px rgba(0,0,0,0.12);
        }
        .modal-header {
            border-bottom: 1px solid var(--border-color);
            padding: 20px 24px;
        }
        .modal-body {
            padding: 24px;
        }
        .form-control, .form-select {
            border-radius: 12px;
            padding: 12px 16px;
            font-size: 14px;
            border: 1px solid #cbd5e1;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.15);
        }

        /* Alert Toast */
        .alert-toast {
            position: fixed;
            top: 24px;
            right: 40px;
            z-index: 9999;
            min-width: 320px;
            animation: slideDown 0.4s ease-out;
        }
        @keyframes slideDown {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        @media (max-width: 1100px) {
            .emr-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 992px) {
            .sidebar {
                display: none;
            }
            .main-content {
                margin-left: 0;
                padding: 24px 16px 40px;
            }
            .pet-header-inner {
                flex-direction: column;
                text-align: center;
            }
            .pet-title-row, .pet-details-row, .pet-owner-row {
                justify-content: center;
            }
            .timeline-card {
                flex-direction: column;
                align-items: flex-start;
            }
            .timeline-action-col {
                width: 100%;
                justify-content: space-between;
                margin-top: 10px;
            }
        }
    
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
</head>
<body>

<!-- Alert Feedback -->
<?php if(isset($_SESSION['message'])): ?>
<div class="alert-toast">
    <div class="alert alert-<?php echo $_SESSION['msg_type']; ?> alert-dismissible fade show shadow-sm" role="alert" style="border-radius: 14px;">
        <strong><?php echo $_SESSION['msg_type'] == 'success' ? '<i class="fa fa-check-circle me-1"></i>' : '<i class="fa fa-exclamation-circle me-1"></i>'; ?></strong>
        <?php echo $_SESSION['message']; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
</div>
<?php unset($_SESSION['message']); unset($_SESSION['msg_type']); endif; ?>

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
        <a href="pets.php" class="active"><i class="fa fa-notes-medical"></i> <span>Profile & Pets</span></a>
        <a href="billing.php"><i class="fa fa-file-invoice-dollar"></i> <span>Billing</span></a>
        <a href="settings.php"><i class="fa fa-cog"></i> <span>Account Settings</span></a>
        <div class="sidebar-logout"><a href="logout.php"><i class="fa fa-sign-out-alt"></i> <span>Logout</span></a></div>
    </div>

<!-- ====== MAIN CONTENT ====== -->
<div class="main-content">
    <div class="emr-container">
        
        <!-- Page Header -->
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <h2 class="fw-bold mb-1" style="color: #0f172a; font-size: 26px; letter-spacing: -0.4px;">Profile & Records</h2>
                <p class="text-muted mb-0" style="font-size: 14px;">Manage your owner account and view pet medical logs (EMR)</p>
            </div>
            
            <div class="d-flex align-items-center gap-3">
                <div class="emr-notif-btn" title="Notifications">
                    <i class="fa fa-bell"></i>
                    <span class="emr-notif-badge">2</span>
                </div>

                <div class="emr-user-pill" role="link" tabindex="0" onclick="location.href='settings.php'" onkeydown="if(event.key==='Enter')location.href='settings.php'" title="Edit your profile in Account Settings">
                    <div class="emr-user-avatar">
                        <?php if($owner_pic): ?>
                            <img src="<?php echo htmlspecialchars($owner_pic); ?>" alt="Owner">
                        <?php else: ?>
                            <i class="fa fa-user text-secondary"></i>
                        <?php endif; ?>
                    </div>
                    <div class="emr-user-info">
                        <span class="emr-user-name"><?php echo htmlspecialchars($owner_name); ?></span>
                        <span class="emr-user-role">Pet Owner</span>
                    </div>
                    <i class="fa fa-chevron-down" style="font-size: 10px; color: #94a3b8; margin-left: 4px;"></i>
                </div>
            </div>
        </div>

        <!-- Pet Switcher Bar -->
        <div class="pet-switcher-bar">
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <span class="text-muted small fw-bold text-uppercase me-1" style="letter-spacing:0.05em; font-size:11px;">Select Pet:</span>
                <?php if(!empty($all_user_pets)): ?>
                    <?php foreach($all_user_pets as $up): ?>
                        <a href="pets.php?pet_id=<?php echo $up['id']; ?>" class="pet-pill <?php echo $up['id'] == $pet_id ? 'active' : ''; ?>">
                            <?php if(!empty($up['pet_picture']) && file_exists('uploads/pets/'.$up['pet_picture'])): ?>
                                <img src="uploads/pets/<?php echo htmlspecialchars($up['pet_picture']); ?>" style="width:20px;height:20px;border-radius:50%;object-fit:cover;">
                            <?php else: ?>
                                <i class="fa fa-paw" style="font-size:11px;"></i>
                            <?php endif; ?>
                            <span><?php echo htmlspecialchars($up['name']); ?></span>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <button class="btn btn-sm btn-primary rounded-pill px-3 py-2 fw-bold d-inline-flex align-items-center gap-2 shadow-sm" data-bs-toggle="modal" data-bs-target="#addPetModal">
                <i class="fa fa-plus"></i> Add New Pet
            </button>
        </div>

        <!-- Main Layout Grid -->
        <div class="emr-grid">
            
            <?php if (empty($all_user_pets)): ?>
            <!-- ── EMPTY STATE: No pets yet ─────────────────────────────── -->
            <div class="col-12" style="grid-column: 1 / -1;">
                <div class="emr-card text-center py-5">
                    <div style="width:80px;height:80px;border-radius:50%;background:linear-gradient(135deg,#0E5A6B,#1E7FA8);display:flex;align-items:center;justify-content:center;margin:0 auto 20px;font-size:32px;color:#fff;">
                        <i class="fa fa-paw"></i>
                    </div>
                    <h4 class="fw-bold text-dark mb-2">No Pets Added Yet</h4>
                    <p class="text-muted mb-4" style="font-size:14px;max-width:400px;margin-left:auto;margin-right:auto;">
                        Add your first pet to start managing their medical records, vaccination history, and more.
                    </p>
                    <button class="btn btn-primary rounded-pill px-4 py-2 fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#addPetModal">
                        <i class="fa fa-plus me-2"></i> Add Your First Pet
                    </button>
                </div>
            </div>
            <?php else: ?>
            <!-- LEFT COLUMN (Main Records Area) -->
            <div class="emr-left-col">
                
                <!-- Pet Header Card -->
                <div class="emr-card emr-pet-header-card">
                    <div class="pet-header-inner">
                        <?php if($pet_pic_url): ?>
                            <img src="<?php echo htmlspecialchars($pet_pic_url); ?>" alt="<?php echo htmlspecialchars($pet_name); ?>" class="pet-avatar-large">
                        <?php else: ?>
                            <div class="pet-avatar-placeholder-large">
                                <?php echo strtoupper(substr($pet_name, 0, 1)); ?>
                            </div>
                        <?php endif; ?>

                        <div class="pet-header-info">
                            <div class="pet-title-row d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <div class="d-flex align-items-center gap-2">
                                    <h1><?php echo htmlspecialchars($pet_name); ?></h1>
                                    <span class="badge-active">Active</span>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <a href="download_emr.php?pet_id=<?php echo $pet_id; ?>&preview=1" target="_blank" class="btn btn-sm btn-outline-secondary rounded-pill px-3 fw-semibold">
                                        <i class="fa fa-eye me-1"></i> Preview EMR
                                    </a>
                                    <a href="download_emr.php?pet_id=<?php echo $pet_id; ?>" class="btn btn-sm btn-primary rounded-pill px-3 fw-bold shadow-sm">
                                        <i class="fa fa-download me-1"></i> Download EMR (PDF)
                                    </a>
                                    <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3 fw-semibold" data-bs-toggle="modal" data-bs-target="#requestCertModal">
                                        <i class="fa fa-file-contract me-1"></i> Request Certificate
                                    </button>
                                </div>
                            </div>

                            <div class="pet-details-row">
                                <span class="item"><i class="fa fa-dog"></i> <?php echo htmlspecialchars($pet_species ?: 'Species not specified'); ?></span>
                                <span class="item"><i class="fa fa-tag"></i> <?php echo htmlspecialchars($pet_breed ?: 'Breed not specified'); ?></span>
                                <span class="item">
                                    <i class="fa fa-<?php echo strtolower($pet_gender) === 'male' ? 'mars' : (strtolower($pet_gender) === 'female' ? 'venus' : 'genderless'); ?>"></i> 
                                    <?php echo htmlspecialchars($pet_gender ?: 'Gender not specified'); ?>
                                </span>
                                <span class="divider">|</span>
                                <span class="item"><i class="fa fa-calendar-alt"></i> <?php echo htmlspecialchars($pet_age_display); ?></span>
                            </div>

                            <div class="pet-owner-row">
                                <span class="owner-item"><i class="fa fa-user"></i> Owner: <strong><?php echo htmlspecialchars($owner_name); ?></strong></span>
                                <span class="owner-item"><i class="fa fa-phone"></i> <?php echo htmlspecialchars($owner_phone); ?></span>
                                <span class="owner-item"><i class="fa fa-map-marker-alt"></i> <?php echo htmlspecialchars($owner_address); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tabs Container -->
                <div class="emr-card" style="padding: 0; overflow: hidden;">
                    
                    <!-- Tabs Navigation Bar -->
                    <div class="emr-tabs-nav">
                        <button class="emr-tab-button <?php echo $active_tab === 'overview' ? 'active' : ''; ?>" onclick="switchTab('overview')">
                            <i class="fa fa-home"></i> Overview
                        </button>
                        <button class="emr-tab-button <?php echo $active_tab === 'medical_history' ? 'active' : ''; ?>" onclick="switchTab('medical_history')">
                            <i class="fa fa-file-alt"></i> Medical History
                        </button>
                        <button class="emr-tab-button <?php echo $active_tab === 'vaccinations' ? 'active' : ''; ?>" onclick="switchTab('vaccinations')">
                            <i class="fa fa-syringe"></i> Vaccinations
                        </button>
                        <button class="emr-tab-button <?php echo $active_tab === 'consultations' ? 'active' : ''; ?>" onclick="switchTab('consultations')">
                            <i class="fa fa-stethoscope"></i> Consultations
                        </button>
                        <button class="emr-tab-button <?php echo $active_tab === 'lab_results' ? 'active' : ''; ?>" onclick="switchTab('lab_results')">
                            <i class="fa fa-flask"></i> Lab Results
                        </button>
                        <button class="emr-tab-button <?php echo $active_tab === 'prescriptions' ? 'active' : ''; ?>" onclick="switchTab('prescriptions')">
                            <i class="fa fa-prescription-bottle-alt"></i> Prescriptions
                        </button>
                    </div>

                    <!-- TAB 1: OVERVIEW -->
                    <div id="tab-overview" class="emr-tab-body" style="<?php echo $active_tab === 'overview' ? 'display:block;' : 'display:none;'; ?>">
                        <div class="section-header-row">
                            <div class="section-header-left">
                                <div class="section-icon-box"><i class="fa fa-chart-pie"></i></div>
                                <div class="section-title-wrap">
                                    <h3>Pet Health Overview</h3>
                                    <p>Comprehensive health records summary for <?php echo htmlspecialchars($pet_name); ?>.</p>
                                </div>
                            </div>
                        </div>

                        <div class="row g-3 mb-4">
                            <div class="col-sm-6 col-md-3">
                                <div style="background:#e8f1fd; border-radius:14px; padding:20px; text-align:center;">
                                    <div style="font-size:26px; font-weight:800; color:#1d4ed8;"><?php echo count($medical_records); ?></div>
                                    <div style="font-size:12px; font-weight:700; color:#64748b; margin-top:4px;">Medical Records</div>
                                </div>
                            </div>
                            <div class="col-sm-6 col-md-3">
                                <div style="background:#ecfdf5; border-radius:14px; padding:20px; text-align:center;">
                                    <div style="font-size:26px; font-weight:800; color:#059669;"><?php echo count($vaccination_records); ?></div>
                                    <div style="font-size:12px; font-weight:700; color:#64748b; margin-top:4px;">Vaccinations</div>
                                </div>
                            </div>
                            <div class="col-sm-6 col-md-3">
                                <div style="background:#fef3c7; border-radius:14px; padding:20px; text-align:center;">
                                    <div style="font-size:26px; font-weight:800; color:#d97706;"><?php echo count($consultations); ?></div>
                                    <div style="font-size:12px; font-weight:700; color:#64748b; margin-top:4px;">Consultations</div>
                                </div>
                            </div>
                            <div class="col-sm-6 col-md-3">
                                <div style="background:#f3e8ff; border-radius:14px; padding:20px; text-align:center;">
                                    <div style="font-size:26px; font-weight:800; color:#7c3aed;"><?php echo count($prescriptions); ?></div>
                                    <div style="font-size:12px; font-weight:700; color:#64748b; margin-top:4px;">Prescriptions</div>
                                </div>
                            </div>
                        </div>

                        <h6 style="font-weight:700; color:var(--text-dark); margin-bottom:14px;">Recent Medical Timeline</h6>
                        <div class="timeline-container">
                            <?php if (empty($medical_records)): ?>
                                <div class="text-muted small py-3"><i class="fa fa-circle-info me-1"></i>No medical records yet. They appear here after <?php echo htmlspecialchars($pet_name); ?>'s visits.</div>
                            <?php endif; ?>
                            <?php foreach(array_slice($medical_records, 0, 3) as $rec): ?>
                            <div class="timeline-card">
                                <div class="timeline-date-col">
                                    <div class="t-date"><?php echo date('M d, Y', strtotime($rec['record_date'])); ?></div>
                                    <div class="t-time"><?php echo !empty($rec['record_time']) ? date('h:i A', strtotime($rec['record_time'])) : '10:00 AM'; ?></div>
                                </div>
                                <div class="timeline-icon-circle circle-checkup">
                                    <i class="fa fa-stethoscope"></i>
                                </div>
                                <div class="timeline-content-col">
                                    <div class="t-title"><?php echo htmlspecialchars($rec['title']); ?></div>
                                    <?php if(!empty($rec['doctor_name'])): ?>
                                        <div class="t-vet">Veterinarian: <?php echo htmlspecialchars($rec['doctor_name']); ?></div>
                                    <?php endif; ?>
                                    <div class="t-desc"><?php echo htmlspecialchars($rec['description']); ?></div>
                                </div>
                                <div class="timeline-action-col">
                                    <span class="badge-completed">Completed</span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- TAB 2: MEDICAL HISTORY (Default Active) -->
                    <div id="tab-medical_history" class="emr-tab-body" style="<?php echo $active_tab === 'medical_history' ? 'display:block;' : 'display:none;'; ?>">
                        <div class="section-header-row">
                            <div class="section-header-left">
                                <div class="section-icon-box"><i class="fa fa-file-alt"></i></div>
                                <div class="section-title-wrap">
                                    <h3>Medical History</h3>
                                    <p>Past illnesses, conditions, and treatments of your pet.</p>
                                </div>
                            </div>
                            
                            <div class="dropdown">
                                <button class="filter-dropdown-btn dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                    <i class="fa fa-calendar-alt"></i> Filter by date
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0" style="border-radius:12px; font-size:13px;">
                                    <li><a class="dropdown-item" href="javascript:void(0)" onclick="filterTimeline('all')">All Records</a></li>
                                    <li><a class="dropdown-item" href="javascript:void(0)" onclick="filterTimeline('checkup')">General Check-ups</a></li>
                                    <li><a class="dropdown-item" href="javascript:void(0)" onclick="filterTimeline('diagnosis')">Diagnoses</a></li>
                                    <li><a class="dropdown-item" href="javascript:void(0)" onclick="filterTimeline('treatment')">Treatments</a></li>
                                    <li><a class="dropdown-item" href="javascript:void(0)" onclick="filterTimeline('follow_up')">Follow-ups</a></li>
                                </ul>
                            </div>
                        </div>

                        <!-- Timeline Entries -->
                        <div class="timeline-container" id="timelineList">
                            <?php if (empty($medical_records)): ?>
                                <div class="text-muted small py-3"><i class="fa fa-circle-info me-1"></i>No medical records yet. They appear here after <?php echo htmlspecialchars($pet_name); ?>'s visits.</div>
                            <?php endif; ?>
                            <?php foreach($medical_records as $idx => $record): 
                                $type = $record['record_type'] ?? 'checkup';
                                $circle_class = 'circle-checkup';
                                $icon_class = 'fa-stethoscope';

                                if($type === 'diagnosis') {
                                    $circle_class = 'circle-diagnosis';
                                    $icon_class = 'fa-clipboard-check';
                                } elseif($type === 'treatment') {
                                    $circle_class = 'circle-treatment';
                                    $icon_class = 'fa-capsules';
                                } elseif($type === 'follow_up') {
                                    $circle_class = 'circle-followup';
                                    $icon_class = 'fa-calendar-check';
                                } elseif($type === 'surgery') {
                                    $circle_class = 'circle-surgery';
                                    $icon_class = 'fa-heartbeat';
                                }
                            ?>
                            <div class="timeline-card timeline-item" data-type="<?php echo htmlspecialchars($type); ?>" id="record-card-<?php echo $idx; ?>">
                                <div class="timeline-date-col">
                                    <div class="t-date"><?php echo date('M d, Y', strtotime($record['record_date'])); ?></div>
                                    <div class="t-time"><?php echo !empty($record['record_time']) ? date('h:i A', strtotime($record['record_time'])) : '10:00 AM'; ?></div>
                                </div>

                                <div class="timeline-icon-circle <?php echo $circle_class; ?>">
                                    <i class="fa <?php echo $icon_class; ?>"></i>
                                </div>

                                <div class="timeline-content-col">
                                    <div class="t-title"><?php echo htmlspecialchars($record['title']); ?></div>
                                    <?php if(!empty($record['doctor_name'])): ?>
                                        <div class="t-vet">Veterinarian: <?php echo htmlspecialchars($record['doctor_name']); ?></div>
                                    <?php endif; ?>
                                    <div class="t-desc"><?php echo htmlspecialchars($record['description']); ?></div>
                                    
                                    <?php if(!empty($record['notes'])): ?>
                                    <div class="timeline-extra-details" id="extra-<?php echo $idx; ?>">
                                        <i class="fa fa-info-circle text-primary me-1"></i> <?php echo htmlspecialchars($record['notes']); ?>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <div class="timeline-action-col">
                                    <span class="badge-completed"><?php echo htmlspecialchars($record['status'] ?? 'Completed'); ?></span>
                                    <button class="t-expand-btn" onclick="toggleDetails(<?php echo $idx; ?>)" title="View more details">
                                        <i class="fa fa-chevron-down" id="chevron-<?php echo $idx; ?>"></i>
                                    </button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- TAB 3: VACCINATIONS -->
                    <div id="tab-vaccinations" class="emr-tab-body" style="<?php echo $active_tab === 'vaccinations' ? 'display:block;' : 'display:none;'; ?>">
                        <div class="section-header-row">
                            <div class="section-header-left">
                                <div class="section-icon-box" style="background:#ccfbf1; color:#0d9488;"><i class="fa fa-shield-alt"></i></div>
                                <div class="section-title-wrap">
                                    <h3>Vaccination Records</h3>
                                    <p>Immunization schedule and history for <?php echo htmlspecialchars($pet_name); ?>.</p>
                                </div>
                            </div>
                        </div>

                        <table class="emr-vax-table">
                            <thead>
                                <tr>
                                    <th>Vaccine</th>
                                    <th>Date Given</th>
                                    <th>Next Due</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($vaccination_records)): ?>
                                <tr><td colspan="4" class="text-center text-muted small py-3">No vaccination records yet.</td></tr>
                                <?php endif; ?>
                                <?php foreach($vaccination_records as $vax): ?>
                                <tr>
                                    <td><i class="fa fa-syringe text-primary me-2"></i> <?php echo htmlspecialchars($vax['vaccine_name']); ?></td>
                                    <td><?php echo !empty($vax['date_given']) ? date('M d, Y', strtotime($vax['date_given'])) : '—'; ?></td>
                                    <td><?php echo !empty($vax['next_due']) ? date('M d, Y', strtotime($vax['next_due'])) : '—'; ?></td>
                                    <td><span class="badge-uptodate"><?php echo htmlspecialchars($vax['status'] ?? 'Up to date'); ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- TAB 4: CONSULTATIONS -->
                    <div id="tab-consultations" class="emr-tab-body" style="<?php echo $active_tab === 'consultations' ? 'display:block;' : 'display:none;'; ?>">
                        <div class="section-header-row">
                            <div class="section-header-left">
                                <div class="section-icon-box" style="background:#fef3c7; color:#d97706;"><i class="fa fa-stethoscope"></i></div>
                                <div class="section-title-wrap">
                                    <h3>Clinical Consultations</h3>
                                    <p>Notes and examination findings from attending veterinarians.</p>
                                </div>
                            </div>
                        </div>

                        <?php if(!empty($consultations)): ?>
                            <?php foreach($consultations as $c): ?>
                            <div style="background:#f8fafc; border-radius:14px; border:1px solid var(--border-color); padding:20px; margin-bottom:14px;">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <div>
                                        <h6 class="fw-bold mb-1"><?php echo htmlspecialchars($c['service_type'] ?? 'Consultation'); ?></h6>
                                        <span class="text-muted small"><i class="fa fa-user-md text-primary me-1"></i>Dr. <?php echo htmlspecialchars($c['vet_name']); ?></span>
                                    </div>
                                    <span class="badge bg-light text-secondary border px-3 py-2 rounded-pill font-monospace">
                                        <?php echo date('M d, Y', strtotime($c['created_at'])); ?>
                                    </span>
                                </div>
                                <?php if(!empty($c['diagnosis'])): ?>
                                <div class="mb-2">
                                    <span class="badge bg-primary bg-opacity-10 text-primary mb-1">DIAGNOSIS</span>
                                    <p class="small text-secondary mb-0"><?php echo nl2br(htmlspecialchars($c['diagnosis'])); ?></p>
                                </div>
                                <?php endif; ?>
                                <?php if(!empty($c['treatment'])): ?>
                                <div class="mb-2">
                                    <span class="badge bg-success bg-opacity-10 text-success mb-1">TREATMENT PLAN</span>
                                    <p class="small text-secondary mb-0"><?php echo nl2br(htmlspecialchars($c['treatment'])); ?></p>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="background:#f8fafc; border-radius:14px; border:1px solid var(--border-color); padding:20px;">
                                <p class="small text-muted mb-0"><i class="fa fa-circle-info me-1"></i>No consultation notes yet. Notes from the attending veterinarian appear here after a visit.</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- TAB 5: LAB RESULTS -->
                    <div id="tab-lab_results" class="emr-tab-body" style="<?php echo $active_tab === 'lab_results' ? 'display:block;' : 'display:none;'; ?>">
                        <div class="section-header-row">
                            <div class="section-header-left">
                                <div class="section-icon-box" style="background:#f3e8ff; color:#9333ea;"><i class="fa fa-flask"></i></div>
                                <div class="section-title-wrap">
                                    <h3>Laboratory & Diagnostics</h3>
                                    <p>Official diagnostic laboratory reports and scans.</p>
                                </div>
                            </div>
                        </div>

                        <?php if(!empty($lab_results)): ?>
                            <?php foreach($lab_results as $lab): ?>
                            <div class="d-flex align-items-center justify-content-between p-3 border rounded-3 mb-2 bg-light">
                                <div class="d-flex align-items-center gap-3">
                                    <i class="fa fa-file-pdf text-danger fs-3"></i>
                                    <div>
                                        <div class="fw-bold"><?php echo htmlspecialchars($lab['service_type'] ?? 'Diagnostic Report'); ?></div>
                                        <div class="text-muted small">Date: <?php echo date('M d, Y', strtotime($lab['created_at'])); ?></div>
                                    </div>
                                </div>
                                <a href="uploads/labs/<?php echo htmlspecialchars($lab['lab_results_url']); ?>" target="_blank" class="btn btn-sm btn-primary rounded-pill px-3">
                                    <i class="fa fa-download me-1"></i> Download Report
                                </a>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="p-4 text-center border rounded-3 bg-light">
                                <i class="fa fa-clipboard-check text-muted fs-2 mb-2"></i>
                                <h6 class="fw-bold text-dark">No Lab Results Yet</h6>
                                <p class="text-muted small mb-0">Laboratory reports uploaded by the veterinarian will appear here.</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- TAB 6: PRESCRIPTIONS -->
                    <div id="tab-prescriptions" class="emr-tab-body" style="<?php echo $active_tab === 'prescriptions' ? 'display:block;' : 'display:none;'; ?>">
                        <div class="section-header-row">
                            <div class="section-header-left">
                                <div class="section-icon-box" style="background:#fee2e2; color:#ef4444;"><i class="fa fa-prescription-bottle-alt"></i></div>
                                <div class="section-title-wrap">
                                    <h3>Prescriptions & Medications</h3>
                                    <p>Current and past active medications authorized by clinic veterinarians.</p>
                                </div>
                            </div>
                        </div>

                        <?php if (empty($prescriptions)): ?>
                            <div class="text-muted small py-3"><i class="fa fa-circle-info me-1"></i>No prescriptions on record.</div>
                        <?php endif; ?>
                        <?php foreach($prescriptions as $rx): ?>
                        <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:14px; padding:18px; margin-bottom:14px;">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="fw-bold text-primary"><i class="fa fa-pills me-2"></i><?php echo htmlspecialchars($rx['service_type'] ?? 'Authorized Prescription'); ?></span>
                                <span class="badge bg-white text-secondary border px-3 py-1 rounded-pill small">
                                    <?php echo date('M d, Y', strtotime($rx['created_at'])); ?>
                                </span>
                            </div>
                            <div class="p-3 bg-white rounded-3 border">
                                <div class="text-primary fw-bold mb-1" style="font-size:14px;"><?php echo nl2br(htmlspecialchars($rx['prescription'])); ?></div>
                                <div class="text-muted small">Prescribed by <?php echo !empty($rx['vet_name']) ? 'Dr. ' . htmlspecialchars($rx['vet_name']) : 'the attending veterinarian'; ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                </div>

                <!-- Vaccination Records Section (Permanent under timeline) -->
                <div class="emr-card emr-vax-card">
                    <div class="section-header-row">
                        <div class="section-header-left">
                            <div class="section-icon-box" style="background:#ccfbf1; color:#0d9488;">
                                <i class="fa fa-shield-alt"></i>
                            </div>
                            <div class="section-title-wrap">
                                <h3>Vaccination Records</h3>
                                <p>Keep your pet protected and up to date.</p>
                            </div>
                        </div>
                        <a href="javascript:void(0)" onclick="switchTab('vaccinations')" class="btn-view-all">View All</a>
                    </div>

                    <table class="emr-vax-table">
                        <thead>
                            <tr>
                                <th>Vaccine</th>
                                <th>Date Given</th>
                                <th>Next Due</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($vaccination_records)): ?>
                            <tr><td colspan="4" class="text-center text-muted small py-3">No vaccination records yet.</td></tr>
                            <?php endif; ?>
                            <?php foreach(array_slice($vaccination_records, 0, 2) as $v): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($v['vaccine_name']); ?></td>
                                <td><?php echo !empty($v['date_given']) ? date('M d, Y', strtotime($v['date_given'])) : '—'; ?></td>
                                <td><?php echo !empty($v['next_due']) ? date('M d, Y', strtotime($v['next_due'])) : '—'; ?></td>
                                <td><span class="badge-uptodate"><?php echo htmlspecialchars($v['status'] ?? 'Up to date'); ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            </div>

            <!-- RIGHT COLUMN (Sidebar Info & Quick Actions) -->
            <div class="emr-right-col">
                
                <!-- Card 1: Next Appointment -->
                <div class="emr-card card-next-appt">
                    <div class="next-appt-top">
                        <div class="next-appt-icon">
                            <i class="fa fa-calendar-alt"></i>
                        </div>
                        <div class="next-appt-details">
                            <h6>Next Appointment</h6>
                            <?php if ($next_appointment): ?>
                            <div class="appt-datetime">
                                <?php echo date('M d, Y', strtotime($next_appointment['appointment_date'])); ?> • <?php echo date('h:i A', strtotime($next_appointment['appointment_time'])); ?>
                            </div>
                            <div class="appt-service"><?php echo htmlspecialchars($next_appointment['service_type']); ?></div>
                            <?php else: ?>
                            <div class="appt-datetime">No upcoming appointment</div>
                            <div class="appt-service">Book a visit for <?php echo htmlspecialchars($pet_name); ?> anytime.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <a href="appointments.php" class="btn-view-appt"><?php echo $next_appointment ? 'View Appointment Details' : 'Book an Appointment'; ?></a>
                </div>

                <!-- Card 2: Pet Profile -->
                <div class="emr-card card-pet-profile">
                    <div class="sidebar-card-header">
                        <span class="sidebar-card-title"><i class="fa fa-paw"></i> Pet Profile</span>
                        <a href="javascript:void(0)" class="sidebar-edit-link" data-bs-toggle="modal" data-bs-target="#editPetProfileModal">Edit</a>
                    </div>

                    <div class="profile-pet-flex">
                        <?php if($pet_pic_url): ?>
                            <img src="<?php echo htmlspecialchars($pet_pic_url); ?>" alt="<?php echo htmlspecialchars($pet_name); ?>" class="profile-pet-img">
                        <?php else: ?>
                            <div class="profile-pet-placeholder"><?php echo strtoupper(substr($pet_name, 0, 1)); ?></div>
                        <?php endif; ?>

                        <div class="profile-pet-meta">
                            <h5><?php echo htmlspecialchars($pet_name); ?></h5>
                            <p><?php echo htmlspecialchars($pet_breed ?: 'Breed not specified'); ?></p>
                            <p><?php echo htmlspecialchars($pet_gender ?: 'Gender not specified'); ?></p>
                            <p><?php echo htmlspecialchars($pet_age_display); ?></p>
                        </div>
                    </div>

                    <div class="profile-owner-box">
                        <div class="owner-avatar-sm">
                            <?php if($owner_pic): ?>
                                <img src="<?php echo htmlspecialchars($owner_pic); ?>" alt="Owner">
                            <?php else: ?>
                                <i class="fa fa-user"></i>
                            <?php endif; ?>
                        </div>
                        <div class="owner-meta-sm">
                            <div class="label">Owner</div>
                            <div class="name"><?php echo htmlspecialchars($owner_name); ?></div>
                            <div class="phone"><?php echo htmlspecialchars($owner_phone); ?></div>
                        </div>
                    </div>
                </div>

                <!-- Card 3: Important Notes -->
                <div class="emr-card card-important-notes">
                    <div class="sidebar-card-header">
                        <span class="sidebar-card-title"><i class="fa fa-lightbulb"></i> Important Notes</span>
                        <a href="javascript:void(0)" class="sidebar-edit-link" data-bs-toggle="modal" data-bs-target="#editNotesModal">Edit</a>
                    </div>

                    <div class="notes-list">
                        <div class="note-row">
                            <div class="note-icon-wrap allergies">
                                <i class="fa fa-exclamation-triangle"></i>
                            </div>
                            <div class="note-content">
                                <h6>Allergies</h6>
                                <p><?php echo htmlspecialchars($pet_notes['allergies'] ?: 'None recorded.'); ?></p>
                            </div>
                        </div>

                        <div class="note-row">
                            <div class="note-icon-wrap instructions">
                                <i class="fa fa-info-circle"></i>
                            </div>
                            <div class="note-content">
                                <h6>Special Instructions</h6>
                                <p><?php echo htmlspecialchars($pet_notes['special_instructions'] ?: 'None recorded.'); ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Card 4: Quick Actions -->
                <div class="emr-card card-quick-actions">
                    <div class="sidebar-card-header mb-3">
                        <span class="sidebar-card-title"><i class="fa fa-bolt"></i> Quick Actions</span>
                    </div>

                    <div class="quick-actions-btns">
                        <a href="download_emr.php?pet_id=<?php echo $pet_id; ?>" class="btn-action-outline text-primary border-primary border-opacity-25" style="background: #eff6ff;" target="_blank">
                            <i class="fa fa-file-pdf text-danger"></i>
                            <span>Download Full EMR (PDF)</span>
                        </a>
                        <a href="appointments.php" class="btn-action-outline">
                            <i class="fa fa-calendar-plus"></i>
                            <span>Book Appointment</span>
                        </a>
                        <button type="button" class="btn-action-outline" data-bs-toggle="modal" data-bs-target="#contactClinicModal">
                            <i class="fa fa-phone"></i>
                            <span>Contact Clinic</span>
                        </button>
                    </div>
                </div>

            </div>

        </div><!-- end emr-grid -->
        <?php endif; // end empty state check ?>
    </div>
</div>

<!-- ====== MODAL: ADD NEW PET ====== -->
<div class="modal fade" id="addPetModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="fw-bold mb-0"><i class="fa fa-paw text-primary me-2"></i> Register New Pet</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add_pet">
                <div class="modal-body">
                    <div class="mb-3 text-center">
                        <label class="form-label d-block text-muted small fw-bold">PET PICTURE (Optional)</label>
                        <input type="file" name="pet_pic" class="form-control" accept="image/*">
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">PET NAME</label>
                        <input type="text" name="name" class="form-control" placeholder="e.g. Max, Coco" required>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted">SPECIES</label>
                            <select name="species" class="form-select" required>
                                <option value="Dog">Dog</option>
                                <option value="Cat">Cat</option>
                                <option value="Bird">Bird</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted">BREED</label>
                            <input type="text" name="breed" class="form-control" placeholder="e.g. Dachshund, Golden Retriever">
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted">GENDER</label>
                            <select name="gender" class="form-select">
                                <option value="Female">Female</option>
                                <option value="Male">Male</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted">WEIGHT (kg)</label>
                            <input type="number" step="0.1" name="weight" class="form-control" placeholder="e.g. 6.0">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">DATE OF BIRTH</label>
                        <input type="date" name="date_of_birth" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                    </div>

                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted">LAST VACCINE</label>
                            <input type="date" name="last_vaccination" class="form-control">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted">NEXT VACCINE</label>
                            <input type="date" name="next_vaccination" class="form-control">
                        </div>
                    </div>
                </div>

                <div class="modal-footer border-0 pt-0 px-4 pb-4">
                    <button type="submit" class="btn btn-primary w-100 py-3 rounded-pill fw-bold">Save Pet Profile</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ====== MODAL: EDIT PET PROFILE ====== -->
<div class="modal fade" id="editPetProfileModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="fw-bold mb-0"><i class="fa fa-paw text-primary me-2"></i> Edit Pet Profile</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="edit_pet_profile">
                <input type="hidden" name="pet_id" value="<?php echo $pet_id; ?>">
                
                <div class="modal-body">
                    <div class="mb-3 text-center">
                        <?php if($pet_pic_url): ?>
                            <img src="<?php echo htmlspecialchars($pet_pic_url); ?>" alt="Preview" style="width:80px;height:80px;border-radius:50%;object-fit:cover;" class="border mb-2">
                        <?php endif; ?>
                        <label class="form-label d-block text-muted small fw-bold">CHANGE PHOTO</label>
                        <input type="file" name="pet_pic" class="form-control" accept="image/*">
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">PET NAME</label>
                        <input type="text" name="name" class="form-control" required value="<?php echo htmlspecialchars($pet_name); ?>">
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted">SPECIES</label>
                            <select name="species" class="form-select" required>
                                <option value="Dog" <?php if(strtolower($pet_species) == 'dog') echo 'selected'; ?>>Dog</option>
                                <option value="Cat" <?php if(strtolower($pet_species) == 'cat') echo 'selected'; ?>>Cat</option>
                                <option value="Bird" <?php if(strtolower($pet_species) == 'bird') echo 'selected'; ?>>Bird</option>
                                <option value="Other" <?php if(!in_array(strtolower($pet_species), ['dog','cat','bird'])) echo 'selected'; ?>>Other</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted">BREED</label>
                            <input type="text" name="breed" class="form-control" value="<?php echo htmlspecialchars($pet_breed); ?>">
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted">GENDER</label>
                            <select name="gender" class="form-select">
                                <?php if ($pet_gender === ''): ?><option value="" selected>Not specified</option><?php endif; ?>
                                <option value="Female" <?php if(strtolower($pet_gender) == 'female') echo 'selected'; ?>>Female</option>
                                <option value="Male" <?php if(strtolower($pet_gender) == 'male') echo 'selected'; ?>>Male</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted">WEIGHT (kg)</label>
                            <input type="number" step="0.1" name="weight" class="form-control" value="<?php echo htmlspecialchars($active_pet['weight'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">DATE OF BIRTH</label>
                        <input type="date" name="date_of_birth" class="form-control" value="<?php echo htmlspecialchars($pet_dob ?? '2023-01-15'); ?>">
                    </div>
                </div>

                <div class="modal-footer border-0 pt-0 px-4 pb-4">
                    <button type="submit" class="btn btn-primary w-100 py-3 rounded-pill fw-bold">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ====== MODAL: EDIT IMPORTANT NOTES ====== -->
<div class="modal fade" id="editNotesModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="fw-bold mb-0"><i class="fa fa-lightbulb text-primary me-2"></i> Edit Important Notes</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="update_notes">
                <input type="hidden" name="pet_id" value="<?php echo $pet_id; ?>">

                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted"><i class="fa fa-exclamation-triangle text-danger me-1"></i> KNOWN ALLERGIES</label>
                        <textarea name="allergies" class="form-control" rows="3" placeholder="e.g. No known allergies, or specific drug/food allergies"><?php echo htmlspecialchars($pet_notes['allergies']); ?></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted"><i class="fa fa-info-circle text-primary me-1"></i> SPECIAL INSTRUCTIONS</label>
                        <textarea name="special_instructions" class="form-control" rows="3" placeholder="e.g. Give medication with food, nervous around other animals"><?php echo htmlspecialchars($pet_notes['special_instructions']); ?></textarea>
                    </div>
                </div>

                <div class="modal-footer border-0 pt-0 px-4 pb-4">
                    <button type="submit" class="btn btn-primary w-100 py-3 rounded-pill fw-bold">Update Notes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ====== MODAL: CONTACT CLINIC ====== -->
<div class="modal fade" id="contactClinicModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="fw-bold mb-0"><i class="fa fa-phone text-primary me-2"></i> Contact PragmaVet Clinic</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center py-4">
                <div style="width:60px;height:60px;background:#e8f1fd;color:#1d61d8;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:24px;">
                    <i class="fa fa-hospital"></i>
                </div>
                <h5 class="fw-bold text-dark mb-1">PragmaVet Animal Hospital</h5>
                <p class="text-muted small mb-4">Dedicated Veterinary Healthcare & Wellness Services</p>

                <div class="d-flex flex-column gap-2 text-start bg-light p-3 rounded-3 mb-4">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fa fa-phone text-primary" style="width:20px;"></i>
                        <span class="small font-monospace">+63 (2) 8912-3456 / +63 912 345 6789</span>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <i class="fa fa-envelope text-primary" style="width:20px;"></i>
                        <span class="small">care@pragmavet.com</span>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <i class="fa fa-clock text-primary" style="width:20px;"></i>
                        <span class="small">Mon - Sat: 8:00 AM - 6:00 PM | Sun: Emergency Only</span>
                    </div>
                </div>

                <div class="d-grid gap-2">
                    <a href="tel:+639123456789" class="btn btn-primary rounded-pill py-2 fw-bold">
                        <i class="fa fa-phone me-1"></i> Call Clinic Direct
                    </a>
                    <button type="button" class="btn btn-outline-secondary rounded-pill py-2" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($pet_id)): ?>
<div class="modal fade" id="requestCertModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 20px;">
            <div class="modal-header border-0 pb-0">
                <h5 class="fw-bold text-dark"><i class="fa fa-file-contract text-primary me-2"></i> Request Certificate</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" class="modal-body p-4">
                <input type="hidden" name="request_certificate" value="1">
                <input type="hidden" name="pet_id" value="<?= (int)$pet_id ?>">
                <p class="text-muted small mb-3">Request a Health and Vaccination Certificate for <strong><?= htmlspecialchars($pet_name) ?></strong>. The clinic will review it and notify you when it's ready to download.</p>
                <label class="form-label text-muted small fw-bold">PURPOSE</label>
                <select name="purpose" class="form-select mb-4" required>
                    <option value="" selected disabled>Choose a purpose</option>
                    <option>Travel</option>
                    <option>Boarding / Grooming</option>
                    <option>Pet show or event</option>
                    <option>Housing / Landlord requirement</option>
                    <option>Personal record</option>
                </select>
                <button type="submit" class="btn btn-primary w-100 rounded-pill fw-bold">Send Request</button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
<?php include("chat_widget.php"); ?>

<!-- Bootstrap Bundle JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // Tab Switching Function
    function switchTab(tabId) {
        const newUrl = new URL(window.location);
        newUrl.searchParams.set('tab', tabId);
        window.history.pushState({}, '', newUrl);

        document.querySelectorAll('.emr-tab-button').forEach(btn => {
            btn.classList.remove('active');
        });
        const activeBtn = document.querySelector(`.emr-tab-button[onclick="switchTab('${tabId}')"]`);
        if (activeBtn) activeBtn.classList.add('active');

        document.querySelectorAll('.emr-tab-body').forEach(body => {
            body.style.display = 'none';
        });
        const targetBody = document.getElementById('tab-' + tabId);
        if (targetBody) targetBody.style.display = 'block';
    }

    // Toggle accordion extra details in timeline
    function toggleDetails(index) {
        const extra = document.getElementById('extra-' + index);
        const chevron = document.getElementById('chevron-' + index);
        if (!extra) return;

        if (extra.style.display === 'block') {
            extra.style.display = 'none';
            if (chevron) {
                chevron.classList.remove('fa-chevron-up');
                chevron.classList.add('fa-chevron-down');
            }
        } else {
            extra.style.display = 'block';
            if (chevron) {
                chevron.classList.remove('fa-chevron-down');
                chevron.classList.add('fa-chevron-up');
            }
        }
    }

    // Filter Timeline Items by type
    function filterTimeline(filterType) {
        const items = document.querySelectorAll('.timeline-item');
        items.forEach(item => {
            const itemType = item.getAttribute('data-type');
            if (filterType === 'all' || itemType === filterType) {
                item.style.display = 'flex';
            } else {
                item.style.display = 'none';
            }
        });
    }

    // Auto-dismiss alerts after 4.5 seconds
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert-toast');
        alerts.forEach(a => {
            a.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
            a.style.opacity = '0';
            a.style.transform = 'translateY(-20px)';
            setTimeout(() => a.remove(), 400);
        });
    }, 4500);

    // Modal DOM safety placement
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.modal').forEach(function (modal) {
            document.body.appendChild(modal);
        });
    });
</script>

<script src="mousetrail.js"></script>
    <?php include_once("includes/reminder_popup.php"); ?>
</body>
</html>
