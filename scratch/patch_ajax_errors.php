<?php
$file = 'c:/xampp/htdocs/pragmavet/appointments.php';
$content = file_get_contents($file);

// Replace header("Location: appointments.php"); with AJAX-aware response
$pattern = '/\$_SESSION\[\'message\'\] = (.*?);\s*\$_SESSION\[\'msg_type\'\] = "danger";\s*(?:error_log.*?;)?\s*header\("Location: appointments\.php"\);\s*exit\(\);/s';

$replacement = '$_SESSION[\'message\'] = $1;
        $_SESSION[\'msg_type\'] = "danger";
        if (!empty($_POST[\'return_appt_id\'])) {
            echo "APPT_ERROR:" . $1;
            exit();
        }
        header("Location: appointments.php");
        exit();';

$content = preg_replace($pattern, $replacement, $content);

// Also need to fix the JS to catch APPT_ERROR
$js_pattern = '/const idMatch = text\.match\(\/APPT_ID:\\(\\\\d\+\)\/\);\s*if \(idMatch && idMatch\[1\]\) \{[^\}]*\}\s*else\s*\{[^}]*window\.location\.reload\(\);\s*\}/s';

$js_replacement = 'const idMatch = text.match(/APPT_ID:(\d+)/);
                    const errorMatch = text.match(/APPT_ERROR:(.*)/);
                    if (idMatch && idMatch[1]) {
                        window._newApptId = idMatch[1];
                        showStep(6);
                    } else if (errorMatch && errorMatch[1]) {
                        alert("Booking Failed: " + errorMatch[1].replace(/<\/?[^>]+(>|$)/g, ""));
                        const btn = document.getElementById(\'submitBtn\');
                        if (btn) {
                            btn.disabled = false;
                            btn.innerHTML = \'Confirm Booking &gt;\';
                        }
                    } else {
                        alert("An unexpected error occurred. Please try again.");
                        window.location.reload();
                    }';

$content = preg_replace($js_pattern, $js_replacement, $content);

file_put_contents($file, $content);
echo "Fixed backend validation handling for AJAX requests.";
?>
