<?php
/**
 * PragmaVet RBAC Guard - Central Role-Based Access Control
 * =========================================================
 * Defines the full permission matrix for all roles.
 * Used by both frontend page guards and backend API validators.
 *
 * Roles:
 *   - clinic_owner / owner  → Owner
 *   - admin                 → Admin
 *   - staff                 → Veterinarian Staff
 *   - doctor                → Doctor
 *   - user / client         → Client
 *
 * NOTE: NO QUEUE FUNCTIONALITY EXISTS IN PRAGMAVET.
 * Appointments are handled by scheduled date and time.
 */

// ─── Role Normalization ─────────────────────────────────────────────────────

/**
 * Returns the canonical role string from session.
 */
function rbac_get_role(): string {
    $role = $_SESSION['role'] ?? ($_SESSION['user_role'] ?? '');
    if ($role === 'owner') $role = 'clinic_owner';
    if ($role === 'client') $role = 'user';
    return $role;
}

/**
 * Returns true if the current user is logged in.
 */
function rbac_logged_in(): bool {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

// ─── Role Permission Matrix ─────────────────────────────────────────────────

/**
 * Full permission matrix.
 * Format: 'module.action' => [allowed_roles]
 */
function rbac_permissions(): array {
    return [
        // ── OWNER ──────────────────────────────────────────────────────────
        'owner.dashboard'               => ['clinic_owner'],
        'owner.business_management'     => ['clinic_owner'],
        'owner.revenue_reports'         => ['clinic_owner'],
        'owner.billing_reports'         => ['clinic_owner'],
        'owner.inventory_reports'       => ['clinic_owner'],
        'owner.appointment_statistics'  => ['clinic_owner'],
        'owner.boarding_statistics'     => ['clinic_owner'],
        'owner.feedback_analysis'       => ['clinic_owner'],
        'owner.clinic_performance'      => ['clinic_owner'],
        'owner.user_management'         => ['clinic_owner'],
        'owner.role_management'         => ['clinic_owner'],
        'owner.staff_accounts'          => ['clinic_owner'],
        'owner.permission_management'   => ['clinic_owner'],
        'owner.system_settings'         => ['clinic_owner'],
        'owner.database_management'     => ['clinic_owner'],
        'owner.system_updates'          => ['clinic_owner'],
        'owner.audit_history'           => ['clinic_owner'],
        'owner.activity_monitoring'     => ['clinic_owner'],
        'owner.staff_performance_review'=> ['clinic_owner'],

        // ── ADMIN ──────────────────────────────────────────────────────────
        'admin.dashboard'               => ['admin', 'clinic_owner'],
        'admin.manage_clients'          => ['admin', 'clinic_owner'],
        'admin.manage_staff'            => ['admin', 'clinic_owner'],
        'admin.user_verification'       => ['admin', 'clinic_owner'],
        'admin.role_assignment'         => ['admin', 'clinic_owner'],
        'admin.view_appointments'       => ['admin', 'clinic_owner'],
        'admin.view_appointment_status' => ['admin', 'clinic_owner'],
        'admin.view_doctor_assignments' => ['admin', 'clinic_owner'],
        'admin.view_appointment_history'=> ['admin', 'clinic_owner'],
        'admin.view_boarding_requests'  => ['admin', 'clinic_owner'],
        'admin.view_boarding_status'    => ['admin', 'clinic_owner'],
        'admin.view_cage_assignments'   => ['admin', 'clinic_owner'],
        'admin.view_boarding_history'   => ['admin', 'clinic_owner'],
        'admin.inventory_management'    => ['admin', 'clinic_owner'],
        'admin.medicine_stock'          => ['admin', 'clinic_owner', 'staff'],
        'admin.equipment_tracking'      => ['admin', 'clinic_owner', 'staff'],
        'admin.certificate_requests'    => ['admin', 'clinic_owner', 'staff'],
        'admin.client_messaging'        => ['admin', 'clinic_owner', 'staff', 'doctor'],
        'admin.email_dispatch'          => ['admin', 'clinic_owner'],
        'admin.announcements'           => ['admin', 'clinic_owner'],
        'admin.cctv_monitoring'         => ['admin', 'clinic_owner'],
        'admin.boarding_monitoring'     => ['admin', 'clinic_owner', 'staff'],
        'admin.activity_logs'           => ['admin', 'clinic_owner'],
        'admin.appointment_reports'     => ['admin', 'clinic_owner'],
        'admin.boarding_reports'        => ['admin', 'clinic_owner'],
        'admin.payment_reports'         => ['admin', 'clinic_owner'],
        'admin.feedback_reports'        => ['admin', 'clinic_owner'],
        'admin.system_reports'          => ['admin', 'clinic_owner'],
        'admin.billing'                 => ['admin', 'clinic_owner'],
        'admin.settings'                => ['admin', 'clinic_owner'],

        // ── VETERINARIAN STAFF ─────────────────────────────────────────────
        // Appointment Management (schedule-based, NOT queue-based)
        'staff.view_appointments'       => ['staff', 'admin', 'clinic_owner'],
        'staff.accept_appointment'      => ['staff', 'admin'],
        'staff.reject_appointment'      => ['staff', 'admin'],
        'staff.reschedule_appointment'  => ['staff', 'admin'],
        'staff.assign_doctor'           => ['staff', 'admin'],
        'staff.manage_schedule_slots'   => ['staff', 'admin'],
        'staff.update_appointment_status'=> ['staff', 'admin'],
        // Boarding Management
        'staff.view_boarding_requests'  => ['staff', 'admin'],
        'staff.accept_boarding'         => ['staff', 'admin'],
        'staff.reject_boarding'         => ['staff', 'admin'],
        'staff.assign_cage'             => ['staff', 'admin'],
        'staff.update_boarding_status'  => ['staff', 'admin'],
        'staff.manage_boarding_schedule'=> ['staff', 'admin'],
        // Patient Support (schedule-based client verification)
        'staff.view_pet_profiles'       => ['staff', 'admin', 'doctor'],
        'staff.view_medical_history'    => ['staff', 'admin', 'doctor'],
        'staff.record_care_notes'       => ['staff'],
        'staff.monitor_pet_condition'   => ['staff', 'admin'],
        // Inventory Support
        'staff.check_medicine_availability' => ['staff', 'admin'],
        'staff.request_supplies'        => ['staff'],
        'staff.equipment_status'        => ['staff', 'admin'],
        // Client Assistance (verify and assist based on scheduled appointment)
        'staff.assist_client_communication' => ['staff', 'admin'],
        'staff.provide_pet_care_updates'    => ['staff', 'admin'],
        // Walk-in registration (schedule to today, not queue)
        'staff.register_walkin'         => ['staff', 'admin'],

        // ── DOCTOR ────────────────────────────────────────────────────────
        // Doctor handles ASSIGNED scheduled appointments — NO queue
        'doctor.dashboard'              => ['doctor'],
        'doctor.view_assigned_appointments' => ['doctor', 'admin', 'staff'],
        'doctor.view_schedule'          => ['doctor'],
        'doctor.patient_assessment'     => ['doctor'],
        'doctor.diagnosis'              => ['doctor'],
        'doctor.treatment_plans'        => ['doctor'],
        'doctor.prescriptions'          => ['doctor'],
        'doctor.consultation_history'   => ['doctor', 'admin'],
        'doctor.pet_medical_history'    => ['doctor', 'admin', 'staff'],
        'doctor.vaccination_records'    => ['doctor', 'admin', 'staff'],
        'doctor.lab_results'            => ['doctor', 'admin'],
        'doctor.previous_treatments'    => ['doctor', 'admin'],
        'doctor.health_monitoring'      => ['doctor'],
        'doctor.medical_certificates'   => ['doctor', 'admin'],
        'doctor.treatment_reports'      => ['doctor', 'admin'],
        'doctor.prescription_records'   => ['doctor', 'admin'],
        'doctor.manage_inventory'       => ['doctor'],
        'doctor.messages'               => ['doctor'],

        // ── CLIENT ────────────────────────────────────────────────────────
        'client.login'                  => ['user', 'client'],
        'client.registration'           => ['user', 'client'],
        'client.profile'                => ['user', 'client'],
        'client.notifications'          => ['user', 'client'],
        'client.add_pet'                => ['user', 'client'],
        'client.update_pet'             => ['user', 'client'],
        'client.view_pet_history'       => ['user', 'client'],
        'client.book_appointment'       => ['user', 'client'],
        'client.view_appointment_status'=> ['user', 'client'],
        'client.cancel_appointment'     => ['user', 'client'],
        'client.reschedule_appointment' => ['user', 'client'],
        'client.boarding_request'       => ['user', 'client'],
        'client.view_boarding_status'   => ['user', 'client'],
        'client.view_bills'             => ['user', 'client'],
        'client.online_payment'         => ['user', 'client'],
        'client.payment_history'        => ['user', 'client'],
        'client.live_chat'              => ['user', 'client'],
        'client.view_announcements'     => ['user', 'client'],
        'client.rate_services'          => ['user', 'client'],
        'client.submit_reviews'         => ['user', 'client'],

        // ── SYSTEM / BACKEND ──────────────────────────────────────────────
        // APIs are accessible by logged-in users; specific APIs enforce role internally
        'api.appointment_slots'         => ['user', 'client', 'staff', 'admin', 'clinic_owner', 'doctor'],
        'api.slot_conflict'             => ['user', 'client', 'staff', 'admin', 'clinic_owner', 'doctor'],
        'api.chat'                      => ['user', 'client', 'staff', 'admin', 'clinic_owner', 'doctor'],
        'api.notifications'             => ['user', 'client', 'staff', 'admin', 'clinic_owner', 'doctor'],
        'api.pet_emr'                   => ['user', 'client', 'staff', 'admin', 'clinic_owner', 'doctor'],
        'api.audit_log'                 => ['admin', 'clinic_owner'],
    ];
}

// ─── Permission Check Helpers ───────────────────────────────────────────────

/**
 * Returns true if the given role or the current user can perform the given permission.
 */
function rbac_can(string $permission, string $role = null, int $user_id = null): bool {
    global $conn;
    $role = $role ?? rbac_get_role();
    $user_id = $user_id ?? ($_SESSION['user_id'] ?? 0);

    // Static cache for role permissions
    static $db_permissions = null;
    // Static cache for user specific permissions
    static $db_user_permissions = null;

    if ($db_permissions === null) {
        $db_permissions = [];
        $db_user_permissions = [];
        
        if (isset($conn) && $conn instanceof mysqli) {
            // Load all role permissions
            $result = $conn->query("SELECT role_key, permission_key FROM rbac_role_permissions WHERE is_granted = 1");
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $db_permissions[$row['permission_key']][] = $row['role_key'];
                }
            }
            
            // Load current user's specific permissions if logged in
            if ($user_id > 0) {
                $stmt = $conn->prepare("SELECT permission_key FROM rbac_user_permissions WHERE user_id = ? AND is_granted = 1");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $u_result = $stmt->get_result();
                while ($row = $u_result->fetch_assoc()) {
                    $db_user_permissions[] = $row['permission_key'];
                }
            }
        }
    }

    // 1. Check user-specific permissions first
    if (in_array($permission, $db_user_permissions)) {
        return true;
    }

    // 2. Check role-based permissions from DB
    if (!empty($db_permissions)) {
        if (!isset($db_permissions[$permission])) return false;
        return in_array($role, $db_permissions[$permission]);
    }

    // 3. Fallback to hardcoded matrix if DB is empty
    $matrix = rbac_permissions();
    if (!isset($matrix[$permission])) return false;
    return in_array($role, $matrix[$permission]);
}

/**
 * Page-level guard: requires login + one of the allowed roles.
 * Redirects to the appropriate portal if unauthorized.
 *
 * @param array $allowed_roles  Roles that may access this page
 * @param string $fallback      Where to redirect if unauthorized
 */
function rbac_require_role(array $allowed_roles, string $fallback = 'index.php'): void {
    if (!rbac_logged_in()) {
        header("Location: index.php");
        exit();
    }

    $role = rbac_get_role();
    if (!in_array($role, $allowed_roles)) {
        // Smart redirect to proper home portal
        header("Location: " . rbac_home_url($role));
        exit();
    }
}

/**
 * API-level guard: requires login + one of the allowed roles.
 * Returns JSON 403 if unauthorized.
 */
function rbac_api_require_role(array $allowed_roles): void {
    if (!rbac_logged_in()) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized', 'code' => 401]);
        exit();
    }

    $role = rbac_get_role();
    if (!in_array($role, $allowed_roles)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden: insufficient permissions', 'code' => 403]);
        exit();
    }
}

/**
 * Returns the home/dashboard URL for a given role.
 */
function rbac_home_url(string $role): string {
    return match($role) {
        'clinic_owner', 'owner' => 'owner_dashboard.php',
        'admin'                 => 'admin.php',
        'staff'                 => 'staff_dashboard.php',
        'doctor'                => 'doctor.php',
        'user', 'client'        => 'dashboard.php',
        default                 => 'index.php',
    };
}

/**
 * Audit log helper — writes RBAC-related access events to monitoring table.
 */
function rbac_audit_log($conn, string $event, string $detail = '', int $user_id = 0): void {
    if ($user_id === 0) {
        $user_id = intval($_SESSION['user_id'] ?? 0);
    }
    if (!$user_id) return;

    $ip       = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $ua       = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $page     = basename($_SERVER['PHP_SELF'] ?? '');
    $detail_s = $conn->real_escape_string(substr("[$page] $event: $detail", 0, 1000));
    $event_s  = $conn->real_escape_string(substr($event, 0, 100));
    $ip_s     = $conn->real_escape_string($ip);

    // Try monitoring table first, fallback silently
    $conn->query("INSERT INTO monitoring (user_id, action, detail, ip_address, created_at)
                  VALUES ($user_id, '$event_s', '$detail_s', '$ip_s', NOW())
                  ") ;
}

/**
 * Returns allowed admin pages for the current role.
 * Used to hide/show sidebar items without removing them from DOM.
 */
function rbac_admin_nav_allowed(string $role): array {
    $all = [
        'dashboard'     => ['admin', 'clinic_owner'],
        'users'         => ['admin', 'clinic_owner'],
        'appointments'  => ['admin', 'clinic_owner'],
        'boarding'      => ['admin', 'clinic_owner'],
        'inventory'     => ['admin', 'clinic_owner'],
        'billing'       => ['admin', 'clinic_owner'],
        'certificates'  => ['admin', 'clinic_owner'],
        'messages'      => ['admin', 'clinic_owner'],
        'emails'        => ['admin', 'clinic_owner'],
        'announcements' => ['admin', 'clinic_owner'],
        'monitoring'    => ['admin', 'clinic_owner'],
        'history'       => ['admin', 'clinic_owner'],
        'reports'       => ['admin', 'clinic_owner'],
        'settings'      => ['admin', 'clinic_owner'],
    ];

    $allowed = [];
    foreach ($all as $nav => $roles) {
        if (in_array($role, $roles)) {
            $allowed[] = $nav;
        }
    }
    return $allowed;
}
