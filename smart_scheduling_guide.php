<?php
/**
 * Smart Appointment Scheduling System - Feature Documentation & Setup Guide
 * Version 1.0
 */
include("dbconnection.php");
?>
<!DOCTYPE html>
<html>
<head>
    <title>Smart Appointment Scheduling - Setup Guide</title>
    <style id="guide_custom">
        body { font-family: Arial, sans-serif; max-width: 1000px; margin: 0 auto; padding: 20px; line-height: 1.6; }
        h1 { border-bottom: 3px solid #007bff; color: #333; }
        h2 { color: #0056b3; margin-top: 30px; }
        h3 { color: #495057; }
        .feature { background: #e7f3ff; padding: 15px; margin: 10px 0; border-left: 4px solid #0056b3; }
        .feature h4 { margin-top: 0; }
        .api { background: #f0f0f0; padding: 12px; margin: 10px 0; border-radius: 4px; font-family: monospace; }
        .success { color: green; }
        .danger { color: red; }
        code { background: #f4f4f4; padding: 2px 6px; border-radius: 3px; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th, td { border: 1px solid #ddd; padding: 12px; text-align: left; }
        th { background: #0056b3; color: white; }
        .implementation-steps { background: #fff3cd; padding: 15px; margin: 15px 0; border-left: 4px solid #ffc107; }
    </style>
    <link rel="stylesheet" href="assets/pragmavet.css">
    <link href="assets/global-style.css" rel="stylesheet">
</head>
<body>
<h1>ðŸ¥ Smart Appointment Scheduling System</h1>

<p><strong>Status: Ready for Production</strong></p>
<p>This system adds intelligent appointment scheduling to prevent double-booking, optimize doctor capacity, and provide smart recommendations for clients.</p>

<hr>

<h2>ðŸ“‹ System Overview</h2>

<p>The Smart Scheduling System enforces business rules to ensure optimal appointment management:</p>

<div class="feature">
    <h4>âœ… No Double-Booking</h4>
    <p>Multiple clients cannot book the same doctor at the same time. Uses unique constraints and intelligent time slot detection.</p>
</div>

<div class="feature">
    <h4>â±ï¸ Service Duration Tracking</h4>
    <p>Each service type has a defined duration. Automatically calculates appointment end times and prevents overlaps.</p>
</div>

<div class="feature">
    <h4>ðŸ‘¨â€âš•ï¸ Doctor Capacity Management</h4>
    <p>Limit max appointments per doctor per day. Prevent overworking and ensure quality care.</p>
</div>

<div class="feature">
    <h4>ðŸ• Working Hours Enforcement</h4>
    <p>Doctors have defined working hours. System prevents booking outside these hours.</p>
</div>

<div class="feature">
    <h4>â³ Buffer Time Between Appointments</h4>
    <p>Configurable gap (default 15 min) between appointments for admin tasks and doctor breaks.</p>
</div>

<div class="feature">
    <h4>ðŸ“… 24-Hour Advance Booking</h4>
    <p>Clients cannot book same-day appointments (requires 24+ hours advance). Admins can create walk-ins anytime.</p>
</div>

<h2>ðŸ—„ï¸ Database Schema Changes</h2>

<table>
    <tr>
        <th>Table</th>
        <th>New Columns</th>
        <th>Purpose</th>
    </tr>
    <tr>
        <td>appointments</td>
        <td>
            <code>service_duration_minutes</code><br>
            <code>appointment_end_time</code>
        </td>
        <td>Track how long each appointment takes</td>
    </tr>
    <tr>
        <td>users</td>
        <td>
            <code>max_appointments_per_day</code><br>
            <code>working_hours_start</code><br>
            <code>working_hours_end</code><br>
            <code>appointment_buffer_minutes</code>
        </td>
        <td>Doctor scheduling configuration</td>
    </tr>
    <tr>
        <td>service_durations</td>
        <td>New table</td>
        <td>Reference table with service type â†’ duration mapping</td>
    </tr>
</table>

<h2>ðŸ“Š Default Configuration</h2>

<table>
    <tr>
        <th>Service Type</th>
        <th>Duration</th>
    </tr>
    <tr><td>Consultation</td><td>30 minutes</td></tr>
    <tr><td>Surgery</td><td>120 minutes (2 hours)</td></tr>
    <tr><td>Home Visit</td><td>60 minutes</td></tr>
    <tr><td>Vaccination</td><td>15 minutes</td></tr>
    <tr><td>Dental Cleaning</td><td>45 minutes</td></tr>
    <tr><td>Grooming</td><td>90 minutes</td></tr>
    <tr><td>X-Ray</td><td>20 minutes</td></tr>
    <tr><td>Lab Test</td><td>30 minutes</td></tr>
    <tr><td>Follow-up</td><td>20 minutes</td></tr>
</table>

<p><strong>Doctor Defaults (editable per doctor):</strong></p>
<ul>
    <li>Working Hours: 8:00 AM - 5:00 PM</li>
    <li>Max Appointments/Day: 10</li>
    <li>Buffer Between Appointments: 15 minutes</li>
</ul>

<h2>ðŸ”Œ API Endpoints</h2>

<h3>1. Get Available Slots</h3>
<div class="api">
GET /api_appointment_slots.php?date=2024-12-25&service_type=Consultation&doctor_id=5
</div>
<p><strong>Response:</strong></p>
<pre>{
  "date": "2024-12-25",
  "service_type": "Consultation",
  "duration_minutes": 30,
  "available_slots": [
    {
      "doctor_id": 5,
      "doctor_name": "Dr. John Smith",
      "slots": [
        {"time": "09:00", "end_time": "09:30"},
        {"time": "10:00", "end_time": "10:30"}
      ],
      "booked_count": 3,
      "remaining_capacity": 7
    }
  ]
}</pre>

<h3>2. Check Slot Conflict</h3>
<div class="api">
POST /api_check_slot_conflict.php
Content-Type: application/json

{
  "doctor_id": 5,
  "date": "2024-12-25",
  "time": "09:00",
  "duration_minutes": 30
}
</div>
<p><strong>Response (available):</strong></p>
<pre>{
  "available": true,
  "doctor_capacity": {
    "current_bookings": 3,
    "max_capacity": 10,
    "remaining": 7
  }
}</pre>

<p><strong>Response (conflict):</strong></p>
<pre>{
  "available": false,
  "reason": "Time slot conflicts with existing appointment",
  "conflicting_time": "09:15",
  "suggested_earliest": "09:45"
}</pre>

<h2>ðŸ› ï¸ Helper Functions</h2>

<p>Located in: <code>helpers_smart_scheduling.php</code></p>

<h3>validate_appointment_slot()</h3>
<p>Comprehensive validation of an appointment slot against all smart scheduling rules.</p>
<pre>$validation = validate_appointment_slot($conn, $doctor_id, $date, $time, $service_type, $check_24hour);

if ($validation['valid']) {
    // Slot is available - proceed with booking
    $duration = $validation['data']['duration_minutes'];
    $end_time = $validation['data']['end_time'];
} else {
    // Show error
    echo $validation['message'];
}</pre>

<h3>get_available_slots()</h3>
<p>Get next N available time slots for a doctor on a given date.</p>
<pre>$slots = get_available_slots($conn, $doctor_id, $date, $service_type, 5);
// Returns: [['time' => '09:00', 'end_time' => '09:30'], ...]</pre>

<h2>ðŸ“ Implementation in Your Application</h2>

<div class="implementation-steps">
    <h3>âœ… Already Implemented</h3>
    <ol>
        <li><strong>appointments.php</strong> - Client booking now uses smart scheduling validation</li>
        <li><strong>admin_list.php</strong> - Walk-in bookings use smart scheduling (allows same-day)</li>
        <li><strong>api_appointment_slots.php</strong> - JSON API for available slots</li>
        <li><strong>api_check_slot_conflict.php</strong> - JSON API for conflict detection</li>
        <li><strong>helpers_smart_scheduling.php</strong> - Reusable validation functions</li>
    </ol>
</div>

<h2>ðŸš€ Setup Instructions</h2>

<ol>
    <li>
        <strong>Run the migration:</strong>
        <ul>
            <li>Navigate to: <code>http://yoursite.com/upgrade_smart_scheduling.php</code></li>
            <li>This creates all required tables and columns</li>
            <li>Populates service_durations table with defaults</li>
        </ul>
    </li>
    <li>
        <strong>Verify database:</strong>
        <pre>SELECT * FROM service_durations;
SELECT max_appointments_per_day, working_hours_start FROM users WHERE role='doctor';</pre>
    </li>
    <li>
        <strong>Configure per-doctor settings (optional):</strong>
        <p>Update doctor working hours and max appointments in admin panel as needed</p>
    </li>
    <li>
        <strong>Test the system:</strong>
        <ul>
            <li>Book an appointment as a client - should fail if within 24 hours</li>
            <li>Try booking same slot twice - should fail on second attempt</li>
            <li>Create walk-in as admin - should work without 24-hour restriction</li>
        </ul>
    </li>
</ol>

<h2>ðŸ” Error Messages Users Will See</h2>

<table>
    <tr>
        <th>Scenario</th>
        <th>Message</th>
    </tr>
    <tr>
        <td>Booking within 24 hours</td>
        <td><span class="danger">âŒ Appointments must be booked at least 24 hours in advance. Earliest available: 2024-12-27</span></td>
    </tr>
    <tr>
        <td>Doctor on leave</td>
        <td><span class="danger">âŒ The selected doctor is not available on this date (on leave)</span></td>
    </tr>
    <tr>
        <td>Time slot taken</td>
        <td><span class="danger">âŒ This time slot is taken or too close to another appointment. Try 10:45 or later.</span></td>
    </tr>
    <tr>
        <td>Doctor fully booked</td>
        <td><span class="danger">âŒ Dr. Smith is fully booked on this date. Please try another date or doctor.</span></td>
    </tr>
    <tr>
        <td>Booking successful</td>
        <td><span class="success">âœ… Appointment for Fluffy booked successfully! We look forward to seeing you.</span></td>
    </tr>
</table>

<h2>âš™ï¸ Customization Guide</h2>

<h3>Change Service Durations</h3>
<pre>UPDATE service_durations SET duration_minutes = 45 WHERE service_type = 'Consultation';</pre>

<h3>Change Doctor's Max Appointments</h3>
<pre>UPDATE users SET max_appointments_per_day = 12 WHERE id = 5 AND role = 'doctor';</pre>

<h3>Change Doctor's Working Hours</h3>
<pre>UPDATE users 
SET working_hours_start = '09:00:00', 
    working_hours_end = '18:00:00'
WHERE id = 5 AND role = 'doctor';</pre>

<h3>Change Buffer Time Between Appointments</h3>
<pre>UPDATE users 
SET appointment_buffer_minutes = 20
WHERE id = 5 AND role = 'doctor';</pre>

<h3>Remove 24-Hour Advance Booking Requirement</h3>
<p>Edit <code>appointments.php</code> and change:</p>
<pre>if ($date < $tomorrow) {</pre>
<p>to:</p>
<pre>if ($date < $today) {</pre>

<h2>ðŸ“ˆ Monitoring & Analytics</h2>

<p>Useful queries to monitor the system:</p>

<h3>See doctor utilization for a specific day:</h3>
<pre>SELECT 
  CONCAT(u.fullname, ' (', u.max_appointments_per_day, ' max)') as doctor,
  COUNT(a.id) as booked,
  u.max_appointments_per_day - COUNT(a.id) as available,
  ROUND(100 * COUNT(a.id) / u.max_appointments_per_day, 1) as utilization_pct
FROM users u
LEFT JOIN appointments a ON u.id = a.doctor_id 
  AND a.appointment_date = '2024-12-25'
  AND a.status IN ('Pending', 'Approved')
WHERE u.role = 'doctor'
GROUP BY u.id
ORDER BY utilization_pct DESC;</pre>

<h3>Find overlapping appointments (should be none):</h3>
<pre>SELECT 
  a1.id, a1.appointment_time, a1.appointment_end_time, u1.fullname as doctor, a1.pet_name
FROM appointments a1
JOIN users u1 ON a1.doctor_id = u1.id
JOIN appointments a2 ON a1.doctor_id = a2.doctor_id 
  AND a1.appointment_date = a2.appointment_date
  AND a1.id < a2.id
WHERE a1.appointment_time < a2.appointment_end_time
  AND a2.appointment_time < a1.appointment_end_time
  AND a1.status IN ('Pending', 'Approved')
  AND a2.status IN ('Pending', 'Approved');</pre>

<h2>ðŸ› Troubleshooting</h2>

<p><strong>Q: "Duplicate entry" error when booking</strong></p>
<p>A: Two bookings were submitted simultaneously for the same slot. The unique constraint caught it. Show user the error and they should try a different time.</p>

<p><strong>Q: Customers can still book same-day appointments</strong></p>
<p>A: Check that <code>appointments.php</code> was updated with smart scheduling. Verify the validation is checking for <code>$tomorrow</code>.</p>

<p><strong>Q: Available slots API returns empty</strong></p>
<p>A: Check that service_durations table has the service_type. Query it:</p>
<pre>SELECT * FROM service_durations WHERE service_type = 'YourServiceName';</pre>

<p><strong>Q: Doctor capacity not working</strong></p>
<p>A: Verify max_appointments_per_day is set for the doctor (should default to 10):</p>
<pre>SELECT id, fullname, max_appointments_per_day FROM users WHERE role = 'doctor';</pre>

<hr>

<p style="text-align: center; color: #666; margin-top: 40px;">
    <small>Smart Appointment Scheduling System v1.0 | Last Updated: July 2026</small>
</p>

</body>
</html>
