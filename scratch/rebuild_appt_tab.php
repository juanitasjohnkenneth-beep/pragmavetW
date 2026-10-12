<?php
$file = 'c:/xampp/htdocs/pragmavet/staff_dashboard.php';
$content = file_get_contents($file);

// Find the start and end markers
$start_marker = '<!-- ── TAB 2: APPOINTMENT REQUESTS TABLE ────────────────────────────────── -->';
$end_marker   = '<?php endif; ?>' . "\n" . "\n" . '    <!-- ── TAB 3:';

$start_pos = strpos($content, $start_marker);
if ($start_pos === false) {
    die("ERROR: Start marker not found");
}

// Find the end — we want to keep the TAB 3 comment
$end_pos = strpos($content, '    <!-- ── TAB 3:', $start_pos);
if ($end_pos === false) {
    die("ERROR: End marker not found");
}

// Build new appointment tab section
$new_section = <<<'PHPEOF'
<!-- ── TAB 2: APPOINTMENT REQUESTS TABLE ────────────────────────────────── -->
    <?php if ($active_tab === 'appointments'): ?>
        <div class="content-panel">
            <div class="panel-header">
                <div class="panel-title">
                    <i class="fa fa-calendar-check text-primary"></i> Master Appointment Requests &amp; Management
                </div>
                <div class="d-flex gap-2">
                    <a href="staff_dashboard.php?tab=appointments&filter=pending" class="btn btn-sm btn-outline-warning fw-semibold">Pending Only</a>
                    <a href="staff_dashboard.php?tab=appointments&filter=all" class="btn btn-sm btn-outline-secondary fw-semibold">View All</a>
                </div>
            </div>

            <?php
            $filter_sql = ($_GET['filter'] ?? 'pending') === 'all' ? "1=1" : "a.status = 'Pending'";
            $all_appts = $conn->query("
                SELECT a.*, u.fullname as owner_name, u.contact_number, u.email as owner_email, d.fullname as doctor_name
                FROM appointments a
                LEFT JOIN users u ON a.user_id = u.id
                LEFT JOIN users d ON a.doctor_id = d.id
                WHERE $filter_sql AND a.is_archived = 0
                ORDER BY a.appointment_date DESC, a.appointment_time ASC
                LIMIT 100
            ");
            ?>

            <div class="table-responsive">
                <table class="table table-custom">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Date &amp; Time</th>
                            <th>Patient / Pet</th>
                            <th>Owner</th>
                            <th>Service</th>
                            <th>Doctor</th>
                            <th>Status</th>
                            <th class="text-center">Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($all_appts && $all_appts->num_rows > 0): ?>
                            <?php while ($apt = $all_appts->fetch_assoc()):
                                $s = $apt['status'];
                                if ($s === 'Approved')    $sc = 'status-approved';
                                elseif ($s === 'Pending') $sc = 'status-pending';
                                elseif ($s === 'Checked In') $sc = 'status-checked-in';
                                elseif ($s === 'In Progress') $sc = 'status-in-progress';
                                elseif ($s === 'Completed') $sc = 'status-completed';
                                else $sc = 'status-cancelled';
                            ?>
                                <tr>
                                    <td><span class="text-muted fw-bold">#<?= $apt['id'] ?></span></td>
                                    <td>
                                        <div class="fw-bold"><?= date('M d, Y', strtotime($apt['appointment_date'])) ?></div>
                                        <small class="text-muted"><?= date('h:i A', strtotime($apt['appointment_time'])) ?></small>
                                    </td>
                                    <td><span class="fw-bold text-primary"><?= htmlspecialchars($apt['pet_name']) ?></span></td>
                                    <td>
                                        <div><?= htmlspecialchars($apt['owner_name'] ?? 'N/A') ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($apt['contact_number'] ?? '') ?></small>
                                    </td>
                                    <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($apt['service_type']) ?></span></td>
                                    <td><small class="text-muted"><?= htmlspecialchars($apt['doctor_name'] ?? 'Unassigned') ?></small></td>
                                    <td><span class="badge-status <?= $sc ?>"><?= htmlspecialchars($s) ?></span></td>
                                    <td class="text-center">
                                        <button type="button"
                                            class="btn btn-sm btn-outline-primary rounded-circle"
                                            style="width:32px;height:32px;padding:0;"
                                            title="View Details"
                                            data-bs-toggle="modal"
                                            data-bs-target="#apptDetailModal<?= $apt['id'] ?>">
                                            <i class="fa fa-eye"></i>
                                        </button>
                                    </td>
                                </tr>

                                <!-- APPOINTMENT DETAIL MODAL -->
                                <div class="modal fade" id="apptDetailModal<?= $apt['id'] ?>" tabindex="-1" aria-hidden="true">
                                    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                                        <div class="modal-content border-0 shadow-lg" style="border-radius:18px;overflow:hidden;">
                                            <div class="modal-header text-white border-0" style="background:linear-gradient(135deg,#1e3a8a,#2563eb);">
                                                <div>
                                                    <h5 class="fw-bold mb-0"><i class="fa fa-calendar-check me-2"></i>Appointment #<?= $apt['id'] ?></h5>
                                                    <small class="opacity-75"><?= htmlspecialchars($s) ?> &mdash; <?= date('M d, Y', strtotime($apt['appointment_date'])) ?> at <?= date('h:i A', strtotime($apt['appointment_time'])) ?></small>
                                                </div>
                                                <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body p-4">

                                                <!-- Info Cards -->
                                                <div class="row g-3 mb-4">
                                                    <div class="col-md-6">
                                                        <div class="p-3 rounded-3 bg-light border h-100">
                                                            <p class="text-uppercase fw-bold text-muted mb-2" style="font-size:10px;"><i class="fa fa-paw me-1"></i> Patient</p>
                                                            <p class="fw-bold text-primary mb-1" style="font-size:15px;"><?= htmlspecialchars($apt['pet_name']) ?></p>
                                                            <p class="mb-0 text-muted small"><?= htmlspecialchars($apt['service_type']) ?></p>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div class="p-3 rounded-3 bg-light border h-100">
                                                            <p class="text-uppercase fw-bold text-muted mb-2" style="font-size:10px;"><i class="fa fa-user me-1"></i> Owner / Client</p>
                                                            <p class="fw-bold mb-1"><?= htmlspecialchars($apt['owner_name'] ?? 'N/A') ?></p>
                                                            <p class="mb-0 text-muted small"><?= htmlspecialchars($apt['owner_email'] ?? '') ?></p>
                                                            <p class="mb-0 text-muted small"><?= htmlspecialchars($apt['contact_number'] ?? '') ?></p>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <div class="p-3 rounded-3 bg-light border">
                                                            <p class="text-uppercase fw-bold text-muted mb-1" style="font-size:10px;"><i class="fa fa-calendar me-1"></i> Date &amp; Time</p>
                                                            <p class="fw-bold mb-0"><?= date('M d, Y', strtotime($apt['appointment_date'])) ?></p>
                                                            <p class="text-muted mb-0 small"><?= date('h:i A', strtotime($apt['appointment_time'])) ?></p>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <div class="p-3 rounded-3 bg-light border">
                                                            <p class="text-uppercase fw-bold text-muted mb-1" style="font-size:10px;"><i class="fa fa-user-md me-1"></i> Doctor</p>
                                                            <p class="fw-bold mb-0"><?= htmlspecialchars($apt['doctor_name'] ?? 'Unassigned') ?></p>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <div class="p-3 rounded-3 bg-light border">
                                                            <p class="text-uppercase fw-bold text-muted mb-1" style="font-size:10px;"><i class="fa fa-info-circle me-1"></i> Status</p>
                                                            <span class="badge-status <?= $sc ?>"><?= htmlspecialchars($s) ?></span>
                                                        </div>
                                                    </div>
                                                    <?php if (!empty($apt['notes'])): ?>
                                                    <div class="col-12">
                                                        <div class="p-3 rounded-3 border" style="background:#fefce8;">
                                                            <p class="text-uppercase fw-bold text-muted mb-1" style="font-size:10px;"><i class="fa fa-sticky-note me-1"></i> Client Notes</p>
                                                            <p class="mb-0"><?= nl2br(htmlspecialchars($apt['notes'])) ?></p>
                                                        </div>
                                                    </div>
                                                    <?php endif; ?>
                                                    <?php if (!empty($apt['admin_message'])): ?>
                                                    <div class="col-12">
                                                        <div class="p-3 rounded-3 border" style="background:#f0f9ff;">
                                                            <p class="text-uppercase fw-bold text-muted mb-1" style="font-size:10px;"><i class="fa fa-history me-1"></i> Audit Trail</p>
                                                            <p class="mb-0 small text-secondary"><?= nl2br(htmlspecialchars($apt['admin_message'])) ?></p>
                                                        </div>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>

                                                <hr class="my-3">
                                                <p class="fw-bold text-uppercase text-muted mb-3" style="font-size:11px;"><i class="fa fa-bolt me-1"></i> Actions</p>

                                                <div class="d-flex flex-column gap-2">

                                                    <?php if ($s === 'Pending'): ?>
                                                        <form method="POST">
                                                            <input type="hidden" name="action" value="accept_appointment">
                                                            <input type="hidden" name="apt_id" value="<?= $apt['id'] ?>">
                                                            <input type="hidden" name="redirect_tab" value="appointments">
                                                            <button type="submit" class="btn btn-success w-100 fw-bold py-2">
                                                                <i class="fa fa-check-circle me-2"></i> Accept Appointment
                                                            </button>
                                                        </form>
                                                        <form method="POST">
                                                            <input type="hidden" name="action" value="reject_appointment">
                                                            <input type="hidden" name="apt_id" value="<?= $apt['id'] ?>">
                                                            <input type="hidden" name="redirect_tab" value="appointments">
                                                            <div class="input-group">
                                                                <input type="text" name="reject_reason" class="form-control" placeholder="Reason for rejecting..." required>
                                                                <button type="submit" class="btn btn-danger fw-bold"><i class="fa fa-times-circle me-1"></i> Reject</button>
                                                            </div>
                                                        </form>

                                                    <?php elseif ($s === 'Approved'): ?>
                                                        <form method="POST">
                                                            <input type="hidden" name="action" value="update_status">
                                                            <input type="hidden" name="apt_id" value="<?= $apt['id'] ?>">
                                                            <input type="hidden" name="new_status" value="Checked In">
                                                            <input type="hidden" name="redirect_tab" value="appointments">
                                                            <button type="submit" class="btn btn-primary w-100 fw-bold py-2">
                                                                <i class="fa fa-door-open me-2"></i> Check In Patient (Arrived)
                                                            </button>
                                                        </form>

                                                    <?php elseif ($s === 'Checked In'): ?>
                                                        <form method="POST">
                                                            <input type="hidden" name="action" value="update_status">
                                                            <input type="hidden" name="apt_id" value="<?= $apt['id'] ?>">
                                                            <input type="hidden" name="new_status" value="In Progress">
                                                            <input type="hidden" name="redirect_tab" value="appointments">
                                                            <button type="submit" class="btn btn-info text-white w-100 fw-bold py-2">
                                                                <i class="fa fa-stethoscope me-2"></i> Send to Exam Room (In Consultation)
                                                            </button>
                                                        </form>

                                                    <?php elseif ($s === 'In Progress'): ?>
                                                        <form method="POST">
                                                            <input type="hidden" name="action" value="update_status">
                                                            <input type="hidden" name="apt_id" value="<?= $apt['id'] ?>">
                                                            <input type="hidden" name="new_status" value="Completed">
                                                            <input type="hidden" name="redirect_tab" value="appointments">
                                                            <div class="p-3 mb-3 rounded-3 border" style="background:#f8fafc;">
                                                                <label class="fw-bold mb-2 text-primary" style="font-size:12px;">
                                                                    <i class="fa fa-pills me-1"></i> Prescribe Medicine / Deduct Inventory (Optional)
                                                                </label>
                                                                <select name="inv_id" class="form-select form-select-sm mb-2"
                                                                    onchange="var o=this.options[this.selectedIndex];document.getElementById('inv_price_<?= $apt['id'] ?>').value=o.getAttribute('data-price')||'';document.getElementById('inv_qty_<?= $apt['id'] ?>').value=this.value?1:'';">
                                                                    <option value="">-- No Medicine --</option>
                                                                    <?php foreach ($inventory_items as $inv): ?>
                                                                        <option value="<?= $inv['id'] ?>" data-price="<?= $inv['price_per_unit'] ?>">
                                                                            <?= htmlspecialchars($inv['item_name']) ?> (Stock: <?= $inv['quantity'] ?>)
                                                                        </option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                                <div class="row g-2">
                                                                    <div class="col-6">
                                                                        <label class="text-muted fw-bold" style="font-size:10px;">Quantity Used</label>
                                                                        <input type="number" name="inv_qty" id="inv_qty_<?= $apt['id'] ?>" class="form-control form-control-sm" min="1" placeholder="0">
                                                                    </div>
                                                                    <div class="col-6">
                                                                        <label class="text-muted fw-bold" style="font-size:10px;">Price per Unit (&#8369;)</label>
                                                                        <input type="number" name="inv_price" id="inv_price_<?= $apt['id'] ?>" class="form-control form-control-sm" step="0.01" placeholder="0.00">
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            <button type="submit" class="btn btn-success w-100 fw-bold py-2">
                                                                <i class="fa fa-flag-checkered me-2"></i> Mark Consultation Completed
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>

                                                    <?php if (!in_array($s, ['Completed', 'Cancelled', 'Rejected', 'No Show'])): ?>
                                                        <form method="POST" class="border rounded-3 p-3 mt-1" style="background:#fff8f0;">
                                                            <input type="hidden" name="action" value="reschedule_appointment">
                                                            <input type="hidden" name="apt_id" value="<?= $apt['id'] ?>">
                                                            <input type="hidden" name="redirect_tab" value="appointments">
                                                            <label class="fw-bold text-warning mb-2 d-block" style="font-size:11px;"><i class="fa fa-calendar-alt me-1"></i> Reschedule Appointment</label>
                                                            <div class="row g-2 mb-2">
                                                                <div class="col-6">
                                                                    <input type="date" name="new_date" class="form-control form-control-sm" min="<?= date('Y-m-d') ?>" value="<?= $apt['appointment_date'] ?>" required>
                                                                </div>
                                                                <div class="col-6">
                                                                    <input type="time" name="new_time" class="form-control form-control-sm" value="<?= $apt['appointment_time'] ?>" required>
                                                                </div>
                                                            </div>
                                                            <button type="submit" class="btn btn-warning w-100 btn-sm fw-bold">
                                                                <i class="fa fa-sync me-1"></i> Reschedule &amp; Notify Client
                                                            </button>
                                                        </form>
                                                        <form method="POST" onsubmit="return confirm('Mark this patient as No Show?');">
                                                            <input type="hidden" name="action" value="update_status">
                                                            <input type="hidden" name="apt_id" value="<?= $apt['id'] ?>">
                                                            <input type="hidden" name="new_status" value="No Show">
                                                            <input type="hidden" name="redirect_tab" value="appointments">
                                                            <button type="submit" class="btn btn-outline-secondary w-100 btn-sm">
                                                                <i class="fa fa-user-slash me-1"></i> Mark as No Show
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>

                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="8" class="text-center py-4 text-muted">No appointments found matching filter.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    PHPEOF;

// Remove trailing whitespace marker so we can append TAB 3
$new_section = rtrim($new_section) . "\n\n    ";

$content = substr($content, 0, $start_pos) . $new_section . substr($content, $end_pos);
file_put_contents($file, $content);
echo "Done. Lines replaced.";
?>
