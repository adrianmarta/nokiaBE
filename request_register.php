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

$mail = trim($input["mail"]);
$hashedPassword = password_hash($input["parola"], PASSWORD_BCRYPT);
$nume = trim($input["nume"]);
$id_project = (int)$input["id_project"];
$id_rol = 1; // default role
$id_status = 1; // în așteptare
$data = date("Y-m-d H:i:s");

// Check duplicates
$checkUtilizator = sqlsrv_query($conn, "SELECT 1 FROM Utilizator WHERE mail = ?", [$mail]);
$checkCereri = sqlsrv_query($conn, "SELECT 1 FROM Cereri WHERE mail = ?", [$mail]);

if (sqlsrv_has_rows($checkUtilizator)) {
    http_response_code(409);
    echo json_encode(["error" => "Email is already registered."]);
    exit;
}
if (sqlsrv_has_rows($checkCereri)) {
    http_response_code(409);
    echo json_encode(["error" => "You already submitted a registration request."]);
    exit;
}

$sql = "INSERT INTO Cereri (mail, parola, nume, id_rol, id_project, data_cerere, id_status)
        VALUES (?, ?, ?, ?, ?, ?, ?)";

$params = [$mail, $hashedPassword, $nume, $id_rol, $id_project, $data, $id_status];

$stmt = sqlsrv_query($conn, $sql, $params);

if ($stmt) {
    echo json_encode(["message" => "Registration request submitted"]);
} else {
    http_response_code(500);
    echo json_encode(["error" => "Failed to submit request", "details" => sqlsrv_errors()]);
}
?>
