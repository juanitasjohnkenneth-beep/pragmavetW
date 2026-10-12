<?php
session_start();
include("dbconnection.php");

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    die("Invalid consultation ID.");
}

$stmt = $conn->prepare("
    SELECT c.*, a.pet_name, a.service_type, a.appointment_date, u.fullname as owner_name, u.contact_number, p.species, p.breed, p.weight, p.age
    FROM consultations c
    JOIN appointments a ON c.appointment_id = a.id
    JOIN users u ON a.user_id = u.id
    LEFT JOIN pets p ON (p.user_id = u.id AND p.name = a.pet_name)
    WHERE c.id = ?
    LIMIT 1
");
$stmt->bind_param("i", $id);
$stmt->execute();
$res = $stmt->get_result();

if (!$res || $res->num_rows === 0) {
    die("Consultation record not found.");
}

$data = $res->fetch_assoc();

// Clients may only view prescriptions for their own appointments
include_once("includes/rbac_guard.php");
if (in_array(rbac_get_role(), ['user', 'client'], true)) {
    $own = $conn->prepare("SELECT 1 FROM appointments WHERE id = ? AND user_id = ?");
    $own->bind_param("ii", $data['appointment_id'], $_SESSION['user_id']);
    $own->execute();
    if ($own->get_result()->num_rows === 0) {
        die("Consultation record not found.");
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Prescription Slip (Rx) - <?= htmlspecialchars($data['pet_name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: #f8fafc;
            font-family: 'Georgia', serif;
            color: #0f172a;
            padding: 40px 20px;
        }
        .rx-card {
            max-width: 650px;
            margin: 0 auto;
            background: #ffffff;
            border: 2px solid #0f172a;
            border-radius: 12px;
            padding: 40px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            position: relative;
        }
        .rx-symbol {
            font-size: 48px;
            font-weight: bold;
            font-family: 'Times New Roman', serif;
            color: #2563eb;
            line-height: 1;
        }
        .border-dotted {
            border-bottom: 1px dotted #94a3b8;
        }
        .signature-line {
            width: 240px;
            border-top: 1px solid #0f172a;
            margin-top: 50px;
            text-align: center;
            font-size: 13px;
            font-family: sans-serif;
        }
        @media print {
            body { background: white; padding: 0; }
            .rx-card { border: 2px solid #000; box-shadow: none; width: 100%; max-width: 100%; border-radius: 0; padding: 30px; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>

<div class="text-center mb-4 no-print">
    <button onclick="window.print()" class="btn btn-primary btn-lg rounded-pill px-5 fw-bold shadow">
        <i class="fa fa-print me-2"></i> Print Prescription Slip (Rx)
    </button>
    <button onclick="window.close()" class="btn btn-outline-secondary btn-lg rounded-pill px-4 ms-2">
        Close Window
    </button>
</div>

<div class="rx-card">
    <!-- Clinic Header -->
    <div class="text-center border-bottom pb-3 mb-4">
        <h2 class="fw-bold text-uppercase m-0" style="letter-spacing: 1px; color: #1e3a8a;">PragmaVet Animal Clinic</h2>
        <div class="small text-muted" style="font-family: sans-serif;">Veterinary Healthcare & Clinical Services</div>
        <div class="small text-muted" style="font-family: sans-serif;">Contact: (02) 8123-4567 | Email: care@pragmavet.com</div>
    </div>

    <!-- Patient & Owner Info Bar -->
    <div class="row g-2 mb-4 pb-3 border-dotted font-monospace" style="font-size: 13px; font-family: sans-serif !important;">
        <div class="col-6"><b>Patient (Pet):</b> <?= htmlspecialchars($data['pet_name']) ?></div>
        <div class="col-6"><b>Date:</b> <?= date("M d, Y", strtotime($data['created_at'])) ?></div>
        <div class="col-6"><b>Species / Breed:</b> <?= htmlspecialchars($data['species'] ?? 'Pet') ?> (<?= htmlspecialchars($data['breed'] ?? 'N/A') ?>)</div>
        <div class="col-6"><b>Owner Name:</b> <?= htmlspecialchars($data['owner_name']) ?></div>
        <div class="col-6"><b>Weight:</b> <?= ($data['weight'] ?? 0) ?> kg</div>
        <div class="col-6"><b>Contact:</b> <?= htmlspecialchars($data['contact_number'] ?? 'N/A') ?></div>
    </div>

    <!-- Diagnosis Header -->
    <div class="mb-4">
        <div class="small text-muted text-uppercase fw-bold" style="font-family: sans-serif;">Clinical Diagnosis:</div>
        <div class="fs-5 fw-bold text-dark"><?= htmlspecialchars($data['diagnosis']) ?></div>
    </div>

    <!-- Rx Body -->
    <div class="d-flex align-items-start gap-3 mb-4">
        <div class="rx-symbol">℞</div>
        <div class="flex-fill pt-2">
            <div class="small text-muted text-uppercase fw-bold mb-2" style="font-family: sans-serif;">Prescribed Medications & Dosage:</div>
            <div class="fs-6 lh-base p-3 bg-light rounded border" style="font-family: sans-serif; white-space: pre-line;">
                <?= htmlspecialchars($data['prescription'] ?: "No prescription items specified.") ?>
            </div>
        </div>
    </div>

    <!-- Treatment / Special Instructions -->
    <?php if(!empty($data['treatment'])): ?>
    <div class="mb-4">
        <div class="small text-muted text-uppercase fw-bold mb-1" style="font-family: sans-serif;">Treatment & Care Instructions:</div>
        <div class="small text-dark" style="font-family: sans-serif;"><?= nl2br(htmlspecialchars($data['treatment'])) ?></div>
    </div>
    <?php endif; ?>

    <!-- Follow up Date -->
    <?php if(!empty($data['followup_date'])): ?>
    <div class="p-2.5 bg-primary bg-opacity-10 border border-primary border-opacity-25 rounded mb-4 text-primary fw-bold" style="font-family: sans-serif; font-size: 13px;">
        <i class="fa fa-calendar-alt me-2"></i> Recommended Follow-Up Visit: <?= date("F d, Y (l)", strtotime($data['followup_date'])) ?>
    </div>
    <?php endif; ?>

    <!-- Footer Signature Space -->
    <div class="d-flex justify-content-between align-items-end mt-5 pt-3">
        <div class="small text-muted" style="font-family: sans-serif; font-size: 11px;">
            <div>* Valid only when manually signed by attending veterinarian.</div>
            <div>PragmaVet Medical Rx System</div>
        </div>
        <div>
            <div class="signature-line">
                <div class="fw-bold"><?= htmlspecialchars($data['vet_name'] ?: 'Attending Veterinarian') ?></div>
                <div class="text-muted small">Licensed Veterinarian (PRC Reg. #)</div>
            </div>
        </div>
    </div>
</div>

</body>
</html>
