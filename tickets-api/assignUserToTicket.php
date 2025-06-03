<?php
include 'db.php';
require_once __DIR__ . '/../auth.php';

header('Content-Type: application/json');

$user = authenticate();
$currentUserId = $user['id_user'];
$role = $user['id_rol'];

if ((int)$role === 1) { // 1 = user
    http_response_code(403);
    echo json_encode(["error" => "Nu ai permisiunea să asignezi tickete."]);
    exit;
}

// Citim input-ul
$input = json_decode(file_get_contents('php://input'), true);

$ticketId = $input['id_ticket'] ?? null;
$assignedUserId = $input['assigned_person'] ?? null;

if (!$ticketId || !$assignedUserId) {
    http_response_code(400);
    echo json_encode(["error" => "ticket_id și assigned_person sunt obligatorii."]);
    exit;
}

$now = new DateTime("now", new DateTimeZone('Europe/Bucharest'));
$assignedDate = $now->format('Y-m-d H:i:s');

$query = "UPDATE Tickets SET assigned_person = ?, assigned_date = ?, last_modified_date = ? WHERE id = ?";
$params = [$assignedUserId, $assignedDate, $assignedDate, $ticketId];

$stmt = sqlsrv_query($conn, $query, $params);

if ($stmt === false) {
    http_response_code(500);
    echo json_encode(["error" => "Eroare la asignare", "details" => sqlsrv_errors()]);
    exit;
}

echo json_encode(["message" => "Utilizatorul a fost asignat cu succes la ticket!"]);

sqlsrv_close($conn);
?>
