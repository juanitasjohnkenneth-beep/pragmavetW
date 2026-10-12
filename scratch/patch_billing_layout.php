<?php
$file = 'c:/xampp/htdocs/pragmavet/billing.php';
$content = file_get_contents($file);

// Replace grid layout with table layout
$old_grid_start = '<div class="row g-4" id="invoiceGrid">';
$old_grid_end = '<?php endforeach; ?>
            <?php endif; ?>
        </div>';

$new_table = '
        <?php if (empty($invoices)): ?>
            <div class="text-center py-5 text-muted" id="emptyState" style="background: #ffffff; border-radius: var(--radius-md); border: 1px solid var(--border-color);">
                <i class="fa fa-receipt fa-3x mb-3 text-secondary" style="opacity: 0.5;"></i>
                <div style="font-size: 15px;">No billing records found.</div>
            </div>
        <?php else: ?>
        <div class="table-responsive bg-white rounded-4 shadow-sm border" style="border-color: var(--border-color) !important;">
            <table class="table table-hover align-middle mb-0" style="font-size: 14px; border-collapse: collapse;">
                <thead class="bg-light text-muted" style="font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid #eef2f6;">
                    <tr>
                        <th class="py-3 px-4 border-0" style="background:#f8fafc;">Invoice #</th>
                        <th class="py-3 px-4 border-0" style="background:#f8fafc;">Service</th>
                        <th class="py-3 px-4 border-0" style="background:#f8fafc;">Pet</th>
                        <th class="py-3 px-4 border-0" style="background:#f8fafc;">Schedule</th>
                        <th class="py-3 px-4 border-0" style="background:#f8fafc;">Total</th>
                        <th class="py-3 px-4 border-0" style="background:#f8fafc;">Status</th>
                        <th class="py-3 px-4 border-0 text-center" style="background:#f8fafc;">Action</th>
                    </tr>
                </thead>
                <tbody id="invoiceTableBody">
                    <?php foreach ($invoices as $inv): 
                        $is_b       = (int)$inv['is_boarding'] === 1;
                        $pet        = $is_b ? $inv['board_pet'] : $inv['appt_pet'];
                        $service    = $is_b ? 'Pet Boarding Stay' : ($inv['appt_service'] ?? 'Clinic Service');
                        $total      = (float)$inv['total_amount'];
                        $dp         = (float)$inv['computed_dp'];
                        $rem        = (float)$inv['remaining_balance'];
                        $p_status   = $inv['payment_status'];
                        $dp_status  = $inv['downpayment_status'];
                        
                        $is_paid    = ($p_status === 'Paid');
                        $is_dp_paid = ($is_b && $dp_status === 'Paid' && !$is_paid);

                        // Category tag for JS filtering
                        $filter_cat = $is_b ? 'boarding' : 'appointment';
                        $status_cat = $is_paid ? 'paid' : 'unpaid';

                        $date_str = $is_b 
                            ? date("M j, Y", strtotime($inv['checkin_date'])) . ' - ' . date("M j, Y", strtotime($inv['checkout_date']))
                            : date("M j, Y", strtotime($inv['appt_date'] ?? $inv['bill_date']));
                    ?>
                    <tr class="invoice-item" data-type="<?= $filter_cat ?>" data-status="<?= $status_cat ?>" style="border-bottom: 1px solid #f1f5f9; transition: background 0.2s;">
                        <td class="py-3 px-4 fw-bold" style="color: #0f172a;">INV-<?= str_pad($inv['invoice_id'], 5, '0', STR_PAD_LEFT) ?></td>
                        <td class="py-3 px-4">
                            <span class="d-block fw-semibold text-dark"><i class="fa <?= $is_b ? 'fa-hotel text-primary' : 'fa-stethoscope text-primary' ?> me-1"></i> <?= $is_b ? 'Pet Boarding' : 'Veterinary Care' ?></span>
                            <span class="text-muted small"><?= htmlspecialchars($service) ?></span>
                        </td>
                        <td class="py-3 px-4"><?= htmlspecialchars($pet ?: 'N/A') ?></td>
                        <td class="py-3 px-4 text-muted" style="font-size: 13px;"><?= $date_str ?></td>
                        <td class="py-3 px-4 fw-bold text-dark">&#8369;<?= number_format($total, 2) ?></td>
                        <td class="py-3 px-4">
                            <?php if ($is_paid): ?>
                                <span class="badge-paid"><i class="fa fa-check-circle"></i> Paid in Full</span>
                            <?php elseif ($is_dp_paid): ?>
                                <span class="badge-dp"><i class="fa fa-circle-half-stroke"></i> Downpayment Paid</span>
                            <?php else: ?>
                                <span class="badge-pending"><i class="fa fa-clock"></i> Payment Due</span>
                            <?php endif; ?>
                        </td>
                        <td class="py-3 px-4 text-center">
                            <button type="button" class="btn btn-sm rounded-circle" style="width:32px;height:32px;padding:0; background:#2563eb; color:#fff; border:none; box-shadow:0 4px 10px rgba(37,99,235,0.35); transition:transform 0.2s;" onmouseover="this.style.transform=\'scale(1.1)\'" onmouseout="this.style.transform=\'scale(1)\'" title="View Payment Details" onclick=\'openReceiptModal(<?= json_encode($inv, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)\'>
                                <i class="fa fa-eye"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>';

// Extract the old grid and replace it
$pattern_grid = '/<div class="row g-4" id="invoiceGrid">.*?<\?php endforeach; \?>\s*<\?php endif; \?>\s*<\/div>/s';
$content = preg_replace($pattern_grid, $new_table, $content);

// Remove the standalone empty state since we built it inside the table logic
$pattern_empty = '/<\?php if \(empty\(\$invoices\)\): \?>\s*<div class="text-center py-5 text-muted" id="emptyState".*?<\/div>\s*<\?php else: \?>/s';
$content = preg_replace($pattern_empty, '', $content);

// Update JS for receipt modal to include payment buttons
$old_js_modal_start = 'let html = `
        <div style="background: #f8fafc; border: 1px solid #eef2f6; border-radius: 14px; padding: 18px; margin-bottom: 20px;">';

$new_js_modal = '
    let payButtonsHtml = "";
    if (!isPaid) {
        if (isBoarding && !isDpPaid) {
            payButtonsHtml = `
                <div class="d-flex gap-2 w-100 mt-3 pt-3" style="border-top: 1px solid #eef2f6;">
                    <a href="billing.php?initpay=downpayment&invoice_id=${inv.invoice_id}" class="btn-pay-now flex-fill text-center" style="text-decoration:none;">
                        <i class="fa fa-shield-halved"></i> Pay 30% DP (&#8369;${dp})
                    </a>
                    <a href="billing.php?initpay=full&invoice_id=${inv.invoice_id}" class="btn-pay-now flex-fill text-center" style="background:#ffffff; color:#2563eb; border: 1.5px solid #2563eb; text-decoration:none;">
                        Pay Full
                    </a>
                </div>
            `;
        } else if (isBoarding && isDpPaid) {
            payButtonsHtml = `
                <div class="w-100 mt-3 pt-3" style="border-top: 1px solid #eef2f6;">
                    <a href="billing.php?initpay=balance&invoice_id=${inv.invoice_id}" class="btn-pay-now w-100 d-block text-center" style="text-decoration:none;">
                        <i class="fa fa-credit-card"></i> Pay Balance (&#8369;${rem})
                    </a>
                </div>
            `;
        } else {
            payButtonsHtml = `
                <div class="w-100 mt-3 pt-3" style="border-top: 1px solid #eef2f6;">
                    <a href="billing.php?initpay=full&invoice_id=${inv.invoice_id}" class="btn-pay-now w-100 d-block text-center" style="text-decoration:none;">
                        <i class="fa fa-credit-card"></i> Pay Now (&#8369;${total})
                    </a>
                </div>
            `;
        }
    }

    let html = `
        <div style="background: #f8fafc; border: 1px solid #eef2f6; border-radius: 14px; padding: 18px; margin-bottom: 20px;">';

$content = str_replace($old_js_modal_start, $new_js_modal, $content);

$old_js_modal_end = '<div><i class="fa fa-briefcase-medical me-2 text-muted"></i>Service Type: <strong>${service}</strong></div>
        </div>
    `;

    document.getElementById(\'printableReceiptArea\').innerHTML = html;';

$new_js_modal_end = '<div><i class="fa fa-briefcase-medical me-2 text-muted"></i>Service Type: <strong>${service}</strong></div>
        </div>
        ${payButtonsHtml}
    `;

    document.getElementById(\'printableReceiptArea\').innerHTML = html;';

$content = str_replace($old_js_modal_end, $new_js_modal_end, $content);

file_put_contents($file, $content);
echo "Billing layout successfully updated to horizontal grid with eye action icon.";
?>
