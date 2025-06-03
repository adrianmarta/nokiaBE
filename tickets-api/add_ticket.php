<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

include '/../db.php'; // conexiunea la baza de date

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
    $statusId = null;
    $statusLookup = sqlsrv_query($conn, "SELECT id_status FROM status_ticket WHERE nume = ?", [$status]);
    if ($row = sqlsrv_fetch_array($statusLookup, SQLSRV_FETCH_ASSOC)) {
        $statusId = $row['id_status'];
    }
    $id_project = null;
    $projectLookup = sqlsrv_query($conn, "SELECT id_project FROM Project WHERE provider = ?", [$project]);
    if ($row = sqlsrv_fetch_array($projectLookup, SQLSRV_FETCH_ASSOC)) {
    $id_project = $row['id_project'];
    } else {
    echo json_encode(["success" => false, "error" => "Provider invalid"]);
    exit;
      }
    if ($statusId) {
        // === Inserare în audit_stare ===
        require_once '/auth.php'; // fișierul care conține funcția `authenticate()`
        $user = authenticate();   // fără roluri restricționate
        $id_user = $user['id_user'];
        $id_actiune = 3; // Adăugare Ticket

        $auditSQL = "INSERT INTO audit_stare (id_user, id_actiune, id_stare_curenta, id_project, timp, id_ticket)
                     VALUES (?, ?, ?, ?, ?, ?)";
        $auditParams = [$id_user, $id_actiune, $statusId, $project, $now, $newTicketId];
        sqlsrv_query($conn, $auditSQL, $auditParams);
    }
    echo json_encode(["success" => true, "ticket" => [
        "id" => $newTicketId,
        "incident_title" => $incident_title,
        "project" => $project,
        "priority_id" => $priority_id,
        "assigned_person" => $assigned_person,
        "status" => $status
    ]]);
} else {
$errors = sqlsrv_errors();
echo json_encode([
    "success" => false,
    "error" => "Eroare la inserare",
    "details" => $errors
]);
}
?>
