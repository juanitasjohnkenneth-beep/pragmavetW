<?php
session_start();
$_SESSION['user_id'] = 6;
$_SESSION['user'] = 'Test User';
include('c:/xampp/htdocs/pragmavet/dbconnection.php');

$user_id = 6;
$uStmt = $conn->prepare("SELECT address FROM users WHERE id=?");
$uStmt->bind_param("i", $user_id);
$uStmt->execute();
$myAddr = $uStmt->get_result()->fetch_assoc()['address'] ?? '';

echo "=== myAddr value ===\n";
var_dump($myAddr);
echo "\n";

echo "=== json_encode output ===\n";
$encoded = json_encode($myAddr ? $myAddr : 'Registered Client');
echo $encoded . "\n";

echo "\n=== addslashes output ===\n";
echo addslashes($myAddr ? $myAddr : 'Registered Client') . "\n";

echo "\n=== Check for newlines/special chars ===\n";
echo "Has newline: " . (strpos($myAddr, "\n") !== false ? "YES" : "no") . "\n";
echo "Has carriage return: " . (strpos($myAddr, "\r") !== false ? "YES" : "no") . "\n";
echo "Has single quote: " . (strpos($myAddr, "'") !== false ? "YES" : "no") . "\n";
echo "Hex dump of first 50 chars: " . bin2hex(substr($myAddr, 0, 50)) . "\n";
?>
