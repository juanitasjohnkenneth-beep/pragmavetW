<?php
$file = 'c:/xampp/htdocs/pragmavet/admin_list.php';
$content = file_get_contents($file);

// Update JS so Audit Trail always shows
$js_old = <<<JS
    // Show Audit Trail
    var auditW = document.getElementById('dm-audit-w');
    if (d.admin_message) {
        auditW.style.display = 'block';
        document.getElementById('dm-audit').textContent = d.admin_message;
    } else {
        auditW.style.display = 'none';
    }
JS;

$js_new = <<<JS
    // Show Audit Trail (Always visible)
    var auditW = document.getElementById('dm-audit-w');
    auditW.style.display = 'block';
    if (d.admin_message && d.admin_message.trim() !== '') {
        document.getElementById('dm-audit').textContent = d.admin_message;
        document.getElementById('dm-audit').style.color = '#0c4a6e';
        document.getElementById('dm-audit').style.fontStyle = 'normal';
    } else {
        document.getElementById('dm-audit').textContent = "No audit logs available for this appointment yet.";
        document.getElementById('dm-audit').style.color = '#94a3b8';
        document.getElementById('dm-audit').style.fontStyle = 'italic';
    }
JS;

$content = str_replace($js_old, $js_new, $content);

file_put_contents($file, $content);
echo "Audit trail set to always visible.";
?>
