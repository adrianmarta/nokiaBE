<?php
require_once 'db.php';
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json");

// Preflight (CORS check)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';

if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    http_response_code(400);
    echo json_encode(["error" => "Token missing"]);
    exit;
}

$token = $matches[1];
sqlsrv_query($conn, "DELETE FROM Tokens WHERE token = ?", [$token]);
echo json_encode(["message" => "Logged out"]);
