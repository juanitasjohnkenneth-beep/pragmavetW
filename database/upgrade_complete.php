<?php
/**
 * PragmaVet Complete System Upgrade
 * Version 2.0 - Full Appointment Scheduling & Management System
 * Run this script to upgrade the database to the complete schema
 */

require_once __DIR__ . '/../dbconnection.php';

echo "<!DOCTYPE html><html><head><title>System Upgrade</title>";
echo "<style>body{font-family:Arial,sans-serif;padding:20px;max-width:900px;margin:0 auto}";
echo ".success{color:#059669;background:#ecfdf5;padding:8px 14px;border-radius:6px;margin:4px 0}";
echo ".warning{color:#d97706;background:#fffbeb;padding:8px 14px;border-radius:6px;margin:4px 0}";
echo ".error{color:#dc2626;background:#fef2f2;padding:8px 14px;border-radius:6px;margin:4px 0}";
echo ".section{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:16px;margin:12px 0}";
echo "</style></head><body>";
echo "<h1>🚀 PragmaVet Complete System Upgrade v2.0</h1>";
echo "<p>Upgrading database schema and seeding initial data...</p><hr>";

$success_count = 0;
$warning_count = 0;
$error_count = 0;

function runQuery($conn, $sql, $description = '') {
    global $success_count, $warning_count, $error_count;
    $status = 'success';
    
    if ($conn->query($sql) === TRUE) {
        echo "<div class='success'>✓ " . htmlspecialchars($description ?: substr($sql, 0, 80)) . "</div>";
        $success_count++;
    } else {
        $err = $conn->error;
        // Check for "duplicate column" or "already exists" - these are warnings, not errors
        if (strpos($err, 'Duplicate column') !== false || 
            strpos($err, 'Duplicate key') !== false ||
            strpos($err, 'already exists') !== false ||
            strpos($err, 'Duplicate entry') !== false) {
            echo "<div class='warning'>⚠ Already exists: " . htmlspecialchars($description ?: substr($sql, 0, 60)) . "</div>";
            $warning_count++;
            $status = 'warning';
        } else {
            echo "<div class='error'>✗ Error: " . htmlspecialchars($err) . "</div>";
            $error_count++;
            $status = 'error';
        }
    }
    return $status;
}

// ============================================================
// SECTION 1: CREATE NEW TABLES
// ============================================================
echo "<div class='section'><h2>📦 Creating New Tables</h2>";

// Roles table
runQuery($conn, "CREATE TABLE IF NOT EXISTS `roles` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `name` VARCHAR(50) UNIQUE NOT NULL,
    `description` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "Create roles table");

// Permissions table
runQuery($conn, "CREATE TABLE IF NOT EXISTS `permissions` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `role_id` INT NOT NULL,
    `module` VARCHAR(100) NOT NULL,
    `can_view` TINYINT(1) DEFAULT 0,
    `can_create` TINYINT(1) DEFAULT 0,
    `can_edit` TINYINT(1) DEFAULT 0,
    `can_delete` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `unique_role_module` (`role_id`, `module`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "Create permissions table");

// Clients table
runQuery($conn, "CREATE TABLE IF NOT EXISTS `clients` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `user_id` INT UNIQUE NOT NULL,
    `date_of_birth` DATE,
    `emergency_contact` VARCHAR(100),
    `emergency_phone` VARCHAR(20),
    `preferred_contact_method` ENUM('email', 'phone', 'sms') DEFAULT 'email',
    `notes` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "Create clients table");

// Staff table
runQuery($conn, "CREATE TABLE IF NOT EXISTS `staff` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `user_id` INT UNIQUE NOT NULL,
    `specialization` VARCHAR(200),
    `license_number` VARCHAR(100),
    `qualifications` TEXT,
    `bio` TEXT,
    `is_active` TINYINT(1) DEFAULT 1,
    `max_appointments_per_day` INT DEFAULT 10,
    `working_hours_start` TIME DEFAULT '08:00:00',
    `working_hours_end` TIME DEFAULT '17:00:00',
    `appointment_buffer_minutes` INT DEFAULT 15,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "Create staff table");

// Services table
runQuery($conn, "CREATE TABLE IF NOT EXISTS `services` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `description` TEXT,
    `duration_minutes` INT NOT NULL DEFAULT 30,
    `price` DECIMAL(10,2) DEFAULT 0.00,
    `category` VARCHAR(50),
    `color` VARCHAR(7) DEFAULT '#1E5AA8',
    `is_active` TINYINT(1) DEFAULT 1,
    `requires_doctor` TINYINT(1) DEFAULT 1,
    `max_pets_per_appointment` INT DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "Create services table");

// Appointment statuses table
runQuery($conn, "CREATE TABLE IF NOT EXISTS `appointment_statuses` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `name` VARCHAR(50) UNIQUE NOT NULL,
    `display_name` VARCHAR(100) NOT NULL,
    `color` VARCHAR(7) NOT NULL,
    `icon` VARCHAR(50),
    `sort_order` INT DEFAULT 0,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "Create appointment_statuses table");

// Appointment history table
runQuery($conn, "CREATE TABLE IF NOT EXISTS `appointment_history` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `appointment_id` INT NOT NULL,
    `user_id` INT,
    `action` VARCHAR(50) NOT NULL,
    `from_status` VARCHAR(50),
    `to_status` VARCHAR(50),
    `description` TEXT,
    `metadata` JSON,
    `ip_address` VARCHAR(45),
    `user_agent` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`appointment_id`) REFERENCES `appointments`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_appointment_history_appt` (`appointment_id`),
    INDEX `idx_appointment_history_date` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "Create appointment_history table");

// Schedule availability table
runQuery($conn, "CREATE TABLE IF NOT EXISTS `schedule_availability` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `staff_id` INT NOT NULL,
    `day_of_week` TINYINT NOT NULL COMMENT '0=Sunday, 1=Monday, etc.',
    `start_time` TIME NOT NULL,
    `end_time` TIME NOT NULL,
    `is_available` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`staff_id`) REFERENCES `staff`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `unique_staff_day` (`staff_id`, `day_of_week`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "Create schedule_availability table");

// Staff unavailability table
runQuery($conn, "CREATE TABLE IF NOT EXISTS `staff_unavailability` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `staff_id` INT NOT NULL,
    `start_date` DATE NOT NULL,
    `end_date` DATE NOT NULL,
    `reason` VARCHAR(200),
    `type` ENUM('leave', 'sick', 'training', 'personal', 'other') DEFAULT 'leave',
    `is_approved` TINYINT(1) DEFAULT 0,
    `approved_by` INT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`staff_id`) REFERENCES `staff`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_staff_unavail_dates` (`start_date`, `end_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "Create staff_unavailability table");

// Holidays table
runQuery($conn, "CREATE TABLE IF NOT EXISTS `holidays` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `date` DATE NOT NULL,
    `is_recurring` TINYINT(1) DEFAULT 0,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_holiday_date` (`date`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "Create holidays table");

// Clinic settings table
runQuery($conn, "CREATE TABLE IF NOT EXISTS `clinic_settings` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `setting_key` VARCHAR(100) UNIQUE NOT NULL,
    `setting_value` TEXT,
    `setting_type` ENUM('text', 'number', 'boolean', 'json', 'time') DEFAULT 'text',
    `description` TEXT,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "Create clinic_settings table");

// Notifications table
runQuery($conn, "CREATE TABLE IF NOT EXISTS `notifications` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `type` VARCHAR(50) DEFAULT 'info',
    `title` VARCHAR(200),
    `message` TEXT NOT NULL,
    `link` VARCHAR(500),
    `is_read` TINYINT(1) DEFAULT 0,
    `read_at` TIMESTAMP NULL,
    `sent_via` JSON,
    `reference_type` VARCHAR(50),
    `reference_id` INT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_notifications_user_read` (`user_id`, `is_read`),
    INDEX `idx_notifications_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "Create notifications table");

// Notification templates table
runQuery($conn, "CREATE TABLE IF NOT EXISTS `notification_templates` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `event` VARCHAR(100) UNIQUE NOT NULL,
    `title_template` VARCHAR(200),
    `message_template` TEXT NOT NULL,
    `channels` JSON DEFAULT '[\"in_app\"]',
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "Create notification_templates table");

// Activity logs table
runQuery($conn, "CREATE TABLE IF NOT EXISTS `activity_logs` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `user_id` INT,
    `user_role` VARCHAR(50),
    `action` VARCHAR(100) NOT NULL,
    `module` VARCHAR(50) NOT NULL,
    `description` TEXT,
    `metadata` JSON,
    `ip_address` VARCHAR(45),
    `user_agent` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_activity_logs_user` (`user_id`),
    INDEX `idx_activity_logs_action` (`action`),
    INDEX `idx_activity_logs_module` (`module`),
    INDEX `idx_activity_logs_date` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "Create activity_logs table");

// Reports table
runQuery($conn, "CREATE TABLE IF NOT EXISTS `reports` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `name` VARCHAR(200) NOT NULL,
    `type` VARCHAR(50) NOT NULL,
    `parameters` JSON,
    `generated_by` INT,
    `file_path` VARCHAR(500),
    `file_type` VARCHAR(10),
    `file_size` INT,
    `is_ready` TINYINT(1) DEFAULT 0,
    `error_message` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `completed_at` TIMESTAMP NULL,
    FOREIGN KEY (`generated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_reports_type` (`type`),
    INDEX `idx_reports_date` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "Create reports table");

// Backup history table
runQuery($conn, "CREATE TABLE IF NOT EXISTS `backup_history` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `file_name` VARCHAR(200) NOT NULL,
    `file_path` VARCHAR(500) NOT NULL,
    `file_size` BIGINT,
    `type` ENUM('manual', 'scheduled', 'auto') DEFAULT 'manual',
    `status` ENUM('success', 'failed', 'in_progress') DEFAULT 'in_progress',
    `created_by` INT,
    `notes` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `restored_at` TIMESTAMP NULL,
    `restored_by` INT,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`restored_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "Create backup_history table");

// Password reset tokens table
runQuery($conn, "CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `email` VARCHAR(100) NOT NULL,
    `token` VARCHAR(100) NOT NULL,
    `expires_at` TIMESTAMP NOT NULL,
    `used_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_password_reset_email` (`email`),
    INDEX `idx_password_reset_token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "Create password_reset_tokens table");

// Appointment pets junction table
runQuery($conn, "CREATE TABLE IF NOT EXISTS `appointment_pets` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `appointment_id` INT NOT NULL,
    `pet_id` INT,
    `pet_name` VARCHAR(100),
    `display_name` VARCHAR(100),
    `display_picture` VARCHAR(500),
    `species` VARCHAR(50),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`appointment_id`) REFERENCES `appointments`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`pet_id`) REFERENCES `pets`(`id`) ON DELETE SET NULL,
    INDEX `idx_appt_pets_appointment` (`appointment_id`),
    INDEX `idx_appt_pets_pet` (`pet_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "Create appointment_pets table");

echo "</div>";

// ============================================================
// SECTION 2: ALTER EXISTING TABLES
// ============================================================
echo "<div class='section'><h2>🔧 Enhancing Existing Tables</h2>";

// Users table enhancements
$user_columns = [
    "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `role_id` INT NULL AFTER `role`",
    "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `phone` VARCHAR(20) AFTER `contact_number`",
    "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `timezone` VARCHAR(50) DEFAULT 'Asia/Manila' AFTER `phone`",
    "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `language` VARCHAR(10) DEFAULT 'en' AFTER `timezone`",
    "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `notification_preferences` JSON AFTER `language`",
    "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `email_verified_at` TIMESTAMP NULL AFTER `notification_preferences`",
    "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `remember_token` VARCHAR(100) AFTER `email_verified_at`",
    "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `last_activity` TIMESTAMP NULL AFTER `remember_token`",
    "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `deleted_at` TIMESTAMP NULL AFTER `last_activity`"
];

foreach ($user_columns as $sql) {
    runQuery($conn, $sql);
}

// Pets table enhancements
$pets_columns = [
    "ALTER TABLE `pets` ADD COLUMN IF NOT EXISTS `microchip_number` VARCHAR(50) AFTER `pet_picture`",
    "ALTER TABLE `pets` ADD COLUMN IF NOT EXISTS `allergies` TEXT AFTER `microchip_number`",
    "ALTER TABLE `pets` ADD COLUMN IF NOT EXISTS `medical_conditions` TEXT AFTER `allergies`",
    "ALTER TABLE `pets` ADD COLUMN IF NOT EXISTS `medications` TEXT AFTER `medical_conditions`",
    "ALTER TABLE `pets` ADD COLUMN IF NOT EXISTS `dietary_restrictions` TEXT AFTER `medications`",
    "ALTER TABLE `pets` ADD COLUMN IF NOT EXISTS `is_active` TINYINT(1) DEFAULT 1 AFTER `dietary_restrictions`",
    "ALTER TABLE `pets` ADD COLUMN IF NOT EXISTS `deleted_at` TIMESTAMP NULL AFTER `is_active`"
];

foreach ($pets_columns as $sql) {
    runQuery($conn, $sql);
}

// Appointments table enhancements
$appt_columns = [
    "ALTER TABLE `appointments` ADD COLUMN IF NOT EXISTS `appointment_number` VARCHAR(20) UNIQUE AFTER `id`",
    "ALTER TABLE `appointments` ADD COLUMN IF NOT EXISTS `service_id` INT AFTER `service_type`",
    "ALTER TABLE `appointments` ADD COLUMN IF NOT EXISTS `status_id` INT AFTER `status`",
    "ALTER TABLE `appointments` ADD COLUMN IF NOT EXISTS `duration_minutes` INT DEFAULT 30 AFTER `service_duration_minutes`",
    "ALTER TABLE `appointments` ADD COLUMN IF NOT EXISTS `end_time` TIME AFTER `appointment_end_time`",
    "ALTER TABLE `appointments` ADD COLUMN IF NOT EXISTS `check_in_time` TIMESTAMP NULL AFTER `end_time`",
    "ALTER TABLE `appointments` ADD COLUMN IF NOT EXISTS `check_out_time` TIMESTAMP NULL AFTER `check_in_time`",
    "ALTER TABLE `appointments` ADD COLUMN IF NOT EXISTS `start_time` TIMESTAMP NULL AFTER `check_out_time`",
    "ALTER TABLE `appointments` ADD COLUMN IF NOT EXISTS `completed_time` TIMESTAMP NULL AFTER `start_time`",
    "ALTER TABLE `appointments` ADD COLUMN IF NOT EXISTS `cancelled_at` TIMESTAMP NULL AFTER `completed_time`",
    "ALTER TABLE `appointments` ADD COLUMN IF NOT EXISTS `cancellation_reason` TEXT AFTER `cancelled_at`",
    "ALTER TABLE `appointments` ADD COLUMN IF NOT EXISTS `rescheduled_from` INT NULL AFTER `cancellation_reason`",
    "ALTER TABLE `appointments` ADD COLUMN IF NOT EXISTS `rescheduled_to` INT NULL AFTER `rescheduled_from`",
    "ALTER TABLE `appointments` ADD COLUMN IF NOT EXISTS `is_walk_in` TINYINT(1) DEFAULT 0 AFTER `rescheduled_to`",
    "ALTER TABLE `appointments` ADD COLUMN IF NOT EXISTS `reminder_sent` TINYINT(1) DEFAULT 0 AFTER `is_walk_in`",
    "ALTER TABLE `appointments` ADD COLUMN IF NOT EXISTS `reminder_sent_at` TIMESTAMP NULL AFTER `reminder_sent`",
    "ALTER TABLE `appointments` ADD COLUMN IF NOT EXISTS `notes` TEXT AFTER `reminder_sent_at`",
    "ALTER TABLE `appointments` ADD COLUMN IF NOT EXISTS `internal_notes` TEXT AFTER `notes`",
    "ALTER TABLE `appointments` ADD COLUMN IF NOT EXISTS `created_by` INT AFTER `internal_notes`",
    "ALTER TABLE `appointments` ADD COLUMN IF NOT EXISTS `updated_by` INT AFTER `created_by`",
    "ALTER TABLE `appointments` ADD COLUMN IF NOT EXISTS `deleted_at` TIMESTAMP NULL AFTER `updated_by`"
];

foreach ($appt_columns as $sql) {
    runQuery($conn, $sql);
}

echo "</div>";

// ============================================================
// SECTION 3: SEED DEFAULT DATA
// ============================================================
echo "<div class='section'><h2>🌱 Seeding Default Data</h2>";

// Default roles
$roles = [
    [1, 'admin', 'Full system access - all modules and features'],
    [2, 'doctor', 'Veterinary staff - appointments, client records, medical services'],
    [3, 'staff', 'Clinic staff - limited administrative access'],
    [4, 'client', 'Pet owners - booking, history, profile management']
];

foreach ($roles as $role) {
    $stmt = $conn->prepare("INSERT IGNORE INTO `roles` (`id`, `name`, `description`) VALUES (?, ?, ?)");
    $stmt->bind_param("iss", $role[0], $role[1], $role[2]);
    if ($stmt->execute()) {
        echo "<div class='success'>✓ Role created: {$role[1]}</div>";
    }
}

// Default appointment statuses
$statuses = [
    [1, 'pending', 'Pending', '#F59E0B', 'fa-clock', 1],
    [2, 'waiting_approval', 'Waiting Approval', '#F97316', 'fa-hourglass-half', 2],
    [3, 'approved', 'Approved', '#3B82F6', 'fa-check-circle', 3],
    [4, 'scheduled', 'Scheduled', '#8B5CF6', 'fa-calendar-check', 4],
    [5, 'checked_in', 'Checked In', '#06B6D4', 'fa-sign-in-alt', 5],
    [6, 'in_progress', 'In Progress', '#6366F1', 'fa-spinner', 6],
    [7, 'completed', 'Completed', '#10B981', 'fa-check-double', 7],
    [8, 'cancelled', 'Cancelled', '#EF4444', 'fa-times-circle', 8],
    [9, 'rejected', 'Rejected', '#DC2626', 'fa-ban', 9],
    [10, 'rescheduled', 'Rescheduled', '#EC4899', 'fa-calendar-alt', 10],
    [11, 'no_show', 'No Show', '#6B7280', 'fa-user-slash', 11]
];

foreach ($statuses as $s) {
    $stmt = $conn->prepare("INSERT IGNORE INTO `appointment_statuses` (`id`, `name`, `display_name`, `color`, `icon`, `sort_order`) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("issssi", $s[0], $s[1], $s[2], $s[3], $s[4], $s[5]);
    if ($stmt->execute()) {
        echo "<div class='success'>✓ Status created: {$s[2]}</div>";
    }
}

// Default clinic settings
$settings = [
    ['clinic_name', 'PragmaVet Animal Clinic', 'text', 'Name of the veterinary clinic'],
    ['clinic_address', '0234 Unit B Palico St, Palico 4, Imus, Cavite', 'text', 'Clinic physical address'],
    ['clinic_phone', '046-8851-221', 'text', 'Clinic contact number'],
    ['clinic_mobile', '0954-285-3053', 'text', 'Clinic mobile number'],
    ['clinic_email', 'pragmavet@gmail.com', 'text', 'Clinic email address'],
    ['working_hours_start', '08:00', 'time', 'Clinic opening time'],
    ['working_hours_end', '17:00', 'time', 'Clinic closing time'],
    ['appointment_buffer_minutes', '15', 'number', 'Buffer time between appointments (minutes)'],
    ['max_appointments_per_day', '10', 'number', 'Maximum appointments per doctor per day'],
    ['advance_booking_hours', '24', 'number', 'Minimum hours in advance for booking'],
    ['max_advance_booking_days', '30', 'number', 'Maximum days in advance for booking'],
    ['allow_same_day_booking', '0', 'boolean', 'Allow same-day appointment booking'],
    ['auto_approve_appointments', '0', 'boolean', 'Automatically approve new appointments'],
    ['reminder_hours_before', '24', 'number', 'Hours before appointment to send reminder'],
    ['no_show_grace_period', '15', 'number', 'Minutes after appointment time to mark as no-show'],
    ['default_appointment_duration', '30', 'number', 'Default appointment duration in minutes'],
    ['currency_symbol', '₱', 'text', 'Currency symbol for pricing'],
    ['enable_audit_logging', '1', 'boolean', 'Enable activity audit logging']
];

foreach ($settings as $s) {
    $stmt = $conn->prepare("INSERT IGNORE INTO `clinic_settings` (`setting_key`, `setting_value`, `setting_type`, `description`) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $s[0], $s[1], $s[2], $s[3]);
    if ($stmt->execute()) {
        echo "<div class='success'>✓ Setting: {$s[0]}</div>";
    }
}

// Default notification templates
$templates = [
    ['appointment_created', 'Appointment Booked', 'Your appointment for {pet_name} on {date} at {time} has been booked successfully. Reference: {appointment_number}', '["in_app", "email"]'],
    ['appointment_approved', 'Appointment Approved', 'Your appointment for {pet_name} on {date} at {time} has been approved.', '["in_app", "email"]'],
    ['appointment_cancelled', 'Appointment Cancelled', 'Your appointment for {pet_name} on {date} at {time} has been cancelled.', '["in_app", "email"]'],
    ['appointment_rescheduled', 'Appointment Rescheduled', 'Your appointment for {pet_name} has been rescheduled to {new_date} at {new_time}.', '["in_app", "email"]'],
    ['appointment_reminder', 'Appointment Reminder', 'Reminder: You have an appointment for {pet_name} tomorrow ({date}) at {time}.', '["in_app", "email"]'],
    ['appointment_completed', 'Appointment Completed', 'Your appointment for {pet_name} on {date} has been marked as completed.', '["in_app"]'],
    ['appointment_no_show', 'Missed Appointment', 'Your appointment for {pet_name} on {date} at {time} was marked as missed.', '["in_app", "email"]']
];

foreach ($templates as $t) {
    $stmt = $conn->prepare("INSERT IGNORE INTO `notification_templates` (`event`, `title_template`, `message_template`, `channels`) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $t[0], $t[1], $t[2], $t[3]);
    if ($stmt->execute()) {
        echo "<div class='success'>✓ Template: {$t[0]}</div>";
    }
}

// Default services
$services = [
    ['Consultation', 'General veterinary consultation and checkup', 30, 500.00, 'Medical', '#3B82F6', 1],
    ['Vaccination', 'Pet vaccination services', 15, 300.00, 'Preventive', '#10B981', 1],
    ['Surgery', 'Surgical procedures', 120, 5000.00, 'Medical', '#EF4444', 1],
    ['Dental Cleaning', 'Professional dental cleaning for pets', 45, 1500.00, 'Medical', '#8B5CF6', 1],
    ['Grooming', 'Full pet grooming services', 90, 800.00, 'Wellness', '#EC4899', 0],
    ['X-Ray', 'Digital X-ray imaging', 20, 1000.00, 'Diagnostic', '#F59E0B', 1],
    ['Lab Test', 'Laboratory testing and analysis', 30, 750.00, 'Diagnostic', '#06B6D4', 0],
    ['Follow-up', 'Follow-up consultation', 20, 300.00, 'Medical', '#6366F1', 1],
    ['Home Visit', 'Veterinary home visit service', 60, 1500.00, 'Special', '#F97316', 1],
    ['Confinement', 'Pet confinement and monitoring', 1440, 2000.00, 'Medical', '#DC2626', 1]
];

foreach ($services as $svc) {
    $stmt = $conn->prepare("INSERT IGNORE INTO `services` (`name`, `description`, `duration_minutes`, `price`, `category`, `color`, `requires_doctor`) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssidssi", $svc[0], $svc[1], $svc[2], $svc[3], $svc[4], $svc[5], $svc[6]);
    if ($stmt->execute()) {
        echo "<div class='success'>✓ Service: {$svc[0]}</div>";
    }
}

// Migrate service_durations to services if exists
$check = $conn->query("SELECT COUNT(*) as c FROM service_durations");
if ($check && $check->fetch_assoc()['c'] > 0) {
    $conn->query("INSERT IGNORE INTO `services` (`name`, `duration_minutes`) SELECT `service_type`, `duration_minutes` FROM `service_durations` WHERE `service_type` NOT IN (SELECT `name` FROM `services`)");
    echo "<div class='success'>✓ Migrated service durations to services table</div>";
}

echo "</div>";

// ============================================================
// SUMMARY
// ============================================================
echo "<hr><h2>📊 Upgrade Summary</h2>";
echo "<div style='display:flex;gap:15px;'>";
echo "<div style='background:#ecfdf5;padding:12px 20px;border-radius:8px;border:1px solid #a7f3d0;'>";
echo "<strong style='color:#059669;font-size:18px;'>✓ {$success_count}</strong><br><span style='color:#047857;font-size:13px;'>Successful</span></div>";
echo "<div style='background:#fffbeb;padding:12px 20px;border-radius:8px;border:1px solid #fde68a;'>";
echo "<strong style='color:#d97706;font-size:18px;'>⚠ {$warning_count}</strong><br><span style='color:#b45309;font-size:13px;'>Already Exists</span></div>";
echo "<div style='background:#fef2f2;padding:12px 20px;border-radius:8px;border:1px solid #fecaca;'>";
echo "<strong style='color:#dc2626;font-size:18px;'>✗ {$error_count}</strong><br><span style='color:#b91c1c;font-size:13px;'>Errors</span></div>";
echo "</div>";

if ($error_count === 0) {
    echo "<p style='color:#059669;font-weight:bold;margin-top:16px;'>✅ Database upgrade completed successfully!</p>";
} else {
    echo "<p style='color:#dc2626;font-weight:bold;margin-top:16px;'>⚠ Upgrade completed with {$error_count} error(s). Please check the errors above.</p>";
}

echo "<hr><h3>Next Steps</h3>";
echo "<ol>";
echo "<li>Update user roles - admin users are now role_id=1, doctors role_id=2</li>";
echo "<li>Configure clinic settings in the admin panel</li>";
echo "<li>Set up staff schedules in the schedule manager</li>";
echo "<li>Verify the appointment wizard works correctly</li>";
echo "</ol>";

echo "</body></html>";
?>