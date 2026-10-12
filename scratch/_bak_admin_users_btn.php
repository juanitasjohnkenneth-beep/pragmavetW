<?php
require_once __DIR__ . '/includes/mail_config.php';
session_start();
include("dbconnection.php");
include_once("includes/rbac_guard.php");

// PHPMailer Integration for Walk-In Registration
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

// Check if user is logged in AND is an admin or owner
$user_role = $_SESSION['role'] ?? '';
$is_admin = ($user_role === 'admin');
$is_owner = ($user_role === 'clinic_owner' || $user_role === 'owner');

if (!isset($_SESSION['user_id']) || (!$is_admin && !$is_owner)) {
    if ($user_role === 'doctor') {
        header("Location: doctor.php");
    } elseif ($user_role === 'staff') {
        header("Location: staff_dashboard.php");
    } else {
        header("Location: index.php");
    }
    exit();
}

// 1. ADD ACCOUNT (Consolidated Walk-In & Staff Creation)
if (isset($_POST['add_walkin'])) {
    $fullname = trim($_POST['fullname']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $address = trim($_POST['address']);
    $role = $_POST['role'];

    // Check if email already exists
    $check = $conn->prepare("SELECT id FROM users WHERE email=?");
    $check->bind_param("s", $email);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        $_SESSION['message'] = "Email is already registered in the system.";
        $_SESSION['msg_type'] = "danger";
    } else {
        // Generate Default Password
        $raw_password = 'PragmaVet!123';
        $hashed_pwd = password_hash($raw_password, PASSWORD_DEFAULT);

        // Insert User with force_password_change flag securely enabled and pre-verified status
        $stmt = $conn->prepare("INSERT INTO users (fullname, email, password, contact_number, address, role, force_password_change, is_verified) VALUES (?, ?, ?, ?, ?, ?, 1, 1)");
        $stmt->bind_param("ssssss", $fullname, $email, $hashed_pwd, $phone, $address, $role);

        if ($stmt->execute()) {
            $new_user_id = $conn->insert_id;
            
            // Save RBAC user permissions if provided and applicable
            $perms = $_POST['permissions'] ?? [];
            if (!empty($perms) && in_array($role, ['staff', 'doctor', 'clinic_owner', 'admin'])) {
                $insert_stmt = $conn->prepare("INSERT INTO rbac_user_permissions (user_id, permission_key, is_granted) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE is_granted = 1");
                foreach ($perms as $p_key) {
                    $insert_stmt->bind_param("is", $new_user_id, $p_key);
                    $insert_stmt->execute();
                }
            }

            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host = 'smtp.gmail.com';
                $mail->SMTPAuth = true;
                $mail->Username = PV_SMTP_USER;
                $mail->Password = PV_SMTP_PASS;
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port = 587;

                $mail->setFrom(PV_SMTP_USER, 'PragmaVet Hub');
                $mail->addAddress($email, $fullname);
                $mail->isHTML(true);

                $role_title = ($role === 'doctor') ? 'Medical Professional' : 'Client Profile';
                $mail->Subject = "Welcome to PragmaVet: Your New $role_title Account";

                $welcome_header = ($role === 'doctor')
                    ? "Welcome to the Team, Dr. $fullname!"
                    : "Welcome to PragmaVet, $fullname!";
                $welcome_msg = ($role === 'doctor')
                    ? "Your professional doctor account has been initialized in our system. You now have full access to manage patient consultations and clinical workflows."
                    : "An administrator has successfully registered your walk-in profile in our digital system. You can now manage your pets and appointments online.";

                $mail->Body = "
                    <div style='font-family: Helvetica, sans-serif; max-width: 600px; padding: 30px; border: 1px solid #e1e8ed; border-radius: 15px; background:#ffffff;'>
                        <div style='text-align:center; margin-bottom:20px;'>
                            <h2 style='color: #2563eb; margin:0;'>PragmaVet Hub</h2>
                        </div>
                        <h3 style='color: #0f172a;'>$welcome_header</h3>
                        <p style='color: #475569; font-size: 15px; line-height:1.6;'>$welcome_msg</p>
                        
                        <div style='background: #E6EEF8; padding: 25px; border-radius: 12px; border: 1px solid #e2e8f0; margin: 25px 0;'>
                            <p style='margin: 0 0 12px 0; color:#64748b; font-size:13px; font-weight:bold; text-transform:uppercase;'>Secure Access Credentials</p>
                            <p style='margin: 0 0 10px 0;'><strong>Login Page:</strong> <a href='http://localhost/pragmavet/index.php' style='color:#2563eb; text-decoration:none;'>Portal Entry</a></p>
                            <p style='margin: 0 0 10px 0;'><strong>Username:</strong> <span style='color:#0f172a; font-weight:600;'>$email</span></p>
                            <p style='margin: 0;'><strong>Temporary Password:</strong> <span style='color: #ef4444; font-weight: bold;'>$raw_password</span></p>
                        </div>
                        
                        <p style='font-size: 13px; color: #ef4444; font-weight: bold; padding: 12px; background: #fef2f2; border-radius: 8px;'>Ã¢Å¡Â Ã¯Â¸Â SECURITY ACTION REQUIRED: You must change this temporary password immediately upon your first login.</p>
                        <hr style='border:none; border-top:1px solid #f1f5f9; margin:25px 0;'>
                        <p style='color: #94a3b8; font-size: 11px; text-align:center;'>This is an automated security dispatch. Please do not reply directly to this email.</p>
                    </div>
                ";
                $mail->send();

                $_SESSION['message'] = "Account generated securely! Credentials dispatched to $email.";
                $_SESSION['msg_type'] = "success";
            } catch (Exception $e) {
                $_SESSION['message'] = "Profile created, but email dispatch failed: " . $mail->ErrorInfo;
                $_SESSION['msg_type'] = "warning";
            }
        } else {
            $_SESSION['message'] = "Database Error: " . $conn->error;
            $_SESSION['msg_type'] = "danger";
        }
    }
    header("Location: admin_users.php");
    exit();
}

// 2. DELETE USER
if (isset($_POST['delete_user'])) {
    $uid = (int)$_POST['target_user_id'];
    $tgt = $conn->query("SELECT role FROM users WHERE id = $uid")->fetch_assoc();
    $has_records = $tgt && (
        $conn->query("SELECT 1 FROM appointments WHERE user_id = $uid OR doctor_id = $uid LIMIT 1")->num_rows
        || $conn->query("SELECT 1 FROM boarding_reservations WHERE user_id = $uid LIMIT 1")->num_rows
        || $conn->query("SELECT 1 FROM billing WHERE user_id = $uid LIMIT 1")->num_rows
        || $conn->query("SELECT 1 FROM pets WHERE user_id = $uid LIMIT 1")->num_rows);
    $why = !$tgt ? "User not found."
         : ($uid === (int)$_SESSION['user_id'] ? "You can't delete your own account."
         : ($tgt['role'] === 'clinic_owner' ? "The clinic owner's account can't be deleted."
         : ($has_records ? "This account has appointments, pets, stays or invoices, so it can't be deleted (that history would be lost). Remove its verification instead to block access." : null)));
    if ($why) {
        $_SESSION['message'] = $why;
        $_SESSION['msg_type'] = "danger";
        header("Location: admin_users.php");
        exit();
    }
    $stmt = $conn->prepare("DELETE FROM users WHERE id=?");
    $stmt->bind_param("i", $uid);
    if ($stmt->execute()) {
        $_SESSION['message'] = "User successfully deleted from the database.";
        $_SESSION['msg_type'] = "success";
    }
    header("Location: admin_users.php");
    exit();
}

// 3. EDIT USER
if (isset($_POST['edit_user'])) {
    $uid = (int)$_POST['target_user_id'];
    $fn = trim($_POST['edit_fullname']);
    $em = trim($_POST['edit_email']);
    $rl = $_POST['edit_role'];
    $cur = $conn->query("SELECT role FROM users WHERE id = $uid")->fetch_assoc();
    $actor_owner = ($_SESSION['role'] ?? '') === 'clinic_owner';
    $dup = $conn->prepare("SELECT 1 FROM users WHERE email = ? AND id <> ?");
    $dup->bind_param("si", $em, $uid); $dup->execute();
    $why = !$cur ? "User not found."
         : (!in_array($rl, ['admin', 'clinic_owner', 'doctor', 'staff', 'user', 'client'], true) ? "Choose a valid role."
         : (!filter_var($em, FILTER_VALIDATE_EMAIL) ? "Enter a valid email address."
         : ($dup->get_result()->num_rows ? "Another account already uses that email."
         : ($uid === (int)$_SESSION['user_id'] && $rl !== $cur['role'] ? "You can't change your own role."
         : (!$actor_owner && ($cur['role'] === 'clinic_owner' || $rl === 'clinic_owner') && $rl !== $cur['role'] ? "Only the clinic owner can give or remove the clinic owner role." : null)))));
    if ($why) {
        $_SESSION['message'] = $why;
        $_SESSION['msg_type'] = "danger";
        header("Location: admin_users.php");
        exit();
    }

    $stmt = $conn->prepare("UPDATE users SET fullname=?, email=?, role=? WHERE id=?");
    $stmt->bind_param("sssi", $fn, $em, $rl, $uid);
    if ($stmt->execute()) {
        $_SESSION['message'] = "User credentials and permission roles updated securely.";
        $_SESSION['msg_type'] = "success";
    }
    header("Location: admin_users.php");
    exit();
}

// 4. ADD PET (from the owner's profile window)
if (isset($_POST['admin_add_pet'])) {
    $uid = (int)$_POST['pet_user_id'];
    $n   = trim($_POST['pet_name'] ?? '');
    $sp  = trim($_POST['pet_species'] ?? '');
    $br  = trim($_POST['pet_breed'] ?? '');
    $gd  = in_array($_POST['pet_gender'] ?? '', ['Male', 'Female'], true) ? $_POST['pet_gender'] : null;
    $dob = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['pet_dob'] ?? '') ? $_POST['pet_dob'] : null;
    $ag  = max(0, (int)($_POST['pet_age'] ?? 0));
    if ($dob && $ag === 0) $ag = (int)floor((time() - strtotime($dob)) / (365.25 * 86400));
    $wt  = max(0, round((float)($_POST['pet_weight'] ?? 0), 2));
    $mh  = trim($_POST['pet_history'] ?? '');
    $owner = $uid ? $conn->query("SELECT id FROM users WHERE id = $uid")->fetch_assoc() : null;
    if (!$owner || $n === '' || $sp === '') {
        $_SESSION['message'] = "Pet not saved: the pet's name and species are required."; $_SESSION['msg_type'] = "danger";
    } else {
        $stmt = $conn->prepare("INSERT INTO pets(user_id,name,species,breed,gender,date_of_birth,age,weight,medical_history) VALUES(?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param("isssssids", $uid, $n, $sp, $br, $gd, $dob, $ag, $wt, $mh);
        $stmt->execute();
        $_SESSION['message'] = "Pet '" . htmlspecialchars($n) . "' added."; $_SESSION['msg_type'] = "success";
    }
    header("Location: admin_users.php?profile=$uid"); exit();
}

// 5b. DELETE PET
if (isset($_POST['admin_delete_pet'])) {
    $pid = (int)$_POST['delete_pet_id'];
    $owner_id = (int)($conn->query("SELECT user_id FROM pets WHERE id = $pid")->fetch_assoc()['user_id'] ?? 0);
    $del = $conn->prepare("DELETE FROM pets WHERE id=?");
    $del->bind_param('i', $pid);
    $del->execute();
    $_SESSION['message'] = "Pet deleted successfully."; $_SESSION['msg_type'] = "danger";
    header("Location: admin_users.php" . ($owner_id ? "?profile=$owner_id" : '')); exit();
}

// 5. EDIT PET
if (isset($_POST['admin_edit_pet'])) {
    $pid = (int)$_POST['edit_pet_id'];
    $n   = trim($_POST['edit_pet_name']);
    $sp  = trim($_POST['edit_pet_species']);
    $br  = trim($_POST['edit_pet_breed']);
    $ag  = (int)$_POST['edit_pet_age'];
    $wt  = (float)$_POST['edit_pet_weight'];
    $mh  = trim($_POST['edit_pet_history'] ?? '');
    $stmt = $conn->prepare("UPDATE pets SET name=?,species=?,breed=?,age=?,weight=?,medical_history=? WHERE id=?");
    $stmt->bind_param("sssidsi", $n, $sp, $br, $ag, $wt, $mh, $pid);
    $stmt->execute();
    $owner_id = (int)($conn->query("SELECT user_id FROM pets WHERE id = $pid")->fetch_assoc()['user_id'] ?? 0);
    $_SESSION['message'] = "Pet updated!"; $_SESSION['msg_type'] = "success";
    header("Location: admin_users.php" . ($owner_id ? "?profile=$owner_id" : '')); exit();
}

// 6. TOGGLE USER VERIFICATION
if (isset($_POST['toggle_verification'])) {
    $uid = (int)$_POST['target_user_id'];
    $status = (int)$_POST['verification_status'];
    $stmt = $conn->prepare("UPDATE users SET is_verified=? WHERE id=?");
    $stmt->bind_param("ii", $status, $uid);
    if ($stmt->execute()) {
        $_SESSION['message'] = $status ? "User account #$uid has been successfully verified." : "User account #$uid verification revoked.";
        $_SESSION['msg_type'] = "success";
    }
    header("Location: admin_users.php");
    exit();
}

// 7. UNLOCK LOCKED ACCOUNT
if (isset($_POST['unlock_user'])) {
    $uid = (int)$_POST['target_user_id'];
    $stmt = $conn->prepare("UPDATE users SET failed_attempts=0, locked_until=NULL WHERE id=?");
    $stmt->bind_param("i", $uid);
    if ($stmt->execute()) {
        $_SESSION['message'] = "User account #$uid has been unlocked successfully.";
        $_SESSION['msg_type'] = "success";
    }
    header("Location: admin_users.php");
    exit();
}

// 8. RESET USER PASSWORD
if (isset($_POST['reset_user_password'])) {
    $uid = (int)$_POST['target_user_id'];
    $new_pwd = 'Pv-' . substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(9))), 0, 8) . random_int(10, 99) . '!'; // hard to guess; must be changed at next login
    $hashed = password_hash($new_pwd, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE users SET password=?, force_password_change=1 WHERE id=?");
    $stmt->bind_param("si", $hashed, $uid);
    if ($stmt->execute()) {
        $_SESSION['message'] = "Password for user #$uid reset to temporary password: $new_pwd (Required to change upon login).";
        $_SESSION['msg_type'] = "warning";
    }
    header("Location: admin_users.php");
    exit();
}

// 9. SAVE RBAC PERMISSIONS
if (isset($_POST['save_rbac'])) {
    $target_user_id = (int)$_POST['rbac_user'];
    $perms = $_POST['permissions'] ?? [];
    
    // Reset user-specific permissions
    $stmt = $conn->prepare("UPDATE rbac_user_permissions SET is_granted = 0 WHERE user_id = ?");
    $stmt->bind_param("i", $target_user_id);
    $stmt->execute();
    
    // Insert new user-specific permissions
    if (!empty($perms)) {
        $insert_stmt = $conn->prepare("INSERT INTO rbac_user_permissions (user_id, permission_key, is_granted) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE is_granted = 1");
        foreach ($perms as $p_key) {
            $insert_stmt->bind_param("is", $target_user_id, $p_key);
            $insert_stmt->execute();
        }
    }
    
    $_SESSION['message'] = "User-specific RBAC permissions updated successfully for User ID: " . $target_user_id;
    $_SESSION['msg_type'] = "success";
    header("Location: admin_users.php");
    exit();
}

// Fetch Master List
$users = $conn->query("SELECT * FROM users ORDER BY created_at DESC");
$all_rbac_users = [];
if ($users) {
    // we need to iterate, but we also use $users below for the table. So let's store it into an array or just run a separate query
    $all_users_for_dropdown = $conn->query("SELECT id, fullname, role, email FROM users ORDER BY fullname ASC");
    while ($u = $all_users_for_dropdown->fetch_assoc()) {
        $all_rbac_users[] = $u;
    }
}

// Fetch RBAC data for modal
$rbac_perms_q = $conn->query("SELECT * FROM rbac_permissions ORDER BY module_name, permission_name");
$rbac_perms_by_module = [];
if ($rbac_perms_q) { while ($p = $rbac_perms_q->fetch_assoc()) $rbac_perms_by_module[$p['module_name']][] = $p; }

// Fetch user-specific permissions
$rbac_user_perms_q = $conn->query("SELECT user_id, permission_key FROM rbac_user_permissions WHERE is_granted = 1");
$rbac_user_perms = [];
if ($rbac_user_perms_q) { while ($rp = $rbac_user_perms_q->fetch_assoc()) $rbac_user_perms[$rp['user_id']][] = $rp['permission_key']; }

// Pets grouped by user
$all_pets_q = $conn->query("SELECT * FROM pets ORDER BY user_id, name ASC");
$pets_by_user = [];
if ($all_pets_q) { while ($p = $all_pets_q->fetch_assoc()) $pets_by_user[$p['user_id']][] = $p; }
$profile_open = null;
if (!empty($_GET['profile'])) {
    $po = $conn->query("SELECT * FROM users WHERE id = " . (int)$_GET['profile'])->fetch_assoc();
    if ($po) $profile_open = ['id' => $po['id'], 'fullname' => $po['fullname'], 'email' => $po['email'], 'role' => $po['role'], 'contact' => $po['contact_number'] ?? '',
                            'address' => $po['address'] ?? '', 'created' => $po['created_at'], 'pets' => $pets_by_user[$po['id']] ?? []];
}

// User Stats Metrics
$total_users_count      = (int)($conn->query("SELECT COUNT(*) as c FROM users")->fetch_assoc()['c'] ?? 0);
$total_clients_count    = (int)($conn->query("SELECT COUNT(*) as c FROM users WHERE role IN ('user','client')")->fetch_assoc()['c'] ?? 0);
$total_staff_count      = (int)($conn->query("SELECT COUNT(*) as c FROM users WHERE role='staff'")->fetch_assoc()['c'] ?? 0);
$total_vets_count       = (int)($conn->query("SELECT COUNT(*) as c FROM users WHERE role='doctor'")->fetch_assoc()['c'] ?? 0);
$total_pets_count       = (int)($conn->query("SELECT COUNT(*) as c FROM pets")->fetch_assoc()['c'] ?? 0);
$total_unverified_count = (int)($conn->query("SELECT COUNT(*) as c FROM users WHERE is_verified=0")->fetch_assoc()['c'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>User Management | Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">

    <style>
        .role-ftab {
            padding: 7px 16px;
            border-radius: 20px;
            border: 1.5px solid #e2e8f0;
            background: #f8fafc;
            font-size: 12.5px;
            font-weight: 700;
            color: #64748b;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .role-ftab:hover, .role-ftab.active {
            background: #2563eb;
            border-color: #2563eb;
            color: #ffffff;
        }
        .role-ftab .cnt {
            background: rgba(0,0,0,0.06);
            border-radius: 20px;
            padding: 1px 8px;
            font-size: 11px;
        }
        .role-ftab.active .cnt {
            background: rgba(255,255,255,0.25);
            color: #ffffff;
        }

    <style>
        :root {
            --primary: #2563eb;
            --secondary: #3b82f6;
            --dark: #1e40af;
            --light-bg: #E6EEF8;
            --sidebar-width: 260px;
        }

        body {
            background: var(--light-bg);
            font-family: 'Inter', sans-serif;
            color: #333;
            overflow-x: hidden;
        }

        /* SIDEBAR */
        .sidebar {
            background: #0f172a;
            width: var(--sidebar-width);
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            padding: 24px 16px;
            z-index: 1000;
            display: flex;
            flex-direction: column;
            box-shadow: 5px 0 20px rgba(0, 0, 0, .2);
            overflow-y: auto;
        }

        .sidebar h4 {
            color: #ffffff;
            font-weight: 800;
            font-size: 22px;
            margin-bottom: 30px;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .sidebar a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            border-radius: 8px;
            color: #94a3b8;
            text-decoration: none;
            margin-bottom: 4px;
            font-weight: 600;
            font-size: 15px;
            transition: .2s;
        }

        .sidebar a i {
            width: 30px;
            font-size: 18px;
        }

        .sidebar a:hover,
        .sidebar a.active {
            background: #2563eb;
            color: #ffffff;
        }

        /* CONTENT AREA */
        .main-content {
            margin-left: 260px !important;
            width: calc(100% - 260px) !important;
            max-width: calc(100% - 260px) !important;
            padding: 24px 28px !important;
            min-height: 100vh;
            box-sizing: border-box !important;
        }

        @media (max-width: 1200px) {
            .main-content {
                padding: 20px 20px !important;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
                padding: 16px !important;
            }
            .sidebar {
                position: relative !important;
                width: 100% !important;
                height: auto !important;
            }
        }

        .custom-card {
            background: white;
            border: none;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
            padding: 24px;
        }

        .table-responsive {
            overflow-x: auto !important;
            width: 100% !important;
        }

        .table th {
            border-bottom: 2px solid #edf2f6;
            color: #8a9fc2;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 12px;
            letter-spacing: 0.5px;
            padding-bottom: 15px;
        }

        .table td {
            vertical-align: middle;
            border-bottom: 1px solid #edf2f6;
            padding: 20px 10px;
            font-size: 14px;
            font-weight: 500;
        }

        .btn-action {
            width: 35px;
            height: 35px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-right: 5px;
            transition: 0.2s;
            border: none;
        }

        .btn-action:hover {
            transform: translateY(-2px);
        }

        /* Custom Search Styling */
        .search-input-group {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 50px;
            padding: 2px 15px;
            display: flex;
            align-items: center;
            width: 300px;
            transition: 0.3s;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.02);
        }

        .search-input-group:focus-within {
            border-color: #2563eb;
            box-shadow: 0 4px 15px rgba(37, 99, 235, 0.1);
            width: 350px;
        }

        .search-input-group input {
            border: none;
            background: transparent;
            padding: 8px 10px;
            font-size: 14px;
            font-weight: 500;
            width: 100%;
            outline: none;
        }

        .search-input-group i {
            color: #94a3b8;
            font-size: 14px;
        }

        /* SaaS Modal Styling */
        .modal-header-saas {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 25px 35px 15px;
        }

        .modal-header-saas .icon-box {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: #eff6ff;
            color: #2563eb;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .modal-body-saas {
            padding: 10px 35px 35px;
        }

        .saas-label {
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #94a3b8;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .form-control,
        .form-select {
            border-radius: 12px !important;
            padding: 12px 16px !important;
            border: 1px solid #e2e8f0 !important;
            font-size: 14px !important;
        }

        .sidebar::-webkit-scrollbar {
            width: 5px;
        }

        .sidebar::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 10px;
        }

        .sidebar::-webkit-scrollbar-track {
            background: transparent;
        }

        /* ALERT */
        .alert-container {
            position: fixed;
            top: 30px;
            right: 40px;
            z-index: 9999;
            animation: slideDown 0.5s ease-out forwards;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    
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

    <?php if (isset($_SESSION['message'])): ?>
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <script>
            document.addEventListener("DOMContentLoaded", function() {
                const Toast = Swal.mixin({
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 3000,
                    timerProgressBar: true,
                    didOpen: (toast) => {
                        toast.addEventListener('mouseenter', Swal.stopTimer)
                        toast.addEventListener('mouseleave', Swal.resumeTimer)
                    }
                });
                
                let iconType = 'info';
                const msgType = '<?php echo $_SESSION['msg_type']; ?>';
                if (msgType === 'success') iconType = 'success';
                else if (msgType === 'danger') iconType = 'error';
                else if (msgType === 'warning') iconType = 'warning';

                Toast.fire({
                    icon: iconType,
                    title: <?php echo json_encode($_SESSION['message']); ?>
                });
            });
        </script>
        <?php unset($_SESSION['message'], $_SESSION['msg_type']); ?>
    <?php endif; ?>

    <?php include("includes/admin_sidebar.php"); ?>

    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <span class="badge bg-primary bg-opacity-10 text-primary fw-bold px-3 py-1.5 rounded-pill mb-2" style="font-size: 11px;">
                    <i class="fa fa-users-cog me-1"></i> RBAC & IDENTITY MANAGEMENT
                </span>
                <h2 class="fw-bold text-dark m-0">User & Client Management</h2>
                <p class="text-muted mb-0">System-wide directory for registered clients, vet staff accounts, doctor credentials, and RBAC permissions.</p>
            </div>

            <div class="d-flex align-items-center gap-3">
                <div class="search-input-group">
                    <i class="fa fa-search"></i>
                    <input type="text" id="customSearch" placeholder="Search Users...">
                </div>
                <button class="btn btn-secondary px-4 py-2 rounded-pill fw-bold shadow-sm" data-bs-toggle="modal"
                    data-bs-target="#rbacModal">
                    <i class="fa fa-shield-alt me-1"></i> RBAC Permissions
                </button>
                <button class="btn btn-primary px-4 py-2 rounded-pill fw-bold shadow-sm" data-bs-toggle="modal"
                    data-bs-target="#addAccModal">
                    <i class="fa fa-user-plus me-1"></i> Add Account
                </button>
            </div>
        </div>

        <!-- KPI Executive Metric Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-2 col-sm-4 col-6">
                <div class="custom-card p-3 d-flex align-items-center gap-3 shadow-sm border-0 h-100">
                    <div class="rounded-circle bg-primary bg-opacity-10 text-primary p-2.5 d-flex align-items-center justify-content-center" style="width: 44px; height: 44px;">
                        <i class="fa fa-users fs-5"></i>
                    </div>
                    <div>
                        <div class="text-muted small fw-semibold">Total Accounts</div>
                        <h4 class="fw-bold m-0 text-dark"><?= number_format($total_users_count) ?></h4>
                    </div>
                </div>
            </div>
            <div class="col-md-2 col-sm-4 col-6">
                <div class="custom-card p-3 d-flex align-items-center gap-3 shadow-sm border-0 h-100">
                    <div class="rounded-circle bg-info bg-opacity-10 text-info p-2.5 d-flex align-items-center justify-content-center" style="width: 44px; height: 44px;">
                        <i class="fa fa-user-tag fs-5"></i>
                    </div>
                    <div>
                        <div class="text-muted small fw-semibold">Clients / Owners</div>
                        <h4 class="fw-bold m-0 text-dark"><?= number_format($total_clients_count) ?></h4>
                    </div>
                </div>
            </div>
            <div class="col-md-2 col-sm-4 col-6">
                <div class="custom-card p-3 d-flex align-items-center gap-3 shadow-sm border-0 h-100">
                    <div class="rounded-circle p-2.5 d-flex align-items-center justify-content-center" style="width: 44px; height: 44px; color: #2563eb; background-color: rgba(37, 99, 235, 0.1);">
                        <i class="fa fa-id-badge fs-5"></i>
                    </div>
                    <div>
                        <div class="text-muted small fw-semibold">Vet Staff</div>
                        <h4 class="fw-bold m-0 text-dark"><?= number_format($total_staff_count) ?></h4>
                    </div>
                </div>
            </div>
            <div class="col-md-2 col-sm-4 col-6">
                <div class="custom-card p-3 d-flex align-items-center gap-3 shadow-sm border-0 h-100">
                    <div class="rounded-circle p-2.5 d-flex align-items-center justify-content-center" style="width: 44px; height: 44px; color: #7c3aed; background-color: rgba(124, 58, 237, 0.1);">
                        <i class="fa fa-user-md fs-5"></i>
                    </div>
                    <div>
                        <div class="text-muted small fw-semibold">Active Doctors</div>
                        <h4 class="fw-bold m-0 text-dark"><?= number_format($total_vets_count) ?></h4>
                    </div>
                </div>
            </div>
            <div class="col-md-2 col-sm-4 col-6">
                <div class="custom-card p-3 d-flex align-items-center gap-3 shadow-sm border-0 h-100">
                    <div class="rounded-circle bg-warning bg-opacity-10 text-warning p-2.5 d-flex align-items-center justify-content-center" style="width: 44px; height: 44px;">
                        <i class="fa fa-user-clock fs-5"></i>
                    </div>
                    <div>
                        <div class="text-muted small fw-semibold">Unverified</div>
                        <h4 class="fw-bold m-0 text-warning"><?= number_format($total_unverified_count) ?></h4>
                    </div>
                </div>
            </div>
            <div class="col-md-2 col-sm-4 col-6">
                <div class="custom-card p-3 d-flex align-items-center gap-3 shadow-sm border-0 h-100">
                    <div class="rounded-circle bg-success bg-opacity-10 text-success p-2.5 d-flex align-items-center justify-content-center" style="width: 44px; height: 44px;">
                        <i class="fa fa-paw fs-5"></i>
                    </div>
                    <div>
                        <div class="text-muted small fw-semibold">Registered Pets</div>
                        <h4 class="fw-bold m-0 text-dark"><?= number_format($total_pets_count) ?></h4>
                    </div>
                </div>
            </div>
        </div>

        <!-- Role Segment Filter Tabs -->
        <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
            <div class="d-flex gap-2 flex-wrap">
                <button class="role-ftab active" data-filter="all" onclick="filterUserRole('all', this)">
                    <i class="fa fa-layer-group me-1"></i> All Users
                </button>
                <button class="role-ftab" data-filter="client" onclick="filterUserRole('client', this)">
                    <i class="fa fa-user-tag me-1"></i> Clients / Owners
                </button>
                <button class="role-ftab" data-filter="staff" onclick="filterUserRole('staff', this)">
                    <i class="fa fa-id-badge me-1"></i> Vet Staff
                </button>
                <button class="role-ftab" data-filter="doctor" onclick="filterUserRole('doctor', this)">
                    <i class="fa fa-user-md me-1"></i> Vets / Doctors
                </button>
                <button class="role-ftab" data-filter="admin" onclick="filterUserRole('admin', this)">
                    <i class="fa fa-user-shield me-1"></i> Admins
                </button>
                <button class="role-ftab" data-filter="clinic_owner" onclick="filterUserRole('clinic_owner', this)">
                    <i class="fa fa-briefcase me-1"></i> Clinic Owners
                </button>
                <button class="role-ftab" data-filter="unverified" onclick="filterUserRole('unverified', this)">
                    <i class="fa fa-user-clock me-1 text-warning"></i> Unverified (<?= $total_unverified_count ?>)
                </button>
            </div>
        </div>

        <div class="custom-card">
            <div class="table-responsive">
                <table id="usersTable" class="table table-borderless align-middle mb-0">
                    <thead>
                        <tr>
                            <th>User ID</th>
                            <th>Full Name & Contact</th>
                            <th>Email Address</th>
                            <th>Role / Permission</th>
                            <th>Verification Status</th>
                            <th>Registered Pets</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($users && $users->num_rows > 0): ?>
                            <?php while ($row = $users->fetch_assoc()): ?>
                                <?php 
                                    $user_pets = $pets_by_user[$row['id']] ?? [];
                                    $pc = count($user_pets); 
                                    $user_json = json_encode([
                                        'id' => $row['id'],
                                        'fullname' => $row['fullname'],
                                        'email' => $row['email'],
                                        'role' => $row['role'],
                                        'contact' => $row['contact_number'] ?? '',
                                        'address' => $row['address'] ?? '',
                                        'created' => $row['created_at'],
                                        'pets' => $user_pets
                                    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                                ?>
                                <tr class="user-row" data-role="<?= htmlspecialchars($row['role']) ?>">
                                    <td>
                                        <span class="badge bg-light text-dark font-monospace border px-2 py-1">
                                            #<?= str_pad($row['id'], 5, '0', STR_PAD_LEFT); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($row['fullname']); ?></div>
                                        <div class="small text-muted">
                                            <i class="fa fa-phone me-1 opacity-50"></i><?php echo htmlspecialchars($row['contact_number'] ?? 'N/A'); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="text-primary fw-semibold">
                                            <i class="fa fa-envelope me-1 opacity-50"></i><?php echo htmlspecialchars($row['email']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($row['role'] === 'admin'): ?>
                                            <span class="badge bg-warning bg-opacity-10 text-dark border border-warning border-opacity-25 px-2.5 py-1.5 rounded-pill fw-bold" style="font-size: 11px;">
                                                <i class="fa fa-shield-alt me-1 text-warning"></i>ADMIN
                                            </span>
                                        <?php elseif ($row['role'] === 'doctor'): ?>
                                            <span class="badge px-2.5 py-1.5 rounded-pill fw-bold" style="font-size: 11px; background: rgba(124, 58, 237, 0.1); color: #7c3aed; border: 1px solid rgba(124, 58, 237, 0.25);">
                                                <i class="fa fa-user-md me-1"></i>DOCTOR
                                            </span>
                                        <?php elseif ($row['role'] === 'staff'): ?>
                                            <span class="badge px-2.5 py-1.5 rounded-pill fw-bold" style="font-size: 11px; background: rgba(37, 99, 235, 0.1); color: #2563eb; border: 1px solid rgba(37, 99, 235, 0.25);">
                                                <i class="fa fa-id-badge me-1"></i>VET STAFF
                                            </span>
                                        <?php elseif ($row['role'] === 'clinic_owner' || $row['role'] === 'owner'): ?>
                                            <span class="badge px-2.5 py-1.5 rounded-pill fw-bold" style="font-size: 11px; background: rgba(16, 185, 129, 0.1); color: #059669; border: 1px solid rgba(16, 185, 129, 0.25);">
                                                <i class="fa fa-briefcase me-1"></i>CLINIC OWNER
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-2.5 py-1.5 rounded-pill fw-bold" style="font-size: 11px;">
                                                <i class="fa fa-user me-1"></i>CLIENT
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                         <?php if (!empty($row['is_verified'])): ?>
                                             <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2.5 py-1 rounded-pill fw-semibold" style="font-size: 11px;">
                                                 <i class="fa fa-check-circle me-1"></i>Verified
                                             </span>
                                         <?php else: ?>
                                             <span class="badge bg-warning bg-opacity-10 text-warning border border-warning border-opacity-25 px-2.5 py-1 rounded-pill fw-semibold" style="font-size: 11px;">
                                                 <i class="fa fa-clock me-1"></i>Unverified
                                             </span>
                                         <?php endif; ?>
                                        <?php if (!empty($row['locked_until']) && strtotime($row['locked_until']) > time()): ?>
                                            <div class="mt-1">
                                                <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 px-2 py-0.5 rounded-pill fw-bold" style="font-size: 10px;">
                                                    <i class="fa fa-lock me-1"></i>Locked Out
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary rounded-pill px-3 py-1 fw-semibold d-inline-flex align-items-center gap-1"
                                                onclick="openProfile(<?= htmlspecialchars($user_json, ENT_QUOTES, 'UTF-8') ?>)">
                                            <i class="fa fa-paw"></i>
                                            <span><?= $pc ?> pet<?= $pc !== 1 ? 's' : '' ?></span>
                                        </button>
                                        <?php if ($pc > 0): ?>
                                            <span style="display:none;"><?php 
                                                $p_names = array_map(function($p){ return $p['name']; }, $user_pets);
                                                echo htmlspecialchars(implode(' ', $p_names)); 
                                            ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-inline-flex align-items-center gap-1">
                                            <button class="btn btn-sm btn-primary rounded-pill px-3 py-1 fw-bold shadow-sm d-inline-flex align-items-center gap-1"
                                                onclick="openProfile(<?= htmlspecialchars($user_json, ENT_QUOTES, 'UTF-8') ?>)">
                                                <i class="fa fa-eye"></i> View Profile
                                            </button>
                                            <div class="dropdown d-inline-block">
                                                <button class="btn btn-sm btn-light rounded-circle shadow-sm border" type="button" data-bs-toggle="dropdown" style="width: 32px; height: 32px; padding: 0;">
                                                    <i class="fa fa-ellipsis-v text-secondary"></i>
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end border-0 shadow-lg p-2" style="border-radius: 12px; font-size: 13px;">
                                                    <li>
                                                        <a class="dropdown-item rounded-2 py-1.5" href="#" data-bs-toggle="modal" data-bs-target="#editModal<?= $row['id']; ?>">
                                                            <i class="fa fa-user-shield text-primary me-2"></i>Modify Role / RBAC
                                                        </a>
                                                    </li>
                                                    </li>
                                                    <?php if (!empty($row['locked_until']) && strtotime($row['locked_until']) > time()): ?>
                                                        <li>
                                                            <form method="POST" class="d-inline" onsubmit="return confirmFormSubmit(event, this, 'Unlock this user account?');">
                                                                <input type="hidden" name="unlock_user" value="1">
                                                                <input type="hidden" name="target_user_id" value="<?= $row['id'] ?>">
                                                                <button type="submit" class="dropdown-item rounded-2 py-1.5 text-danger fw-bold">
                                                                    <i class="fa fa-unlock-alt me-2"></i>Unlock Account
                                                                </button>
                                                            </form>
                                                        </li>
                                                    <?php endif; ?>
                                                    <li>
                                                        <form method="POST" class="d-inline" onsubmit="return confirmFormSubmit(event, this, 'Generate and set a temporary password for this user?');">
                                                            <input type="hidden" name="reset_user_password" value="1">
                                                            <input type="hidden" name="target_user_id" value="<?= $row['id'] ?>">
                                                            <button type="submit" class="dropdown-item rounded-2 py-1.5 text-secondary">
                                                                <i class="fa fa-key text-secondary me-2"></i>Reset Temporary Password
                                                            </button>
                                                        </form>
                                                    </li>
                                                    <li>
                                                        <a class="dropdown-item rounded-2 py-1.5" href="#" onclick="openProfile(<?= htmlspecialchars($user_json, ENT_QUOTES, 'UTF-8') ?>); setTimeout(() => { if(document.getElementById('addPetCollapse')) new bootstrap.Collapse(document.getElementById('addPetCollapse')).show(); }, 400); return false;">
                                                            <i class="fa fa-plus-circle text-info me-2"></i>Add Pet for Client
                                                        </a>
                                                    </li>
                                                    <li><hr class="dropdown-divider my-1"></li>
                                                    <li>
                                                        <a class="dropdown-item rounded-2 py-1.5 text-danger" href="#" data-bs-toggle="modal" data-bs-target="#deleteModal<?= $row['id']; ?>">
                                                            <i class="fa fa-trash-alt me-2"></i>Delete User Account
                                                        </a>
                                                    </li>
                                                </ul>
                                            </div>
                                        </div>
                                    </td>
                                </tr>

                                <!-- Edit Modal -->
                                <div class="modal fade" id="editModal<?php echo $row['id']; ?>" tabindex="-1">
                                    <div class="modal-dialog modal-dialog-centered">
                                        <div class="modal-content"
                                            style="border-radius:20px; border:none; box-shadow:0 10px 30px rgba(0,0,0,0.1);">
                                            <div class="modal-header border-0 pb-0">
                                                <h5 class="fw-bold">Modify User</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body p-4">
                                                <form method="POST">
                                                    <input type="hidden" name="target_user_id"
                                                        value="<?php echo $row['id']; ?>">
                                                    <div class="mb-3">
                                                        <label class="saas-label">Full Name</label>
                                                        <input type="text" name="edit_fullname" class="form-control"
                                                            value="<?php echo htmlspecialchars($row['fullname']); ?>" required>
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="saas-label">Email</label>
                                                        <input type="email" name="edit_email" class="form-control"
                                                            value="<?php echo htmlspecialchars($row['email']); ?>" required>
                                                    </div>
                                                    <div class="mb-4">
                                                        <label class="saas-label">Role</label>
                                                        <select name="edit_role" class="form-select">
                                                            <option value="user" <?php echo ($row['role'] == 'user') ? 'selected' : ''; ?>>Client</option>
                                                            <option value="doctor" <?php echo ($row['role'] == 'doctor') ? 'selected' : ''; ?>>Doctor</option>
                                                            <option value="staff" <?php echo ($row['role'] == 'staff') ? 'selected' : ''; ?>>Vet Staff</option>
                                                            <option value="clinic_owner" <?php echo ($row['role'] == 'clinic_owner' || $row['role'] == 'owner') ? 'selected' : ''; ?>>Clinic Owner</option>
                                                            <option value="admin" <?php echo ($row['role'] == 'admin') ? 'selected' : ''; ?>>Admin</option>
                                                        </select>
                                                    </div>
                                                    <button type="submit" name="edit_user"
                                                        class="btn btn-primary w-100 py-3 fw-bold rounded-pill">Update
                                                        User</button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Add Account Modal -->
    <div class="modal fade" id="addAccModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content"
                style="border-radius:24px; padding:0; overflow:hidden; border:none; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);">
                <div class="modal-header-saas mt-2">
                    <div class="icon-box"><i class="fa fa-user-shield"></i></div>
                    <div>
                        <h4 class="fw-bold text-dark m-0">Register New Account</h4>
                        <p class="text-muted small m-0">Create medical staff or client profiles</p>
                    </div>
                    <button type="button" class="btn-close ms-auto" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body-saas">
                    <div class="alert alert-info border-0 shadow-sm mb-4"
                        style="background: #f0f9ff; color: #0369a1; border-radius: 12px; font-size: 13px; font-weight:500;">
                        <i class="fa fa-info-circle me-1"></i> A secure temporary password will be emailed instantly to
                        the user.
                    </div>
                    <form method="POST">
                        <div class="mb-3">
                            <label class="saas-label"><i class="fa fa-user"></i> Full Name</label>
                            <input type="text" name="fullname" class="form-control" placeholder="E.g. Dr. John Smith"
                                required>
                        </div>
                        <div class="mb-3">
                            <label class="saas-label"><i class="fa fa-envelope"></i> Email Address</label>
                            <input type="email" name="email" class="form-control" placeholder="staff@pragmavet.com"
                                required>
                        </div>
                        <div class="mb-3">
                            <label class="saas-label"><i class="fa fa-shield-alt"></i> System Authorization</label>
                            <select name="role" id="addAccRole" class="form-select" onchange="toggleAddAccRbac()" required>
                                <option value="user">Client / Pet Owner</option>
                                <option value="doctor">Veterinarian (Doctor)</option>
                                <option value="staff">Vet Staff (Receptionist / Operations)</option>
                                <option value="clinic_owner">Clinic Owner (View-Only Executive)</option>
                                <option value="admin">System Administrator</option>
                            </select>
                        </div>
                        <div class="row g-2 mb-4">
                            <div class="col-6">
                                <label class="saas-label"><i class="fa fa-phone"></i> Contact #</label>
                                <input type="text" name="phone" class="form-control" placeholder="Optional">
                            </div>
                            <div class="col-6">
                                <label class="saas-label"><i class="fa fa-map-marker-alt"></i> Address</label>
                                <input type="text" name="address" class="form-control" placeholder="City">
                            </div>
                        </div>

                        <!-- Add Account RBAC Checklist -->
                        <div id="addAccRbacContainer" class="mb-4" style="display: none; max-height: 250px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 12px; padding: 15px; background: #f8fafc;">
                            <label class="saas-label mb-3"><i class="fa fa-list-check"></i> Assign Specific Permissions (Optional)</label>
                            <div class="row g-3">
                                <?php foreach ($rbac_perms_by_module as $module_name => $perms): ?>
                                    <div class="col-12">
                                        <h6 class="fw-bold text-primary mb-2" style="font-size: 13px;"><i class="fa fa-cube me-1"></i><?= htmlspecialchars($module_name) ?></h6>
                                        <?php foreach ($perms as $p): ?>
                                            <div class="form-check form-switch mb-1">
                                                <input class="form-check-input" type="checkbox" name="permissions[]" 
                                                    value="<?= htmlspecialchars($p['permission_key']) ?>" 
                                                    id="add_perm_<?= htmlspecialchars($p['permission_key']) ?>">
                                                <label class="form-check-label" for="add_perm_<?= htmlspecialchars($p['permission_key']) ?>">
                                                    <span class="fw-semibold text-dark" style="font-size: 12px;"><?= htmlspecialchars($p['permission_name']) ?></span>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <button type="submit" name="add_walkin" class="btn btn-primary w-100 py-3 fw-bold shadow-lg"
                            style="border-radius:14px;">
                            <i class="fa fa-paper-plane me-2"></i> Register & Dispatch Credentials
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>

    <!-- ── USER PROFILE + PETS MODAL ── -->
    <style>
        .pm-head { background:linear-gradient(135deg,#0f172a 0%,#1e3a8a 55%,#2563eb 100%); padding:22px 24px; display:flex; align-items:center; gap:16px; position:relative; }
        .pm-av { width:58px; height:58px; border-radius:18px; background:rgba(255,255,255,.16); border:2px solid rgba(255,255,255,.35); color:#fff; font-size:24px; font-weight:800; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        .pm-name { color:#fff !important; -webkit-text-fill-color:#fff !important; font-size:20px; font-weight:800; margin:0; letter-spacing:-.3px; }
        .pm-meta { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-top:5px; }
        .pm-role { background:rgba(255,255,255,.18); color:#fff; border-radius:999px; padding:2px 10px; font-size:11px; font-weight:800; letter-spacing:.06em; text-transform:uppercase; }
        .pm-since { color:#cbd5e1; font-size:12.5px; }
        .pm-x { position:absolute; top:16px; right:16px; width:34px; height:34px; border-radius:50%; border:none; background:rgba(255,255,255,.14) !important; background-image:none !important; color:#fff !important; display:flex; align-items:center; justify-content:center; box-shadow:none !important; padding:0; }
        .pm-x:hover { background:rgba(255,255,255,.26) !important; }
        .pm-info { display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:12px; }
        .pm-tile { border:1px solid #e8edf3; border-radius:14px; padding:12px 14px; display:flex; gap:11px; align-items:flex-start; background:#fff; min-width:0; }
        .pm-tile > div { flex:1; text-align:left; }
        .pm-tile .ic { width:34px; height:34px; border-radius:10px; display:flex; align-items:center; justify-content:center; flex-shrink:0; font-size:14px; }
        .pm-tile .k { font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; }
        .pm-tile .v { font-size:14px; font-weight:600; color:#0f172a; word-break:break-word; }
        .pm-tile .v.empty { color:#94a3b8; font-weight:500; font-style:italic; }
        .pm-sec { display:flex; justify-content:space-between; align-items:center; margin:22px 0 12px; }
        .pm-sec h6 { font-weight:800; font-size:15px; margin:0; color:#0f172a; }
        .pm-sec h6 .cnt { background:#eff6ff; color:#1d4ed8; border-radius:999px; padding:1px 9px; font-size:12px; margin-left:6px; }
        .pm-add { border:none; background:#2563eb !important; background-image:none !important; color:#fff !important; border-radius:999px; padding:8px 16px; font-weight:700; font-size:13px; display:inline-flex; gap:7px; align-items:center; box-shadow:0 6px 16px rgba(37,99,235,.25) !important; }
        .pm-add.open { background:#f1f5f9 !important; color:#334155 !important; box-shadow:none !important; }
        .pm-form { border:1.5px dashed #bfdbfe; background:#f8fbff; border-radius:16px; padding:16px; margin-bottom:14px; display:none; }
        .pm-form.show { display:block; animation:pmIn .18s ease; }
        @keyframes pmIn { from { opacity:0; transform:translateY(-6px); } to { opacity:1; transform:none; } }
        .pm-form label { font-size:11.5px; font-weight:700; color:#475569; margin-bottom:4px; display:block; }
        .pm-form label .req { color:#dc2626; }
        .pm-form .form-control, .pm-form .form-select { border:1.5px solid #e2e8f0; border-radius:10px; font-size:13.5px; padding:8px 11px; }
        .pm-form .form-control:focus, .pm-form .form-select:focus { border-color:#3b82f6; box-shadow:0 0 0 3px rgba(59,130,246,.15); }
        .pm-save { border:none; background:#16a34a !important; background-image:none !important; color:#fff !important; border-radius:999px; padding:9px 18px; font-weight:800; font-size:13.5px; box-shadow:none !important; }
        .pm-cancel { border:1.5px solid #e2e8f0; background:#fff !important; background-image:none !important; color:#475569 !important; border-radius:999px; padding:8px 16px; font-weight:700; font-size:13.5px; box-shadow:none !important; }
        .pm-pets { display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:12px; }
        .pm-pet { border:1px solid #e8edf3; border-radius:16px; padding:14px; background:#fff; transition:box-shadow .15s, transform .15s; }
        .pm-pet:hover { box-shadow:0 10px 24px rgba(15,23,42,.08); transform:translateY(-1px); }
        .pm-pet-top { display:flex; gap:11px; align-items:center; }
        .pm-pet-ic { width:42px; height:42px; border-radius:13px; display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0; }
        .pm-pet-name { font-weight:800; font-size:15px; color:#0f172a; }
        .pm-pet-sub { font-size:12px; color:#64748b; }
        .pm-pet-acts { margin-left:auto; display:flex; gap:6px; }
        .pm-ibtn { width:32px; height:32px; border-radius:10px; border:1px solid #e2e8f0; background:#fff !important; background-image:none !important; display:flex; align-items:center; justify-content:center; font-size:13px; padding:0; box-shadow:none !important; }
        .pm-ibtn.e { color:#2563eb !important; } .pm-ibtn.e:hover { background:#eff6ff !important; border-color:#bfdbfe; }
        .pm-ibtn.d { color:#dc2626 !important; } .pm-ibtn.d:hover { background:#fef2f2 !important; border-color:#fecaca; }
        .pm-chips { display:flex; flex-wrap:wrap; gap:6px; margin-top:10px; }
        .pm-chip { background:#f1f5f9; color:#334155; border-radius:8px; padding:3px 9px; font-size:12px; font-weight:600; }
        .pm-chip i { color:#94a3b8; margin-right:4px; }
        .pm-hist { margin-top:9px; font-size:12.5px; color:#475569; background:#fffbeb; border-radius:10px; padding:7px 10px; }
        .pm-empty { text-align:center; padding:30px 10px; border:1.5px dashed #e2e8f0; border-radius:16px; color:#94a3b8; }
        .pm-empty .ic { width:58px; height:58px; border-radius:18px; background:#eff6ff; color:#2563eb; display:inline-flex; align-items:center; justify-content:center; font-size:24px; margin-bottom:10px; }
        @media (max-width: 768px) { .pm-info, .pm-pets { grid-template-columns:1fr; } }
    </style>
    <div class="modal fade" id="profileModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content border-0 shadow-lg" style="border-radius:22px;overflow:hidden;">
                <div class="pm-head">
                    <div class="pm-av" id="pmAvatar">?</div>
                    <div style="min-width:0;">
                        <h5 class="pm-name" id="pmName"></h5>
                        <div class="pm-meta"><span class="pm-role" id="pmRole"></span><span class="pm-since"><i class="fa fa-calendar me-1"></i>Member since <span id="pmCreated"></span></span></div>
                    </div>
                    <button type="button" class="pm-x" data-bs-dismiss="modal" aria-label="Close"><i class="fa fa-xmark"></i></button>
                </div>
                <div class="modal-body p-4">
                    <div class="pm-info">
                        <div class="pm-tile"><span class="ic" style="background:#eff6ff;color:#2563eb;"><i class="fa fa-envelope"></i></span><div style="min-width:0;"><div class="k">Email</div><div class="v" id="pmEmail"></div></div></div>
                        <div class="pm-tile"><span class="ic" style="background:#f0fdf4;color:#16a34a;"><i class="fa fa-phone"></i></span><div style="min-width:0;"><div class="k">Contact</div><div class="v" id="pmContact"></div></div></div>
                        <div class="pm-tile"><span class="ic" style="background:#fff7ed;color:#ea580c;"><i class="fa fa-location-dot"></i></span><div style="min-width:0;"><div class="k">Address</div><div class="v" id="pmAddress"></div></div></div>
                    </div>

                    <div class="pm-sec">
                        <h6><i class="fa fa-paw text-primary me-2"></i>Registered Pets <span class="cnt" id="pmPetCount">0</span></h6>
                        <button class="pm-add" type="button" id="pmAddBtn" onclick="pmToggleAdd()"><i class="fa fa-plus"></i> <span>Add Pet</span></button>
                    </div>

                    <!-- Add pet form -->
                    <div class="pm-form" id="addPetCollapse">
                        <form method="POST" id="pmAddForm">
                            <input type="hidden" name="pet_user_id" id="pmUserId">
                            <div class="row g-2">
                                <div class="col-md-6"><label>Pet name <span class="req">*</span></label><input type="text" name="pet_name" class="form-control" maxlength="100" required placeholder="e.g. Max"></div>
                                <div class="col-md-6"><label>Species <span class="req">*</span></label>
                                    <select name="pet_species" class="form-select" required>
                                        <option value="" disabled selected>Choose…</option>
                                        <option>Dog</option><option>Cat</option><option>Rabbit</option><option>Bird</option><option>Hamster</option><option>Other</option>
                                    </select></div>
                                <div class="col-md-6"><label>Breed</label><input type="text" name="pet_breed" class="form-control" maxlength="100" placeholder="e.g. Aspin, Persian"></div>
                                <div class="col-md-6"><label>Gender</label>
                                    <select name="pet_gender" class="form-select"><option value="">Not specified</option><option>Male</option><option>Female</option></select></div>
                                <div class="col-md-4"><label>Birth date</label><input type="date" name="pet_dob" class="form-control" max="<?= date('Y-m-d') ?>"></div>
                                <div class="col-md-4"><label>Age (years)</label><input type="number" name="pet_age" class="form-control" min="0" max="40" value="0"></div>
                                <div class="col-md-4"><label>Weight (kg)</label><input type="number" name="pet_weight" class="form-control" step="0.1" min="0" value="0"></div>
                                <div class="col-12"><label>Medical history</label><textarea name="pet_history" class="form-control" rows="2" placeholder="Allergies, past illnesses, current medication… (optional)"></textarea></div>
                            </div>
                            <div class="d-flex justify-content-end gap-2 mt-3">
                                <button type="button" class="pm-cancel" onclick="pmToggleAdd(false)">Cancel</button>
                                <button type="submit" name="admin_add_pet" class="pm-save"><i class="fa fa-save me-1"></i> Save Pet</button>
                            </div>
                            <div class="text-muted mt-2" style="font-size:11.5px;"><i class="fa fa-circle-info me-1"></i>If you enter a birth date and leave age at 0, the age is worked out for you.</div>
                        </form>
                    </div>

                    <div id="pmPetsList"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Pet Modal -->
    <div class="modal fade" id="editPetModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius:20px;">
                <div class="modal-header border-0">
                    <h5 class="fw-bold"><i class="fa fa-edit text-primary me-2"></i>Edit Pet</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <form method="POST">
                        <input type="hidden" name="edit_pet_id" id="epId">
                        <div class="row g-2">
                            <div class="col-md-6"><label class="saas-label">Pet Name</label><input type="text" name="edit_pet_name" id="epName" class="form-control" required></div>
                            <div class="col-md-6"><label class="saas-label">Species</label><input type="text" name="edit_pet_species" id="epSpecies" class="form-control" required></div>
                            <div class="col-md-6"><label class="saas-label">Breed</label><input type="text" name="edit_pet_breed" id="epBreed" class="form-control"></div>
                            <div class="col-md-3"><label class="saas-label">Age (yrs)</label><input type="number" name="edit_pet_age" id="epAge" class="form-control" min="0"></div>
                            <div class="col-md-3"><label class="saas-label">Weight (kg)</label><input type="number" name="edit_pet_weight" id="epWeight" class="form-control" step="0.1" min="0"></div>
                            <div class="col-12"><label class="saas-label">Medical History</label><textarea name="edit_pet_history" id="epHistory" class="form-control" rows="2"></textarea></div>
                        </div>
                        <button type="submit" name="admin_edit_pet" class="btn btn-primary w-100 mt-3 fw-bold rounded-pill"><i class="fa fa-save me-1"></i> Update Pet</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        $(document).ready(function () {
            var table = $('#usersTable').DataTable({
                pageLength: 10,
                order: [[0, "desc"]],
                dom: 'rtip',
                language: { search: "", searchPlaceholder: "Search users..." }
            });
            $('#customSearch').on('keyup', function () { table.search(this.value).draw(); });
        });

        function filterUserRole(role, btn) {
            $('.role-ftab').removeClass('active');
            $(btn).addClass('active');

            var table = $('#usersTable').DataTable();
            table.column(3).search('');
            table.column(4).search('');

            if (role === 'all') {
                table.draw();
            } else if (role === 'client') {
                table.column(3).search('CLIENT|USER', true, false).draw();
            } else if (role === 'doctor') {
                table.column(3).search('DOCTOR', true, false).draw();
            } else if (role === 'staff') {
                table.column(3).search('STAFF', true, false).draw();
            } else if (role === 'clinic_owner') {
                table.column(3).search('CLINIC OWNER', true, false).draw();
            } else if (role === 'admin') {
                table.column(3).search('ADMIN', true, false).draw();
            } else if (role === 'unverified') {
                table.column(4).search('Unverified', false, false).draw();
            }
        }

        $(document).ready(function () {
            const urlParams = new URLSearchParams(window.location.search);
            const reqRole = urlParams.get('role');
            if (reqRole) {
                const targetBtn = document.querySelector(`.role-ftab[data-filter="${reqRole}"]`);
                if (targetBtn) {
                    filterUserRole(reqRole, targetBtn);
                }
            }
        });

        
            
        

        function pmToggleAdd(force) {
            var box = document.getElementById('addPetCollapse'), btn = document.getElementById('pmAddBtn');
            var show = typeof force === 'boolean' ? force : !box.classList.contains('show');
            box.classList.toggle('show', show);
            btn.classList.toggle('open', show);
            btn.querySelector('span').textContent = show ? 'Close' : 'Add Pet';
            btn.querySelector('i').className = show ? 'fa fa-xmark' : 'fa fa-plus';
            if (show) { var f = box.querySelector('input[name="pet_name"]'); setTimeout(function () { f.focus(); }, 50); }
        }

        function openProfile(u) {
            var set = function (id, val) { var el = document.getElementById(id); var v = (val || '').toString().trim(); el.textContent = v || 'Not provided'; el.classList.toggle('empty', !v); };
            document.getElementById('pmName').textContent    = u.fullname;
            document.getElementById('pmAvatar').textContent  = (u.fullname || '?').trim().charAt(0).toUpperCase();
            document.getElementById('pmRole').textContent    = (u.role || '').replace('_', ' ');
            document.getElementById('pmCreated').textContent = u.created ? new Date(String(u.created).replace(' ', 'T')).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '—';
            set('pmEmail', u.email);
            set('pmContact', u.contact && /\d/.test(u.contact) ? u.contact : '');
            set('pmAddress', u.address);
            document.getElementById('pmUserId').value = u.id;
            document.getElementById('pmPetCount').textContent = (u.pets || []).length;

            var list = document.getElementById('pmPetsList');
            if (!u.pets || u.pets.length === 0) {
                list.innerHTML = '<div class="pm-empty"><div class="ic"><i class="fa fa-paw"></i></div><div class="fw-bold text-dark">No pets registered yet</div>'
                               + '<div style="font-size:13px;">Use <b>Add Pet</b> to register this client\'s first pet.</div></div>';
            } else {
                var html = '<div class="pm-pets">';
                u.pets.forEach(function (p) {
                    var sp = (p.species || '').toLowerCase();
                    var ic = sp.indexOf('dog') > -1 ? ['fa-dog', '#2563eb', '#eff6ff'] : (sp.indexOf('cat') > -1 ? ['fa-cat', '#7c3aed', '#f5f3ff'] : ['fa-paw', '#0e7490', '#ecfeff']);
                    var age = parseInt(p.age, 10) || 0;
                    var chips = [];
                    if (p.gender) chips.push('<span class="pm-chip"><i class="fa fa-' + (p.gender === 'Female' ? 'venus' : 'mars') + '"></i>' + escHtml(p.gender) + '</span>');
                    chips.push('<span class="pm-chip"><i class="fa fa-cake-candles"></i>' + age + ' yr' + (age === 1 ? '' : 's') + '</span>');
                    if (parseFloat(p.weight) > 0) chips.push('<span class="pm-chip"><i class="fa fa-weight-scale"></i>' + parseFloat(p.weight) + ' kg</span>');
                    if (p.next_vaccination) chips.push('<span class="pm-chip"><i class="fa fa-syringe"></i>Next vaccine ' + escHtml(p.next_vaccination) + '</span>');
                    html += '<div class="pm-pet"><div class="pm-pet-top">'
                         + '<span class="pm-pet-ic" style="background:' + ic[2] + ';color:' + ic[1] + ';"><i class="fa ' + ic[0] + '"></i></span>'
                         + '<div style="min-width:0;"><div class="pm-pet-name">' + escHtml(p.name) + '</div><div class="pm-pet-sub">' + escHtml(p.species || 'Pet') + (p.breed ? ' · ' + escHtml(p.breed) : '') + '</div></div>'
                         + '<div class="pm-pet-acts">'
                         + '<button class="pm-ibtn e" type="button" title="Edit pet" onclick="editPet(' + JSON.stringify(p).replace(/"/g, '&quot;') + ')"><i class="fa fa-pen"></i></button>'
                         + '<button class="pm-ibtn d" type="button" title="Delete pet" onclick="deletePet(' + p.id + ',\'' + escHtml(p.name).replace(/'/g, '\\\'') + '\')"><i class="fa fa-trash-can"></i></button>'
                         + '</div></div>'
                         + '<div class="pm-chips">' + chips.join('') + '</div>'
                         + (p.medical_history ? '<div class="pm-hist"><i class="fa fa-notes-medical me-1"></i>' + escHtml(p.medical_history) + '</div>' : '')
                         + '</div>';
                });
                list.innerHTML = html + '</div>';
            }

            pmToggleAdd(false);
            document.getElementById('pmAddForm').reset();
            document.getElementById('pmUserId').value = u.id;
            bootstrap.Modal.getOrCreateInstance(document.getElementById('profileModal')).show();
        }

        <?php if ($profile_open): ?>
        // Reopen the owner's profile after adding / editing / deleting a pet
        window.addEventListener('load', function () { setTimeout(function () { openProfile(<?= json_encode($profile_open, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>); }, 200); });
        <?php endif; ?>

        function editPet(p) {
            document.getElementById('epId').value      = p.id;
            document.getElementById('epName').value    = p.name;
            document.getElementById('epSpecies').value = p.species;
            document.getElementById('epBreed').value   = p.breed;
            document.getElementById('epAge').value     = p.age;
            document.getElementById('epWeight').value  = p.weight;
            document.getElementById('epHistory').value = p.medical_history || '';
            new bootstrap.Modal(document.getElementById('editPetModal')).show();
        }

        function escHtml(t) {
            var d = document.createElement('div');
            d.appendChild(document.createTextNode(t || ''));
            return d.innerHTML;
        }

        function deletePet(petId, petName) {
            Swal.fire({
                title: 'Delete ' + petName + '?',
                text: "This action cannot be undone.",
                icon: 'error',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, delete pet!'
            }).then((result) => {
                if (result.isConfirmed) {
                    var f = document.getElementById('deletePetForm');
                    document.getElementById('deletePetId').value = petId;
                    f.submit();
                }
            });
        }
    </script>

    <!-- RBAC Permissions Modal -->
    <div class="modal fade" id="rbacModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content" style="border-radius: 20px; border: none;">
                <form method="POST">
                    <div class="modal-header modal-header-saas border-0 pb-0">
                        <div class="icon-box"><i class="fa fa-shield-alt"></i></div>
                        <div>
                            <h4 class="fw-bold mb-1">Dynamic RBAC Permissions</h4>
                            <p class="text-muted small mb-0">Configure role-based access control rules</p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body modal-body-saas pt-4">
                        <div class="mb-4">
                            <label class="saas-label">Select User to Configure</label>
                            <select class="form-select" name="rbac_user" id="rbacUserSelect" onchange="loadUserPermissions(this.value)" required>
                                <option value="" disabled selected>Select a user...</option>
                                <?php foreach ($all_rbac_users as $u): ?>
                                    <option value="<?= htmlspecialchars($u['id']) ?>"><?= htmlspecialchars($u['fullname']) ?> (<?= htmlspecialchars($u['role']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div id="rbacChecklistContainer" style="display: none;">
                            <div class="row g-4">
                                <?php foreach ($rbac_perms_by_module as $module_name => $perms): ?>
                                    <div class="col-md-6 col-lg-4">
                                        <div class="card h-100 border-0 shadow-sm" style="border-radius: 12px; background: #f8fafc;">
                                            <div class="card-header bg-transparent border-0 pt-3 pb-0">
                                                <h6 class="fw-bold text-primary mb-0"><i class="fa fa-cube me-2"></i><?= htmlspecialchars($module_name) ?></h6>
                                            </div>
                                            <div class="card-body">
                                                <?php foreach ($perms as $p): ?>
                                                    <div class="form-check form-switch mb-2">
                                                        <input class="form-check-input rbac-perm-checkbox" type="checkbox" name="permissions[]" 
                                                            value="<?= htmlspecialchars($p['permission_key']) ?>" 
                                                            id="perm_<?= htmlspecialchars($p['permission_key']) ?>">
                                                        <label class="form-check-label" for="perm_<?= htmlspecialchars($p['permission_key']) ?>">
                                                            <span class="fw-semibold text-dark" style="font-size: 13px;"><?= htmlspecialchars($p['permission_name']) ?></span>
                                                            <br>
                                                            <small class="text-muted" style="font-size: 11px;"><?= htmlspecialchars($p['description']) ?></small>
                                                        </label>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-0 pt-0 pb-4 px-4">
                        <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="save_rbac" class="btn btn-primary rounded-pill px-4 fw-bold">Save User Permissions</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        const userPermissions = <?= json_encode($rbac_user_perms) ?>;
        
        function loadUserPermissions(userId) {
            const container = document.getElementById('rbacChecklistContainer');
            container.style.display = 'block';
            
            // Uncheck all first
            document.querySelectorAll('.rbac-perm-checkbox').forEach(cb => cb.checked = false);
            
            // Check granted permissions
            if (userPermissions[userId]) {
                userPermissions[userId].forEach(permKey => {
                    const cb = document.getElementById('perm_' + permKey);
                    if (cb) cb.checked = true;
                });
            }
        }

        function toggleAddAccRbac() {
            const role = document.getElementById('addAccRole').value;
            const container = document.getElementById('addAccRbacContainer');
            if (['staff', 'doctor'].includes(role)) {
                container.style.display = 'block';
            } else {
                container.style.display = 'none';
                // Uncheck all when hidden
                container.querySelectorAll('input[type="checkbox"]').forEach(cb => cb.checked = false);
            }
        }
        
        // Initialize state on load
        document.addEventListener('DOMContentLoaded', () => {
            toggleAddAccRbac();
        });
    </script>

    <!-- Hidden delete pet form -->
    <form method="POST" id="deletePetForm" style="display:none;">
        <input type="hidden" name="admin_delete_pet" value="1">
        <input type="hidden" name="delete_pet_id" id="deletePetId">
    </form>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        function confirmFormSubmit(event, formElement, message) {
            event.preventDefault();
            Swal.fire({
                title: 'Confirm Action',
                text: message || "Are you sure you want to proceed?",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#2563eb',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Yes, proceed!'
            }).then((result) => {
                if (result.isConfirmed) {
                    formElement.submit();
                }
            });
            return false;
        }

        // Apply sweetalert to modal form submits for major actions
        document.addEventListener('DOMContentLoaded', () => {
            const formsToConfirm = [
                { selector: 'button[name="add_walkin"]', msg: 'Register this new account?' },
                { selector: 'button[name="edit_user"]', msg: 'Save changes to this user?' },
                { selector: 'button[name="save_rbac"]', msg: 'Update user permissions?' },
                { selector: 'button[name="admin_add_pet"]', msg: 'Register this new pet?' },
                { selector: 'button[name="admin_edit_pet"]', msg: 'Save pet changes?' }
            ];

            formsToConfirm.forEach(item => {
                const buttons = document.querySelectorAll(item.selector);
                buttons.forEach(btn => {
                    const form = btn.closest('form');
                    if (form && !form.hasAttribute('data-swal-bound')) {
                        form.setAttribute('data-swal-bound', 'true');
                        form.addEventListener('submit', function(e) {
                            // If HTML5 validation fails, don't show sweetalert
                            if (!this.checkValidity()) return;
                            
                            e.preventDefault();
                            Swal.fire({
                                title: 'Confirm Action',
                                text: item.msg,
                                icon: 'question',
                                showCancelButton: true,
                                confirmButtonColor: '#2563eb',
                                cancelButtonColor: '#64748b',
                                confirmButtonText: 'Yes, proceed!'
                            }).then((result) => {
                                if (result.isConfirmed) {
                                    // Append button name to form data since submit() doesn't include it
                                    const hiddenInput = document.createElement('input');
                                    hiddenInput.type = 'hidden';
                                    hiddenInput.name = btn.name;
                                    hiddenInput.value = '1';
                                    form.appendChild(hiddenInput);
                                    form.submit();
                                }
                            });
                        });
                    }
                });
            });
        });
    </script>
    <script src="mousetrail.js"></script>
</body>
</html>
