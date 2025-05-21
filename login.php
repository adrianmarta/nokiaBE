<?php
require_once 'db.php';
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");


if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
$input = json_decode(file_get_contents("php://input"), true);
$mail = $input['mail'] ?? '';
$parola = $input['parola'] ?? '';

/// Fetch user
$sql = "
    SELECT u.*, r.nume_rol 
    FROM Utilizator u
    JOIN Rol r ON u.id_rol = r.id_rol
    WHERE u.mail = ?
";
$stmt = sqlsrv_query($conn, $sql, [$mail]);

// Fetch user
if ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
   
    if (password_verify($parola, $row['parola'])) {
        // Delete old token
   

        sqlsrv_query($conn, "DELETE FROM Tokens WHERE id_user = ?", [$row['id_user']]);

        // Generate new token
        
        $token = bin2hex(random_bytes(32));
        $insertToken = "INSERT INTO Tokens (token, id_user) VALUES (?, ?)";
        sqlsrv_query($conn, $insertToken, [$token, $row['id_user']]);


        sqlsrv_query($conn, "INSERT INTO audit (id_user, actiune) VALUES (?, ?)", [$row['id_user'], 'conectare']);


        echo json_encode([
            "token" => $token,
            "role" => $row['nume_rol']
        ]);
        exit;
    }
}

http_response_code(401);
echo json_encode(["error" => "Invalid credentials"]);
