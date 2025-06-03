<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

include 'db.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$input = json_decode(file_get_contents("php://input"), true);

if (!$input || empty($input["provider"])) {
    echo json_encode([
        "success" => false,
        "message" => "Numele proiectului este necesar."
    ]);
    exit;
}

$provider = $input["provider"];

$sql = "INSERT INTO Project (provider) VALUES (?)";
$stmt = sqlsrv_prepare($conn, $sql, [$provider]);

if ($stmt && sqlsrv_execute($stmt)) {
    echo json_encode([
        "success" => true,
        "message" => "Proiect creat cu succes."
    ]);
} else {
    $errors = sqlsrv_errors();
    echo json_encode([
        "success" => false,
        "message" => "Eroare la inserare.",
        "details" => $errors
    ]);
}
?>
