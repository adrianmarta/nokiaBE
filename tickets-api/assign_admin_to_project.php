<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

require_once "db.php";
ini_set('display_errors', 1);
error_reporting(E_ALL);

$data = json_decode(file_get_contents("php://input"), true);

// DEBUG: răspunde înapoi cu datele primite
if (!$data) {
    echo json_encode([
        "success" => false,
        "message" => "Nu s-a putut decoda JSON-ul.",
        "raw" => file_get_contents("php://input")
    ]);
    exit;
}

if (!isset($data["projectId"]) || !isset($data["adminId"])) {
    echo json_encode([
        "success" => false,
        "message" => "Date lipsă.",
        "received" => $data
    ]);
    exit;
}

$projectId = $data["projectId"];
$adminId = $data["adminId"];

$sql = "UPDATE Project SET id_user = ? WHERE id_project = ?";
$stmt = sqlsrv_query($conn, $sql, [$adminId, $projectId]);

echo json_encode(["success" => $stmt ? true : false]);
