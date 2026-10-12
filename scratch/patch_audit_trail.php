<?php
$file = 'c:/xampp/htdocs/pragmavet/appointments.php';
$content = file_get_contents($file);

// 1. Add HTML for Audit Trail in the modal
$old_html = '                    <div class="info-item full" id="dmNotesWrap" style="display:none;">
                        <label>Notes &amp; Reason</label>
                        <div class="val text-muted small" id="dmNotes" style="font-weight:400; line-height:1.5;"></div>
                    </div>
                </div>

                <!-- Reschedule Request Form';

$new_html = '                    <div class="info-item full" id="dmNotesWrap" style="display:none;">
                        <label>Notes &amp; Reason</label>
                        <div class="val text-muted small" id="dmNotes" style="font-weight:400; line-height:1.5;"></div>
                    </div>
                </div>

                <!-- Audit Trail Section -->
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:16px; margin-bottom:16px;">
                    <h6 style="font-size:12px; font-weight:800; color:#64748b; text-transform:uppercase; letter-spacing:.05em; margin-bottom:12px;"><i class="fa fa-history me-1"></i> Audit Trail</h6>
                    <ul style="list-style:none; padding:0; margin:0; font-size:13px; color:#475569;" id="dmAuditTrail">
                        <!-- Populated via JS -->
                    </ul>
                </div>

                <!-- Reschedule Request Form';

$content = str_replace($old_html, $new_html, $content);

// 2. Add JS logic to populate Audit Trail
$old_js = '        if (dmLocation) dmLocation.textContent = row.service_location || \'On-Site\';
        if (dmVet) dmVet.textContent = row.doctor_name || row.doctor_fullname || \'Any Available Doctor\';

        // Notes';

$new_js = '        if (dmLocation) dmLocation.textContent = row.service_location || \'On-Site\';
        if (dmVet) dmVet.textContent = row.doctor_name || row.doctor_fullname || \'Any Available Doctor\';

        // Audit Trail
        const auditWrap = document.getElementById(\'dmAuditTrail\');
        if (auditWrap) {
            let html = \'\';
            
            function formatDateStr(dStr) {
                if (!dStr) return \'\';
                const d = new Date(dStr);
                return isNaN(d) ? dStr : d.toLocaleString();
            }

            if (row.created_at) {
                html += `<li style="margin-bottom:6px;"><i class="fa fa-circle text-primary" style="font-size:8px; margin-right:6px; vertical-align:middle;"></i> <strong>Booked:</strong> ${formatDateStr(row.created_at)}</li>`;
            }
            if (row.downpayment_paid_at) {
                html += `<li style="margin-bottom:6px;"><i class="fa fa-circle text-warning" style="font-size:8px; margin-right:6px; vertical-align:middle;"></i> <strong>Payment Captured:</strong> ${formatDateStr(row.downpayment_paid_at)}</li>`;
            }
            if (row.approved_at) {
                html += `<li style="margin-bottom:6px;"><i class="fa fa-circle text-success" style="font-size:8px; margin-right:6px; vertical-align:middle;"></i> <strong>Approved:</strong> ${formatDateStr(row.approved_at)}</li>`;
            }
            
            if (row.status === \'Cancelled\' || row.status === \'Rejected\') {
                html += `<li style="margin-bottom:6px;"><i class="fa fa-circle text-danger" style="font-size:8px; margin-right:6px; vertical-align:middle;"></i> <strong>${row.status}:</strong> (Updated by Clinic/User)</li>`;
            } else if (row.status === \'Completed\') {
                html += `<li style="margin-bottom:6px;"><i class="fa fa-circle text-info" style="font-size:8px; margin-right:6px; vertical-align:middle;"></i> <strong>Completed:</strong> (Service Delivered)</li>`;
            }

            if (html === \'\') {
                html = \'<li>No audit trail records available.</li>\';
            }
            auditWrap.innerHTML = html;
        }

        // Notes';

$content = str_replace($old_js, $new_js, $content);

file_put_contents($file, $content);
echo "Added Audit Trail to Detail Modal.";
?>
