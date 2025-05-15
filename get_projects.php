<?php
require_once 'db.php';

header("Access-Control-Allow-Origin: http://localhost:3000");
header("Content-Type: application/json");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$sql = "SELECT id_project, provider FROM Project";
$stmt = sqlsrv_query($conn, $sql);

$projects = [];

if ($stmt) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $projects[] = $row;
    }
    echo json_encode($projects);
} else {
    http_response_code(500);
    echo json_encode([
        "error" => "Database query failed",
        "details" => sqlsrv_errors()
    ]);
}
