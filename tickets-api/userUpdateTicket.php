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

header("Content-Type: application/json");

$input = json_decode(file_get_contents("php://input"), true);

if (!isset($input["ticket_id"])) {
    http_response_code(400);
    echo json_encode(["error" => "Missing required field: ticket_id"]);
    exit;
}

$ticketId = (int)$input["ticket_id"];
$status = isset($input["status"]) ? $input["status"] : null;
$comment = isset($input["comment"]) ? $input["comment"] : null;
$resolution = isset($input["resolution"]) ? $input["resolution"] : null;

// autentificare utilizator
$user = authenticate(); // care returneaza id_user, id_rol
$currentUserId = $user['id_user'];

//Verificam dacă el e assigned_person pe ticket
$sql = "SELECT assigned_person FROM Tickets WHERE id = ?";
$stmt = sqlsrv_query($conn, $sql, [$ticketId]);

if (!$stmt || !sqlsrv_has_rows($stmt)) {
    http_response_code(404);
    echo json_encode(["error" => "Ticket not found"]);
    exit;
}

$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

if ((int)$row['assigned_person'] !== (int)$currentUserId) {
    http_response_code(403);
    echo json_encode(["error" => "You are not assigned to this ticket"]);
    exit;
}

//daca e ticketul lui, face update
$now = new DateTime("now", new DateTimeZone('Europe/Bucharest'));
$lastModified = $now->format('Y-m-d H:i:s');

if ($status === "Closed") {
    $closedDate = $lastModified;
    $update = "UPDATE Tickets SET status = ?, comment = ?, resolution = ?, last_modified_date = ?, closed_date = ? WHERE id = ?";
    $params = [$status, $comment, $resolution, $lastModified, $closedDate, $ticketId];
} else {
    $update = "UPDATE Tickets SET status = ?, comment = ?, last_modified_date = ? WHERE id = ?";
    $params = [$status, $comment, $lastModified, $ticketId];
}

$updateStmt = sqlsrv_query($conn, $update, $params);

if (!$updateStmt) {
    http_response_code(500);
    echo json_encode(["error" => "Failed to update", "details" => sqlsrv_errors()]);
}
$statusLookupSql  = "SELECT id_status FROM status_ticket WHERE nume = ?";
$statusLookupStmt = sqlsrv_query($conn, $statusLookupSql, [$status]);

if (!$statusLookupStmt) {
    http_response_code(500);
    echo json_encode([
        "error_phase"   => "status_lookup_failed",
        "sqlsrv_errors" => sqlsrv_errors()
    ]);
    exit;
}
if (!sqlsrv_fetch($statusLookupStmt)) {
    http_response_code(400);
    echo json_encode(["error" => "Status '$status' nu există în tabelul status_ticket"]);
    exit;
}
$status_id = sqlsrv_get_field($statusLookupStmt, 0);
sqlsrv_free_stmt($statusLookupStmt);

if ($status_id === null) {
    http_response_code(500);
    echo json_encode(["error" => "ID-ul statusului a fost NULL"]);
    exit;
}


$projectLookupSql  = "SELECT project FROM Tickets WHERE id = ?";
$projectLookupStmt = sqlsrv_query($conn, $projectLookupSql, [$ticketId]);

if (!$projectLookupStmt || !sqlsrv_fetch($projectLookupStmt)) {
    http_response_code(500);
    echo json_encode(["error" => "Nu s-a putut obține proiectul pentru ticket"]);
    exit;
}
$project_id = sqlsrv_get_field($projectLookupStmt, 0);
sqlsrv_free_stmt($projectLookupStmt);


$auditSql = "
    INSERT INTO audit_stare (
        id_user,
        id_actiune,
        id_stare_curenta,
        id_project,
        timp,
        id_ticket
    )
    VALUES (?, ?, ?, ?, ?, ?)
";


$auditParams = [
    $currentUserId,  
    4,               
    $status_id,      
    $project_id,     
    $lastModified,   
    $ticketId      
];

$auditStmt = sqlsrv_query($conn, $auditSql, $auditParams);
if (!$auditStmt) {
    http_response_code(500);
    echo json_encode([
        "error_phase"   => "audit_insert_failed",
        "audit_sql"     => $auditSql,
        "audit_params"  => $auditParams,
        "sqlsrv_errors" => sqlsrv_errors()
    ]);
    exit;
}
http_response_code(200);
echo json_encode(["message" => "Ticket updated and audit added successfully"]);

sqlsrv_close($conn);
?>
