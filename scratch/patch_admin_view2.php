<?php
$file = 'c:/xampp/htdocs/pragmavet/admin_list.php';
$content = file_get_contents($file);

// 1. Hide the entire action block via CSS
$content = str_replace(
    '<div id="dm-actions" style="border-top:1px solid #f1f5f9;padding-top:16px;margin-top:4px;display:none;">',
    '<div id="dm-actions" style="display:none !important;">',
    $content
);

// 2. Disable JS trying to change their display status so they remain hidden forever
$content = preg_replace("/actW\.style\.display\s*=\s*.*?;/i", "/* actW display hidden */", $content);
$content = preg_replace("/approveW\.style\.display\s*=\s*.*?;/i", "/* approveW display hidden */", $content);
$content = preg_replace("/completeW\.style\.display\s*=\s*.*?;/i", "/* completeW display hidden */", $content);
$content = preg_replace("/msgW\.style\.display\s*=\s*.*?;/i", "/* msgW display hidden */", $content);

// 3. Just to be totally sure, let's also make client reschedule block hidden permanently
$content = preg_replace("/reschedW\.style\.display\s*=\s*'block';/i", "reschedW.style.display = 'none';", $content);

file_put_contents($file, $content);
echo "JS and CSS patched to enforce view-only mode.";
?>
