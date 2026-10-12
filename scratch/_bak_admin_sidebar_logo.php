<?php include_once __DIR__ . '/pv_responsive.php'; ?>
<?php
// includes/admin_sidebar.php
// Centralized, unified navigation for PragmaVet Administration & Staff Modules

$current_script = basename($_SERVER['PHP_SELF']);
$active = $active_nav ?? '';
if (empty($active)) {
    if ($current_script === 'admin.php') $active = 'dashboard';
    elseif ($current_script === 'admin_users.php') $active = 'users';
    elseif ($current_script === 'admin_list.php') $active = 'appointments';
    elseif ($current_script === 'admin_boarding.php') $active = 'boarding';
    elseif ($current_script === 'admin_inventory.php') $active = 'inventory';
    elseif ($current_script === 'admin_certificates.php') $active = 'certificates';
    elseif ($current_script === 'admin_messages.php') $active = 'messages';
    elseif ($current_script === 'admin_emails.php') $active = 'emails';
    elseif ($current_script === 'admin_announcements.php') $active = 'announcements';
    elseif ($current_script === 'admin_monitoring.php') $active = 'monitoring';
    elseif ($current_script === 'admin_reports.php') $active = 'reports';
    elseif ($current_script === 'admin_billing.php') $active = 'billing';
    elseif ($current_script === 'admin_history.php') $active = 'history';
    elseif ($current_script === 'admin_settings.php') $active = 'settings';
}

$user_role = $_SESSION['role'] ?? ($_SESSION['user_role'] ?? '');
$is_owner_user = in_array($user_role, ['clinic_owner', 'owner']);
$is_staff_user = ($user_role === 'staff');
$is_vet_user   = ($user_role === 'doctor');
$admin_display_name = htmlspecialchars($_SESSION['fullname'] ?? $_SESSION['username'] ?? $_SESSION['user'] ?? 'Administrator');
?>
<div class="sidebar" id="mainSidebar">
    <!-- Brand Header -->
    <a href="<?= $is_owner_user ? 'owner_dashboard.php' : ($is_staff_user ? 'staff_dashboard.php' : ($is_vet_user ? 'doctor.php' : 'admin.php')) ?>" class="sidebar-brand text-decoration-none">
        <div class="sidebar-brand-icon">
            <i class="fa fa-paw"></i>
        </div>
        <span>PragmaVet</span>
    </a>

    <!-- User Profile Strip -->
    <div class="sidebar-user">
        <div class="welcome">
            <?= $is_owner_user ? 'Executive Portal' : ($is_staff_user ? 'Staff Workspace' : ($is_vet_user ? 'Welcome Doctor,' : 'System Administration')) ?>
        </div>
        <div class="uname text-truncate" title="<?= $admin_display_name ?>">
            <?= $admin_display_name ?>
        </div>
        <div class="small mt-1 d-flex align-items-center gap-1">
            <?php if ($is_owner_user): ?>
                <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 rounded-pill px-2 py-0.5" style="font-size:11px;">
                    <i class="fa fa-shield-alt me-1"></i> Clinic Owner
                </span>
            <?php elseif ($is_vet_user): ?>
                <span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25 rounded-pill px-2 py-0.5" style="font-size:11px;"><i class="fa fa-user-md me-1"></i> Veterinarian</span>
            <?php elseif ($is_staff_user): ?>
                <span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25 rounded-pill px-2 py-0.5" style="font-size:11px;">
                    <i class="fa fa-id-badge me-1"></i> Vet Staff
                </span>
            <?php else: ?>
                <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 rounded-pill px-2 py-0.5" style="font-size:11px;">
                    <i class="fa fa-user-shield me-1"></i> System Admin
                </span>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($is_owner_user): ?>
        <!-- OWNER ROLE NAVIGATION -->
        <a href="owner_dashboard.php" class="<?= $active === 'owner_dashboard' ? 'active' : '' ?>">
            <i class="fa fa-chart-pie"></i> <span>Owner Dashboard</span>
        </a>
        <a href="admin.php" class="<?= $active === 'dashboard' ? 'active' : '' ?>">
            <i class="fa fa-chart-line"></i> <span>Reports & Analytics</span>
        </a>
        <a href="admin_reports.php" class="<?= $active === 'reports' ? 'active' : '' ?>">
            <i class="fa fa-file-invoice-dollar"></i> <span>Executive Reports</span>
        </a>
        <a href="admin_billing.php" class="<?= $active === 'billing' ? 'active' : '' ?>">
            <i class="fa fa-receipt"></i> <span>Billing & Revenue</span>
        </a>
        <a href="admin_inventory.php" class="<?= $active === 'inventory' ? 'active' : '' ?>">
            <i class="fa fa-boxes"></i> <span>Inventory Reports</span>
        </a>
        <a href="admin_users.php" class="<?= $active === 'users' ? 'active' : '' ?>">
            <i class="fa fa-users-cog"></i> <span>Staff & Access Control</span>
        </a>
        <a href="admin_settings.php" class="<?= $active === 'settings' ? 'active' : '' ?>">
            <i class="fa fa-cogs"></i> <span>System Control</span>
        </a>
        <a href="admin_history.php" class="<?= $active === 'history' ? 'active' : '' ?>">
            <i class="fa fa-history"></i> <span>Audit History</span>
        </a>
        <a href="admin_monitoring.php" class="<?= $active === 'monitoring' ? 'active' : '' ?>">
            <i class="fa fa-video"></i> <span>Live Feed</span>
        </a>

    <?php elseif ($is_vet_user): ?>
        <!-- VETERINARIAN NAVIGATION (same links as the vet pages) -->
        <a href="doctor.php"><i class="fa fa-calendar-check"></i> <span>Schedule & Availability</span></a>
        <a href="doctor_consultations.php"><i class="fa fa-stethoscope"></i> <span>Active Consultations</span></a>
        <a href="doctor_patients.php"><i class="fa fa-paw"></i> <span>Patient EMR Lookup</span></a>
        <a href="doctor_inventory.php"><i class="fa fa-boxes"></i> <span>Pharmacy Stock</span></a>
        <a href="admin_certificates.php" class="<?= $active === 'certificates' ? 'active' : '' ?>"><i class="fa fa-file-contract"></i> <span>Cert Requests</span></a>

    <?php elseif ($is_staff_user): ?>
        <!-- VET STAFF ROLE NAVIGATION -->
        <a href="staff_dashboard.php" class="<?= $active === 'staff_dashboard' ? 'active' : '' ?>">
            <i class="fa fa-home"></i> <span>Staff Dashboard</span>
        </a>
        <a href="admin_list.php" class="<?= $active === 'appointments' ? 'active' : '' ?>">
            <i class="fa fa-calendar-check"></i> <span>Appointments</span>
        </a>
        <a href="admin_boarding.php" class="<?= $active === 'boarding' ? 'active' : '' ?>">
            <i class="fa fa-hotel"></i> <span>Boarding Ops</span>
        </a>
        <a href="admin_inventory.php" class="<?= $active === 'inventory' ? 'active' : '' ?>">
            <i class="fa fa-boxes"></i> <span>Inventory Support</span>
        </a>
        <a href="admin_certificates.php" class="<?= $active === 'certificates' ? 'active' : '' ?>">
            <i class="fa fa-file-contract"></i> <span>Cert Requests</span>
        </a>
        <a href="admin_messages.php" class="<?= $active === 'messages' ? 'active' : '' ?>">
            <i class="fa fa-comments"></i> <span>Client Chat</span>
        </a>

    <?php else: ?>
        <!-- ADMIN MODULE STRUCTURE (7 CORE PILLARS) -->
        
        <!-- 1. Dashboard -->
        <a href="admin.php" class="<?= $active === 'dashboard' ? 'active' : '' ?>">
            <i class="fa fa-chart-line"></i> <span>Dashboard</span>
        </a>

        <!-- 2. User & Client Management -->
        <a href="admin_users.php" class="<?= $active === 'users' ? 'active' : '' ?>">
            <i class="fa fa-users-cog"></i> <span>User & Client Mgmt</span>
        </a>

        <!-- 3. Appointment Monitoring -->
        <a href="admin_list.php" class="<?= $active === 'appointments' ? 'active' : '' ?>">
            <i class="fa fa-calendar-check"></i> <span>Appointment Monitoring</span>
        </a>

        <!-- 4. Boarding Monitoring -->
        <a href="admin_boarding.php" class="<?= $active === 'boarding' ? 'active' : '' ?>">
            <i class="fa fa-hotel"></i> <span>Boarding Monitoring</span>
        </a>

        <!-- 5. Clinic Operations Section -->
        <div class="sidebar-heading">Clinic Operations</div>
        <a href="admin_inventory.php" class="<?= $active === 'inventory' ? 'active' : '' ?>">
            <i class="fa fa-boxes"></i> <span>Inventory & Stock</span>
        </a>
        <a href="admin_certificates.php" class="<?= $active === 'certificates' ? 'active' : '' ?>">
            <i class="fa fa-file-contract"></i> <span>Cert Requests</span>
        </a>
        <a href="admin_messages.php" class="<?= $active === 'messages' ? 'active' : '' ?>">
            <i class="fa fa-comments"></i> <span>Client Messaging</span>
        </a>
        <a href="admin_announcements.php" class="<?= $active === 'announcements' ? 'active' : '' ?>">
            <i class="fa fa-bullhorn"></i> <span>Announcements</span>
        </a>

        <!-- 6. Monitoring Section -->
        <div class="sidebar-heading">Monitoring</div>
        <a href="admin_monitoring.php" class="<?= $active === 'monitoring' ? 'active' : '' ?>">
            <i class="fa fa-video"></i> <span>Live Feed</span>
        </a>
        <a href="admin_history.php" class="<?= $active === 'history' ? 'active' : '' ?>">
            <i class="fa fa-history"></i> <span>Activity Logs / Audit</span>
        </a>

        <!-- 7. Reports & System Control -->
        <div class="sidebar-heading">Reports & Control</div>
        <a href="admin_reports.php" class="<?= $active === 'reports' ? 'active' : '' ?>">
            <i class="fa fa-chart-pie"></i> <span>Reports Center</span>
        </a>
        <a href="admin_billing.php" class="<?= $active === 'billing' ? 'active' : '' ?>">
            <i class="fa fa-file-invoice-dollar"></i> <span>Billing & Payments</span>
        </a>
        <a href="admin_settings.php" class="<?= $active === 'settings' ? 'active' : '' ?>">
            <i class="fa fa-cogs"></i> <span>System Settings</span>
        </a>
    <?php endif; ?>

    <!-- Logout / Switch Action -->
    <div class="sidebar-logout">
        <a href="logout.php">
            <i class="fa fa-sign-out-alt"></i> <span>Sign Out</span>
        </a>
    </div>
</div>
