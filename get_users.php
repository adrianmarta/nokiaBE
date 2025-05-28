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

// Super-admin: allow optional project filter
if ($id_rol === 3 && !empty($_GET['project_id'])) {
  // only users in teams that work on that project
  $where = "
    WHERE id_team IN (
      SELECT id_team FROM Team WHERE id_project = ?
    )
  ";
  $params[] = (int)$_GET['project_id'];
}

// Admin (role=2): only your own team
elseif ($id_rol === 2) {
  $tsql     = "SELECT id_team FROM Utilizator WHERE id_user = ?";
  $stmtTeam = sqlsrv_query($conn, $tsql, [$user['id_user']]);
  $rowTeam  = sqlsrv_fetch_array($stmtTeam, SQLSRV_FETCH_ASSOC);
  $where    = "WHERE id_team = ?";
  $params[] = $rowTeam['id_team'];
}

$sql = "
  SELECT id_user, nume
  FROM Utilizator
  {$where}
  ORDER BY nume
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
