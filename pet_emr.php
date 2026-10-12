<?php
// Forward pet_emr.php directly to pets.php
$query = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
header("Location: pets.php" . $query);
exit();
