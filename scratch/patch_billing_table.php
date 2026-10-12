<?php
$file = 'c:/xampp/htdocs/pragmavet/billing.php';
$content = file_get_contents($file);

// Find the start of the invoiceGrid
$start_pos = strpos($content, '<div class="row g-4" id="invoiceGrid">');
if ($start_pos === false) {
    die("Could not find invoiceGrid");
}

// Find the end of the if statement block
$end_pos = strpos($content, '<?php endif; ?>', $start_pos);
if ($end_pos === false) {
    die("Could not find endif");
}

$end_div = strpos($content, '</div>', $end_pos);
if ($end_div === false) {
    die("Could not find end div");
}

$old_grid = substr($content, $start_pos, $end_div - $start_pos + 6);

$new_table = <<<'HTML'
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
HTML;

$content = str_replace($old_grid, $new_table, $content);
file_put_contents($file, $content);
echo "Replaced grid with table.\n";
?>
