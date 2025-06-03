<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

require_once 'db.php';

header('Content-Type: application/json');

$sql = "SELECT id_user, nume, mail FROM Utilizator WHERE rol = 'admin'";
$stmt = sqlsrv_query($conn, $sql);

$admins = [];
if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $admins[] = $row;
    }
}

echo json_encode($admins);
