<?php
// The REAL problem test - simulate exactly what browser sees
// When PHP outputs: onclick="openDetailModal({&quot;id&quot;:&quot;69&quot;})"
// The browser HTML parser decodes &quot; -> "
// So JS runtime sees: openDetailModal({"id":"69"})
// This is a JS object literal - VALID. openDetailModal(row) receives an object. That's correct.

// But wait - let's check if maybe the onclick is using single quotes instead of double quotes somewhere
$content = file_get_contents('c:/xampp/htdocs/pragmavet/appointments.php');

// Find the exact onclick line for the Pending/Approved case
preg_match_all('/onclick="[^"]*openDetailModal[^"]*"/', $content, $matches);

echo "=== All onclick=\" openDetailModal instances ===\n";
foreach ($matches[0] as $i => $m) {
    echo "#$i: " . substr($m, 0, 120) . "\n\n";
}

// Also check the tr onclick
preg_match_all("/onclick='[^']*openDetailModal[^']*'/", $content, $matches2);
echo "\n=== All onclick=' openDetailModal instances ===\n";
foreach ($matches2[0] as $i => $m) {
    echo "#$i: " . substr($m, 0, 120) . "\n\n";
}

// Now check if the modal backdrop has display:flex
preg_match('/\.dmodal-backdrop\.open\s*\{([^}]+)\}/', $content, $css);
echo "\n=== .dmodal-backdrop.open CSS ===\n";
echo $css[1] ?? "NOT FOUND\n";

// Check if the backdrop starts hidden
preg_match('/id="detailModalBackdrop"[^>]*>/', $content, $bdMatch);
echo "\n=== detailModalBackdrop opening tag ===\n";
echo $bdMatch[0] ?? "NOT FOUND\n";
?>
