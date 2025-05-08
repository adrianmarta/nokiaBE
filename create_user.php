<?php
require_once 'db.php'; // This should define $conn

header("Content-Type: application/json");

// Get JSON input
$input = json_decode(file_get_contents("php://input"), true);

// Basic validation
if (!isset($input["mail"], $input["parola"], $input["id_rol"])) {
    http_response_code(400);
    echo json_encode(["error" => "Missing required fields"]);
    exit;
}

// Sanitize + hash
$mail = $input["mail"];
$parolaHashed = password_hash($input["parola"], PASSWORD_BCRYPT);
$id_rol = (int)$input["id_rol"];

// Insert into DB
$sql = "INSERT INTO Utilizator (mail, parola, id_rol) VALUES (?, ?, ?)";
$params = [$mail, $parolaHashed, $id_rol];

$stmt = sqlsrv_query($conn, $sql, $params);

if (!$stmt) {
    http_response_code(500);
    echo json_encode(["error" => "Query failed", "details" => sqlsrv_errors()]);
} else {
    http_response_code(201);
    echo json_encode(["message" => "User created successfully"]);
}
?>
