<?php
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Trimite răspuns OK la cererile preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'db.php';
require_once __DIR__ . '/../auth.php';

$user = authenticate(); // returneaza id_user, id_rol
$currentUserId = $user['id_user'];
$role = $user['id_rol'];

// Debug logging - remove this in production
error_log("Create ticket - User ID: $currentUserId, Role: $role");

// Fixed role check - only allow admins (role 2) and super admins (role 3) to create tickets
if ((int)$role === 1) { // 1 == regular user role
    http_response_code(403);
    echo json_encode([
        "error" => "Nu ai permisiunea să adaugi tickete.", 
        "debug" => ["user_id" => $currentUserId, "role" => $role]
    ]);
    exit;
}

header('Content-Type: application/json');
$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
if (empty($input['priority_id'])) {
    http_response_code(400);
    echo json_encode(["error" => "Priority ID este obligatoriu"]);
    exit;
}

// **NEW: Validate assigned person email if provided**
if (!empty($input['assigned_person'])) {
    $email = trim($input['assigned_person']);
    
    // Basic email format validation
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(["error" => "Email format invalid"]);
        exit;
    }
    
    // Check if email exists in database
    $emailCheckQuery = "SELECT COUNT(*) as count FROM Utilizator WHERE mail = ?";
    $emailCheckStmt = sqlsrv_query($conn, $emailCheckQuery, [$email]);
    
    if (!$emailCheckStmt) {
        http_response_code(500);
        echo json_encode([
            "error" => "Eroare la verificarea emailului",
            "details" => sqlsrv_errors()
        ]);
        exit;
    }
    
    $emailCheckRow = sqlsrv_fetch_array($emailCheckStmt, SQLSRV_FETCH_ASSOC);
    $emailCount = $emailCheckRow['count'];
    
    if ($emailCount == 0) {
        http_response_code(400);
        echo json_encode(["error" => "This email does not exist"]);
        exit;
    }
}

$projectQuery = "SELECT id_project FROM Project WHERE id_user = ?";
$projectStmt = sqlsrv_query($conn, $projectQuery, [$currentUserId]);

if (!$projectStmt || !sqlsrv_fetch($projectStmt)) {
    echo json_encode(["error" => "Nu s-a putut obține proiectul echipei"]);
    exit;
}

$id_project = sqlsrv_get_field($projectStmt, 0);

// Get current user's email
$emailQuery = "SELECT mail FROM Utilizator WHERE id_user = ?";
$emailStmt = sqlsrv_query($conn, $emailQuery, [$currentUserId]);

if (!$emailStmt || !sqlsrv_fetch($emailStmt)) {
    echo json_encode(["error" => "Nu s-a putut obtine emailul utilizatorului"]);
    exit;
}

$emailRow = sqlsrv_get_field($emailStmt, 0);
$created_by_email = $currentUserId;

$now = new DateTime("now", new DateTimeZone('Europe/Bucharest'));
$now = $now->format('Y-m-d H:i:s');

// Handle assigned_person - if provided as email, get the user ID
$assigned_person_id = null;
$assigned_date = null;
$team_assigned_person = null;
$response_time = null;

if (!empty($input['assigned_person'])) {
    $assignedPersonQuery = "SELECT id_user, id_team FROM Utilizator WHERE mail = ?";
    $assignedPersonStmt = sqlsrv_query($conn, $assignedPersonQuery, [$input['assigned_person']]);
    
    if ($assignedPersonStmt && sqlsrv_fetch($assignedPersonStmt)) {
        $assigned_person_id = sqlsrv_get_field($assignedPersonStmt, 0); // id_user
        $team_assigned_person = sqlsrv_get_field($assignedPersonStmt, 1); // id_team
        $assigned_date = $now;
        $response_time = 0;
    }
}


$status = $input['status'] ?? 'Open';

// Insert ticket without ticket_id + get auto-increment ID
$query = "
    INSERT INTO Tickets (
        incident_title, status, priority_id, 
        start_date, last_modified_date, comment, description,
        created_by, team_created_by, project, assigned_person, assigned_date, team_assigned_person, response_time
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?);
    SELECT SCOPE_IDENTITY() AS inserted_id;
";

$params = [
    $input['incident_title'] ?? null,
    $status,
    $input['priority_id'],
    $now,
    $now,
    $input['comment'] ?? null,
    $input['description'] ?? null,
    $created_by_email,
    null,
    $id_project,
    $assigned_person_id,
    $assigned_date,
    $team_assigned_person ?? null,
    $response_time ?? null
];

// Execute query + get generated ID
$stmt = sqlsrv_query($conn, $query, $params);

if ($stmt === false) {
    echo json_encode([
        "error" => "Eroare la inserare ticket",
        "details" => sqlsrv_errors()
    ]);
    exit;
}

sqlsrv_next_result($stmt);

$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
$insertedId = $row['inserted_id'] ?? null;

if (!$insertedId) {
    echo json_encode(["error" => "Nu s-a putut obtine id-ul inserat"]);
    exit;
}

// Generate ticket_id in format T + 10 digits
$ticketId = 'T' . str_pad($insertedId, 10, '0', STR_PAD_LEFT);

// Update ticket_id
$updateQuery = "UPDATE Tickets SET ticket_id = ? WHERE id = ?";
$updateParams = [$ticketId, $insertedId];
$updateStmt = sqlsrv_query($conn, $updateQuery, $updateParams);

if ($updateStmt === false) {
    echo json_encode([
        "error" => "Eroare la update ticket_id",
        "details" => sqlsrv_errors()
    ]);
    exit;
}

echo json_encode([
    "message" => "Ticket adăugat cu succes!",
    "ticket_id" => $ticketId,
    "success" => true
]);

sqlsrv_close($conn);
?>