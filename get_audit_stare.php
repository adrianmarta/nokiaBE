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

// pagination params
$page     = isset($_GET['page']) && (int)$_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$per_page = isset($_GET['per_page']) && (int)$_GET['per_page'] > 0 ? (int)$_GET['per_page'] : 15;
$offset   = ($page - 1) * $per_page;

// authenticate user
$user    = authenticate([2,3]);
$id_user = $user['id_user'];
$id_rol  = (int)$user['id_rol'];

// fetch user team
$tsql     = "SELECT id_team FROM Utilizator WHERE id_user = ?";
$stmtTeam = sqlsrv_query($conn, $tsql, [$id_user]);
if (!$stmtTeam) throw new Exception("Cannot fetch team for user {\$id_user}");
$rowTeam = sqlsrv_fetch_array($stmtTeam, SQLSRV_FETCH_ASSOC);
$id_team = $rowTeam['id_team'];

// optional filters
$filters = [];
$params  = [];

if ($id_rol !== 3) {
    $filters[] = 'tm.id_team = ?';
    $params[]  = $id_team;
}
if (!empty($_GET['start_date'])) {
    $filters[] = 'a.timp >= ?';
    $params[]  = $_GET['start_date'] . ' 00:00:00';
}
if (!empty($_GET['end_date'])) {
    $filters[] = 'a.timp <= ?';
    $params[]  = $_GET['end_date'] . ' 23:59:59';
}
if (!empty($_GET['project_id'])) {
    $filters[] = 'p.id_project = ?';
    $params[]  = (int)$_GET['project_id'];
}
if (!empty($_GET['user_id'])) {
    $filters[] = 'a.id_user = ?';
    $params[]  = (int)$_GET['user_id'];
}
if (!empty($_GET['ticket_id'])) {
    $filters[] = 'a.id_ticket = ?';
    $params[]  = (int)$_GET['ticket_id'];
}

// build query
$where = count($filters) ? 'WHERE ' . implode(' AND ', $filters) : '';

$sql = "
   SELECT
        ROW_NUMBER() OVER (ORDER BY a.timp DESC) AS row_number,
        u.nume          AS nume_utilizator,
        act.actiune,
        p.provider,
        s1.nume         AS stare_curenta,
        s2.nume         AS stare_trecuta,
        t.id,
        a.id_ticket,
        a.timp
    FROM audit_stare a
    JOIN Utilizator     u   ON a.id_user           = u.id_user
    JOIN actiune         act ON a.id_actiune       = act.id
    JOIN Project         p   ON a.id_project       = p.id_project
    JOIN Team            tm  ON p.id_project       = tm.id_project
    JOIN Tickets         t   ON a.id_ticket        = t.id
    LEFT JOIN status_ticket s1 ON a.id_stare_curenta = s1.id_status
    LEFT JOIN status_ticket s2 ON a.id_stare_trecuta = s2.id_status
    {$where}
    ORDER BY a.timp DESC
    OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
    ";
// add paging params
$params[] = $offset;
$params[] = $per_page;

$stmt = sqlsrv_query($conn, $sql, $params);

if ($stmt) {
    $results = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $results[] = $row;
    }
    echo json_encode(["success" => true, "rows" => $results, "page" => $page, "per_page" => $per_page]);
} else {
    $error = sqlsrv_errors()[0]['message'] ?? 'Eroare necunoscută';
    echo json_encode(["success" => false, "error" => "Eroare la interogare.", "details" => $error]);
}
?>
