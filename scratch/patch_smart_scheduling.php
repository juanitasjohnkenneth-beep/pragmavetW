<?php
// Patch appointments.php with smart scheduling server-side additions
$file = __DIR__ . '/../appointments.php';
$content = file_get_contents($file);
if ($content === false) { die("Cannot read file\n"); }

// ──────────────────────────────────────────────────────────────────
// PATCH 1: Add holiday + Sunday check after same-day validation
// ──────────────────────────────────────────────────────────────────
$old1 = <<<'EOT'
    // 2. Get service duration from service_durations table
EOT;

$new1 = <<<'EOT'
    // 1b. Check if date falls on a public holiday
    if (isset($holiday_map[$date])) {
        $hol_fmt = date('F j, Y', strtotime($date));
        $_SESSION['message'] = "The clinic is closed on $hol_fmt ({$holiday_map[$date]}). Please choose another date.";
        $_SESSION['msg_type'] = "danger";
        header("Location: appointments.php");
        exit();
    }

    // 1c. Block Sundays server-side
    if (date('N', strtotime($date)) == 7) {
        $_SESSION['message'] = "The clinic is closed on Sundays. Please select a weekday.";
        $_SESSION['msg_type'] = "danger";
        header("Location: appointments.php");
        exit();
    }

    // 2. Get service duration from service_durations table
EOT;

if (strpos($content, $old1) !== false) {
    $content = str_replace($old1, $new1, $content);
    echo "PATCH 1 applied OK\n";
} else {
    echo "PATCH 1 - target not found\n";
}

// ──────────────────────────────────────────────────────────────────
// PATCH 2: Replace hardcoded schedule with DB-driven + break check
// ──────────────────────────────────────────────────────────────────
$old2 = <<<'EOT'
        $doc_start_time = '08:00:00';
        $doc_end_time = '17:00:00';
        $buffer_mins = 15;
        $max_appts = 10;
        
        // 3c. Validate time is within working hours
        $slot_time = strtotime($time);
        $slot_end = strtotime($appointment_end_time);
        $working_start = strtotime($doc_start_time);
        $working_end = strtotime($doc_end_time);
        
        if ($slot_time < $working_start || $slot_end > $working_end) {
EOT;

$new2 = <<<'EOT'
        // 3b. Get doctor schedule from DB (fall back to clinic defaults)
        $sched            = $doctor_sched_map[$doctor_id] ?? null;
        $doc_start_time   = $sched ? ($sched['work_start']  . ':00') : '08:00:00';
        $doc_end_time     = $sched ? ($sched['work_end']    . ':00') : '17:00:00';
        $break_start_time = ($sched && !empty($sched['break_start'])) ? ($sched['break_start'] . ':00') : '12:00:00';
        $break_end_time   = ($sched && !empty($sched['break_end']))   ? ($sched['break_end']   . ':00') : '13:00:00';
        $buffer_mins      = $sched ? (int)$sched['buffer_mins']       : 15;
        $max_appts        = $sched ? (int)$sched['max_appts_per_day'] : 10;

        // 3c. Validate time is within working hours
        $slot_time     = strtotime($time);
        $slot_end      = strtotime($appointment_end_time);
        $working_start = strtotime($doc_start_time);
        $working_end   = strtotime($doc_end_time);

        if ($slot_time < $working_start || $slot_end > $working_end) {
EOT;

if (strpos($content, $old2) !== false) {
    $content = str_replace($old2, $new2, $content);
    echo "PATCH 2 applied OK\n";
} else {
    echo "PATCH 2 - target not found\n";
}

// ──────────────────────────────────────────────────────────────────
// PATCH 3: Add break-time check after working-hours check
// ──────────────────────────────────────────────────────────────────
$old3 = <<<'EOT'
        // 3d. Check for slot conflicts with existing appointments (prevent double-booking)
EOT;

$new3 = <<<'EOT'
        // 3c-2. Validate slot does not overlap the doctor's break time
        $break_s = strtotime($break_start_time);
        $break_e = strtotime($break_end_time);
        if (!($slot_end <= $break_s || $slot_time >= $break_e)) {
            $_SESSION['message'] = "This time slot overlaps the doctor's break ("
                . substr($break_start_time, 0, 5) . " - " . substr($break_end_time, 0, 5)
                . "). Please choose a slot before or after the break.";
            $_SESSION['msg_type'] = "danger";
            header("Location: appointments.php");
            exit();
        }

        // 3d. Check for slot conflicts with existing appointments (prevent double-booking)
EOT;

if (strpos($content, $old3) !== false) {
    $content = str_replace($old3, $new3, $content);
    echo "PATCH 3 applied OK\n";
} else {
    echo "PATCH 3 - target not found\n";
}

file_put_contents($file, $content);
echo "Done. File written.\n";
