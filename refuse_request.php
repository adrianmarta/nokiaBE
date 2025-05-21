<?php
require_once 'db.php';
require_once 'auth.php';
ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_NOTICE);
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

    $sql = "SELECT id_project FROM cereri WHERE id_cerere = ?";
    $stmt = sqlsrv_query($conn, $sql, [$id]);
    if (!$stmt || !sqlsrv_has_rows($stmt)) {
        throw new Exception("Request not found", 404);
    }

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
if ($data['id_rol'] == 2 && $id_rol !== 3) {
    throw new Exception("Only super-admins may approve or reject admin requests.", 403);
}
    if ($id_rol != 3) {
        $projectCheck = sqlsrv_query(
            $conn,
            "SELECT 1 FROM Utilizator WHERE id_user = ? AND id_project = ?",
            [$id_user, $row['id_project']]
        );

        if (!$projectCheck || !sqlsrv_fetch($projectCheck)) {
            throw new Exception("Not authorized to refuse this request", 403);
        }
    }

    $result = sqlsrv_query($conn, "UPDATE cereri SET id_status = 3 WHERE id_cerere = ?", [$id]);
    if (!$result) {
        throw new Exception("Failed to refuse request", 500);
    }

    echo json_encode(["message" => "Request refused"]);

} catch (Throwable $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["error" => $e->getMessage()]);
}
