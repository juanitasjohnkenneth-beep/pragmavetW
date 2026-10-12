<?php
$file = 'c:/xampp/htdocs/pragmavet/boarding.php';
$content = file_get_contents($file);

// Add the JSON payload to the JavaScript block
$search_js_start = "// Flatpickr & Live Price Calculation for Booking Modal";
$replace_js_start = "const flatpickrDisable = " . "<?= json_encode(\$flatpickr_disable) ?>;\n\n// Flatpickr & Live Price Calculation for Booking Modal";

$content = str_replace($search_js_start, $replace_js_start, $content);

// Update the modal show event to apply the disabled dates
$search_modal_event = <<<JS
            document.getElementById('modalCageId').value = cageNum;
            document.getElementById('displayCageTitle').innerText = 'Cage Suite #' + String(cageNum).padStart(2, '0');
        });
JS;

$replace_modal_event = <<<JS
            document.getElementById('modalCageId').value = cageNum;
            document.getElementById('displayCageTitle').innerText = 'Cage Suite #' + String(cageNum).padStart(2, '0');
            
            // Apply disabled dates for this specific cage
            const disabledDates = flatpickrDisable[cageNum] || [];
            fpIn.set('disable', disabledDates);
            fpOut.set('disable', disabledDates);
            
            // Reset dates when switching cages
            fpIn.clear();
            fpOut.clear();
            document.getElementById('boardingTotalCalc').innerText = '₱0.00';
            document.getElementById('totalAmountInput').value = '0';
        });
JS;

$content = str_replace($search_modal_event, $replace_modal_event, $content);

file_put_contents($file, $content);
echo "Boarding availability patched in client UI.";
?>
