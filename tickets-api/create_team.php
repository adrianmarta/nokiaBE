<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

include 'db.php';

$input = json_decode(file_get_contents("php://input"), true);

if (!$input || empty($input["name"])) {
    echo json_encode([
        "success" => false,
        "message" => "Numele echipei este necesar."
    ]);
    exit;
}

$name = $input["name"];
$id_project = isset($input["id_project"]) ? $input["id_project"] : null;

$sql = "INSERT INTO Team (name, id_project) VALUES (?, ?)";
$params = [$name, $id_project];

$stmt = sqlsrv_prepare($conn, $sql, $params);

if ($stmt && sqlsrv_execute($stmt)) {
    echo json_encode([
        "success" => true,
        "message" => "Echipă creată cu succes."
    ]);
} else {
    echo json_encode([
        "success" => false,
        "message" => "Eroare la inserare.",
        "details" => sqlsrv_errors()
    ]);
}
?>
