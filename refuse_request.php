<?php
require_once 'db.php';
require_once 'auth.php';

header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 🔐 Admin only
authenticate(3);

$input = json_decode(file_get_contents("php://input"), true);
$id = $input['id_cerere'] ?? null;

if (!$id) {
    http_response_code(400);
    echo json_encode(["error" => "Missing request ID"]);
    exit;
}

// Update to refused
$result = sqlsrv_query($conn, "UPDATE cereri SET id_status = 3 WHERE id_cerere = ?", [$id]);

if ($result) {
    echo json_encode(["message" => "Request refused"]);
} else {
    http_response_code(500);
    echo json_encode(["error" => "Failed to refuse request"]);
}
