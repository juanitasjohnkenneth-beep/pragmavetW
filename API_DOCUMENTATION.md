# PragmaVet Appointment Scheduling System - API Documentation

## Overview
RESTful API endpoints for the PragmaVet appointment scheduling and management system. All endpoints return JSON responses.

## Base URL
```
http://your-domain.com/pragmavet/api/
```

## Authentication
All endpoints require PHP session-based authentication. State-changing operations require CSRF tokens.

### CSRF Token
Include in POST/PUT/DELETE requests:
- Header: `X-CSRF-Token: {token}`
- Or POST field: `csrf_token={token}`

Get token: `<?php echo generate_csrf_token(); ?>`

### Response Format
```json
{
  "success": true,
  "message": "Operation successful",
  "data": { ... }
}
```

Error Response:
```json
{
  "error": "Error message",
  "errors": ["field1 is required"]
}
```

---

## 1. Appointments API
**File:** `api/appointments.php`

### List Appointments
```
GET /api/appointments.php?action=list
```

**Parameters:**
| Parameter | Type | Description |
|-----------|------|-------------|
| page | int | Page number (default: 1) |
| per_page | int | Items per page (10-50, default: 20) |
| status | string | Filter by status |
| staff_id | int | Filter by staff ID |
| client_id | int | Filter by client ID |
| date | string | Filter by exact date (Y-m-d) |
| date_from | string | Filter from date |
| date_to | string | Filter to date |
| search | string | Search keyword |
| sort | string | Sort field (appointment_date, status, created_at) |
| dir | string | Sort direction (ASC/DESC) |

**Response:**
```json
{
  "appointments": [
    {
      "id": 1,
      "appointment_number": "PV-202607-0001",
      "pet_name": "Max",
      "service_type": "Consultation",
      "appointment_date": "2026-07-29",
      "appointment_time": "09:00:00",
      "status": "pending",
      "client_name": "John Doe",
      "staff_name": "Dr. Smith",
      "status_info": { "name": "pending", "color": "#F59E0B", "display_name": "Pending" },
      "pets": [...]
    }
  ],
  "pagination": {
    "current_page": 1,
    "per_page": 20,
    "total": 50,
    "total_pages": 3
  }
}
```

### View Appointment
```
GET /api/appointments.php?action=view&id={id}
```

**Response:** Full appointment details with history, pets, and notifications.

### Create Appointment
```
POST /api/appointments.php?action=create
```

**Request Body:**
```json
{
  "pet_name": "Max",
  "service_type": "Consultation",
  "appointment_date": "2026-07-29",
  "appointment_time": "09:00",
  "doctor_id": 2,
  "service_location": "On-Site",
  "notes": "Annual checkup",
  "pet_ids": [1]
}
```

**Response:** `{ "appointment_id": 1, "appointment_number": "PV-202607-0001", "status": "pending" }`

### Update Appointment Status
```
POST /api/appointments.php?action={status}&id={id}
```

**Available Status Actions:**
| Action | Description |
|--------|-------------|
| approve | Approve pending appointment |
| reject | Reject with reason |
| cancel | Cancel appointment |
| check_in | Check in patient |
| start | Start appointment (in_progress) |
| complete | Mark as completed |
| no_show | Mark as no-show |

**Request Body (for reject/cancel):**
```json
{ "reason": "Client requested cancellation" }
```

### Reschedule Appointment
```
POST /api/appointments.php?action=reschedule&id={id}
```

**Request Body:**
```json
{
  "new_date": "2026-07-30",
  "new_time": "10:00",
  "new_doctor_id": 3,
  "reason": "Client requested time change"
}
```

### Check Slot Availability
```
GET /api/appointments.php?action=check_slot&doctor_id=2&date=2026-07-29&time=09:00&service=Consultation
```

**Response:**
```json
{
  "valid": true,
  "message": "Slot is available",
  "data": { "start_time": "09:00:00", "end_time": "09:30:00", "duration_minutes": 30 }
}
```

### Get Available Slots
```
GET /api/appointments.php?action=available_slots&staff_id=2&date=2026-07-29&service=Consultation
```

**Response:**
```json
{
  "slots": [
    { "time": "09:00", "time_label": "09:00 AM", "end_time": "09:30", "available": true },
    { "time": "09:30", "time_label": "09:30 AM", "end_time": "10:00", "available": true }
  ],
  "available": true,
  "count": 8
}
```

### Calendar Data
```
GET /api/appointments.php?action=calendar&start=2026-07-01&end=2026-08-01&staff_id=2
```

### Appointment Statistics
```
GET /api/appointments.php?action=stats
```

### Search Appointments
```
GET /api/appointments.php?action=search&q=Max
```

### Get Services
```
GET /api/appointments.php?action=services
```

### Get Staff
```
GET /api/appointments.php?action=staff
```

### Get User Pets
```
GET /api/appointments.php?action=pets
```

---

## 2. Notifications API
**File:** `api/notifications.php`

### List Notifications
```
GET /api/notifications.php?action=list&page=1&per_page=20
```

### Get Unread Count
```
GET /api/notifications.php?action=unread_count
```

### Mark as Read
```
POST /api/notifications.php
{ "action": "mark_read", "id": 1 }
```

### Mark All as Read
```
POST /api/notifications.php
{ "action": "mark_all_read" }
```

### Send Notification (Admin)
```
POST /api/notifications.php
{ "action": "send", "target_user_id": 5, "title": "Announcement", "message": "Clinic closed on Friday", "type": "info" }
```

### Update Preferences
```
PUT /api/notifications.php
{ "action": "update_preferences", "preferences": { "email": true, "sms": false, "in_app": true } }
```

---

## 3. Reports API
**File:** `api/reports.php`

### Daily Report
```
GET /api/reports.php?action=daily&date=2026-07-28
```

### Weekly Report
```
GET /api/reports.php?action=weekly&date=2026-07-28
```

### Monthly Report
```
GET /api/reports.php?action=monthly&year=2026&month=07
```

### Annual Report
```
GET /api/reports.php?action=annual&year=2026
```

### Staff Performance
```
GET /api/reports.php?action=staff_performance&date_from=2026-01-01&date_to=2026-07-28
```

### Service Utilization
```
GET /api/reports.php?action=service_utilization&date_from=2026-01-01&date_to=2026-07-28
```

### Cancellation Report
```
GET /api/reports.php?action=cancellation&date_from=2026-01-01&date_to=2026-07-28
```

### No-Show Report
```
GET /api/reports.php?action=no_show&date_from=2026-01-01&date_to=2026-07-28
```

### Client Activity
```
GET /api/reports.php?action=client_activity&date_from=2026-01-01&date_to=2026-07-28
```

### Audit Logs
```
GET /api/reports.php?action=audit_logs&date_from=2026-01-01&date_to=2026-07-28&module=appointments
```

### Analytics Dashboard
```
GET /api/reports.php?action=analytics
```

### Export to Excel (CSV)
```
POST /api/reports.php
{ "action": "export_excel", "type": "daily", "params": { "date": "2026-07-28" } }
```

---

## 4. Activity Logs API
**File:** `api/activity_logs.php`

### List Logs
```
GET /api/activity_logs.php?action=list&page=1&per_page=50&module=appointments&date_from=2026-07-01
```

### Get Modules
```
GET /api/activity_logs.php?action=modules
```

### Get Actions
```
GET /api/activity_logs.php?action=actions&module=appointments
```

### Get Summary
```
GET /api/activity_logs.php?action=summary
```

---

## 5. Backup API
**File:** `api/backup.php` (Admin only)

### List Backups
```
GET /api/backup.php?action=list
```

### Get Backup Info
```
GET /api/backup.php?action=info
```

### Create Backup
```
POST /api/backup.php
{ "action": "create" }
```

### Restore Backup
```
POST /api/backup.php
{ "action": "restore", "backup_id": 1, "confirmed": true }
```

### Delete Backup
```
POST /api/backup.php
{ "action": "delete", "backup_id": 1 }
```

---

## 6. Analytics/Dashboard API
**File:** `api/analytics/index.php`

### Admin Dashboard
```
GET /api/analytics/index.php?dashboard=admin
```

### Staff Dashboard
```
GET /api/analytics/index.php?dashboard=staff
```

### Client Dashboard
```
GET /api/analytics/index.php?dashboard=client
```

---

## Error Codes
| Code | Description |
|------|-------------|
| 200 | Success |
| 201 | Created |
| 400 | Bad Request |
| 401 | Unauthorized |
| 403 | Forbidden |
| 404 | Not Found |
| 409 | Conflict (slot unavailable) |
| 422 | Validation Error |
| 429 | Rate Limit Exceeded |
| 500 | Internal Server Error |

## Rate Limiting
- 5 attempts per 15 minutes for sensitive actions (login, password reset)
- Standard endpoints: no strict limit but pagination recommended