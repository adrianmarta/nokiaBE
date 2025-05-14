<?php
require_once 'db.php';
require_once 'auth.php';

header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$user = authenticate();
$id_user = $user['id_user'];
$id_rol = $user['id_rol'];

if ($id_rol == 3) {
    // super_admin: see all
    $sql = "
        SELECT 
            c.id_cerere AS id,
            c.nume AS fullName,
            c.mail AS email,
            p.provider AS projectName,
            s.status AS status
        FROM Cereri c
        JOIN status s ON c.id_status = s.id_status
        JOIN Project p ON c.id_project = p.id_project
    ";
    $params = [];
} else {
    // admin: only own project
    $sql = "
        SELECT 
            c.id_cerere AS id,
            c.nume AS fullName,
            c.mail AS email,
            p.provider AS projectName,
            s.status AS status
        FROM Cereri c
        JOIN status s ON c.id_status = s.id_status
        JOIN Project p ON c.id_project = p.id_project
        WHERE c.id_project = (
            SELECT id_project FROM Project WHERE id_user = ?
        )
    ";
    $params = [$id_user];
}

$stmt = sqlsrv_query($conn, $sql, $params);
$requests = [];

if (!$stmt) {
    http_response_code(500);
    die(json_encode([
        "error" => "Query failed",
        "details" => sqlsrv_errors()
    ]));
}

while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $requests[] = $row;
}

echo json_encode($requests);
