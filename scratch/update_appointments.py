file_path = r"c:\xampp\htdocs\pragmavet\appointments.php"

with open(file_path, "r", encoding="utf-8", errors="ignore") as f:
    content = f.read()

# Target area: between <div class="main-content"> and <!-- FEEDBACK MODAL -->
start_str = '<div class="main-content">'
end_str = '<!-- FEEDBACK MODAL -->'

start_pos = content.find(start_str)
end_pos = content.find(end_str)

if start_pos != -1 and end_pos != -1:
    replacement = '''<div class="main-content" style="margin-left:250px;padding:24px 36px 60px;">

        <!-- Header & View Tabs -->
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <h1 style="font-size:24px;font-weight:800;color:#1e1b4b;margin:0;">New Appointment Wizard</h1>
                <div style="font-size:13px;color:#64748b;" id="bCrumbText">
                    <a href="appointments.php" style="color:#5b21b6;text-decoration:none;font-weight:600;">Appointment</a> &gt; <span>New Appointment</span>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="button" class="btn rounded-pill px-4 fw-bold" id="btnTabWiz" onclick="switchApptView('wizard')" style="background:#5b21b6;color:#fff;font-size:13px;box-shadow:0 4px 14px rgba(91,33,182,0.3);">
                    <i class="fa fa-magic me-2"></i>New Appointment Wizard
                </button>
                <button type="button" class="btn btn-outline-secondary rounded-pill px-4 fw-bold" id="btnTabList" onclick="switchApptView('list')" style="font-size:13px;">
                    <i class="fa fa-list me-2"></i>Your Appointments (<?php echo count($apptRows); ?>)
                </button>
            </div>
        </div>

        <!-- ════════════════════════════════════════════════════════════════════
             VIEW 1: NEW APPOINTMENT WIZARD (DEFAULT MATCHING SCREENSHOT)
        ════════════════════════════════════════════════════════════════════════ -->
        <div id="viewWizardContainer">

            <!-- 7-STEP HORIZONTAL TIMELINE STEPPER -->
            <div style="display:flex;align-items:flex-start;justify-content:space-between;position:relative;margin-bottom:36px;padding:0 10px;">
                <div style="position:absolute;top:18px;left:40px;right:40px;height:2px;border-top:2px dashed #cbd5e1;z-index:0;"></div>
                <?php
                $step_names = [
                    1 => 'Select Client & Pet',
                    2 => 'Select Service',
                    3 => 'Select Veterinarian',
                    4 => 'Date & Time',
                    5 => 'Appointment Details',
                    6 => 'Review & Confirm',
                    7 => 'Appointment Created'
                ];
                for ($i = 1; $i <= 7; $i++):
                ?>
                <div class="step-node <?=$i===2?'active':''?>" id="step-node-<?=$i?>" onclick="jumpToStep(<?=$i?>)" style="display:flex;flex-direction:column;align-items:center;position:relative;z-index:1;cursor:pointer;flex:1;">
                    <div class="step-circle" style="width:36px;height:36px;border-radius:50%;background:#fff;border:2px solid #cbd5e1;color:#64748b;font-weight:700;font-size:14px;display:flex;align-items:center;justify-content:center;transition:all .3s;"><?=$i?></div>
                    <div class="step-node-label" style="font-size:11px;font-weight:600;color:#64748b;margin-top:8px;text-align:center;white-space:nowrap;"><?=$step_names[$i]?></div>
                </div>
                <?php endfor; ?>
            </div>

            <!-- MAIN WIZARD GRID (70% Left Panel, 30% Right Panel) -->
            <div style="display:grid;grid-template-columns:1fr 340px;gap:28px;align-items:start;">
                
                <!-- LEFT PANEL CARD -->
                <div style="background:#fff;border-radius:20px;border:1px solid #e2e8f0;padding:32px;box-shadow:0 4px 20px rgba(0,0,0,0.03);">

                    <!-- STEP 1: Select Client & Pet -->
                    <div class="wiz-panel" id="panel-1" style="display:none;">
                        <div style="display:flex;align-items:center;gap:14px;margin-bottom:6px;">
                            <div style="width:42px;height:42px;border-radius:12px;background:#f3e8ff;color:#5b21b6;display:flex;align-items:center;justify-content:center;font-size:20px;"><i class="fa fa-user-paw"></i></div>
                            <h2 style="font-size:20px;font-weight:800;color:#1e1b4b;margin:0;">Step 1 of 7: Select Client &amp; Pet</h2>
                        </div>
                        <div style="font-size:13px;color:#64748b;margin-bottom:24px;margin-left:56px;">Choose the client and pet for the appointment.</div>

                        <div class="mb-4 p-3 rounded-3" style="background:#f8fafc;border:1px solid #e2e8f0;">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="fw-bold" style="font-size:14px;color:#1e1b4b;"><?=htmlspecialchars($user_name ?? '')?></div>
                                    <div style="font-size:12px;color:#64748b;"><?=htmlspecialchars($myAddr ? $myAddr : '0917-123-4567')?></div>
                                </div>
                                <span class="badge rounded-pill px-3 py-2" style="background:#5b21b6;color:#ffffff;">Active Client</span>
                            </div>
                        </div>

                        <div class="d-flex align-items-center gap-2 mb-3">
                            <input type="checkbox" id="multiPetToggle" style="width:18px;height:18px;cursor:pointer;">
                            <label for="multiPetToggle" class="fw-bold" style="font-size:13px;color:#334155;cursor:pointer;">Book for multiple pets</label>
                        </div>

                        <div style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:24px;">
                            <?php if (empty($pets_for_form)): ?>
                            <div class="pet-card-chip selected" data-pet-id="0" data-pet-name="Buddy" data-pet-spec="Golden Retriever • Male" data-pet-age="3 years old" onclick="togglePetChip(this)" style="padding:12px 20px;border-radius:30px;border:2px solid #5b21b6;background:#f3e8ff;color:#5b21b6;cursor:pointer;font-weight:700;font-size:14px;display:flex;align-items:center;gap:10px;">
                                <i class="fa fa-paw"></i> Buddy <span style="font-size:11px;color:#64748b;">(Golden Retriever)</span>
                            </div>
                            <?php else: ?>
                            <?php foreach ($pets_for_form as $idx => $p): ?>
                            <div class="pet-card-chip <?=$idx===0?'selected':''?>"
                                 data-pet-id="<?=(int)$p['id']?>"
                                 data-pet-name="<?=htmlspecialchars($p['name'])?>"
                                 data-pet-spec="<?=htmlspecialchars(($p['breed']??'').' • '.($p['species']??''))?>"
                                 data-pet-age="Active profile"
                                 onclick="togglePetChip(this)"
                                 style="padding:12px 20px;border-radius:30px;border:2px solid #e2e8f0;background:#fff;cursor:pointer;font-weight:700;font-size:14px;color:#334155;display:flex;align-items:center;gap:10px;">
                                <i class="fa fa-paw"></i> <?=htmlspecialchars($p['name'])?>
                                <?php if (!empty($p['species'])): ?>
                                <span style="font-size:11px;color:#64748b;">(<?=htmlspecialchars($p['species'])?>)</span>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <div class="wiz-alert-err" id="err-1" style="display:none;background:#fef2f2;border:1px solid #fca5a5;color:#dc2626;border-radius:10px;padding:10px 16px;font-size:13px;font-weight:600;margin-top:14px;">Please select at least one pet to proceed.</div>

                        <div style="display:flex;align-items:center;justify-content:space-between;padding-top:16px;border-top:1px solid #f1f5f9;">
                            <div></div>
                            <div>
                                <button type="button" class="btn-wiz-next" onclick="goNext(1)" style="padding:10px 28px;border-radius:10px;background:#5b21b6;color:#fff;font-weight:700;font-size:13px;border:none;cursor:pointer;box-shadow:0 4px 12px rgba(91,33,182,0.3);">Next &gt;</button>
                            </div>
                        </div>
                    </div>

                    <!-- STEP 2: Select Service (3x3 Grid matching mockup) -->
                    <div class="wiz-panel active" id="panel-2" style="display:block;">
                        <div style="display:flex;align-items:center;gap:14px;margin-bottom:6px;">
                            <div style="width:42px;height:42px;border-radius:12px;background:#f3e8ff;color:#5b21b6;display:flex;align-items:center;justify-content:center;font-size:20px;"><i class="fa fa-clipboard-list"></i></div>
                            <h2 style="font-size:20px;font-weight:800;color:#1e1b4b;margin:0;">Step 2 of 7: Select Service</h2>
                        </div>
                        <div style="font-size:13px;color:#64748b;margin-bottom:24px;margin-left:56px;">Choose the type of service you need for your pet.</div>

                        <?php
                        $preset_services = [
                            ['id' => 1, 'name' => 'Consultation', 'description' => 'General check-up and health consultation', 'duration_minutes' => 30, 'price' => 500, 'icon' => 'fa-stethoscope', 'bg' => '#f3e8ff', 'color' => '#7c3aed', 'info' => 'Consultation includes: Physical examination, health assessment, and basic treatment recommendation.'],
                            ['id' => 2, 'name' => 'Vaccination', 'description' => 'Routine vaccination services', 'duration_minutes' => 20, 'price' => 350, 'icon' => 'fa-syringe', 'bg' => '#dcfce7', 'color' => '#16a34a', 'info' => 'Vaccination includes: Vaccine administration, health check before injection, and passport update.'],
                            ['id' => 3, 'name' => 'Laboratory Test', 'description' => 'Blood test, fecal test, urinalysis, etc.', 'duration_minutes' => 45, 'price' => 600, 'icon' => 'fa-flask', 'bg' => '#e0f2fe', 'color' => '#0284c7', 'info' => 'Laboratory Test includes: Blood, urine, or stool sample extraction, laboratory processing, and diagnostic report.'],
                            ['id' => 4, 'name' => 'Deworming', 'description' => 'Internal and external deworming', 'duration_minutes' => 15, 'price' => 250, 'icon' => 'fa-first-aid', 'bg' => '#ffedd5', 'color' => '#ea580c', 'info' => 'Deworming includes: Oral broad-spectrum dewormer dosage, parasite preventive guidance, and weight check.'],
                            ['id' => 5, 'name' => 'Dental Cleaning', 'description' => 'Teeth cleaning and oral care', 'duration_minutes' => 60, 'price' => 700, 'icon' => 'fa-tooth', 'bg' => '#ccfbf1', 'color' => '#0d9488', 'info' => 'Dental Cleaning includes: Ultrasonic scaling, tooth polishing, gum health assessment, and oral hygiene check.'],
                            ['id' => 6, 'name' => 'Grooming', 'description' => 'Bath, nail trimming, ear cleaning, etc.', 'duration_minutes' => 60, 'price' => 400, 'icon' => 'fa-paw', 'bg' => '#fce7f3', 'color' => '#db2777', 'info' => 'Grooming includes: Medicated shampoo bath, coat blow dry, nail clipping, ear cleaning, and sanitary trim.'],
                            ['id' => 7, 'name' => 'Pet Boarding', 'description' => 'Pet boarding and accommodation', 'duration_minutes' => 1440, 'price' => 350, 'icon' => 'fa-hospital', 'bg' => '#e0f2fe', 'color' => '#2563eb', 'info' => 'Pet Boarding includes: Individual climate-controlled suite, 3 daily meals, regular exercise, and 24/7 care.', 'price_unit' => '/ day'],
                            ['id' => 8, 'name' => 'Surgery', 'description' => 'Surgical procedure and operation', 'duration_minutes' => 120, 'price' => 0, 'icon' => 'fa-scissors', 'bg' => '#f3e8ff', 'color' => '#9333ea', 'info' => 'Surgery includes: Pre-surgical evaluation, sterile operating room prep, anesthesia monitoring, and post-op care.', 'price_display' => 'Price varies'],
                            ['id' => 9, 'name' => 'Other Services', 'description' => 'Other medical services', 'duration_minutes' => 30, 'price' => 0, 'icon' => 'fa-ellipsis-h', 'bg' => '#f1f5f9', 'color' => '#475569', 'info' => 'Other Services includes: Special consultation, microchip insertion, health certificate issuance, or custom medical care.', 'price_display' => 'Price varies']
                        ];
                        ?>

                        <div style="display:grid;grid-template-columns:repeat(3, 1fr);gap:16px;margin-bottom:24px;">
                            <?php foreach ($preset_services as $idx => $s): 
                                $is_sel = ($idx === 0);
                                $price_txt = isset($s['price_display']) ? $s['price_display'] : '&#8369;' . number_format((float)$s['price'], 2) . ($s['price_unit'] ?? '');
                            ?>
                            <div class="svc-item-card <?=$is_sel?'selected':''?>"
                                 data-svc-id="<?=(int)$s['id']?>"
                                 data-svc-name="<?=htmlspecialchars($s['name'])?>"
                                 data-svc-dur="<?=(int)$s['duration_minutes']?>"
                                 data-svc-price="<?=(float)$s['price']?>"
                                 data-svc-pricetxt="<?=htmlspecialchars(strip_tags($price_txt))?>"
                                 data-svc-info="<?=htmlspecialchars($s['info'])?>"
                                 onclick="selectServiceCard(this)"
                                 style="border:2px solid <?=$is_sel?'#5b21b6':'#e2e8f0'?>;border-radius:16px;padding:20px;background:<?=$is_sel?'#fcfaff':'#fff'?>;cursor:pointer;position:relative;display:flex;flex-direction:column;">
                                <div class="svc-check-radio" style="width:20px;height:20px;border-radius:50%;border:2px solid <?=$is_sel?'#5b21b6':'#cbd5e1'?>;background:<?=$is_sel?'#5b21b6':'transparent'?>;position:absolute;top:18px;right:18px;display:flex;align-items:center;justify-content:center;">
                                    <?php if($is_sel): ?><div style="width:6px;height:6px;border-radius:50%;background:#fff;"></div><?php endif; ?>
                                </div>
                                <div style="width:46px;height:46px;border-radius:50%;background:<?=$s['bg']?>;color:<?=$s['color']?>;display:flex;align-items:center;justify-content:center;font-size:20px;margin-bottom:14px;">
                                    <i class="fa <?=$s['icon']?>"></i>
                                </div>
                                <div style="font-size:15px;font-weight:800;color:#1e1b4b;margin-bottom:6px;"><?=htmlspecialchars($s['name'])?></div>
                                <div style="font-size:12px;color:#64748b;line-height:1.4;margin-bottom:14px;flex-grow:1;"><?=htmlspecialchars($s['description'])?></div>
                                <div style="font-size:14px;font-weight:800;color:#5b21b6;"><?=$price_txt?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Yellow/Cream Service Information Callout Box -->
                        <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:14px;padding:16px 20px;margin-bottom:28px;" id="svcInfoBox">
                            <div style="font-size:13px;font-weight:800;color:#92400e;margin-bottom:4px;">Service Information</div>
                            <div style="font-size:13px;color:#b45309;margin:0;" id="svcInfoText">
                                <strong>Consultation includes:</strong> Physical examination, health assessment, and basic treatment recommendation.
                            </div>
                        </div>

                        <div class="wiz-alert-err" id="err-2" style="display:none;background:#fef2f2;border:1px solid #fca5a5;color:#dc2626;border-radius:10px;padding:10px 16px;font-size:13px;font-weight:600;margin-top:14px;">Please select a service to proceed.</div>

                        <div style="display:flex;align-items:center;justify-content:space-between;padding-top:16px;border-top:1px solid #f1f5f9;">
                            <button type="button" class="btn-wiz-prev" onclick="goPrev(2)" style="padding:10px 24px;border-radius:10px;border:1.5px solid #cbd5e1;background:#fff;font-weight:700;font-size:13px;color:#475569;cursor:pointer;">&lt; Previous</button>
                            <div>
                                <button type="button" class="btn-wiz-cancel" onclick="resetWizard()" style="padding:10px 20px;border-radius:10px;border:1.5px solid #e2e8f0;background:#fff;font-weight:700;font-size:13px;color:#64748b;cursor:pointer;margin-right:10px;">Cancel</button>
                                <button type="button" class="btn-wiz-next" onclick="goNext(2)" style="padding:10px 28px;border-radius:10px;background:#5b21b6;color:#fff;font-weight:700;font-size:13px;border:none;cursor:pointer;box-shadow:0 4px 12px rgba(91,33,182,0.3);">Next &gt;</button>
                            </div>
                        </div>
                    </div>

                    <!-- STEP 3: Select Veterinarian -->
                    <div class="wiz-panel" id="panel-3" style="display:none;">
                        <div style="display:flex;align-items:center;gap:14px;margin-bottom:6px;">
                            <div style="width:42px;height:42px;border-radius:12px;background:#f3e8ff;color:#5b21b6;display:flex;align-items:center;justify-content:center;font-size:20px;"><i class="fa fa-user-md"></i></div>
                            <h2 style="font-size:20px;font-weight:800;color:#1e1b4b;margin:0;">Step 3 of 7: Select Veterinarian</h2>
                        </div>
                        <div style="font-size:13px;color:#64748b;margin-bottom:24px;margin-left:56px;">Choose an available veterinarian for the appointment.</div>

                        <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(220px, 1fr));gap:16px;margin-bottom:24px;">
                            <div class="vet-card selected" data-vet-id="" data-vet-name="Any Available Veterinarian" data-vet-spec="Auto-assigned by clinic" onclick="selectVetCard(this)" style="border:2px solid #5b21b6;border-radius:16px;padding:20px;background:#fcfaff;cursor:pointer;text-align:center;">
                                <div style="width:56px;height:56px;border-radius:50%;background:linear-gradient(135deg, #6d28d9, #4c1d95);color:#fff;font-size:20px;font-weight:800;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;"><i class="fa fa-user-md"></i></div>
                                <div style="font-weight:700;font-size:15px;color:#1e1b4b;">Any Available Doctor</div>
                                <div style="font-size:12px;color:#64748b;margin-top:4px;">First available vet on duty</div>
                            </div>
                            <?php foreach ($doctors as $doc): 
                                $words = explode(' ', $doc['fullname']);
                                $inits = strtoupper(substr($words[0]??'D', 0, 1) . substr($words[1]??'V', 0, 1));
                            ?>
                            <div class="vet-card" data-vet-id="<?=(int)$doc['id']?>" data-vet-name="<?=htmlspecialchars($doc['fullname'])?>" data-vet-spec="Veterinarian" onclick="selectVetCard(this)" style="border:2px solid #e2e8f0;border-radius:16px;padding:20px;background:#fff;cursor:pointer;text-align:center;">
                                <div style="width:56px;height:56px;border-radius:50%;background:linear-gradient(135deg, #6d28d9, #4c1d95);color:#fff;font-size:20px;font-weight:800;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;"><?=$inits?></div>
                                <div style="font-weight:700;font-size:15px;color:#1e1b4b;"><?=htmlspecialchars($doc['fullname'])?></div>
                                <div style="font-size:12px;color:#64748b;margin-top:4px;">Veterinary Specialist</div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="wiz-alert-err" id="err-3" style="display:none;background:#fef2f2;border:1px solid #fca5a5;color:#dc2626;border-radius:10px;padding:10px 16px;font-size:13px;font-weight:600;margin-top:14px;">Please select a veterinarian option.</div>

                        <div style="display:flex;align-items:center;justify-content:space-between;padding-top:16px;border-top:1px solid #f1f5f9;">
                            <button type="button" class="btn-wiz-prev" onclick="goPrev(3)" style="padding:10px 24px;border-radius:10px;border:1.5px solid #cbd5e1;background:#fff;font-weight:700;font-size:13px;color:#475569;cursor:pointer;">&lt; Previous</button>
                            <div>
                                <button type="button" class="btn-wiz-cancel" onclick="resetWizard()" style="padding:10px 20px;border-radius:10px;border:1.5px solid #e2e8f0;background:#fff;font-weight:700;font-size:13px;color:#64748b;cursor:pointer;margin-right:10px;">Cancel</button>
                                <button type="button" class="btn-wiz-next" onclick="goNext(3)" style="padding:10px 28px;border-radius:10px;background:#5b21b6;color:#fff;font-weight:700;font-size:13px;border:none;cursor:pointer;box-shadow:0 4px 12px rgba(91,33,182,0.3);">Next &gt;</button>
                            </div>
                        </div>
                    </div>

                    <!-- STEP 4: Date & Time -->
                    <div class="wiz-panel" id="panel-4" style="display:none;">
                        <div style="display:flex;align-items:center;gap:14px;margin-bottom:6px;">
                            <div style="width:42px;height:42px;border-radius:12px;background:#f3e8ff;color:#5b21b6;display:flex;align-items:center;justify-content:center;font-size:20px;"><i class="fa fa-calendar-alt"></i></div>
                            <h2 style="font-size:20px;font-weight:800;color:#1e1b4b;margin:0;">Step 4 of 7: Date &amp; Time</h2>
                        </div>
                        <div style="font-size:13px;color:#64748b;margin-bottom:24px;margin-left:56px;">Pick your preferred appointment date and time slot.</div>

                        <div class="mb-4" style="max-width: 440px;">
                            <label class="form-label fw-bold" style="font-size:13px;color:#334155;">Select Preferred Date</label>
                            <input type="text" id="wizDatePicker" class="form-control form-control-lg rounded-3" placeholder="Click to choose a date…" style="font-size:14px;border:2px solid #e2e8f0;" readonly>
                        </div>

                        <div>
                            <label class="form-label fw-bold" style="font-size:13px;color:#334155;">Available Time Slots (<span id="slotDateDisplay" style="color:#5b21b6;">Select date above</span>)</label>
                            <div id="slotsContainer" style="display:grid;grid-template-columns:repeat(auto-fill, minmax(110px, 1fr));gap:10px;margin-top:16px;">
                                <div style="grid-column: 1/-1; color: #94a3b8; font-size:13px; padding: 20px 0;">Please pick a date above to view available time slots.</div>
                            </div>
                        </div>

                        <div class="wiz-alert-err" id="err-4" style="display:none;background:#fef2f2;border:1px solid #fca5a5;color:#dc2626;border-radius:10px;padding:10px 16px;font-size:13px;font-weight:600;margin-top:14px;">Please select a date and available time slot.</div>

                        <div style="display:flex;align-items:center;justify-content:space-between;padding-top:16px;border-top:1px solid #f1f5f9;">
                            <button type="button" class="btn-wiz-prev" onclick="goPrev(4)" style="padding:10px 24px;border-radius:10px;border:1.5px solid #cbd5e1;background:#fff;font-weight:700;font-size:13px;color:#475569;cursor:pointer;">&lt; Previous</button>
                            <div>
                                <button type="button" class="btn-wiz-cancel" onclick="resetWizard()" style="padding:10px 20px;border-radius:10px;border:1.5px solid #e2e8f0;background:#fff;font-weight:700;font-size:13px;color:#64748b;cursor:pointer;margin-right:10px;">Cancel</button>
                                <button type="button" class="btn-wiz-next" onclick="goNext(4)" style="padding:10px 28px;border-radius:10px;background:#5b21b6;color:#fff;font-weight:700;font-size:13px;border:none;cursor:pointer;box-shadow:0 4px 12px rgba(91,33,182,0.3);">Next &gt;</button>
                            </div>
                        </div>
                    </div>

                    <!-- STEP 5: Appointment Details -->
                    <div class="wiz-panel" id="panel-5" style="display:none;">
                        <div style="display:flex;align-items:center;gap:14px;margin-bottom:6px;">
                            <div style="width:42px;height:42px;border-radius:12px;background:#f3e8ff;color:#5b21b6;display:flex;align-items:center;justify-content:center;font-size:20px;"><i class="fa fa-file-alt"></i></div>
                            <h2 style="font-size:20px;font-weight:800;color:#1e1b4b;margin:0;">Step 5 of 7: Appointment Details</h2>
                        </div>
                        <div style="font-size:13px;color:#64748b;margin-bottom:24px;margin-left:56px;">Add reason for visit, symptoms, and service location.</div>

                        <div class="mb-4">
                            <label class="form-label fw-bold" style="font-size:13px;color:#334155;">Service Location</label>
                            <div class="d-flex gap-3">
                                <label class="p-3 border rounded-3 d-flex align-items-center gap-2 cursor-pointer flex-grow-1" style="background:#f8fafc;">
                                    <input type="radio" name="wiz_location" value="Clinic Visit" checked onchange="toggleLocation(this)">
                                    <div>
                                        <div class="fw-bold" style="font-size:14px;color:#1e1b4b;"><i class="fa fa-clinic-medical me-1" style="color:#5b21b6;"></i> Clinic Visit</div>
                                        <div style="font-size:11px;color:#64748b;">Bring your pet to PragmaVet Animal Clinic</div>
                                    </div>
                                </label>
                                <label class="p-3 border rounded-3 d-flex align-items-center gap-2 cursor-pointer flex-grow-1" style="background:#f8fafc;">
                                    <input type="radio" name="wiz_location" value="Home Service" onchange="toggleLocation(this)">
                                    <div>
                                        <div class="fw-bold" style="font-size:14px;color:#1e1b4b;"><i class="fa fa-home me-1" style="color:#5b21b6;"></i> Home Service</div>
                                        <div style="font-size:11px;color:#64748b;">Vet visits your residence address</div>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <div class="mb-4" id="homeAddrBox" style="display:none;">
                            <label class="form-label fw-bold" style="font-size:13px;color:#334155;">Complete Home Address</label>
                            <input type="text" id="wizHomeAddr" class="form-control rounded-3" value="<?=htmlspecialchars($myAddr)?>" placeholder="Street, Barangay, City, Province…">
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-bold" style="font-size:13px;color:#334155;">Reason for Visit &amp; Symptoms (Optional)</label>
                            <textarea id="wizNotes" class="form-control rounded-3" rows="3" placeholder="Describe any symptoms, medical history, or specific requirements for your pet…"></textarea>
                        </div>

                        <div class="wiz-alert-err" id="err-5" style="display:none;background:#fef2f2;border:1px solid #fca5a5;color:#dc2626;border-radius:10px;padding:10px 16px;font-size:13px;font-weight:600;margin-top:14px;">Please fill out all required details.</div>

                        <div style="display:flex;align-items:center;justify-content:space-between;padding-top:16px;border-top:1px solid #f1f5f9;">
                            <button type="button" class="btn-wiz-prev" onclick="goPrev(5)" style="padding:10px 24px;border-radius:10px;border:1.5px solid #cbd5e1;background:#fff;font-weight:700;font-size:13px;color:#475569;cursor:pointer;">&lt; Previous</button>
                            <div>
                                <button type="button" class="btn-wiz-cancel" onclick="resetWizard()" style="padding:10px 20px;border-radius:10px;border:1.5px solid #e2e8f0;background:#fff;font-weight:700;font-size:13px;color:#64748b;cursor:pointer;margin-right:10px;">Cancel</button>
                                <button type="button" class="btn-wiz-next" onclick="goNext(5)" style="padding:10px 28px;border-radius:10px;background:#5b21b6;color:#fff;font-weight:700;font-size:13px;border:none;cursor:pointer;box-shadow:0 4px 12px rgba(91,33,182,0.3);">Next &gt;</button>
                            </div>
                        </div>
                    </div>

                    <!-- STEP 6: Review & Confirm -->
                    <div class="wiz-panel" id="panel-6" style="display:none;">
                        <div style="display:flex;align-items:center;gap:14px;margin-bottom:6px;">
                            <div style="width:42px;height:42px;border-radius:12px;background:#f3e8ff;color:#5b21b6;display:flex;align-items:center;justify-content:center;font-size:20px;"><i class="fa fa-check-double"></i></div>
                            <h2 style="font-size:20px;font-weight:800;color:#1e1b4b;margin:0;">Step 6 of 7: Review &amp; Confirm</h2>
                        </div>
                        <div style="font-size:13px;color:#64748b;margin-bottom:24px;margin-left:56px;">Review all appointment details before final confirmation.</div>

                        <div class="card border-0 rounded-4 p-4 mb-4" style="background:#f8fafc;">
                            <h5 class="fw-bold mb-3" style="color:#1e1b4b;"><i class="fa fa-clipboard-check me-2" style="color:#5b21b6;"></i>Booking Summary</h5>
                            <div class="row g-3" style="font-size:14px;">
                                <div class="col-md-6">
                                    <span class="text-muted d-block font-size-12" style="font-size:11px;font-weight:700;text-transform:uppercase;">Client Name</span>
                                    <span class="fw-bold" id="rvClientName">—</span>
                                </div>
                                <div class="col-md-6">
                                    <span class="text-muted d-block font-size-12" style="font-size:11px;font-weight:700;text-transform:uppercase;">Pet(s)</span>
                                    <span class="fw-bold" id="rvPetName">—</span>
                                </div>
                                <div class="col-md-6">
                                    <span class="text-muted d-block font-size-12" style="font-size:11px;font-weight:700;text-transform:uppercase;">Service</span>
                                    <span class="fw-bold" id="rvService">—</span>
                                </div>
                                <div class="col-md-6">
                                    <span class="text-muted d-block font-size-12" style="font-size:11px;font-weight:700;text-transform:uppercase;">Veterinarian</span>
                                    <span class="fw-bold" id="rvVet">—</span>
                                </div>
                                <div class="col-md-6">
                                    <span class="text-muted d-block font-size-12" style="font-size:11px;font-weight:700;text-transform:uppercase;">Date &amp; Time</span>
                                    <span class="fw-bold" id="rvDateTime">—</span>
                                </div>
                                <div class="col-md-6">
                                    <span class="text-muted d-block font-size-12" style="font-size:11px;font-weight:700;text-transform:uppercase;">Location</span>
                                    <span class="fw-bold" id="rvLocation">—</span>
                                </div>
                            </div>
                        </div>

                        <div class="p-3 rounded-3 mb-4" style="background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;font-size:13px;">
                            <i class="fa fa-info-circle me-2"></i>By clicking Confirm Booking, your appointment schedule request will be transmitted to the clinic staff for approval.
                        </div>

                        <div class="wiz-alert-err" id="err-6" style="display:none;background:#fef2f2;border:1px solid #fca5a5;color:#dc2626;border-radius:10px;padding:10px 16px;font-size:13px;font-weight:600;margin-top:14px;"></div>

                        <div style="display:flex;align-items:center;justify-content:space-between;padding-top:16px;border-top:1px solid #f1f5f9;">
                            <button type="button" class="btn-wiz-prev" onclick="goPrev(6)" style="padding:10px 24px;border-radius:10px;border:1.5px solid #cbd5e1;background:#fff;font-weight:700;font-size:13px;color:#475569;cursor:pointer;">&lt; Previous</button>
                            <div>
                                <button type="button" class="btn-wiz-cancel" onclick="resetWizard()" style="padding:10px 20px;border-radius:10px;border:1.5px solid #e2e8f0;background:#fff;font-weight:700;font-size:13px;color:#64748b;cursor:pointer;margin-right:10px;">Cancel</button>
                                <button type="button" class="btn-wiz-next" id="submitBtn" onclick="submitAppointment()" style="padding:10px 28px;border-radius:10px;background:#5b21b6;color:#fff;font-weight:700;font-size:13px;border:none;cursor:pointer;box-shadow:0 4px 12px rgba(91,33,182,0.3);">Confirm Booking &gt;</button>
                            </div>
                        </div>
                    </div>

                    <!-- STEP 7: Appointment Created -->
                    <div class="wiz-panel" id="panel-7" style="display:none;">
                        <div class="text-center py-5">
                            <div style="width:80px;height:80px;border-radius:50%;background:#d1fae5;color:#10b981;display:flex;align-items:center;justify-content:center;font-size:36px;margin:0 auto 20px;">
                                <i class="fa fa-check"></i>
                            </div>
                            <h2 class="fw-bold" style="color:#1e1b4b;font-size:24px;margin-bottom:8px;">Appointment Created!</h2>
                            <div class="fw-bold text-purple mb-3" style="font-size:20px;color:#5b21b6;" id="confirmRefNum">#PV-202608-8842</div>
                            <p class="text-muted mx-auto mb-4" style="max-width:460px;font-size:14px;">
                                Your appointment has been successfully scheduled. A confirmation notification will be sent shortly.
                            </p>

                            <div class="d-flex justify-content-center gap-3">
                                <button type="button" class="btn btn-outline-secondary rounded-3 px-4 py-2 fw-bold" style="font-size:13px;" onclick="switchApptView('list')">
                                    View Appointments List
                                </button>
                                <button type="button" class="btn btn-primary rounded-3 px-4 py-2 fw-bold" style="font-size:13px;background:#5b21b6;border:none;" onclick="resetWizard()">
                                    Book Another
                                </button>
                            </div>
                        </div>
                    </div>

                </div><!-- /Left Panel Card -->

                <!-- RIGHT PANEL: STICKY SUMMARY & PROCESS CARDS -->
                <div style="display:flex;flex-direction:column;gap:20px;position:sticky;top:88px;">
                    
                    <!-- APPOINTMENT SUMMARY CARD -->
                    <div style="background:#fff;border-radius:20px;border:1px solid #e2e8f0;padding:24px;box-shadow:0 4px 20px rgba(0,0,0,0.03);">
                        <div style="font-size:16px;font-weight:800;color:#1e1b4b;margin-bottom:20px;">Appointment Summary</div>

                        <div style="display:flex;align-items:flex-start;gap:12px;margin-bottom:16px;">
                            <div style="width:32px;height:32px;border-radius:50%;background:#f1f5f9;color:#5b21b6;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0;"><i class="fa fa-user"></i></div>
                            <div>
                                <div style="font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.04em;margin-bottom:2px;">Client</div>
                                <div style="font-size:13px;font-weight:700;color:#1e1b4b;line-height:1.3;" id="sumClientName"><?=htmlspecialchars($user_name ?? '')?></div>
                                <div style="font-size:11px;color:#64748b;" id="sumClientPhone"><?=htmlspecialchars($myAddr ? $myAddr : '0917-123-4567')?></div>
                            </div>
                        </div>

                        <div style="display:flex;align-items:flex-start;gap:12px;margin-bottom:16px;">
                            <div style="width:32px;height:32px;border-radius:50%;background:#f1f5f9;color:#5b21b6;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0;"><i class="fa fa-paw"></i></div>
                            <div>
                                <div style="font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.04em;margin-bottom:2px;">Pet</div>
                                <div style="font-size:13px;font-weight:700;color:#1e1b4b;line-height:1.3;" id="sumPetName">Buddy</div>
                                <div style="font-size:11px;color:#64748b;" id="sumPetSub">Golden Retriever &bull; Male</div>
                                <div style="font-size:11px;color:#64748b;" id="sumPetAge">Active Profile</div>
                            </div>
                        </div>

                        <div style="display:flex;align-items:flex-start;gap:12px;margin-bottom:16px;">
                            <div style="width:32px;height:32px;border-radius:50%;background:#f1f5f9;color:#5b21b6;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0;"><i class="fa fa-clipboard-list"></i></div>
                            <div>
                                <div style="font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.04em;margin-bottom:2px;">Service</div>
                                <div style="font-size:13px;font-weight:700;color:#1e1b4b;line-height:1.3;" id="sumServiceName">Consultation</div>
                                <div style="font-size:11px;color:#64748b;" id="sumServicePrice">&#8369;500.00</div>
                            </div>
                        </div>

                        <div style="display:flex;align-items:flex-start;gap:12px;margin-bottom:16px;">
                            <div style="width:32px;height:32px;border-radius:50%;background:#f1f5f9;color:#5b21b6;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0;"><i class="fa fa-user-md"></i></div>
                            <div>
                                <div style="font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.04em;margin-bottom:2px;">Veterinarian</div>
                                <div style="font-size:13px;font-weight:700;color:#64748b;line-height:1.3;" id="sumVetName">Not selected yet</div>
                            </div>
                        </div>

                        <div style="display:flex;align-items:flex-start;gap:12px;margin-bottom:16px;">
                            <div style="width:32px;height:32px;border-radius:50%;background:#f1f5f9;color:#5b21b6;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0;"><i class="fa fa-calendar-alt"></i></div>
                            <div>
                                <div style="font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.04em;margin-bottom:2px;">Date &amp; Time</div>
                                <div style="font-size:13px;font-weight:700;color:#64748b;line-height:1.3;" id="sumDateTime">Not selected yet</div>
                            </div>
                        </div>

                        <div style="display:flex;align-items:flex-start;gap:12px;margin-bottom:16px;">
                            <div style="width:32px;height:32px;border-radius:50%;background:#f1f5f9;color:#5b21b6;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0;"><i class="fa fa-file-alt"></i></div>
                            <div>
                                <div style="font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.04em;margin-bottom:2px;">Appointment Details</div>
                                <div style="font-size:13px;font-weight:700;color:#64748b;line-height:1.3;" id="sumDetails">Not added yet</div>
                            </div>
                        </div>

                        <div style="height:1px;background:#e2e8f0;margin:18px 0;"></div>

                        <div style="display:flex;align-items:center;justify-content:space-between;">
                            <div style="font-size:14px;font-weight:800;color:#1e1b4b;">Estimated Total</div>
                            <div style="font-size:18px;font-weight:800;color:#5b21b6;" id="sumEstimatedTotal">&#8369;500.00</div>
                        </div>
                    </div>

                    <!-- APPOINTMENT PROCESS LIST CARD -->
                    <div style="background:#fff;border-radius:20px;border:1px solid #e2e8f0;padding:24px;box-shadow:0 4px 20px rgba(0,0,0,0.03);">
                        <div style="font-size:16px;font-weight:800;color:#1e1b4b;margin-bottom:20px;">Appointment Process</div>
                        <div style="display:flex;flex-direction:column;gap:10px;">
                            <?php for ($k = 1; $k <= 7; $k++): ?>
                            <div class="process-step-item <?=$k===2?'active':''?>" id="proc-item-<?=$k?>" style="display:flex;align-items:center;gap:12px;padding:8px 12px;border-radius:10px;font-size:13px;font-weight:600;color:#64748b;">
                                <div class="process-badge" id="proc-badge-<?=$k?>" style="width:22px;height:22px;border-radius:50%;background:<?=$k===2?'#5b21b6':'#e2e8f0'?>;color:<?=$k===2?'#fff':'#64748b'?>;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;"><?=$k?></div>
                                <div><?=$k?>. <?=$step_names[$k]?></div>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>

                </div><!-- /Right Panel -->

            </div><!-- /Main Grid -->

            <!-- BOTTOM BANNER: HOW THE APPOINTMENT PROCESS WORKS -->
            <div style="background:#fff;border-radius:20px;border:1px solid #e2e8f0;padding:28px 32px;margin-top:36px;box-shadow:0 4px 20px rgba(0,0,0,0.03);">
                <div style="font-size:16px;font-weight:800;color:#1e1b4b;margin-bottom:24px;">How the Appointment Process Works</div>
                <div style="display:grid;grid-template-columns:repeat(7, 1fr);gap:14px;">
                    <div style="display:flex;flex-direction:column;align-items:flex-start;">
                        <div style="width:28px;height:28px;border-radius:8px;background:#f3e8ff;color:#5b21b6;font-size:13px;font-weight:800;display:flex;align-items:center;justify-content:center;margin-bottom:8px;">1</div>
                        <div style="font-size:12px;font-weight:800;color:#1e1b4b;margin-bottom:4px;">Select Client &amp; Pet</div>
                        <div style="font-size:11px;color:#64748b;line-height:1.3;">Choose the client and pet for the appointment</div>
                    </div>
                    <div style="display:flex;flex-direction:column;align-items:flex-start;">
                        <div style="width:28px;height:28px;border-radius:8px;background:#f3e8ff;color:#5b21b6;font-size:13px;font-weight:800;display:flex;align-items:center;justify-content:center;margin-bottom:8px;">2</div>
                        <div style="font-size:12px;font-weight:800;color:#1e1b4b;margin-bottom:4px;">Select Service</div>
                        <div style="font-size:11px;color:#64748b;line-height:1.3;">Choose the type of service needed</div>
                    </div>
                    <div style="display:flex;flex-direction:column;align-items:flex-start;">
                        <div style="width:28px;height:28px;border-radius:8px;background:#f3e8ff;color:#5b21b6;font-size:13px;font-weight:800;display:flex;align-items:center;justify-content:center;margin-bottom:8px;">3</div>
                        <div style="font-size:12px;font-weight:800;color:#1e1b4b;margin-bottom:4px;">Select Veterinarian</div>
                        <div style="font-size:11px;color:#64748b;line-height:1.3;">Choose the available veterinarian</div>
                    </div>
                    <div style="display:flex;flex-direction:column;align-items:flex-start;">
                        <div style="width:28px;height:28px;border-radius:8px;background:#f3e8ff;color:#5b21b6;font-size:13px;font-weight:800;display:flex;align-items:center;justify-content:center;margin-bottom:8px;">4</div>
                        <div style="font-size:12px;font-weight:800;color:#1e1b4b;margin-bottom:4px;">Date &amp; Time</div>
                        <div style="font-size:11px;color:#64748b;line-height:1.3;">Pick the preferred date and time</div>
                    </div>
                    <div style="display:flex;flex-direction:column;align-items:flex-start;">
                        <div style="width:28px;height:28px;border-radius:8px;background:#f3e8ff;color:#5b21b6;font-size:13px;font-weight:800;display:flex;align-items:center;justify-content:center;margin-bottom:8px;">5</div>
                        <div style="font-size:12px;font-weight:800;color:#1e1b4b;margin-bottom:4px;">Details</div>
                        <div style="font-size:11px;color:#64748b;line-height:1.3;">Add reason, symptoms and notes</div>
                    </div>
                    <div style="display:flex;flex-direction:column;align-items:flex-start;">
                        <div style="width:28px;height:28px;border-radius:8px;background:#f3e8ff;color:#5b21b6;font-size:13px;font-weight:800;display:flex;align-items:center;justify-content:center;margin-bottom:8px;">6</div>
                        <div style="font-size:12px;font-weight:800;color:#1e1b4b;margin-bottom:4px;">Review</div>
                        <div style="font-size:11px;color:#64748b;line-height:1.3;">Review all details before confirmation</div>
                    </div>
                    <div style="display:flex;flex-direction:column;align-items:flex-start;">
                        <div style="width:28px;height:28px;border-radius:8px;background:#f3e8ff;color:#5b21b6;font-size:13px;font-weight:800;display:flex;align-items:center;justify-content:center;margin-bottom:8px;">7</div>
                        <div style="font-size:12px;font-weight:800;color:#1e1b4b;margin-bottom:4px;">Confirmed</div>
                        <div style="font-size:11px;color:#64748b;line-height:1.3;">Appointment is created and confirmed</div>
                    </div>
                </div>
            </div>

        </div><!-- /viewWizardContainer -->

        <!-- ════════════════════════════════════════════════════════════════════════
             VIEW 2: YOUR APPOINTMENTS LIST TABLE (TOGGLE TAB)
        ════════════════════════════════════════════════════════════════════════════ -->
        <div id="viewListContainer" style="display:none;">
            <div class="card-prem">
                <div class="card-head">
                    <h5><span style="width:32px;height:32px;border-radius:9px;background:#f0fdf4;display:inline-flex;align-items:center;justify-content:center;color:#16a34a;font-size:14px;"><i class="fa fa-list-alt"></i></span> Your Appointments History</h5>

                    <!-- Live Search -->
                    <div class="search-wrap">
                        <span class="si"><i class="fa fa-search"></i></span>
                        <input type="text" id="apptSearch" placeholder="Search pet, service, status…" autocomplete="off">
                    </div>

                    <!-- Filter tabs -->
                    <div style="display:flex;gap:6px;flex-wrap:wrap;">
                        <button class="filter-tab active" data-filter="all">All</button>
                        <button class="filter-tab" data-filter="Pending">Pending</button>
                        <?php if (count($rescheduleProposals) > 0): ?>
                            <button class="filter-tab" data-filter="action_needed" style="border-color:#7e22ce; color:#7e22ce; background:#f5f3ff;">
                                <i class="fa fa-exclamation-circle me-1"></i> Reschedule Alerts <span style="background:#7e22ce; color:#fff; padding:1px 6px; border-radius:50px; font-size:10px; margin-left:4px;"><?php echo count($rescheduleProposals); ?></span>
                            </button>
                        <?php endif; ?>
                        <button class="filter-tab" data-filter="Approved">Approved</button>
                        <button class="filter-tab" data-filter="Completed">Completed</button>
                        <button class="filter-tab" data-filter="Cancelled">Cancelled</button>
                    </div>
                </div>

                <div style="overflow-x:auto;">
                    <?php if (count($apptRows) > 0): ?>
                        <table class="appt-table" id="apptTable">
                            <thead>
                                <tr>
                                    <th>Details</th>
                                    <th>Schedule</th>
                                    <th>Service Flow</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody id="apptBody">
                                <?php foreach ($apptRows as $row):
                                    $status = $row['status'];
                                    $badgeCls = match (true) {
                                        $status === 'Pending' => 'badge-pending',
                                        $status === 'Approved' => 'badge-approved',
                                        $status === 'Completed' => 'badge-completed',
                                        $status === 'Reschedule Proposed' => 'badge-proposed',
                                        $status === 'Reschedule Requested' => 'badge-proposed',
                                        str_contains($status, 'Cancel') || str_contains($status, 'Reject') => 'badge-cancelled',
                                        default => 'badge-default'
                                    };
                                    $rowData = json_encode($row, JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP);
                                    ?>
                                    <tr class="appt-row" data-status="<?php echo htmlspecialchars($status); ?>"
                                        data-action-needed="<?php echo (isset($row['client_action_needed']) && $row['client_action_needed'] == 1 && !str_contains($status, 'Cancel') && !str_contains($status, 'Reject')) ? '1' : '0'; ?>"
                                        onclick="openDetailModal(<?php echo htmlspecialchars($rowData, ENT_QUOTES); ?>)">
                                        <td>
                                            <div style="display:flex;align-items:center;gap:12px;">
                                                <div style="font-weight:700;color:#1e1b4b;font-size:14px;"><?=htmlspecialchars($row['pet_name'])?></div>
                                            </div>
                                            <div style="font-size:12px;color:#64748b;margin-top:2px;"><?=htmlspecialchars($row['service_type'])?></div>
                                        </td>
                                        <td>
                                            <div style="font-size:13px;font-weight:600;color:#1e1b4b;"><i class="fa fa-calendar-alt me-1 text-muted"></i><?=htmlspecialchars($row['appointment_date'])?></div>
                                            <div style="font-size:12px;color:#64748b;"><i class="fa fa-clock me-1 text-muted"></i><?=htmlspecialchars($row['appointment_time']??'')?></div>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border px-2 py-1"><i class="fa fa-map-marker-alt me-1"></i><?=htmlspecialchars($row['service_location']??'On-Site')?></span>
                                        </td>
                                        <td>
                                            <span class="<?=$badgeCls?>">&bull; <?=htmlspecialchars($status)?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="text-center py-5 text-muted">
                            <i class="fa fa-calendar-times fa-3x mb-3 text-secondary"></i>
                            <div>No appointments found. Use the Wizard above to book a new appointment.</div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Hidden Form for POST submission to appointments.php -->
        <form id="wizForm" method="POST" action="appointments.php" style="display:none;">
            <input type="hidden" name="book" value="1">
            <input type="hidden" name="pet_name" id="f_pet">
            <input type="hidden" name="service_type" id="f_svc">
            <input type="hidden" name="appointment_date" id="f_date">
            <input type="hidden" name="appointment_time" id="f_time">
            <input type="hidden" name="service_location" id="f_loc">
            <input type="hidden" name="home_address" id="f_addr">
            <input type="hidden" name="doctor_id" id="f_doc">
            <input type="hidden" name="notes" id="f_notes">
        </form>

    </div><!-- /main-content -->
'''

new_content = content[:start_pos] + replacement + "\n" + content[end_pos:]

with open(file_path, "w", encoding="utf-8") as f:
    f.write(new_content)

print("SUCCESS: appointments.php updated!")
