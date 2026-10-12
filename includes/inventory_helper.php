<?php
/**
 * Inventory helpers: category groups + stock movement log.
 * Database triggers on `inventory` record every quantity/price change and deletion (who, when, why).
 * Pages describe the reason first with inv_set_context(); dbconnection.php supplies @pv_actor.
 */

// Category groups used by the filter tabs and the Add/Edit forms (matches the categories in the data)
const INV_GROUPS = [
    'Vaccines'  => ['Vaccines'],
    'Medicines' => ['Allergy Medications', 'Anesthetics', 'Antibiotics', 'Dewormers', 'Ear Medications', 'Emergency Drugs', 'Eye Medications',
                    'Flea/Tick Control', 'Fluids', 'Gastrointestinal Medicines', 'Pain Relief', 'Reproductive Medicines', 'Skin Medications',
                    'Steroids', 'Vitamins & Supplements'],
    'Supplies'  => ['Consumables', 'Diagnostic Supplies', 'Wound Care'],
    'Pet Food'  => ['Pet Food'],
];

function inv_group_of($category) {
    foreach (INV_GROUPS as $g => $cats) if (in_array($category, $cats, true)) return $g;
    $c = strtolower((string)$category);
    if (strpos($c, 'vaccin') !== false) return 'Vaccines';
    if (strpos($c, 'food') !== false) return 'Pet Food';
    if (strpos($c, 'suppl') !== false || strpos($c, 'consumable') !== false) return 'Supplies';
    return 'Medicines';
}

/** Problems that need the admin's attention for one inventory row. */
function inv_attention($row) {
    $issues = [];
    $exp = $row['expiry_date'] ?? null;
    $no_exp = empty($exp) || $exp === '1970-01-01' || $exp === '0000-00-00';
    if ((float)$row['price_per_unit'] <= 0) $issues[] = 'No price';
    if ($no_exp && in_array(inv_group_of($row['category']), ['Vaccines', 'Medicines'], true)) $issues[] = 'No expiry date';
    if (!$no_exp && $exp < date('Y-m-d')) $issues[] = 'Expired';
    return $issues;
}

/** Describe the next stock change(s) on this connection (picked up by the triggers). */
/**
 * Server-side checks for the Add / Edit item forms. Returns an error message, or null when the values are valid.
 * $current = the item being edited (its existing category/unit stay allowed even if not in the standard lists).
 */
function inv_validate_item($conn, $v, $current = null, $is_new = false) {
    if ($v['name'] === '' || mb_strlen($v['name']) > 150) return "Enter an item name (up to 150 characters).";
    $cats = array_merge(...array_values(INV_GROUPS));
    if (!in_array($v['cat'], $cats, true) && !($current && $v['cat'] === $current['category'])) return "Choose a valid category.";
    if (!preg_match('/^[A-Za-z][A-Za-z .\/-]{0,19}$/', (string)$v['uom'])) return "Choose a valid unit of measure.";
    if ($v['qty'] < 0) return "Quantity can't be negative.";
    if ($v['price'] < 0 || $v['cost'] < 0) return "Prices can't be negative.";
    if ($v['reorder'] < 0) return "Reorder level can't be negative.";
    if ($v['expiry'] !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v['expiry'])) return "Enter a valid expiry date.";
    if ($is_new && $v['expiry'] !== null && $v['expiry'] < date('Y-m-d')) return "This expiry date has already passed — expired stock can't be added.";
    $dup = $conn->prepare("SELECT id FROM inventory WHERE LOWER(TRIM(item_name)) = LOWER(?) AND id <> ? LIMIT 1");
    $cid = (int)($current['id'] ?? 0);
    $dup->bind_param("si", $v['name'], $cid);
    $dup->execute();
    if ($dup->get_result()->num_rows) return "An item named \"" . htmlspecialchars($v['name']) . "\" already exists. Restock or edit that item instead.";
    return null;
}

/** Note shown with a successful save when the selling price is below the cost price (still allowed). */
function inv_price_note($price, $cost) {
    return ($cost > 0 && $price < $cost) ? " Note: the selling price (₱" . number_format($price, 2) . ") is below the cost price (₱" . number_format($cost, 2) . ")." : "";
}

function inv_set_context($conn, $type, $reference = '') {
    $conn->query("SET @pv_inv_type = '" . $conn->real_escape_string(mb_substr($type, 0, 40)) . "', @pv_inv_reason = '" . $conn->real_escape_string(mb_substr($reference, 0, 150)) . "'");
}

/**
 * Stock status for one row: [key, label, days_to_expiry|null].
 * Priority: Expired > Out of stock > Low stock (at/below the item's reorder level) > Expiring soon (≤ 60 days) > Available.
 */
function inv_stock_status($row, $today) {
    $exp = $row['expiry_date'] ?? null;
    $has = !empty($exp) && $exp !== '1970-01-01' && $exp !== '0000-00-00';
    $days = $has ? (int)floor((strtotime($exp) - strtotime($today)) / 86400) : null;
    $qty = (int)$row['quantity'];
    if ($has && $days < 0) return ['expired', 'Expired', $days];
    if ($qty <= 0) return ['out', 'Out of stock', $days];
    if ($qty <= (int)($row['reorder_level'] ?? 10)) return ['low', 'Low stock', $days];
    if ($has && $days <= 60) return ['expiring', 'Expiring soon', $days];
    return ['ok', 'Available', $days];
}

const INV_REPORT_TYPES = ['Damaged', 'Expired', 'Missing', 'Contaminated', 'Other'];

/** Stock problem reports: vet staff report damaged / expired / missing stock, the admin approves before stock changes. */
function inv_reports_ensure($conn) {
    static $done = false;
    if ($done) return;
    $done = true;
    $conn->query("
        CREATE TABLE IF NOT EXISTS inventory_reports (
            id INT AUTO_INCREMENT PRIMARY KEY,
            item_id INT NOT NULL,
            item_name VARCHAR(150) NULL,
            report_type VARCHAR(30) NOT NULL,
            quantity INT NOT NULL DEFAULT 1,
            notes VARCHAR(500) NULL,
            reported_by INT NULL,
            reporter_name VARCHAR(150) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'Pending',
            admin_note VARCHAR(300) NULL,
            decided_by VARCHAR(150) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            decided_at DATETIME NULL,
            INDEX idx_status (status), INDEX idx_item (item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function inv_ensure($conn) {
    static $done = false;
    if ($done) return;
    $done = true;
    $conn->query("
        CREATE TABLE IF NOT EXISTS inventory_movements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            item_id INT NOT NULL,
            item_name VARCHAR(150) NULL,
            movement_type VARCHAR(40) NOT NULL,
            qty_change INT NOT NULL DEFAULT 0,
            qty_after INT NULL,
            reference VARCHAR(150) NULL,
            actor VARCHAR(150) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_item (item_id), INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $have = [];
    $r = $conn->query("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = 'inventory'");
    if ($r) while ($t = $r->fetch_assoc()) $have[$t['TRIGGER_NAME']] = true;

    if (empty($have['trg_inventory_mv_ai'])) {
        $conn->query("
            CREATE TRIGGER trg_inventory_mv_ai AFTER INSERT ON inventory FOR EACH ROW
            INSERT INTO inventory_movements (item_id, item_name, movement_type, qty_change, qty_after, reference, actor)
            VALUES (NEW.id, NEW.item_name, 'Added', NEW.quantity, NEW.quantity, NULLIF(IFNULL(@pv_inv_reason, ''), ''), IFNULL(@pv_actor, 'System'))
        ");
    }
    if (empty($have['trg_inventory_mv_au'])) {
        $conn->query("
            CREATE TRIGGER trg_inventory_mv_au AFTER UPDATE ON inventory FOR EACH ROW
            BEGIN
                IF NOT (OLD.quantity <=> NEW.quantity) THEN
                    INSERT INTO inventory_movements (item_id, item_name, movement_type, qty_change, qty_after, reference, actor)
                    VALUES (NEW.id, NEW.item_name,
                            IFNULL(NULLIF(@pv_inv_type, ''), IF(NEW.quantity > OLD.quantity, 'Restock', 'Adjustment')),
                            NEW.quantity - OLD.quantity, NEW.quantity, NULLIF(IFNULL(@pv_inv_reason, ''), ''), IFNULL(@pv_actor, 'System'));
                END IF;
                IF NOT (OLD.price_per_unit <=> NEW.price_per_unit) THEN
                    INSERT INTO inventory_movements (item_id, item_name, movement_type, qty_change, qty_after, reference, actor)
                    VALUES (NEW.id, NEW.item_name, 'Price change', 0, NEW.quantity,
                            CONCAT('P', OLD.price_per_unit, ' -> P', NEW.price_per_unit), IFNULL(@pv_actor, 'System'));
                END IF;
            END
        ");
    }
    if (empty($have['trg_inventory_mv_ad'])) {
        $conn->query("
            CREATE TRIGGER trg_inventory_mv_ad AFTER DELETE ON inventory FOR EACH ROW
            INSERT INTO inventory_movements (item_id, item_name, movement_type, qty_change, qty_after, reference, actor)
            VALUES (OLD.id, OLD.item_name, 'Deleted', -OLD.quantity, 0, NULLIF(IFNULL(@pv_inv_reason, ''), ''), IFNULL(@pv_actor, 'System'))
        ");
    }
}
