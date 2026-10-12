<?php
session_start();
include("dbconnection.php");

if(!isset($_SESSION['user_id']) || !isset($_GET['pet_id'])){
    header("Location: index.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$pet_id = (int)$_GET['pet_id'];

// Verify Ownership and Fetch Pet + Owner Data
$stmt = $conn->prepare("
    SELECT p.*, u.fullname, u.email, u.address, u.contact_number 
    FROM pets p 
    JOIN users u ON p.user_id = u.id 
    WHERE p.id=? AND p.user_id=?
");
$stmt->bind_param("ii", $pet_id, $user_id);
$stmt->execute();
$pet = $stmt->get_result()->fetch_assoc();

if(!$pet) {
    echo "Unauthorized Access.";
    exit();
}

// Fetch Latest Health Log
$h_stmt = $conn->prepare("
    SELECT c.*, a.appointment_date 
    FROM consultations c 
    JOIN appointments a ON c.appointment_id = a.id 
    WHERE a.pet_name = ? AND a.user_id = ?
    ORDER BY a.appointment_date DESC LIMIT 1
");
$h_stmt->bind_param("si", $pet['name'], $user_id);
$h_stmt->execute();
$history = $h_stmt->get_result()->fetch_assoc();

$curr_date = date("F j, Y");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Medical Certificate - <?php echo htmlspecialchars($pet['name']); ?></title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Old+Standard+TT:ital,wght@0,400;0,700;1,400&family=Playfair+Display:ital,wght@0,400;0,600;0,700;1,400&display=swap');
        
        body { margin: 0; padding: 0; font-family: Arial, sans-serif; color: #1a1a1a; background: #e2e8f0; }
        .page { background: white; max-width: 800px; margin: 40px auto; padding: 60px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border-radius: 4px; position: relative; }
        
        .header { text-align: center; border-bottom: 2px solid #3b82f6; padding-bottom: 20px; margin-bottom: 30px; }
        .header h1 { font-family: 'Playfair Display', serif; font-size: 38px; color: #2563eb; margin: 0; text-transform: uppercase; letter-spacing: 2px; }
        .header p { margin: 5px 0 0; font-size: 14px; color: #666; font-style: italic; }
        
        .title { text-align: center; margin: 40px 0; }
        .title h2 { font-family: 'Old Standard TT', serif; font-size: 32px; letter-spacing: 1px; margin: 0; }
        
        .data-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-bottom: 40px; }
        .box { border: 1px solid #e1e8ed; border-radius: 8px; padding: 20px; background: #fafcff; }
        .box h4 { margin: 0 0 15px; font-size: 14px; text-transform: uppercase; color: #3b82f6; border-bottom: 1px solid #eee; padding-bottom: 8px; }
        
        .row { display: flex; justify-content: space-between; margin-bottom: 10px; font-size: 15px; }
        .label { font-weight: bold; color: #555; }
        .value { color: #111; }
        
        .clinical-notes { background: #fffcf5; border: 1px solid #f2e3c6; padding: 25px; border-radius: 8px; margin-bottom: 40px; }
        .clinical-notes h4 { color: #d09a2b; margin: 0 0 15px; text-transform: uppercase; font-size: 14px; }
        .clinical-notes p { line-height: 1.6; margin: 0; font-family: 'Old Standard TT', serif; font-size: 18px; }
        
        .footer-sig { display: flex; justify-content: space-between; margin-top: 80px; align-items: flex-end; }
        .sig-block { text-align: center; width: 40%; }
        .sig-line { border-top: 1px solid #333; margin-top: 50px; padding-top: 10px; font-weight: bold; }
        
        .print-btn { position: fixed; bottom: 30px; right: 30px; background: #2563eb; color: white; padding: 15px 30px; border: none; border-radius: 50px; font-size: 16px; font-weight: bold; cursor: pointer; box-shadow: 0 4px 15px rgba(0,0,0,0.2); }
        .print-btn:hover { background: #1e40af; }

        @media print {
            body { background: white; margin: 0; padding: 0; }
            .page { box-shadow: none; margin: 0; padding: 20px; width: 100%; border: none; border-radius: 0; max-width: none; }
            .print-btn { display: none; }
            @page { margin: 1.5cm; }
        }
    </style>
    <link rel="stylesheet" href="assets/pragmavet.css">
    <link href="assets/global-style.css" rel="stylesheet">
</head>
<body>

<div class="page">
    <div class="header">
        <h1>PragmaVet</h1>
        <p>123 Clinic Avenue, Wellness District, NY 10001 â€¢ (555) 123-4567 â€¢ www.pragmavet.com</p>
    </div>

    <div class="title">
        <h2>CERTIFICATE OF HEALTH AND VACCINATION</h2>
        <p style="color: #666; font-size: 14px;">Date of Issuance: <?php echo $curr_date; ?></p>
    </div>

    <div class="data-grid">
        <div class="box">
            <h4>Animal Information</h4>
            <div class="row"><span class="label">Name:</span> <span class="value"><?php echo htmlspecialchars($pet['name']); ?></span></div>
            <div class="row"><span class="label">Species / Breed:</span> <span class="value"><?php echo htmlspecialchars($pet['species']) . ' / ' . htmlspecialchars($pet['breed']); ?></span></div>
            <div class="row"><span class="label">Age:</span> <span class="value"><?php echo $pet['age'] ? htmlspecialchars($pet['age']).' yrs' : 'N/A'; ?></span></div>
            <div class="row"><span class="label">Weight:</span> <span class="value"><?php echo $pet['weight'] ? htmlspecialchars($pet['weight']).' kg' : 'N/A'; ?></span></div>
        </div>

        <div class="box">
            <h4>Owner Details</h4>
            <div class="row"><span class="label">Full Name:</span> <span class="value"><?php echo htmlspecialchars($pet['fullname']); ?></span></div>
            <div class="row"><span class="label">Contact:</span> <span class="value"><?php echo htmlspecialchars($pet['contact_number'] ?: 'N/A'); ?></span></div>
            <div class="row"><span class="label">Email Address:</span> <span class="value"><?php echo htmlspecialchars($pet['email']); ?></span></div>
            <div class="row"><span class="label">Location:</span> <span class="value"><?php echo htmlspecialchars($pet['address'] ?: 'N/A'); ?></span></div>
        </div>
    </div>

    <div class="box" style="margin-bottom: 40px;">
        <h4>Immunization Record</h4>
        <div class="row"><span class="label">Date of Last Vaccination:</span> <span class="value"><?php echo $pet['last_vaccination'] ? date("F j, Y", strtotime($pet['last_vaccination'])) : 'Strictly Not Recorded / Unknown'; ?></span></div>
        <div class="row"><span class="label">Next Vaccination Due:</span> <span class="value" style="color: #D32F2F; font-weight: bold;"><?php echo $pet['next_vaccination'] ? date("F j, Y", strtotime($pet['next_vaccination'])) : 'Consult Veterinarian Immediately'; ?></span></div>
    </div>

    <div class="clinical-notes">
        <h4>Recent Clinical Observations</h4>
        <p>
            <?php 
                if($history) {
                    echo "On <b>" . date("F j, Y", strtotime($history['appointment_date'])) . "</b>, the animal underwent a formal consultation handled by " . htmlspecialchars($history['vet_name']) . ".<br><br>";
                    echo "<b>Primary Diagnosis:</b> " . htmlspecialchars($history['diagnosis']) . "<br>";
                    echo "<b>Prescribed Path:</b> " . htmlspecialchars($history['treatment']);
                } else {
                    echo "No recent clinical observations or formal records exist for this animal within the PragmaVet system database.";
                }
            ?>
        </p>
    </div>

    <p style="text-align: center; font-size: 13px; color: #777; line-height: 1.5;">This document is generated digitally and secured by PragmaVet Systems. It certifies that the above animal has been reviewed by licensed professionals at our facility.</p>

    <div class="footer-sig">
        <div class="sig-block">
            <div class="sig-line">Clinic Administrator</div>
        </div>
        <div class="sig-block">
            <div class="sig-line">Licensed Veterinarian</div>
        </div>
    </div>
</div>

<button class="print-btn" onclick="window.print();">ðŸ–¨ï¸ Export PDF / Print</button>

<script>
    // Prompt print dialogue seamlessly after a short delay
    setTimeout(() => { window.print(); }, 800);
</script>

    <script src="mousetrail.js"></script>
</body>
</html>
