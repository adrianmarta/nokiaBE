<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// === Fix pentru OPTIONS request ===
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

// === Citim datele trimise prin POST (JSON) ===
$data = json_decode(file_get_contents("php://input"), true);

$id = $data['id'] ?? null;
$status = $data['status'] ?? null;

if (!$id ) {
    http_response_code(400);
    echo json_encode(["error" => "ID-ul sau statusul lipsesc."]);
    exit();
}

// === Update ticket ===
date_default_timezone_set('Europe/Bucharest');
$now = date('Y-m-d H:i:s');

$sql = "UPDATE tickets SET status = ?, last_modified_date = ? WHERE id = ?";
$stmt = sqlsrv_query($conn, $sql, [$status, $now, $id]);

if ($stmt) {
    echo json_encode(["success" => true]);
} else {
    http_response_code(500);
    echo json_encode(["error" => "Eroare la actualizarea statusului."]);
}

sqlsrv_close($conn);
?>
