<?php
require 'auth_middleware.php';

$user = verifyToken();
$role = $user['role'];
include '/../db.php';
if ($role !== 'superuser') {
    http_response_code(403);
    echo json_encode(["error" => "Nu ai permisiunea să adaugi tickete."]);
    exit;
}

header('Content-Type: application/json');
$input = json_decode(file_get_contents('php://input'), true);


$conn = sqlsrv_connect($serverName, $connectionOptions);

if (!$conn) {
    echo json_encode(["error" => "Eroare conectare"]);
    exit;
}

// Inserare ticket
$query = "INSERT INTO Tickets (incident_title, status, project, priority_id, start_date)
VALUES (?, ?, ?, ?, GETDATE())";

$params = [
    $input['incident_title'],
    $input['status'],
    $input['project'],
    $input['priority_id']
];

$result = sqlsrv_query($conn, $query, $params);

if ($result) {
    echo json_encode(["message" => "Ticket adăugat cu succes!"]);
} else {
    echo json_encode(["error" => "Eroare la adăugare ticket"]);
}

sqlsrv_close($conn);
?>
