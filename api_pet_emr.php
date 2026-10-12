<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include("dbconnection.php");
include_once("includes/rbac_guard.php");
header('Content-Type: application/json');

// RBAC: Requires authenticated session; ownership validated per-action below
if (!rbac_logged_in()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error'   => 'Unauthorized: Please log in.'
    ]);
    exit();
}

$user_id = (int)$_SESSION['user_id'];

// Parse request data from JSON body, POST parameters, or GET query string
$raw_input = file_get_contents('php://input');
$json_data = json_decode($raw_input, true);
$data = is_array($json_data) ? array_merge($_POST, $json_data) : $_POST;

if (empty($data) && !empty($_GET)) {
    $data = $_GET;
}

$action = isset($data['action']) ? trim((string)$data['action']) : '';

if (empty($action)) {
    echo json_encode([
        'success' => false,
        'error' => 'Action parameter is required.'
    ]);
    exit();
}

// Validate pet_id for all actions
$pet_id = isset($data['pet_id']) ? (int)$data['pet_id'] : 0;
if ($pet_id <= 0) {
    echo json_encode([
        'success' => false,
        'error' => 'A valid pet_id is required.'
    ]);
    exit();
}

// Verify that the pet belongs to the logged-in user
$stmt_check = $conn->prepare("SELECT id FROM pets WHERE id = ? AND user_id = ?");
if (!$stmt_check) {
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $conn->error
    ]);
    exit();
}
$stmt_check->bind_param("ii", $pet_id, $user_id);
$stmt_check->execute();
$pet_res = $stmt_check->get_result();

if (!$pet_res || $pet_res->num_rows === 0) {
    $stmt_check->close();
    echo json_encode([
        'success' => false,
        'error' => 'Pet not found or access denied.'
    ]);
    exit();
}
$stmt_check->close();

// Handle API actions
switch ($action) {
    case 'update_pet_notes':
        $allergies = isset($data['allergies']) ? (string)$data['allergies'] : '';
        $special_instructions = isset($data['special_instructions']) ? (string)$data['special_instructions'] : '';

        $stmt = $conn->prepare("INSERT INTO pet_notes (pet_id, allergies, special_instructions) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE allergies = VALUES(allergies), special_instructions = VALUES(special_instructions)");
        if (!$stmt) {
            echo json_encode([
                'success' => false,
                'error' => 'Database prepare failed: ' . $conn->error
            ]);
            exit();
        }

        $stmt->bind_param("iss", $pet_id, $allergies, $special_instructions);
        if ($stmt->execute()) {
            $stmt->close();
            echo json_encode([
                'success' => true,
                'data' => [
                    'pet_id' => $pet_id,
                    'allergies' => $allergies,
                    'special_instructions' => $special_instructions,
                    'message' => 'Pet notes updated successfully.'
                ]
            ]);
        } else {
            $err = $stmt->error;
            $stmt->close();
            echo json_encode([
                'success' => false,
                'error' => 'Failed to update pet notes: ' . $err
            ]);
        }
        break;

    case 'update_pet_profile':
        $gender = isset($data['gender']) ? trim((string)$data['gender']) : null;
        $date_of_birth = !empty($data['date_of_birth']) ? trim((string)$data['date_of_birth']) : null;

        $stmt = $conn->prepare("UPDATE pets SET gender = ?, date_of_birth = ? WHERE id = ? AND user_id = ?");
        if (!$stmt) {
            echo json_encode([
                'success' => false,
                'error' => 'Database prepare failed: ' . $conn->error
            ]);
            exit();
        }

        $stmt->bind_param("ssii", $gender, $date_of_birth, $pet_id, $user_id);
        if ($stmt->execute()) {
            $stmt->close();
            echo json_encode([
                'success' => true,
                'data' => [
                    'pet_id' => $pet_id,
                    'gender' => $gender,
                    'date_of_birth' => $date_of_birth,
                    'message' => 'Pet profile updated successfully.'
                ]
            ]);
        } else {
            $err = $stmt->error;
            $stmt->close();
            echo json_encode([
                'success' => false,
                'error' => 'Failed to update pet profile: ' . $err
            ]);
        }
        break;

    case 'get_medical_records':
        $filter = isset($data['filter']) ? trim((string)$data['filter']) : 'all';
        if (empty($filter)) {
            $filter = 'all';
        }

        if ($filter !== 'all') {
            $stmt = $conn->prepare("SELECT * FROM medical_records WHERE pet_id = ? AND record_type = ? ORDER BY record_date DESC, record_time DESC, id DESC");
            if (!$stmt) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Database prepare failed: ' . $conn->error
                ]);
                exit();
            }
            $stmt->bind_param("is", $pet_id, $filter);
        } else {
            $stmt = $conn->prepare("SELECT * FROM medical_records WHERE pet_id = ? ORDER BY record_date DESC, record_time DESC, id DESC");
            if (!$stmt) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Database prepare failed: ' . $conn->error
                ]);
                exit();
            }
            $stmt->bind_param("i", $pet_id);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $records = [];
        while ($row = $result->fetch_assoc()) {
            $records[] = $row;
        }
        $stmt->close();

        echo json_encode([
            'success' => true,
            'data' => $records
        ]);
        break;

    case 'get_vaccination_records':
        $stmt = $conn->prepare("SELECT * FROM vaccination_records WHERE pet_id = ? ORDER BY date_given DESC, id DESC");
        if (!$stmt) {
            echo json_encode([
                'success' => false,
                'error' => 'Database prepare failed: ' . $conn->error
            ]);
            exit();
        }

        $stmt->bind_param("i", $pet_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $records = [];
        while ($row = $result->fetch_assoc()) {
            $records[] = $row;
        }
        $stmt->close();

        echo json_encode([
            'success' => true,
            'data' => $records
        ]);
        break;

    case 'get_pet_notes':
        $stmt = $conn->prepare("SELECT allergies, special_instructions FROM pet_notes WHERE pet_id = ? LIMIT 1");
        if (!$stmt) {
            echo json_encode([
                'success' => false,
                'error' => 'Database prepare failed: ' . $conn->error
            ]);
            exit();
        }

        $stmt->bind_param("i", $pet_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $notes = $result->fetch_assoc();
        $stmt->close();

        if (!$notes) {
            $notes = [
                'allergies' => '',
                'special_instructions' => ''
            ];
        } else {
            $notes = [
                'allergies' => $notes['allergies'] !== null ? $notes['allergies'] : '',
                'special_instructions' => $notes['special_instructions'] !== null ? $notes['special_instructions'] : ''
            ];
        }

        echo json_encode([
            'success' => true,
            'data' => $notes
        ]);
        break;

    default:
        echo json_encode([
            'success' => false,
            'error' => 'Invalid action: ' . $action
        ]);
        break;
}
