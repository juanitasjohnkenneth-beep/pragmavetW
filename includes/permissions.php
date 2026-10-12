<?php
/**
 * Role-based access control (RBAC) — the tasks each vet staff member or veterinarian may do.
 *
 * How it works:
 *  - The ROLE decides which pages a person can open at all (staff pages, vet pages, admin pages).
 *  - Within the vet staff and veterinarian roles, each account has TASKS (below). A new account starts with every task of its
 *    role; the admin can switch tasks off for one person (Add Account, or ⋮ → Permissions on User & Client Management).
 *  - Stored in rbac_user_permissions: one row per task, is_granted 1 = allowed, 0 = switched off.
 *    An account with no rows has the full set of its role (so nobody is ever locked out by default).
 *  - Every task is checked on the server (pv_can) before the action runs, and the buttons/links for switched-off tasks are hidden.
 *
 * UI keys: 'links' = link targets to hide, 'fields' = form fields ("name" or "name=value") whose form buttons are hidden.
 */
const PV_TASKS = [
    'staff' => [
        'staff.view_appointments'          => ['Appointments', 'View appointments', 'Open the appointment calendar and request list', ['links' => ['staff_dashboard.php?tab=calendar', 'staff_dashboard.php?tab=appointments']]],
        'staff.accept_appointment'         => ['Appointments', 'Approve appointments', 'Confirm pending appointment requests', ['fields' => ['action=accept_appointment']]],
        'staff.reject_appointment'         => ['Appointments', 'Decline appointments', 'Decline appointment requests with a reason', ['fields' => ['action=reject_appointment']]],
        'staff.reschedule_appointment'     => ['Appointments', 'Reschedule appointments', 'Propose or set a new date and time', ['fields' => ['action=reschedule_appointment']]],
        'staff.assign_doctor'              => ['Appointments', 'Assign veterinarian', 'Choose or change the vet for an appointment', ['fields' => ['action=assign_doctor']]],
        'staff.update_appointment_status'  => ['Appointments', 'Check in & complete visits', 'Check in, start, complete or mark no-show (completing also bills the visit)', ['fields' => ['action=update_status']]],
        'staff.register_walkin'            => ['Appointments', 'Register walk-ins', 'Add walk-in appointments and walk-in boarding stays', ['fields' => ['action=create_walkin', 'add_walkin_boarding']]],
        'staff.view_boarding_requests'     => ['Boarding', 'Open Boarding Ops', 'View boarding requests, stays and cages', ['links' => ['staff_boarding.php']]],
        'staff.accept_boarding'            => ['Boarding', 'Approve boarding & assign cage', 'Approve a stay and assign its cage', ['fields' => ['boarding_action=approve']]],
        'staff.reject_boarding'            => ['Boarding', 'Decline boarding', 'Decline a boarding request with a reason', ['fields' => ['boarding_action=reject']]],
        'staff.update_boarding_status'     => ['Boarding', 'Check in & check out pets', 'Check a pet in, check it out and record the payment', ['fields' => ['check_in_pet', 'release_cage', 'action=update_boarding_status']]],
        'staff.manage_boarding_schedule'   => ['Boarding', 'Change or cancel stays', 'Reschedule or cancel a boarding stay', ['fields' => ['boarding_manage']]],
        'staff.record_care_notes'          => ['Boarding', 'Record care notes', 'Write daily care notes for boarding pets', ['fields' => ['save_care_notes']]],
        'staff.monitor_pet_condition'      => ['Boarding', 'Manage cage cameras', 'Set the camera link and turn the live feed on or off', ['fields' => ['save_cctv']]],
        'staff.check_medicine_availability'=> ['Inventory & Clients', 'View inventory', 'See medicine and supply stock', ['links' => ['staff_dashboard.php?tab=inventory']]],
        'staff.request_supplies'           => ['Inventory & Clients', 'Report stock problems', 'Report damaged, expired or missing stock to the admin', ['fields' => ['action=report_stock_problem']]],
        'staff.assist_client_communication'=> ['Inventory & Clients', 'Chat with clients', 'Read and reply to client messages', ['links' => ['staff_dashboard.php?tab=client_assistance', 'admin_messages.php']]],
    ],
    'doctor' => [
        'doctor.view_schedule'             => ['Schedule', 'Manage my schedule & leave', 'Set weekly working hours and file leave', ['fields' => ['add_leave', 'save_weekly_schedule', 'delete_leave']]],
        'doctor.patient_assessment'        => ['Consultations', 'Open consultations', 'See appointments waiting for a consultation', ['links' => ['doctor_consultations.php']]],
        'doctor.diagnosis'                 => ['Consultations', 'Record consultations', 'Save the assessment, diagnosis and treatment', ['fields' => ['save_consultation']]],
        'doctor.prescriptions'             => ['Consultations', 'Write prescriptions', 'Issue and print prescriptions', ['fields' => ['save_rx']]],
        'doctor.pet_medical_history'       => ['Patients', 'View patient records (EMR)', 'Open the patient list and medical history', ['links' => ['doctor_patients.php'], 'fields' => ['emr_action=update_profile']]],
        'doctor.treatment_plans'           => ['Patients', 'Add medical records', 'Add records to a pet\'s medical history', ['fields' => ['emr_action=save_record']]],
        'doctor.vaccination_records'       => ['Patients', 'Record vaccinations', 'Log vaccines given and the next due date', ['fields' => ['emr_action=save_vaccination']]],
        'doctor.manage_inventory'          => ['Patients', 'View pharmacy stock', 'See medicine stock and expiry', ['links' => ['doctor_inventory.php']]],
    ],
];

/** Tasks of a role ([] for roles without per-person tasks: admin, clinic owner, clients). */
function pv_tasks_for($role) {
    return PV_TASKS[$role] ?? [];
}

/** The account's saved task switches: [permission_key => 0|1] ([] = full role set). */
function pv_user_tasks($conn, $uid) {
    static $cache = [];
    $uid = (int)$uid;
    if (!isset($cache[$uid])) {
        $cache[$uid] = [];
        $q = $conn->query("SELECT permission_key, is_granted FROM rbac_user_permissions WHERE user_id = $uid");
        if ($q) while ($r = $q->fetch_assoc()) $cache[$uid][$r['permission_key']] = (int)$r['is_granted'];
    }
    return $cache[$uid];
}

/** May the signed-in person (or $uid/$role) do this task? Tasks of other roles are not restricted here (the role checks handle those). */
function pv_can($conn, $perm, $uid = null, $role = null) {
    $uid  = $uid ?? (int)($_SESSION['user_id'] ?? 0);
    $role = $role ?? ($_SESSION['role'] ?? '');
    if (!isset(PV_TASKS[$role][$perm])) return true;
    $saved = pv_user_tasks($conn, $uid);
    return !$saved || (($saved[$perm] ?? 1) === 1); // a task added after the account was set up starts allowed
}

/** Message shown when a switched-off task is attempted. */
function pv_denied_message($perm) {
    foreach (PV_TASKS as $tasks) if (isset($tasks[$perm])) return "Your account doesn't have permission to “" . $tasks[$perm][1] . "”. Ask the administrator if you need it.";
    return "Your account doesn't have permission for that action.";
}

/** Save an account's task switches. All tasks on → no rows (full role set). */
function pv_save_tasks($conn, $uid, $role, array $granted_keys) {
    $uid = (int)$uid;
    $conn->query("DELETE FROM rbac_user_permissions WHERE user_id = $uid");
    $tasks = pv_tasks_for($role);
    if (!$tasks) return;
    $granted = array_flip(array_intersect(array_keys($tasks), $granted_keys));
    if (count($granted) === count($tasks)) return;
    $st = $conn->prepare("INSERT INTO rbac_user_permissions (user_id, permission_key, is_granted) VALUES (?, ?, ?)");
    foreach (array_keys($tasks) as $k) { $g = isset($granted[$k]) ? 1 : 0; $st->bind_param("isi", $uid, $k, $g); $st->execute(); }
}

/** Number of switched-off tasks for an account (0 = full access). */
function pv_restricted_count($conn, $uid, $role) {
    if (!pv_tasks_for($role)) return 0;
    $saved = pv_user_tasks($conn, $uid);
    return count(array_filter(array_intersect_key($saved, pv_tasks_for($role)), fn($g) => $g === 0));
}

/** <script> that hides links and buttons of switched-off tasks for the signed-in person ('' when nothing is switched off). */
function pv_ui_script($conn) {
    $role = $_SESSION['role'] ?? '';
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if (!$uid || !pv_tasks_for($role)) return '';
    $links = $fields = [];
    foreach (pv_tasks_for($role) as $k => $t) {
        if (pv_can($conn, $k, $uid, $role)) continue;
        $links = array_merge($links, $t[3]['links'] ?? []);
        $fields = array_merge($fields, $t[3]['fields'] ?? []);
    }
    if (!$links && !$fields) return '';
    $js = json_encode(['links' => $links, 'fields' => $fields]);
    return "<script>(function(){var D=$js;function run(){"
        . "D.links.forEach(function(h){document.querySelectorAll('a[href]').forEach(function(a){var x=a.getAttribute('href')||'';if(x===h||x.indexOf(h+'&')===0||x.indexOf(h+'#')===0||(h.indexOf('?')<0&&x.split('?')[0]===h)){a.style.display='none';}});});"
        . "D.fields.forEach(function(f){var p=f.split('='),sel='[name=\"'+p[0]+'\"]'+(p[1]?'[value=\"'+p[1]+'\"]':'');document.querySelectorAll(sel).forEach(function(el){"
        . "if(el.tagName==='BUTTON'){el.style.display='none';return;}var fm=el.closest('form');if(fm){fm.querySelectorAll('button[type=submit],button:not([type]),input[type=submit]').forEach(function(b){b.style.display='none';});}});});}"
        . "if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',run);}else{run();}})();</script>";
}
