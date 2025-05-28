<?php
require_once 'db.php';
require_once 'auth.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
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

    $sql = "
SELECT
    c.id_cerere   AS id,
    c.nume        AS fullName,
    c.mail        AS email,
    CASE c.id_rol
        WHEN 3 THEN 'super_admin'
        WHEN 2 THEN 'admin'
        ELSE 'user'
    END           AS rol,
    s.status      AS status
FROM Cereri c
JOIN status s
  ON c.id_status = s.id_status;
    ";
    $params = [];

    $stmt = sqlsrv_query($conn, $sql, $params);
    if (!$stmt) {
        throw new Exception("Query failed", 500);
    }

    $requests = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $requests[] = $row;
    }

    echo json_encode($requests);

} catch (Throwable $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["error" => $e->getMessage()]);
}
