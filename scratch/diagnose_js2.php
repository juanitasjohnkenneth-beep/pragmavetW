<?php
// Check what line the openDetailModal is on in the file
$content = file_get_contents('c:/xampp/htdocs/pragmavet/appointments.php');
$lines = explode("\n", $content);

echo "=== Searching for openDetailModal ===\n";
foreach ($lines as $i => $line) {
    if (strpos($line, 'function openDetailModal') !== false) {
        echo "Line " . ($i+1) . ": " . trim($line) . "\n";
    }
}

echo "\n=== Searching for detailModalBackdrop.classList ===\n";
foreach ($lines as $i => $line) {
    if (strpos($line, 'detailModalBackdrop') !== false && strpos($line, 'classList') !== false) {
        echo "Line " . ($i+1) . ": " . trim($line) . "\n";
    }
}

echo "\n=== Searching for script tag that has openDetailModal ===\n";
// Find all script opening tags
$in_script = false;
$script_start = 0;
$found = false;
foreach ($lines as $i => $line) {
    if (strpos($line, '<script>') !== false && strpos($line, 'src=') === false) {
        $in_script = true;
        $script_start = $i+1;
    }
    if ($in_script && strpos($line, 'function openDetailModal') !== false) {
        echo "Script started at line $script_start, openDetailModal at line " . ($i+1) . "\n";
        $found = true;
    }
    if ($in_script && strpos($line, '</script>') !== false) {
        if ($found) {
            echo "Script ends at line " . ($i+1) . "\n";
        }
        $in_script = false;
        $found = false;
    }
}

echo "\n=== Lines AROUND where feedback modal script tag opens ===\n";
foreach ($lines as $i => $line) {
    if (strpos($line, 'autoFeedbackModal') !== false && strpos($line, '<script>') !== false) {
        echo "Feedback script opens at line " . ($i+1) . "\n";
        // Show 5 lines before
        for ($j = max(0,$i-3); $j <= min(count($lines)-1,$i+5); $j++) {
            echo "  L" . ($j+1) . ": " . trim($lines[$j]) . "\n";
        }
    }
}
?>
