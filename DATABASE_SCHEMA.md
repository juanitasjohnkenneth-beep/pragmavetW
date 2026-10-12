# PragmaVet Database Schema Documentation

## Entity Relationship Overview

The database consists of 18+ tables organized into core entities, appointment management, scheduling, and system utilities.

---

## Core Tables

### 1. users
Primary table for all system users (admin, staff, doctors, clients).

| Column | Type | Description |
|--------|------|-------------|
| id | INT PRIMARY KEY AUTO_INCREMENT | User ID |
| fullname | VARCHAR(100) | Full name |
| email | VARCHAR(100) UNIQUE | Email address |
| password | VARCHAR(255) | Hashed password |
| role | ENUM('admin','doctor','staff','client') | User role |
| role_id | INT NULL | Foreign key to roles table |
| phone | VARCHAR(20) | Contact number |
| address | TEXT | Physical address |
| is_active | TINYINT(1) DEFAULT 1 | Active status |
| email_verified_at | TIMESTAMP NULL | Email verification |
| notification_preferences | JSON | Notification settings |
| force_password_change | TINYINT(1) DEFAULT 0 | Force password change flag |
| created_at | TIMESTAMP | Account creation date |
| updated_at | TIMESTAMP | Last update |
| deleted_at | TIMESTAMP NULL | Soft delete |

**Indexes:** id, email, role, role_id

### 2. roles
Defines user roles and their permissions.

| Column | Type | Description |
|--------|------|-------------|
| id | INT PRIMARY KEY AUTO_INCREMENT | Role ID |
| name | VARCHAR(50) UNIQUE | Role name (admin, doctor, staff, client) |
| description | TEXT | Role description |

**Seed Data:** admin, doctor, staff, client

### 3. permissions
Granular module-level permissions per role.

| Column | Type | Description |
|--------|------|-------------|
| id | INT PRIMARY KEY AUTO_INCREMENT | Permission ID |
| role_id | INT NOT NULL | Foreign key to roles |
| module | VARCHAR(100) | Module name (appointments, users, reports, etc.) |
| can_view | TINYINT(1) DEFAULT 0 | View permission |
| can_create | TINYINT(1) DEFAULT 0 | Create permission |
| can_edit | TINYINT(1) DEFAULT 0 | Edit permission |
| can_delete | TINYINT(1) DEFAULT 0 | Delete permission |

**Unique Key:** (role_id, module)

### 4. clients
Extended client information.

| Column | Type | Description |
|--------|------|-------------|
| id | INT PRIMARY KEY AUTO_INCREMENT | Client ID |
| user_id | INT UNIQUE NOT NULL | Foreign key to users |
| date_of_birth | DATE | Client DOB |
| emergency_contact | VARCHAR(100) | Emergency contact name |
| emergency_phone | VARCHAR(20) | Emergency contact number |
| preferred_contact_method | ENUM('email','phone','sms') | Contact preference |
| notes | TEXT | Client notes |

### 5. staff
Extended staff/doctor information.

| Column | Type | Description |
|--------|------|-------------|
| id | INT PRIMARY KEY AUTO_INCREMENT | Staff ID |
| user_id | INT UNIQUE NOT NULL | Foreign key to users |
| specialization | VARCHAR(200) | Medical specialization |
| license_number | VARCHAR(100) | Professional license |
| qualifications | TEXT | Qualifications |
| bio | TEXT | Professional bio |
| is_active | TINYINT(1) DEFAULT 1 | Active status |
| max_appointments_per_day | INT DEFAULT 10 | Daily capacity |
| working_hours_start | TIME DEFAULT '08:00:00' | Work start time |
| working_hours_end | TIME DEFAULT '17:00:00' | Work end time |
| appointment_buffer_minutes | INT DEFAULT 15 | Buffer between appointments |

---

## Pet Management

### 6. pets
Pet profiles owned by clients.

| Column | Type | Description |
|--------|------|-------------|
| id | INT PRIMARY KEY AUTO_INCREMENT | Pet ID |
| user_id | INT | Owner ID (foreign key to users) |
| name | VARCHAR(100) | Pet name |
| species | VARCHAR(50) | Species (dog, cat, etc.) |
| breed | VARCHAR(100) | Breed |
| date_of_birth | DATE | Date of birth |
| pet_picture | VARCHAR(500) | Profile picture |
| microchip_number | VARCHAR(50) | Microchip ID |
| allergies | TEXT | Known allergies |
| medical_conditions | TEXT | Medical conditions |
| medications | TEXT | Current medications |
| is_active | TINYINT(1) DEFAULT 1 | Active status |
| deleted_at | TIMESTAMP NULL | Soft delete |

---

## Service Management

### 7. services
Clinic services offered.

| Column | Type | Description |
|--------|------|-------------|
| id | INT PRIMARY KEY AUTO_INCREMENT | Service ID |
| name | VARCHAR(100) NOT NULL | Service name |
| description | TEXT | Service description |
| duration_minutes | INT NOT NULL DEFAULT 30 | Duration |
| price | DECIMAL(10,2) DEFAULT 0.00 | Price |
| category | VARCHAR(50) | Category |
| color | VARCHAR(7) DEFAULT '#1E5AA8' | Display color |
| is_active | TINYINT(1) DEFAULT 1 | Active status |
| requires_doctor | TINYINT(1) DEFAULT 1 | Requires doctor assignment |
| max_pets_per_appointment | INT DEFAULT 1 | Max pets per booking |

**Seed Data:** Consultation, Vaccination, Surgery, Dental Cleaning, Grooming, X-Ray, Lab Test, Follow-up, Home Visit, Confinement

---

## Appointment Management

### 8. appointments
Core appointment records.

| Column | Type | Description |
|--------|------|-------------|
| id | INT PRIMARY KEY AUTO_INCREMENT | Appointment ID |
| appointment_number | VARCHAR(20) UNIQUE | Auto-generated reference number (PV-YYYYMM-XXXX) |
| user_id | INT | Client ID |
| pet_name | VARCHAR(200) | Pet name(s) |
| service_type | VARCHAR(100) | Service type |
| service_id | INT NULL | Foreign key to services |
| appointment_date | DATE | Appointment date |
| appointment_time | TIME | Appointment time |
| end_time | TIME | Calculated end time |
| duration_minutes | INT DEFAULT 30 | Duration |
| service_location | VARCHAR(100) | Location (On-Site/Home Visit) |
| doctor_id | INT NULL | Assigned doctor/staff |
| status | VARCHAR(50) | Current status |
| status_id | INT NULL | Foreign key to appointment_statuses |
| check_in_time | TIMESTAMP NULL | Check-in timestamp |
| start_time | TIMESTAMP NULL | Appointment start |
| completed_time | TIMESTAMP NULL | Completion time |
| cancelled_at | TIMESTAMP NULL | Cancellation time |
| cancellation_reason | TEXT | Reason for cancellation |
| rescheduled_from | INT NULL | Previous appointment ID |
| notes | TEXT | Client notes |
| internal_notes | TEXT | Internal/staff notes |
| is_walk_in | TINYINT(1) DEFAULT 0 | Walk-in flag |
| reminder_sent | TINYINT(1) DEFAULT 0 | Reminder status |
| reminder_sent_at | TIMESTAMP NULL | When reminder was sent |
| created_by | INT | Who created |
| updated_by | INT | Last updated by |
| created_at | TIMESTAMP | Created timestamp |
| deleted_at | TIMESTAMP NULL | Soft delete |

**Indexes:** user_id, doctor_id, appointment_date, status, appointment_number

### 9. appointment_statuses
Lookup table for appointment statuses with display metadata.

| Column | Type | Description |
|--------|------|-------------|
| id | INT PRIMARY KEY AUTO_INCREMENT | Status ID |
| name | VARCHAR(50) UNIQUE | System name |
| display_name | VARCHAR(100) | Display name |
| color | VARCHAR(7) | Hex color code |
| icon | VARCHAR(50) | FontAwesome icon |
| sort_order | INT DEFAULT 0 | Display order |
| is_active | TINYINT(1) DEFAULT 1 | Active status |

**Seed Statuses:** Pending, Waiting Approval, Approved, Scheduled, Checked In, In Progress, Completed, Cancelled, Rejected, Rescheduled, No Show

### 10. appointment_history
Audit trail for all appointment changes.

| Column | Type | Description |
|--------|------|-------------|
| id | INT PRIMARY KEY AUTO_INCREMENT | History ID |
| appointment_id | INT NOT NULL | Foreign key to appointments |
| user_id | INT NULL | User who performed action |
| action | VARCHAR(50) | Action type |
| from_status | VARCHAR(50) | Previous status |
| to_status | VARCHAR(50) | New status |
| description | TEXT | Action description |
| metadata | JSON | Additional data |
| ip_address | VARCHAR(45) | IP address |
| user_agent | TEXT | Browser/device info |
| created_at | TIMESTAMP | Action timestamp |

**Indexes:** appointment_id, created_at

### 11. appointment_pets
Junction table linking appointments to pets.

| Column | Type | Description |
|--------|------|-------------|
| id | INT PRIMARY KEY AUTO_INCREMENT | Record ID |
| appointment_id | INT NOT NULL | Foreign key to appointments |
| pet_id | INT NULL | Foreign key to pets |
| pet_name | VARCHAR(100) | Pet name at time of visit |
| display_name | VARCHAR(100) | Display name |
| display_picture | VARCHAR(500) | Pet photo |
| species | VARCHAR(50) | Species |

**Indexes:** appointment_id, pet_id

---

## Scheduling

### 12. schedule_availability
Staff weekly schedule templates.

| Column | Type | Description |
|--------|------|-------------|
| id | INT PRIMARY KEY AUTO_INCREMENT | Schedule ID |
| staff_id | INT NOT NULL | Foreign key to staff/users |
| day_of_week | TINYINT | 0=Sunday, 1=Monday, etc. |
| start_time | TIME | Shift start |
| end_time | TIME | Shift end |
| is_available | TINYINT(1) DEFAULT 1 | Available flag |

**Unique Key:** (staff_id, day_of_week)

### 13. staff_unavailability
Staff leave/vacation/unavailable dates.

| Column | Type | Description |
|--------|------|-------------|
| id | INT PRIMARY KEY AUTO_INCREMENT | Record ID |
| staff_id | INT NOT NULL | Foreign key to staff |
| start_date | DATE | Leave start |
| end_date | DATE | Leave end |
| reason | VARCHAR(200) | Leave reason |
| type | ENUM('leave','sick','training','personal','other') | Leave type |
| is_approved | TINYINT(1) DEFAULT 0 | Approval status |
| approved_by | INT NULL | Approver ID |

**Indexes:** start_date, end_date

### 14. holidays
Clinic holidays and closures.

| Column | Type | Description |
|--------|------|-------------|
| id | INT PRIMARY KEY AUTO_INCREMENT | Holiday ID |
| name | VARCHAR(100) | Holiday name |
| date | DATE | Holiday date |
| is_recurring | TINYINT(1) DEFAULT 0 | Recurring annually |
| is_active | TINYINT(1) DEFAULT 1 | Active status |

**Unique Key:** (date, name)

---

## System Utilities

### 15. clinic_settings
System-wide configuration.

| Column | Type | Description |
|--------|------|-------------|
| id | INT PRIMARY KEY AUTO_INCREMENT | Setting ID |
| setting_key | VARCHAR(100) UNIQUE | Setting key |
| setting_value | TEXT | Setting value |
| setting_type | ENUM('text','number','boolean','json','time') | Value type |
| description | TEXT | Setting description |
| updated_at | TIMESTAMP | Last update |

**Key Settings:** clinic_name, clinic_address, working_hours, appointment defaults, notification config, etc.

### 16. notifications
In-app and external notification records.

| Column | Type | Description |
|--------|------|-------------|
| id | INT PRIMARY KEY AUTO_INCREMENT | Notification ID |
| user_id | INT NOT NULL | Recipient ID |
| type | VARCHAR(50) DEFAULT 'info' | Notification type |
| title | VARCHAR(200) | Notification title |
| message | TEXT | Notification body |
| link | VARCHAR(500) | Action link |
| is_read | TINYINT(1) DEFAULT 0 | Read status |
| read_at | TIMESTAMP NULL | When read |
| sent_via | JSON | Channels used |
| reference_type | VARCHAR(50) | Related entity type |
| reference_id | INT | Related entity ID |
| created_at | TIMESTAMP | Creation time |

**Indexes:** (user_id, is_read), created_at

### 17. notification_templates
Templates for automated notifications.

| Column | Type | Description |
|--------|------|-------------|
| id | INT PRIMARY KEY AUTO_INCREMENT | Template ID |
| event | VARCHAR(100) UNIQUE | Event identifier |
| title_template | VARCHAR(200) | Title with placeholders |
| message_template | TEXT | Message with placeholders |
| channels | JSON | Delivery channels |
| is_active | TINYINT(1) DEFAULT 1 | Active status |

**Events:** appointment_created, appointment_approved, appointment_cancelled, appointment_rescheduled, appointment_reminder, appointment_completed, appointment_no_show, check_in_reminder

### 18. activity_logs
System activity audit trail.

| Column | Type | Description |
|--------|------|-------------|
| id | INT PRIMARY KEY AUTO_INCREMENT | Log ID |
| user_id | INT NULL | User who performed action |
| user_role | VARCHAR(50) | User role at time |
| action | VARCHAR(100) | Action performed |
| module | VARCHAR(50) | System module |
| description | TEXT | Action description |
| metadata | JSON | Additional context |
| ip_address | VARCHAR(45) | IP address |
| user_agent | TEXT | Browser/device info |
| created_at | TIMESTAMP | Action timestamp |

**Indexes:** user_id, action, module, created_at

### 19. reports
Generated report records.

| Column | Type | Description |
|--------|------|-------------|
| id | INT PRIMARY KEY AUTO_INCREMENT | Report ID |
| name | VARCHAR(200) | Report name |
| type | VARCHAR(50) | Report type |
| parameters | JSON | Generation parameters |
| generated_by | INT | User who generated |
| file_path | VARCHAR(500) | Export file path |
| file_type | VARCHAR(10) | File format |
| file_size | INT | File size in bytes |
| is_ready | TINYINT(1) DEFAULT 0 | Generation complete |
| error_message | TEXT | Error details |
| created_at | TIMESTAMP | Creation time |

### 20. backup_history
Database backup records.

| Column | Type | Description |
|--------|------|-------------|
| id | INT PRIMARY KEY AUTO_INCREMENT | Backup ID |
| file_name | VARCHAR(200) | Backup filename |
| file_path | VARCHAR(500) | Full file path |
| file_size | BIGINT | File size |
| type | ENUM('manual','scheduled','auto') | Backup type |
| status | ENUM('success','failed','in_progress') | Backup status |
| created_by | INT | User ID |
| notes | TEXT | Notes/errors |
| created_at | TIMESTAMP | Creation time |
| restored_at | TIMESTAMP NULL | Restoration time |
| restored_by | INT NULL | Who restored |

### 21. password_reset_tokens
Password reset tokens.

| Column | Type | Description |
|--------|------|-------------|
| id | INT PRIMARY KEY AUTO_INCREMENT | Token ID |
| email | VARCHAR(100) | User email |
| token | VARCHAR(100) | Reset token |
| expires_at | TIMESTAMP | Expiration |
| used_at | TIMESTAMP NULL | When used |
| created_at | TIMESTAMP | Creation time |

**Indexes:** email, token

---

## Relationships Diagram

```
users (1) ----< pets (N)          [Owner relationship]
users (1) ----< clients (1)       [Client profile]
users (1) ----< staff (1)         [Staff profile]
users (1) ----< appointments (N)  [Client appointments]
users (1) ----< appointments (N)  [Doctor assignments]
users (1) ----< notifications (N) [Recipient]
roles (1) ----< users (N)         [Role assignment]
roles (1) ----< permissions (N)   [Module permissions]
services (1) ----< appointments (N) [Service used]
appointments (1) ----< appointment_history (N) [Audit trail]
appointments (1) ----< appointment_pets (N) [Pets in appointment]
staff/users (1) ----< schedule_availability (N) [Weekly schedule]
staff/users (1) ----< staff_unavailability (N) [Leave records]
users (1) ----< activity_logs (N) [User activity]
```

---

## Key Constraints

### Unique Constraints
- `users.email`
- `roles.name`
- `services.name`
- `appointments.appointment_number`
- `appointment_statuses.name`
- `clinic_settings.setting_key`
- `schedule_availability` (staff_id, day_of_week)
- `holidays` (date, name)
- `notification_templates.event`
- `permissions` (role_id, module)
- `clients.user_id`, `staff.user_id`

### Foreign Keys with Cascade
- `appointment_history.appointment_id` -> `appointments.id` (CASCADE DELETE)
- `appointment_pets.appointment_id` -> `appointments.id` (CASCADE DELETE)
- `permissions.role_id` -> `roles.id` (CASCADE DELETE)
- `clients.user_id` -> `users.id` (CASCADE DELETE)
- `staff.user_id` -> `users.id` (CASCADE DELETE)
- `notifications.user_id` -> `users.id` (CASCADE DELETE)
- `schedule_availability.staff_id` -> `staff.id` (CASCADE DELETE)
- `staff_unavailability.staff_id` -> `staff.id` (CASCADE DELETE)

### Foreign Keys with SET NULL
- `appointment_history.user_id` -> `users.id` (SET NULL)
- `activity_logs.user_id` -> `users.id` (SET NULL)
- `appointment_pets.pet_id` -> `pets.id` (SET NULL)
- `reports.generated_by` -> `users.id` (SET NULL)
- `backup_history.created_by` -> `users.id` (SET NULL)