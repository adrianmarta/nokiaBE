<?php
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

ini_set('display_errors', 1);
error_reporting(E_ALL);

include 'db.php';
require_once __DIR__ . '/../auth.php';

$user = authenticate(); // returnează id_user, id_rol
$currentUserId = $user['id_user'];
$role = $user['id_rol'];

if (!$conn) {
    echo json_encode(["error" => "Conexiune eșuată"]);
    exit;
}

$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
$offset = ($page - 1) * $limit;

$period = $_GET['period'] ?? '';

$search           = isset($_GET['search']) && trim($_GET['search']) !== '' ? $_GET['search'] : null;
$priority         = isset($_GET['priority']) && trim($_GET['priority']) !== '' ? $_GET['priority'] : null;
$status           = isset($_GET['status']) && trim($_GET['status']) !== '' ? $_GET['status'] : null;
$project          = isset($_GET['project']) && trim($_GET['project']) !== '' ? $_GET['project'] : null;
$assigned_person  = isset($_GET['assigned_person']) && trim($_GET['assigned_person']) !== '' ? $_GET['assigned_person'] : null;
$dateFrom         = isset($_GET['dateFrom']) && trim($_GET['dateFrom']) !== '' ? $_GET['dateFrom'] : null;
$dateTo           = isset($_GET['dateTo']) && trim($_GET['dateTo']) !== '' ? $_GET['dateTo'] : null;
$ticket_id        = isset($_GET['ticket_id']) && trim($_GET['ticket_id']) !== '' ? $_GET['ticket_id'] : null;
$created_by       = isset($_GET['created_by']) && trim($_GET['created_by']) !== '' ? $_GET['created_by'] : null;

// Construim filtrele suplimentare (pentru WHERE)
$whereFilters = [];
$filterParams = [];

// Filtrare perioadă
if ($period === 'day') {
    $whereFilters[] = "CAST(t.start_date AS DATE) = CAST(GETDATE() AS DATE)";
} elseif ($period === 'week') {
    $startOfWeek = (new DateTime('monday this week'))->setTime(0, 0);
    $endOfWeek = (clone $startOfWeek)->modify('+7 days');
    $whereFilters[] = "(t.start_date >= ? AND t.start_date < ?)";
    $filterParams[] = $startOfWeek->format('Y-m-d H:i:s');
    $filterParams[] = $endOfWeek->format('Y-m-d H:i:s');
} elseif ($period === 'month') {
    $whereFilters[] = "MONTH(t.start_date) = MONTH(GETDATE()) AND YEAR(t.start_date) = YEAR(GETDATE())";
} elseif ($period === 'year') {
    $whereFilters[] = "YEAR(t.start_date) = YEAR(GETDATE())";
}

// Filtre adiționale
if (!is_null($ticket_id)) {
    $whereFilters[] = "t.ticket_id LIKE ?";
    $filterParams[] = "%$ticket_id%";
}
if (!is_null($priority)) {
    $whereFilters[] = "p.priority = ?";
    $filterParams[] = $priority;
}
if (!is_null($status)) {
    $whereFilters[] = "t.status = ?";
    $filterParams[] = $status;
}
if (!is_null($project)) {
    $whereFilters[] = "t.project LIKE ?";
    $filterParams[] = "%$project%";
}
if (!is_null($assigned_person)) {
    $whereFilters[] = "t.assigned_person LIKE ?";
    $filterParams[] = "%$assigned_person%";
}
if (!is_null($created_by)) {
    $whereFilters[] = "t.created_by LIKE ?";
    $filterParams[] = "%$created_by%";
}
if (!is_null($dateFrom)) {
    $whereFilters[] = "CAST(t.start_date AS DATE) >= ?";
    $filterParams[] = $dateFrom;
}
if (!is_null($dateTo)) {
    $whereFilters[] = "CAST(t.start_date AS DATE) <= ?";
    $filterParams[] = $dateTo;
}

// Acum construim filtrul pe rol + combinăm cu filtrele suplimentare

$whereRole = [];
$params = [];

if ($role == 1) {
    // User simplu: doar tichetele lui
    $whereRole[] = "t.assigned_person = ?";
    $params[] = $currentUserId;
}  elseif ($role == 2) {
    // Admin: extragem proiectele unde el este owner (id_user în Project)
    $projectSql = "SELECT id_project FROM Project WHERE id_user = ?";
    $projectStmt = sqlsrv_query($conn, $projectSql, [$currentUserId]);

    $projectIds = [];
    while ($projectRow = sqlsrv_fetch_array($projectStmt, SQLSRV_FETCH_ASSOC)) {
        $projectIds[] = $projectRow['id_project'];
    }

    if (!empty($projectIds)) {
        $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
        $whereRole[] = "t.project IN ($placeholders)";
        $params = array_merge($params, $projectIds);
    } else {
        $whereRole[] = "1 = 0"; // nu are proiecte asociate
    }
}


// Combinăm toate filtrele în WHERE
$whereAll = array_merge($whereRole, $whereFilters);

$whereSQL = count($whereAll) > 0 ? "WHERE " . implode(" AND ", $whereAll) : "";

// Construim query-ul total count (folosind aceleași filtre)
$countQuery = "
    SELECT COUNT(*) AS total
    FROM Tickets t
    LEFT JOIN Priority p ON t.priority_id = p.id
    $whereSQL
";

$countParams = array_merge($params, $filterParams);

$countStmt = sqlsrv_query($conn, $countQuery, $countParams);

if ($countStmt === false) {
    echo json_encode(["error" => "Eroare la count query", "details" => sqlsrv_errors()]);
    exit;
}

$countRow = sqlsrv_fetch_array($countStmt, SQLSRV_FETCH_ASSOC);
$total = $countRow['total'] ?? 0;

// Query-ul principal cu paginare

$sql = "
SELECT 
    u.mail AS assigned_person,
    t.id, t.ticket_id, t.incident_title, t.status, 
    pj.provider AS project,
    t.description, t.comment,
    ta.name AS team_assigned_person,
    uc.mail AS created_by,
    tc.name AS team_created_by,
    FORMAT(t.assigned_date, 'yyyy-MM-dd HH:mm:ss') AS assigned_date,
    t.response_time, ISNULL(s.duration_hours, 0) AS duration_hours,
    FORMAT(t.start_date, 'yyyy-MM-dd HH:mm:ss') AS start_date,
    FORMAT(t.last_modified_date, 'yyyy-MM-dd HH:mm:ss') AS last_modified_date,
    FORMAT(t.closed_date, 'yyyy-MM-dd HH:mm:ss') AS closed_date,
    p.priority AS priority_name,
    CASE 
        WHEN t.closed_date IS NOT NULL AND DATEDIFF(HOUR, t.start_date, t.closed_date) <= ISNULL(s.duration_hours, 0) THEN 'IN'
        WHEN t.closed_date IS NOT NULL THEN 'OUT'
        WHEN t.closed_date IS NULL AND DATEDIFF(HOUR, t.start_date, GETDATE()) <= ISNULL(s.duration_hours, 0) THEN 'IN'
        ELSE 'OUT'
    END AS sla_status

FROM Tickets t
LEFT JOIN Priority p ON t.priority_id = p.id
LEFT JOIN SLA s ON s.priority_id = t.priority_id
LEFT JOIN Utilizator u ON u.id_user = t.assigned_person
LEFT JOIN Utilizator uc ON uc.id_user = t.created_by
LEFT JOIN Team ta ON ta.id_team = t.team_assigned_person
LEFT JOIN Team tc ON tc.id_team = t.team_created_by
LEFT JOIN Project pj ON pj.id_project = t.project
$whereSQL
ORDER BY t.id ASC
OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
";

$paramsFinal = array_merge($params, $filterParams);
$paramsFinal[] = $offset;
$paramsFinal[] = $limit;

$stmt = sqlsrv_query($conn, $sql, $paramsFinal);

if ($stmt === false) {
    echo json_encode(["error" => "Eroare la queryul de tickete", "details" => sqlsrv_errors()]);
    exit;
}

$tickets = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $tickets[] = $row;
}

echo json_encode([
    "tickets" => $tickets,
    "totalCount" => $total
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

sqlsrv_close($conn);