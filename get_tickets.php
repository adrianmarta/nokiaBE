<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");
if ($_SERVER['REQUEST_METHOD']==='OPTIONS') {
  http_response_code(200); exit;
}

include 'db.php';
require_once 'auth.php';

$user   = authenticate([2,3]);
$id_rol = (int)$user['id_rol'];
$params = [];
$where  = '';

// Super-admin: optional project filter
if ($id_rol === 3 && !empty($_GET['project_id'])) {
  $where = "WHERE id_project = ?";
  $params[] = (int)$_GET['project_id'];
}

// Admin (role=2): only tickets for projects your team works on
elseif ($id_rol === 2) {
  $tsql     = "SELECT id_team FROM Utilizator WHERE id_user = ?";
  $stmtTeam = sqlsrv_query($conn, $tsql, [$user['id_user']]);
  $rowTeam  = sqlsrv_fetch_array($stmtTeam, SQLSRV_FETCH_ASSOC);

  $where = "
    WHERE id_project IN (
      SELECT id_project FROM Team WHERE id_team = ?
    )
  ";
  $params[] = $rowTeam['id_team'];
}

$sql = "
  SELECT id, incident_title
  FROM Tickets
  {$where}
  ORDER BY incident_title
";
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
