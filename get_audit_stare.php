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

// 1) Pagination
$page     = isset($_GET['page'])     ? max((int)$_GET['page'], 1)     : 1;
$per_page = isset($_GET['per_page']) ? max((int)$_GET['per_page'], 1) : 15;
$offset   = ($page - 1) * $per_page;

// 2) Authenticate & fetch the caller’s team
$user    = authenticate([2,3]);
$id_user = $user['id_user'];
$id_rol  = (int)$user['id_rol'];

$tsql     = "SELECT id_team FROM Utilizator WHERE id_user = ?";
$stmtTeam = sqlsrv_query($conn, $tsql, [$id_user]);
if (!$stmtTeam) {
    echo json_encode(["success" => false, "error" => "Cannot fetch team for user {$id_user}"]);
    exit;
}
$rowTeam = sqlsrv_fetch_array($stmtTeam, SQLSRV_FETCH_ASSOC);
$id_team = $rowTeam['id_team'];

// 3) Build dynamic WHERE clauses
$filters = [];
$params  = [];

// team filter
if ($id_rol === 2) {
    $filters[] = 'tm.id_team = ?';
    $params[]  = $id_team;
} elseif ($id_rol === 3 && isset($_GET['team_id']) && $_GET['team_id'] !== '') {
    $filters[] = 'tm.id_team = ?';
    $params[]  = (int)$_GET['team_id'];
}

// date range
if (!empty($_GET['start_date'])) {
    $filters[] = 'a.timp >= ?';
    $params[]  = $_GET['start_date'] . ' 00:00:00';
}
if (!empty($_GET['end_date'])) {
    $filters[] = 'a.timp <= ?';
    $params[]  = $_GET['end_date'] . ' 23:59:59';
}

// project filter
if (!empty($_GET['project_id'])) {
    $filters[] = 'p.id_project = ?';
    $params[]  = (int)$_GET['project_id'];
}

// user filter
if (!empty($_GET['user_id'])) {
    $filters[] = 'a.id_user = ?';
    $params[]  = (int)$_GET['user_id'];
}

// ticket filter
if (!empty($_GET['ticket_id'])) {
    $filters[] = 'a.id_ticket = ?';
    $params[]  = (int)$_GET['ticket_id'];
}

$where = $filters ? 'WHERE ' . implode(' AND ', $filters) : '';

// 4) Get total count for pagination
$countSql = "
    SELECT COUNT(*) AS totalRows
    FROM audit_stare a
    JOIN Project        p  ON a.id_project       = p.id_project
    JOIN Team           tm ON p.id_project       = tm.id_project
    JOIN Utilizator     u  ON a.id_user          = u.id_user
    JOIN actiune        act ON a.id_actiune      = act.id
    JOIN Tickets        t  ON a.id_ticket        = t.id
    LEFT JOIN status_ticket s1 ON a.id_stare_curenta = s1.id_status
    LEFT JOIN status_ticket s2 ON a.id_stare_trecuta = s2.id_status
    {$where}
";
$countStmt = sqlsrv_query($conn, $countSql, $params);
if (!$countStmt) {
    echo json_encode(["success" => false, "error" => "Count query failed"]);
    exit;
}
$totalRow  = sqlsrv_fetch_array($countStmt, SQLSRV_FETCH_ASSOC);
$totalRows = (int)$totalRow['totalRows'];

// 5) Main paged query
$sql = "
    SELECT
        ROW_NUMBER() OVER (ORDER BY a.timp DESC) AS row_number,
        u.nume           AS nume_utilizator,
        act.actiune      ,
        tm.name          ,
        p.provider       ,
        s1.nume          AS stare_curenta,
        s2.nume          AS stare_trecuta,
        t.id,
        a.id_ticket     ,
        a.timp          
    FROM audit_stare a
    JOIN Utilizator     u   ON a.id_user     = u.id_user
    JOIN actiune         act ON a.id_actiune = act.id
    JOIN Project         p   ON a.id_project = p.id_project
    JOIN Team            tm  ON p.id_project = tm.id_project
    JOIN Tickets         t   ON a.id_ticket  = t.id
    LEFT JOIN status_ticket s1 ON a.id_stare_curenta = s1.id_status
    LEFT JOIN status_ticket s2 ON a.id_stare_trecuta = s2.id_status
    {$where}
    ORDER BY a.timp DESC
    OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
";
$params[] = $offset;
$params[] = $per_page;

$stmt = sqlsrv_query($conn, $sql, $params);
if (!$stmt) {
    $err = sqlsrv_errors()[0]['message'] ?? 'Unknown error';
    echo json_encode(["success" => false, "error" => "Query failed", "details" => $err]);
    exit;
}

// 6) Fetch rows
$results = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $results[] = $row;
}

// 7) Return JSON with total
echo json_encode([
    "success"   => true,
    "rows"      => $results,
    "page"      => $page,
    "per_page"  => $per_page,
    "total"     => $totalRows
]);
