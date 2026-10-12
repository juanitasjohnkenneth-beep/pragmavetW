<?php
session_start();
include("dbconnection.php");

$user_role = $_SESSION['role'] ?? '';
$is_admin = ($user_role === 'admin');
$is_owner = ($user_role === 'clinic_owner' || $user_role === 'owner');
$is_staff = ($user_role === 'staff');
$is_doctor = ($user_role === 'doctor');

if(!isset($_SESSION['user_id']) || (!$is_admin && !$is_owner && !$is_staff && !$is_doctor)){
    header("Location: index.php");
    exit();
}

include_once("includes/inventory_helper.php");
inv_ensure($conn);
inv_reports_ensure($conn);

// Stock problem reports from vet staff: approve (stock is written off) or decline
if (isset($_POST['report_decision']) && $is_admin) {
    $rid = (int)($_POST['report_id'] ?? 0);
    $decision = $_POST['report_decision'] === 'approve' ? 'Approved' : 'Declined';
    $note = mb_substr(trim($_POST['admin_note'] ?? ''), 0, 300);
    $rep = $conn->query("SELECT * FROM inventory_reports WHERE id = $rid AND status = 'Pending'")->fetch_assoc();
    $who = $_SESSION['user'] ?? 'Admin';
    if (!$rep) {
        $_SESSION['message'] = "That report was already handled.";
        $_SESSION['msg_type'] = "danger";
    } elseif ($decision === 'Declined' && $note === '') {
        $_SESSION['message'] = "Please give a reason when declining a report.";
        $_SESSION['msg_type'] = "danger";
    } else {
        if ($decision === 'Approved') {
            inv_set_context($conn, 'Write-off', "Report #$rid · {$rep['report_type']} · reported by {$rep['reporter_name']}");
            $wq = $conn->prepare("UPDATE inventory SET quantity = GREATEST(0, quantity - ?) WHERE id = ?");
            $wq->bind_param("ii", $rep['quantity'], $rep['item_id']);
            $wq->execute();
        }
        $up = $conn->prepare("UPDATE inventory_reports SET status = ?, admin_note = NULLIF(?, ''), decided_by = ?, decided_at = NOW() WHERE id = ?");
        $up->bind_param("sssi", $decision, $note, $who, $rid);
        $up->execute();
        if (!empty($rep['reported_by'])) {
            $msg = "Your stock report for " . htmlspecialchars($rep['item_name']) . " (" . (int)$rep['quantity'] . " " . strtolower($rep['report_type']) . ") was " . strtolower($decision)
                 . ($decision === 'Approved' ? " and the stock was updated." : ".") . ($note !== '' ? " Note: " . htmlspecialchars($note) : "");
            $link = "staff_dashboard.php?tab=inventory";
            $nt = $conn->prepare("INSERT INTO notifications (user_id, message, is_read, created_at, link) VALUES (?, ?, 0, NOW(), ?)");
            $uid = (int)$rep['reported_by'];
            $nt->bind_param("iss", $uid, $msg, $link);
            $nt->execute();
        }
        $_SESSION['message'] = "Report #$rid " . strtolower($decision) . ($decision === 'Approved' ? " — " . (int)$rep['quantity'] . " " . $rep['item_name'] . " written off." : ".");
        $_SESSION['msg_type'] = "success";
    }
    header("Location: admin_inventory.php#stockReports");
    exit();
}
$pending_reports = [];
$prq = $conn->query("SELECT r.*, i.quantity AS stock_now, i.unit_of_measure FROM inventory_reports r LEFT JOIN inventory i ON i.id = r.item_id WHERE r.status = 'Pending' ORDER BY r.created_at ASC");
if ($prq) while ($x = $prq->fetch_assoc()) $pending_reports[] = $x;
$recent_reports = [];
$rrq = $conn->query("SELECT * FROM inventory_reports WHERE status <> 'Pending' ORDER BY decided_at DESC, id DESC LIMIT 4");
if ($rrq) while ($x = $rrq->fetch_assoc()) $recent_reports[] = $x;

// Add Item Logic
// Changing stock items is for the admin only. The clinic owner views; vet staff use "Report a stock problem"; vets have a read-only lookup.
$pv_owner_ro = $is_owner;
if ($is_owner && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION['message']  = "Inventory is managed by the administrator. Your access to this page is view only.";
    $_SESSION['msg_type'] = "warning";
    header("Location: admin_inventory.php");
    exit();
}
if (!$is_admin && (isset($_POST['add_item']) || isset($_POST['edit_item']) || isset($_POST['restock_item']) || isset($_POST['delete_item']))) {
    $_SESSION['message']  = "Only the admin can add, edit, restock or delete inventory items.";
    $_SESSION['msg_type'] = "danger";
    header("Location: admin_inventory.php");
    exit();
}

if(isset($_POST['add_item'])){
    inv_set_context($conn, 'Added', 'New item');
    $name     = trim($_POST['item_name']);
    $cat      = $_POST['category'];
    $qty      = (int)$_POST['quantity'];
    $price    = (float)$_POST['price_per_unit'];
    $expiry   = (!empty($_POST['expiry_date']) && $_POST['expiry_date'] !== '1970-01-01') ? $_POST['expiry_date'] : null;
    $batch    = trim($_POST['batch_number'] ?? '');
    $lot      = trim($_POST['lot_number'] ?? '');
    $uom      = $_POST['unit_of_measure'] ?? 'piece';
    $reorder  = (int)($_POST['reorder_level'] ?? 10);
    $supplier = trim($_POST['supplier'] ?? '');
    $cost     = (float)($_POST['cost_price'] ?? 0);
    $inv_err  = inv_validate_item($conn, compact('name', 'cat', 'qty', 'price', 'cost', 'reorder', 'uom', 'expiry'), null, true);
    if ($inv_err) {
        $_SESSION['message'] = $inv_err;
        $_SESSION['msg_type'] = "danger";
        header("Location: admin_inventory.php");
        exit();
    }
    $stmt = $conn->prepare("INSERT INTO inventory(item_name, category, quantity, price_per_unit, expiry_date, batch_number, lot_number, unit_of_measure, reorder_level, supplier, cost_price) VALUES(?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->bind_param("ssidssssisd", $name, $cat, $qty, $price, $expiry, $batch, $lot, $uom, $reorder, $supplier, $cost);
    
    if($stmt->execute()){
        $_SESSION['message'] = "Item <b>" . htmlspecialchars($name) . "</b> added to inventory!" . inv_price_note($price, $cost);
        $_SESSION['msg_type'] = "success";
    } else {
        $_SESSION['message'] = "Failed to add item: " . $conn->error;
        $_SESSION['msg_type'] = "danger";
    }
    header("Location: admin_inventory.php");
    exit();
}

// Edit Item Logic
if(isset($_POST['edit_item'])){
    inv_set_context($conn, 'Adjustment', 'Edited item details (stock count corrected)');
    $id       = (int)$_POST['item_id'];
    $name     = trim($_POST['item_name']);
    $cat      = $_POST['category'];
    $qty      = (int)$_POST['quantity'];
    $price    = (float)$_POST['price_per_unit'];
    $expiry   = (!empty($_POST['expiry_date']) && $_POST['expiry_date'] !== '1970-01-01') ? $_POST['expiry_date'] : null;
    $batch    = trim($_POST['batch_number'] ?? '');
    $lot      = trim($_POST['lot_number'] ?? '');
    $uom      = $_POST['unit_of_measure'] ?? 'piece';
    $reorder  = (int)($_POST['reorder_level'] ?? 10);
    $supplier = trim($_POST['supplier'] ?? '');
    $cost     = (float)($_POST['cost_price'] ?? 0);
    $cur_item = $conn->query("SELECT * FROM inventory WHERE id = $id")->fetch_assoc();
    $inv_err  = !$cur_item ? "Item not found." : inv_validate_item($conn, compact('name', 'cat', 'qty', 'price', 'cost', 'reorder', 'uom', 'expiry'), $cur_item);
    if ($inv_err) {
        $_SESSION['message'] = $inv_err;
        $_SESSION['msg_type'] = "danger";
        header("Location: admin_inventory.php");
        exit();
    }
    if ((int)$cur_item['quantity'] !== $qty) inv_set_context($conn, 'Adjustment', 'Stock count corrected from ' . (int)$cur_item['quantity'] . ' to ' . $qty . ' by editing the item');

    $stmt = $conn->prepare("UPDATE inventory SET item_name=?, category=?, quantity=?, price_per_unit=?, expiry_date=?, batch_number=?, lot_number=?, unit_of_measure=?, reorder_level=?, supplier=?, cost_price=? WHERE id=?");
    $stmt->bind_param("ssidssssisdi", $name, $cat, $qty, $price, $expiry, $batch, $lot, $uom, $reorder, $supplier, $cost, $id);
    
    if($stmt->execute()){
        $_SESSION['message'] = "Item <b>" . htmlspecialchars($name) . "</b> updated successfully!" . inv_price_note($price, $cost);
        $_SESSION['msg_type'] = "success";
    } else {
        $_SESSION['message'] = "Failed to update item: " . $conn->error;
        $_SESSION['msg_type'] = "danger";
    }
    header("Location: admin_inventory.php");
    exit();
}

// Quick Restock Logic
if(isset($_POST['restock_item'])){
    $id     = (int)$_POST['item_id'];
    $add    = (int)$_POST['restock_qty'];
    $batch  = trim($_POST['restock_batch'] ?? '');
    $lot    = trim($_POST['restock_lot'] ?? '');
    $expiry = (!empty($_POST['restock_expiry']) && $_POST['restock_expiry'] !== '1970-01-01') ? $_POST['restock_expiry'] : null;
    $cur_item = $conn->query("SELECT quantity, expiry_date FROM inventory WHERE id = $id")->fetch_assoc();
    $inv_err = !$cur_item ? "Item not found."
             : ($add <= 0 ? "Enter how many units were received (more than 0). To remove stock, use a stock report or edit the count."
             : ($expiry !== null && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry) || $expiry < date('Y-m-d')) ? "The new batch's expiry date has already passed — expired stock can't be added." : null));    if ($inv_err) {
        $_SESSION['message'] = $inv_err;
        $_SESSION['msg_type'] = "danger";
        header("Location: admin_inventory.php");
        exit();
    }
    // One expiry per item: while older units are still in stock, keep the EARLIER date so they are still flagged before they expire
    $kept_note = '';
    if ($expiry !== null && (int)$cur_item['quantity'] > 0 && !empty($cur_item['expiry_date']) && $cur_item['expiry_date'] < $expiry) {
        $kept_note = ' · existing stock still expires ' . $cur_item['expiry_date'] . ' (kept)';
        $expiry_new_batch = $expiry;
        $expiry = $cur_item['expiry_date'];
    }
    inv_set_context($conn, 'Restock', 'Restocked' . ($batch !== '' ? ' · batch ' . $batch : '') . (isset($expiry_new_batch) ? ' · new batch exp ' . $expiry_new_batch : ($expiry ? ' · exp ' . $expiry : '')) . $kept_note);

    $stmt = $conn->prepare("UPDATE inventory SET quantity = quantity + ?, batch_number = IF(? != '', ?, batch_number), lot_number = IF(? != '', ?, lot_number), expiry_date = COALESCE(?, expiry_date) WHERE id=?");
    $stmt->bind_param("isssssi", $add, $batch, $batch, $lot, $lot, $expiry, $id);
    
    if($stmt->execute()){
        $_SESSION['message'] = "Stock replenished by <b>+$add</b> units!" . ($kept_note !== '' ? " The item keeps the earlier expiry date (" . date('M d, Y', strtotime($expiry)) . ") until the older units are used up." : "");
        $_SESSION['msg_type'] = "success";
    } else {
        $_SESSION['message'] = "Restock failed.";
        $_SESSION['msg_type'] = "danger";
    }
    header("Location: admin_inventory.php");
    exit();
}

// Delete Item Logic
if(isset($_POST['delete_item'])){
    $id = (int)$_POST['item_id'];
    $left = (int)($conn->query("SELECT quantity FROM inventory WHERE id = $id")->fetch_assoc()['quantity'] ?? 0);
    $inv_err = $left > 0 ? "This item still has $left unit(s) in stock. Write the stock off (approve a stock report) or set the count to 0 first, so the loss is recorded." : null;    if ($inv_err) {
        $_SESSION['message'] = $inv_err;
        $_SESSION['msg_type'] = "danger";
        header("Location: admin_inventory.php");
        exit();
    }
    inv_set_context($conn, 'Deleted', 'Removed from inventory');
    $stmt = $conn->prepare("DELETE FROM inventory WHERE id=?");
    $stmt->bind_param("i", $id);
    
    if($stmt->execute()){
        $_SESSION['message'] = "Item permanently deleted from inventory.";
        $_SESSION['msg_type'] = "success";
    } else {
        $_SESSION['message'] = "Failed to delete item.";
        $_SESSION['msg_type'] = "danger";
    }
    header("Location: admin_inventory.php");
    exit();
}

// Fetch Inventory
$inventory     = $conn->query("SELECT * FROM inventory ORDER BY category ASC, item_name ASC");
$total_items   = $conn->query("SELECT COALESCE(SUM(quantity),0) as total FROM inventory")->fetch_assoc()['total'] ?? 0;
$total_sku     = $conn->query("SELECT COUNT(*) as cnt FROM inventory")->fetch_assoc()['cnt'] ?? 0;
$low_stock     = $conn->query("SELECT COUNT(*) as count FROM inventory WHERE quantity <= COALESCE(reorder_level, 10)")->fetch_assoc()['count'] ?? 0;
$expired_stock = $conn->query("SELECT COUNT(*) as count FROM inventory WHERE expiry_date IS NOT NULL AND expiry_date != '1970-01-01' AND expiry_date < CURDATE()")->fetch_assoc()['count'] ?? 0;
$expiring_soon = $conn->query("SELECT COUNT(*) as count FROM inventory WHERE expiry_date IS NOT NULL AND expiry_date != '1970-01-01' AND expiry_date >= CURDATE() AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)")->fetch_assoc()['count'] ?? 0;
$total_value   = $conn->query("SELECT COALESCE(SUM(quantity * price_per_unit),0) as val FROM inventory")->fetch_assoc()['val'] ?? 0;
$priced_count  = (int)($conn->query("SELECT COUNT(*) c FROM inventory WHERE price_per_unit > 0")->fetch_assoc()['c'] ?? 0);

// Items needing the admin's attention (missing price / expiry, expired)
$attn = ['No price' => 0, 'No expiry date' => 0, 'Expired' => 0];
$attn_items = 0;
$aq = $conn->query("SELECT item_name, category, price_per_unit, expiry_date FROM inventory");
if ($aq) while ($ar = $aq->fetch_assoc()) {
    $iss = inv_attention($ar);
    if ($iss) $attn_items++;
    foreach ($iss as $i) $attn[$i]++;
}

// Stock movement history (latest first)
$movements_by_item = [];
$recent_movements = [];
$mq = $conn->query("SELECT * FROM inventory_movements ORDER BY created_at DESC, id DESC LIMIT 600");
if ($mq) while ($mv = $mq->fetch_assoc()) {
    if (count($recent_movements) < 25) $recent_movements[] = $mv;
    if (count($movements_by_item[$mv['item_id']] ?? []) < 20) $movements_by_item[$mv['item_id']][] = $mv;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <script src="theme.js?v=<?=time()?>"></script>
    <link rel="stylesheet" href="theme.css?v=<?=time()?>">

    <title>Inventory Management | PragmaVet Admin</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" type="text/css" href="https://npmcdn.com/flatpickr/dist/themes/material_blue.css">

    <style>
        :root {
            --primary: #2563eb;
            --secondary: #475569;
            --dark: #0f172a;
            --light-bg: #f8fafc;
            --sidebar-width: 260px;
        }

        body {
            background: #f8fafc !important;
            font-family: 'Inter', sans-serif;
            color: #334155;
            overflow-x: hidden;
        }

        /* CONTENT AREA */
        .main-content {
            margin-left: 260px !important;
            width: calc(100% - 260px) !important;
            max-width: calc(100% - 260px) !important;
            padding: 24px 28px !important;
            min-height: 100vh;
            box-sizing: border-box !important;
        }

        @media (max-width: 1200px) {
            .main-content {
                padding: 20px 20px !important;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
                padding: 16px !important;
            }
            .sidebar {
                position: relative !important;
                width: 100% !important;
                height: auto !important;
            }
        }

        .custom-card {
            background: white;
            border: none;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
            padding: 24px;
        }

        .card-premium {
            background: white;
            border: none;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
        }

        .table-custom thead th {
            background: #f8fafc;
            color: #64748b;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #e2e8f0;
            padding: 12px 14px;
        }

        .table-custom tbody td {
            padding: 14px;
            vertical-align: middle;
            border-bottom: 1px solid #f1f5f9;
            font-size: 13.5px;
        }

        /* Category Filter Tabs */
        .inv-ftab {
            padding: 8px 16px;
            border-radius: 30px;
            border: 1px solid #e2e8f0;
            background: #ffffff;
            font-size: 13px;
            font-weight: 600;
            color: #64748b;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .inv-ftab:hover {
            border-color: #cbd5e1;
            color: #0f172a;
            background: #f8fafc;
        }

        .inv-ftab.active {
            background: #2563eb !important;
            color: #ffffff !important;
            border-color: #2563eb !important;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25) !important;
        }

        /* Custom Search Box */
        .search-input-group {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 50px;
            padding: 2px 15px;
            display: flex;
            align-items: center;
            width: 280px;
            transition: 0.3s;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.02);
        }

        /* Header + row buttons, search field and modal buttons (override the site-wide gradient button / boxed input styles) */
        .search-input-group { padding: 0 16px !important; height: 42px; }
        .search-input-group input#customSearch { border: 0 !important; box-shadow: none !important; background: transparent !important; padding: 0 10px !important; height: 40px !important; min-height: 0 !important; border-radius: 0 !important; margin: 0 !important; }
        .search-input-group i { color: #94a3b8; }
        .iv-add { background: #2563eb !important; color: #fff !important; border: 0 !important; box-shadow: 0 4px 14px rgba(37,99,235,.28) !important; height: 42px; display: inline-flex !important; align-items: center; white-space: nowrap; }
        .iv-add:hover { background: #1d4ed8 !important; }
        .iv-restock { background: #f0fdf4 !important; color: #15803d !important; border: 1px solid #bbf7d0 !important; box-shadow: none !important; font-size: 12.5px !important; padding: 6px 14px !important; white-space: nowrap; }
        .iv-restock:hover { background: #dcfce7 !important; }
        .iv-more { background: #fff !important; border: 1px solid #e2e8f0 !important; box-shadow: none !important; display: inline-flex !important; align-items: center; justify-content: center; }
        .iv-more:hover { background: #f1f5f9 !important; }
        .modal .btn-primary { background: #2563eb !important; background-image: none !important; color: #fff !important; border: 0 !important; box-shadow: 0 4px 14px rgba(37,99,235,.25) !important; }
        .modal .btn-primary:hover { background: #1d4ed8 !important; }
        .modal .btn-success { background: #16a34a !important; background-image: none !important; color: #fff !important; border: 0 !important; box-shadow: 0 4px 14px rgba(22,163,74,.25) !important; }
        .modal .btn-success:hover { background: #15803d !important; }
        .modal .btn-danger { background: #dc2626 !important; background-image: none !important; color: #fff !important; border: 0 !important; box-shadow: none !important; }

        /* Clinic owner: view only */
        .pv-owner-ro .iv-add, .pv-owner-ro .iv-restock, .pv-owner-ro .iv-more, .pv-owner-ro button[name="report_decision"] { display: none !important; }

        .search-input-group:focus-within {
            border-color: #2563eb;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.12);
            width: 320px;
        }

        .search-input-group input {
            border: none;
            background: transparent;
            padding: 8px 10px;
            font-size: 13.5px;
            font-weight: 500;
            width: 100%;
            outline: none;
        }

        .search-input-group i {
            color: #94a3b8;
            font-size: 14px;
        }

        /* Modal Labels */
        .saas-label {
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748b;
            margin-bottom: 6px;
            display: block;
        }

        /* ALERT */
        .alert-container {
            position: fixed;
            top: 25px;
            right: 35px;
            z-index: 9999;
            animation: slideDown 0.4s ease-out forwards;
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* ====== WHITE SIDEBAR THEME ====== */
        .sidebar {
            background: #ffffff !important;
            width: var(--sidebar-width, 260px) !important;
            height: 100vh !important;
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            padding: 28px 20px 24px !important;
            z-index: 1000 !important;
            display: flex !important;
            flex-direction: column !important;
            border-right: 1px solid #eef2f6 !important;
            box-shadow: none !important;
            overflow-y: auto !important;
        }

        .sidebar-brand {
            display: flex !important;
            align-items: center !important;
            gap: 12px !important;
            margin-bottom: 24px !important;
            padding: 0 4px !important;
            text-decoration: none !important;
        }

        .sidebar-brand-icon {
            width: 44px !important;
            height: 44px !important;
            min-width: 44px !important;
            border-radius: 13px !important;
            background: #2563eb !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            color: #ffffff !important;
            font-size: 20px !important;
            flex-shrink: 0 !important;
            box-shadow: 0 4px 14px rgba(37, 99, 235, 0.28) !important;
        }

        .sidebar-brand span {
            font-weight: 800 !important;
            color: #182032 !important;
            font-size: 22px !important;
            letter-spacing: -0.4px !important;
        }

        .sidebar-user {
            margin-bottom: 24px !important;
            padding: 15px 18px !important;
            border-radius: 14px !important;
            background: #f8fafc !important;
            border: 1px solid #eef2f6 !important;
        }

        .sidebar-user .welcome {
            font-size: 13px !important;
            color: #64748b !important;
            font-weight: 500 !important;
            line-height: 1.3 !important;
        }

        .sidebar-user .uname {
            font-weight: 700 !important;
            color: #0f172a !important;
            font-size: 15.5px !important;
            margin-top: 3px !important;
            white-space: nowrap !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
        }

        .sidebar a {
            display: flex !important;
            align-items: center !important;
            gap: 14px !important;
            padding: 12px 16px !important;
            border-radius: 12px !important;
            color: #334155 !important;
            text-decoration: none !important;
            margin-bottom: 4px !important;
            font-weight: 600 !important;
            font-size: 14.5px !important;
            transition: all 0.2s ease !important;
            letter-spacing: -0.1px !important;
        }

        .sidebar a i {
            width: 22px !important;
            text-align: center !important;
            font-size: 16px !important;
            color: #64748b !important;
            transition: color 0.2s !important;
            flex-shrink: 0 !important;
        }

        .sidebar a:hover {
            background: #f8fafc !important;
            color: #0f172a !important;
        }

        .sidebar a:hover i {
            color: #2563eb !important;
        }

        .sidebar a.active {
            background: #2563eb !important;
            color: #ffffff !important;
            font-weight: 700 !important;
            box-shadow: 0 4px 14px rgba(37, 99, 235, 0.28) !important;
        }

        .sidebar a.active i {
            color: #ffffff !important;
        }

        .sidebar .sidebar-logout {
            margin-top: auto !important;
            padding-top: 20px !important;
            border-top: 1px solid #f1f5f9 !important;
        }

        .sidebar .sidebar-logout a {
            color: #dc2626 !important;
            font-weight: 600 !important;
            margin-bottom: 0 !important;
        }

        .sidebar .sidebar-logout a i {
            color: #dc2626 !important;
        }

        .sidebar .sidebar-logout a:hover {
            background: #fef2f2 !important;
            color: #b91c1c !important;
        }

        .sidebar::-webkit-scrollbar { width: 5px; }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.06); border-radius: 10px; }
        .sidebar::-webkit-scrollbar-track { background: transparent; }
    </style>
    <link rel="stylesheet" href="assets/pragmavet.css">
    <link href="assets/global-style.css" rel="stylesheet">
</head>
<body class="<?= $pv_owner_ro ? 'pv-owner-ro' : '' ?>">

<?php if(isset($_SESSION['message'])): ?>
<div class="alert-container">
    <div class="alert alert-<?php echo $_SESSION['msg_type']; ?> alert-dismissible fade show shadow-lg text-dark pe-5" style="border-radius: 12px; border: none; font-weight: 500;">
        <?php echo $_SESSION['message']; unset($_SESSION['message'], $_SESSION['msg_type']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
</div>
<?php endif; ?>

<?php include("includes/admin_sidebar.php"); ?>

<div class="main-content">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold m-0 text-dark">Clinical Inventory</h2>
            <p class="text-muted mb-0">Manage clinic medicines, vaccines, batch numbers, and stock replenishments.</p>
            <?php if ($pv_owner_ro): ?><div style="display:inline-flex;align-items:center;gap:8px;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:99px;padding:8px 16px;font-size:13px;font-weight:600;margin:10px 0 4px;"><i class="fa fa-eye"></i> View only · inventory items are managed by the administrator</div><?php endif; ?>
        </div>
        <div class="d-flex align-items-center gap-3">
            <div class="search-input-group">
                <i class="fa fa-search"></i>
                <input type="text" id="customSearch" placeholder="Search Inventory...">
            </div>
            <button class="btn btn-primary px-4 py-2 rounded-pill fw-bold shadow-sm iv-add" data-bs-toggle="modal" data-bs-target="#addItemModal">
                <i class="fa fa-plus me-1"></i> Add Item
            </button>
        </div>
    </div>

    <!-- Quick Executive Metric Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card-premium d-flex align-items-center gap-3 h-100 shadow-sm border-0">
                <div class="rounded-circle bg-primary bg-opacity-10 text-primary p-3 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                    <i class="fa fa-boxes fs-5"></i>
                </div>
                <div>
                    <div class="text-muted small fw-semibold">Total Stock Units</div>
                    <h4 class="fw-bold m-0 text-dark"><?= number_format($total_items) ?> <span class="fs-6 text-muted fw-normal">(<?= $total_sku ?> SKUs)</span></h4>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card-premium d-flex align-items-center gap-3 h-100 shadow-sm border-0">
                <div class="rounded-circle bg-success bg-opacity-10 text-success p-3 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                    <i class="fa fa-coins fs-5"></i>
                </div>
                <div>
                    <div class="text-muted small fw-semibold">Total Stock Value</div>
                    <h4 class="fw-bold m-0 text-dark">&#8369;<?= number_format($total_value, 2) ?></h4>
                    <div class="text-muted" style="font-size:11px;"><?= $priced_count ?> of <?= number_format($total_sku) ?> items priced</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card-premium d-flex align-items-center gap-3 h-100 shadow-sm border-0">
                <div class="rounded-circle bg-warning bg-opacity-10 text-warning p-3 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                    <i class="fa fa-exclamation-triangle fs-5"></i>
                </div>
                <div>
                    <div class="text-muted small fw-semibold">Low Stock Alert</div>
                    <h4 class="fw-bold m-0 text-dark"><?= number_format($low_stock) ?> <span class="fs-6 text-muted fw-normal">items</span></h4>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card-premium d-flex align-items-center gap-3 h-100 shadow-sm border-0">
                <div class="rounded-circle bg-danger bg-opacity-10 text-danger p-3 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                    <i class="fa fa-calendar-times fs-5"></i>
                </div>
                <div>
                    <div class="text-muted small fw-semibold">Expired / Expiring Soon</div>
                    <h4 class="fw-bold m-0 text-dark"><?= number_format($expired_stock) ?> <span class="fs-6 text-muted fw-normal">exp / <?= number_format($expiring_soon) ?> soon</span></h4>
                </div>
            </div>
        </div>
    </div>

    <!-- Stock problem reports from vet staff -->
    <?php if ($pending_reports || $recent_reports): ?>
    <div class="custom-card mb-4" id="stockReports" style="border-left:4px solid <?= $pending_reports ? '#dc2626' : '#e2e8f0' ?>;">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <div>
                <h5 class="fw-bold m-0" style="font-size:16px;"><i class="fa fa-flag text-danger me-2"></i>Stock problem reports
                    <?php if ($pending_reports): ?><span class="ms-2" style="background:#dc2626;color:#fff;border-radius:20px;padding:3px 10px;font-size:11.5px;font-weight:700;vertical-align:middle;"><?= count($pending_reports) ?> to review</span><?php endif; ?></h5>
                <div class="text-muted small">Vet staff reported damaged, expired or missing stock. Approving deducts it and records a write-off in Stock History.</div>
            </div>
        </div>
        <?php foreach ($pending_reports as $r): ?>
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 p-3 mb-2 rounded-3" style="background:#fef2f2;border:1px solid #fecaca;">
            <div style="min-width:240px;flex:1;">
                <div class="fw-bold text-dark"><?= htmlspecialchars($r['item_name']) ?> <span class="badge bg-white text-danger border border-danger-subtle ms-1"><?= (int)$r['quantity'] ?> · <?= htmlspecialchars($r['report_type']) ?></span></div>
                <div class="small text-muted mt-1">“<?= htmlspecialchars($r['notes']) ?>”</div>
                <div class="small text-muted mt-1"><i class="fa fa-user me-1"></i><?= htmlspecialchars($r['reporter_name']) ?> · <?= date('M d, Y g:i A', strtotime($r['created_at'])) ?> · <?= $r['stock_now'] === null ? 'item no longer in inventory' : 'in stock now: ' . (int)$r['stock_now'] . ' ' . htmlspecialchars($r['unit_of_measure'] ?? '') ?></div>
            </div>
            <?php if ($is_admin || $is_owner): ?>
            <form method="POST" class="d-flex gap-2 align-items-center flex-wrap" style="min-width:300px;">
                <input type="hidden" name="report_id" value="<?= (int)$r['id'] ?>">
                <input type="text" name="admin_note" class="form-control form-control-sm" style="width:190px;" maxlength="300" placeholder="Note (required to decline)">
                <button type="submit" name="report_decision" value="approve" class="btn btn-sm fw-bold" style="background:#16a34a !important;background-image:none !important;color:#fff !important;border:none !important;border-radius:10px !important;padding:7px 14px;box-shadow:none !important;" <?= $r['stock_now'] === null ? 'disabled' : '' ?>><i class="fa fa-check me-1"></i>Approve</button>
                <button type="submit" name="report_decision" value="decline" class="btn btn-sm fw-bold" style="background:#fff !important;background-image:none !important;color:#dc2626 !important;border:1.5px solid #fca5a5 !important;border-radius:10px !important;padding:6px 14px;box-shadow:none !important;"><i class="fa fa-xmark me-1"></i>Decline</button>
            </form>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php if (!$pending_reports): ?><div class="small text-muted mb-2"><i class="fa fa-circle-check text-success me-1"></i>No reports waiting for review.</div><?php endif; ?>
        <?php if ($recent_reports): ?>
        <div class="small text-muted mt-2">
            <span class="fw-bold text-uppercase" style="font-size:10.5px;letter-spacing:.06em;">Recently handled:</span>
            <?php foreach ($recent_reports as $r): ?>
                <span class="ms-2"><?= htmlspecialchars($r['item_name']) ?> (<?= (int)$r['quantity'] ?> <?= strtolower(htmlspecialchars($r['report_type'])) ?>) — <b class="<?= $r['status'] === 'Approved' ? 'text-success' : 'text-danger' ?>"><?= htmlspecialchars($r['status']) ?></b></span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Category Filter Tabs -->
    <?php if ($attn_items > 0): ?>
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 p-3 mb-3 rounded-4 border" style="background:#fffbeb;border-color:#fde68a !important;">
        <div style="font-size:13.5px;color:#92400e;">
            <i class="fa fa-triangle-exclamation me-1"></i>
            <b><?= $attn_items ?> item<?= $attn_items === 1 ? '' : 's' ?> need attention:</b>
            <?= $attn['No price'] ?> without a selling price · <?= $attn['No expiry date'] ?> medicines/vaccines without an expiry date<?= $attn['Expired'] ? ' · <b class="text-danger">' . $attn['Expired'] . ' expired</b>' : '' ?>.
            Open an item's <b>⋮ → Edit Item Details</b> to fill it in.
        </div>
        <button class="btn btn-sm btn-warning fw-bold rounded-pill px-3" onclick="filterCategory('attention', document.getElementById('tabAttention'))"><i class="fa fa-filter me-1"></i> Show them</button>
    </div>
    <?php endif; ?>
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div class="d-flex gap-2 flex-wrap">
            <button class="inv-ftab active" onclick="filterCategory('all', this)">
                <i class="fa fa-layer-group me-1"></i> All Items
            </button>
            <button class="inv-ftab" onclick="filterCategory('Vaccines', this)">
                <i class="fa fa-syringe me-1"></i> Vaccines
            </button>
            <button class="inv-ftab" onclick="filterCategory('Medicines', this)">
                <i class="fa fa-pills me-1"></i> Medicines
            </button>
            <button class="inv-ftab" onclick="filterCategory('Supplies', this)">
                <i class="fa fa-first-aid me-1"></i> Supplies
            </button>
            <button class="inv-ftab" onclick="filterCategory('Pet Food', this)">
                <i class="fa fa-bone me-1"></i> Pet Food
            </button>
            <button class="inv-ftab" id="tabAttention" onclick="filterCategory('attention', this)">
                <i class="fa fa-triangle-exclamation me-1"></i> Needs Attention <span class="badge bg-warning text-dark ms-1"><?= $attn_items ?></span>
            </button>
        </div>
    </div>
    <div class="custom-card">
        <?php if($inventory && $inventory->num_rows > 0): ?>
        <div class="table-responsive">
            <table id="inventoryTable" class="table table-custom table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Item & Supplier</th>
                        <th>Category</th>
                        <th>Batch / Lot No.</th>
                        <th>Stock Level</th>
                        <th>Unit</th>
                        <th>Price (Selling / Cost)</th>
                        <th>Expiry Date</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($row = $inventory->fetch_assoc()): ?>
                    <?php 
                        $qty = (int)$row['quantity'];
                        $reorder = (int)($row['reorder_level'] ?? 10);
                        $batch = htmlspecialchars($row['batch_number'] ?? '');
                        $lot = htmlspecialchars($row['lot_number'] ?? '');
                        $uom = htmlspecialchars($row['unit_of_measure'] ?? 'piece');
                        $supplier = htmlspecialchars($row['supplier'] ?? '');
                        $price = (float)$row['price_per_unit'];
                        $cost = (float)($row['cost_price'] ?? 0);
                        $expiry_raw = $row['expiry_date'];
                        $is_no_expiry = empty($expiry_raw) || $expiry_raw === '1970-01-01' || $expiry_raw === '0000-00-00';
                        $is_expired = !$is_no_expiry && strtotime($expiry_raw) < time();
                        $is_expiring_soon = !$is_no_expiry && !$is_expired && strtotime($expiry_raw) <= strtotime('+7 days');

                        // Stock Status logic
                        if ($qty == 0) {
                            $stock_status = "<span class='badge bg-dark text-white rounded-pill px-2.5 py-1.5 fw-bold' style='font-size: 11px;'><i class='fa fa-times-circle me-1'></i>Out of Stock</span>";
                        } elseif ($qty <= $reorder) {
                            $stock_status = "<span class='badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 rounded-pill px-2.5 py-1.5 fw-bold' style='font-size: 11px;'><i class='fa fa-exclamation-circle me-1'></i>$qty (Low)</span>";
                        } elseif ($qty <= ($reorder * 1.5)) {
                            $stock_status = "<span class='badge bg-warning bg-opacity-10 text-dark border border-warning border-opacity-25 rounded-pill px-2.5 py-1.5 fw-bold' style='font-size: 11px;'><i class='fa fa-exclamation-triangle me-1'></i>$qty</span>";
                        } else {
                            $stock_status = "<span class='badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 rounded-pill px-2.5 py-1.5 fw-bold' style='font-size: 11px;'><i class='fa fa-check-circle me-1'></i>$qty</span>";
                        }

                        // JSON object for edit script
                        $json = json_encode($row, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                    ?>
                    <?php $row_issues = inv_attention($row); ?>
                    <tr class="inv-row" data-category="<?= htmlspecialchars($row['category']) ?>" data-group="<?= htmlspecialchars(inv_group_of($row['category'])) ?>" data-attention="<?= $row_issues ? '1' : '0' ?>">
                        <td>
                            <div class="fw-bold text-dark"><?php echo htmlspecialchars($row['item_name']); ?></div>
                            <?php foreach ($row_issues as $iss): ?>
                                <span class="badge rounded-pill <?= $iss === 'Expired' ? 'bg-danger' : 'bg-warning text-dark' ?> me-1" style="font-size:10px;"><i class="fa fa-triangle-exclamation me-1"></i><?= $iss ?></span>
                            <?php endforeach; ?>
                            <div class="small text-muted">
                                <?php if (!empty($supplier)): ?>
                                    <i class="fa fa-truck me-1 opacity-50"></i><?= $supplier ?>
                                <?php else: ?>
                                    <span class="opacity-50">No supplier listed</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 rounded-pill px-2.5 py-1 fw-bold" style="font-size: 11px;">
                                <?php echo htmlspecialchars($row['category']); ?>
                            </span>
                        </td>
                        <td>
                            <?php if(!empty($batch) || !empty($lot)): ?>
                                <div class="small font-monospace">
                                    <?php if(!empty($batch)): ?><span class="badge bg-light text-dark border"><i class="fa fa-barcode me-1 opacity-50"></i>B: <?= $batch ?></span><?php endif; ?>
                                    <?php if(!empty($lot)): ?><span class="badge bg-light text-dark border"><i class="fa fa-tag me-1 opacity-50"></i>L: <?= $lot ?></span><?php endif; ?>
                                </div>
                            <?php else: ?>
                                <span class="text-muted small font-monospace">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= $stock_status ?>
                            <div class="text-muted" style="font-size: 10.5px; margin-top: 2px;">min threshold: <?= $reorder ?></div>
                        </td>
                        <td>
                            <span class="badge bg-light text-secondary border px-2 py-1 text-lowercase font-monospace">
                                <?= $uom ?>
                            </span>
                        </td>
                        <td>
                            <div class="fw-bold text-dark">
                                <?php if($price > 0): ?>
                                    &#8369;<?= number_format($price, 2) ?>
                                <?php else: ?>
                                    <span class="text-muted fw-normal">—</span>
                                <?php endif; ?>
                            </div>
                            <?php if($cost > 0): ?>
                                <div class="text-muted small" style="font-size: 11px;">cost: &#8369;<?= number_format($cost, 2) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if($is_no_expiry): ?>
                                <span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25 rounded-pill px-2.5 py-1 fw-bold" style="font-size: 11px;">
                                    Not Set
                                </span>
                            <?php elseif($is_expired): ?>
                                <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 rounded-pill px-2.5 py-1 fw-bold" style="font-size: 11px;">
                                    <i class="fa fa-times-circle me-1"></i>Expired (<?= date("M d, Y", strtotime($expiry_raw)) ?>)
                                </span>
                            <?php elseif($is_expiring_soon): ?>
                                <span class="badge bg-warning bg-opacity-10 text-dark border border-warning border-opacity-25 rounded-pill px-2.5 py-1 fw-bold" style="font-size: 11px;">
                                    <i class="fa fa-clock me-1"></i><?= date("M d, Y", strtotime($expiry_raw)) ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted small fw-semibold">
                                    <span class="d-none"><?= $expiry_raw ?></span>
                                    <?= date("M d, Y", strtotime($expiry_raw)) ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <div class="d-inline-flex align-items-center gap-1">
                                <button class="btn btn-sm btn-outline-success rounded-pill px-3 py-1 fw-bold shadow-sm d-inline-flex align-items-center gap-1 iv-restock"
                                        onclick='openRestockModal(<?= htmlspecialchars($json, ENT_QUOTES, 'UTF-8') ?>)'>
                                    <i class="fa fa-plus-circle"></i> Restock
                                </button>
                                <div class="dropdown d-inline-block">
                                    <button class="btn btn-sm btn-light rounded-circle shadow-sm border iv-more" type="button" data-bs-toggle="dropdown" style="width: 32px; height: 32px; padding: 0;">
                                        <i class="fa fa-ellipsis-v text-secondary"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end border-0 shadow-lg p-2" style="border-radius: 12px; font-size: 13px;">
                                        <li>
                                            <a class="dropdown-item rounded-2 py-1.5" href="#" onclick='openEditModal(<?= htmlspecialchars($json, ENT_QUOTES, 'UTF-8') ?>); return false;'>
                                                <i class="fa fa-edit text-primary me-2"></i>Edit Item Details
                                            </a>
                                        </li>
                                        <li>
                                            <a class="dropdown-item rounded-2 py-1.5" href="#" onclick='openHistoryModal(<?= (int)$row['id'] ?>, <?= json_encode($row['item_name'], JSON_HEX_APOS | JSON_HEX_QUOT) ?>); return false;'>
                                                <i class="fa fa-clock-rotate-left text-info me-2"></i>Stock History
                                            </a>
                                        </li>
                                        <li><hr class="dropdown-divider my-1"></li>
                                        <li>
                                            <a class="dropdown-item rounded-2 py-1.5 text-danger" href="#" onclick='openDeleteModal(<?= $row['id'] ?>); return false;'>
                                                <i class="fa fa-trash-alt me-2"></i>Delete Item
                                            </a>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
            <div class="text-center py-5">
                <i class="fa fa-box-open fa-4x text-muted opacity-25 mb-3"></i>
                <h5 class="text-muted">Inventory is empty.</h5>
                <p class="text-muted small mb-3">Click "+ Add Item" to register clinical medicines and supplies.</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Recent stock movements -->
    <div class="custom-card mt-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h5 class="fw-bold m-0"><i class="fa fa-clock-rotate-left text-primary me-2"></i>Recent Stock Movements</h5>
                <div class="text-muted small">Every restock, edit, use on an appointment and deletion — who did it and when.</div>
            </div>
        </div>
        <?php if ($recent_movements): ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" style="font-size:13px;">
                <thead><tr class="text-muted"><th>When</th><th>Item</th><th>Movement</th><th class="text-end">Change</th><th class="text-end">Stock After</th><th>Reference</th><th>By</th></tr></thead>
                <tbody>
                <?php foreach ($recent_movements as $mv): $chg = (int)$mv['qty_change']; ?>
                    <tr>
                        <td class="text-muted"><?= date('M d, Y g:i A', strtotime($mv['created_at'])) ?></td>
                        <td class="fw-semibold"><?= htmlspecialchars($mv['item_name'] ?? '#' . $mv['item_id']) ?></td>
                        <td><span class="badge rounded-pill mv-<?= strtolower(str_replace(' ', '-', $mv['movement_type'])) ?>"><?= htmlspecialchars($mv['movement_type']) ?></span></td>
                        <td class="text-end fw-bold <?= $chg > 0 ? 'text-success' : ($chg < 0 ? 'text-danger' : 'text-muted') ?>"><?= $chg > 0 ? '+' . $chg : $chg ?></td>
                        <td class="text-end"><?= $mv['qty_after'] !== null ? (int)$mv['qty_after'] : '—' ?></td>
                        <td class="text-muted"><?= htmlspecialchars(str_replace(' -> ', ' → ', $mv['reference'] ?? '')) ?: '—' ?></td>
                        <td class="text-muted"><?= htmlspecialchars($mv['actor'] ?? 'System') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
            <div class="text-muted text-center py-4"><i class="fa fa-clock-rotate-left me-1 opacity-50"></i>No stock movements recorded yet.</div>
        <?php endif; ?>
    </div>
</div>

<!-- Stock History Modal -->
<div class="modal fade" id="historyModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg" style="border-radius:18px;">
            <div class="modal-header border-0">
                <h5 class="fw-bold m-0"><i class="fa fa-clock-rotate-left text-info me-2"></i>Stock History — <span id="historyItemName"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-0" id="historyBody"></div>
        </div>
    </div>
</div>
<script>const INV_MOVEMENTS = <?= json_encode($movements_by_item, JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>

<!-- Add Item Modal -->
<div class="modal fade" id="addItemModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 20px;">
            <div class="modal-header border-0 pb-0">
                <h5 class="fw-bold"><i class="fa fa-plus-circle text-primary me-2"></i>Add New Inventory Item</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form method="POST">
                    <div class="row g-3 mb-3">
                        <div class="col-md-8">
                            <label class="saas-label">Item Name</label>
                            <input type="text" name="item_name" class="form-control" required placeholder="E.g. Rabies Vaccine, Amoxicillin 500mg">
                        </div>
                        <div class="col-md-4">
                            <label class="saas-label">Category</label>
                            <select name="category" class="form-select" required>
                                <?php foreach (INV_GROUPS as $g => $cats): ?><optgroup label="<?= $g ?>"><?php foreach ($cats as $cat): ?><option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="saas-label">Batch Number</label>
                            <input type="text" name="batch_number" class="form-control" placeholder="E.g. BATCH-2026-A">
                        </div>
                        <div class="col-md-4">
                            <label class="saas-label">Lot Number</label>
                            <input type="text" name="lot_number" class="form-control" placeholder="E.g. LOT-9982">
                        </div>
                        <div class="col-md-4">
                            <label class="saas-label">Unit of Measure</label>
                            <select name="unit_of_measure" class="form-select">
                                <option value="vial">Vial</option>
                                <option value="tablet">Tablet</option>
                                <option value="bottle">Bottle</option>
                                <option value="mL">mL</option>
                                <option value="sachet">Sachet</option>
                                <option value="piece" selected>Piece</option>
                                <option value="box">Box</option>
                            </select>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="saas-label">Initial Quantity</label>
                            <input type="number" name="quantity" class="form-control" required min="0" value="100">
                        </div>
                        <div class="col-md-4">
                            <label class="saas-label">Reorder Level (Alert Threshold)</label>
                            <input type="number" name="reorder_level" class="form-control" required min="1" value="10">
                        </div>
                        <div class="col-md-4">
                            <label class="saas-label">Supplier / Brand</label>
                            <input type="text" name="supplier" class="form-control" placeholder="E.g. Pfizer Animal Health">
                        </div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="saas-label">Selling Price / Unit (&#8369;)</label>
                            <input type="number" step="0.01" name="price_per_unit" class="form-control" required min="0" value="0.00">
                        </div>
                        <div class="col-md-4">
                            <label class="saas-label">Purchase Cost / Unit (&#8369;)</label>
                            <input type="number" step="0.01" name="cost_price" class="form-control" min="0" value="0.00">
                        </div>
                        <div class="col-md-4">
                            <label class="saas-label">Expiry Date (Optional)</label>
                            <input type="text" name="expiry_date" class="form-control flatpickr-expiry bg-white" placeholder="Select Expiry Date">
                        </div>
                    </div>

                    <button type="submit" name="add_item" class="btn btn-primary w-100 fw-bold py-2.5 rounded-pill shadow-sm">
                        <i class="fa fa-save me-1"></i> Add Item to Inventory
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Edit Item Modal -->
<div class="modal fade" id="editItemModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 20px;">
            <div class="modal-header border-0 pb-0">
                <h5 class="fw-bold"><i class="fa fa-edit text-primary me-2"></i>Edit Inventory Item</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form method="POST">
                    <input type="hidden" name="item_id" id="edit_item_id">

                    <div class="row g-3 mb-3">
                        <div class="col-md-8">
                            <label class="saas-label">Item Name</label>
                            <input type="text" name="item_name" id="edit_item_name" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="saas-label">Category</label>
                            <select name="category" id="edit_category" class="form-select" required>
                                <?php foreach (INV_GROUPS as $g => $cats): ?><optgroup label="<?= $g ?>"><?php foreach ($cats as $cat): ?><option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="saas-label">Batch Number</label>
                            <input type="text" name="batch_number" id="edit_batch_number" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="saas-label">Lot Number</label>
                            <input type="text" name="lot_number" id="edit_lot_number" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="saas-label">Unit of Measure</label>
                            <select name="unit_of_measure" id="edit_unit_of_measure" class="form-select">
                                <option value="vial">Vial</option>
                                <option value="tablet">Tablet</option>
                                <option value="bottle">Bottle</option>
                                <option value="mL">mL</option>
                                <option value="sachet">Sachet</option>
                                <option value="piece">Piece</option>
                                <option value="box">Box</option>
                            </select>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="saas-label">Current Stock Quantity</label>
                            <input type="number" name="quantity" id="edit_quantity" class="form-control" required min="0">
                        </div>
                        <div class="col-md-4">
                            <label class="saas-label">Reorder Level (Alert Threshold)</label>
                            <input type="number" name="reorder_level" id="edit_reorder_level" class="form-control" required min="1">
                        </div>
                        <div class="col-md-4">
                            <label class="saas-label">Supplier / Brand</label>
                            <input type="text" name="supplier" id="edit_supplier" class="form-control">
                        </div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="saas-label">Selling Price / Unit (&#8369;)</label>
                            <input type="number" step="0.01" name="price_per_unit" id="edit_price" class="form-control" required min="0">
                        </div>
                        <div class="col-md-4">
                            <label class="saas-label">Purchase Cost / Unit (&#8369;)</label>
                            <input type="number" step="0.01" name="cost_price" id="edit_cost_price" class="form-control" min="0">
                        </div>
                        <div class="col-md-4">
                            <label class="saas-label">Expiry Date</label>
                            <input type="text" name="expiry_date" id="edit_expiry" class="form-control flatpickr-expiry bg-white" placeholder="Select Date">
                        </div>
                    </div>

                    <button type="submit" name="edit_item" class="btn btn-primary w-100 fw-bold py-2.5 rounded-pill shadow-sm">
                        <i class="fa fa-save me-1"></i> Update Item
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Quick Restock Modal -->
<div class="modal fade" id="restockModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 20px;">
            <div class="modal-header border-0 pb-0">
                <h5 class="fw-bold"><i class="fa fa-plus-circle text-success me-2"></i>Replenish Stock</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form method="POST">
                    <input type="hidden" name="item_id" id="restock_item_id">
                    <div class="mb-3 p-3 bg-light rounded-3">
                        <div class="fw-bold text-dark fs-6" id="restock_item_name"></div>
                        <div class="small text-muted" id="restock_current_info"></div>
                    </div>

                    <div class="mb-3">
                        <label class="saas-label">Quantity to Add (+)</label>
                        <input type="number" name="restock_qty" class="form-control form-control-lg fw-bold text-success" required min="1" value="50">
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="saas-label">New Batch No. (Optional)</label>
                            <input type="text" name="restock_batch" id="restock_batch" class="form-control" placeholder="Keep current if empty">
                        </div>
                        <div class="col-md-6">
                            <label class="saas-label">New Lot No. (Optional)</label>
                            <input type="text" name="restock_lot" id="restock_lot" class="form-control" placeholder="Keep current if empty">
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="saas-label">New Expiry Date (Optional)</label>
                        <input type="text" name="restock_expiry" id="restock_expiry" class="form-control flatpickr-expiry bg-white" placeholder="Select Date if updated">
                    </div>

                    <button type="submit" name="restock_item" class="btn btn-success w-100 fw-bold py-2.5 rounded-pill shadow-sm">
                        <i class="fa fa-check-circle me-1"></i> Confirm Replenishment
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Delete Item Modal -->
<div class="modal fade" id="deleteItemModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow-lg text-center p-4" style="border-radius: 20px;">
            <div class="mb-3 text-danger"><i class="fa fa-exclamation-triangle fa-3x"></i></div>
            <h5 class="fw-bold mb-3">Delete Item?</h5>
            <p class="text-muted small mb-4">Are you sure you want to permanently delete this item from inventory? This action cannot be undone.</p>
            <form method="POST">
                <input type="hidden" name="item_id" id="delete_item_id">
                <div class="d-flex gap-2 justify-content-center">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="delete_item" class="btn btn-danger fw-bold rounded-pill px-4">Delete Item</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Expiry Alert Modal -->
<?php if ($expired_stock > 0 || $expiring_soon > 0): ?>
<div class="modal fade" id="expiryAlertModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg text-center p-4" style="border-radius: 20px;">
            <div class="mb-3">
                <i class="fa fa-exclamation-circle fa-4x text-<?php echo ($expired_stock > 0) ? 'danger' : 'warning'; ?>"></i>
            </div>
            <h4 class="fw-bold text-dark mb-3">Inventory Expiry Warning</h4>
            <p class="text-muted mb-4">Stock safety alerts requiring immediate attention:</p>
            
            <div class="row g-3 mb-4">
                <?php if ($expired_stock > 0): ?>
                <div class="col-12">
                    <div class="p-3 bg-danger bg-opacity-10 border border-danger border-opacity-25 rounded-3 text-danger fw-bold">
                        <i class="fa fa-times-circle me-2"></i> <?php echo $expired_stock; ?> item(s) have passed their expiry date!
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($expiring_soon > 0): ?>
                <div class="col-12">
                    <div class="p-3 bg-warning bg-opacity-10 border border-warning border-opacity-25 rounded-3 text-warning fw-bold" style="color: #b45309 !important;">
                        <i class="fa fa-clock me-2"></i> <?php echo $expiring_soon; ?> item(s) will expire within 7 days!
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <button type="button" class="btn btn-primary w-100 fw-bold py-2 rounded-pill shadow-sm" data-bs-dismiss="modal">Acknowledge & Inspect</button>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
$(document).ready(function() {
    <?php if ($expired_stock > 0 || $expiring_soon > 0): ?>
        new bootstrap.Modal(document.getElementById('expiryAlertModal')).show();
    <?php endif; ?>

    var table = $('#inventoryTable').DataTable({
        "order": [[ 6, "desc" ]],
        "dom": "rtip",
        "language": {
            "search": "",
            "searchPlaceholder": "Search inventory..."
        }
    });

    $('#customSearch').on('keyup', function () {
        table.search(this.value).draw();
    });
});

// Tabs filter by category group (Vaccines / Medicines / Supplies / Pet Food) or "Needs attention"
var invActiveTab = 'all';
$.fn.dataTable.ext.search.push(function (settings, data, dataIndex) {
    if (settings.nTable.id !== 'inventoryTable' || invActiveTab === 'all') return true;
    var row = settings.aoData[dataIndex].nTr;
    if (invActiveTab === 'attention') return row.getAttribute('data-attention') === '1';
    return row.getAttribute('data-group') === invActiveTab;
});
function filterCategory(cat, btn) {
    $('.inv-ftab').removeClass('active');
    $(btn).addClass('active');
    invActiveTab = cat;
    $('#inventoryTable').DataTable().draw();
}

function openHistoryModal(id, name) {
    document.getElementById('historyItemName').textContent = name;
    var rows = INV_MOVEMENTS[id] || [];
    var esc = function (t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; };
    var html = rows.length ? '<table class="table table-sm align-middle mb-0" style="font-size:13px;"><thead><tr class="text-muted"><th>When</th><th>Movement</th><th class="text-end">Change</th><th class="text-end">Stock After</th><th>Reference</th><th>By</th></tr></thead><tbody>' +
        rows.map(function (m) {
            var c = parseInt(m.qty_change, 10);
            return '<tr><td class="text-muted">' + esc(new Date(m.created_at.replace(' ', 'T')).toLocaleString('en-US', { month: 'short', day: '2-digit', year: 'numeric', hour: 'numeric', minute: '2-digit' })) + '</td>' +
                '<td><span class="badge rounded-pill mv-' + esc(m.movement_type.toLowerCase().replace(/ /g, '-')) + '">' + esc(m.movement_type) + '</span></td>' +
                '<td class="text-end fw-bold ' + (c > 0 ? 'text-success' : c < 0 ? 'text-danger' : 'text-muted') + '">' + (c > 0 ? '+' + c : c) + '</td>' +
                '<td class="text-end">' + (m.qty_after == null ? '—' : esc(m.qty_after)) + '</td>' +
                '<td class="text-muted">' + (esc((m.reference || '').replace(' -> ', ' → ')) || '—') + '</td><td class="text-muted">' + esc(m.actor || 'System') + '</td></tr>';
        }).join('') + '</tbody></table>'
        : '<div class="text-muted text-center py-4"><i class="fa fa-clock-rotate-left me-1 opacity-50"></i>No stock movements recorded for this item yet. Changes are logged from now on.</div>';
    document.getElementById('historyBody').innerHTML = html;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('historyModal')).show();
}

function openEditModal(item) {
    document.getElementById('edit_item_id').value = item.id;
    document.getElementById('edit_item_name').value = item.item_name || '';
    (function (sel, v) { if (v && ![...sel.options].some(o => o.value === v)) { const o = new Option(v + ' (old)', v); sel.add(o); } sel.value = v || 'Antibiotics'; })(document.getElementById('edit_category'), item.category);
    document.getElementById('edit_quantity').value = item.quantity || 0;
    document.getElementById('edit_reorder_level').value = item.reorder_level || 10;
    document.getElementById('edit_supplier').value = item.supplier || '';
    document.getElementById('edit_price').value = item.price_per_unit || 0;
    document.getElementById('edit_cost_price').value = item.cost_price || 0;
    document.getElementById('edit_batch_number').value = item.batch_number || '';
    document.getElementById('edit_lot_number').value = item.lot_number || '';
    document.getElementById('edit_unit_of_measure').value = item.unit_of_measure || 'piece';

    if (item.expiry_date && item.expiry_date !== '1970-01-01' && item.expiry_date !== '0000-00-00') {
        document.getElementById('edit_expiry')._flatpickr.setDate(item.expiry_date);
    } else {
        document.getElementById('edit_expiry')._flatpickr.clear();
    }
    new bootstrap.Modal(document.getElementById('editItemModal')).show();
}

function openRestockModal(item) {
    document.getElementById('restock_item_id').value = item.id;
    document.getElementById('restock_item_name').textContent = item.item_name;
    document.getElementById('restock_current_info').textContent = "Current stock: " + item.quantity + " " + (item.unit_of_measure || 'units') + " | Reorder level: " + (item.reorder_level || 10);
    document.getElementById('restock_batch').value = item.batch_number || '';
    document.getElementById('restock_lot').value = item.lot_number || '';
    new bootstrap.Modal(document.getElementById('restockModal')).show();
}

function openDeleteModal(id) {
    document.getElementById('delete_item_id').value = id;
    new bootstrap.Modal(document.getElementById('deleteItemModal')).show();
}

// Auto Dismiss Alerts
setTimeout(function() {
    let alerts = document.querySelectorAll('.alert-container');
    alerts.forEach(a => {
        a.style.transition = 'opacity 0.5s ease-out, transform 0.5s ease-out';
        a.style.opacity = '0';
        a.style.transform = 'translateY(-20px)';
        setTimeout(() => a.remove(), 500);
    });
}, 2000);

document.addEventListener('DOMContentLoaded', function() {
    flatpickr(".flatpickr-expiry", {
        dateFormat: "Y-m-d"
    });
});
</script>
<script src="mousetrail.js"></script>
<style>
    .mv-restock { background:#dcfce7; color:#166534; } .mv-used { background:#dbeafe; color:#1e40af; } .mv-adjustment { background:#fef3c7; color:#92400e; }
    .mv-added { background:#e0f2fe; color:#075985; } .mv-deleted { background:#fee2e2; color:#991b1b; } .mv-price-change { background:#f3e8ff; color:#6b21a8; } .mv-write-off { background:#fee2e2; color:#991b1b; }
</style>
</body>
</html>
