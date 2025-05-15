<?php
require_once 'db.php';
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");

// Handle preflight (CORS pre-check)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204); // No content
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
$input = json_decode(file_get_contents("php://input"), true);
$mail = $input['mail'] ?? '';
$parola = $input['parola'] ?? '';

// Fetch user
$sql = "SELECT * FROM Utilizator WHERE mail = ?";
$stmt = sqlsrv_query($conn, $sql, [$mail]);

if ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    if (password_verify($parola, $row['parola'])) {
        // Delete old token
        sqlsrv_query($conn, "DELETE FROM Tokens WHERE id_user = ?", [$row['id_user']]);

        // Generate new token
        // Generate new token
$token = bin2hex(random_bytes(32));
$insertToken = "INSERT INTO Tokens (token, id_user) VALUES (?, ?)";
sqlsrv_query($conn, $insertToken, [$token, $row['id_user']]);

// ✅ Log login
sqlsrv_query($conn, "INSERT INTO audit (id_user, actiune) VALUES (?, ?)", [$row['id_user'], 'conectare']);


        echo json_encode([
            "token" => $token
        ]);
        exit;
    }
}

http_response_code(401);
echo json_encode(["error" => "Invalid credentials"]);
