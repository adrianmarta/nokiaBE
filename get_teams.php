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

// only super-admins need to see teams
authenticate([3]);

// optional project filter
$params = [];
$where  = '';
if (!empty($_GET['project_id'])) {
  $where    = 'WHERE id_project = ?';
  $params[] = (int)$_GET['project_id'];
}

$sql = "
  SELECT id_team, name
  FROM Team
  {$where}
  ORDER BY name
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
