/**
 * Smart Time Slot Picker
 * Shows only available appointment times based on date, service type, and doctor
 */

let smartSlotState = {
    selectedDate: null,
    selectedTime: null,
    serviceType: null,
    doctorId: null,
    availableSlots: [],
    isLoading: false
};

/**
 * Fetch available slots from API
 */
async function fetchAvailableSlots(date, serviceType, doctorId = null) {
    if (!date || !serviceType) {
        console.log('Missing date or service type');
        return [];
    }

    smartSlotState.isLoading = true;
    updateSlotPickerUI(true); // Show loading state

    try {
        let url = `api_appointment_slots.php?date=${encodeURIComponent(date)}&service_type=${encodeURIComponent(serviceType)}`;
        if (doctorId) {
            url += `&doctor_id=${doctorId}`;
        }

        const response = await fetch(url);
        const text = await response.text();
        let data;

        try {
            data = JSON.parse(text);
        } catch (parseError) {
            throw new Error('Invalid server response: ' + text);
        }

        if (!response.ok) {
            const reason = data.reason || data.error || data.message || 'Server returned an error while checking slots.';
            showErrorMessage(reason);
            smartSlotState.isLoading = false;
            return [];
        }

        if (data.available_slots && data.available_slots.length > 0) {
            smartSlotState.availableSlots = data.available_slots;
            updateSlotPickerUI(false, data);
            return data.available_slots;
        } else {
            updateSlotPickerUI(false, null);
            const reason = data.reason || data.message || 'No available slots for the selected date.';
            showNoSlotsMessage(date, reason);
            return [];
        }
    } catch (error) {
        console.error('Error fetching slots:', error);
        smartSlotState.isLoading = false;
        updateSlotPickerUI(false, null);
        showErrorMessage(error.message || 'Could not load available slots. Please try again.');
    }
}

/**
 * Update the UI with available slots
 */
function updateSlotPickerUI(isLoading, data = null) {
    const container = document.getElementById('availableSlotsContainer');
    if (!container) return;

    if (isLoading) {
        container.innerHTML = `
            <div style="text-align: center; padding: 20px;">
                <div style="font-size: 24px; color: #94a3b8; margin-bottom: 10px;">
                    <i class="fa fa-spinner fa-spin"></i>
                </div>
                <div style="color: #64748b; font-size: 14px;">Loading available times...</div>
            </div>
        `;
        container.style.display = 'block';
        return;
    }

    if (!data || !data.available_slots || data.available_slots.length === 0) {
        container.innerHTML = `
            <div style="text-align: center; padding: 20px; color: #94a3b8;">
                <div style="font-size: 40px; margin-bottom: 10px;">📅</div>
                <div style="font-weight: 600;">No Available Slots</div>
                <div style="font-size: 13px; margin-top: 8px;">Try selecting a different date</div>
            </div>
        `;
        container.style.display = 'block';
        return;
    }

    // Build the UI with available doctors and their slots
    let html = '';
    let slotCount = 0;

    data.available_slots.forEach((doctor, index) => {
        if (!doctor.slots || doctor.slots.length === 0) return;

        slotCount += doctor.slots.length;

        // Doctor header
        html += `
            <div style="margin-bottom: 16px;">
                <div style="font-size: 12px; font-weight: 700; text-transform: uppercase; 
                            letter-spacing: 0.5px; color: #0056b3; margin-bottom: 8px;">
                    👨‍⚕️ ${doctor.doctor_name}
                    <span style="color: #94a3b8; font-weight: 400; margin-left: 8px;">
                        (${doctor.current_bookings}/${doctor.booked_count + doctor.remaining_capacity} booked)
                    </span>
                </div>
                
                <!-- Time slots grid -->
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(80px, 1fr)); gap: 8px;">
        `;

        // Each time slot
        doctor.slots.forEach(slot => {
            const slotId = `slot-${doctor.doctor_id}-${slot.time.replace(':', '')}`;
            html += `
                <button type="button" 
                        class="slot-button" 
                        id="${slotId}"
                        data-doctor-id="${doctor.doctor_id}"
                        data-doctor-name="${doctor.doctor_name}"
                        data-time="${slot.time}"
                        data-end-time="${slot.end_time}"
                        onclick="selectTimeSlot(this)"
                        style="padding: 8px 12px; border: 2px solid #e2e8f0; 
                               background: white; border-radius: 8px; cursor: pointer;
                               font-size: 14px; font-weight: 500; color: #333;
                               transition: all 0.2s;">
                    ${slot.time}
                </button>
            `;
        });

        html += `
                </div>
            </div>
        `;
    });

    if (slotCount === 0) {
        html = `
            <div style="text-align: center; padding: 20px; color: #94a3b8;">
                <div style="font-size: 40px; margin-bottom: 10px;">📅</div>
                <div style="font-weight: 600;">No Available Slots</div>
                <div style="font-size: 13px; margin-top: 8px;">Try selecting a different date</div>
            </div>
        `;
    }

    container.innerHTML = html;
    container.style.display = 'block';

    // Add hover effects
    const buttons = container.querySelectorAll('.slot-button');
    buttons.forEach(btn => {
        btn.addEventListener('mouseover', function() {
            this.style.borderColor = '#0056b3';
            this.style.background = '#e7f3ff';
            this.style.color = '#0056b3';
        });
        btn.addEventListener('mouseout', function() {
            if (!this.classList.contains('selected')) {
                this.style.borderColor = '#e2e8f0';
                this.style.background = 'white';
                this.style.color = '#333';
            }
        });
    });

    smartSlotState.isLoading = false;
}

/**
 * Handle time slot selection
 */
function selectTimeSlot(button) {
    const doctorId = button.getAttribute('data-doctor-id');
    const doctorName = button.getAttribute('data-doctor-name');
    const time = button.getAttribute('data-time');
    const endTime = button.getAttribute('data-end-time');

    // Clear previous selection
    document.querySelectorAll('.slot-button').forEach(btn => {
        btn.classList.remove('selected');
        btn.style.borderColor = '#e2e8f0';
        btn.style.background = 'white';
        btn.style.color = '#333';
    });

    // Mark this slot as selected
    button.classList.add('selected');
    button.style.borderColor = '#0056b3';
    button.style.background = '#0056b3';
    button.style.color = 'white';

    // Update form fields
    document.getElementById('appointmentTime').value = time;
    document.getElementById('doctorSelect').value = doctorId;

    // Store in state
    smartSlotState.selectedTime = time;
    smartSlotState.doctorId = doctorId;

    // Show confirmation
    showSlotConfirmation(doctorName, time, endTime);
}

/**
 * Show confirmation when slot is selected
 */
function showSlotConfirmation(doctorName, time, endTime) {
    const confirmation = document.getElementById('slotConfirmation');
    if (!confirmation) return;

    confirmation.innerHTML = `
        <div style="padding: 12px; background: #dcfce7; border: 1.5px solid #86efac; 
                    border-radius: 8px; color: #166534;">
            <i class="fa fa-check-circle me-2"></i>
            <strong>Selected:</strong> ${time} - ${endTime} with ${doctorName}
        </div>
    `;
    confirmation.style.display = 'block';
}

/**
 * Show error messages
 */
function showErrorMessage(message) {
    const container = document.getElementById('availableSlotsContainer');
    if (!container) return;

    container.innerHTML = `
        <div style="padding: 12px; background: #fee2e2; border: 1.5px solid #fca5a5; 
                    border-radius: 8px; color: #991b1b;">
            <i class="fa fa-exclamation-circle me-2"></i>
            ${message}
        </div>
    `;
    container.style.display = 'block';
}

/**
 * Show no slots message
 */
function showNoSlotsMessage(date, additionalMessage = '') {
    const container = document.getElementById('availableSlotsContainer');
    if (!container) return;

    let msg = `No available slots for ${date}.`;
    if (additionalMessage) {
        msg += ` ${additionalMessage}`;
    }
    msg += ' Please try a different date.';

    container.innerHTML = `
        <div style="text-align: center; padding: 20px; color: #94a3b8;">
            <div style="font-size: 48px; margin-bottom: 12px;">📅</div>
            <div style="font-weight: 600; color: #333; margin-bottom: 6px;">No Available Slots</div>
            <div style="font-size: 13px;">${msg}</div>
        </div>
    `;
    container.style.display = 'block';
}

/**
 * Initialize smart slot picker - call when page loads
 */
function initSmartSlotPicker() {
    const dateInput = document.getElementById('appointmentDate');
    const serviceSelect = document.getElementById('serviceTypeSelect');
    const doctorSelect = document.getElementById('doctorSelect');

    if (dateInput) {
        dateInput.addEventListener('change', function() {
            smartSlotState.selectedDate = this.value;
            loadAvailableSlots();
        });
    }

    if (serviceSelect) {
        serviceSelect.addEventListener('change', function() {
            smartSlotState.serviceType = this.value;
            loadAvailableSlots();
        });
    }

    if (doctorSelect) {
        doctorSelect.addEventListener('change', function() {
            smartSlotState.doctorId = this.value || null;
            loadAvailableSlots();
        });
    }
}

/**
 * Load available slots (triggered by any field change)
 */
function loadAvailableSlots() {
    const date = smartSlotState.selectedDate;
    const service = smartSlotState.serviceType;

    const container = document.getElementById('availableSlotsContainer');
    if (!container) return;

    if (!date || !service) {
        container.innerHTML = `
            <div style="text-align: center; padding: 20px; color: #334155;">
                <div style="font-size: 14px; font-weight: 600;">Select service type and date to view available appointment times.</div>
                <div style="font-size: 12px; color: #64748b; margin-top: 6px;">Available slots are shown here when both fields are selected.</div>
            </div>
        `;
        container.style.display = 'block';
        return;
    }

    fetchAvailableSlots(date, service, smartSlotState.doctorId);
}

// Auto-initialize when page loads
document.addEventListener('DOMContentLoaded', initSmartSlotPicker);
