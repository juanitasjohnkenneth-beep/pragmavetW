<?php
$file = 'c:/xampp/htdocs/pragmavet/appointments.php';
$content = file_get_contents($file);

// ============================================================
// APPROACH: Store rowData in a data-row attribute (JSON),
// use a simple onclick="openRow(this)" that reads it cleanly.
// This bypasses ALL escaping issues entirely.
// ============================================================

// 1. Change $rowData encoding - no HEX flags needed, just clean JSON
$old_rowData = '$rowData = json_encode($row, JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP);';
$new_rowData = '$rowData = htmlspecialchars(json_encode($row), ENT_QUOTES, "UTF-8");';
$content = str_replace($old_rowData, $new_rowData, $content);

// 2. Change TR onclick
$old_tr_onclick = 'onclick="openDetailModal(<?php echo htmlspecialchars($rowData, ENT_QUOTES); ?>)"';
$new_tr_onclick = 'data-row="<?php echo $rowData; ?>" onclick="openRow(this)"';
$content = str_replace($old_tr_onclick, $new_tr_onclick, $content);

// 3. Change all button onclicks (4 of them)
$old_btn_onclick = 'onclick="event.stopPropagation(); openDetailModal(<?php echo htmlspecialchars($rowData, ENT_QUOTES); ?>)"';
$new_btn_onclick = 'onclick="event.stopPropagation(); openRow(this.closest(\'tr\'))"';
$content = str_replace($old_btn_onclick, $new_btn_onclick, $content);

// 4. Add a small helper function before openDetailModal
$old_fn = '    function openDetailModal(row) {';
$new_fn = '    function openRow(el) {
        try {
            var raw = el.getAttribute("data-row");
            if (!raw) el = el.closest("[data-row]");
            raw = el ? el.getAttribute("data-row") : null;
            if (!raw) return;
            openDetailModal(JSON.parse(raw));
        } catch(e) { console.error("openRow parse error:", e); }
    }

    function openDetailModal(row) {';
$content = str_replace($old_fn, $new_fn, $content);

file_put_contents($file, $content);
echo "Patched successfully.\n";

// Verify
$count_old = substr_count($content, 'htmlspecialchars($rowData');
echo "Remaining htmlspecialchars(\$rowData calls: $count_old\n";
$count_new = substr_count($content, 'data-row=');
echo "New data-row attributes: $count_new\n";
?>
