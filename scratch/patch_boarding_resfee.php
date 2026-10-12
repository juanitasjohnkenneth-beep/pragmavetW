<?php
$file = 'c:/xampp/htdocs/pragmavet/boarding.php';
$content = file_get_contents($file);

// 1. Add 'reservation' payment type handling in the backend
$old_pricing = '    // Pricing calculation
    $total  = round($days * 800, 2);
    $dp     = round($total * 0.30, 2);
    $charge = ($pay_type === \'full\') ? $total : $dp;
    $label  = ($pay_type === \'full\')
        ? "Pet Boarding (Full Payment - $days days - $pet)"
        : "Pet Boarding (30% Downpayment - $pet)";';

$new_pricing = '    // Pricing calculation
    $total  = round($days * 800, 2);
    $dp     = round($total * 0.30, 2);
    if ($pay_type === \'full\') {
        $charge = $total;
        $label  = "PragmaVet Pet Boarding (Full Payment - $days days) - $pet";
    } elseif ($pay_type === \'reservation\') {
        $charge = 500.00; // Fixed reservation fee to secure the slot
        $label  = "PragmaVet Pet Boarding Reservation Fee - $pet";
    } else {
        $charge = $dp;
        $label  = "PragmaVet Pet Boarding (30% Deposit - $days days) - $pet";
    }';

$content = str_replace($old_pricing, $new_pricing, $content);

// 2. Handle reservation fee success callback (₱500)
// Already handled by pm_success = 'downpayment' or 'full'; add 'reservation'
$old_success = "if (isset(\$_GET['pm_success'], \$_GET['res_id'])) {
    \$res_id   = (int)\$_GET['res_id'];
    \$pay_type = \$_GET['pm_success']; // 'downpayment' or 'full'";

$new_success = "if (isset(\$_GET['pm_success'], \$_GET['res_id'])) {
    \$res_id   = (int)\$_GET['res_id'];
    \$pay_type = \$_GET['pm_success']; // 'downpayment', 'full', or 'reservation'";

$content = str_replace($old_success, $new_success, $content);

// 3. Add reservation handler in the success block (before the downpayment if)
$old_pay_type_if = "    if (\$pay_type === 'downpayment') {
        \$conn->query(\"UPDATE billing SET downpayment_status='Paid', downpayment_amount=\$dp, downpayment_paid_at='\$now' WHERE id=\$bill_id\");
        \$conn->query(\"UPDATE boarding_reservations SET status='Approved' WHERE id=\$res_id AND user_id=\$user_id\");
        \$_SESSION['message']  = \"Downpayment of";

$new_pay_type_if = "    if (\$pay_type === 'reservation') {
        // ₱500 reservation fee only — status stays Pending (staff must still approve)
        \$conn->query(\"UPDATE billing SET downpayment_amount=500.00, downpayment_status='Partial', downpayment_paid_at='\$now' WHERE id=\$bill_id\");
        \$conn->query(\"UPDATE boarding_reservations SET status='Pending' WHERE id=\$res_id AND user_id=\$user_id\");
        \$_SESSION['message']  = \"Reservation fee of ₱500 confirmed! Your cage is secured — staff will review and approve your stay shortly.\";
        \$_SESSION['msg_type'] = 'success';
    } elseif (\$pay_type === 'downpayment') {
        \$conn->query(\"UPDATE billing SET downpayment_status='Paid', downpayment_amount=\$dp, downpayment_paid_at='\$now' WHERE id=\$bill_id\");
        \$conn->query(\"UPDATE boarding_reservations SET status='Approved' WHERE id=\$res_id AND user_id=\$user_id\");
        \$_SESSION['message']  = \"Downpayment of";

$content = str_replace($old_pay_type_if, $new_pay_type_if, $content);

// 4. Fix the else for full — change "} else {" to "} elseif (..."
$old_full_else = "    } else {
        \$conn->query(\"UPDATE billing SET payment_status='Paid', downpayment_status='Paid', downpayment_amount=\$dp, downpayment_paid_at='\$now' WHERE id=\$bill_id\");
        \$conn->query(\"UPDATE boarding_reservations SET status='Approved' WHERE id=\$res_id AND user_id=\$user_id\");
        \$_SESSION['message']  = \"Full payment of";

$new_full_else = "    } elseif (\$pay_type === 'full') {
        \$conn->query(\"UPDATE billing SET payment_status='Paid', downpayment_status='Paid', downpayment_amount=\$dp, downpayment_paid_at='\$now' WHERE id=\$bill_id\");
        \$conn->query(\"UPDATE boarding_reservations SET status='Approved' WHERE id=\$res_id AND user_id=\$user_id\");
        \$_SESSION['message']  = \"Full payment of";

$content = str_replace($old_full_else, $new_full_else, $content);

// 5. Update the payment buttons HTML - add reservation fee button
$old_buttons = '                    <!-- PAYMENT BUTTONS -->
                    <div id="payButtons" style="display: none;">
                        <div class="d-grid gap-2">
                            <button type="submit" name="book_cage" onclick="document.getElementById(\'paymentType\').value=\'downpayment\';" class="btn btn-primary p-3 rounded-3 fw-bold" style="background: #2563eb; border: none;">
                                <i class="fa fa-shield-halved me-1"></i> Pay 30% Deposit (<span id="btnDpAmt">&#8369;0.00</span>)
                            </button>
                            <button type="submit" name="book_cage" onclick="document.getElementById(\'paymentType\').value=\'full\';" class="btn btn-outline-primary p-2 rounded-3 fw-bold">
                                Pay Full Stay (<span id="btnFullAmt">&#8369;0.00</span>)
                            </button>
                        </div>
                    </div>';

$new_buttons = '                    <!-- PAYMENT BUTTONS -->
                    <div id="payButtons" style="display: none;">
                        <div class="p-3 rounded-3 mb-3" style="background:#fffbeb;border:1px solid #fde68a;font-size:13px;color:#92400e;">
                            <i class="fa fa-lock me-1"></i> Choose a payment option to secure your cage booking.
                        </div>
                        <div class="d-grid gap-2">
                            <button type="submit" name="book_cage" onclick="document.getElementById(\'paymentType\').value=\'reservation\';" class="btn p-3 rounded-3 fw-bold" style="background:#f59e0b;color:#fff;border:none;font-size:14px;">
                                <i class="fa fa-shield-halved me-1"></i> Pay ₱500 Reservation Fee <span class="badge bg-white text-warning ms-1" style="font-size:11px;">Secure Slot</span>
                            </button>
                            <button type="submit" name="book_cage" onclick="document.getElementById(\'paymentType\').value=\'downpayment\';" class="btn btn-primary p-3 rounded-3 fw-bold" style="background: #2563eb; border: none;">
                                <i class="fa fa-percent me-1"></i> Pay 30% Deposit (<span id="btnDpAmt">&#8369;0.00</span>)
                            </button>
                            <button type="submit" name="book_cage" onclick="document.getElementById(\'paymentType\').value=\'full\';" class="btn btn-outline-primary p-2 rounded-3 fw-bold">
                                Pay Full Stay (<span id="btnFullAmt">&#8369;0.00</span>)
                            </button>
                        </div>
                    </div>';

$content = str_replace($old_buttons, $new_buttons, $content);

file_put_contents($file, $content);
echo "boarding.php patched with PayMongo reservation fee support.\n";
?>
