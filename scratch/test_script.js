
    // Registered Pets Data passed from PHP
    const DB_PETS           = <?php echo json_encode($pets_for_form); ?>;
    const BOOKED_SLOTS      = <?php echo $bookedSlotsJSON ?? '{}'; ?>;
    const BOOKED_DETAILED   = <?php echo $bookedDetailedJSON ?? '{}'; ?>;
    const TOMORROW_STR      = '<?=date('Y-m-d', strtotime('+1 day'))?>';
    const HOLIDAYS          = <?php echo $holidaysJSON ?? '[]'; ?>;
    const DOCTOR_SCHEDULES  = <?php echo $doctorSchedulesJSON ?? '{}'; ?>;
    const SVC_DURATIONS     = <?php echo $serviceDurationsJSON ?? '{}'; ?>;
    const APPT_DENSITY      = <?php echo $apptDensityJSON ?? '{}'; ?>;
    const MAX_DAILY_APPTS   = 10;

    // Global State for Interactive 7-Step Appointment Wizard
    const S = {
        step: 1,
        clientName: <?=json_encode($user_name ?? 'Client')?>,
        clientPhone: <?=json_encode($myAddr ? $myAddr : 'Registered Client')?>,
        pets: [],
        multi: false,
        svcId: 1,
        svcName: "Consultation",
        svcDur: 30,
        svcPrice: 500.00,
        svcPriceTxt: "₱500.00",
        svcInfo: "Consultation includes: Physical examination, health assessment, and basic treatment recommendation.",
        vetId: "",
        vetName: "Any Available Doctor",
        vetSpec: "Auto-assigned by clinic",
        date: "",
        time: "",
        timeLabel: "",
        loc: "Clinic Visit",
        addr: <?=json_encode($myAddr ?? '')?>,
        notes: ""
    };

    // Initialize default selected pet from DB_PETS
    if (Array.isArray(DB_PETS) && DB_PETS.length > 0) {
        S.pets = [{
            id: DB_PETS[0].id,
            name: DB_PETS[0].name,
            spec: (DB_PETS[0].breed || '') + ' ' + (DB_PETS[0].species || 'Pet'),
            age: DB_PETS[0].age || 'Active Profile'
        }];
    }

    function switchApptView(mode) {
        const wizCon = document.getElementById('viewWizardContainer');
        const listCon = document.getElementById('viewListContainer');
        const btnWiz = document.getElementById('btnTabWiz');
        const btnList = document.getElementById('btnTabList');
        const bSub = document.getElementById('bCrumbSub');

        if (mode === 'wizard') {
            if (wizCon) wizCon.style.display = 'block';
            if (listCon) listCon.style.display = 'none';
            if (btnWiz) { btnWiz.style.background = '#5b21b6'; btnWiz.style.color = '#fff'; }
            if (btnList) { btnList.style.background = '#fff'; btnList.style.color = '#64748b'; }
            if (bSub) bSub.textContent = 'New Appointment';
        } else {
            if (wizCon) wizCon.style.display = 'none';
            if (listCon) listCon.style.display = 'block';
            if (btnList) { btnList.style.background = '#5b21b6'; btnList.style.color = '#fff'; }
            if (btnWiz) { btnWiz.style.background = '#fff'; btnWiz.style.color = '#64748b'; }
            if (bSub) bSub.textContent = 'Appointments History';
        }
    }

    document.addEventListener("DOMContentLoaded", function() {
        initFlatpickr();
        quickPickDate(1); // Default to Tomorrow
        calcEstimatedTotal();
        updateSummaryWidget();
        showStep(1);
    });

    function jumpToStep(n) {
        if (n < S.step) {
            showStep(n);
        } else if (n > S.step) {
            if (validateStep(S.step)) showStep(n);
        }
    }

    function goNext(from) {
        if (!validateStep(from)) return;
        showStep(from + 1);
    }

    function goPrev(from) {
        showStep(from - 1);
    }

    function showStep(n) {
        S.step = n;

        for (let i = 1; i <= 7; i++) {
            const p = document.getElementById('panel-' + i);
            if (p) p.style.display = (i === n) ? 'block' : 'none';

            const node = document.getElementById('step-node-' + i);
            if (node) {
                node.classList.remove('active', 'done');
                if (i === n) node.classList.add('active');
                else if (i < n) node.classList.add('done');

                const circle = node.querySelector('.step-circle');
                if (circle) {
                    if (i === n) {
                        circle.style.background = '#5b21b6';
                        circle.style.borderColor = '#5b21b6';
                        circle.style.color = '#ffffff';
                    } else if (i < n) {
                        circle.style.background = '#10b981';
                        circle.style.borderColor = '#10b981';
                        circle.style.color = '#ffffff';
                    } else {
                        circle.style.background = '#ffffff';
                        circle.style.borderColor = '#cbd5e1';
                        circle.style.color = '#64748b';
                    }
                }
            }

            const pItem = document.getElementById('proc-item-' + i);
            const pBadge = document.getElementById('proc-badge-' + i);
            if (pItem && pBadge) {
                pItem.classList.remove('active', 'done');
                if (i === n) {
                    pItem.classList.add('active');
                    pItem.style.background = '#f3e8ff';
                    pItem.style.color = '#5b21b6';
                    pBadge.style.background = '#5b21b6';
                    pBadge.style.color = '#fff';
                    pBadge.textContent = i;
                } else if (i < n) {
                    pItem.classList.add('done');
                    pItem.style.background = 'transparent';
                    pItem.style.color = '#10b981';
                    pBadge.style.background = '#10b981';
                    pBadge.style.color = '#fff';
                    pBadge.innerHTML = '<i class="fa fa-check"></i>';
                } else {
                    pItem.style.background = 'transparent';
                    pItem.style.color = '#64748b';
                    pBadge.style.background = '#e2e8f0';
                    pBadge.style.color = '#64748b';
                    pBadge.textContent = i;
                }
            }
        }

        if (n === 5) fillReviewData();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function validateStep(n) {
        clearErr(n);
        if (n === 1 && S.pets.length === 0) { showErr(1, "Please select at least one pet."); return false; }
        if (n === 2 && !S.svcName) { showErr(2, "Please select a service."); return false; }
        if (n === 4 && (!S.date || !S.time)) { showErr(4, "Please select a date and available time slot."); return false; }
        return true;
    }

    function clearErr(n) { const e = document.getElementById('err-' + n); if (e) e.style.display = 'none'; }
    function showErr(n, msg) { const e = document.getElementById('err-' + n); if (e) { e.textContent = msg; e.style.display = 'block'; } }

    function toggleMultiPetMode(cb) {
        S.multi = cb.checked;
        const chips = document.querySelectorAll('.pet-card-chip');
        if (!S.multi && chips.length > 0) {
            chips.forEach((c, idx) => {
                const badge = c.querySelector('.pet-sel-badge');
                const nameEl = c.querySelector('[data-name-el]');
                if (idx === 0) {
                    c.classList.add('selected');
                    c.style.border = '2px solid #5b21b6';
                    c.style.background = '#f3e8ff';
                    c.style.boxShadow = '0 4px 16px rgba(91,33,182,0.18)';
                    if (badge) badge.style.display = 'flex';
                } else {
                    c.classList.remove('selected');
                    c.style.border = '2px solid #e2e8f0';
                    c.style.background = '#fff';
                    c.style.boxShadow = '0 2px 8px rgba(0,0,0,0.04)';
                    if (badge) badge.style.display = 'none';
                }
            });
            if (DB_PETS.length > 0) {
                S.pets = [{
                    id: DB_PETS[0].id,
                    name: DB_PETS[0].name,
                    spec: (DB_PETS[0].breed || '') + ' ' + (DB_PETS[0].species || 'Pet'),
                    age: DB_PETS[0].age || 'Active Profile'
                }];
            }
        }
        calcEstimatedTotal();
        updateSummaryWidget();
    }

    function togglePetChip(chip) {
        const id = chip.dataset.petId;
        const name = chip.dataset.petName;
        const spec = chip.dataset.petSpec || 'Pet Profile';
        const age = chip.dataset.petAge || '';

        // Helper to style a card as selected or deselected
        function setCardState(c, selected) {
            const badge = c.querySelector('.pet-sel-badge');
            const nameDiv = c.querySelector('[data-name-ref]');
            c.classList.toggle('selected', selected);
            c.style.border = selected ? '2px solid #5b21b6' : '2px solid #e2e8f0';
            c.style.background = selected ? '#f3e8ff' : '#fff';
            c.style.boxShadow = selected ? '0 4px 16px rgba(91,33,182,0.18)' : '0 2px 8px rgba(0,0,0,0.04)';
            if (badge) badge.style.display = selected ? 'flex' : 'none';
        }

        if (!S.multi) {
            document.querySelectorAll('.pet-card-chip').forEach(c => setCardState(c, false));
            setCardState(chip, true);
            S.pets = [{ id, name, spec, age }];
        } else {
            const nowSelected = !chip.classList.contains('selected');
            setCardState(chip, nowSelected);
            if (nowSelected) {
                S.pets.push({ id, name, spec, age });
            } else {
                S.pets = S.pets.filter(p => p.id !== id);
            }
        }
        calcEstimatedTotal();
        updateSummaryWidget();
    }

    function selectServiceCard(card) {
        document.querySelectorAll('.svc-item-card').forEach(c => {
            c.classList.remove('selected');
            c.style.border = '2px solid #e2e8f0';
            c.style.background = '#fff';
            const rad = c.querySelector('.svc-check-radio');
            if (rad) { rad.style.border = '2px solid #cbd5e1'; rad.style.background = 'transparent'; rad.innerHTML = ''; }
        });
        card.classList.add('selected');
        card.style.border = '2px solid #5b21b6';
        card.style.background = '#fcfaff';
        const rad = card.querySelector('.svc-check-radio');
        if (rad) { rad.style.border = '2px solid #5b21b6'; rad.style.background = '#5b21b6'; rad.innerHTML = '<div style="width:6px;height:6px;border-radius:50%;background:#fff;"></div>'; }

        S.svcId   = card.dataset.svcId;
        S.svcName = card.dataset.svcName;
        S.svcDur  = parseInt(card.dataset.svcDur) || 30;
        S.svcPrice = parseFloat(card.dataset.svcPrice) || 0;
        S.svcInfo  = card.dataset.svcInfo;

        const infoEl = document.getElementById('svcInfoText');
        if (infoEl) infoEl.innerHTML = '<strong>' + S.svcName + ' includes:</strong> ' + S.svcInfo.replace(/^.*includes:\s*/i, '');

        calcEstimatedTotal();
        updateSummaryWidget();
        // Re-render slots: service duration affects which slots are available
        if (S.date) renderDefaultTimeSlots();
    }

    function calcEstimatedTotal() {
        const count = S.pets.length > 0 ? S.pets.length : 1;
        if (S.svcPrice === 0) {
            S.svcPriceTxt = "Price Varies";
        } else {
            const total = S.svcPrice * count;
            S.svcPriceTxt = '₱' + total.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        const sumPriceEl = document.getElementById('sumServicePrice');
        if (sumPriceEl) {
            if (S.svcPrice > 0 && count > 1) {
                sumPriceEl.textContent = '₱' + S.svcPrice.toFixed(2) + ' × ' + count + ' pets';
            } else if (S.svcPrice > 0) {
                sumPriceEl.textContent = '₱' + S.svcPrice.toFixed(2);
            } else {
                sumPriceEl.textContent = "Price Varies";
            }
        }

        const sumTotEl = document.getElementById('sumEstimatedTotal');
        if (sumTotEl) sumTotEl.textContent = S.svcPriceTxt;
    }

    function selectVetCard(card) {
        document.querySelectorAll('.vet-card').forEach(c => {
            c.classList.remove('selected');
            c.style.border = '2px solid #e2e8f0';
            c.style.background = '#fff';
        });
        card.classList.add('selected');
        card.style.border = '2px solid #5b21b6';
        card.style.background = '#fcfaff';

        S.vetId   = card.dataset.vetId;
        S.vetName = card.dataset.vetName;
        S.vetSpec = card.dataset.vetSpec;
        updateSummaryWidget();
        // Re-render slots with the selected doctor's schedule
        if (S.date) renderDefaultTimeSlots();
    }

    let fpInstance = null;
    function initFlatpickr() {
        const el = document.getElementById('wizDatePicker');
        const wrapper = document.getElementById('calWrapper');
        if (!el || !wrapper) return;
        fpInstance = flatpickr(el, {
            inline: true,
            appendTo: wrapper,
            minDate: TOMORROW_STR,
            dateFormat: 'Y-m-d',
            disable: [
                // Sundays
                d => d.getDay() === 0,
                // Public holidays from DB
                d => {
                    const y = d.getFullYear();
                    const mo = String(d.getMonth() + 1).padStart(2, '0');
                    const dy = String(d.getDate()).padStart(2, '0');
                    return HOLIDAYS.includes(`${y}-${mo}-${dy}`);
                }
            ],
            onChange: function(sel, str) {
                onDateSelected(str);
            }
        });
    }

    function quickPickDate(offsetDays) {
        const d = new Date();
        d.setDate(d.getDate() + offsetDays);
        const str = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
        if (fpInstance) fpInstance.setDate(str, true);
        onDateSelected(str);
    }

    function onDateSelected(str) {
        S.date = str;
        S.time = '';
        S.timeLabel = '';

        const dObj = new Date(str + 'T12:00:00');
        const dtDisp = document.getElementById('slotDateDisplay');
        if (dtDisp) dtDisp.textContent = dObj.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });

        // Show the selected-date badge
        const badge = document.getElementById('selDateBadge');
        if (badge) badge.style.display = 'inline-flex';

        renderDefaultTimeSlots();
        updateSummaryWidget();
    }

    // Convert "HH:MM" string to total minutes
    function timeStrToMins(t) {
        if (!t) return 0;
        const [h, m] = t.split(':').map(Number);
        return h * 60 + m;
    }

    function renderDefaultTimeSlots() {
        const grid = document.getElementById('slotsGrid');
        if (!grid) return;

        if (!S.date) {
            grid.innerHTML = '<div class="slots-ph"><i class="fa fa-calendar-day"></i>Select a date to see available time slots.</div>';
            return;
        }

        // All possible clinic time slots (30-min increments 08:00-17:00, skipping noon)
        const amTimes = ["08:00","08:30","09:00","09:30","10:00","10:30","11:00","11:30"];
        const pmTimes = ["13:00","13:30","14:00","14:30","15:00","15:30","16:00","16:30"];

        let html = '';
        const amBtns = amTimes.map(t => buildSlotBtn(t)).filter(b => b !== null);
        const pmBtns = pmTimes.map(t => buildSlotBtn(t)).filter(b => b !== null);

        if (amBtns.length > 0) {
            html += '<div class="slot-section-lbl"><i class="fa fa-sun" style="color:#f59e0b;"></i> Morning</div>';
            html += amBtns.join('');
        }
        if (pmBtns.length > 0) {
            html += '<div class="slot-section-lbl"><i class="fa fa-cloud-sun" style="color:#6366f1;"></i> Afternoon</div>';
            html += pmBtns.join('');
        }
        if (amBtns.length === 0 && pmBtns.length === 0) {
            html = '<div class="slots-ph"><i class="fa fa-times-circle" style="color:#fca5a5;"></i>No available slots for this date. Please pick another day.</div>';
        }
        grid.innerHTML = html;
    }

    function buildSlotBtn(t) {
        const lbl = fmt12(t);
        const [h, m] = t.split(':').map(Number);
        const slotStartMins = h * 60 + m;
        const slotDur       = S.svcDur || 30;
        const slotEndMins   = slotStartMins + slotDur;

        // ── Doctor schedule constraints ───────────────────────────────────
        const docSched   = S.vetId ? DOCTOR_SCHEDULES[S.vetId] : null;
        const workStart  = docSched ? timeStrToMins(docSched.work_start)  : 8  * 60;
        const workEnd    = docSched ? timeStrToMins(docSched.work_end)    : 17 * 60;
        const breakStart = docSched && docSched.break_start ? timeStrToMins(docSched.break_start) : 12 * 60;
        const breakEnd   = docSched && docSched.break_end   ? timeStrToMins(docSched.break_end)   : 13 * 60;
        const bufferMins = docSched ? parseInt(docSched.buffer_mins) : 15;

        // Outside working hours → hide slot entirely
        if (slotStartMins < workStart || slotEndMins > workEnd) return null;

        // Overlaps break time → hide slot
        if (!(slotEndMins <= breakStart || slotStartMins >= breakEnd)) return null;

        // ── Duration-aware conflict check ─────────────────────────────────
        const bookedOnDate = BOOKED_DETAILED[S.date] || [];
        const isConflict = bookedOnDate.some(b => {
            const bWinStart = b.s - bufferMins;
            const bWinEnd   = b.s + b.d + bufferMins;
            return !(slotEndMins <= bWinStart || slotStartMins >= bWinEnd);
        });

        if (isConflict) {
            return `<button type="button" class="appt-slot-btn booked" disabled title="Already booked">
                        <div>${lbl}</div>
                        <div style="font-size:9px;color:#dc2626;font-weight:800;margin-top:1px;">BOOKED</div>
                    </button>`;
        }
        const isSel = (S.time === t);
        return `<button type="button" class="appt-slot-btn${isSel ? ' selected' : ''}" data-time="${t}" data-label="${lbl}" onclick="selectSlotBtn(this)">${lbl}</button>`;
    }

    function selectSlotBtn(btn) {
        if (btn.disabled || btn.classList.contains('booked')) return;
        document.querySelectorAll('.appt-slot-btn').forEach(b => {
            if (!b.classList.contains('booked')) b.classList.remove('selected');
        });
        btn.classList.add('selected');
        S.time = btn.dataset.time;
        S.timeLabel = btn.dataset.label;
        updateSummaryWidget();
        // Trigger smart suggestion analysis with slight delay for feel
        setTimeout(analyzeAndSuggest, 350);
    }

    function fmt12(t) {
        const [h, m] = t.split(':').map(Number);
        const h12 = h > 12 ? h - 12 : (h || 12);
        return `${h12}:${String(m).padStart(2, '0')} ${h >= 12 ? 'PM' : 'AM'}`;
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  SMART SCHEDULING SUGGESTION ENGINE
    // ══════════════════════════════════════════════════════════════════════════
    let _pendingSuggestion = null;

    function analyzeAndSuggest() {
        if (!S.date || !S.time) { hideSuggestionUI(); return; }

        const suggestion = computeSuggestion();
        if (suggestion === 'optimal') {
            showOptimalBadge();
        } else if (suggestion) {
            _pendingSuggestion = suggestion;
            showSuggestionCard(suggestion);
        } else {
            hideSuggestionUI();
        }
    }

    function computeSuggestion() {
        const [sh, sm] = S.time.split(':').map(Number);
        const selMins    = sh * 60 + sm;
        const curLoad    = APPT_DENSITY[S.date] || 0;
        const docSched   = S.vetId ? DOCTOR_SCHEDULES[S.vetId] : null;
        const bufferMins = docSched ? parseInt(docSched.buffer_mins) : 15;

        // ── Rule 1: Afternoon selected → suggest morning ─────────────────────
        if (selMins >= 13 * 60) {
            const morn = findEarliestFreeSlot(S.date, 8 * 60, 12 * 60);
            if (morn) {
                return {
                    type: 'time', date: S.date, time: morn,
                    reason: '🌅 <strong>Morning slots are recommended.</strong> Your pet will be seen when the doctor is most alert, and you\'ll likely experience shorter wait times.',
                    tag: 'Better Time of Day',
                    load: curLoad
                };
            }
        }

        // ── Rule 2: Busy day (≥6 appts) → suggest lighter nearby day ────────
        if (curLoad >= 6) {
            const lighter = findLighterDay();
            if (lighter) {
                const slot = findEarliestFreeSlot(lighter.date, 8 * 60, 12 * 60)
                           || findEarliestFreeSlot(lighter.date, 13 * 60, 17 * 60);
                if (slot) {
                    return {
                        type: 'day', date: lighter.date, time: slot,
                        reason: `📊 <strong>${formatDateLabel(S.date)}</strong> is getting busy with <strong>${curLoad} appointments</strong>. <strong>${formatDateLabel(lighter.date)}</strong> has only <strong>${lighter.count}</strong> — your pet will receive more dedicated care time.`,
                        tag: 'Less Busy Day',
                        load: lighter.count
                    };
                }
            }
        }

        // ── Rule 3: Not earliest slot → suggest earlier same day ─────────────
        if (selMins > 9 * 60 && selMins < 13 * 60) {
            const earlier = findEarliestFreeSlot(S.date, 8 * 60, selMins);
            if (earlier && earlier !== S.time) {
                return {
                    type: 'time', date: S.date, time: earlier,
                    reason: '⚡ <strong>An earlier slot is available today.</strong> You can arrive sooner and get your pet checked before the mid-morning rush.',
                    tag: 'Earlier Available Slot',
                    load: curLoad
                };
            }
        }

        // Already optimal
        return 'optimal';
    }

    function findEarliestFreeSlot(date, fromMins, toMins) {
        const allSlots = ["08:00","08:30","09:00","09:30","10:00","10:30","11:00","11:30",
                          "13:00","13:30","14:00","14:30","15:00","15:30","16:00","16:30"];
        const docSched   = S.vetId ? DOCTOR_SCHEDULES[S.vetId] : null;
        const bufferMins = docSched ? parseInt(docSched.buffer_mins) : 15;
        const svcDur     = S.svcDur || 30;
        const bookedOnDate = BOOKED_DETAILED[date] || [];

        for (const t of allSlots) {
            const [h, m] = t.split(':').map(Number);
            const tMins    = h * 60 + m;
            const tEndMins = tMins + svcDur;
            if (tMins < fromMins || tMins >= toMins) continue;
            const conflict = bookedOnDate.some(b => {
                const bWin = [b.s - bufferMins, b.s + b.d + bufferMins];
                return !(tEndMins <= bWin[0] || tMins >= bWin[1]);
            });
            if (!conflict) return t;
        }
        return null;
    }

    function findLighterDay() {
        const selectedDate = new Date(S.date + 'T00:00:00');
        const threshold    = Math.max(0, (APPT_DENSITY[S.date] || 0) - 2);
        let best = null, bestCnt = 999;
        for (let offset = 1; offset <= 14; offset++) {
            const d = new Date(selectedDate);
            d.setDate(d.getDate() + offset);
            if (d.getDay() === 0) continue; // skip Sunday
            const dStr = d.toISOString().split('T')[0];
            if (HOLIDAYS.includes(dStr)) continue;
            const cnt = APPT_DENSITY[dStr] || 0;
            if (cnt <= threshold && cnt < bestCnt) { bestCnt = cnt; best = { date: dStr, count: cnt }; }
        }
        return best;
    }

    function formatDateLabel(dateStr) {
        const d = new Date(dateStr + 'T00:00:00');
        const days   = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        return `${days[d.getDay()]}, ${months[d.getMonth()]} ${d.getDate()}`;
    }

    function buildLoadDots(count) {
        const filled = Math.min(5, Math.round((count / MAX_DAILY_APPTS) * 5));
        let html = '';
        for (let i = 0; i < 5; i++) {
            let color;
            if (i >= filled) { color = '#e2e8f0'; }
            else if (filled >= 4) { color = '#ef4444'; }
            else if (filled >= 3) { color = '#f59e0b'; }
            else { color = '#10b981'; }
            html += `<span style="width:11px;height:11px;border-radius:50%;background:${color};display:inline-block;"></span>`;
        }
        return html;
    }

    function showSuggestionCard(s) {
        const box     = document.getElementById('smartSuggestionBox');
        const optBox  = document.getElementById('smartOptimalBox');
        const reason  = document.getElementById('suggestionReason');
        const dtLbl   = document.getElementById('suggestionDateTimeLabel');
        const tagEl   = document.getElementById('suggestionReasonTag');
        const dotsEl  = document.getElementById('suggestionLoadDots');

        if (optBox) optBox.style.display = 'none';
        if (!box) return;

        const dateLabel = formatDateLabel(s.date);
        const timeLabel = fmt12(s.time);

        if (reason)  reason.innerHTML  = s.reason;
        if (dtLbl)   dtLbl.textContent = `${dateLabel} · ${timeLabel}`;
        if (tagEl)   tagEl.textContent = s.tag;
        if (dotsEl)  dotsEl.innerHTML  = buildLoadDots(s.load);

        box.style.display    = 'block';
        box.style.opacity    = '0';
        box.style.transform  = 'translateY(8px)';
        box.style.transition = 'all .35s cubic-bezier(.4,0,.2,1)';
        requestAnimationFrame(() => {
            box.style.opacity   = '1';
            box.style.transform = 'translateY(0)';
        });
    }

    function showOptimalBadge() {
        const box    = document.getElementById('smartSuggestionBox');
        const optBox = document.getElementById('smartOptimalBox');
        if (box)    box.style.display    = 'none';
        if (optBox) {
            optBox.style.display    = 'flex';
            optBox.style.opacity    = '0';
            optBox.style.transform  = 'translateY(6px)';
            optBox.style.transition = 'all .35s';
            requestAnimationFrame(() => {
                optBox.style.opacity   = '1';
                optBox.style.transform = 'translateY(0)';
            });
        }
        _pendingSuggestion = null;
    }

    function hideSuggestionUI() {
        const box    = document.getElementById('smartSuggestionBox');
        const optBox = document.getElementById('smartOptimalBox');
        if (box)    box.style.display = 'none';
        if (optBox) optBox.style.display = 'none';
        _pendingSuggestion = null;
    }

    function acceptSuggestion(e) {
        if (e) {
            e.preventDefault();
            e.stopPropagation();
        }
        if (!_pendingSuggestion) return;
        const s = _pendingSuggestion;

        // Apply the suggestion to global appointment state
        S.date      = s.date;
        S.time      = s.time;
        S.timeLabel = fmt12(s.time);

        // Update flatpickr calendar to the new date WITHOUT triggering onChange (pass false)
        if (fpInstance) {
            fpInstance.setDate(s.date, false);
        }

        // Update the date display badge
        const badge   = document.getElementById('selDateBadge');
        const dtDisp  = document.getElementById('slotDateDisplay');
        if (badge)  badge.style.display = 'inline-flex';
        if (dtDisp) dtDisp.textContent  = formatDateLabel(s.date);

        // Capture notes & location preferences from Step 4 inputs
        const notes = document.getElementById('wizNotes');
        if (notes) S.notes = notes.value;
        const homeAddr = document.getElementById('wizHomeAddr');
        if (homeAddr && S.loc === 'Home Service') S.addr = homeAddr.value;

        // Re-render slots for new date (suggested slot will be marked selected)
        renderDefaultTimeSlots();

        hideSuggestionUI();
        _pendingSuggestion = null;
        clearErr(4);
        updateSummaryWidget();

        // Immediately transition directly to Step 5: Review & Confirm
        showStep(5);
    }

    function keepSelection() {
        const box    = document.getElementById('smartSuggestionBox');
        const optBox = document.getElementById('smartOptimalBox');
        const fadeOut = el => {
            if (!el) return;
            el.style.transition = 'all .25s';
            el.style.opacity    = '0';
            el.style.transform  = 'translateY(-6px)';
            setTimeout(() => { el.style.display = 'none'; el.style.opacity = '1'; el.style.transform = ''; }, 260);
        };
        fadeOut(box);
        fadeOut(optBox);
        _pendingSuggestion = null;
    }

    function toggleLocation(radio) {
        S.loc = radio.value;
        const box = document.getElementById('homeAddrBox');
        if (box) box.style.display = (radio.value === 'Home Service') ? 'block' : 'none';
        updateSummaryWidget();
    }

    function updateSummaryWidget() {
        const cName = document.getElementById('sumClientName');
        if (cName) cName.textContent = S.clientName || '—';
        const cPhone = document.getElementById('sumClientPhone');
        if (cPhone) cPhone.textContent = S.clientPhone || '—';

        const pName = document.getElementById('sumPetName');
        const pSub = document.getElementById('sumPetSub');
        const pAge = document.getElementById('sumPetAge');
        if (pName) {
            if (S.pets.length > 0) {
                pName.textContent = S.pets.map(p => p.name).join(', ');
                if (pSub) pSub.textContent = S.pets[0].spec || 'Pet Profile';
                if (pAge) pAge.textContent = S.pets[0].age || 'Active Profile';
            } else {
                pName.textContent = 'Select a pet';
                if (pSub) pSub.textContent = '';
                if (pAge) pAge.textContent = '';
            }
        }

        const sName = document.getElementById('sumServiceName');
        if (sName) sName.textContent = S.svcName;

        const vNameEl = document.getElementById('sumVetName');
        if (vNameEl) {
            if (S.vetName) {
                vNameEl.textContent = S.vetName;
                vNameEl.style.color = '#1e1b4b';
            } else {
                vNameEl.textContent = 'Not selected yet';
                vNameEl.style.color = '#94a3b8';
            }
        }

        const dtEl = document.getElementById('sumDateTime');
        if (dtEl) {
            if (S.date && S.timeLabel) {
                const dObj = new Date(S.date + 'T12:00:00');
                const dStr = dObj.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                dtEl.textContent = dStr + ' @ ' + S.timeLabel;
                dtEl.style.color = '#1e1b4b';
            } else {
                dtEl.textContent = 'Not selected yet';
                dtEl.style.color = '#94a3b8';
            }
        }

        calcEstimatedTotal();
    }

    function fillReviewData() {
        const rClient = document.getElementById('rvClientName'); if (rClient) rClient.textContent = S.clientName;
        const rPet = document.getElementById('rvPetName'); if (rPet) rPet.textContent = S.pets.map(p => p.name).join(', ');
        const rSvc = document.getElementById('rvService'); if (rSvc) rSvc.textContent = S.svcName + ' (' + S.svcPriceTxt + ')';
        const rVet = document.getElementById('rvVet'); if (rVet) rVet.textContent = S.vetName || 'Any Available Doctor';
        const rDt = document.getElementById('rvDateTime'); if (rDt) rDt.textContent = (S.date ? S.date : '—') + ' at ' + (S.timeLabel ? S.timeLabel : '—');
        const rLoc = document.getElementById('rvLocation'); if (rLoc) rLoc.textContent = S.loc + (S.addr ? ' (' + S.addr + ')' : '');
    }

    function submitAppointment() {
        const btn = document.getElementById('submitBtn');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fa fa-spinner fa-spin me-2"></i>Submitting…';
        }

        const petVal = S.pets.length === 1
            ? S.pets[0].name
            : JSON.stringify(S.pets.map(p => ({ name: p.name, id: p.id })));

        const fPet = document.getElementById('f_pet'); if (fPet) fPet.value = petVal;
        const fSvc = document.getElementById('f_svc'); if (fSvc) fSvc.value = S.svcName;
        const fDate = document.getElementById('f_date'); if (fDate) fDate.value = S.date;
        const fTime = document.getElementById('f_time'); if (fTime) fTime.value = S.time;
        const fLoc = document.getElementById('f_loc'); if (fLoc) fLoc.value = S.loc;
        const fAddr = document.getElementById('f_addr'); if (fAddr) fAddr.value = S.addr;
        const fDoc = document.getElementById('f_doc'); if (fDoc) fDoc.value = S.vetId;
        const fNotes = document.getElementById('f_notes');
        const wizNotes = document.getElementById('wizNotes');
        if (fNotes && wizNotes) fNotes.value = wizNotes.value;

        const form = document.getElementById('wizForm');
        if (form) {
            const fd = new FormData(form);
            fd.append('return_appt_id', '1'); // Signal we want the appointment ID back
            fetch('appointments.php', { method: 'POST', body: fd })
                .then(async r => {
                    const text = await r.text();
                    // Try to extract appointment ID from response
                    const idMatch = text.match(/APPT_ID:(\d+)/);
                    const errorMatch = text.match(/APPT_ERROR:(.*)/);
                    if (idMatch && idMatch[1]) {
                        window._newApptId = idMatch[1];
                        showStep(6);
                    } else if (errorMatch && errorMatch[1]) {
                        alert("Booking Failed: " + errorMatch[1].replace(/<\/?[^>]+(>|$)/g, ""));
                        const btn = document.getElementById('submitBtn');
                        if (btn) {
                            btn.disabled = false;
                            btn.innerHTML = 'Confirm Booking &gt;';
                        }
                    } else {
                        alert("An unexpected error occurred. Please try again.");
                        window.location.reload();
                    }
                })
                .catch(() => {
                    alert('Network error while booking. Please try again.');
                    window.location.reload();
                });
        }
    }

    function payReservationFee() {
        if (!window._newApptId) {
            alert('Appointment ID not found. Please try again.');
            return;
        }
        document.getElementById('payReservationBtn').disabled = true;
        document.getElementById('payReservationBtn').innerHTML = '<i class="fa fa-spinner fa-spin me-2"></i> Redirecting...';
        document.getElementById('payment-loading-msg').style.display = 'block';
        window.location.href = 'appointments.php?initpay=reservation&appt_id=' + window._newApptId;
    }

    function skipReservationFee() {
        showStep(7);
    }

    function resetWizard() {
        S.step = 1;
        S.date = "";
        S.time = "";
        S.timeLabel = "";
        S.loc = "Clinic Visit";
        S.addr = "";
        // Reset location radio to Clinic Visit
        const clinicRadio = document.querySelector('input[name="wiz_location"][value="Clinic Visit"]');
        if (clinicRadio) { clinicRadio.checked = true; }
        const addrBox = document.getElementById('homeAddrBox');
        if (addrBox) addrBox.style.display = 'none';
        const notes = document.getElementById('wizNotes'); if (notes) notes.value = "";
        if (fpInstance) fpInstance.clear();
        // Hide date badge
        const badge = document.getElementById('selDateBadge');
        if (badge) badge.style.display = 'none';
        const slotsGrid = document.getElementById('slotsGrid');
        if (slotsGrid) slotsGrid.innerHTML = '<div class="slots-ph"><i class="fa fa-calendar-day"></i>Select a date to see available time slots.</div>';
        showStep(1);
    }

    // ── APPOINTMENT DETAIL & ACTION MODAL HANDLERS ──
    function openDetailModal(row) {
        if (!row) return;
        const dmTitle = document.getElementById('dmTitle');
        const dmService = document.getElementById('dmService');
        const dmPet = document.getElementById('dmPet');
        const dmApptId = document.getElementById('dmApptId');
        const dmStatus = document.getElementById('dmStatus');
        const dmDateTime = document.getElementById('dmDateTime');
        const dmLocation = document.getElementById('dmLocation');
        const dmVet = document.getElementById('dmVet');

        if (dmTitle) dmTitle.textContent = 'Appointment #' + row.id;
        if (dmService) dmService.textContent = row.service_type || 'General Consultation';
        if (dmPet) dmPet.textContent = 'Pet: ' + (row.pet_name || 'Pet');
        if (dmApptId) dmApptId.textContent = '#' + row.id;
        if (dmStatus) dmStatus.textContent = row.status || 'Pending';
        if (dmDateTime) dmDateTime.textContent = (row.appointment_date || '—') + (row.appointment_time ? ' at ' + row.appointment_time : '');
        if (dmLocation) dmLocation.textContent = row.service_location || 'On-Site';
        if (dmVet) dmVet.textContent = row.doctor_name || row.doctor_fullname || 'Any Available Doctor';

        // Audit Trail
        const auditWrap = document.getElementById('dmAuditTrail');
        if (auditWrap) {
            let html = '';
            
            function formatDateStr(dStr) {
                if (!dStr) return '';
                const d = new Date(dStr);
                return isNaN(d) ? dStr : d.toLocaleString();
            }

            if (row.created_at) {
                html += `<li style="margin-bottom:6px;"><i class="fa fa-circle text-primary" style="font-size:8px; margin-right:6px; vertical-align:middle;"></i> <strong>Booked:</strong> ${formatDateStr(row.created_at)}</li>`;
            }
            if (row.downpayment_paid_at) {
                html += `<li style="margin-bottom:6px;"><i class="fa fa-circle text-warning" style="font-size:8px; margin-right:6px; vertical-align:middle;"></i> <strong>Payment Captured:</strong> ${formatDateStr(row.downpayment_paid_at)}</li>`;
            }
            if (row.approved_at) {
                html += `<li style="margin-bottom:6px;"><i class="fa fa-circle text-success" style="font-size:8px; margin-right:6px; vertical-align:middle;"></i> <strong>Approved:</strong> ${formatDateStr(row.approved_at)}</li>`;
            }
            
            if (row.status === 'Cancelled' || row.status === 'Rejected') {
                html += `<li style="margin-bottom:6px;"><i class="fa fa-circle text-danger" style="font-size:8px; margin-right:6px; vertical-align:middle;"></i> <strong>${row.status}:</strong> (Updated by Clinic/User)</li>`;
            } else if (row.status === 'Completed') {
                html += `<li style="margin-bottom:6px;"><i class="fa fa-circle text-info" style="font-size:8px; margin-right:6px; vertical-align:middle;"></i> <strong>Completed:</strong> (Service Delivered)</li>`;
            }

            if (html === '') {
                html = '<li>No audit trail records available.</li>';
            }
            auditWrap.innerHTML = html;
        }

        // Notes
        const notesWrap = document.getElementById('dmNotesWrap');
        const notesVal = document.getElementById('dmNotes');
        if (notesWrap && notesVal) {
            if (row.notes || row.admin_message) {
                notesVal.textContent = row.admin_message ? ('Clinic Message: ' + row.admin_message + (row.notes ? '\nYour Notes: ' + row.notes : '')) : row.notes;
                notesWrap.style.display = 'block';
            } else {
                notesWrap.style.display = 'none';
            }
        }

        // Reschedule proposal alert banner
        const alertBox = document.getElementById('dmRescheduleAlert');
        const pText = document.getElementById('dmProposalText');
        const accId = document.getElementById('dmAcceptAptId');
        const decId = document.getElementById('dmDeclineAptId');
        if (alertBox && pText && accId && decId) {
            if (row.client_action_needed == 1 && row.proposed_reschedule_date) {
                alertBox.style.display = 'block';
                accId.value = row.id;
                decId.value = row.id;
                const pTime = row.proposed_reschedule_time ? ' at ' + row.proposed_reschedule_time : '';
                pText.innerHTML = 'The clinic proposed shifting this visit to <strong>' + row.proposed_reschedule_date + pTime + '</strong>.' + (row.admin_message ? '<br><em>Reason: ' + row.admin_message + '</em>' : '');
            } else {
                alertBox.style.display = 'none';
            }
        }

        // Reset reschedule form
        toggleRescheduleForm(false);
        const reqId = document.getElementById('dmReqReschedId');
        if (reqId) reqId.value = row.id;

        // Action buttons in footer
        const footer = document.getElementById('dmFooterActions');
        if (footer) {
            footer.innerHTML = '';
            const status = row.status || '';
            const isCancelable = (status === 'Pending' || status === 'Approved');

            if (isCancelable) {
                // Request Reschedule Button
                const btnResched = document.createElement('button');
                btnResched.type = 'button';
                btnResched.className = 'btn-action gray';
                btnResched.innerHTML = '<i class="fa fa-calendar-alt me-1"></i> Request Reschedule';
                btnResched.onclick = function() { toggleRescheduleForm(true); };
                footer.appendChild(btnResched);

                // Cancel Appointment Button
                const cancelForm = document.createElement('form');
                cancelForm.method = 'POST';
                cancelForm.style.flex = '1';
                cancelForm.onsubmit = function() { return confirm('Are you sure you want to cancel this appointment?'); };
                cancelForm.innerHTML = `
                    <input type="hidden" name="cancel_appt" value="1">
                    <input type="hidden" name="cancel_appt_id" value="${row.id}">
                    <input type="hidden" name="appt_type" value="Appointment">
                    <button type="submit" class="btn-action red w-100">
                        <i class="fa fa-times me-1"></i> Cancel Appointment
                    </button>
                `;
                footer.appendChild(cancelForm);
            } else if (status === 'Completed') {
                const btnBill = document.createElement('a');
                btnBill.href = 'billing.php';
                btnBill.className = 'btn-action green';
                btnBill.style.textAlign = 'center';
                btnBill.style.textDecoration = 'none';
                btnBill.innerHTML = '<i class="fa fa-file-invoice-dollar me-1"></i> View Billing';
                footer.appendChild(btnBill);
            }

            // Close button
            const btnClose = document.createElement('button');
            btnClose.type = 'button';
            btnClose.className = 'btn-action gray';
            btnClose.style.flex = '0 0 auto';
            btnClose.textContent = 'Close';
            btnClose.onclick = closeDetailModal;
            footer.appendChild(btnClose);
        }

        // Open modal
        const bd = document.getElementById('detailModalBackdrop');
        if (bd) bd.classList.add('open');
    }

    function closeDetailModal() {
        const bd = document.getElementById('detailModalBackdrop');
        if (bd) bd.classList.remove('open');
        toggleRescheduleForm(false);
    }

    function toggleRescheduleForm(show) {
        const wrap = document.getElementById('dmRescheduleFormWrap');
        if (wrap) wrap.style.display = show ? 'block' : 'none';
    }

    // Filter tabs listener
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.filter-tab').forEach(tab => {
            tab.addEventListener('click', function() {
                document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
                this.classList.add('active');
                const filter = this.dataset.filter;
                document.querySelectorAll('.appt-row').forEach(row => {
                    const status = row.dataset.status;
                    const actionNeeded = row.dataset.actionNeeded;
                    if (filter === 'all') {
                        row.style.display = '';
                    } else if (filter === 'action_needed') {
                        row.style.display = (actionNeeded === '1') ? '' : 'none';
                    } else {
                        row.style.display = (status === filter) ? '' : 'none';
                    }
                });
            });
        });
    });
    

            document.addEventListener('DOMContentLoaded', function () {
                var fbModal = new bootstrap.Modal(document.getElementById('autoFeedbackModal'));
                fbModal.show();

                // Star Interaction
                const stars = document.querySelectorAll('.star-item');
                stars.forEach(s => {
                    s.addEventListener('click', function () {
                        const val = this.getAttribute('data-value');
                        document.getElementById('feedbackRatingInput').value = val;
                        stars.forEach(st => {
                            if (st.getAttribute('data-value') <= val) st.classList.add('active');
                            else st.classList.remove('active');
                        });
                    });
                });
            });

            function submitFeedbackAjax() {
                const rating = document.getElementById('feedbackRatingInput').value;
                if (rating == "0") {
                    alert("Please select a rating star first.");
                    return;
                }

                const btn = document.querySelector('.feedback-btn');
                const originalText = btn.innerHTML;
                btn.innerHTML = '<i class="fa fa-spinner fa-spin me-2"></i> SENDING...';
                btn.disabled = true;

                const formData = new FormData(document.getElementById('feedbackForm'));
                fetch('feedback.php', {
                    method: 'POST',
                    body: formData
                })
                    .then(() => {
                        btn.innerHTML = '<i class="fa fa-check me-2"></i> THANK YOU!';
                        btn.style.background = '#10b981';
                        setTimeout(() => {
                            bootstrap.Modal.getInstance(document.getElementById('autoFeedbackModal')).hide();
                        }, 1500);
                    })
                    .catch(() => {
                        alert("Something went wrong. Please try again.");
                        btn.innerHTML = originalText;
                        btn.disabled = false;
                    });
            }
        
