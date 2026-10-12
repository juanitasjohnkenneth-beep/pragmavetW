<?php
$file_path = __DIR__ . '/../appointments_wizard.php';
$content = file_get_contents($file_path);

// Update pet fetching block in appointments_wizard.php
$old_pet_block = '// ── Fetch Pets ────────────────────────────────────────────────────────────────
$pets = [];
$stmt_pets = $conn->prepare("SELECT id, name, species, breed, TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) as age FROM pets WHERE user_id=? AND is_active=1 ORDER BY name ASC");
if (!$stmt_pets) {
    $stmt_pets = $conn->prepare("SELECT id, name, species, breed, 0 as age FROM pets WHERE user_id=? ORDER BY name ASC");
}
if ($stmt_pets) {
    $stmt_pets->bind_param("i", $user_id); 
    $stmt_pets->execute();
    $pets = $stmt_pets->get_result()->fetch_all(MYSQLI_ASSOC);
}';

$new_pet_block = '// ── Fetch Pets ────────────────────────────────────────────────────────────────
$pets = [];
$r_p = $conn->query("SELECT id, name, species, breed FROM pets WHERE user_id=$user_id ORDER BY name ASC");
if ($r_p && $r_p->num_rows > 0) {
    while ($pt = $r_p->fetch_assoc()) {
        $pets[] = $pt;
    }
}';

if (strpos($content, '$pets = [];') !== false) {
    $content = str_replace($old_pet_block, $new_pet_block, $content);
}

file_put_contents($file_path, $content);
echo "SUCCESS: appointments_wizard.php pet fetch synced!\n";
