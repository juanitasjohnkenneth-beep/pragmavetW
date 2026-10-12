<?php
include("dbconnection.php");

$query = "
CREATE TABLE IF NOT EXISTS system_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    clinic_name VARCHAR(255) DEFAULT 'PragmaVet Hub',
    clinic_address VARCHAR(255) DEFAULT '123 Clinic Avenue, Wellness District, NY 10001',
    clinic_phone VARCHAR(50) DEFAULT '(555) 123-4567',
    smtp_user VARCHAR(100) DEFAULT 'timferrer1@gmail.com',
    smtp_pass VARCHAR(255) DEFAULT 'azrpmoxxzvhucetu',
    stripe_pub_key VARCHAR(255) DEFAULT 'pk_test_placeholder',
    stripe_sec_key VARCHAR(255) DEFAULT 'sk_test_placeholder',
    dark_mode_default BOOLEAN DEFAULT 0,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";

if($conn->query($query)){
    echo "system_settings table generated.\n";
    
    // Seed initial properties if empty
    $check = $conn->query("SELECT id FROM system_settings LIMIT 1");
    if($check->num_rows == 0){
        $conn->query("INSERT INTO system_settings(clinic_name) VALUES('PragmaVet Hub')");
        echo "Default settings row seeded natively.\n";
    }
} else {
    echo "Error inserting framework: " . $conn->error;
}
?>
