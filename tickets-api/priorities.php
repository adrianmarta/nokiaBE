<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
include 'db.php';

$sql = "SELECT id, priority FROM Priority ORDER BY priority ASC";
$stmt = sqlsrv_query($conn, $sql);
$priorities = [];

while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $priorities[] = $row;
}

header('Content-Type: application/json');
echo json_encode($priorities);
