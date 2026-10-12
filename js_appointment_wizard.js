/**
 * Appointment Scheduling Wizard
 * Step-by-step booking form with validation and smooth transitions
 */

function togglePetCard(card) {
    card.classList.toggle('selected');
    var selected = document.querySelectorAll('.pet-select-card.selected');
    var pets = Array.from(selected).map(function(c) {
        return { id: parseInt(c.dataset.petId), name: c.dataset.petName };
    });
    var hidden = document.getElementById('petNameHidden');
    if (hidden) hidden.value = pets.length > 0 ? JSON.stringify(pets) : '';
    var badge = document.getElementById('multiPetBadge');
    var label = document.getElementById('petCountLabel');
    if (badge && label) {
        label.textContent = pets.length;
        badge.style.display = pets.length > 0 ? 'block' : 'none';
    }
    // Update wizard review step if shown
    var reviewPet = document.getElementById('review-pet');
    if (reviewPet) {
        reviewPet.textContent = pets.map(function(p){ return p.name; }).join(', ') || 'None selected';
    }
}

(function () {
    'use strict';

    // ── State ──────────────────────────────────────────────────────────────
    const state = {
        currentStep: 1,
        totalSteps: 3,
        data: {
            pet_name: '',
            service_type: '',
            service_location: 'On-Site',
            home_address: '',
            appointment_date: '',
            appointment_time: '',
            end_date: '',
            doctor_id: '',
            signature_data: ''
        },
        validated: { 1: false, 2: false, 3: false }
    };

    // ── DOM refs ───────────────────────────────────────────────────────────
    let els = {};

    function cacheElements() {
        els = {
            wizard: document.getElementById('bookingWizard'),
            steps: document.querySelectorAll('.wz-step'),
            indicators: document.querySelectorAll('.wz-dot'),
            progressBar: document.querySelector('.wz-progress-fill'),
            prevBtn: document.getElementById('wzPrev'),
            nextBtn: document.getElementById('wzNext'),
            submitBtn: document.getElementById('wzSubmit'),
            form: document.getElementById('appointmentForm'),
            // Step 1
            petSelect: document.querySelector('[name="pet_name"]'),
            serviceSelect: document.querySelector('[name="service_type"]'),
            locRadios: document.querySelectorAll('[name="service_location"]'),
            locSite: document.getElementById('btnSite'),
            locHome: document.getElementById('btnHome'),
            homeAddrContainer: document.getElementById('homeAddressContainer'),
            homeAddrInput: document.getElementById('homeAddressInput'),
            // Step 2
            dateInput: document.getElementById('appointmentDate'),
            timeInput: document.getElementById('appointmentTime'),
            endDateContainer: document.getElementById('endDateContainer'),
            endDateInput: document.getElementById('endDateInput'),
            doctorSelect: document.getElementById('doctorSelect'),
            slotsContainer: document.getElementById('availableSlotsContainer'),
            slotConfirm: document.getElementById('slotConfirmation'),
            // Step 3
            reviewPet: document.getElementById('reviewPet'),
            reviewService: document.getElementById('reviewService'),
            reviewLocation: document.getElementById('reviewLocation'),
            reviewAddress: document.getElementById('reviewAddress'),
            reviewDate: document.getElementById('reviewDate'),
            reviewTime: document.getElementById('reviewTime'),
            reviewDoctor: document.getElementById('reviewDoctor'),
            reviewEndDate: document.getElementById('reviewEndDate'),
            reviewSlotConfirm: document.getElementById('reviewSlotConfirm'),
        };
    }

    // ── Navigation ─────────────────────────────────────────────────────────
    function goToStep(step) {
        if (step < 1 || step > state.totalSteps) return;

        // Validate when moving forward
        if (step > state.currentStep) {
            if (!validateStep(state.currentStep)) {
                shakeStep(state.currentStep);
                return;
            }
        }

        state.currentStep = step;
        renderStep();
        updateProgress();
        updateButtons();
        scrollToWizard();
    }

    function renderStep() {
        els.steps.forEach((el, i) => {
            const stepNum = i + 1;
            el.classList.toggle('wz-active', stepNum === state.currentStep);
            el.classList.toggle('wz-done', stepNum < state.currentStep);
        });
    }

    function updateProgress() {
        const pct = ((state.currentStep - 1) / (state.totalSteps - 1)) * 100;
        if (els.progressBar) {
            els.progressBar.style.width = pct + '%';
        }
        els.indicators.forEach((dot, i) => {
            const stepNum = i + 1;
            dot.classList.toggle('wz-dot-active', stepNum === state.currentStep);
            dot.classList.toggle('wz-dot-done', stepNum < state.currentStep);
        });
    }

    function updateButtons() {
        if (els.prevBtn) {
            els.prevBtn.style.display = state.currentStep === 1 ? 'none' : 'flex';
        }
        if (els.nextBtn) {
            els.nextBtn.style.display = state.currentStep === state.totalSteps ? 'none' : 'flex';
        }
        if (els.submitBtn) {
            els.submitBtn.style.display = state.currentStep === state.totalSteps ? 'flex' : 'none';
        }
    }

    function scrollToWizard() {
        if (els.wizard) {
            const rect = els.wizard.getBoundingClientRect();
            if (rect.top < 0 || rect.top > 200) {
                els.wizard.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }
    }

    function shakeStep(step) {
        const el = document.querySelector(`.wz-step[data-step="${step}"]`);
        if (!el) return;
        el.classList.add('wz-shake');
        setTimeout(() => el.classList.remove('wz-shake'), 500);
    }

    // ── Validation ─────────────────────────────────────────────────────────
    function validateStep(step) {
        switch (step) {
            case 1:
                return validateStep1();
            case 2:
                return validateStep2();
            case 3:
                return true; // Review step - no validation needed
            default:
                return false;
        }
    }

    function validateStep1() {
        const petHidden = document.getElementById('petNameHidden');
        const pet = petHidden ? petHidden.value : '';
        const service = els.serviceSelect ? els.serviceSelect.value : '';
        const location = getSelectedLocation();
        const homeAddr = els.homeAddrInput ? els.homeAddrInput.value.trim() : '';

        if (!pet || pet === '[]' || pet === '') {
            const grid = document.getElementById('petCardGrid');
            if (grid) showFieldError(grid, 'Please select at least one pet');
            return false;
        }
        if (!service) {
            showFieldError(els.serviceSelect, 'Please select a service type');
            return false;
        }
        if (location === 'Home Service' && !homeAddr) {
            showFieldError(els.homeAddrInput, 'Please enter your home address');
            return false;
        }

        // Store data
        state.data.pet_name = pet;
        state.data.service_type = service;
        state.data.service_location = location;
        state.data.home_address = homeAddr;

        // Update review
        if (els.reviewPet) {
            try {
                let parsed = JSON.parse(pet);
                els.reviewPet.textContent = parsed.map(p => p.name).join(', ');
            } catch(e) {
                els.reviewPet.textContent = pet;
            }
        }
        if (els.reviewService) els.reviewService.textContent = service;
        if (els.reviewLocation) {
            els.reviewLocation.textContent = location;
            els.reviewLocation.className = location === 'Home Service' ? 'text-info' : 'text-secondary';
        }
        if (els.reviewAddress) {
            els.reviewAddress.parentElement.style.display = location === 'Home Service' ? 'flex' : 'none';
            if (location === 'Home Service') els.reviewAddress.textContent = homeAddr;
        }

        return true;
    }

    function validateStep2() {
        const date = els.dateInput ? els.dateInput.value : '';
        const time = els.timeInput ? els.timeInput.value : '';
        const doctor = els.doctorSelect ? els.doctorSelect.value : '';
        const endDate = els.endDateInput ? els.endDateInput.value : '';

        if (!date) {
            showFieldError(els.dateInput, 'Please select an appointment date');
            return false;
        }
        if (!time) {
            showFieldError(els.timeInput, 'Please select a time slot');
            return false;
        }

        // Validate end date if required
        const service = state.data.service_type;
        if ((service === 'Confinement' || service === 'Pet Boarding Reservation') && !endDate) {
            showFieldError(els.endDateInput, 'Please select a discharge/end date');
            return false;
        }

        // Store data
        state.data.appointment_date = date;
        state.data.appointment_time = time;
        state.data.doctor_id = doctor;
        state.data.end_date = endDate;

        // Update review
        if (els.reviewDate) {
            const d = new Date(date + 'T00:00:00');
            els.reviewDate.textContent = d.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
        }
        if (els.reviewTime) {
            els.reviewTime.textContent = formatTime12h(time);
        }
        if (els.reviewDoctor) {
            const docOpt = els.doctorSelect ? els.doctorSelect.options[els.doctorSelect.selectedIndex] : null;
            els.reviewDoctor.textContent = docOpt && docOpt.value ? docOpt.text : 'No Preference (Assigned by Admin)';
        }
        if (els.reviewEndDate) {
            if (endDate) {
                const ed = new Date(endDate + 'T00:00:00');
                els.reviewEndDate.textContent = ed.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
                els.reviewEndDate.parentElement.style.display = 'flex';
            } else {
                els.reviewEndDate.parentElement.style.display = 'none';
            }
        }

        // Show slot confirmation in review
        if (els.reviewSlotConfirm && els.slotConfirm) {
            const clone = els.slotConfirm.cloneNode(true);
            els.reviewSlotConfirm.innerHTML = '';
            els.reviewSlotConfirm.appendChild(clone);
        }

        return true;
    }

    function showFieldError(el, msg) {
        if (!el) return;
        el.focus();
        el.classList.add('wz-error');
        // Remove error class on change
        const handler = function () {
            el.classList.remove('wz-error');
            el.removeEventListener('change', handler);
            el.removeEventListener('input', handler);
        };
        el.addEventListener('change', handler);
        el.addEventListener('input', handler);

        // Show a subtle tooltip-like message
        const parent = el.closest('.mb-3') || el.parentElement;
        let errEl = parent.querySelector('.wz-field-error');
        if (!errEl) {
            errEl = document.createElement('div');
            errEl.className = 'wz-field-error';
            errEl.style.cssText = 'color:#e11d48;font-size:11px;font-weight:600;margin-top:4px;display:flex;align-items:center;gap:4px;';
            parent.appendChild(errEl);
        }
        errEl.innerHTML = '<i class="fa fa-exclamation-circle"></i> ' + msg;
        setTimeout(() => { if (errEl.parentNode) errEl.remove(); }, 3000);
    }

    // ── Helpers ────────────────────────────────────────────────────────────
    function getSelectedLocation() {
        let loc = 'On-Site';
        els.locRadios.forEach(r => { if (r.checked) loc = r.value; });
        return loc;
    }

    function formatTime12h(t) {
        if (!t) return '';
        const parts = t.split(':');
        let h = parseInt(parts[0]);
        const m = parts[1] || '00';
        const ap = h >= 12 ? 'PM' : 'AM';
        h = h % 12 || 12;
        return h + ':' + m + ' ' + ap;
    }

    // ── Event Handlers ─────────────────────────────────────────────────────
    function onNext() {
        goToStep(state.currentStep + 1);
    }

    function onPrev() {
        goToStep(state.currentStep - 1);
    }

    function onSubmit(e) {
        if (!validateStep(1) || !validateStep(2)) {
            e.preventDefault();
            // Go to the first invalid step
            if (!validateStep(1)) {
                goToStep(1);
            } else {
                goToStep(2);
            }
            return;
        }

        // Sync all form fields before submit
        syncFormFields();
    }

    function syncFormFields() {
        // Set all form values from state
        const form = els.form;
        if (!form) return;

        setFieldValue(form, 'pet_name', state.data.pet_name);
        setFieldValue(form, 'service_type', state.data.service_type);
        setFieldValue(form, 'service_location', state.data.service_location);
        setFieldValue(form, 'home_address', state.data.home_address);
        setFieldValue(form, 'appointment_date', state.data.appointment_date);
        setFieldValue(form, 'appointment_time', state.data.appointment_time);
        setFieldValue(form, 'end_date', state.data.end_date);
        setFieldValue(form, 'doctor_id', state.data.doctor_id);
    }

    function setFieldValue(form, name, value) {
        const field = form.querySelector(`[name="${name}"]`);
        if (field) field.value = value;
    }

    // ── Location Toggle Integration ────────────────────────────────────────
    function setupLocationToggle() {
        if (els.locSite) {
            els.locSite.addEventListener('click', function () {
                document.getElementById('locSite').checked = true;
                document.getElementById('locHome').checked = false;
                els.locSite.classList.add('active');
                els.locHome.classList.remove('active');
                if (els.homeAddrContainer) els.homeAddrContainer.style.display = 'none';
                if (els.homeAddrInput) els.homeAddrInput.removeAttribute('required');
            });
        }
        if (els.locHome) {
            els.locHome.addEventListener('click', function () {
                document.getElementById('locSite').checked = false;
                document.getElementById('locHome').checked = true;
                els.locHome.classList.add('active');
                els.locSite.classList.remove('active');
                if (els.homeAddrContainer) els.homeAddrContainer.style.display = 'block';
                if (els.homeAddrInput) els.homeAddrInput.setAttribute('required', 'required');
            });
        }
    }

    // ── End Date Toggle ────────────────────────────────────────────────────
    function setupEndDateToggle() {
        const sel = els.serviceSelect;
        if (!sel) return;
        function toggle() {
            const v = sel.value;
            const show = v === 'Confinement' || v === 'Pet Boarding Reservation';
            if (els.endDateContainer) {
                els.endDateContainer.style.display = show ? 'block' : 'none';
            }
            if (els.endDateInput) {
                if (show) els.endDateInput.setAttribute('required', 'required');
                else els.endDateInput.removeAttribute('required');
            }
        }
        sel.addEventListener('change', toggle);
        toggle();
    }

    // ── Init ───────────────────────────────────────────────────────────────
    function init() {
        cacheElements();
        if (!els.wizard) return;

        // Set up navigation
        if (els.nextBtn) els.nextBtn.addEventListener('click', onNext);
        if (els.prevBtn) els.prevBtn.addEventListener('click', onPrev);
        if (els.submitBtn) els.submitBtn.addEventListener('click', function (e) {
            // Let the form's own submit handler run
        });
        if (els.form) els.form.addEventListener('submit', onSubmit);

        // Set up location toggle
        setupLocationToggle();

        // Set up end date toggle
        setupEndDateToggle();

        // Initialize step 1
        goToStep(1);

        // Watch for slot selection to auto-update
        const origSelectSlot = window.selectTimeSlot;
        if (origSelectSlot) {
            window.selectTimeSlot = function (button) {
                origSelectSlot(button);
                // Update state
                state.data.appointment_time = button.getAttribute('data-time');
                state.data.doctor_id = button.getAttribute('data-doctor-id') || '';
            };
        }

        // Watch for date changes
        if (els.dateInput) {
            els.dateInput.addEventListener('change', function () {
                state.data.appointment_date = this.value;
            });
        }

        // Watch for doctor changes
        if (els.doctorSelect) {
            els.doctorSelect.addEventListener('change', function () {
                state.data.doctor_id = this.value;
            });
        }

        console.log('✅ Appointment Wizard initialized');
    }

    // Run on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();