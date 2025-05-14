<?php
require_once 'db.php';

header("Content-Type: application/json");

// Parse JSON input
$input = json_decode(file_get_contents("php://input"), true);

// Validate required fields
if (
    !isset($input["nume"], $input["mail"], $input["parola"], $input["id_project"], $input["id_rol"])
) {
    http_response_code(400);
    echo json_encode(["error" => "Missing required fields"]);
    exit;
}

// Sanitize & assign
$nume = $input["nume"];
$mail = $input["mail"];
$parolaHashed = password_hash($input["parola"], PASSWORD_BCRYPT);
$id_project = (int)$input["id_project"];
$id_rol = (int)$input["id_rol"];

// Optional: Check if project exists
$checkProject = sqlsrv_query($conn, "SELECT 1 FROM Project WHERE id_project = ?", [$id_project]);
if (!sqlsrv_fetch($checkProject)) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid project ID"]);
    exit;
}

// Optional: Check for existing email
$checkUser = sqlsrv_query($conn, "SELECT 1 FROM Utilizator WHERE mail = ?", [$mail]);
if (sqlsrv_fetch($checkUser)) {
    http_response_code(409);
    echo json_encode(["error" => "User already exists with this email"]);
    exit;
}

// Insert into DB
$sql = "INSERT INTO Utilizator (nume, mail, parola, id_project, id_rol) VALUES (?, ?, ?, ?, ?)";
$params = [$nume, $mail, $parolaHashed, $id_project, $id_rol];

$stmt = sqlsrv_query($conn, $sql, $params);

if (!$stmt) {
    http_response_code(500);
    echo json_encode(["error" => "Query failed", "details" => sqlsrv_errors()]);
} else {
    http_response_code(201);
    echo json_encode(["message" => "User created successfully"]);
}
?>
