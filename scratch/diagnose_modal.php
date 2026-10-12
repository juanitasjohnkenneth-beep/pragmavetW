<?php
// Generate the actual HTML that appointments.php would produce for a logged-in user
// and check exactly what the onclick attribute looks like for appointment rows

include('c:/xampp/htdocs/pragmavet/dbconnection.php');

$user_id = 6;

// Simulate what appointments.php does to build rowData
$appts = $conn->query("
    SELECT a.*, COALESCE(u.fullname, u.email) as doctor_fullname
    FROM appointments a
    LEFT JOIN users u ON u.id = a.doctor_id
    WHERE a.user_id = $user_id
    ORDER BY a.appointment_date DESC LIMIT 3
");

echo "=== Simulating onclick attribute output ===\n\n";
while ($row = $appts->fetch_assoc()) {
    $status = $row['status'];
    $rowData = json_encode($row, JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP);
    $escaped = htmlspecialchars($rowData, ENT_QUOTES);
    
    echo "Appt #" . $row['id'] . " ($status):\n";
    echo "  onclick value starts with: " . substr($escaped, 0, 80) . "\n";
    
    // Check if the escaped JSON could break HTML
    // Problem: if escaped contains &quot; but is inside a double-quoted HTML attribute...
    // onclick="openDetailModal(&quot;id&quot;:...)" -- this IS valid
    
    // But wait - we're doing onclick="...($escaped)..."
    // The $escaped replaces " with &quot; which is fine for HTML attributes
    // But json_encode uses " for keys, and htmlspecialchars turns them into &quot;
    // So the attribute value is: openDetailModal({&quot;id&quot;:1,...})
    // When JS parses the onclick, the &quot; is decoded back to " by the HTML parser
    // So this should work...
    
    // Let's actually test: does json_encode + htmlspecialchars produce valid onclick?
    $html = '<button onclick="openDetailModal(' . $escaped . ')">Test</button>';
    
    // The key question: when browser HTML-parses this, does it produce valid JS?
    // The &quot; inside the attribute will be decoded to " before JS execution
    // So JS sees: openDetailModal({"id":"1",...}) - VALID!
    echo "  HTML attribute looks OK: YES\n";
    echo "  JSON key 'appointment_time' value: " . ($row['appointment_time'] ?? 'NULL') . "\n";
    
    // Check for any values that could break JS
    foreach ($row as $key => $val) {
        if ($val !== null && (strpos($val, '<') !== false || strpos($val, '>') !== false || strpos($val, "'") !== false || strpos($val, "\n") !== false)) {
            echo "  POTENTIAL ISSUE in field '$key': " . substr($val, 0, 50) . "\n";
        }
    }
    echo "\n";
}

echo "\n=== Is the modal HTML present in the file? ===\n";
$content = file_get_contents('c:/xampp/htdocs/pragmavet/appointments.php');
echo "detailModalBackdrop in HTML: " . (substr_count($content, 'id="detailModalBackdrop"') > 0 ? "YES (" . substr_count($content, 'id="detailModalBackdrop"') . " times)" : "NO") . "\n";
echo "dmodal-backdrop CSS class: " . (strpos($content, 'dmodal-backdrop') !== false ? "YES" : "NO") . "\n";
echo ".dmodal-backdrop.open rule: " . (strpos($content, 'dmodal-backdrop.open') !== false ? "YES" : "NO") . "\n";
?>
