<?php
session_start();
include("dbconnection.php");
// Valid invoices only: no void ones, none for cancelled/rejected/no-show or deleted bookings, one per boarding stay
include_once("includes/report_data.php");
[$pv_valid_bills] = rpt_valid_bills($conn);
$pv_valid_ids = implode(',', array_map(fn($b) => (int)$b['id'], $pv_valid_bills)) ?: '0';
// 1. AUTHENTICATION & ACCESS GUARD
$user_role = $_SESSION['role'] ?? ($_SESSION['user_role'] ?? '');
$is_admin = ($user_role === 'admin');
$is_owner = ($user_role === 'clinic_owner' || $user_role === 'owner');

if (!isset($_SESSION['user_id']) || (!$is_admin && !$is_owner)) {
    if ($user_role === 'staff') {
        header("Location: staff_dashboard.php");
    } elseif ($user_role === 'doctor') {
        header("Location: doctor.php");
    } else {
        header("Location: index.php");
    }
    exit();
}

$active_nav = 'reports';

// 2. PERIOD & DATE RANGE FILTERING
$period = $_GET['period'] ?? 'month';
$start_date_input = trim($_GET['start_date'] ?? '');
$end_date_input = trim($_GET['end_date'] ?? '');
$active_tab = $_GET['tab'] ?? 'appointments';

$filter_label = "This Month (" . date('F Y') . ")";
$start_date = date('Y-m-01');
$end_date = date('Y-m-t');

if ($period === 'today') {
    $start_date = date('Y-m-d');
    $end_date = date('Y-m-d');
    $filter_label = "Today (" . date('M j, Y') . ")";
} elseif ($period === 'week') {
    $start_date = date('Y-m-d', strtotime('monday this week'));
    $end_date = date('Y-m-d', strtotime('sunday this week'));
    $filter_label = "This Week (" . date('M j', strtotime($start_date)) . " - " . date('M j, Y', strtotime($end_date)) . ")";
} elseif ($period === 'month') {
    $start_date = date('Y-m-01');
    $end_date = date('Y-m-t');
    $filter_label = "This Month (" . date('F Y') . ")";
} elseif ($period === 'year') {
    $start_date = date('Y-01-01');
    $end_date = date('Y-12-31');
    $filter_label = "This Year (" . date('Y') . ")";
} elseif ($period === 'custom' && !empty($start_date_input) && !empty($end_date_input)) {
    $start_date = date('Y-m-d', strtotime($start_date_input));
    $end_date = date('Y-m-d', strtotime($end_date_input));
    if ($start_date > $end_date) {
        $temp = $start_date; $start_date = $end_date; $end_date = $temp;
    }
    $filter_label = "Custom: " . date('M j, Y', strtotime($start_date)) . " to " . date('M j, Y', strtotime($end_date));
} elseif ($period === 'all') {
    $start_date = '2000-01-01';
    $end_date = '2099-12-31';
    $filter_label = "All Time History";
}

// 3. EXPORT HANDLERS (CSV)
if (isset($_GET['export_report'])) {
    $type = $_GET['export_report'];
    if (ob_get_length()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    
    if ($type === 'appointments') {
        header('Content-Disposition: attachment; filename="appointments_report_' . date('Ymd_His') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Appt ID', 'Client Name', 'Pet Name', 'Service', 'Date', 'Time', 'Doctor Assigned', 'Status']);
        $q = $conn->query("
            SELECT a.id, COALESCE(u.fullname, 'Walk-in Client') as client_name, a.pet_name, a.service_type, 
                   a.appointment_date, a.appointment_time, COALESCE(d.fullname, 'Unassigned') as doc_name, a.status
            FROM appointments a
            LEFT JOIN users u ON a.user_id = u.id
            LEFT JOIN users d ON a.doctor_id = d.id
            WHERE a.service_type != 'Pet Boarding Reservation' AND a.appointment_date BETWEEN '$start_date' AND '$end_date'
            ORDER BY a.appointment_date DESC, a.appointment_time DESC
        ");
        while ($r = $q->fetch_assoc()) fputcsv($out, $r);
        fclose($out);
        exit();
    } elseif ($type === 'boarding') {
        header('Content-Disposition: attachment; filename="boarding_report_' . date('Ymd_His') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Res ID', 'Client Name', 'Pet Name', 'Cage #', 'Check In', 'Check Out', 'Status']);
        $q = $conn->query("
            SELECT br.id, COALESCE(u.fullname, 'Client') as client_name, br.pet_name, 
                   COALESCE(br.cage_number, 'N/A') as cage_no, br.appointment_date, br.end_date, br.status
            FROM boarding_reservations br
            LEFT JOIN users u ON br.user_id = u.id
            WHERE br.appointment_date BETWEEN '$start_date' AND '$end_date'
            ORDER BY br.appointment_date DESC
        ");
        while ($r = $q->fetch_assoc()) fputcsv($out, $r);
        fclose($out);
        exit();
    } elseif ($type === 'payments') {
        header('Content-Disposition: attachment; filename="payments_report_' . date('Ymd_His') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Invoice #', 'Client Name', 'Category', 'Total Billed', 'Amount Paid', 'Status', 'Payment Method', 'Date']);
        $q = $conn->query("
            SELECT b.invoice_no, COALESCE(NULLIF(TRIM(b.client_name), ''), u.fullname, 'Client') as client_name,
                   CASE WHEN b.is_boarding=1 THEN 'Boarding' ELSE 'Clinic' END as cat,
                   b.total_amount, b.amount_paid, b.payment_status, b.payment_method, b.created_at
            FROM billing b
            LEFT JOIN users u ON b.user_id = u.id
            WHERE b.id IN ($pv_valid_ids) AND DATE(b.created_at) BETWEEN '$start_date' AND '$end_date'
            ORDER BY b.created_at DESC
        ");
        while ($r = $q->fetch_assoc()) fputcsv($out, $r);
        fclose($out);
        exit();
    } elseif ($type === 'feedback') {
        header('Content-Disposition: attachment; filename="feedback_report_' . date('Ymd_His') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Feedback ID', 'Client Name', 'Pet Name', 'Rating (Stars)', 'Comments', 'Submitted At']);
        $q = $conn->query("
            SELECT f.id, COALESCE(u.fullname, 'Anonymous') as client_name, COALESCE(a.pet_name, 'N/A') as pet_name,
                   f.rating, f.comments, f.created_at
            FROM feedbacks f
            LEFT JOIN users u ON f.user_id = u.id
            LEFT JOIN appointments a ON f.appointment_id = a.id
            ORDER BY f.created_at DESC
        ");
        while ($r = $q->fetch_assoc()) fputcsv($out, $r);
        fclose($out);
        exit();
    } elseif ($type === 'system') {
        header('Content-Disposition: attachment; filename="system_audit_report_' . date('Ymd_His') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Metric', 'Value', 'Details']);
        fputcsv($out, ['Total Accounts', $conn->query("SELECT COUNT(*) FROM users")->fetch_row()[0], 'All registered accounts']);
        fputcsv($out, ['Verified Users', $conn->query("SELECT COUNT(*) FROM users WHERE is_verified=1")->fetch_row()[0], 'Email/identity verified']);
        fputcsv($out, ['Locked Accounts', $conn->query("SELECT COUNT(*) FROM users WHERE locked_until > NOW()")->fetch_row()[0], 'Security lockouts']);
        fputcsv($out, ['Total Pets', $conn->query("SELECT COUNT(*) FROM pets WHERE deleted_at IS NULL")->fetch_row()[0], 'Registered animal patients']);
        fputcsv($out, ['Total Appointments', $conn->query("SELECT COUNT(*) FROM appointments")->fetch_row()[0], 'All-time appointments']);
        fputcsv($out, ['Total Invoices', $conn->query("SELECT COUNT(*) FROM billing WHERE id IN ($pv_valid_ids)")->fetch_row()[0], 'All-time transactions']);
        fclose($out);
        exit();
    }
}

// 4. DATA AGGREGATIONS

// A. Appointments
$appt_totals = $conn->query("
    SELECT 
        COUNT(*) as total_appts,
        SUM(CASE WHEN status='Completed' THEN 1 ELSE 0 END) as completed_cnt,
        SUM(CASE WHEN status='Approved' THEN 1 ELSE 0 END) as approved_cnt,
        SUM(CASE WHEN status='Pending' THEN 1 ELSE 0 END) as pending_cnt,
        SUM(CASE WHEN status='Cancelled' THEN 1 ELSE 0 END) as cancelled_cnt,
        SUM(CASE WHEN status='No Show' THEN 1 ELSE 0 END) as noshow_cnt
    FROM appointments 
    WHERE service_type != 'Pet Boarding Reservation' AND appointment_date BETWEEN '$start_date' AND '$end_date'
")->fetch_assoc();

$doc_caseload = $conn->query("
    SELECT u.id, u.fullname,
           COUNT(a.id) as total_assigned,
           SUM(CASE WHEN a.status='Completed' THEN 1 ELSE 0 END) as completed,
           SUM(CASE WHEN a.status='Approved' THEN 1 ELSE 0 END) as active,
           SUM(CASE WHEN a.status='Cancelled' THEN 1 ELSE 0 END) as cancelled
    FROM users u
    LEFT JOIN appointments a ON a.doctor_id = u.id AND a.service_type != 'Pet Boarding Reservation' AND a.appointment_date BETWEEN '$start_date' AND '$end_date'
    WHERE u.role = 'doctor'
    GROUP BY u.id, u.fullname
    ORDER BY total_assigned DESC
");

$service_breakdown = $conn->query("
    SELECT service_type, COUNT(*) as count
    FROM appointments
    WHERE service_type != 'Pet Boarding Reservation' AND appointment_date BETWEEN '$start_date' AND '$end_date'
    GROUP BY service_type
    ORDER BY count DESC
    LIMIT 6
");

// B. Boarding
$boarding_totals = $conn->query("
    SELECT 
        COUNT(*) as total_reservations,
        SUM(CASE WHEN status IN ('Approved', 'Checked In') THEN 1 ELSE 0 END) as active_stays,
        SUM(CASE WHEN status='Completed' THEN 1 ELSE 0 END) as completed_stays,
        SUM(CASE WHEN status='Pending' THEN 1 ELSE 0 END) as pending_stays,
        AVG(DATEDIFF(end_date, appointment_date) + 1) as avg_stay_days
    FROM boarding_reservations
    WHERE appointment_date BETWEEN '$start_date' AND '$end_date'
")->fetch_assoc();

$boarding_rev = $conn->query("
    SELECT IFNULL(SUM(total_amount), 0) as total_rev, IFNULL(SUM(amount_paid), 0) as paid_rev
    FROM billing
    WHERE id IN ($pv_valid_ids) AND is_boarding = 1 AND DATE(created_at) BETWEEN '$start_date' AND '$end_date'
")->fetch_assoc();

// C. Payments & Billing
$billing_totals = $conn->query("
    SELECT 
        COUNT(*) as total_invoices,
        IFNULL(SUM(total_amount), 0) as gross_billed,
        IFNULL(SUM(amount_paid), 0) as net_collected,
        IFNULL(SUM(CASE WHEN payment_status != 'Paid' THEN (total_amount - amount_paid) ELSE 0 END), 0) as outstanding_balance,
        SUM(CASE WHEN payment_status='Paid' THEN 1 ELSE 0 END) as paid_invoices,
        SUM(CASE WHEN payment_status='Unpaid' THEN 1 ELSE 0 END) as unpaid_invoices
    FROM billing
    WHERE id IN ($pv_valid_ids) AND DATE(created_at) BETWEEN '$start_date' AND '$end_date'
")->fetch_assoc();

$methods_breakdown = $conn->query("
    SELECT payment_method, COUNT(*) as count, IFNULL(SUM(amount_paid), 0) as total
    FROM billing
    WHERE id IN ($pv_valid_ids) AND DATE(created_at) BETWEEN '$start_date' AND '$end_date' AND amount_paid > 0
    GROUP BY payment_method
    ORDER BY total DESC
");

// D. Feedbacks
$feedback_stats = $conn->query("
    SELECT 
        COUNT(*) as total_reviews,
        IFNULL(ROUND(AVG(rating), 2), 5.00) as avg_rating,
        SUM(CASE WHEN rating=5 THEN 1 ELSE 0 END) as star5,
        SUM(CASE WHEN rating=4 THEN 1 ELSE 0 END) as star4,
        SUM(CASE WHEN rating=3 THEN 1 ELSE 0 END) as star3,
        SUM(CASE WHEN rating=2 THEN 1 ELSE 0 END) as star2,
        SUM(CASE WHEN rating=1 THEN 1 ELSE 0 END) as star1
    FROM feedbacks
")->fetch_assoc();

$recent_feedbacks = $conn->query("
    SELECT f.*, COALESCE(u.fullname, 'Anonymous Client') as client_name, a.pet_name, a.service_type
    FROM feedbacks f
    LEFT JOIN users u ON f.user_id = u.id
    LEFT JOIN appointments a ON f.appointment_id = a.id
    ORDER BY f.created_at DESC
    LIMIT 10
");

// E. System
$user_counts = $conn->query("
    SELECT 
        COUNT(*) as total_users,
        SUM(CASE WHEN role IN ('user', 'client') THEN 1 ELSE 0 END) as clients,
        SUM(CASE WHEN role='staff' THEN 1 ELSE 0 END) as staff,
        SUM(CASE WHEN role='doctor' THEN 1 ELSE 0 END) as doctors,
        SUM(CASE WHEN role='admin' THEN 1 ELSE 0 END) as admins,
        SUM(CASE WHEN role IN ('clinic_owner', 'owner') THEN 1 ELSE 0 END) as owners,
        SUM(CASE WHEN is_verified=1 THEN 1 ELSE 0 END) as verified_users,
        SUM(CASE WHEN locked_until > NOW() THEN 1 ELSE 0 END) as locked_users
    FROM users
")->fetch_assoc();

$table_status_q = $conn->query("SHOW TABLE STATUS");
$tables_info = [];
$total_db_size_kb = 0;
while ($tbl = $table_status_q->fetch_assoc()) {
    $size_kb = round(($tbl['Data_length'] + $tbl['Index_length']) / 1024, 2);
    $total_db_size_kb += $size_kb;
    $tables_info[] = [
        'name' => $tbl['Name'],
        'rows' => $tbl['Rows'],
        'size_kb' => $size_kb,
        'engine' => $tbl['Engine']
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Clinical Intelligence Center | PragmaVet</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/pragmavet.css">
    <link href="assets/global-style.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        :root {
            --sidebar-width: 260px;
            --primary-accent: #2563eb;
            --bg-canvas: #f8fafc;
            --card-radius: 16px;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-canvas);
            color: #1e293b;
            margin: 0;
            padding: 0;
        }

        /* SIDEBAR STYLING */
        .sidebar {
            width: var(--sidebar-width);
            background: #ffffff;
            border-right: 1px solid #e2e8f0;
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            z-index: 1040;
            display: flex;
            flex-direction: column;
            padding: 20px 14px;
            overflow-y: auto;
        }

        .sidebar-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 4px 10px 18px;
            border-bottom: 1px solid #f1f5f9;
            margin-bottom: 16px;
        }

        .sidebar-brand-icon {
            width: 40px;
            height: 40px;
            border-radius: 11px;
            background: var(--primary-accent);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 19px;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.28);
        }

        .sidebar-brand span {
            font-size: 20px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.3px;
        }

        .sidebar-user {
            background: #f8fafc;
            border: 1px solid #eef2f6;
            border-radius: 12px;
            padding: 12px 14px;
            margin-bottom: 16px;
        }

        .sidebar-user .welcome {
            font-size: 11.5px;
            color: #64748b;
            font-weight: 600;
        }

        .sidebar-user .uname {
            font-size: 14.5px;
            font-weight: 700;
            color: #0f172a;
        }

        .sidebar-heading {
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #94a3b8;
            padding: 12px 14px 4px;
        }

        .sidebar a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            border-radius: 10px;
            color: #475569;
            text-decoration: none;
            font-size: 13.5px;
            font-weight: 600;
            margin-bottom: 2px;
            transition: all 0.2s ease;
        }

        .sidebar a i {
            width: 20px;
            text-align: center;
            font-size: 15px;
            color: #64748b;
        }

        .sidebar a:hover {
            background: #f1f5f9;
            color: #0f172a;
        }

        .sidebar a:hover i {
            color: var(--primary-accent);
        }

        .sidebar a.active {
            background: var(--primary-accent);
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.22);
        }

        .sidebar a.active i {
            color: #ffffff;
        }

        .sidebar-logout {
            margin-top: auto;
            padding-top: 14px;
            border-top: 1px solid #f1f5f9;
        }

        .sidebar-logout a {
            color: #ef4444;
        }

        .sidebar-logout a:hover {
            background: #fee2e2;
            color: #dc2626;
        }

        /* MAIN CONTENT */
        .main-content {
            margin-left: var(--sidebar-width);
            padding: 28px 34px;
            min-height: 100vh;
        }

        .stat-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: var(--card-radius);
            padding: 22px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.03);
            transition: all 0.2s ease;
            height: 100%;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.05);
        }

        .stat-icon {
            width: 46px;
            height: 46px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .report-nav-tab {
            padding: 10px 18px;
            border-radius: 12px;
            font-size: 13.5px;
            font-weight: 700;
            color: #64748b;
            text-decoration: none;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }

        .report-nav-tab:hover {
            background: #f1f5f9;
            color: #0f172a;
        }

        .report-nav-tab.active {
            background: var(--primary-accent);
            color: #ffffff;
            border-color: var(--primary-accent);
            box-shadow: 0 4px 14px rgba(37,99,235,0.22);
        }

        .custom-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: var(--card-radius);
            padding: 24px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.03);
            margin-bottom: 24px;
        }

        @media print {
            .sidebar, .filter-strip, .no-print, .btn, .nav-tabs-wrapper {
                display: none !important;
            }
            .main-content {
                margin-left: 0 !important;
                padding: 0 !important;
            }
        }
    </style>
</head>
<body>

    <!-- SIDEBAR -->
    <?php include("includes/admin_sidebar.php"); ?>

    <!-- MAIN CONTENT -->
    <div class="main-content">

        <!-- Top Header -->
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <span class="badge bg-primary bg-opacity-10 text-primary fw-bold px-3 py-1.5 rounded-pill mb-2" style="font-size: 11px;">
                    <i class="fa fa-chart-pie me-1"></i> EXECUTIVE AUDIT & REPORTING HUB
                </span>
                <h2 class="fw-bold text-dark m-0">Reports & Analytics Center</h2>
                <p class="text-muted small mb-0">System-wide clinical performance, financial yield, facility boarding, customer sentiment, and operational metrics.</p>
            </div>
            <div class="d-flex gap-2 align-items-center no-print">
                <button onclick="window.print()" class="btn btn-outline-secondary rounded-pill px-3 py-2 fw-bold shadow-sm">
                    <i class="fa fa-print me-1.5 text-primary"></i> Print Report Sheet
                </button>
                <a href="admin_reports.php?tab=<?= $active_tab ?>&period=<?= $period ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&export_report=<?= $active_tab ?>" class="btn btn-success rounded-pill px-4 py-2 fw-bold shadow-sm">
                    <i class="fa fa-file-excel me-1.5"></i> Export CSV
                </a>
            </div>
        </div>

        <!-- Filter Strip -->
        <div class="custom-card p-3 mb-4 filter-strip">
            <div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-center gap-3">
                <!-- Preset Buttons -->
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="small fw-bold text-muted me-1"><i class="fa fa-calendar-alt text-primary me-1"></i> Period:</span>
                    <a href="admin_reports.php?tab=<?= $active_tab ?>&period=all" class="btn btn-sm <?= $period === 'all' ? 'btn-primary' : 'btn-light border' ?> rounded-pill px-3 fw-bold">All Time</a>
                    <a href="admin_reports.php?tab=<?= $active_tab ?>&period=today" class="btn btn-sm <?= $period === 'today' ? 'btn-primary' : 'btn-light border' ?> rounded-pill px-3 fw-bold">Today</a>
                    <a href="admin_reports.php?tab=<?= $active_tab ?>&period=week" class="btn btn-sm <?= $period === 'week' ? 'btn-primary' : 'btn-light border' ?> rounded-pill px-3 fw-bold">This Week</a>
                    <a href="admin_reports.php?tab=<?= $active_tab ?>&period=month" class="btn btn-sm <?= $period === 'month' ? 'btn-primary' : 'btn-light border' ?> rounded-pill px-3 fw-bold">This Month</a>
                    <a href="admin_reports.php?tab=<?= $active_tab ?>&period=year" class="btn btn-sm <?= $period === 'year' ? 'btn-primary' : 'btn-light border' ?> rounded-pill px-3 fw-bold">This Year</a>
                </div>

                <!-- Custom Range Form -->
                <form method="GET" class="d-flex align-items-center gap-2 flex-wrap">
                    <input type="hidden" name="tab" value="<?= htmlspecialchars($active_tab) ?>">
                    <input type="hidden" name="period" value="custom">
                    <div class="input-group input-group-sm" style="width: auto;">
                        <span class="input-group-text bg-light"><i class="fa fa-calendar-day text-muted"></i></span>
                        <input type="date" name="start_date" class="form-control form-control-sm" value="<?= htmlspecialchars($start_date) ?>" required>
                    </div>
                    <span class="text-muted small">to</span>
                    <div class="input-group input-group-sm" style="width: auto;">
                        <span class="input-group-text bg-light"><i class="fa fa-calendar-check text-muted"></i></span>
                        <input type="date" name="end_date" class="form-control form-control-sm" value="<?= htmlspecialchars($end_date) ?>" required>
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary rounded-pill px-3 fw-bold">Apply</button>
                </form>
            </div>
            <div class="small text-muted mt-2 border-top pt-2">
                Active Filter Range: <strong><?= htmlspecialchars($filter_label) ?></strong> (<?= date('M j, Y', strtotime($start_date)) ?> - <?= date('M j, Y', strtotime($end_date)) ?>)
            </div>
        </div>

        <!-- Report Tabs (The 5 Core Required Reports) -->
        <div class="d-flex gap-2 mb-4 flex-wrap nav-tabs-wrapper">
            <a href="admin_reports.php?tab=appointments&period=<?= $period ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>" class="report-nav-tab <?= $active_tab === 'appointments' ? 'active' : '' ?>">
                <i class="fa fa-calendar-check"></i> Appointment Reports
            </a>
            <a href="admin_reports.php?tab=boarding&period=<?= $period ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>" class="report-nav-tab <?= $active_tab === 'boarding' ? 'active' : '' ?>">
                <i class="fa fa-hotel"></i> Boarding Reports
            </a>
            <a href="admin_reports.php?tab=payments&period=<?= $period ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>" class="report-nav-tab <?= $active_tab === 'payments' ? 'active' : '' ?>">
                <i class="fa fa-file-invoice-dollar"></i> Payment & Revenue Reports
            </a>
            <a href="admin_reports.php?tab=feedback&period=<?= $period ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>" class="report-nav-tab <?= $active_tab === 'feedback' ? 'active' : '' ?>">
                <i class="fa fa-star"></i> Feedback & CSAT Reports
            </a>
            <a href="admin_reports.php?tab=system&period=<?= $period ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>" class="report-nav-tab <?= $active_tab === 'system' ? 'active' : '' ?>">
                <i class="fa fa-server"></i> System Health & Audit Reports
            </a>
        </div>

        <!-- ================= TAB 1: APPOINTMENT REPORTS ================= -->
        <?php if ($active_tab === 'appointments'): ?>
            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted small fw-bold text-uppercase">Total Bookings</span>
                            <div class="stat-icon bg-primary bg-opacity-10 text-primary"><i class="fa fa-calendar"></i></div>
                        </div>
                        <h3 class="fw-bold m-0 text-dark"><?= number_format($appt_totals['total_appts']) ?></h3>
                        <div class="small text-muted mt-1">Evaluated across selected period</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted small fw-bold text-uppercase">Completed Consults</span>
                            <div class="stat-icon bg-success bg-opacity-10 text-success"><i class="fa fa-check-double"></i></div>
                        </div>
                        <h3 class="fw-bold m-0 text-success"><?= number_format($appt_totals['completed_cnt']) ?></h3>
                        <div class="small text-muted mt-1">Successfully concluded treatments</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted small fw-bold text-uppercase">Active / Approved</span>
                            <div class="stat-icon bg-info bg-opacity-10 text-info"><i class="fa fa-spinner"></i></div>
                        </div>
                        <h3 class="fw-bold m-0 text-info"><?= number_format($appt_totals['approved_cnt']) ?></h3>
                        <div class="small text-muted mt-1">Confirmed scheduled appointments awaiting arrival</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted small fw-bold text-uppercase">Cancellations & No-Shows</span>
                            <div class="stat-icon bg-danger bg-opacity-10 text-danger"><i class="fa fa-user-slash"></i></div>
                        </div>
                        <h3 class="fw-bold m-0 text-danger"><?= number_format($appt_totals['cancelled_cnt'] + $appt_totals['noshow_cnt']) ?></h3>
                        <div class="small text-muted mt-1"><?= (int)$appt_totals['noshow_cnt'] ?> no-shows, <?= (int)$appt_totals['cancelled_cnt'] ?> cancelled</div>
                    </div>
                </div>
            </div>

            <div class="row g-4">
                <!-- Doctor Caseload Table -->
                <div class="col-xl-7">
                    <div class="custom-card">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="fw-bold text-dark m-0"><i class="fa fa-user-md text-primary me-2"></i>Doctor Caseload & Clinical Audit</h5>
                            <span class="badge bg-light text-dark border">By Assigned Vet</span>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light small text-uppercase">
                                    <tr>
                                        <th>Veterinarian</th>
                                        <th class="text-center">Total Assigned</th>
                                        <th class="text-center">Completed</th>
                                        <th class="text-center">In Progress</th>
                                        <th class="text-center">Completion Rate</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($doc_caseload && $doc_caseload->num_rows > 0): ?>
                                        <?php while ($d = $doc_caseload->fetch_assoc()): ?>
                                            <?php 
                                                $rate = $d['total_assigned'] > 0 ? round(($d['completed'] / $d['total_assigned']) * 100, 1) : 0;
                                            ?>
                                            <tr>
                                                <td class="fw-bold text-dark">
                                                    <i class="fa fa-user-circle text-muted me-1"></i><?= htmlspecialchars($d['fullname']) ?>
                                                </td>
                                                <td class="text-center fw-bold"><?= $d['total_assigned'] ?></td>
                                                <td class="text-center text-success fw-bold"><?= $d['completed'] ?></td>
                                                <td class="text-center text-primary fw-semibold"><?= $d['active'] ?></td>
                                                <td class="text-center">
                                                    <div class="d-flex align-items-center justify-content-center gap-2">
                                                        <div class="progress flex-grow-1" style="height: 6px; max-width: 80px;">
                                                            <div class="progress-bar bg-success" style="width: <?= $rate ?>%"></div>
                                                        </div>
                                                        <span class="small fw-bold"><?= $rate ?>%</span>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr><td colspan="5" class="text-center text-muted py-4">No doctor caseload data for this period.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Service Breakdown -->
                <div class="col-xl-5">
                    <div class="custom-card">
                        <h5 class="fw-bold text-dark mb-3"><i class="fa fa-stethoscope text-primary me-2"></i>Service Category Distribution</h5>
                        <ul class="list-group list-group-flush">
                            <?php if ($service_breakdown && $service_breakdown->num_rows > 0): ?>
                                <?php while ($sb = $service_breakdown->fetch_assoc()): ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-2.5">
                                        <div class="fw-semibold text-dark">
                                            <i class="fa fa-angle-right text-primary me-2"></i><?= htmlspecialchars($sb['service_type']) ?>
                                        </div>
                                        <span class="badge bg-primary bg-opacity-10 text-primary rounded-pill px-3 py-1 fw-bold"><?= $sb['count'] ?> consults</span>
                                    </li>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <li class="list-group-item text-muted text-center py-4">No service data recorded for this period.</li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </div>

        <!-- ================= TAB 2: BOARDING REPORTS ================= -->
        <?php elseif ($active_tab === 'boarding'): ?>
            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted small fw-bold text-uppercase">Total Stays</span>
                            <div class="stat-icon bg-primary bg-opacity-10 text-primary"><i class="fa fa-hotel"></i></div>
                        </div>
                        <h3 class="fw-bold m-0 text-dark"><?= number_format($boarding_totals['total_reservations']) ?></h3>
                        <div class="small text-muted mt-1">Pet hotel bookings</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted small fw-bold text-uppercase">Active Boarded</span>
                            <div class="stat-icon bg-success bg-opacity-10 text-success"><i class="fa fa-paw"></i></div>
                        </div>
                        <h3 class="fw-bold m-0 text-success"><?= number_format($boarding_totals['active_stays']) ?></h3>
                        <div class="small text-muted mt-1">Currently occupying cages</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted small fw-bold text-uppercase">Avg Stay Length</span>
                            <div class="stat-icon bg-info bg-opacity-10 text-info"><i class="fa fa-clock"></i></div>
                        </div>
                        <h3 class="fw-bold m-0 text-info"><?= round($boarding_totals['avg_stay_days'] ?? 0, 1) ?> <span class="fs-6 fw-normal text-muted">days</span></h3>
                        <div class="small text-muted mt-1">Average reservation duration</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted small fw-bold text-uppercase">Boarding Yield</span>
                            <div class="stat-icon bg-warning bg-opacity-10 text-warning"><i class="fa fa-coins"></i></div>
                        </div>
                        <h3 class="fw-bold m-0 text-dark">₱<?= number_format($boarding_rev['total_rev'], 2) ?></h3>
                        <div class="small text-muted mt-1">Collected: ₱<?= number_format($boarding_rev['paid_rev'], 2) ?></div>
                    </div>
                </div>
            </div>

            <!-- Boarding Reservations Log -->
            <div class="custom-card">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold text-dark m-0"><i class="fa fa-history text-primary me-2"></i>Boarding Reservation Audited Log</h5>
                    <a href="admin_boarding.php" class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-bold">Open Boarding Operations</a>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light small text-uppercase">
                            <tr>
                                <th>Res ID</th>
                                <th>Client Name</th>
                                <th>Pet Name</th>
                                <th>Assigned Cage</th>
                                <th>Check In</th>
                                <th>Check Out</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $b_log = $conn->query("
                                SELECT br.*, u.fullname as client_name
                                FROM boarding_reservations br
                                LEFT JOIN users u ON br.user_id = u.id
                                WHERE br.appointment_date BETWEEN '$start_date' AND '$end_date'
                                ORDER BY br.appointment_date DESC LIMIT 15
                            ");
                            if ($b_log && $b_log->num_rows > 0):
                                while ($br = $b_log->fetch_assoc()):
                            ?>
                                <tr>
                                    <td><span class="badge bg-light text-dark border font-monospace">#<?= $br['id'] ?></span></td>
                                    <td class="fw-bold"><?= htmlspecialchars($br['client_name'] ?? 'Client') ?></td>
                                    <td><i class="fa fa-paw text-primary me-1"></i><?= htmlspecialchars($br['pet_name']) ?></td>
                                    <td><span class="badge bg-info bg-opacity-10 text-info border">Cage #<?= htmlspecialchars($br['cage_number'] ?? 'N/A') ?></span></td>
                                    <td><?= date('M d, Y', strtotime($br['appointment_date'])) ?></td>
                                    <td><?= date('M d, Y', strtotime($br['end_date'])) ?></td>
                                    <td>
                                        <span class="badge bg-<?= $br['status'] === 'Approved' || $br['status'] === 'Checked In' ? 'success' : 'secondary' ?> bg-opacity-10 text-<?= $br['status'] === 'Approved' || $br['status'] === 'Checked In' ? 'success' : 'secondary' ?> border">
                                            <?= htmlspecialchars($br['status']) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endwhile; else: ?>
                                <tr><td colspan="7" class="text-center text-muted py-4">No boarding stays recorded for this period.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <!-- ================= TAB 3: PAYMENT & BILLING REPORTS ================= -->
        <?php elseif ($active_tab === 'payments'): ?>
            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted small fw-bold text-uppercase">Gross Billed</span>
                            <div class="stat-icon bg-primary bg-opacity-10 text-primary"><i class="fa fa-receipt"></i></div>
                        </div>
                        <h3 class="fw-bold m-0 text-dark">₱<?= number_format($billing_totals['gross_billed'], 2) ?></h3>
                        <div class="small text-muted mt-1"><?= number_format($billing_totals['total_invoices']) ?> invoices generated</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted small fw-bold text-uppercase">Net Collections</span>
                            <div class="stat-icon bg-success bg-opacity-10 text-success"><i class="fa fa-money-bill-wave"></i></div>
                        </div>
                        <h3 class="fw-bold m-0 text-success">₱<?= number_format($billing_totals['net_collected'], 2) ?></h3>
                        <div class="small text-muted mt-1">Confirmed payments received</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted small fw-bold text-uppercase">Outstanding Receivables</span>
                            <div class="stat-icon bg-danger bg-opacity-10 text-danger"><i class="fa fa-exclamation-triangle"></i></div>
                        </div>
                        <h3 class="fw-bold m-0 text-danger">₱<?= number_format($billing_totals['outstanding_balance'], 2) ?></h3>
                        <div class="small text-muted mt-1"><?= (int)$billing_totals['unpaid_invoices'] ?> pending invoices</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted small fw-bold text-uppercase">Settlement Rate</span>
                            <div class="stat-icon bg-info bg-opacity-10 text-info"><i class="fa fa-percentage"></i></div>
                        </div>
                        <?php 
                            $settle_rate = $billing_totals['gross_billed'] > 0 ? round(($billing_totals['net_collected'] / $billing_totals['gross_billed']) * 100, 1) : 100;
                        ?>
                        <h3 class="fw-bold m-0 text-info"><?= $settle_rate ?>%</h3>
                        <div class="small text-muted mt-1">Cashflow collection efficiency</div>
                    </div>
                </div>
            </div>

            <!-- Payment Methods Breakdown Table -->
            <div class="row g-4">
                <div class="col-xl-6">
                    <div class="custom-card">
                        <h5 class="fw-bold text-dark mb-3"><i class="fa fa-wallet text-primary me-2"></i>Revenue by Payment Method</h5>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light small text-uppercase">
                                    <tr>
                                        <th>Payment Gateway / Channel</th>
                                        <th class="text-center">Transactions</th>
                                        <th class="text-end">Total Collected</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($methods_breakdown && $methods_breakdown->num_rows > 0): ?>
                                        <?php while ($mb = $methods_breakdown->fetch_assoc()): ?>
                                            <tr>
                                                <td class="fw-bold">
                                                    <i class="fa fa-check-circle text-success me-1.5"></i><?= htmlspecialchars($mb['payment_method']) ?>
                                                </td>
                                                <td class="text-center"><?= $mb['count'] ?></td>
                                                <td class="text-end fw-bold text-dark">₱<?= number_format($mb['total'], 2) ?></td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr><td colspan="3" class="text-center text-muted py-4">No payments recorded for this period.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="col-xl-6">
                    <div class="custom-card">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="fw-bold text-dark m-0"><i class="fa fa-bolt text-warning me-2"></i>Quick Billing Operations</h5>
                            <a href="admin_billing.php" class="btn btn-sm btn-primary rounded-pill px-3 fw-bold">Open Full POS / Billing</a>
                        </div>
                        <p class="text-muted small">Access the complete transaction ledger, print official clinic receipts, apply loyalty/senior discounts, and register walk-in checkouts in the primary billing hub.</p>
                        <div class="d-flex gap-2">
                            <a href="admin_billing.php" class="btn btn-outline-primary rounded-pill px-3 py-2 fw-bold text-decoration-none">
                                <i class="fa fa-plus-circle me-1"></i> Issue New Invoice
                            </a>
                            <a href="admin_billing.php?filter=unpaid" class="btn btn-outline-danger rounded-pill px-3 py-2 fw-bold text-decoration-none">
                                <i class="fa fa-filter me-1"></i> Review Unpaid Invoices
                            </a>
                        </div>
                    </div>
                </div>
            </div>

        <!-- ================= TAB 4: FEEDBACK & CSAT REPORTS ================= -->
        <?php elseif ($active_tab === 'feedback'): ?>
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="stat-card text-center d-flex flex-column align-items-center justify-content-center py-4">
                        <span class="text-muted small fw-bold text-uppercase mb-1">Customer Satisfaction Score</span>
                        <h1 class="fw-bold display-4 text-warning mb-1">
                            <?= $feedback_stats['avg_rating'] ?> <span class="fs-4 text-muted">/ 5.0</span>
                        </h1>
                        <div class="text-warning fs-5 mb-2">
                            <?php 
                                $r = round($feedback_stats['avg_rating']);
                                for($i=1; $i<=5; $i++) {
                                    echo $i <= $r ? '<i class="fa fa-star"></i>' : '<i class="far fa-star"></i>';
                                }
                            ?>
                        </div>
                        <div class="small text-muted">Based on <?= number_format($feedback_stats['total_reviews']) ?> verified client ratings</div>
                    </div>
                </div>

                <div class="col-md-8">
                    <div class="custom-card">
                        <h5 class="fw-bold text-dark mb-3"><i class="fa fa-chart-bar text-primary me-2"></i>Rating Distribution</h5>
                        <?php 
                            $tot = max(1, $feedback_stats['total_reviews']);
                            $stars = [
                                5 => $feedback_stats['star5'],
                                4 => $feedback_stats['star4'],
                                3 => $feedback_stats['star3'],
                                2 => $feedback_stats['star2'],
                                1 => $feedback_stats['star1'],
                            ];
                            foreach($stars as $s => $cnt):
                                $pct = round(($cnt / $tot) * 100, 1);
                        ?>
                            <div class="d-flex align-items-center gap-3 mb-2">
                                <span class="fw-bold text-dark small" style="width: 50px;"><?= $s ?> Star</span>
                                <div class="progress flex-grow-1" style="height: 8px;">
                                    <div class="progress-bar bg-warning" style="width: <?= $pct ?>%"></div>
                                </div>
                                <span class="small text-muted fw-bold" style="width: 70px; text-align: right;"><?= $cnt ?> (<?= $pct ?>%)</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Recent Client Feedbacks Table -->
            <div class="custom-card">
                <h5 class="fw-bold text-dark mb-3"><i class="fa fa-comments text-primary me-2"></i>Client Reviews & Patient Feedback Feed</h5>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light small text-uppercase">
                            <tr>
                                <th>Client Name</th>
                                <th>Patient / Pet</th>
                                <th>Service</th>
                                <th>Rating</th>
                                <th>Comments / Feedback</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($recent_feedbacks && $recent_feedbacks->num_rows > 0): ?>
                                <?php while ($fb = $recent_feedbacks->fetch_assoc()): ?>
                                    <tr>
                                        <td class="fw-bold text-dark"><?= htmlspecialchars($fb['client_name']) ?></td>
                                        <td><i class="fa fa-paw text-primary me-1"></i><?= htmlspecialchars($fb['pet_name'] ?? 'General') ?></td>
                                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($fb['service_type'] ?? 'Consultation') ?></span></td>
                                        <td>
                                            <span class="text-warning">
                                                <?php for($i=1; $i<=$fb['rating']; $i++): ?><i class="fa fa-star"></i><?php endfor; ?>
                                            </span>
                                            <span class="small fw-bold ms-1 text-muted">(<?= $fb['rating'] ?>)</span>
                                        </td>
                                        <td>
                                            <div class="small text-dark" style="max-width: 400px;"><?= htmlspecialchars($fb['comments'] ?: 'â€” No comment text provided â€”') ?></div>
                                        </td>
                                        <td class="small text-muted"><?= date('M d, Y', strtotime($fb['created_at'])) ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="6" class="text-center text-muted py-4">No client feedback submissions yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <!-- ================= TAB 5: SYSTEM HEALTH & AUDIT REPORTS ================= -->
        <?php elseif ($active_tab === 'system'): ?>
            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted small fw-bold text-uppercase">Total Users</span>
                            <div class="stat-icon bg-primary bg-opacity-10 text-primary"><i class="fa fa-users"></i></div>
                        </div>
                        <h3 class="fw-bold m-0 text-dark"><?= number_format($user_counts['total_users']) ?></h3>
                        <div class="small text-muted mt-1"><?= $user_counts['clients'] ?> clients, <?= $user_counts['staff'] ?> staff, <?= $user_counts['doctors'] ?> vets</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted small fw-bold text-uppercase">Account Verification</span>
                            <div class="stat-icon bg-success bg-opacity-10 text-success"><i class="fa fa-user-check"></i></div>
                        </div>
                        <?php 
                            $v_rate = $user_counts['total_users'] > 0 ? round(($user_counts['verified_users'] / $user_counts['total_users']) * 100, 1) : 100;
                        ?>
                        <h3 class="fw-bold m-0 text-success"><?= $v_rate ?>%</h3>
                        <div class="small text-muted mt-1"><?= $user_counts['verified_users'] ?> accounts verified</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted small fw-bold text-uppercase">Security Lockouts</span>
                            <div class="stat-icon bg-danger bg-opacity-10 text-danger"><i class="fa fa-user-lock"></i></div>
                        </div>
                        <h3 class="fw-bold m-0 text-danger"><?= (int)$user_counts['locked_users'] ?></h3>
                        <div class="small text-muted mt-1">Accounts locked due to failed auth</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted small fw-bold text-uppercase">Database Footprint</span>
                            <div class="stat-icon bg-info bg-opacity-10 text-info"><i class="fa fa-database"></i></div>
                        </div>
                        <h3 class="fw-bold m-0 text-dark"><?= round($total_db_size_kb, 1) ?> <span class="fs-6 fw-normal text-muted">KB</span></h3>
                        <div class="small text-muted mt-1"><?= count($tables_info) ?> database tables active</div>
                    </div>
                </div>
            </div>

            <!-- Database & Server Diagnostics -->
            <div class="row g-4">
                <div class="col-xl-7">
                    <div class="custom-card">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="fw-bold text-dark m-0"><i class="fa fa-table text-primary me-2"></i>Database Storage & Record Status</h5>
                            <span class="badge bg-light text-dark border">MySQL InnoDB</span>
                        </div>
                        <div class="table-responsive" style="max-height: 380px; overflow-y: auto;">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light small text-uppercase">
                                    <tr>
                                        <th>Table Name</th>
                                        <th>Engine</th>
                                        <th class="text-center">Row Count</th>
                                        <th class="text-end">Storage Size</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($tables_info as $ti): ?>
                                        <tr>
                                            <td class="font-monospace fw-bold text-primary"><?= htmlspecialchars($ti['name']) ?></td>
                                            <td><span class="badge bg-light text-muted border"><?= htmlspecialchars($ti['engine']) ?></span></td>
                                            <td class="text-center fw-semibold"><?= number_format($ti['rows']) ?></td>
                                            <td class="text-end text-muted small"><?= $ti['size_kb'] ?> KB</td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="col-xl-5">
                    <div class="custom-card">
                        <h5 class="fw-bold text-dark mb-3"><i class="fa fa-microchip text-primary me-2"></i>Server & Software Environment</h5>
                        <ul class="list-group list-group-flush">
                            <li class="list-group-item d-flex justify-content-between px-0 py-2.5">
                                <span class="text-muted fw-semibold">Application Release</span>
                                <span class="fw-bold text-dark">PragmaVet Enterprise v2.4.0</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between px-0 py-2.5">
                                <span class="text-muted fw-semibold">PHP Version</span>
                                <span class="font-monospace fw-bold text-dark"><?= phpversion() ?></span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between px-0 py-2.5">
                                <span class="text-muted fw-semibold">MySQL Client Version</span>
                                <span class="font-monospace fw-bold text-dark"><?= $conn->server_info ?></span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between px-0 py-2.5">
                                <span class="text-muted fw-semibold">Operating System</span>
                                <span class="fw-bold text-dark"><?= PHP_OS ?></span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between px-0 py-2.5">
                                <span class="text-muted fw-semibold">Database Host</span>
                                <span class="font-monospace fw-bold text-dark">localhost (pragmavet)</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between px-0 py-2.5">
                                <span class="text-muted fw-semibold">System Server Time</span>
                                <span class="fw-bold text-dark"><?= date('Y-m-d H:i:s') ?></span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        <?php endif; ?>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="mousetrail.js"></script>
</body>
</html>