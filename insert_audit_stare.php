<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
include 'db.php';
require_once 'auth.php';

$input = json_decode(file_get_contents("php://input"), true);

if (!$input) {
    echo json_encode(["success" => false, "error" => "Date JSON invalide."]);
    exit;
}

$user = authenticate();
$id_user = $user['id_user'];

$id_actiune       = $input['id_actiune']       ?? null;
$id_project       = $input['id_project']       ?? null;
$id_ticket        = $input['id_ticket']        ?? null;
$id_stare_curenta = $input['id_stare_curenta'] ?? null;

date_default_timezone_set('Europe/Bucharest');
$now = date('Y-m-d H:i:s');

if (!$id_actiune || !$id_project || !$id_ticket || !$id_stare_curenta) {
    echo json_encode(["success" => false, "error" => "Câmpuri obligatorii lipsă."]);
    exit;
}

$sql = "INSERT INTO audit_stare (
            id_user, id_actiune, id_project,
            timp, id_ticket, id_stare_curenta
        )
        VALUES (?, ?, ?, ?, ?, ?)";

$params = [$id_user, $id_actiune, $id_project, $now, $id_ticket, $id_stare_curenta];

$stmt = sqlsrv_query($conn, $sql, $params);

if ($stmt) {
    echo json_encode(["success" => true, "message" => "Audit înregistrat."]);
} else {
    $error = sqlsrv_errors()[0]['message'] ?? 'Eroare necunoscută';
    echo json_encode([
        "success" => false,
        "error" => "Eroare la inserare în audit_stare.",
        "details" => $error
    ]);
}
?>
