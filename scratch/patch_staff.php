<?php
$file = 'c:/xampp/htdocs/pragmavet/staff_dashboard.php';
$content = file_get_contents($file);

// 1. Add fetching of inventory items at the top
$inventory_fetch = '
// Fetch Active Inventory Items
$inv_res = $conn->query("SELECT id, item_name, quantity, price_per_unit FROM inventory WHERE quantity > 0 ORDER BY item_name ASC");
$inventory_items = [];
while ($inv = $inv_res->fetch_assoc()) {
    $inventory_items[] = $inv;
}
$inventory_json = json_encode($inventory_items, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
';
$content = str_replace(
    '// Fetch Available Doctors for assignments',
    $inventory_fetch . "\n" . '// Fetch Available Doctors for assignments',
    $content
);

// 2. Modify the backend `update_status` block to handle inventory and billing
$old_backend = '        $audit_status = "Status changed to \'$new_status\' by Vet Staff: {$user_name} (" . date(\'M d, Y h:i A\') . ")";
        $stmt = $conn->prepare("UPDATE appointments SET status=?, admin_message=? WHERE id=?");
        $stmt->bind_param("ssi", $new_status, $audit_status, $apt_id);
        if ($stmt->execute()) {
            $_SESSION[\'flash_msg\'] = "Appointment #$apt_id status updated to \'$new_status\'.";
            $_SESSION[\'flash_type\'] = "info";
        }';

$new_backend = '        $audit_status = "Status changed to \'$new_status\' by Vet Staff: {$user_name} (" . date(\'M d, Y h:i A\') . ")";
        $stmt = $conn->prepare("UPDATE appointments SET status=?, admin_message=? WHERE id=?");
        $stmt->bind_param("ssi", $new_status, $audit_status, $apt_id);
        if ($stmt->execute()) {
            $_SESSION[\'flash_msg\'] = "Appointment #$apt_id status updated to \'$new_status\'.";
            $_SESSION[\'flash_type\'] = "info";

            if ($new_status === \'Completed\') {
                $inv_id = intval($_POST[\'inv_id\'] ?? 0);
                $inv_qty = intval($_POST[\'inv_qty\'] ?? 0);
                $inv_price = floatval($_POST[\'inv_price\'] ?? 0);
                
                if ($inv_id > 0 && $inv_qty > 0) {
                    // Deduct from inventory
                    $conn->query("UPDATE inventory SET quantity = quantity - $inv_qty WHERE id = $inv_id");
                    
                    // Fetch details for billing
                    $inv_item = $conn->query("SELECT item_name FROM inventory WHERE id = $inv_id")->fetch_assoc();
                    $apt_info = $conn->query("SELECT user_id, pet_name, doctor_id FROM appointments WHERE id = $apt_id")->fetch_assoc();
                    
                    if ($apt_info && $inv_item) {
                        $u_id = $apt_info[\'user_id\'];
                        $client_name = $conn->query("SELECT fullname FROM users WHERE id = $u_id")->fetch_assoc()[\'fullname\'];
                        
                        $items_json = json_encode([[
                            "name" => "Consultation",
                            "qty" => 1,
                            "price" => 500.00
                        ], [
                            "name" => $inv_item[\'item_name\'],
                            "qty" => $inv_qty,
                            "price" => $inv_price
                        ]]);
                        $total_amount = 500.00 + ($inv_qty * $inv_price);
                        $invoice_no = "INV-" . date("Ymd") . "-" . rand(1000, 9999);
                        
                        $stmt_bill = $conn->prepare("INSERT INTO billing (invoice_no, appointment_id, user_id, client_name, pet_name, total_amount, payment_status, items_json) VALUES (?, ?, ?, ?, ?, ?, \'Unpaid\', ?)");
                        $stmt_bill->bind_param("siissds", $invoice_no, $apt_id, $u_id, $client_name, $apt_info[\'pet_name\'], $total_amount, $items_json);
                        $stmt_bill->execute();
                    }
                }
            }
        }';
$content = str_replace($old_backend, $new_backend, $content);

// 3. Update Javascript to render the Medicine Dropdown form
$old_js = "            } else if (st === 'In Progress') {
                btnContainer.innerHTML += `
                    <form method=\"POST\">
                        <input type=\"hidden\" name=\"action\" value=\"update_status\">
                        <input type=\"hidden\" name=\"apt_id\" value=\"\${data.id}\">
                        <input type=\"hidden\" name=\"new_status\" value=\"Completed\">
                        <input type=\"hidden\" name=\"redirect_tab\" value=\"calendar\">
                        <button type=\"submit\" class=\"btn btn-success w-100 fw-bold py-2\"><i class=\"fa fa-flag-checkered me-1\"></i> Mark Consultation Completed</button>
                    </form>
                `;
            }";

$new_js = "            } else if (st === 'In Progress') {
                let inventoryItems = <?= \$inventory_json ?>;
                let optionsHtml = '<option value=\"\">-- Select Medicine (Optional) --</option>';
                inventoryItems.forEach(item => {
                    optionsHtml += `<option value=\"\${item.id}\" data-price=\"\${item.price_per_unit}\">\${item.item_name} (Stock: \${item.quantity})</option>`;
                });

                btnContainer.innerHTML += `
                    <form method=\"POST\" class=\"text-start\">
                        <input type=\"hidden\" name=\"action\" value=\"update_status\">
                        <input type=\"hidden\" name=\"apt_id\" value=\"\${data.id}\">
                        <input type=\"hidden\" name=\"new_status\" value=\"Completed\">
                        <input type=\"hidden\" name=\"redirect_tab\" value=\"calendar\">
                        
                        <div class=\"mb-3 border p-3 rounded bg-light\" style=\"border-color: #cbd5e1 !important;\">
                            <label class=\"fw-bold mb-2 text-primary\" style=\"font-size:12px;\"><i class=\"fa fa-pills\"></i> Prescribe Medicine / Deduct Inventory</label>
                            <select name=\"inv_id\" id=\"inv_select_\${data.id}\" class=\"form-select form-select-sm mb-2\" onchange=\"document.getElementById('inv_price_\${data.id}').value = this.options[this.selectedIndex].getAttribute('data-price') || ''; document.getElementById('inv_qty_\${data.id}').value = (this.value ? 1 : '');\">
                                \${optionsHtml}
                            </select>
                            <div class=\"row g-2\">
                                <div class=\"col-6\">
                                    <label class=\"text-muted fw-bold\" style=\"font-size:10px;\">Quantity</label>
                                    <input type=\"number\" name=\"inv_qty\" id=\"inv_qty_\${data.id}\" class=\"form-control form-control-sm\" min=\"1\" placeholder=\"0\">
                                </div>
                                <div class=\"col-6\">
                                    <label class=\"text-muted fw-bold\" style=\"font-size:10px;\">Price (₱)</label>
                                    <input type=\"number\" name=\"inv_price\" id=\"inv_price_\${data.id}\" class=\"form-control form-control-sm\" step=\"0.01\" placeholder=\"0.00\">
                                </div>
                            </div>
                        </div>

                        <button type=\"submit\" class=\"btn btn-success w-100 fw-bold py-2\"><i class=\"fa fa-flag-checkered me-1\"></i> Mark Consultation Completed</button>
                    </form>
                `;
            }";
$content = str_replace($old_js, $new_js, $content);

file_put_contents($file, $content);
echo "staff_dashboard.php updated.";
?>
