<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");
include 'db.php';

$id_project = $_GET['id_project'] ?? null;

if (!$id_project || !is_numeric($id_project)) {
    echo json_encode([]);
    exit;
}

$sql = "SELECT id_team, name AS team_name, id_project FROM Team WHERE id_project = ?";
$stmt = sqlsrv_prepare($conn, $sql, [$id_project]);

if ($stmt && sqlsrv_execute($stmt)) {
    $teams = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $teams[] = $row;
    }
    echo json_encode($teams);  // trebuie să fie un array []
} else {
    echo json_encode([]);
}
?>
