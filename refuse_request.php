<?php
require_once 'db.php';
require_once 'auth.php';

header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 🔐 Allow both admin and super_admin
$user = authenticate(2);
$id_user = $user['id_user'];
$id_rol = $user['id_rol'];

$input = json_decode(file_get_contents("php://input"), true);
$id = $input['id_cerere'] ?? null;

if (!$id) {
    http_response_code(400);
    echo json_encode(["error" => "Missing request ID"]);
    exit;
}

// Get request to validate project
$sql = "SELECT id_project FROM cereri WHERE id_cerere = ?";
$stmt = sqlsrv_query($conn, $sql, [$id]);

if (!$stmt || !sqlsrv_has_rows($stmt)) {
    http_response_code(404);
    echo json_encode(["error" => "Request not found"]);
    exit;
}

$row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

// 🔒 Admins can only act on their own project
if ($id_rol != 3) {
    $projectCheck = sqlsrv_query(
        $conn,
        "SELECT 1 FROM Projects WHERE id_project = ? AND id_user = ?",
        [$row['id_project'], $id_user]
    );
    if (!sqlsrv_fetch($projectCheck)) {
        http_response_code(403);
        echo json_encode(["error" => "Not authorized to refuse this request"]);
        exit;
    }
}

// ❌ Refuse request
$result = sqlsrv_query($conn, "UPDATE cereri SET id_status = 3 WHERE id_cerere = ?", [$id]);

if ($result) {
    echo json_encode(["message" => "Request refused"]);
} else {
    http_response_code(500);
    echo json_encode(["error" => "Failed to refuse request"]);
}
