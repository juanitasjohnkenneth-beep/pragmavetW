<?php
$file = 'c:/xampp/htdocs/pragmavet/appointments.php';
$content = file_get_contents($file);

$old_js = "fetch('appointments.php', { method: 'POST', body: fd })
                .then(async r => {
                    const text = await r.text();
                    // Try to extract appointment ID from response
                    const idMatch = text.match(/APPT_ID:(\d+)/);
                    if (idMatch && idMatch[1]) {
                        window._newApptId = idMatch[1];
                    }
                    showStep(6);
                })
                .catch(() => {
                    showStep(6);
                });";

$new_js = "fetch('appointments.php', { method: 'POST', body: fd })
                .then(async r => {
                    const text = await r.text();
                    // Try to extract appointment ID from response
                    const idMatch = text.match(/APPT_ID:(\d+)/);
                    if (idMatch && idMatch[1]) {
                        window._newApptId = idMatch[1];
                        showStep(6);
                    } else {
                        // Booking failed validation (or other error). 
                        // Reload to show the PHP session error message at the top of the page.
                        window.location.reload();
                    }
                })
                .catch(() => {
                    alert('Network error while booking. Please try again.');
                    window.location.reload();
                });";

$content = str_replace($old_js, $new_js, $content);
file_put_contents($file, $content);
echo "JS updated to handle failed bookings correctly.";
?>
