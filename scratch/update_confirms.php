<?php
$file = 'c:/xampp/htdocs/pragmavet/admin_users.php';
$content = file_get_contents($file);

// Replace confirm inside onsubmit
$content = preg_replace_callback(
    '/onsubmit="return confirm\(\'([^\']+)\'\);"/',
    function($matches) {
        $msg = $matches[1];
        return 'onsubmit="return confirmFormSubmit(event, this, \'' . $msg . '\');"';
    },
    $content
);

// Add unlock user confirm
$content = str_replace(
    '<form method="POST" class="d-inline">
                                                                <input type="hidden" name="unlock_user" value="1">',
    '<form method="POST" class="d-inline" onsubmit="return confirmFormSubmit(event, this, \'Unlock this user account?\');">
                                                                <input type="hidden" name="unlock_user" value="1">',
    $content
);

file_put_contents($file, $content);
echo "File updated successfully.";
?>
