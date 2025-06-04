<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// === Fix pentru preflight OPTIONS ===
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

include '/../db.php';

$conn = sqlsrv_connect($serverName, $connectionOptions);

if (!$conn) {
    http_response_code(500);
    echo json_encode(["error" => "Conexiunea la baza de date a eșuat"]);
    exit();
}

// === Preluăm id-ul din query string ===
if (!isset($_GET['id'])) {
    http_response_code(400);
    echo json_encode(["error" => "ID-ul ticketului lipsește."]);
    exit();
}

$id = intval($_GET['id']);

// === Ștergem ticketul ===
$sql = "DELETE FROM tickets WHERE id = ?";
$stmt = sqlsrv_query($conn, $sql, [$id]);

if ($stmt) {
    echo json_encode(["success" => true]);
} else {
    http_response_code(500);
    echo json_encode(["error" => "Eroare la ștergerea ticketului."]);
}

sqlsrv_close($conn);
?>