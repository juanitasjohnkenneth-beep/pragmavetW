<?php
$file = 'c:/xampp/htdocs/pragmavet/admin_list.php';
$content = file_get_contents($file);

// Replace the complex action column with just an eye icon
$action_start = '                <!-- ACTION COLUMN: UNIFIED PRIMARY BUTTON + DROPDOWN -->';
$action_end   = '                </td>';

$start_pos = strpos($content, $action_start);
$end_pos   = strpos($content, $action_end, $start_pos) + strlen($action_end);

if ($start_pos !== false && $end_pos !== false) {
    $new_action_col = <<<HTML
                <!-- ACTION COLUMN: EYE ICON ONLY -->
                <td onclick="event.stopPropagation();" class="text-center">
                    <button type="button" class="btn btn-sm btn-outline-primary rounded-circle" style="width:32px;height:32px;padding:0;" title="View Details" onclick="openModal(<?php echo \$rowData; ?>); return false;">
                        <i class="fa fa-eye"></i>
                    </button>
                </td>
HTML;
    $content = substr($content, 0, $start_pos) . $new_action_col . substr($content, $end_pos);
}

// Remove or hide the admin actions inside the modal
$modal_actions_start = '            <!-- Admin actions form -->';
$modal_actions_end   = '            </div>';

// Let's just remove the form actions entirely from the modal HTML
$form_start = '<form id="dm-form" method="POST" action="">';
$form_end   = '</form>';

$f_start_pos = strpos($content, $form_start);
$f_end_pos   = strpos($content, $form_end, $f_start_pos) + strlen($form_end);

if ($f_start_pos !== false && $f_end_pos !== false) {
    // Replace the form with just a hidden field for the ID (just in case JS needs it, though we don't need it)
    $content = substr($content, 0, $f_start_pos) . '<!-- Administrative actions removed (view only) -->' . substr($content, $f_end_pos);
}

// And also remove the Archive button from the modal footer
$archive_btn_str = '<button class="btn-act" style="background:#475569;color:#fff;" id="dm-archive-btn" onclick="archiveFromModal()" style="display:none;"><i class="fa fa-archive me-1"></i> Archive</button>';
$content = str_replace($archive_btn_str, '', $content);

file_put_contents($file, $content);
echo "Changes applied.";
?>
