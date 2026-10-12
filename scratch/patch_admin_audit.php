<?php
$file = 'c:/xampp/htdocs/pragmavet/admin_list.php';
$content = file_get_contents($file);

// 1. Insert the HTML for the audit trail inside the modal info grid
$html_to_insert = <<<HTML
                <div class="info-i full" id="dm-addr-w" style="display:none;"><label><i class="fa fa-home me-1"></i>Home Address</label><div class="v" id="dm-addr" style="font-size:13px;font-weight:500;"></div></div>
                
                <div class="info-i full" id="dm-audit-w" style="display:none; background:#f0f9ff; padding:12px; border-radius:10px; border:1px dashed #bae6fd; margin-top:8px;">
                    <label style="color:#0369a1; font-size:11px; text-transform:uppercase; font-weight:800; margin-bottom:4px;"><i class="fa fa-history me-1"></i> Audit Trail</label>
                    <div class="v" id="dm-audit" style="font-size:13px; font-weight:500; color:#0c4a6e; white-space:pre-wrap;"></div>
                </div>
HTML;

$content = str_replace(
    '<div class="info-i full" id="dm-addr-w" style="display:none;"><label><i class="fa fa-home me-1"></i>Home Address</label><div class="v" id="dm-addr" style="font-size:13px;font-weight:500;"></div></div>',
    $html_to_insert,
    $content
);

// 2. Insert the JS logic into the openModal() function
$js_to_insert = <<<JS
    var addrW = document.getElementById('dm-addr-w');
    if(d.service_location==='Home Service' && d.home_address){ addrW.style.display='block'; document.getElementById('dm-addr').textContent=d.home_address; }
    else addrW.style.display='none';

    // Show Audit Trail
    var auditW = document.getElementById('dm-audit-w');
    if (d.admin_message) {
        auditW.style.display = 'block';
        document.getElementById('dm-audit').textContent = d.admin_message;
    } else {
        auditW.style.display = 'none';
    }
JS;

$content = str_replace(
    "    var addrW = document.getElementById('dm-addr-w');\n    if(d.service_location==='Home Service' && d.home_address){ addrW.style.display='block'; document.getElementById('dm-addr').textContent=d.home_address; }\n    else addrW.style.display='none';",
    $js_to_insert,
    $content
);

file_put_contents($file, $content);
echo "Audit trail added to modal.";
?>
