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

$user   = authenticate([2,3]);
$id_rol = (int)$user['id_rol'];
$params = [];
$where  = '';

// Super-admin: allow optional project filter
if ($id_rol === 3) {
    $teamIdFromRequest = isset($_GET['team_id']) ? (int)$_GET['team_id'] : 0;

    if ($teamIdFromRequest > 0) {
        // Dacă e selectată echipa => returnează doar userii din acea echipă
        $where = "WHERE id_team = ?";
        $params[] = $teamIdFromRequest;
    } elseif (!empty($_GET['project_id'])) {
        // Dacă e selectat doar project_id
        $where = "
            WHERE id_team IN (
                SELECT id_team FROM Team WHERE id_project = ?
            )
        ";
        $params[] = (int)$_GET['project_id'];
    } else {
        // Nici o filtrare → toți utilizatorii
        $where = "";
        $params = [];
    }
}
elseif ($id_rol === 2) {
    $teamIdFromRequest = isset($_GET['team_id']) ? (int)$_GET['team_id'] : 0;

    if ($teamIdFromRequest > 0) {
        // Dacă s-a selectat manual o echipă în filtrul frontend
        $where = "WHERE id_team = ?";
        $params[] = $teamIdFromRequest;
    } else {
        // Găsește echipa userului logat
        $tsqlTeam = "SELECT id_team FROM Utilizator WHERE id_user = ?";
        $stmtTeam = sqlsrv_query($conn, $tsqlTeam, [$user['id_user']]);
        $rowTeam  = sqlsrv_fetch_array($stmtTeam, SQLSRV_FETCH_ASSOC);
        $id_team  = $rowTeam['id_team'];

        // Găsește proiectul asociat echipei
        $tsqlProj = "SELECT id_project FROM Team WHERE id_team = ?";
        $stmtProj = sqlsrv_query($conn, $tsqlProj, [$id_team]);
        $rowProj  = sqlsrv_fetch_array($stmtProj, SQLSRV_FETCH_ASSOC);
        $id_project = $rowProj['id_project'];

        // Găsește toate echipele din acel proiect
        $tsqlAllTeams = "SELECT id_team FROM Team WHERE id_project = ?";
        $stmtAllTeams = sqlsrv_query($conn, $tsqlAllTeams, [$id_project]);

        $teamIds = [];
        while ($r = sqlsrv_fetch_array($stmtAllTeams, SQLSRV_FETCH_ASSOC)) {
            $teamIds[] = $r['id_team'];
        }

        if (count($teamIds) > 0) {
            $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
            $where = "WHERE id_team IN ($placeholders)";
            $params = $teamIds;
        } else {
            $where = "WHERE 1 = 0"; // fallback
        }
    }
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
