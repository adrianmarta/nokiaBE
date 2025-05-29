<?php
require_once 'db.php';
require_once 'auth.php';

header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    echo json_encode(["message" => "Preflight OK"]);
    exit;
}

try {
    $user = authenticate([2,3]);
    $id_user = $user['id_user'];
    $id_rol = $user['id_rol'];

    $input = json_decode(file_get_contents("php://input"), true);
    $id = $input['id_cerere'] ?? null;

    if (!$id) {
        throw new Exception("Missing request ID", 400);
    }

    $sql = "SELECT * FROM cereri WHERE id_cerere = ?";
    $stmt = sqlsrv_query($conn, $sql, [$id]);
    if (!$stmt || !sqlsrv_has_rows($stmt)) {
        throw new Exception("Request not found", 404);
    }
    $data = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
if ($data['id_rol'] == 2 && $id_rol !== 3) {
    throw new Exception("Only super-admins may approve or reject admin requests.", 403);
}
    
    

    $hashed = $data['parola']; 

    $result = sqlsrv_query($conn, "INSERT INTO utilizator (nume, mail, parola, id_rol) VALUES (?, ?, ?, 1)", [
        $data['nume'], $data['mail'], $hashed, 
    ]);

    if (!$result) {
        throw new Exception("Failed to create user", 500);
    }

    sqlsrv_query($conn, "UPDATE cereri SET id_status = 2 WHERE id_cerere = ?", [$id]);
    echo json_encode(["message" => "Request approved and user created"]);

} catch (Throwable $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "error" => $e->getMessage()
    ]);
}
