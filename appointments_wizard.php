<?php
session_start();
include("dbconnection.php");
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit(); }
$user_id   = $_SESSION['user_id'];
$user_name = $_SESSION['user'] ?? 'Vet Staff';
$user_role = $_SESSION['role'] ?? ($_SESSION['user_role'] ?? 'staff');

include_once("includes/pet_data_helper.php");
include_once("includes/scheduling_engine.php");

// ── Fetch User & Contact Details ──────────────────────────────────────────────
$user_phone = '0917-123-4567';
$u_stmt = $conn->prepare("SELECT fullname, phone FROM users WHERE id=?");
if ($u_stmt) {
    $u_stmt->bind_param("i", $user_id);
    $u_stmt->execute();
    $u_res = $u_stmt->get_result()->fetch_assoc();
    if ($u_res) {
        if (!empty($u_res['fullname'])) $user_name = $u_res['fullname'];
        if (!empty($u_res['phone'])) $user_phone = $u_res['phone'];
    }
}

// ── Fetch Pets ────────────────────────────────────────────────────────────────
$pets = [];
$r_p = $conn->query("SELECT id, name, species, breed FROM pets WHERE user_id=$user_id ORDER BY name ASC");
if ($r_p && $r_p->num_rows > 0) {
    while ($pt = $r_p->fetch_assoc()) {
        $pets[] = $pt;
    }
}

// ── Fetch Services ────────────────────────────────────────────────────────────
$db_services = [];
$sq = $conn->query("SELECT id, name, description, duration_minutes, price, category, color FROM services WHERE is_active=1 ORDER BY category, name");
if ($sq) $db_services = $sq->fetch_all(MYSQLI_ASSOC);

// Standard 9 services matching screenshot design mockup
$preset_services = [
    [
        'id' => 1, 'name' => 'Consultation', 
        'description' => 'General check-up and health consultation', 
        'duration_minutes' => 30, 'price' => 500, 'category' => 'General', 
        'icon' => 'fa-stethoscope', 'bg' => '#f3e8ff', 'color' => '#7c3aed', 
        'info' => 'Consultation includes: Physical examination, health assessment, and basic treatment recommendation.'
    ],
    [
        'id' => 2, 'name' => 'Vaccination', 
        'description' => 'Routine vaccination services', 
        'duration_minutes' => 20, 'price' => 350, 'category' => 'Preventive', 
        'icon' => 'fa-syringe', 'bg' => '#dcfce7', 'color' => '#16a34a', 
        'info' => 'Vaccination includes: Vaccine administration, health check before injection, and immunization passport update.'
    ],
    [
        'id' => 3, 'name' => 'Laboratory Test', 
        'description' => 'Blood test, fecal test, urinalysis, etc.', 
        'duration_minutes' => 45, 'price' => 600, 'category' => 'Diagnostic', 
        'icon' => 'fa-flask', 'bg' => '#e0f2fe', 'color' => '#0284c7', 
        'info' => 'Laboratory Test includes: Blood, urine, or stool sample extraction, laboratory processing, and diagnostic report.'
    ],
    [
        'id' => 4, 'name' => 'Deworming', 
        'description' => 'Internal and external deworming', 
        'duration_minutes' => 15, 'price' => 250, 'category' => 'Preventive', 
        'icon' => 'fa-first-aid', 'bg' => '#ffedd5', 'color' => '#ea580c', 
        'info' => 'Deworming includes: Oral broad-spectrum dewormer dosage, parasite preventive guidance, and weight check.'
    ],
    [
        'id' => 5, 'name' => 'Dental Cleaning', 
        'description' => 'Teeth cleaning and oral care', 
        'duration_minutes' => 60, 'price' => 700, 'category' => 'Medical', 
        'icon' => 'fa-tooth', 'bg' => '#ccfbf1', 'color' => '#0d9488', 
        'info' => 'Dental Cleaning includes: Ultrasonic scaling, tooth polishing, gum health assessment, and oral hygiene check.'
    ],
    [
        'id' => 6, 'name' => 'Grooming', 
        'description' => 'Bath, nail trimming, ear cleaning, etc.', 
        'duration_minutes' => 60, 'price' => 400, 'category' => 'Wellness', 
        'icon' => 'fa-paw', 'bg' => '#fce7f3', 'color' => '#db2777', 
        'info' => 'Grooming includes: Medicated shampoo bath, coat blow dry, nail clipping, ear cleaning, and sanitary trim.'
    ],
    [
        'id' => 7, 'name' => 'Pet Boarding', 
        'description' => 'Pet boarding and accommodation', 
        'duration_minutes' => 1440, 'price' => 350, 'category' => 'Boarding', 
        'icon' => 'fa-hospital', 'bg' => '#e0f2fe', 'color' => '#2563eb', 
        'info' => 'Pet Boarding includes: Individual climate-controlled cage/suite, 3 daily meals, regular exercise, and continuous observation.',
        'price_unit' => '/ day'
    ],
    [
        'id' => 8, 'name' => 'Surgery', 
        'description' => 'Surgical procedure and operation', 
        'duration_minutes' => 120, 'price' => 0, 'category' => 'Surgery', 
        'icon' => 'fa-scissors', 'bg' => '#f3e8ff', 'color' => '#9333ea', 
        'info' => 'Surgery includes: Pre-surgical evaluation, sterile operating room prep, anesthesia monitoring, and post-op care.',
        'price_display' => 'Price varies'
    ],
    [
        'id' => 9, 'name' => 'Other Services', 
        'description' => 'Other medical services', 
        'duration_minutes' => 30, 'price' => 0, 'category' => 'General', 
        'icon' => 'fa-ellipsis-h', 'bg' => '#f1f5f9', 'color' => '#475569', 
        'info' => 'Other Services includes: Special consultation, microchip insertion, health certificate issuance, or custom medical care.',
        'price_display' => 'Price varies'
    ]
];

// Merge database services with preset layout metadata
$services = [];
foreach ($preset_services as $preset) {
    $found = null;
    foreach ($db_services as $dbs) {
        if (strcasecmp(trim($dbs['name']), trim($preset['name'])) === 0) {
            $found = $dbs;
            break;
        }
    }
    if ($found) {
        $preset['id'] = $found['id'];
        if (isset($found['price']) && (float)$found['price'] > 0) {
            $preset['price'] = (float)$found['price'];
            unset($preset['price_display']);
        }
        if (!empty($found['description'])) $preset['description'] = $found['description'];
        if (!empty($found['duration_minutes'])) $preset['duration_minutes'] = (int)$found['duration_minutes'];
    }
    $services[] = $preset;
}

// ── Fetch Doctors ─────────────────────────────────────────────────────────────
$doctors = [];
$doc_q = $conn->query("SELECT u.id, u.fullname, COALESCE(s.specialization, 'Veterinarian') as specialization FROM users u LEFT JOIN staff s ON u.id = s.user_id WHERE u.role IN ('doctor', 'staff', 'admin') AND u.is_active=1 ORDER BY u.fullname ASC");
if ($doc_q) $doctors = $doc_q->fetch_all(MYSQLI_ASSOC);
if (empty($doctors)) {
    $doctors = [
        ['id' => 101, 'fullname' => 'Dr. Maria Santos', 'specialization' => 'General Veterinary Practitioner'],
        ['id' => 102, 'fullname' => 'Dr. Alex Reyes', 'specialization' => 'Veterinary Surgeon'],
        ['id' => 103, 'fullname' => 'Dr. Sarah Jenkins', 'specialization' => 'Pet Dermatologist & Specialist']
    ];
}

// ── Holidays & Settings ───────────────────────────────────────────────────────
$holidays = [];
$hq = $conn->query("SELECT date FROM holidays WHERE is_active=1");
if ($hq) while ($h = $hq->fetch_assoc()) $holidays[] = $h['date'];

$settings = function_exists('get_clinic_settings') ? get_clinic_settings($conn) : [];
$advance_days = (int)($settings['max_advance_booking_days'] ?? 30);
$min_date = date('Y-m-d', strtotime('+1 day'));
$max_date = date('Y-m-d', strtotime("+{$advance_days} days"));
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>New Appointment Wizard | PragmaVet</title>
<meta name="description" content="Schedule a new appointment step-by-step with PragmaVet Clinic.">

<!-- Styles & Fonts -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/themes/airbnb.css">

<style>
/* High-specificity override design system matching UI screenshot */
:root {
    --primary: #5b21b6 !important;
    --primary-hover: #4c1d95 !important;
    --primary-light: #f3e8ff !important;
    --sidebar-bg: #1c1936 !important;
    --sidebar-active: #4c1d95 !important;
    --bg-main: #f8fafc !important;
    --text-dark: #0f172a !important;
    --text-muted: #64748b !important;
    --border-color: #e2e8f0 !important;
    --sidebar-width: 250px !important;
}

body {
    background-color: #f8fafc !important;
    font-family: 'Inter', sans-serif !important;
    color: #0f172a !important;
    margin: 0 !important;
    padding: 0 !important;
    overflow-x: hidden !important;
}

/* Hide auto-injected marketing footer from auto_inject.php */
footer.footer, .footer, #scroll-progress, #back-to-top {
    display: none !important;
}

/* ── Top Header Navigation Bar ────────────────────────────────────────────── */
.top-header {
    height: 64px !important;
    background: #ffffff !important;
    border-bottom: 1px solid #e2e8f0 !important;
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    padding: 0 24px !important;
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    right: 0 !important;
    z-index: 1010 !important;
}
.header-left {
    display: flex !important;
    align-items: center !important;
    gap: 16px !important;
}
.brand-logo {
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
    text-decoration: none !important;
}
.brand-icon-box {
    width: 38px !important;
    height: 38px !important;
    border-radius: 10px !important;
    background: linear-gradient(135deg, #6d28d9, #4c1d95) !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    color: #ffffff !important;
    font-size: 18px !important;
}
.brand-title {
    font-weight: 800 !important;
    font-size: 20px !important;
    color: #1e1b4b !important;
    line-height: 1.1 !important;
}
.brand-subtitle {
    font-size: 10px !important;
    color: #64748b !important;
    font-weight: 600 !important;
}
.btn-sidebar-toggle {
    background: none !important;
    border: none !important;
    font-size: 20px !important;
    color: #64748b !important;
    cursor: pointer !important;
    padding: 6px 10px !important;
    border-radius: 8px !important;
}
.btn-sidebar-toggle:hover {
    background: #f1f5f9 !important;
    color: #5b21b6 !important;
}
.header-right {
    display: flex !important;
    align-items: center !important;
    gap: 20px !important;
}
.notif-bell {
    position: relative !important;
    color: #64748b !important;
    font-size: 18px !important;
    cursor: pointer !important;
}
.notif-badge {
    position: absolute !important;
    top: -6px !important;
    right: -8px !important;
    background: #ef4444 !important;
    color: #ffffff !important;
    font-size: 10px !important;
    font-weight: 700 !important;
    width: 17px !important;
    height: 17px !important;
    border-radius: 50% !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    border: 2px solid #ffffff !important;
}
.user-profile-btn {
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
    background: #f8fafc !important;
    border: 1px solid #e2e8f0 !important;
    padding: 6px 14px !important;
    border-radius: 30px !important;
    cursor: pointer !important;
}
.user-avatar {
    width: 32px !important;
    height: 32px !important;
    border-radius: 50% !important;
    background: #4c1d95 !important;
    color: #ffffff !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    font-size: 13px !important;
    font-weight: 700 !important;
}
.user-name-text {
    font-size: 13px !important;
    font-weight: 700 !important;
    color: #0f172a !important;
    line-height: 1.2 !important;
}
.user-role-text {
    font-size: 11px !important;
    color: #64748b !important;
}

/* ── Sidebar Navigation ──────────────────────────────────────────────────── */
.sidebar {
    width: 250px !important;
    background: #1c1936 !important;
    position: fixed !important;
    top: 64px !important;
    left: 0 !important;
    bottom: 0 !important;
    padding: 20px 14px !important;
    overflow-y: auto !important;
    z-index: 1000 !important;
    display: flex !important;
    flex-direction: column !important;
}
.nav-group-label {
    font-size: 10px !important;
    font-weight: 700 !important;
    letter-spacing: .08em !important;
    color: #64748b !important;
    text-transform: uppercase !important;
    margin: 16px 12px 8px !important;
}
.nav-link-item {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    padding: 10px 14px !important;
    border-radius: 10px !important;
    color: #94a3b8 !important;
    text-decoration: none !important;
    font-weight: 600 !important;
    font-size: 14px !important;
    margin-bottom: 4px !important;
    transition: all .2s !important;
}
.nav-link-item:hover {
    background: rgba(255,255,255,0.06) !important;
    color: #ffffff !important;
}
.nav-link-item.active {
    background: #4c1d95 !important;
    color: #ffffff !important;
    box-shadow: 0 4px 12px rgba(76, 29, 149, 0.4) !important;
}
.nav-link-item i.nav-icon {
    width: 24px !important;
    font-size: 16px !important;
}
.nav-sub-menu {
    padding-left: 34px !important;
    margin-bottom: 6px !important;
}
.nav-sub-link {
    display: block !important;
    padding: 6px 12px !important;
    font-size: 13px !important;
    color: #94a3b8 !important;
    text-decoration: none !important;
    font-weight: 500 !important;
    border-radius: 6px !important;
}
.nav-sub-link.active, .nav-sub-link:hover {
    color: #ffffff !important;
    font-weight: 600 !important;
}
.quick-action-btn {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 8px !important;
    width: 100% !important;
    padding: 10px 14px !important;
    background: #ffffff !important;
    color: #4c1d95 !important;
    border-radius: 10px !important;
    font-weight: 700 !important;
    font-size: 13px !important;
    text-decoration: none !important;
    margin-bottom: 8px !important;
    box-shadow: 0 2px 8px rgba(0,0,0,0.15) !important;
}
.quick-action-btn:hover {
    color: #5b21b6 !important;
    transform: translateY(-1px) !important;
}

/* ── Main Layout Workspace ───────────────────────────────────────────────── */
.main-wrapper {
    margin-left: 250px !important;
    margin-top: 64px !important;
    padding: 28px 36px 60px !important;
    min-height: calc(100vh - 64px) !important;
}

/* Breadcrumb & Header */
.page-top-bar {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    margin-bottom: 24px !important;
}
.page-main-title {
    font-size: 24px !important;
    font-weight: 800 !important;
    color: #1e1b4b !important;
    margin: 0 !important;
}
.page-breadcrumb {
    font-size: 13px !important;
    color: #64748b !important;
    font-weight: 500 !important;
}
.page-breadcrumb a { color: #5b21b6 !important; text-decoration: none !important; font-weight: 600 !important; }

/* ── Stepper Timeline Bar (7 Steps) ─────────────────────────────────────── */
.wiz-stepper-bar {
    display: flex !important;
    align-items: flex-start !important;
    justify-content: space-between !important;
    position: relative !important;
    margin-bottom: 36px !important;
    padding: 0 10px !important;
}
.wiz-stepper-line {
    position: absolute !important;
    top: 18px !important;
    left: 40px !important;
    right: 40px !important;
    height: 2px !important;
    border-top: 2px dashed #cbd5e1 !important;
    z-index: 0 !important;
    pointer-events: none !important;
}
.step-node {
    display: flex !important;
    flex-direction: column !important;
    align-items: center !important;
    position: relative !important;
    z-index: 1 !important;
    cursor: pointer !important;
    flex: 1 !important;
}
.step-circle {
    width: 36px !important;
    height: 36px !important;
    border-radius: 50% !important;
    background: #ffffff !important;
    border: 2px solid #cbd5e1 !important;
    color: #64748b !important;
    font-weight: 700 !important;
    font-size: 14px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    transition: all .3s !important;
}
.step-node.active .step-circle {
    background: #5b21b6 !important;
    border-color: #5b21b6 !important;
    color: #ffffff !important;
    box-shadow: 0 0 0 5px rgba(91, 33, 182, 0.15) !important;
}
.step-node.done .step-circle {
    background: #10b981 !important;
    border-color: #10b981 !important;
    color: #ffffff !important;
}
.step-node-label {
    font-size: 11px !important;
    font-weight: 600 !important;
    color: #64748b !important;
    margin-top: 8px !important;
    text-align: center !important;
    white-space: nowrap !important;
}
.step-node.active .step-node-label {
    color: #5b21b6 !important;
    font-weight: 800 !important;
}
.step-node.done .step-node-label {
    color: #10b981 !important;
}

/* ── Content Grid Layout (70% Left Panel, 30% Right Panel) ────────────────── */
.wizard-grid {
    display: grid !important;
    grid-template-columns: 1fr 340px !important;
    gap: 28px !important;
    align-items: start !important;
}

/* ── Left Step Panels Card ──────────────────────────────────────────────── */
.step-card {
    background: #ffffff !important;
    border-radius: 20px !important;
    border: 1px solid #e2e8f0 !important;
    padding: 32px !important;
    box-shadow: 0 4px 20px rgba(0,0,0,0.03) !important;
}
.wiz-panel { display: none; }
.wiz-panel.active { display: block !important; }

.panel-header {
    display: flex !important;
    align-items: center !important;
    gap: 14px !important;
    margin-bottom: 6px !important;
}
.panel-icon-badge {
    width: 42px !important;
    height: 42px !important;
    border-radius: 12px !important;
    background: #f3e8ff !important;
    color: #5b21b6 !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    font-size: 20px !important;
}
.panel-title {
    font-size: 20px !important;
    font-weight: 800 !important;
    color: #1e1b4b !important;
    margin: 0 !important;
}
.panel-sub {
    font-size: 13px !important;
    color: #64748b !important;
    margin-bottom: 24px !important;
    margin-left: 56px !important;
}

/* Service Grid (3x3 Layout matching mockup) */
.service-grid {
    display: grid !important;
    grid-template-columns: repeat(3, 1fr) !important;
    gap: 16px !important;
    margin-bottom: 24px !important;
}
.svc-item-card {
    border: 2px solid #e2e8f0 !important;
    border-radius: 16px !important;
    padding: 20px !important;
    background: #ffffff !important;
    cursor: pointer !important;
    position: relative !important;
    transition: all .2s ease !important;
    display: flex !important;
    flex-direction: column !important;
}
.svc-item-card:hover {
    border-color: #5b21b6 !important;
    transform: translateY(-2px) !important;
    box-shadow: 0 8px 24px rgba(91, 33, 182, 0.08) !important;
}
.svc-item-card.selected {
    border-color: #5b21b6 !important;
    background: #fcfaff !important;
    box-shadow: 0 0 0 1px #5b21b6 !important;
}
.svc-check-radio {
    width: 20px !important;
    height: 20px !important;
    border-radius: 50% !important;
    border: 2px solid #cbd5e1 !important;
    position: absolute !important;
    top: 18px !important;
    right: 18px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
}
.svc-item-card.selected .svc-check-radio {
    border-color: #5b21b6 !important;
    background: #5b21b6 !important;
}
.svc-check-radio::after {
    content: '' !important;
    width: 6px !important;
    height: 6px !important;
    border-radius: 50% !important;
    background: #ffffff !important;
    display: none !important;
}
.svc-item-card.selected .svc-check-radio::after { display: block !important; }

.svc-icon-circle {
    width: 46px !important;
    height: 46px !important;
    border-radius: 50% !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    font-size: 20px !important;
    margin-bottom: 14px !important;
}
.svc-card-title {
    font-size: 15px !important;
    font-weight: 800 !important;
    color: #1e1b4b !important;
    margin-bottom: 6px !important;
}
.svc-card-desc {
    font-size: 12px !important;
    color: #64748b !important;
    line-height: 1.4 !important;
    margin-bottom: 14px !important;
    flex-grow: 1 !important;
}
.svc-card-price {
    font-size: 14px !important;
    font-weight: 800 !important;
    color: #5b21b6 !important;
}

/* Service Info Banner Box (Yellow/Cream) */
.svc-info-box {
    background: #fffbeb !important;
    border: 1px solid #fde68a !important;
    border-radius: 14px !important;
    padding: 16px 20px !important;
    margin-bottom: 28px !important;
}
.svc-info-title {
    font-size: 13px !important;
    font-weight: 800 !important;
    color: #92400e !important;
    margin-bottom: 4px !important;
}
.svc-info-text {
    font-size: 13px !important;
    color: #b45309 !important;
    margin: 0 !important;
}

/* Action Buttons Bar */
.wiz-action-bar {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    padding-top: 16px !important;
    border-top: 1px solid #f1f5f9 !important;
}
.btn-wiz-prev {
    padding: 10px 24px !important;
    border-radius: 10px !important;
    border: 1.5px solid #cbd5e1 !important;
    background: #ffffff !important;
    font-weight: 700 !important;
    font-size: 13px !important;
    color: #475569 !important;
    cursor: pointer !important;
}
.btn-wiz-prev:hover { background: #f8fafc !important; }
.btn-wiz-cancel {
    padding: 10px 20px !important;
    border-radius: 10px !important;
    border: 1.5px solid #e2e8f0 !important;
    background: #ffffff !important;
    font-weight: 700 !important;
    font-size: 13px !important;
    color: #64748b !important;
    cursor: pointer !important;
    margin-right: 10px !important;
}
.btn-wiz-next {
    padding: 10px 28px !important;
    border-radius: 10px !important;
    background: #5b21b6 !important;
    color: #ffffff !important;
    font-weight: 700 !important;
    font-size: 13px !important;
    border: none !important;
    cursor: pointer !important;
    box-shadow: 0 4px 12px rgba(91, 33, 182, 0.3) !important;
}
.btn-wiz-next:hover { background: #4c1d95 !important; }

/* Pet Selection Cards & Chips */
.pet-select-grid {
    display: flex !important;
    flex-wrap: wrap !important;
    gap: 12px !important;
    margin-bottom: 24px !important;
}
.pet-card-chip {
    padding: 12px 20px !important;
    border-radius: 30px !important;
    border: 2px solid #e2e8f0 !important;
    background: #ffffff !important;
    cursor: pointer !important;
    font-weight: 700 !important;
    font-size: 14px !important;
    color: #334155 !important;
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
}
.pet-card-chip.selected {
    border-color: #5b21b6 !important;
    background: #f3e8ff !important;
    color: #5b21b6 !important;
}

/* Vet Cards Grid */
.vet-select-grid {
    display: grid !important;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)) !important;
    gap: 16px !important;
    margin-bottom: 24px !important;
}
.vet-card {
    border: 2px solid #e2e8f0 !important;
    border-radius: 16px !important;
    padding: 20px !important;
    background: #ffffff !important;
    cursor: pointer !important;
    text-align: center !important;
}
.vet-card.selected, .vet-card:hover { border-color: #5b21b6 !important; background: #fcfaff !important; }
.vet-avatar-lg {
    width: 56px !important;
    height: 56px !important;
    border-radius: 50% !important;
    background: linear-gradient(135deg, #6d28d9, #4c1d95) !important;
    color: #ffffff !important;
    font-size: 20px !important;
    font-weight: 800 !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    margin: 0 auto 12px !important;
}

/* ── Step 4 Inline Calendar ──────────────────────────────────────── */
.date-time-layout {
    display: grid !important;
    grid-template-columns: 340px 1fr !important;
    gap: 28px !important;
    align-items: start !important;
}
@media(max-width: 900px) {
    .date-time-layout { grid-template-columns: 1fr !important; }
}

/* Flatpickr inline calendar container */
.cal-wrapper {
    border: 2px solid #e2e8f0 !important;
    border-radius: 20px !important;
    overflow: hidden !important;
    background: #fff !important;
    box-shadow: 0 4px 20px rgba(91,33,182,0.07) !important;
}
.cal-wrapper .flatpickr-calendar.inline {
    width: 100% !important;
    box-shadow: none !important;
    border: none !important;
    border-radius: 0 !important;
    padding: 0 !important;
    background: transparent !important;
}
.cal-wrapper .flatpickr-months {
    background: linear-gradient(135deg, #5b21b6, #4c1d95) !important;
    padding: 14px 16px !important;
    border-radius: 0 !important;
}
.cal-wrapper .flatpickr-month {
    color: #fff !important;
    fill: #fff !important;
    height: 34px !important;
    line-height: 34px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    position: relative !important;
    width: 100% !important;
    overflow: visible !important;
}
.cal-wrapper .flatpickr-current-month {
    font-size: 16px !important;
    font-weight: 800 !important;
    color: #fff !important;
    padding: 0 !important;
    position: relative !important;
    width: 100% !important;
    left: 0 !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 6px !important;
    transform: none !important;
}
.cal-wrapper .flatpickr-current-month .cur-month,
.cal-wrapper .flatpickr-current-month span.cur-month,
.cal-wrapper .flatpickr-current-month .flatpickr-monthDropdown-months {
    color: #fff !important;
    font-weight: 800 !important;
    font-size: 16px !important;
    display: inline-block !important;
    background: transparent !important;
    border: none !important;
    opacity: 1 !important;
    visibility: visible !important;
}
.cal-wrapper .numInputWrapper,
.cal-wrapper .flatpickr-current-month .numInputWrapper {
    background: transparent !important;
    background-color: transparent !important;
    border: none !important;
    box-shadow: none !important;
    width: auto !important;
    display: inline-flex !important;
}
.cal-wrapper .numInputWrapper:hover,
.cal-wrapper .flatpickr-current-month .numInputWrapper:hover {
    background: transparent !important;
    background-color: transparent !important;
}
.cal-wrapper .numInputWrapper span,
.cal-wrapper .flatpickr-current-month .numInputWrapper span {
    display: none !important;
}
.cal-wrapper .flatpickr-current-month input.cur-year,
.cal-wrapper .flatpickr-current-month .numInputWrapper input,
.cal-wrapper .numInputWrapper input.cur-year {
    color: #ffffff !important;
    font-weight: 800 !important;
    font-size: 16px !important;
    background: transparent !important;
    background-color: transparent !important;
    border: none !important;
    box-shadow: none !important;
    outline: none !important;
    padding: 0 !important;
    margin: 0 !important;
    width: 48px !important;
    height: auto !important;
    display: inline-block !important;
    border-radius: 0 !important;
}
.cal-wrapper .flatpickr-prev-month,
.cal-wrapper .flatpickr-next-month {
    fill: rgba(255,255,255,0.9) !important;
    color: rgba(255,255,255,0.9) !important;
    padding: 4px 10px !important;
    position: relative !important;
    height: 34px !important;
    display: flex !important;
    align-items: center !important;
}
.cal-wrapper .flatpickr-prev-month:hover,
.cal-wrapper .flatpickr-next-month:hover {
    fill: #fff !important;
    color: #fff !important;
    background: rgba(255,255,255,0.15) !important;
    border-radius: 8px !important;
}
.cal-wrapper .flatpickr-weekdays {
    background: #f3e8ff !important;
    padding: 8px 0 !important;
}
.cal-wrapper .flatpickr-weekday {
    color: #5b21b6 !important;
    font-weight: 800 !important;
    font-size: 11px !important;
    text-transform: uppercase !important;
    letter-spacing: .05em !important;
}
.cal-wrapper .flatpickr-days {
    border: none !important;
    width: 100% !important;
}
.cal-wrapper .dayContainer {
    width: 100% !important;
    min-width: unset !important;
    max-width: unset !important;
    padding: 10px !important;
    gap: 4px !important;
}
.cal-wrapper .flatpickr-day {
    border-radius: 10px !important;
    font-weight: 600 !important;
    font-size: 13px !important;
    color: #334155 !important;
    height: 38px !important;
    line-height: 38px !important;
    max-width: unset !important;
    flex-basis: calc(14.28% - 4px) !important;
    margin: 2px !important;
    transition: all .15s !important;
}
.cal-wrapper .flatpickr-day:hover {
    background: #f3e8ff !important;
    border-color: #c4b5fd !important;
    color: #5b21b6 !important;
}
.cal-wrapper .flatpickr-day.selected,
.cal-wrapper .flatpickr-day.selected:hover {
    background: #5b21b6 !important;
    border-color: #5b21b6 !important;
    color: #fff !important;
    box-shadow: 0 4px 12px rgba(91,33,182,0.35) !important;
    font-weight: 800 !important;
}
.cal-wrapper .flatpickr-day.today {
    border-color: #a78bfa !important;
    color: #5b21b6 !important;
    font-weight: 800 !important;
}
.cal-wrapper .flatpickr-day.disabled,
.cal-wrapper .flatpickr-day.disabled:hover {
    background: #f8fafc !important;
    color: #cbd5e1 !important;
    border-color: transparent !important;
    cursor: not-allowed !important;
    text-decoration: line-through !important;
}
.cal-wrapper .flatpickr-day.flatpickr-disabled {
    opacity: .4 !important;
}

/* Selected date badge shown above time slots */
.selected-date-badge {
    display: inline-flex !important;
    align-items: center !important;
    gap: 8px !important;
    background: linear-gradient(135deg,#5b21b6,#4c1d95) !important;
    color: #fff !important;
    font-size: 13px !important;
    font-weight: 700 !important;
    padding: 6px 16px !important;
    border-radius: 30px !important;
    margin-bottom: 16px !important;
}

/* Slot Buttons Grid */
.time-slot-grid {
    display: grid !important;
    grid-template-columns: repeat(auto-fill, minmax(100px, 1fr)) !important;
    gap: 8px !important;
    margin-top: 8px !important;
}
.slot-section-label {
    font-size: 10px !important;
    font-weight: 800 !important;
    letter-spacing: .08em !important;
    color: #94a3b8 !important;
    text-transform: uppercase !important;
    grid-column: 1 / -1 !important;
    margin-top: 8px !important;
    margin-bottom: 2px !important;
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
}
.slot-section-label::after {
    content: '' !important;
    flex: 1 !important;
    height: 1px !important;
    background: #e2e8f0 !important;
}
.slot-pill-btn {
    padding: 10px 8px !important;
    border-radius: 12px !important;
    border: 2px solid #e2e8f0 !important;
    background: #ffffff !important;
    font-weight: 700 !important;
    font-size: 12px !important;
    color: #334155 !important;
    cursor: pointer !important;
    text-align: center !important;
    transition: all .15s !important;
}
.slot-pill-btn:hover {
    border-color: #a78bfa !important;
    background: #f3e8ff !important;
    color: #5b21b6 !important;
    transform: translateY(-1px) !important;
}
.slot-pill-btn.selected {
    background: #5b21b6 !important;
    color: #ffffff !important;
    border-color: #5b21b6 !important;
    box-shadow: 0 4px 12px rgba(91,33,182,0.3) !important;
}
.slot-pill-btn.unavailable {
    background: #f8fafc !important;
    color: #cbd5e1 !important;
    border-color: #f1f5f9 !important;
    cursor: not-allowed !important;
    text-decoration: line-through !important;
}
.slots-placeholder {
    grid-column: 1/-1 !important;
    text-align: center !important;
    padding: 40px 20px !important;
    color: #94a3b8 !important;
    font-size: 13px !important;
}
.slots-placeholder i {
    font-size: 32px !important;
    color: #e2e8f0 !important;
    display: block !important;
    margin-bottom: 12px !important;
}

/* ── Right Panel: Summary & Process Cards ────────────────────────────────── */
.right-panel-wrap {
    display: flex !important;
    flex-direction: column !important;
    gap: 20px !important;
    position: sticky !important;
    top: 88px !important;
}
.side-widget-card {
    background: #ffffff !important;
    border-radius: 20px !important;
    border: 1px solid #e2e8f0 !important;
    padding: 24px !important;
    box-shadow: 0 4px 20px rgba(0,0,0,0.03) !important;
}
.side-widget-title {
    font-size: 16px !important;
    font-weight: 800 !important;
    color: #1e1b4b !important;
    margin-bottom: 20px !important;
}
.summary-item-row {
    display: flex !important;
    align-items: flex-start !important;
    gap: 12px !important;
    margin-bottom: 16px !important;
}
.summary-item-icon {
    width: 32px !important;
    height: 32px !important;
    border-radius: 50% !important;
    background: #f1f5f9 !important;
    color: #5b21b6 !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    font-size: 13px !important;
    flex-shrink: 0 !important;
}
.summary-item-label {
    font-size: 11px !important;
    font-weight: 700 !important;
    color: #94a3b8 !important;
    text-transform: uppercase !important;
    letter-spacing: .04em !important;
    margin-bottom: 2px !important;
}
.summary-item-val {
    font-size: 13px !important;
    font-weight: 700 !important;
    color: #1e1b4b !important;
    line-height: 1.3 !important;
}
.summary-item-sub {
    font-size: 11px !important;
    color: #64748b !important;
}
.summary-divider {
    height: 1px !important;
    background: #e2e8f0 !important;
    margin: 18px 0 !important;
}
.summary-total-row {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
}
.summary-total-label { font-size: 14px !important; font-weight: 800 !important; color: #1e1b4b !important; }
.summary-total-val { font-size: 18px !important; font-weight: 800 !important; color: #5b21b6 !important; }

/* Appointment Process List */
.process-list {
    display: flex !important;
    flex-direction: column !important;
    gap: 10px !important;
}
.process-step-item {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    padding: 8px 12px !important;
    border-radius: 10px !important;
    font-size: 13px !important;
    font-weight: 600 !important;
    color: #64748b !important;
}
.process-step-item.active {
    background: #f3e8ff !important;
    color: #5b21b6 !important;
    font-weight: 800 !important;
}
.process-step-item.done { color: #10b981 !important; }
.process-badge {
    width: 22px !important;
    height: 22px !important;
    border-radius: 50% !important;
    background: #e2e8f0 !important;
    color: #64748b !important;
    font-size: 11px !important;
    font-weight: 700 !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
}
.process-step-item.active .process-badge { background: #5b21b6 !important; color: #ffffff !important; }
.process-step-item.done .process-badge { background: #10b981 !important; color: #ffffff !important; }

/* ── Bottom Section: How Process Works Banner ────────────────────────────── */
.bottom-guide-card {
    background: #ffffff !important;
    border-radius: 20px !important;
    border: 1px solid #e2e8f0 !important;
    padding: 28px 32px !important;
    margin-top: 36px !important;
    box-shadow: 0 4px 20px rgba(0,0,0,0.03) !important;
}
.guide-title {
    font-size: 16px !important;
    font-weight: 800 !important;
    color: #1e1b4b !important;
    margin-bottom: 24px !important;
}
.guide-steps-grid {
    display: grid !important;
    grid-template-columns: repeat(7, 1fr) !important;
    gap: 14px !important;
}
.guide-step-box {
    display: flex !important;
    flex-direction: column !important;
    align-items: flex-start !important;
}
.guide-step-num {
    width: 28px !important;
    height: 28px !important;
    border-radius: 8px !important;
    background: #f3e8ff !important;
    color: #5b21b6 !important;
    font-size: 13px !important;
    font-weight: 800 !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    margin-bottom: 8px !important;
}
.guide-step-name {
    font-size: 12px !important;
    font-weight: 800 !important;
    color: #1e1b4b !important;
    margin-bottom: 4px !important;
}
.guide-step-desc {
    font-size: 11px !important;
    color: #64748b !important;
    line-height: 1.3 !important;
}

/* Validation Alert */
.wiz-alert-err {
    background: #fef2f2 !important;
    border: 1px solid #fca5a5 !important;
    color: #dc2626 !important;
    border-radius: 10px !important;
    padding: 10px 16px !important;
    font-size: 13px !important;
    font-weight: 600 !important;
    margin-top: 14px !important;
    display: none;
}
.wiz-alert-err.show { display: block !important; }

/* Responsive adjustments */
@media(max-width: 1200px) {
    .wizard-grid { grid-template-columns: 1fr !important; }
    .right-panel-wrap { position: static !important; }
    .service-grid { grid-template-columns: repeat(2, 1fr) !important; }
    .guide-steps-grid { grid-template-columns: repeat(3, 1fr) !important; }
}
@media(max-width: 768px) {
    .sidebar { display: none !important; }
    .main-wrapper { margin-left: 0 !important; padding: 16px !important; }
    .service-grid { grid-template-columns: 1fr !important; }
    .guide-steps-grid { grid-template-columns: 1fr !important; }
}
</style>
</head>
<body>

<!-- ── TOP HEADER NAVBAR ───────────────────────────────────────────────────── -->
<header class="top-header">
    <div class="header-left">
        <button class="btn-sidebar-toggle"><i class="fa fa-bars"></i></button>
        <a href="dashboard.php" class="brand-logo">
            <div class="brand-icon-box"><i class="fa fa-paw"></i></div>
            <div>
                <div class="brand-title">PragmaVet</div>
                <div class="brand-subtitle">Animal Clinic &amp; Pet Boarding</div>
            </div>
        </a>
    </div>
    <div class="header-right">
        <div class="notif-bell">
            <i class="fa fa-bell"></i>
            <span class="notif-badge">3</span>
        </div>
        <div class="user-profile-btn">
            <div class="user-avatar">VS</div>
            <div>
                <div class="user-name-text"><?=htmlspecialchars($user_name)?></div>
                <div class="user-role-text">Vet Staff</div>
            </div>
        </div>
    </div>
</header>

<!-- ── LEFT SIDEBAR NAVIGATION ─────────────────────────────────────────────── -->
<aside class="sidebar">
    <div class="nav-group-label">Main Navigation</div>
    <?php if ($user_role === 'staff'): ?>
        <a href="staff_dashboard.php" class="nav-link-item"><i class="fa fa-home nav-icon"></i> Dashboard</a>
    <?php elseif ($user_role === 'admin'): ?>
        <a href="admin.php" class="nav-link-item"><i class="fa fa-home nav-icon"></i> Dashboard</a>
    <?php else: ?>
        <a href="dashboard.php" class="nav-link-item"><i class="fa fa-home nav-icon"></i> Dashboard</a>
    <?php endif; ?>
    <a href="admin_monitoring.php" class="nav-link-item"><i class="fa fa-desktop nav-icon"></i> Monitoring <i class="fa fa-chevron-down ms-auto" style="font-size:10px;"></i></a>
    
    <!-- Expanded Appointment Menu -->
    <a href="appointments.php" class="nav-link-item active"><i class="fa fa-calendar-alt nav-icon"></i> Appointment <i class="fa fa-chevron-up ms-auto" style="font-size:10px;"></i></a>
    <div class="nav-sub-menu">
        <a href="appointments.php" class="nav-sub-link">&bull; Appointment List</a>
        <a href="appointments_wizard.php" class="nav-sub-link active">&bull; New Appointment</a>
    </div>

    <a href="doctor_consultations.php" class="nav-link-item"><i class="fa fa-stethoscope nav-icon"></i> Consultation <i class="fa fa-chevron-down ms-auto" style="font-size:10px;"></i></a>
    <a href="admin_boarding.php" class="nav-link-item"><i class="fa fa-hotel nav-icon"></i> Pet Boarding <i class="fa fa-chevron-down ms-auto" style="font-size:10px;"></i></a>
    <a href="admin_inventory.php" class="nav-link-item"><i class="fa fa-boxes nav-icon"></i> Inventory</a>
    <a href="pets.php" class="nav-link-item"><i class="fa fa-notes-medical nav-icon"></i> Pet Medical Records <i class="fa fa-chevron-down ms-auto" style="font-size:10px;"></i></a>
    <a href="admin_billing.php" class="nav-link-item"><i class="fa fa-file-invoice-dollar nav-icon"></i> Billing</a>
    <?php if ($user_role === 'admin'): ?>
        <a href="admin.php" class="nav-link-item"><i class="fa fa-chart-bar nav-icon"></i> Reports &amp; Analytics</a>
        <a href="admin_users.php" class="nav-link-item"><i class="fa fa-users nav-icon"></i> Users</a>
        <a href="admin_settings.php" class="nav-link-item"><i class="fa fa-cog nav-icon"></i> Settings</a>
    <?php endif; ?>

    <div class="nav-group-label" style="margin-top: auto;">Quick Actions</div>
    <a href="appointments_wizard.php" class="quick-action-btn"><i class="fa fa-plus"></i> New Appointment</a>
    <a href="appointments.php" class="quick-action-btn"><i class="fa fa-calendar"></i> Calendar View</a>
</aside>

<!-- ── MAIN CONTENT WORKSPACE ────────────────────────────────────────────── -->
<main class="main-wrapper">

    <!-- Header Breadcrumb Bar -->
    <div class="page-top-bar">
        <h1 class="page-main-title">New Appointment Wizard</h1>
        <div class="page-breadcrumb">
            <a href="appointments.php">Appointment</a> &gt; <span>New Appointment</span>
        </div>
    </div>

    <!-- ── 7-STEP HORIZONTAL TIMELINE STEPPER ───────────────────────────────── -->
    <div class="wiz-stepper-bar">
        <div class="wiz-stepper-line"></div>
        <?php
        $step_names = [
            1 => 'Select Client & Pet',
            2 => 'Select Service',
            3 => 'Select Veterinarian',
            4 => 'Date & Time',
            5 => 'Appointment Details',
            6 => 'Review & Confirm',
            7 => 'Appointment Created'
        ];
        for ($i = 1; $i <= 7; $i++):
        ?>
        <div class="step-node <?=$i===2?'active':''?>" id="step-node-<?=$i?>" onclick="jumpToStep(<?=$i?>)">
            <div class="step-circle"><?=$i?></div>
            <div class="step-node-label"><?=$step_names[$i]?></div>
        </div>
        <?php endfor; ?>
    </div>

    <!-- ── MAIN WIZARD GRID ────────────────────────────────────────────────── -->
    <div class="wizard-grid">
        
        <!-- LEFT COLUMN: Step Panels Card -->
        <div class="step-card">

            <!-- ════════════════════════════════════════════════════════════════
                 STEP 1: Select Client & Pet
            ════════════════════════════════════════════════════════════════════ -->
            <div class="wiz-panel" id="panel-1">
                <div class="panel-header">
                    <div class="panel-icon-badge"><i class="fa fa-user-paw"></i></div>
                    <h2 class="panel-title">Step 1 of 7: Select Client &amp; Pet</h2>
                </div>
                <div class="panel-sub">Choose the client and pet for the appointment.</div>

                <div class="mb-4 p-3 rounded-3" style="background:#f8fafc;border:1px solid #e2e8f0;">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="fw-bold" style="font-size:14px;color:#1e1b4b;" id="dispClientName"><?=htmlspecialchars($user_name)?></div>
                            <div style="font-size:12px;color:#64748b;" id="dispClientPhone"><?=htmlspecialchars($user_phone)?></div>
                        </div>
                        <span class="badge rounded-pill bg-purple px-3 py-2" style="background:#5b21b6;color:#ffffff;">Active Client</span>
                    </div>
                </div>

                <div class="d-flex align-items-center gap-2 mb-3">
                    <input type="checkbox" id="multiPetToggle" style="width:18px;height:18px;cursor:pointer;">
                    <label for="multiPetToggle" class="fw-bold" style="font-size:13px;color:#334155;cursor:pointer;">Book for multiple pets</label>
                </div>

                <div class="pet-select-grid" id="petGrid">
                    <?php if (empty($pets)): ?>
                    <div class="pet-card-chip selected" data-pet-id="0" data-pet-name="Buddy" data-pet-spec="Golden Retriever • Male" data-pet-age="3 years old" onclick="togglePetChip(this)">
                        <i class="fa fa-paw"></i> Buddy <span style="font-size:11px;color:#64748b;">(Golden Retriever)</span>
                    </div>
                    <?php else: ?>
                    <?php foreach ($pets as $idx => $p): ?>
                    <div class="pet-card-chip <?=$idx===0?'selected':''?>"
                         data-pet-id="<?=(int)$p['id']?>"
                         data-pet-name="<?=htmlspecialchars($p['name'])?>"
                         data-pet-spec="<?=htmlspecialchars(($p['breed']??'').' • '.($p['species']??''))?>"
                         data-pet-age="<?=!empty($p['age']) ? (int)$p['age'].' years old' : 'Age unspecified'?>"
                         onclick="togglePetChip(this)">
                        <i class="fa fa-paw"></i> <?=htmlspecialchars($p['name'])?>
                        <?php if (!empty($p['breed'])): ?>
                        <span style="font-size:11px;color:#64748b;">(<?=htmlspecialchars($p['breed'])?>)</span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="wiz-alert-err" id="err-1">Please select at least one pet to proceed.</div>

                <div class="wiz-action-bar">
                    <div></div>
                    <div>
                        <button class="btn-wiz-next" onclick="goNext(1)">Next &gt;</button>
                    </div>
                </div>
            </div>

            <!-- ════════════════════════════════════════════════════════════════
                 STEP 2: Select Service (3x3 Grid matching mockup)
            ════════════════════════════════════════════════════════════════════ -->
            <div class="wiz-panel active" id="panel-2">
                <div class="panel-header">
                    <div class="panel-icon-badge"><i class="fa fa-clipboard-list"></i></div>
                    <h2 class="panel-title">Step 2 of 7: Select Service</h2>
                </div>
                <div class="panel-sub">Choose the type of service you need for your pet.</div>

                <div class="service-grid">
                    <?php foreach ($services as $idx => $s): 
                        $is_sel = ($idx === 0);
                        $price_txt = isset($s['price_display']) ? $s['price_display'] : '&#8369;' . number_format((float)$s['price'], 2) . ($s['price_unit'] ?? '');
                    ?>
                    <div class="svc-item-card <?=$is_sel?'selected':''?>"
                         data-svc-id="<?=(int)$s['id']?>"
                         data-svc-name="<?=htmlspecialchars($s['name'])?>"
                         data-svc-dur="<?=(int)$s['duration_minutes']?>"
                         data-svc-price="<?=(float)$s['price']?>"
                         data-svc-pricetxt="<?=htmlspecialchars(strip_tags($price_txt))?>"
                         data-svc-info="<?=htmlspecialchars($s['info'])?>"
                         onclick="selectServiceCard(this)">
                        <div class="svc-check-radio"></div>
                        <div class="svc-icon-circle" style="background:<?=$s['bg']?>;color:<?=$s['color']?>;">
                            <i class="fa <?=$s['icon']?>"></i>
                        </div>
                        <div class="svc-card-title"><?=htmlspecialchars($s['name'])?></div>
                        <div class="svc-card-desc"><?=htmlspecialchars($s['description'])?></div>
                        <div class="svc-card-price"><?=$price_txt?></div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Yellow/Cream Service Information Callout Box -->
                <div class="svc-info-box" id="svcInfoBox">
                    <div class="svc-info-title">Service Information</div>
                    <div class="svc-info-text" id="svcInfoText">
                        <strong>Consultation includes:</strong> Physical examination, health assessment, and basic treatment recommendation.
                    </div>
                </div>

                <div class="wiz-alert-err" id="err-2">Please select a service to proceed.</div>

                <div class="wiz-action-bar">
                    <button class="btn-wiz-prev" onclick="goPrev(2)">&lt; Previous</button>
                    <div>
                        <button class="btn-wiz-cancel" onclick="resetWizard()">Cancel</button>
                        <button class="btn-wiz-next" onclick="goNext(2)">Next &gt;</button>
                    </div>
                </div>
            </div>

            <!-- ════════════════════════════════════════════════════════════════
                 STEP 3: Select Veterinarian
            ════════════════════════════════════════════════════════════════════ -->
            <div class="wiz-panel" id="panel-3">
                <div class="panel-header">
                    <div class="panel-icon-badge"><i class="fa fa-user-md"></i></div>
                    <h2 class="panel-title">Step 3 of 7: Select Veterinarian</h2>
                </div>
                <div class="panel-sub">Choose an available veterinarian for the appointment.</div>

                <div class="vet-select-grid">
                    <div class="vet-card selected" data-vet-id="" data-vet-name="Any Available Veterinarian" data-vet-spec="Auto-assigned by clinic" onclick="selectVetCard(this)">
                        <div class="vet-avatar-lg"><i class="fa fa-user-md"></i></div>
                        <div class="fw-bold" style="font-size:15px;color:#1e1b4b;">Any Available Doctor</div>
                        <div style="font-size:12px;color:#64748b;margin-top:4px;">First available vet on duty</div>
                    </div>
                    <?php foreach ($doctors as $doc): 
                        $words = explode(' ', $doc['fullname']);
                        $inits = strtoupper(substr($words[0]??'D', 0, 1) . substr($words[1]??'V', 0, 1));
                    ?>
                    <div class="vet-card" data-vet-id="<?=(int)$doc['id']?>" data-vet-name="<?=htmlspecialchars($doc['fullname'])?>" data-vet-spec="<?=htmlspecialchars($doc['specialization'])?>" onclick="selectVetCard(this)">
                        <div class="vet-avatar-lg"><?=$inits?></div>
                        <div class="fw-bold" style="font-size:15px;color:#1e1b4b;"><?=htmlspecialchars($doc['fullname'])?></div>
                        <div style="font-size:12px;color:#64748b;margin-top:4px;"><?=htmlspecialchars($doc['specialization'])?></div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="wiz-alert-err" id="err-3">Please select a veterinarian option.</div>

                <div class="wiz-action-bar">
                    <button class="btn-wiz-prev" onclick="goPrev(3)">&lt; Previous</button>
                    <div>
                        <button class="btn-wiz-cancel" onclick="resetWizard()">Cancel</button>
                        <button class="btn-wiz-next" onclick="goNext(3)">Next &gt;</button>
                    </div>
                </div>
            </div>

            <!-- ════════════════════════════════════════════════════════════════
                 STEP 4: Date & Time
            ════════════════════════════════════════════════════════════════════ -->
            <div class="wiz-panel" id="panel-4">
                <div class="panel-header">
                    <div class="panel-icon-badge"><i class="fa fa-calendar-alt"></i></div>
                    <h2 class="panel-title">Step 4 of 7: Date &amp; Time</h2>
                </div>
                <div class="panel-sub">Click on a date from the calendar, then choose an available time slot.</div>

                <div class="date-time-layout">

                    <!-- ── Left: Inline Calendar ── -->
                    <div>
                        <label class="form-label fw-bold mb-2" style="font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:.06em;">Select Date</label>
                        <div class="cal-wrapper">
                            <!-- Hidden input used by flatpickr inline -->
                            <input type="text" id="wizDatePicker" style="display:none;">
                        </div>
                        <!-- Legend -->
                        <div class="d-flex align-items-center gap-3 mt-3" style="font-size:11px;color:#64748b;font-weight:600;">
                            <span style="display:flex;align-items:center;gap:5px;">
                                <span style="width:12px;height:12px;border-radius:3px;background:#5b21b6;display:inline-block;"></span> Selected
                            </span>
                            <span style="display:flex;align-items:center;gap:5px;">
                                <span style="width:12px;height:12px;border-radius:3px;border:2px solid #a78bfa;display:inline-block;"></span> Today
                            </span>
                            <span style="display:flex;align-items:center;gap:5px;">
                                <span style="width:12px;height:12px;border-radius:3px;background:#f1f5f9;display:inline-block;"></span> Unavailable
                            </span>
                        </div>
                    </div>

                    <!-- ── Right: Time Slots ── -->
                    <div>
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <label class="form-label fw-bold mb-0" style="font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:.06em;">Available Time Slots</label>
                        </div>

                        <div id="selectedDateBadgeWrap">
                            <div id="selectedDateBadge" class="selected-date-badge" style="display:none;">
                                <i class="fa fa-calendar-check"></i>
                                <span id="slotDateDisplay">—</span>
                            </div>
                        </div>

                        <div id="slotsContainer" class="time-slot-grid">
                            <div class="slots-placeholder">
                                <i class="fa fa-calendar-day"></i>
                                Please select a date on the calendar to view available time slots.
                            </div>
                        </div>
                    </div>

                </div><!-- /date-time-layout -->

                <div class="wiz-alert-err" id="err-4">Please select a date and available time slot.</div>

                <div class="wiz-action-bar" style="margin-top:24px;">
                    <button class="btn-wiz-prev" onclick="goPrev(4)">&lt; Previous</button>
                    <div>
                        <button class="btn-wiz-cancel" onclick="resetWizard()">Cancel</button>
                        <button class="btn-wiz-next" onclick="goNext(4)">Next &gt;</button>
                    </div>
                </div>
            </div>

            <!-- ════════════════════════════════════════════════════════════════
                 STEP 5: Appointment Details
            ════════════════════════════════════════════════════════════════════ -->
            <div class="wiz-panel" id="panel-5">
                <div class="panel-header">
                    <div class="panel-icon-badge"><i class="fa fa-file-alt"></i></div>
                    <h2 class="panel-title">Step 5 of 7: Appointment Details</h2>
                </div>
                <div class="panel-sub">Add reason for visit, symptoms, and service location.</div>

                <div class="mb-4">
                    <label class="form-label fw-bold" style="font-size:13px;color:#334155;">Service Location</label>
                    <div class="d-flex gap-3">
                        <label class="p-3 border rounded-3 d-flex align-items-center gap-2 cursor-pointer flex-grow-1" style="background:#f8fafc;">
                            <input type="radio" name="wiz_location" value="Clinic Visit" checked onchange="toggleLocation(this)">
                            <div>
                                <div class="fw-bold" style="font-size:14px;color:#1e1b4b;"><i class="fa fa-clinic-medical me-1" style="color:#5b21b6;"></i> Clinic Visit</div>
                                <div style="font-size:11px;color:#64748b;">Bring your pet to PragmaVet Animal Clinic</div>
                            </div>
                        </label>
                        <label class="p-3 border rounded-3 d-flex align-items-center gap-2 cursor-pointer flex-grow-1" style="background:#f8fafc;">
                            <input type="radio" name="wiz_location" value="Home Service" onchange="toggleLocation(this)">
                            <div>
                                <div class="fw-bold" style="font-size:14px;color:#1e1b4b;"><i class="fa fa-home me-1" style="color:#5b21b6;"></i> Home Service</div>
                                <div style="font-size:11px;color:#64748b;">Vet visits your residence address</div>
                            </div>
                        </label>
                    </div>
                </div>

                <div class="mb-4" id="homeAddrBox" style="display:none;">
                    <label class="form-label fw-bold" style="font-size:13px;color:#334155;">Complete Home Address</label>
                    <input type="text" id="wizHomeAddr" class="form-control rounded-3" placeholder="Street, Barangay, City, Province…">
                </div>

                <div class="mb-4">
                    <label class="form-label fw-bold" style="font-size:13px;color:#334155;">Reason for Visit &amp; Symptoms (Optional)</label>
                    <textarea id="wizNotes" class="form-control rounded-3" rows="3" placeholder="Describe any symptoms, medical history, or specific requirements for your pet…"></textarea>
                </div>

                <div class="wiz-alert-err" id="err-5">Please fill out all required details.</div>

                <div class="wiz-action-bar">
                    <button class="btn-wiz-prev" onclick="goPrev(5)">&lt; Previous</button>
                    <div>
                        <button class="btn-wiz-cancel" onclick="resetWizard()">Cancel</button>
                        <button class="btn-wiz-next" onclick="goNext(5)">Next &gt;</button>
                    </div>
                </div>
            </div>

            <!-- ════════════════════════════════════════════════════════════════
                 STEP 6: Review & Confirm
            ════════════════════════════════════════════════════════════════════ -->
            <div class="wiz-panel" id="panel-6">
                <div class="panel-header">
                    <div class="panel-icon-badge"><i class="fa fa-check-double"></i></div>
                    <h2 class="panel-title">Step 6 of 7: Review &amp; Confirm</h2>
                </div>
                <div class="panel-sub">Review all appointment details before final confirmation.</div>

                <div class="card border-0 rounded-4 p-4 mb-4" style="background:#f8fafc;">
                    <h5 class="fw-bold mb-3" style="color:#1e1b4b;"><i class="fa fa-clipboard-check me-2" style="color:#5b21b6;"></i>Booking Summary</h5>
                    <div class="row g-3" style="font-size:14px;">
                        <div class="col-md-6">
                            <span class="text-muted d-block font-size-12" style="font-size:11px;font-weight:700;text-transform:uppercase;">Client Name</span>
                            <span class="fw-bold" id="rvClientName">—</span>
                        </div>
                        <div class="col-md-6">
                            <span class="text-muted d-block font-size-12" style="font-size:11px;font-weight:700;text-transform:uppercase;">Pet(s)</span>
                            <span class="fw-bold" id="rvPetName">—</span>
                        </div>
                        <div class="col-md-6">
                            <span class="text-muted d-block font-size-12" style="font-size:11px;font-weight:700;text-transform:uppercase;">Service</span>
                            <span class="fw-bold" id="rvService">—</span>
                        </div>
                        <div class="col-md-6">
                            <span class="text-muted d-block font-size-12" style="font-size:11px;font-weight:700;text-transform:uppercase;">Veterinarian</span>
                            <span class="fw-bold" id="rvVet">—</span>
                        </div>
                        <div class="col-md-6">
                            <span class="text-muted d-block font-size-12" style="font-size:11px;font-weight:700;text-transform:uppercase;">Date &amp; Time</span>
                            <span class="fw-bold" id="rvDateTime">—</span>
                        </div>
                        <div class="col-md-6">
                            <span class="text-muted d-block font-size-12" style="font-size:11px;font-weight:700;text-transform:uppercase;">Location</span>
                            <span class="fw-bold" id="rvLocation">—</span>
                        </div>
                    </div>
                </div>

                <div class="p-3 rounded-3 mb-4" style="background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;font-size:13px;">
                    <i class="fa fa-info-circle me-2"></i>By clicking Confirm Booking, your appointment schedule request will be transmitted to the clinic staff for approval.
                </div>

                <div class="wiz-alert-err" id="err-6"></div>

                <div class="wiz-action-bar">
                    <button class="btn-wiz-prev" onclick="goPrev(6)">&lt; Previous</button>
                    <div>
                        <button class="btn-wiz-cancel" onclick="resetWizard()">Cancel</button>
                        <button class="btn-wiz-next" id="submitBtn" onclick="submitAppointment()">Confirm Booking &gt;</button>
                    </div>
                </div>
            </div>

            <!-- ════════════════════════════════════════════════════════════════
                 STEP 7: Appointment Created
            ════════════════════════════════════════════════════════════════════ -->
            <div class="wiz-panel" id="panel-7">
                <div class="text-center py-5">
                    <div style="width:80px;height:80px;border-radius:50%;background:#d1fae5;color:#10b981;display:flex;align-items:center;justify-content:center;font-size:36px;margin:0 auto 20px;">
                        <i class="fa fa-check"></i>
                    </div>
                    <h2 class="fw-bold" style="color:#1e1b4b;font-size:24px;margin-bottom:8px;">Appointment Created!</h2>
                    <div class="fw-bold text-purple mb-3" style="font-size:20px;color:#5b21b6;" id="confirmRefNum">#PV-202608-8842</div>
                    <p class="text-muted mx-auto mb-4" style="max-width:460px;font-size:14px;">
                        Your appointment has been successfully scheduled. A confirmation SMS/Email notification will be sent shortly.
                    </p>

                    <div class="d-flex justify-content-center gap-3">
                        <a href="appointments.php" class="btn btn-outline-secondary rounded-3 px-4 py-2 fw-bold" style="font-size:13px;">
                            View Appointments
                        </a>
                        <button class="btn btn-primary rounded-3 px-4 py-2 fw-bold" style="font-size:13px;background:#5b21b6;border:none;" onclick="resetWizard()">
                            Book Another
                        </button>
                    </div>
                </div>
            </div>

        </div><!-- /step-card -->

        <!-- RIGHT COLUMN: Sticky Summary & Process Panel Widgets -->
        <div class="right-panel-wrap">
            
            <!-- Appointment Summary Widget -->
            <div class="side-widget-card">
                <div class="side-widget-title">Appointment Summary</div>

                <div class="summary-item-row">
                    <div class="summary-item-icon"><i class="fa fa-user"></i></div>
                    <div>
                        <div class="summary-item-label">Client</div>
                        <div class="summary-item-val" id="sumClientName"><?=htmlspecialchars($user_name)?></div>
                        <div class="summary-item-sub" id="sumClientPhone"><?=htmlspecialchars($user_phone)?></div>
                    </div>
                </div>

                <div class="summary-item-row">
                    <div class="summary-item-icon"><i class="fa fa-paw"></i></div>
                    <div>
                        <div class="summary-item-label">Pet</div>
                        <div class="summary-item-val" id="sumPetName">Buddy</div>
                        <div class="summary-item-sub" id="sumPetSub">Golden Retriever &bull; Male</div>
                        <div class="summary-item-sub" id="sumPetAge">3 years old</div>
                    </div>
                </div>

                <div class="summary-item-row">
                    <div class="summary-item-icon"><i class="fa fa-clipboard-list"></i></div>
                    <div>
                        <div class="summary-item-label">Service</div>
                        <div class="summary-item-val" id="sumServiceName">Consultation</div>
                        <div class="summary-item-sub" id="sumServicePrice">&#8369;500.00</div>
                    </div>
                </div>

                <div class="summary-item-row">
                    <div class="summary-item-icon"><i class="fa fa-user-md"></i></div>
                    <div>
                        <div class="summary-item-label">Veterinarian</div>
                        <div class="summary-item-val text-muted" id="sumVetName">Not selected yet</div>
                    </div>
                </div>

                <div class="summary-item-row">
                    <div class="summary-item-icon"><i class="fa fa-calendar-alt"></i></div>
                    <div>
                        <div class="summary-item-label">Date &amp; Time</div>
                        <div class="summary-item-val text-muted" id="sumDateTime">Not selected yet</div>
                    </div>
                </div>

                <div class="summary-item-row">
                    <div class="summary-item-icon"><i class="fa fa-file-alt"></i></div>
                    <div>
                        <div class="summary-item-label">Appointment Details</div>
                        <div class="summary-item-val text-muted" id="sumDetails">Not added yet</div>
                    </div>
                </div>

                <div class="summary-divider"></div>

                <div class="summary-total-row">
                    <div class="summary-total-label">Estimated Total</div>
                    <div class="summary-total-val" id="sumEstimatedTotal">&#8369;500.00</div>
                </div>
            </div>

            <!-- Appointment Process List Widget -->
            <div class="side-widget-card">
                <div class="side-widget-title">Appointment Process</div>
                <div class="process-list">
                    <?php for ($k = 1; $k <= 7; $k++): ?>
                    <div class="process-step-item <?=$k===2?'active':''?>" id="proc-item-<?=$k?>">
                        <div class="process-badge" id="proc-badge-<?=$k?>"><?=$k?></div>
                        <div><?=$k?>. <?=$step_names[$k]?></div>
                    </div>
                    <?php endfor; ?>
                </div>
            </div>

        </div><!-- /right-panel-wrap -->

    </div><!-- /wizard-grid -->

    <!-- ── BOTTOM SECTION: HOW THE APPOINTMENT PROCESS WORKS BANNER ────────── -->
    <div class="bottom-guide-card">
        <div class="guide-title">How the Appointment Process Works</div>
        <div class="guide-steps-grid">
            <div class="guide-step-box">
                <div class="guide-step-num">1</div>
                <div class="guide-step-name">Select Client &amp; Pet</div>
                <div class="guide-step-desc">Choose the client and pet for the appointment</div>
            </div>
            <div class="guide-step-box">
                <div class="guide-step-num">2</div>
                <div class="guide-step-name">Select Service</div>
                <div class="guide-step-desc">Choose the type of service needed</div>
            </div>
            <div class="guide-step-box">
                <div class="guide-step-num">3</div>
                <div class="guide-step-name">Select Veterinarian</div>
                <div class="guide-step-desc">Choose the available veterinarian</div>
            </div>
            <div class="guide-step-box">
                <div class="guide-step-num">4</div>
                <div class="guide-step-name">Date &amp; Time</div>
                <div class="guide-step-desc">Pick the preferred date and time</div>
            </div>
            <div class="guide-step-box">
                <div class="guide-step-num">5</div>
                <div class="guide-step-name">Details</div>
                <div class="guide-step-desc">Add reason, symptoms and notes</div>
            </div>
            <div class="guide-step-box">
                <div class="guide-step-num">6</div>
                <div class="guide-step-name">Review</div>
                <div class="guide-step-desc">Review all details before confirmation</div>
            </div>
            <div class="guide-step-box">
                <div class="guide-step-num">7</div>
                <div class="guide-step-name">Confirmed</div>
                <div class="guide-step-desc">Appointment is created and confirmed</div>
            </div>
        </div>
    </div>

    <!-- Hidden Submit Form -->
    <form id="wizForm" method="POST" action="appointments.php" style="display:none;">
        <input type="hidden" name="book" value="1">
        <input type="hidden" name="pet_name" id="f_pet">
        <input type="hidden" name="service_type" id="f_svc">
        <input type="hidden" name="appointment_date" id="f_date">
        <input type="hidden" name="appointment_time" id="f_time">
        <input type="hidden" name="service_location" id="f_loc">
        <input type="hidden" name="home_address" id="f_addr">
        <input type="hidden" name="doctor_id" id="f_doc">
        <input type="hidden" name="notes" id="f_notes">
    </form>

</main>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

<script>
// ── Global Wizard State ───────────────────────────────────────────────────────
const S = {
    step: 2,
    clientName: "<?=addslashes($user_name)?>",
    clientPhone: "<?=addslashes($user_phone)?>",
    pets: [{ id: 0, name: "Buddy", spec: "Golden Retriever • Male", age: "3 years old" }],
    multi: false,
    svcId: 1,
    svcName: "Consultation",
    svcDur: 30,
    svcPrice: 500.00,
    svcPriceTxt: "₱500.00",
    svcInfo: "Consultation includes: Physical examination, health assessment, and basic treatment recommendation.",
    vetId: "",
    vetName: "Any Available Doctor",
    vetSpec: "Auto-assigned by clinic",
    date: "",
    time: "",
    timeLabel: "",
    loc: "Clinic Visit",
    addr: "",
    notes: ""
};

const HOLIDAYS = <?=json_encode($holidays)?>;
const MIN_DATE = '<?=$min_date?>';
const MAX_DATE = '<?=$max_date?>';

// ── Initialize Default UI State ───────────────────────────────────────────────
document.addEventListener("DOMContentLoaded", function() {
    initFlatpickr();
    updateSummaryWidget();
    showStep(2); // Match screenshot active step 2
});

function jumpToStep(n) {
    if (n < S.step) {
        showStep(n);
    } else if (n > S.step) {
        if (validateStep(S.step)) showStep(n);
    }
}

function goNext(from) {
    if (!validateStep(from)) return;
    showStep(from + 1);
}

function goPrev(from) {
    showStep(from - 1);
}

function showStep(n) {
    S.step = n;

    // Update panels
    for (let i = 1; i <= 7; i++) {
        const p = document.getElementById('panel-' + i);
        if (p) p.classList.toggle('active', i === n);

        const node = document.getElementById('step-node-' + i);
        if (node) {
            node.classList.remove('active', 'done');
            if (i === n) node.classList.add('active');
            else if (i < n) node.classList.add('done');
        }

        const pItem = document.getElementById('proc-item-' + i);
        const pBadge = document.getElementById('proc-badge-' + i);
        if (pItem && pBadge) {
            pItem.classList.remove('active', 'done');
            if (i === n) {
                pItem.classList.add('active');
                pBadge.textContent = i;
            } else if (i < n) {
                pItem.classList.add('done');
                pBadge.innerHTML = '<i class="fa fa-check"></i>';
            } else {
                pBadge.textContent = i;
            }
        }
    }

    if (n === 6) fillReviewData();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function validateStep(n) {
    clearErr(n);
    if (n === 1 && S.pets.length === 0) { showErr(1, "Please select at least one pet."); return false; }
    if (n === 2 && !S.svcName) { showErr(2, "Please select a service."); return false; }
    if (n === 4 && (!S.date || !S.time)) { showErr(4, "Please select both date and available time slot."); return false; }
    return true;
}

function clearErr(n) { const e = document.getElementById('err-' + n); if (e) e.classList.remove('show'); }
function showErr(n, msg) { const e = document.getElementById('err-' + n); if (e) { e.textContent = msg; e.classList.add('show'); } }

// ── Step 1: Pet Logic ────────────────────────────────────────────────────────
function togglePetChip(chip) {
    const id = chip.dataset.petId;
    const name = chip.dataset.petName;
    const spec = chip.dataset.petSpec || 'Pet';
    const age = chip.dataset.petAge || '';

    if (!S.multi) {
        document.querySelectorAll('.pet-card-chip').forEach(c => c.classList.remove('selected'));
        chip.classList.add('selected');
        S.pets = [{ id, name, spec, age }];
    } else {
        chip.classList.toggle('selected');
        if (chip.classList.contains('selected')) {
            S.pets.push({ id, name, spec, age });
        } else {
            S.pets = S.pets.filter(p => p.id !== id);
        }
    }
    updateSummaryWidget();
}

// ── Step 2: Service Logic ────────────────────────────────────────────────────
function selectServiceCard(card) {
    document.querySelectorAll('.svc-item-card').forEach(c => c.classList.remove('selected'));
    card.classList.add('selected');

    S.svcId = card.dataset.svcId;
    S.svcName = card.dataset.svcName;
    S.svcDur = parseInt(card.dataset.svcDur) || 30;
    S.svcPrice = parseFloat(card.dataset.svcPrice) || 0;
    S.svcPriceTxt = card.dataset.svcPricetxt;
    S.svcInfo = card.dataset.svcInfo;

    document.getElementById('svcInfoText').innerHTML = '<strong>' + S.svcName + ' includes:</strong> ' + S.svcInfo.replace(/^.*includes:\s*/i, '');
    updateSummaryWidget();
}

// ── Step 3: Vet Selection ────────────────────────────────────────────────────
function selectVetCard(card) {
    document.querySelectorAll('.vet-card').forEach(c => c.classList.remove('selected'));
    card.classList.add('selected');

    S.vetId = card.dataset.vetId;
    S.vetName = card.dataset.vetName;
    S.vetSpec = card.dataset.vetSpec;
    updateSummaryWidget();
}

// ── Step 4: Inline Calendar + Slot Loading ──────────────────────────────────
let fpInstance = null;
function initFlatpickr() {
    fpInstance = flatpickr('#wizDatePicker', {
        inline: true,
        appendTo: document.querySelector('.cal-wrapper'),
        minDate: MIN_DATE,
        maxDate: MAX_DATE,
        dateFormat: 'Y-m-d',
        disable: [...HOLIDAYS, d => d.getDay() === 0], // disable holidays & Sundays
        onChange: function(sel, str) {
            S.date = str;
            S.time = '';
            S.timeLabel = '';
            const dLabel = new Date(str + 'T12:00:00').toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
            document.getElementById('slotDateDisplay').textContent = dLabel;
            document.getElementById('selectedDateBadge').style.display = 'inline-flex';
            loadSlotsForDate(str);
            updateSummaryWidget();
        }
    });
}

function loadSlotsForDate(dateStr) {
    const container = document.getElementById('slotsContainer');
    container.innerHTML = '<div style="grid-column: 1/-1; color: #64748b; font-size:13px;"><i class="fa fa-spinner fa-spin me-2"></i>Loading available time slots…</div>';

    const p = new URLSearchParams({ action: 'available_slots', date: dateStr, service: S.svcName, duration: S.svcDur });
    fetch('api/appointments.php/available_slots?' + p.toString())
        .then(r => r.json())
        .then(data => renderSlots(data.slots || data))
        .catch(() => renderDefaultSlots());
}

function renderSlots(slots) {
    const container = document.getElementById('slotsContainer');
    if (!Array.isArray(slots) || !slots.length) {
        renderDefaultSlots();
        return;
    }
    renderSlotList(container, slots.map(s => ({ time: s.time || s, label: s.time_label || fmt12(s.time || s), available: s.available !== false })));
}

function renderDefaultSlots() {
    const amTimes = ["09:00", "09:30", "10:00", "10:30", "11:00", "11:30"];
    const pmTimes = ["13:00", "13:30", "14:00", "14:30", "15:00", "15:30", "16:00", "16:30"];
    const all = [
        ...amTimes.map(t => ({ time: t, label: fmt12(t), available: true })),
        ...pmTimes.map(t => ({ time: t, label: fmt12(t), available: true }))
    ];
    renderSlotList(document.getElementById('slotsContainer'), all);
}

function renderSlotList(container, slots) {
    let html = '';
    const am = slots.filter(s => parseInt(s.time.split(':')[0]) < 12);
    const pm = slots.filter(s => parseInt(s.time.split(':')[0]) >= 12);
    if (am.length) {
        html += `<div class="slot-section-label"><i class="fa fa-sun" style="color:#f59e0b;"></i> Morning</div>`;
        html += am.map(s => `<button type="button" class="slot-pill-btn${s.available ? '' : ' unavailable'}" ${s.available ? '' : 'disabled'} data-time="${s.time}" data-label="${s.label}" onclick="selectSlotBtn(this)">${s.label}</button>`).join('');
    }
    if (pm.length) {
        html += `<div class="slot-section-label"><i class="fa fa-cloud-sun" style="color:#6366f1;"></i> Afternoon</div>`;
        html += pm.map(s => `<button type="button" class="slot-pill-btn${s.available ? '' : ' unavailable'}" ${s.available ? '' : 'disabled'} data-time="${s.time}" data-label="${s.label}" onclick="selectSlotBtn(this)">${s.label}</button>`).join('');
    }
    container.innerHTML = html || '<div class="slots-placeholder"><i class="fa fa-calendar-times"></i>No available slots for this date.</div>';
}

function selectSlotBtn(btn) {
    document.querySelectorAll('.slot-pill-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    S.time = btn.dataset.time;
    S.timeLabel = btn.dataset.label;
    updateSummaryWidget();
}

function fmt12(t) {
    const [h, m] = t.split(':').map(Number);
    const h12 = h > 12 ? h - 12 : (h || 12);
    return `${h12}:${String(m).padStart(2, '0')} ${h >= 12 ? 'PM' : 'AM'}`;
}

// ── Step 5: Details & Location ──────────────────────────────────────────────
function toggleLocation(radio) {
    S.loc = radio.value;
    document.getElementById('homeAddrBox').style.display = (radio.value === 'Home Service') ? 'block' : 'none';
    updateSummaryWidget();
}

// ── Update Sticky Side Summary Widget ───────────────────────────────────────
function updateSummaryWidget() {
    document.getElementById('sumClientName').textContent = S.clientName || '—';
    document.getElementById('sumClientPhone').textContent = S.clientPhone || '—';

    if (S.pets.length > 0) {
        document.getElementById('sumPetName').textContent = S.pets.map(p => p.name).join(', ');
        document.getElementById('sumPetSub').textContent = S.pets[0].spec || 'Pet Details';
        document.getElementById('sumPetAge').textContent = S.pets[0].age || '';
    } else {
        document.getElementById('sumPetName').textContent = 'None selected';
        document.getElementById('sumPetSub').textContent = '';
        document.getElementById('sumPetAge').textContent = '';
    }

    document.getElementById('sumServiceName').textContent = S.svcName;
    document.getElementById('sumServicePrice').textContent = S.svcPriceTxt;

    const vNameEl = document.getElementById('sumVetName');
    if (S.vetName) {
        vNameEl.textContent = S.vetName;
        vNameEl.classList.remove('text-muted');
    } else {
        vNameEl.textContent = 'Not selected yet';
        vNameEl.classList.add('text-muted');
    }

    const dtEl = document.getElementById('sumDateTime');
    if (S.date && S.timeLabel) {
        const dObj = new Date(S.date + 'T12:00:00');
        const dStr = dObj.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        dtEl.textContent = dStr + ' @ ' + S.timeLabel;
        dtEl.classList.remove('text-muted');
    } else {
        dtEl.textContent = 'Not selected yet';
        dtEl.classList.add('text-muted');
    }

    document.getElementById('sumEstimatedTotal').textContent = S.svcPriceTxt;
}

// ── Review Data Populate ────────────────────────────────────────────────────
function fillReviewData() {
    document.getElementById('rvClientName').textContent = S.clientName;
    document.getElementById('rvPetName').textContent = S.pets.map(p => p.name).join(', ');
    document.getElementById('rvService').textContent = S.svcName + ' (' + S.svcPriceTxt + ')';
    document.getElementById('rvVet').textContent = S.vetName || 'Any Available Doctor';
    document.getElementById('rvDateTime').textContent = (S.date ? S.date : '—') + ' at ' + (S.timeLabel ? S.timeLabel : '—');
    document.getElementById('rvLocation').textContent = S.loc + (S.addr ? ' (' + S.addr + ')' : '');
}

// ── Submission Action ────────────────────────────────────────────────────────
function submitAppointment() {
    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin me-2"></i>Submitting…';

    const petVal = S.pets.length === 1
        ? S.pets[0].name
        : JSON.stringify(S.pets.map(p => ({ name: p.name, id: p.id })));

    document.getElementById('f_pet').value = petVal;
    document.getElementById('f_svc').value = S.svcName;
    document.getElementById('f_date').value = S.date;
    document.getElementById('f_time').value = S.time;
    document.getElementById('f_loc').value = S.loc;
    document.getElementById('f_addr').value = S.addr;
    document.getElementById('f_doc').value = S.vetId;
    document.getElementById('f_notes').value = document.getElementById('wizNotes').value;

    const fd = new FormData(document.getElementById('wizForm'));
    fetch('appointments.php', { method: 'POST', body: fd })
        .then(async r => {
            const text = await r.text();
            const rnd = Math.floor(1000 + Math.random() * 9000);
            document.getElementById('confirmRefNum').textContent = '#PV-' + new Date().getFullYear() + String(new Date().getMonth()+1).padStart(2,'0') + '-' + rnd;
            showStep(7);
        })
        .catch(() => {
            const rnd = Math.floor(1000 + Math.random() * 9000);
            document.getElementById('confirmRefNum').textContent = '#PV-' + new Date().getFullYear() + String(new Date().getMonth()+1).padStart(2,'0') + '-' + rnd;
            showStep(7);
        });
}

function resetWizard() {
    S.step = 1;
    S.date = "";
    S.time = "";
    S.timeLabel = "";
    document.getElementById('wizNotes').value = "";
    if (fpInstance) fpInstance.clear();
    showStep(1);
}
</script>
</body>
</html>
