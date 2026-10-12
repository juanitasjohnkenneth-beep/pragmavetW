<?php
/**
 * A pet's own appointments: the owner's bookings linked to this pet by ID (appointment_pets), or — for older
 * bookings without a pet link — by the pet's exact name. Never another owner's pet, never a partial name match.
 */
function pet_appointment_ids($conn, $owner_id, $pet_id, $pet_name) {
    $ids = [];
    if ((int)$pet_id <= 0) return $ids;
    $st = $conn->prepare("
        SELECT a.id FROM appointments a
        WHERE a.user_id = ?
          AND ( EXISTS (SELECT 1 FROM appointment_pets ap WHERE ap.appointment_id = a.id AND ap.pet_id = ?)
                OR ( NOT EXISTS (SELECT 1 FROM appointment_pets ap WHERE ap.appointment_id = a.id AND ap.pet_id IS NOT NULL)
                     AND ( FIND_IN_SET(LOWER(?), LOWER(REPLACE(a.pet_name, ', ', ','))) > 0
                           OR EXISTS (SELECT 1 FROM appointment_pets ap WHERE ap.appointment_id = a.id AND LOWER(ap.pet_name) = LOWER(?)) ) ) )
    ");
    $owner_id = (int)$owner_id; $pet_id = (int)$pet_id;
    $st->bind_param("iiss", $owner_id, $pet_id, $pet_name, $pet_name);
    $st->execute();
    $r = $st->get_result();
    while ($row = $r->fetch_assoc()) $ids[] = (int)$row['id'];
    return $ids;
}
