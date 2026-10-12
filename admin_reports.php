<?php
// The Reports Center was merged into the Admin Dashboard (admin.php): descriptive analytics, printing lives there.
// Old links and bookmarks are forwarded with their period (and export) choices.
session_start();
$keep = array_intersect_key($_GET, array_flip(['period', 'start_date', 'end_date']));
header('Location: admin.php' . ($keep ? '?' . http_build_query($keep) : ''));
exit();
