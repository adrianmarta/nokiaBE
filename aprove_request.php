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

// Fetch request details
$sql = "SELECT * FROM cereri WHERE id_cerere = ?";
$stmt = sqlsrv_query($conn, $sql, [$id]);
if (!$stmt || !sqlsrv_has_rows($stmt)) {
    http_response_code(404);
    echo json_encode(["error" => "Request not found"]);
    exit;
}
$data = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

// Hash password
$hashed = password_hash($data['parola'], PASSWORD_DEFAULT);

// Insert user
$insert = "INSERT INTO utilizator (nume, mail, parola, id_project, id_rol)
           VALUES (?, ?, ?, ?, 1)";
$result = sqlsrv_query($conn, $insert, [
    $data['nume'],
    $data['mail'],
    $hashed,
    $data['id_project']
]);

if ($result) {
    sqlsrv_query($conn, "UPDATE cereri SET id_status = 2 WHERE id_cerere = ?", [$id]);
    echo json_encode(["message" => "Request approved and user created"]);
} else {
    http_response_code(500);
    $errors = sqlsrv_errors();
    echo json_encode([
        "error" => "Failed to create user",
        "details" => $errors
    ]);
}
