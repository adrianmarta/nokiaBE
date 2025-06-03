<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");
include 'db.php';

$id_team = $_GET['id_team'] ?? null;

if (!$id_team) {
    echo json_encode([]);
    exit;
}

$sql = "SELECT id_user, nume, mail, id_team FROM Utilizator WHERE id_team = ?";
$stmt = sqlsrv_prepare($conn, $sql, [$id_team]);
if (sqlsrv_execute($stmt)) {
    $users = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $users[] = $row;
    }
    echo json_encode($users);
} else {
    echo json_encode([]);
}
?>
