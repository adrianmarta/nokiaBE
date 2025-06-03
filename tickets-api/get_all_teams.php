<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

require_once "db.php";
ini_set('display_errors', 1);
error_reporting(E_ALL);

$sql = "SELECT id_team, name AS team_name FROM Team";
$stmt = sqlsrv_query($conn, $sql);

$data = [];
if ($stmt) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $data[] = $row;
    }
    echo json_encode($data);
} else {
    echo json_encode(["error" => "Query failed", "details" => sqlsrv_errors()]);
}
