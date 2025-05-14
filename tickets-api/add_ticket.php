<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

include 'db.php'; // conexiunea la baza de date

$input = json_decode(file_get_contents("php://input"), true);

if (!$input) {
    echo json_encode(["success" => false, "error" => "Date invalide."]);
    exit;
}

$incident_title = $input['incident_title'] ?? '';
$project = $input['project'] ?? '';
$priority_id = $input['priority_id'] ?? '';
$assigned_person = $input['assigned_person'] ?? '';
$status = $input['status'] ?? 'Open'; // dacă nu e dat, implicit Open

date_default_timezone_set('Europe/Bucharest');
$now = date('Y-m-d H:i:s');

if (!$incident_title || !$project || !$priority_id) {
    echo json_encode(["success" => false, "error" => "Câmpuri obligatorii lipsă."]);
    exit;
}

// Inserăm ticketul
$sql = "INSERT INTO Tickets (incident_title, project, priority_id, assigned_person, status, start_date, last_modified_date)
        VALUES (?, ?, ?, ?, ?, ?, ?)";
$params = [$incident_title, $project, $priority_id, $assigned_person, $status, $now, $now];

$stmt = sqlsrv_query($conn, $sql, $params);

if ($stmt) {
    // Luăm ID-ul noului ticket
    $newIdQuery = sqlsrv_query($conn, "SELECT SCOPE_IDENTITY() AS id");
    $newIdRow = sqlsrv_fetch_array($newIdQuery, SQLSRV_FETCH_ASSOC);
    $newTicketId = $newIdRow['id'];

    echo json_encode(["success" => true, "ticket" => [
        "id" => $newTicketId,
        "incident_title" => $incident_title,
        "project" => $project,
        "priority_id" => $priority_id,
        "assigned_person" => $assigned_person,
        "status" => $status
    ]]);
} else {
    echo json_encode(["success" => false, "error" => "Eroare la inserare"]);
}
?>
