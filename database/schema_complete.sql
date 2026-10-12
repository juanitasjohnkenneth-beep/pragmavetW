-- ============================================================
-- PragmaVet Complete Database Schema
-- Appointment Scheduling & Management System
-- Version 2.0
-- ============================================================

-- Core Tables
CREATE TABLE IF NOT EXISTS `roles` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `name` VARCHAR(50) UNIQUE NOT NULL,
    `description` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `permissions` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Users table (enhanced)
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `role_id` INT NULL AFTER `role`;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `phone` VARCHAR(20) AFTER `contact_number`;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `timezone` VARCHAR(50) DEFAULT 'Asia/Manila' AFTER `phone`;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `language` VARCHAR(10) DEFAULT 'en' AFTER `timezone`;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `notification_preferences` JSON AFTER `language`;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `email_verified_at` TIMESTAMP NULL AFTER `notification_preferences`;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `remember_token` VARCHAR(100) AFTER `email_verified_at`;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `last_activity` TIMESTAMP NULL AFTER `remember_token`;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `deleted_at` TIMESTAMP NULL AFTER `last_activity`;

-- Clients table
CREATE TABLE IF NOT EXISTS `clients` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Staff table
CREATE TABLE IF NOT EXISTS `staff` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Pets table (enhance existing)
ALTER TABLE `pets` ADD COLUMN IF NOT EXISTS `microchip_number` VARCHAR(50) AFTER `pet_picture`;
ALTER TABLE `pets` ADD COLUMN IF NOT EXISTS `allergies` TEXT AFTER `microchip_number`;
ALTER TABLE `pets` ADD COLUMN IF NOT EXISTS `medical_conditions` TEXT AFTER `allergies`;
ALTER TABLE `pets` ADD COLUMN IF NOT EXISTS `medications` TEXT AFTER `medical_conditions`;
ALTER TABLE `pets` ADD COLUMN IF NOT EXISTS `dietary_restrictions` TEXT AFTER `medications`;
ALTER TABLE `pets` ADD COLUMN IF NOT EXISTS `is_active` TINYINT(1) DEFAULT 1 AFTER `dietary_restrictions`;
ALTER TABLE `pets` ADD COLUMN IF NOT EXISTS `deleted_at` TIMESTAMP NULL AFTER `is_active`;

-- Services table
CREATE TABLE IF NOT EXISTS `services` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Appointments table (enhance existing)
ALTER TABLE `appointments` ADD COLUMN IF NOT EXISTS `appointment_number` VARCHAR(20) UNIQUE AFTER `id`;
ALTER TABLE `appointments` ADD COLUMN IF NOT EXISTS `service_id` INT AFTER `service_type`;
ALTER TABLE `appointments` ADD COLUMN IF NOT EXISTS `status_id` INT AFTER `status`;
ALTER TABLE `appointments` ADD COLUMN IF NOT EXISTS `duration_minutes` INT DEFAULT 30 AFTER `service_duration_minutes`;
ALTER TABLE `appointments` ADD COLUMN IF NOT EXISTS `end_time` TIME AFTER `appointment_end_time`;
ALTER TABLE `appointments` ADD COLUMN IF NOT EXISTS `check_in_time` TIMESTAMP NULL AFTER `end_time`;
ALTER TABLE `appointments` ADD COLUMN IF NOT EXISTS `check_out_time` TIMESTAMP NULL AFTER `check_in_time`;
ALTER TABLE `appointments` ADD COLUMN IF NOT EXISTS `start_time` TIMESTAMP NULL AFTER `check_out_time`;
ALTER TABLE `appointments` ADD COLUMN IF NOT EXISTS `completed_time` TIMESTAMP NULL AFTER `start_time`;
ALTER TABLE `appointments` ADD COLUMN IF NOT EXISTS `cancelled_at` TIMESTAMP NULL AFTER `completed_time`;
ALTER TABLE `appointments` ADD COLUMN IF NOT EXISTS `cancellation_reason` TEXT AFTER `cancelled_at`;
ALTER TABLE `appointments` ADD COLUMN IF NOT EXISTS `rescheduled_from` INT NULL AFTER `cancellation_reason`;
ALTER TABLE `appointments` ADD COLUMN IF NOT EXISTS `rescheduled_to` INT NULL AFTER `rescheduled_from`;
ALTER TABLE `appointments` ADD COLUMN IF NOT EXISTS `is_walk_in` TINYINT(1) DEFAULT 0 AFTER `rescheduled_to`;
ALTER TABLE `appointments` ADD COLUMN IF NOT EXISTS `reminder_sent` TINYINT(1) DEFAULT 0 AFTER `is_walk_in`;
ALTER TABLE `appointments` ADD COLUMN IF NOT EXISTS `reminder_sent_at` TIMESTAMP NULL AFTER `reminder_sent`;
ALTER TABLE `appointments` ADD COLUMN IF NOT EXISTS `notes` TEXT AFTER `reminder_sent_at`;
ALTER TABLE `appointments` ADD COLUMN IF NOT EXISTS `internal_notes` TEXT AFTER `notes`;
ALTER TABLE `appointments` ADD COLUMN IF NOT EXISTS `created_by` INT AFTER `internal_notes`;
ALTER TABLE `appointments` ADD COLUMN IF NOT EXISTS `updated_by` INT AFTER `created_by`;
ALTER TABLE `appointments` ADD COLUMN IF NOT EXISTS `deleted_at` TIMESTAMP NULL AFTER `updated_by`;

-- Appointment statuses
CREATE TABLE IF NOT EXISTS `appointment_statuses` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `name` VARCHAR(50) UNIQUE NOT NULL,
    `display_name` VARCHAR(100) NOT NULL,
    `color` VARCHAR(7) NOT NULL,
    `icon` VARCHAR(50),
    `sort_order` INT DEFAULT 0,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Appointment history/audit
CREATE TABLE IF NOT EXISTS `appointment_history` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Schedule availability
CREATE TABLE IF NOT EXISTS `schedule_availability` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Staff leave/unavailability
CREATE TABLE IF NOT EXISTS `staff_unavailability` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Holidays and clinic closures
CREATE TABLE IF NOT EXISTS `holidays` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `date` DATE NOT NULL,
    `is_recurring` TINYINT(1) DEFAULT 0,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_holiday_date` (`date`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Clinic settings
CREATE TABLE IF NOT EXISTS `clinic_settings` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `setting_key` VARCHAR(100) UNIQUE NOT NULL,
    `setting_value` TEXT,
    `setting_type` ENUM('text', 'number', 'boolean', 'json', 'time') DEFAULT 'text',
    `description` TEXT,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Notifications table (enhance existing)
CREATE TABLE IF NOT EXISTS `notifications` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `type` VARCHAR(50) DEFAULT 'info',
    `title` VARCHAR(200),
    `message` TEXT NOT NULL,
    `link` VARCHAR(500),
    `is_read` TINYINT(1) DEFAULT 0,
    `read_at` TIMESTAMP NULL,
    `sent_via` JSON COMMENT '["in_app", "email", "sms"]',
    `reference_type` VARCHAR(50),
    `reference_id` INT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_notifications_user_read` (`user_id`, `is_read`),
    INDEX `idx_notifications_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Notification templates
CREATE TABLE IF NOT EXISTS `notification_templates` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `event` VARCHAR(100) UNIQUE NOT NULL,
    `title_template` VARCHAR(200),
    `message_template` TEXT NOT NULL,
    `channels` JSON DEFAULT '["in_app"]',
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Activity logs
CREATE TABLE IF NOT EXISTS `activity_logs` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Reports table
CREATE TABLE IF NOT EXISTS `reports` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Backup history
CREATE TABLE IF NOT EXISTS `backup_history` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Password reset tokens
CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `email` VARCHAR(100) NOT NULL,
    `token` VARCHAR(100) NOT NULL,
    `expires_at` TIMESTAMP NOT NULL,
    `used_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_password_reset_email` (`email`),
    INDEX `idx_password_reset_token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Appointment pets junction (enhance existing)
CREATE TABLE IF NOT EXISTS `appointment_pets` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- INSERT DEFAULT DATA
-- ============================================================

-- Default roles
INSERT IGNORE INTO `roles` (`id`, `name`, `description`) VALUES
(1, 'admin', 'Full system access - all modules and features'),
(2, 'doctor', 'Veterinary staff - appointments, client records, medical services'),
(3, 'staff', 'Clinic staff - limited administrative access'),
(4, 'client', 'Pet owners - booking, history, profile management');

-- Default appointment statuses
INSERT IGNORE INTO `appointment_statuses` (`id`, `name`, `display_name`, `color`, `icon`, `sort_order`) VALUES
(1, 'pending', 'Pending', '#F59E0B', 'fa-clock', 1),
(2, 'waiting_approval', 'Waiting Approval', '#F97316', 'fa-hourglass-half', 2),
(3, 'approved', 'Approved', '#3B82F6', 'fa-check-circle', 3),
(4, 'scheduled', 'Scheduled', '#8B5CF6', 'fa-calendar-check', 4),
(5, 'checked_in', 'Checked In', '#06B6D4', 'fa-sign-in-alt', 5),
(6, 'in_progress', 'In Progress', '#6366F1', 'fa-spinner', 6),
(7, 'completed', 'Completed', '#10B981', 'fa-check-double', 7),
(8, 'cancelled', 'Cancelled', '#EF4444', 'fa-times-circle', 8),
(9, 'rejected', 'Rejected', '#DC2626', 'fa-ban', 9),
(10, 'rescheduled', 'Rescheduled', '#EC4899', 'fa-calendar-alt', 10),
(11, 'no_show', 'No Show', '#6B7280', 'fa-user-slash', 11);

-- Default clinic settings
INSERT IGNORE INTO `clinic_settings` (`setting_key`, `setting_value`, `setting_type`, `description`) VALUES
('clinic_name', 'PragmaVet Animal Clinic', 'text', 'Name of the veterinary clinic'),
('clinic_address', '0234 Unit B Palico St, Palico 4, Imus, Cavite', 'text', 'Clinic physical address'),
('clinic_phone', '046-8851-221', 'text', 'Clinic contact number'),
('clinic_mobile', '0954-285-3053', 'text', 'Clinic mobile number'),
('clinic_email', 'pragmavet@gmail.com', 'text', 'Clinic email address'),
('working_hours_start', '08:00', 'time', 'Clinic opening time'),
('working_hours_end', '17:00', 'time', 'Clinic closing time'),
('appointment_buffer_minutes', '15', 'number', 'Buffer time between appointments (minutes)'),
('max_appointments_per_day', '10', 'number', 'Maximum appointments per doctor per day'),
('advance_booking_hours', '24', 'number', 'Minimum hours in advance for booking'),
('max_advance_booking_days', '30', 'number', 'Maximum days in advance for booking'),
('allow_same_day_booking', '0', 'boolean', 'Allow same-day appointment booking'),
('auto_approve_appointments', '0', 'boolean', 'Automatically approve new appointments'),
('reminder_hours_before', '24', 'number', 'Hours before appointment to send reminder'),
('no_show_grace_period', '15', 'number', 'Minutes after appointment time to mark as no-show'),
('default_appointment_duration', '30', 'number', 'Default appointment duration in minutes'),
('currency_symbol', '₱', 'text', 'Currency symbol for pricing'),
('date_format', 'Y-m-d', 'text', 'Date display format'),
('time_format', 'H:i', 'text', 'Time display format'),
('timezone', 'Asia/Manila', 'text', 'System timezone'),
('retention_period_months', '12', 'number', 'Months to keep completed appointments before archiving'),
('enable_email_notifications', '1', 'boolean', 'Enable email notifications'),
('enable_sms_notifications', '0', 'boolean', 'Enable SMS notifications'),
('enable_audit_logging', '1', 'boolean', 'Enable activity audit logging');

-- Default notification templates
INSERT IGNORE INTO `notification_templates` (`event`, `title_template`, `message_template`, `channels`) VALUES
('appointment_created', 'Appointment Booked', 'Your appointment for {pet_name} on {date} at {time} has been booked successfully. Reference: {appointment_number}', '["in_app", "email"]'),
('appointment_approved', 'Appointment Approved', 'Your appointment for {pet_name} on {date} at {time} has been approved.', '["in_app", "email"]'),
('appointment_cancelled', 'Appointment Cancelled', 'Your appointment for {pet_name} on {date} at {time} has been cancelled.', '["in_app", "email"]'),
('appointment_rescheduled', 'Appointment Rescheduled', 'Your appointment for {pet_name} has been rescheduled to {new_date} at {new_time}.', '["in_app", "email"]'),
('appointment_reminder', 'Appointment Reminder', 'Reminder: You have an appointment for {pet_name} tomorrow ({date}) at {time}.', '["in_app", "email"]'),
('appointment_completed', 'Appointment Completed', 'Your appointment for {pet_name} on {date} has been marked as completed. Please leave a feedback!', '["in_app"]'),
('appointment_no_show', 'Missed Appointment', 'Your appointment for {pet_name} on {date} at {time} was marked as missed. Please reschedule.', '["in_app", "email"]'),
('check_in_reminder', 'Check-In Reminder', 'Please check in for your appointment with {pet_name} at {time}.', '["in_app"]');

-- Default services
INSERT IGNORE INTO `services` (`name`, `description`, `duration_minutes`, `price`, `category`, `color`, `requires_doctor`) VALUES
('Consultation', 'General veterinary consultation and checkup', 30, 500.00, 'Medical', '#3B82F6', 1),
('Vaccination', 'Pet vaccination services', 15, 300.00, 'Preventive', '#10B981', 1),
('Surgery', 'Surgical procedures', 120, 5000.00, 'Medical', '#EF4444', 1),
('Dental Cleaning', 'Professional dental cleaning for pets', 45, 1500.00, 'Medical', '#8B5CF6', 1),
('Grooming', 'Full pet grooming services', 90, 800.00, 'Wellness', '#EC4899', 0),
('X-Ray', 'Digital X-ray imaging', 20, 1000.00, 'Diagnostic', '#F59E0B', 1),
('Lab Test', 'Laboratory testing and analysis', 30, 750.00, 'Diagnostic', '#06B6D4', 0),
('Follow-up', 'Follow-up consultation', 20, 300.00, 'Medical', '#6366F1', 1),
('Home Visit', 'Veterinary home visit service', 60, 1500.00, 'Special', '#F97316', 1),
('Confinement', 'Pet confinement and monitoring', 1440, 2000.00, 'Medical', '#DC2626', 1);

-- Migrate existing service_durations to services table if exists
INSERT IGNORE INTO `services` (`name`, `duration_minutes`)
SELECT `service_type`, `duration_minutes` FROM `service_durations`
WHERE `service_type` NOT IN (SELECT `name` FROM `services`);