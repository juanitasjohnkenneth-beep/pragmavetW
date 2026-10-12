<?php
// Directly extract the JS block from appointments.php and check for syntax issues
session_start();
$_SESSION['user_id'] = 6;
$_SESSION['user'] = 'Test Client';

// Simulate all PHP variables that appointments.php uses
include('c:/xampp/htdocs/pragmavet/dbconnection.php');
include_once('c:/xampp/htdocs/pragmavet/paymongo_config.php');

$user_id = 6;
$user_name = 'Test Client';
$myAddr = 'San Salitran 45';
$pets_for_form = [];
$bookedSlotsJSON = '{}';
$bookedDetailedJSON = '{}';
$holidaysJSON = '[]';
$doctorSchedulesJSON = '{}';
$serviceDurationsJSON = '{}';
$apptDensityJSON = '{}';

// Get the full appointments.php content
$content = file_get_contents('c:/xampp/htdocs/pragmavet/appointments.php');

// Extract just the <script> blocks
preg_match_all('/<script>(.*?)<\/script>/s', $content, $m);

foreach ($m[1] as $i => $jsBlock) {
    if (strpos($jsBlock, 'openDetailModal') !== false) {
        echo "Found openDetailModal in script block #$i\n";
        
        // Check for PHP tags still inside (would break JS)
        if (strpos($jsBlock, '<?') !== false) {
            echo "WARNING: PHP tags still present in JS block! They need to be processed.\n";
            // Count PHP tags
            $count = substr_count($jsBlock, '<?');
            echo "PHP tag count: $count\n";
        } else {
            echo "No raw PHP tags (already processed)\n";
        }
        
        // Check for the detailModalBackdrop
        if (strpos($jsBlock, 'detailModalBackdrop') !== false) {
            echo "Modal backdrop reference found in JS: YES\n";
        } else {
            echo "Modal backdrop reference found in JS: NO\n";
        }
        
        // Find openDetailModal function position
        $pos = strpos($jsBlock, 'function openDetailModal');
        echo "openDetailModal position in block: " . ($pos !== false ? $pos : "NOT FOUND") . "\n";
        
        // Find closeDetailModal
        $pos2 = strpos($jsBlock, 'function closeDetailModal');
        echo "closeDetailModal position: " . ($pos2 !== false ? $pos2 : "NOT FOUND") . "\n";
        
        // Check if it's closed properly (look for final closing brace)
        $lines = explode("\n", $jsBlock);
        echo "Total lines in block: " . count($lines) . "\n";
        echo "Last 5 lines:\n";
        $lastLines = array_slice($lines, -5);
        foreach ($lastLines as $l) echo "  " . trim($l) . "\n";
    }
}
?>
