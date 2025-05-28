<?php
require_once 'db.php';

header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);
if (!isset($input["mail"], $input["parola"], $input["nume"], $input["id_project"])) {
    http_response_code(400);
    echo json_encode(["error" => "Missing required fields"]);
    exit;
}

$mail   = trim($input["mail"]);
$nume   = trim($input["nume"]);

$hash   = password_hash($input["parola"], PASSWORD_BCRYPT);
$status = 1; // 1 = pending

// map the admin‐checkbox to a requested role:
//    is_admin = true  → request admin → id_rol = 2
//    is_admin = false → normal user → id_rol = 1
$isAdmin = !empty($input['is_admin']) || !empty($input['isAdmin']);
$id_rol = $isAdmin ? 2 : 1;

// duplicate checks
$u = sqlsrv_query($conn, "SELECT 1 FROM Utilizator WHERE mail = ?", [$mail]);
$c = sqlsrv_query($conn, "SELECT 1 FROM Cereri    WHERE mail = ?", [$mail]);
if (sqlsrv_has_rows($u) || sqlsrv_has_rows($c)) {
    http_response_code(409);
    echo json_encode(["error" => "Email already in use or pending."]);
    exit;
}

// insert request
$sql = "INSERT INTO Cereri (mail, parola, nume, id_rol, data_cerere, id_status)
        VALUES (?, ?, ?, ?, ?, GETDATE(), ?)";
$params = [$mail, $hash, $nume, $id_rol, $status];
$stmt = sqlsrv_query($conn, $sql, $params);

if ($stmt) {
    echo json_encode([
      "message"   => "Registration request submitted",
      "requestedRole" => $id_rol === 2 ? "admin" : "user"
    ]);
} else {
    http_response_code(500);
    echo json_encode(["error" => "Failed to submit request", "details" => sqlsrv_errors()]);
}
