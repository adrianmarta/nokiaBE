<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

include 'db.php';

$data = json_decode(file_get_contents("php://input"), true);

$id_user = $data['id_user'] ?? null;
$id_team = $data['id_team'] ?? null;

if (!$id_user || !$id_team) {
    echo json_encode(["success" => false, "message" => "Date lipsă"]);
    exit;
}

$sql = "UPDATE Utilizator SET id_team = ? WHERE id_user = ?";
$stmt = sqlsrv_prepare($conn, $sql, [$id_team, $id_user]);

if ($stmt && sqlsrv_execute($stmt)) {
    echo json_encode(["success" => true]);
} else {
    echo json_encode(["success" => false, "message" => "Eroare la asignare"]);
}
?>
