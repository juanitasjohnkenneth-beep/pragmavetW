<?php
/**
 * Pet Data Helper
 * Centralized functions for pet profile data synchronization
 * and multi-pet appointment support.
 * Include this file in any page that displays pet/appointment data.
 */

/**
 * Get the profile picture URL for a pet, with fallback to default avatar
 */
function getPetPicture($pet_picture) {
    if (!empty($pet_picture) && file_exists($_SERVER['DOCUMENT_ROOT'] . '/pragmavet/uploads/pets/' . $pet_picture)) {
        return 'uploads/pets/' . $pet_picture;
    }
    return ''; // Returns empty string to indicate fallback to default
}

/**
 * Get pet picture HTML with proper fallback
 */
function petAvatarHtml($pet_picture, $pet_name = '', $size = 42) {
    $pic = getPetPicture($pet_picture);
    if ($pic) {
        return '<img src="' . htmlspecialchars($pic) . '" alt="' . htmlspecialchars($pet_name) . '" 
                     style="width:' . $size . 'px;height:' . $size . 'px;border-radius:50%;object-fit:cover;flex-shrink:0;border:2px solid #e2e8f0;">';
    }
    // Default avatar fallback
    $initial = !empty($pet_name) ? strtoupper(substr($pet_name, 0, 1)) : '';
    return '<div style="width:' . $size . 'px;height:' . $size . 'px;border-radius:50%;
                background:linear-gradient(135deg,#e0f2fe,#bae6fd);
                display:flex;align-items:center;justify-content:center;
                font-size:' . round($size * 0.43) . 'px;color:#4A7FC4;flex-shrink:0;
                border:2px solid #bfdbfe;font-weight:700;">
                ' . ($initial ?: '<i class="fa fa-paw"></i>') . '
            </div>';
}

/**
 * Format pet names list for display (handles multi-pet)
 * @param array|string $petData Array of pets or comma-separated names
 */
function formatPetNames($petData) {
    if (is_array($petData)) {
        $names = array_column($petData, 'pet_name');
        return implode(', ', $names);
    }
    return $petData;
}

/**
 * Sync pet profile data for an appointment
 * Updates pet_name and pet_picture from the pets table
 */
function syncAppointmentPetData($conn, $appointment_id) {
    // Get pets linked to this appointment
    $result = $conn->query("
        SELECT ap.id, ap.pet_id, ap.pet_name, ap.pet_picture, p.name as current_name, p.pet_picture as current_picture
        FROM appointment_pets ap
        LEFT JOIN pets p ON ap.pet_id = p.id
        WHERE ap.appointment_id = $appointment_id
    ");
    
    while ($row = $result->fetch_assoc()) {
        $needs_update = false;
        $new_name = $row['pet_name'];
        $new_picture = $row['pet_picture'];
        
        if ($row['pet_id'] && $row['current_name'] && $row['current_name'] !== $row['pet_name']) {
            $new_name = $row['current_name'];
            $needs_update = true;
        }
        if ($row['pet_id'] && $row['current_picture'] !== $row['pet_picture']) {
            $new_picture = $row['current_picture'];
            $needs_update = true;
        }
        
        if ($needs_update) {
            $stmt = $conn->prepare("UPDATE appointment_pets SET pet_name = ?, pet_picture = ? WHERE id = ?");
            $stmt->bind_param("ssi", $new_name, $new_picture, $row['id']);
            $stmt->execute();
        }
    }
}

/**
 * Get all pets for an appointment with full profile sync
 */
function getAppointmentPets($conn, $appointment_id) {
    $result = $conn->query("
        SELECT ap.*, p.pet_picture as current_picture, p.name as current_name
        FROM appointment_pets ap
        LEFT JOIN pets p ON ap.pet_id = p.id
        WHERE ap.appointment_id = $appointment_id
        ORDER BY ap.id ASC
    ");
    $pets = [];
    while ($row = $result->fetch_assoc()) {
        // Use profile picture if available, fallback to stored
        $row['display_picture'] = (!empty($row['current_picture'])) ? $row['current_picture'] : $row['pet_picture'];
        // Use current name if pet is still linked
        $row['display_name'] = (!empty($row['current_name'])) ? $row['current_name'] : $row['pet_name'];
        $pets[] = $row;
    }
    return $pets;
}

/**
 * Get the display name for an appointment (for single-pet backward compat)
 */
function getAppointmentPetDisplayName($conn, $appointment_id) {
    $pets = getAppointmentPets($conn, $appointment_id);
    if (empty($pets)) return 'Unknown Pet';
    $names = array_column($pets, 'display_name');
    return implode(', ', $names);
}

/**
 * Get first pet picture for an appointment (for backward compat with pet_picture column)
 */
function getAppointmentPetPicture($conn, $appointment_id) {
    $pets = getAppointmentPets($conn, $appointment_id);
    if (!empty($pets) && !empty($pets[0]['display_picture'])) {
        return $pets[0]['display_picture'];
    }
    return null;
}

/**
 * Insert pet records for a new appointment (supports multi-pet)
 */
function insertAppointmentPets($conn, $appointment_id, $user_id, $pet_ids, $pet_names) {
    $first_picture = null;
    $pet_count = count($pet_ids);
    
    for ($i = 0; $i < $pet_count; $i++) {
        $pet_id = isset($pet_ids[$i]) && $pet_ids[$i] ? (int)$pet_ids[$i] : null;
        $pet_name = $conn->real_escape_string($pet_names[$i]);
        
        // Get pet picture from pets table
        $picture = null;
        if ($pet_id) {
            $p = $conn->query("SELECT pet_picture FROM pets WHERE id = $pet_id AND user_id = $user_id");
            if ($p && $row = $p->fetch_assoc()) {
                $picture = $row['pet_picture'];
            }
        }
        
        if ($i === 0) $first_picture = $picture;
        
        $stmt = $conn->prepare("INSERT INTO appointment_pets (appointment_id, pet_id, pet_name, pet_picture) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("iiss", $appointment_id, $pet_id, $pet_name, $picture);
        $stmt->execute();
    }
    
    return $first_picture;
}

/**
 * Get pets HTML for display in appointment rows (handles multi-pet with avatars)
 */
function getPetsDisplayHtml($conn, $appointment_id, $size = 36) {
    $pets = getAppointmentPets($conn, $appointment_id);
    if (empty($pets)) {
        // Fallback: try to get from legacy pet_name column
        return '<span style="color:#94a3b8;">Unknown Pet</span>';
    }
    
    $html = '<div style="display:flex;flex-direction:column;gap:4px;">';
    foreach ($pets as $pet) {
        $html .= '<div style="display:flex;align-items:center;gap:8px;">';
        $html .= petAvatarHtml($pet['display_picture'], $pet['display_name'], $size);
        $html .= '<span style="font-weight:600;font-size:13px;color:#0f172a;">' . htmlspecialchars($pet['display_name']) . '</span>';
        $html .= '</div>';
    }
    $html .= '</div>';
    return $html;
}

/**
 * Get summary for an appointment - returns pet names string
 */
function getAppointmentPetSummary($conn, $appointment_id) {
    $pets = getAppointmentPets($conn, $appointment_id);
    if (empty($pets)) return 'Unknown Pet';
    $names = array_column($pets, 'display_name');
    $count = count($names);
    $summary = implode(', ', $names);
    if ($count > 1) {
        $summary .= ' <span style="font-size:11px;color:#94a3b8;font-weight:400;">(' . $count . ' pets)</span>';
    }
    return $summary;
}

/**
 * Get multi-pet avatar stack for compact display
 */
function getMultiPetAvatarStack($conn, $appointment_id, $size = 32) {
    $pets = getAppointmentPets($conn, $appointment_id);
    if (empty($pets)) return '';
    
    $html = '<div style="display:inline-flex;position:relative;align-items:center;">';
    $count = count($pets);
    $display_count = min(3, $count);
    
    for ($i = 0; $i < $display_count; $i++) {
        $pet = $pets[$i];
        $margin = ($i > 0) ? '-8px' : '0';
        $z_index = 10 - $i;
        $html .= '<div style="margin-left:' . $margin . ';position:relative;z-index:' . $z_index . ';">';
        $html .= petAvatarHtml($pet['display_picture'], $pet['display_name'], $size);
        $html .= '</div>';
    }
    
    if ($count > 3) {
        $more = $count - 3;
        $html .= '<div style="margin-left:-8px;position:relative;z-index:7;background:#f1f5f9;
                     color:#475569;font-size:11px;font-weight:bold;border-radius:50%;
                     width:' . $size . 'px;height:' . $size . 'px;display:flex;align-items:center;
                     justify-content:center;border:2px solid #fff;">';
        $html .= '+' . $more;
        $html .= '</div>';
    }
    
    $html .= '</div>';
    return $html;
}

/**
 * Sync pet profile across all linked appointments
 */
function syncPetProfileAcrossAppointments($conn, $pet_id) {
    $pet_id = (int)$pet_id;
    $pet_res = $conn->query("SELECT name, pet_picture FROM pets WHERE id = $pet_id");
    if (!$pet_res || $pet_res->num_rows === 0) {
        return 0;
    }
    $pet = $pet_res->fetch_assoc();
    $name = $pet['name'];
    $picture = $pet['pet_picture'];
    
    // Update appointment_pets
    $stmt = $conn->prepare("UPDATE appointment_pets SET pet_name = ?, pet_picture = ? WHERE pet_id = ?");
    $stmt->bind_param("ssi", $name, $picture, $pet_id);
    $stmt->execute();
    $updated = $stmt->affected_rows;
    
    // Also update appointments.pet_name for single pet appointments
    $appt_res = $conn->query("
        SELECT a.id 
        FROM appointments a 
        JOIN appointment_pets ap ON a.id = ap.appointment_id 
        WHERE a.is_multi_pet = 0 AND ap.pet_id = $pet_id
    ");
    
    while ($appt = $appt_res->fetch_assoc()) {
        $appt_id = $appt['id'];
        
        $ap_res = $conn->query("SELECT pet_name FROM appointment_pets WHERE appointment_id = $appt_id ORDER BY id ASC");
        $names = [];
        while ($ap = $ap_res->fetch_assoc()) {
            $names[] = $ap['pet_name'];
        }
        $names_str = implode(', ', $names);
        
        $update_appt = $conn->prepare("UPDATE appointments SET pet_name = ? WHERE id = ?");
        $update_appt->bind_param("si", $names_str, $appt_id);
        $update_appt->execute();
    }
    
    return $updated;
}
?>
