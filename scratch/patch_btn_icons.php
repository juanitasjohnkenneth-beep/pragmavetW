<?php
$file = 'c:/xampp/htdocs/pragmavet/appointments.php';
$content = file_get_contents($file);

// Replace the action buttons with icon-only buttons
$old_buttons = '                                        <div class="d-inline-flex gap-1">
                                            <?php if (isset($row[\'client_action_needed\']) && $row[\'client_action_needed\'] == 1 && !str_contains($status, \'Cancel\') && !str_contains($status, \'Reject\')): ?>
                                                <button type="button" class="btn btn-sm btn-warning rounded-pill fw-bold" onclick="event.stopPropagation(); openDetailModal(<?php echo htmlspecialchars($rowData, ENT_QUOTES); ?>)">
                                                    <i class="fa fa-exclamation-circle me-1"></i> Review Proposal
                                                </button>
                                            <?php elseif ($status === \'Pending\' || $status === \'Approved\'): ?>
                                                <button type="button" class="btn btn-sm btn-outline-primary rounded-pill fw-semibold" onclick="event.stopPropagation(); openDetailModal(<?php echo htmlspecialchars($rowData, ENT_QUOTES); ?>)">
                                                    <i class="fa fa-eye me-1"></i> Manage
                                                </button>
                                            <?php elseif ($status === \'Completed\'): ?>
                                                <button type="button" class="btn btn-sm btn-outline-success rounded-pill fw-semibold" onclick="event.stopPropagation(); openDetailModal(<?php echo htmlspecialchars($rowData, ENT_QUOTES); ?>)">
                                                    <i class="fa fa-receipt me-1"></i> Details
                                                </button>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill" onclick="event.stopPropagation(); openDetailModal(<?php echo htmlspecialchars($rowData, ENT_QUOTES); ?>)">
                                                    <i class="fa fa-info-circle me-1"></i> View
                                                </button>
                                            <?php endif; ?>
                                        </div>';

$new_buttons = '                                        <div class="d-inline-flex gap-1">
                                            <?php if (isset($row[\'client_action_needed\']) && $row[\'client_action_needed\'] == 1 && !str_contains($status, \'Cancel\') && !str_contains($status, \'Reject\')): ?>
                                                <button type="button" class="btn btn-warning rounded-circle d-flex align-items-center justify-content-center" style="width:36px;height:36px;padding:0;box-shadow:0 2px 4px rgba(0,0,0,0.1);" title="Review Proposal" onclick="event.stopPropagation(); openDetailModal(<?php echo htmlspecialchars($rowData, ENT_QUOTES); ?>)">
                                                    <i class="fa fa-exclamation-circle text-dark"></i>
                                                </button>
                                            <?php elseif ($status === \'Pending\' || $status === \'Approved\'): ?>
                                                <button type="button" class="btn btn-primary rounded-circle d-flex align-items-center justify-content-center" style="width:36px;height:36px;padding:0;background:#2563eb;border:none;box-shadow:0 2px 4px rgba(37,99,235,0.2);" title="View Details" onclick="event.stopPropagation(); openDetailModal(<?php echo htmlspecialchars($rowData, ENT_QUOTES); ?>)">
                                                    <i class="fa fa-eye text-white"></i>
                                                </button>
                                            <?php elseif ($status === \'Completed\'): ?>
                                                <button type="button" class="btn btn-success rounded-circle d-flex align-items-center justify-content-center" style="width:36px;height:36px;padding:0;background:#10b981;border:none;box-shadow:0 2px 4px rgba(16,185,129,0.2);" title="View Receipt" onclick="event.stopPropagation(); openDetailModal(<?php echo htmlspecialchars($rowData, ENT_QUOTES); ?>)">
                                                    <i class="fa fa-eye text-white"></i>
                                                </button>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-secondary rounded-circle d-flex align-items-center justify-content-center" style="width:36px;height:36px;padding:0;background:#94a3b8;border:none;box-shadow:0 2px 4px rgba(148,163,184,0.2);" title="View Details" onclick="event.stopPropagation(); openDetailModal(<?php echo htmlspecialchars($rowData, ENT_QUOTES); ?>)">
                                                    <i class="fa fa-eye text-white"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>';

$content = str_replace($old_buttons, $new_buttons, $content);

file_put_contents($file, $content);
echo "Action buttons updated to icon-only.";
?>
