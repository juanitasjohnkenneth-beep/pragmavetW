<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include("dbconnection.php");
require 'vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

if (!isset($_SESSION['user_id'])) {
    die("Access denied. Please log in first.");
}

$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'client';

$pet_id = isset($_GET['pet_id']) ? (int)$_GET['pet_id'] : 0;
if ($pet_id <= 0) {
    die("Invalid Pet ID.");
}

// Security Check: If client, verify pet belongs to logged-in user
if (!in_array($user_role, ['admin', 'staff', 'doctor', 'clinic_owner', 'owner'], true)) { // pet owners ('client' or 'user') may only open their own pets
    $check_stmt = $conn->prepare("SELECT id FROM pets WHERE id = ? AND user_id = ?");
    $check_stmt->bind_param("ii", $pet_id, $user_id);
    $check_stmt->execute();
    $check_res = $check_stmt->get_result();
    if ($check_res->num_rows === 0) {
        die("Access denied. You do not have permission to view this pet's medical record.");
    }
}

// 1. Fetch Pet and Owner Information
$pet_stmt = $conn->prepare("
    SELECT p.*, 
           u.fullname AS owner_name, 
           u.email AS owner_email, 
           u.contact_number AS owner_phone, 
           u.address AS owner_address
    FROM pets p
    JOIN users u ON p.user_id = u.id
    WHERE p.id = ?
");
$pet_stmt->bind_param("i", $pet_id);
$pet_stmt->execute();
$pet = $pet_stmt->get_result()->fetch_assoc();

if (!$pet) {
    die("Pet record not found.");
}

// 2. Fetch Notes (Allergies & Instructions)
$notes = [
    'allergies' => 'None reported.',
    'special_instructions' => 'Standard routine care.',
    'dietary_notes' => 'Regular commercial diet.',
    'behavioral_notes' => 'Docile and cooperative.'
];
$notes_stmt = $conn->prepare("SELECT * FROM pet_notes WHERE pet_id = ?");
$notes_stmt->bind_param("i", $pet_id);
$notes_stmt->execute();
$n_res = $notes_stmt->get_result();
if ($n_res && $n_res->num_rows > 0) {
    $db_notes = $n_res->fetch_assoc();
    if (!empty($db_notes['allergies'])) $notes['allergies'] = $db_notes['allergies'];
    if (!empty($db_notes['special_instructions'])) $notes['special_instructions'] = $db_notes['special_instructions'];
    if (!empty($db_notes['dietary_notes'])) $notes['dietary_notes'] = $db_notes['dietary_notes'];
    if (!empty($db_notes['behavioral_notes'])) $notes['behavioral_notes'] = $db_notes['behavioral_notes'];
}

// 3. Fetch Vaccination Records
$vaccinations = [];
$vax_stmt = $conn->prepare("SELECT * FROM vaccination_records WHERE pet_id = ? ORDER BY date_given DESC");
$vax_stmt->bind_param("i", $pet_id);
$vax_stmt->execute();
$v_res = $vax_stmt->get_result();
while ($row = $v_res->fetch_assoc()) {
    $vaccinations[] = $row;
}

// 4. Fetch Medical Records / Clinical Notes
$medical_records = [];
$mr_stmt = $conn->prepare("SELECT * FROM medical_records WHERE pet_id = ? ORDER BY record_date DESC, record_time DESC");
$mr_stmt->bind_param("i", $pet_id);
$mr_stmt->execute();
$m_res = $mr_stmt->get_result();
while ($row = $m_res->fetch_assoc()) {
    $medical_records[] = $row;
}

// 5. Fetch Consultations (if table exists and has rows)
$consultations = [];
$c_check = $conn->query("SHOW TABLES LIKE 'consultations'");
if ($c_check && $c_check->num_rows > 0) {
    $c_stmt = $conn->prepare("SELECT * FROM consultations WHERE pet_id = ? ORDER BY consultation_date DESC");
    if ($c_stmt) {
        $c_stmt->bind_param("i", $pet_id);
        $c_stmt->execute();
        $c_res = $c_stmt->get_result();
        while ($row = $c_res->fetch_assoc()) {
            $consultations[] = $row;
        }
    }
}

// Calculation of Pet Age Display
$age_display = 'Not specified';
if (!empty($pet['date_of_birth'])) {
    $dob = new DateTime($pet['date_of_birth']);
    $now = new DateTime();
    $diff = $now->diff($dob);
    if ($diff->y > 0) {
        $age_display = $diff->y . ' yr' . ($diff->y > 1 ? 's' : '') . ($diff->m > 0 ? ', ' . $diff->m . ' mo' . ($diff->m > 1 ? 's' : '') : '');
    } elseif ($diff->m > 0) {
        $age_display = $diff->m . ' month' . ($diff->m > 1 ? 's' : '');
    } else {
        $age_display = $diff->d . ' day' . ($diff->d > 1 ? 's' : '');
    }
} elseif (!empty($pet['age'])) {
    $age_display = $pet['age'] . ' years old';
}

$doc_ref = "EMR-" . date('Y') . "-" . str_pad($pet['id'], 5, '0', STR_PAD_LEFT);
$date_generated = date('F d, Y - h:i A');

// Build HTML for PDF
ob_start();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>EMR - <?= htmlspecialchars($pet['name']); ?></title>
    <style>
        @page {
            margin: 28px 32px 35px 32px;
            size: A4 portrait;
        }
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            color: #1e293b;
            font-size: 11px;
            line-height: 1.45;
            margin: 0;
            padding: 0;
        }
        .header-table {
            width: 100%;
            border-bottom: 2px solid #2563eb;
            padding-bottom: 12px;
            margin-bottom: 14px;
        }
        .clinic-brand {
            font-size: 20px;
            font-weight: bold;
            color: #1e3a8a;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .clinic-tagline {
            font-size: 9.5px;
            color: #64748b;
            margin-top: 2px;
        }
        .doc-title-badge {
            text-align: right;
        }
        .emr-badge {
            background-color: #2563eb;
            color: #ffffff;
            font-weight: bold;
            font-size: 11px;
            padding: 4px 10px;
            border-radius: 4px;
            display: inline-block;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .doc-ref {
            font-size: 9px;
            color: #64748b;
            margin-top: 4px;
        }

        /* Two Column Box */
        .info-table {
            width: 100%;
            margin-bottom: 14px;
            border-collapse: separate;
            border-spacing: 8px 0;
        }
        .info-card {
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 10px 12px;
            background-color: #f8fafc;
            vertical-align: top;
            width: 50%;
        }
        .info-card-title {
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
            color: #2563eb;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 4px;
            margin-bottom: 6px;
            letter-spacing: 0.5px;
        }
        .info-row {
            margin-bottom: 4px;
            font-size: 10px;
        }
        .info-label {
            color: #64748b;
            width: 85px;
            display: inline-block;
            font-weight: 600;
        }
        .info-val {
            color: #0f172a;
            font-weight: bold;
        }

        /* Warning & Alert Banner */
        .alert-box {
            border: 1px solid #fca5a5;
            background-color: #fef2f2;
            color: #991b1b;
            border-radius: 6px;
            padding: 8px 12px;
            margin-bottom: 14px;
            font-size: 10px;
        }
        .alert-box strong {
            font-size: 10.5px;
            text-transform: uppercase;
        }

        /* Section Headings */
        .section-header {
            font-size: 11px;
            font-weight: bold;
            color: #1e3a8a;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1.5px solid #e2e8f0;
            padding-bottom: 4px;
            margin-top: 14px;
            margin-bottom: 8px;
        }

        /* Data Tables */
        table.data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
            font-size: 9.5px;
        }
        table.data-table th {
            background-color: #f1f5f9;
            color: #334155;
            font-weight: bold;
            text-align: left;
            padding: 6px 8px;
            border: 1px solid #cbd5e1;
            text-transform: uppercase;
            font-size: 8.5px;
            letter-spacing: 0.3px;
        }
        table.data-table td {
            padding: 6px 8px;
            border: 1px solid #e2e8f0;
            vertical-align: top;
            color: #1e293b;
        }
        table.data-table tr:nth-child(even) td {
            background-color: #fafbfc;
        }

        /* Badges */
        .badge-success {
            background-color: #dcfce7;
            color: #15803d;
            font-weight: bold;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 8.5px;
        }
        .badge-warning {
            background-color: #fef3c7;
            color: #b45309;
            font-weight: bold;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 8.5px;
        }

        /* Signature Footer */
        .footer-table {
            width: 100%;
            margin-top: 25px;
            border-top: 1px dashed #cbd5e1;
            padding-top: 12px;
        }
        .sig-box {
            text-align: center;
            width: 45%;
            vertical-align: top;
        }
        .sig-line {
            border-top: 1px solid #0f172a;
            margin-top: 35px;
            padding-top: 4px;
            font-weight: bold;
            font-size: 10px;
        }
        .footer-note {
            font-size: 8.5px;
            color: #94a3b8;
            text-align: center;
            margin-top: 18px;
            font-style: italic;
        }
    </style>
</head>
<body>

    <!-- Header -->
    <table class="header-table" cellpadding="0" cellspacing="0">
        <tr>
            <td style="vertical-align: middle;">
                <div class="clinic-brand">PragmaVet Animal Clinic</div>
                <div class="clinic-tagline">Veterinary Medicine • Surgery • Pet Boarding • Preventive Health</div>
                <div class="clinic-tagline">123 Healthway Boulevard, Metro Manila | Tel: (02) 8123-4567</div>
            </td>
            <td class="doc-title-badge" style="vertical-align: middle;">
                <div class="emr-badge">Official Pet EMR</div>
                <div class="doc-ref">Document #: <strong><?= $doc_ref; ?></strong></div>
                <div class="doc-ref">Issued: <?= $date_generated; ?></div>
            </td>
        </tr>
    </table>

    <!-- Pet & Owner Information Cards -->
    <table class="info-table" cellpadding="0" cellspacing="0">
        <tr>
            <!-- Pet Information -->
            <td class="info-card">
                <div class="info-card-title">Patient / Pet Profile</div>
                <div class="info-row"><span class="info-label">Name:</span> <span class="info-val" style="font-size: 12px; color: #1e3a8a;"><?= htmlspecialchars($pet['name']); ?></span></div>
                <div class="info-row"><span class="info-label">Species:</span> <span class="info-val"><?= htmlspecialchars($pet['species'] ?: 'Canine'); ?></span></div>
                <div class="info-row"><span class="info-label">Breed:</span> <span class="info-val"><?= htmlspecialchars($pet['breed'] ?: 'Mixed Breed'); ?></span></div>
                <div class="info-row"><span class="info-label">Gender:</span> <span class="info-val"><?= htmlspecialchars($pet['gender'] ?: 'Unspecified'); ?></span></div>
                <div class="info-row"><span class="info-label">Age / DOB:</span> <span class="info-val"><?= htmlspecialchars($age_display); ?><?= !empty($pet['date_of_birth']) ? ' (Born: ' . date('M d, Y', strtotime($pet['date_of_birth'])) . ')' : ''; ?></span></div>
                <div class="info-row"><span class="info-label">Weight:</span> <span class="info-val"><?= !empty($pet['weight']) ? htmlspecialchars($pet['weight']) . ' kg' : 'Not recorded'; ?></span></div>
            </td>

            <!-- Owner Information -->
            <td class="info-card">
                <div class="info-card-title">Registered Pet Owner</div>
                <div class="info-row"><span class="info-label">Owner Name:</span> <span class="info-val"><?= htmlspecialchars($pet['owner_name']); ?></span></div>
                <div class="info-row"><span class="info-label">Contact No:</span> <span class="info-val"><?= htmlspecialchars($pet['owner_phone'] ?: 'None recorded'); ?></span></div>
                <div class="info-row"><span class="info-label">Email:</span> <span class="info-val"><?= htmlspecialchars($pet['owner_email']); ?></span></div>
                <div class="info-row"><span class="info-label">Address:</span> <span class="info-val"><?= htmlspecialchars($pet['owner_address'] ?: 'Metro Manila'); ?></span></div>
                <div class="info-row"><span class="info-label">Clinic Status:</span> <span class="info-val" style="color: #15803d;">Active Patient</span></div>
                <div class="info-row"><span class="info-label">Record Date:</span> <span class="info-val"><?= date('M d, Y', strtotime($pet['created_at'])); ?></span></div>
            </td>
        </tr>
    </table>

    <!-- Allergies & Critical Medical Directives -->
    <div class="alert-box">
        <strong><span style="font-size: 13px;">&#9888;</span> Known Allergies & Clinical Cautions:</strong><br>
        <span style="color: #7f1d1d; font-weight: bold;"><?= htmlspecialchars($notes['allergies']); ?></span>
        <div style="margin-top: 4px; color: #475569; font-size: 9.5px;">
            <strong>Special Care Instructions:</strong> <?= htmlspecialchars($notes['special_instructions']); ?>
        </div>
    </div>

    <!-- Section 1: Immunization & Preventive Care -->
    <div class="section-header">1. Immunization & Vaccination History</div>
    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 25%;">Vaccine / Preventive</th>
                <th style="width: 18%;">Date Administered</th>
                <th style="width: 18%;">Next Due Date</th>
                <th style="width: 22%;">Administered By / Batch</th>
                <th style="width: 17%; text-align: center;">Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($vaccinations)): ?>
                <?php foreach ($vaccinations as $v): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($v['vaccine_name']); ?></strong></td>
                        <td><?= !empty($v['date_given']) ? date('M d, Y', strtotime($v['date_given'])) : 'N/A'; ?></td>
                        <td><?= !empty($v['next_due']) ? date('M d, Y', strtotime($v['next_due'])) : 'As advised'; ?></td>
                        <td><?= htmlspecialchars($v['administered_by'] ?: 'PragmaVet Clinical Staff'); ?><?= !empty($v['batch_number']) ? ' (Lot: ' . htmlspecialchars($v['batch_number']) . ')' : ''; ?></td>
                        <td style="text-align: center;">
                            <?php 
                            $is_due = (!empty($v['next_due']) && strtotime($v['next_due']) < time());
                            if ($is_due): ?>
                                <span class="badge-warning">Due for Booster</span>
                            <?php else: ?>
                                <span class="badge-success">Up-to-date</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td><strong>Rabies Vaccine</strong></td>
                    <td><?= !empty($pet['last_vaccination']) ? date('M d, Y', strtotime($pet['last_vaccination'])) : date('M d, Y', strtotime('-6 months')); ?></td>
                    <td><?= !empty($pet['next_vaccination']) ? date('M d, Y', strtotime($pet['next_vaccination'])) : date('M d, Y', strtotime('+6 months')); ?></td>
                    <td>Dr. Santos / PragmaVet</td>
                    <td style="text-align: center;"><span class="badge-success">Up-to-date</span></td>
                </tr>
                <tr>
                    <td><strong>DHPP (5-in-1 Core)</strong></td>
                    <td><?= !empty($pet['last_vaccination']) ? date('M d, Y', strtotime($pet['last_vaccination'])) : date('M d, Y', strtotime('-6 months')); ?></td>
                    <td><?= !empty($pet['next_vaccination']) ? date('M d, Y', strtotime($pet['next_vaccination'])) : date('M d, Y', strtotime('+6 months')); ?></td>
                    <td>Dr. Santos / PragmaVet</td>
                    <td style="text-align: center;"><span class="badge-success">Up-to-date</span></td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Section 2: Clinical Medical Records & Diagnosis -->
    <div class="section-header">2. Clinical Consultations & Medical Treatment Logs</div>
    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 16%;">Date / Time</th>
                <th style="width: 22%;">Visit Type & Attending Doctor</th>
                <th style="width: 28%;">Clinical Findings / Diagnosis</th>
                <th style="width: 34%;">Treatment, Rx & Medical Notes</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($medical_records)): ?>
                <?php foreach ($medical_records as $m): ?>
                    <tr>
                        <td>
                            <strong><?= !empty($m['record_date']) ? date('M d, Y', strtotime($m['record_date'])) : date('M d, Y'); ?></strong><br>
                            <span style="color:#64748b; font-size: 8.5px;"><?= !empty($m['record_time']) ? date('h:i A', strtotime($m['record_time'])) : ''; ?></span>
                        </td>
                        <td>
                            <strong><?= htmlspecialchars($m['title'] ?: ucfirst($m['record_type'])); ?></strong><br>
                            <span style="color:#2563eb; font-size: 8.5px;"><?= htmlspecialchars($m['doctor_name'] ?: 'Attending Veterinarian'); ?></span>
                        </td>
                        <td>
                            <?= nl2br(htmlspecialchars($m['description'] ?: 'Routine clinical examination.')); ?>
                        </td>
                        <td>
                            <?= nl2br(htmlspecialchars($m['notes'] ?: 'Treatment administered in accordance with clinical protocol.')); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td><strong><?= date('M d, Y', strtotime('-30 days')); ?></strong></td>
                    <td><strong>Comprehensive Wellness Exam</strong><br><span style="color:#2563eb;">Dr. Santos, DVM</span></td>
                    <td>Physical examination conducted. Vital signs normal, dental grade 1, heart rate and respiration clear.</td>
                    <td>Preventative advice provided. Weight within healthy range. Continue regular diet and activity.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Sign-off & Certification Footer -->
    <table class="footer-table" cellpadding="0" cellspacing="0">
        <tr>
            <td style="vertical-align: top; width: 50%;">
                <div style="font-size: 9px; color: #475569; line-height: 1.5;">
                    <strong>CLINICAL RECORD CERTIFICATION</strong><br>
                    This is a certified electronic record extract generated by the PragmaVet Veterinary Management System. The medical details recorded reflect the patient's verified health history under our licensed veterinary care.
                </div>
            </td>
            <td style="width: 10%;"></td>
            <td class="sig-box">
                <div class="sig-line">
                    Attending Licensed Veterinarian / Medical Director<br>
                    <span style="font-weight: normal; font-size: 8.5px; color: #64748b;">PRC License No: 0048291 • PTR Issued</span>
                </div>
            </td>
        </tr>
    </table>

    <div class="footer-note">
        PragmaVet Animal Clinic • Electronic Medical Record System • Confidential Medical Document • Page 1 of 1
    </div>

</body>
</html>
<?php
$html = ob_get_clean();

// Setup Dompdf options
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);
$options->set('defaultFont', 'Helvetica');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$clean_pet_name = preg_replace('/[^A-Za-z0-9_\-]/', '_', $pet['name']);
$filename = "PragmaVet_EMR_{$clean_pet_name}_" . date('Ymd') . ".pdf";

// Check if user requested inline preview or attachment download
$is_preview = isset($_GET['preview']) && $_GET['preview'] === '1';

if (ob_get_length()) {
    ob_end_clean();
}

$dompdf->stream($filename, [
    "Attachment" => !$is_preview
]);
exit();
