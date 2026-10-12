# PragmaVet Appointment Scheduling System - User Manual

## Table of Contents
1. [Introduction](#introduction)
2. [Getting Started](#getting-started)
3. [Role-Based Dashboards](#role-based-dashboards)
4. [Appointment Management](#appointment-management)
5. [Calendar & Scheduling](#calendar--scheduling)
6. [Notifications](#notifications)
7. [Reports & Analytics](#reports--analytics)
8. [Administration](#administration)
9. [FAQ](#faq)

---

## Introduction

PragmaVet is a comprehensive veterinary clinic management system designed to streamline appointment scheduling, client management, and clinic operations. The system supports three main user roles:

- **Administrator** - Full system access and configuration
- **Staff/Doctor** - Appointment management and client care
- **Client** - Self-service booking and appointment tracking

---

## Getting Started

### Login
1. Navigate to `http://localhost/pragmavet/`
2. Enter your email and password
3. Click **Sign In**

### First-Time Login
- You may be required to change your password
- Set a strong password (min 8 characters, mix of letters, numbers, symbols)
- Update your profile information

### Forgot Password
1. Click **Forgot Password** on the login page
2. Enter your registered email
3. Check your email for reset instructions
4. Follow the link to reset your password

### Navigation
The system uses a sidebar navigation menu:
- **Dashboard** - Role-specific overview
- **Appointments** - Manage all appointments
- **Calendar** - Visual appointment calendar
- **Clients** - Client management (admin/staff)
- **Pets** - Pet profiles
- **Services** - Service management (admin)
- **Reports** - Generate reports (admin/staff)
- **Notifications** - View notifications
- **Settings** - System configuration (admin)

---

## Role-Based Dashboards

### Administrator Dashboard
Access: Full system overview

**Statistics Cards:**
- Total Appointments (all time)
- Today's Appointments
- Upcoming Appointments
- Pending Approvals
- Completed Today
- Cancelled Today
- No-Show Today
- Active Staff/Doctors
- Active Clients

**Charts & Analytics:**
- Monthly Appointment Trends (12-month view)
- Service Distribution (pie chart)
- Staff Workload (today)
- Peak Booking Hours
- Revenue (today & month-to-date)

**Quick Actions:**
- View today's schedule timeline
- Approve/reject pending appointments
- View recent system activities
- Access pending approvals list

### Staff/Doctor Dashboard
Access: Personal schedule and assigned appointments

**Views:**
- Today's Schedule (timeline view)
- Upcoming Appointments (next 7 days)
- Personal Statistics (total, completed, pending)
- Unread Notifications

**Quick Actions:**
- Check in patients
- Start/complete appointments
- View client details
- Update appointment notes

### Client Dashboard
Access: Personal appointment management

**Views:**
- Next Upcoming Appointment (highlighted)
- Appointment History (last 10)
- Personal Statistics
- My Pets (quick view)
- Unread Notifications

**Quick Actions:**
- Book New Appointment
- View/Cancel upcoming appointments
- Update pet profiles
- View appointment history

---

## Appointment Management

### Booking an Appointment (Client)

The system uses a 7-step wizard:

**Step 1: Select Pet**
- Choose from your registered pets
- Each pet shows name, species, and photo

**Step 2: Select Service**
- Choose from available services
- Each service shows duration and price
- Services are categorized (Medical, Preventive, Diagnostic, etc.)

**Step 3: Select Staff/Doctor**
- View available doctors and staff
- See specialization and availability
- Filter by service requirements

**Step 4: Choose Date**
- Interactive date picker
- Unavailable dates are greyed out
- Holidays and closures are marked

**Step 5: Choose Time**
- Available time slots are displayed
- Slots show start and end times
- Conflicts are automatically prevented

**Step 6: Review**
- Summary of all selections
- Pet, service, staff, date, time
- Add notes if needed

**Step 7: Confirmation**
- Appointment is booked
- Reference number is provided
- Confirmation notification sent

### Managing Appointments (Staff/Admin)

**View Appointments:**
- Filter by status, date, staff, client
- Search by pet name, client name, or reference number
- Sort by date, time, status
- Paginated results

**Appointment Actions:**

| Action | Description | Available To |
|--------|-------------|--------------|
| Approve | Approve pending appointment | Admin, Staff |
| Reject | Reject with reason | Admin, Staff |
| Cancel | Cancel appointment | All roles |
| Check In | Mark patient as arrived | Staff, Doctor |
| Start | Begin appointment | Doctor |
| Complete | Mark as done | Doctor |
| No Show | Mark as missed | Admin, Staff |
| Reschedule | Change date/time | All roles |

**Status Flow:**
```
Pending → Approved → Scheduled → Checked In → In Progress → Completed
    ↓         ↓           ↓             ↓              ↓
  Rejected  Cancelled  No Show      Cancelled      Cancelled
```

### Rescheduling
1. Open the appointment details
2. Click **Reschedule**
3. Select new date and time
4. Optionally change doctor
5. Provide reason for rescheduling
6. System validates new slot availability
7. Confirm reschedule

### Cancellation
1. Open the appointment details
2. Click **Cancel**
3. Provide cancellation reason
4. Confirm cancellation
5. Notification sent to affected parties

---

## Calendar & Scheduling

### Calendar Views
- **Daily View:** Hour-by-hour schedule for selected day
- **Weekly View:** Full week overview
- **Monthly View:** Month calendar with appointment indicators

### Color Coding
Appointments are color-coded by status:
- 🟡 **Pending** - Yellow (#F59E0B)
- 🟠 **Waiting Approval** - Orange (#F97316)
- 🔵 **Approved** - Blue (#3B82F6)
- 🟣 **Scheduled** - Purple (#8B5CF6)
- 🔷 **Checked In** - Cyan (#06B6D4)
- 🔮 **In Progress** - Indigo (#6366F1)
- 🟢 **Completed** - Green (#10B981)
- 🔴 **Cancelled** - Red (#EF4444)
- ⚫ **No Show** - Gray (#6B7280)

### Filtering
Filter calendar by:
- Staff/Doctor
- Service type
- Status
- Client

### Calendar Legend
Located below the calendar showing all status colors and their meanings.

---

## Notifications

### Types of Notifications
- **Appointment Created** - When you book an appointment
- **Appointment Approved** - When admin approves
- **Appointment Cancelled** - When cancelled by any party
- **Appointment Rescheduled** - When date/time changes
- **Appointment Reminder** - 24 hours before appointment
- **Missed Appointment** - When marked as no-show
- **System Announcements** - Admin broadcasts

### Managing Notifications
1. Click the bell icon in the top bar
2. View unread notifications count
3. Click a notification to view details
4. Mark as read individually or "Mark All Read"
5. Delete old notifications

### Notification Preferences
1. Go to Settings/Profile
2. Configure which notifications to receive
3. Choose channels (in-app, email)
4. Save preferences

---

## Reports & Analytics

### Available Reports

| Report Type | Description | Export |
|-------------|-------------|--------|
| Daily Report | Summary of today's appointments | CSV, PDF |
| Weekly Report | Weekly appointment statistics | CSV, PDF |
| Monthly Report | Monthly performance metrics | CSV, PDF |
| Annual Report | Yearly overview and trends | CSV, PDF |
| Staff Performance | Individual staff metrics | CSV |
| Service Utilization | Service popularity and revenue | CSV |
| Cancellation Report | All cancelled appointments | CSV |
| No-Show Report | Missed appointments analysis | CSV |
| Client Activity | Client visit history | CSV |
| Audit Logs | System activity trail | CSV |

### Generating Reports
1. Navigate to **Reports** section
2. Select report type
3. Set date range/parameters
4. Click **Generate**
5. View on screen or export

### Analytics Dashboard
- Real-time statistics
- Monthly trends chart
- Service distribution
- Staff workload
- Peak hours analysis
- Revenue tracking

---

## Administration

### User Management
- Add/edit/disable users
- Assign roles (admin, doctor, staff, client)
- Manage permissions per role
- View user activity

### Service Management
- Add/edit services
- Set duration, price, category
- Configure color coding
- Enable/disable services

### Staff Management
- Add/edit staff profiles
- Set working hours
- Configure daily capacity
- Manage leave/unavailability
- Track performance

### Schedule Management
- Set weekly schedules per staff
- Manage holidays and closures
- Configure buffer times
- Set max appointments per day

### System Settings
- Clinic information
- Working hours
- Appointment rules
- Notification configuration
- Email settings
- Backup configuration

### Backup & Restore
1. Go to **Backup** section
2. Click **Create Backup** to generate database backup
3. View backup history with file sizes
4. To restore: select backup, confirm, restore
5. Delete old backups as needed

### Activity Logs
- View all system activities
- Filter by module, action, user, date
- Search by description
- Export logs for auditing

---

## FAQ

### General

**Q: How do I reset my password?**
A: Click "Forgot Password" on the login page and follow the email instructions.

**Q: Can I have multiple pets?**
A: Yes, you can register multiple pets under your account.

**Q: How far in advance can I book?**
A: Up to 30 days in advance, minimum 24 hours notice.

### Appointments

**Q: Can I cancel an appointment?**
A: Yes, you can cancel pending or approved appointments from your dashboard.

**Q: How do I reschedule?**
A: Open the appointment and click "Reschedule" to choose a new date/time.

**Q: What happens if I miss my appointment?**
A: After the grace period (default 15 minutes), it will be marked as "No Show".

**Q: Can I book for multiple pets?**
A: Yes, select multiple pets during the booking wizard.

### Technical

**Q: What browsers are supported?**
A: Chrome, Firefox, Safari, Edge (latest versions).

**Q: Is the system mobile-friendly?**
A: Yes, fully responsive for desktop, tablet, and mobile.

**Q: How is my data protected?**
A: Passwords are hashed, data is encrypted in transit, and all inputs are sanitized.

### Billing

**Q: How do I pay for appointments?**
A: Payment options are available during booking or at the clinic.

**Q: Can I see my billing history?**
A: Yes, view billing history in your account.

---

## Support

For additional assistance:
- **Email:** pragmavet@gmail.com
- **Phone:** 046-8851-221
- **Address:** 0234 Unit B Palico St, Palico 4, Imus, Cavite
- **Hours:** Monday - Saturday, 8:00 AM - 5:00 PM