<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

include 'db.php';
require_once 'auth.php';

// authenticate
$user    = authenticate([2,3]);
$id_user = $user['id_user'];
$id_rol  = (int)$user['id_rol'];

// find user's team
$tsql     = "SELECT id_team FROM Utilizator WHERE id_user = ?";
$stmtTeam = sqlsrv_query($conn, $tsql, [$id_user]);
$rowTeam  = sqlsrv_fetch_array($stmtTeam, SQLSRV_FETCH_ASSOC);
$id_team  = $rowTeam['id_team'];

// build query
if ($id_rol === 3) {
    $sql    = "SELECT id_project, provider FROM Project ORDER BY provider";
    $params = [];
} else {
     $sql    = "
        SELECT id_project, provider, id_user
        FROM Project
        WHERE id_user = ?
        ORDER BY provider
    ";
    $params = [$id_user ];
}

$stmt = sqlsrv_query($conn, $sql, $params);
if (!$stmt) {
    echo json_encode([
      "success" => false,
      "error"   => sqlsrv_errors()[0]['message'] ?? 'Query error'
    ]);
    exit;
}

$rows = [];
while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $rows[] = $r;
}

echo json_encode([
  "success" => true,
  "rows"    => $rows
]);
