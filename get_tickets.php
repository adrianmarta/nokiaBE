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
    // super-admin: all tickets
    $sql    = "SELECT id, incident_title 
               FROM Tickets
               ORDER BY incident_title";
    $params = [];
} else {
    // admin: only tickets whose project belongs to their team
     $sql = "
        SELECT t.id, t.incident_title
        FROM Tickets t
        JOIN Team tm ON t.id_project = tm.id_project
        WHERE tm.id_team = ?
        ORDER BY t.incident_title
    ";
    $params = [$id_team];
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
