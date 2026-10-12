<?php
$file = 'c:/xampp/htdocs/pragmavet/appointments.php';
$content = file_get_contents($file);

// 1. Fix the final else block for booking
$old_block = '      } else {
          if (strpos($conn->error, \'Duplicate entry\') !== false || strpos($conn->error, \'unique\') !== false) {
              $_SESSION[\'message\'] = "AA?\' This time slot was just taken. Please refresh and choose another time.";
          } else {
              $_SESSION[\'message\'] = "AA?\' Error booking appointment: " . htmlspecialchars($conn->error);
          }
          $_SESSION[\'msg_type\'] = "danger";
      }
      header("Location: appointments.php");
      exit();
  }
  
  // A??,A??, POST: Cancel pending';

$new_block = '      } else {
          if (strpos($conn->error, \'Duplicate entry\') !== false || strpos($conn->error, \'unique\') !== false) {
              $_SESSION[\'message\'] = "AA?\' This time slot was just taken. Please refresh and choose another time.";
          } else {
              $_SESSION[\'message\'] = "AA?\' Error booking appointment: " . htmlspecialchars($conn->error);
          }
          $_SESSION[\'msg_type\'] = "danger";
          
          if (!empty($_POST[\'return_appt_id\'])) {
              echo "APPT_ERROR:" . $_SESSION[\'message\'];
              exit();
          }
      }
      header("Location: appointments.php");
      exit();
  }
  
  // A??,A??, POST: Cancel pending';

$content = str_replace($old_block, $new_block, $content);

// 2. Fix the broken reschedule artifact
$bad_resched = '          $_SESSION[\'msg_type\'] = "danger";
        if (!empty($_POST[\'return_appt_id\'])) {
            echo "APPT_ERROR:" . "A"? Appointment for <b>$msg_pet</b> booked successfully! We look forward to seeing you.";
        $_SESSION[\'msg_type\'] = "success";
        
        // Return appointment ID to JS for payment step
        if (!empty($_POST[\'return_appt_id\'])) {
            echo "APPT_ID:" . $appointment_id;
            exit();
        }
    } else {
        if (strpos($conn->error, \'Duplicate entry\') !== false || strpos($conn->error, \'unique\') !== false) {
            $_SESSION[\'message\'] = "AA?\' This time slot was just taken. Please refresh and choose another time.";
        } else {
            $_SESSION[\'message\'] = "AA?\' Error booking appointment: " . htmlspecialchars($conn->error);
        }
        $_SESSION[\'msg_type\'] = "danger";
    }
    header("Location: appointments.php");
    exit();
}';
// Actually, it's easier to just strip APPT_ERROR out of the reschedule block
$content = preg_replace('/(if \(\$new_date < \$tomorrow\) \{.*?)\$_SESSION\[\'msg_type\'\] = "danger";\s*if \(\!empty\(\$_POST\[\'return_appt_id\'\]\)\) \{.*?APPT_ERROR.*?exit\(\);\s*\}/s', '$1$_SESSION[\'msg_type\'] = "danger"; header("Location: appointments.php"); exit();', $content);

file_put_contents($file, $content);
echo "Done.";
?>
