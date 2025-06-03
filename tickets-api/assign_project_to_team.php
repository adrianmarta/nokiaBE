<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

require_once "db.php";
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Citește datele din body
$data = json_decode(file_get_contents("php://input"), true);

// Validează datele primite
if (!$data || !isset($data["projectId"]) || !isset($data["teamId"])) {
    echo json_encode([
        "success" => false,
        "message" => "Date lipsă.",
        "received" => $data
    ]);
    exit;
}

$projectId = $data["projectId"];
$teamId = $data["teamId"];

// Execută update-ul în tabelul Team
$sql = "UPDATE Team SET id_project = ? WHERE id_team = ?";
$stmt = sqlsrv_query($conn, $sql, [$projectId, $teamId]);

if ($stmt) {
    echo json_encode(["success" => true, "message" => "Proiectul a fost asignat echipei."]);
} else {
    echo json_encode([
        "success" => false,
        "message" => "Eroare la actualizarea bazei de date.",
        "details" => sqlsrv_errors()
    ]);
}
