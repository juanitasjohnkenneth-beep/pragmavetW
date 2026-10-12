<?php
$file = 'c:/xampp/htdocs/pragmavet/billing.php';
$content = file_get_contents($file);

// Use regex to replace the entire empty check and foreach loop block
$pattern = '/<\?php if \(empty\(\$invoices\)\): \?>.*?<\?php endforeach; \?>\s*<\?php endif; \?>/s';

$new_table = <<<'HTML'
<?php if (empty($invoices)): ?>
                <div class="col-12">
                    <div class="bg-white rounded-4 p-5 text-center border" style="border-color: #eef2f6 !important;">
                        <div style="width: 64px; height: 64px; border-radius: 20px; background: #eff6ff; color: #2563eb; display: inline-flex; align-items: center; justify-content: center; font-size: 26px; margin-bottom: 16px;">
                            <i class="fa fa-file-invoice"></i>
                        </div>
                        <h4 class="fw-bold mb-1" style="color: #0f172a;">No Invoices Yet</h4>
                        <p class="text-muted mb-0">You don't have any pending or completed billing records.</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="col-12">
                    <div class="table-responsive bg-white rounded-4 border" style="border-color:#eef2f6 !important;" id="invoiceGrid">
                        <table class="table table-hover align-middle mb-0" style="font-size:14px;">
                            <thead>
                                <tr>
                                    <th class="py-3 px-4 border-0" style="background:#f8fafc; color:#64748b; font-weight:700;">Invoice #</th>
                                    <th class="py-3 px-4 border-0" style="background:#f8fafc; color:#64748b; font-weight:700;">Service</th>
                                    <th class="py-3 px-4 border-0" style="background:#f8fafc; color:#64748b; font-weight:700;">Pet</th>
                                    <th class="py-3 px-4 border-0" style="background:#f8fafc; color:#64748b; font-weight:700;">Schedule</th>
                                    <th class="py-3 px-4 border-0" style="background:#f8fafc; color:#64748b; font-weight:700;">Total</th>
                                    <th class="py-3 px-4 border-0" style="background:#f8fafc; color:#64748b; font-weight:700;">Status</th>
                                    <th class="py-3 px-4 border-0 text-center" style="background:#f8fafc; color:#64748b; font-weight:700;">Action</th>
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
                                            <span class="badge rounded-pill bg-success-subtle text-success border border-success-subtle px-3 py-2"><i class="fa fa-check-circle me-1"></i> Paid in Full</span>
                                        <?php elseif ($is_dp_paid): ?>
                                            <span class="badge rounded-pill bg-warning-subtle text-warning border border-warning-subtle px-3 py-2"><i class="fa fa-circle-half-stroke me-1"></i> Downpayment Paid</span>
                                        <?php else: ?>
                                            <span class="badge rounded-pill bg-danger-subtle text-danger border border-danger-subtle px-3 py-2"><i class="fa fa-clock me-1"></i> Payment Due</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-4 text-center">
                                        <button type="button" class="btn btn-sm rounded-circle" style="width:36px;height:36px;padding:0; background:#2563eb; color:#fff; border:none; box-shadow:0 4px 10px rgba(37,99,235,0.35); transition:transform 0.2s;" onmouseover="this.style.transform='scale(1.1)'" onmouseout="this.style.transform='scale(1)'" title="View Details" onclick='openReceiptModal(<?= json_encode($inv, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                            <i class="fa fa-eye"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
HTML;

$content = preg_replace($pattern, $new_table, $content);

// Update JS for receipt modal to include payment buttons
$old_js_modal_start = 'let html = `
        <div style="background: #f8fafc; border: 1px solid #eef2f6; border-radius: 14px; padding: 18px; margin-bottom: 20px;">';

$new_js_modal = <<<'JS'
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
        <div style="background: #f8fafc; border: 1px solid #eef2f6; border-radius: 14px; padding: 18px; margin-bottom: 20px;">
JS;

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
echo "Replaced billing cards with table and eye icon.\n";
?>
