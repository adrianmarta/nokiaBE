<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

include 'db.php';
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (!$conn) {
    echo json_encode(["error" => "Conexiune eșuată"]);
    exit;
}

$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
$offset = ($page - 1) * $limit;

$period = $_GET['period'] ?? '';

// ✅ Tratare filtre GOALE
$search           = isset($_GET['search']) && trim($_GET['search']) !== '' ? $_GET['search'] : null;
$priority         = isset($_GET['priority']) && trim($_GET['priority']) !== '' ? $_GET['priority'] : null;
$status           = isset($_GET['status']) && trim($_GET['status']) !== '' ? $_GET['status'] : null;
$project          = isset($_GET['project']) && trim($_GET['project']) !== '' ? $_GET['project'] : null;
$assigned_person  = isset($_GET['assigned_person']) && trim($_GET['assigned_person']) !== '' ? $_GET['assigned_person'] : null;
$dateFrom         = isset($_GET['dateFrom']) && trim($_GET['dateFrom']) !== '' ? $_GET['dateFrom'] : null;
$dateTo           = isset($_GET['dateTo']) && trim($_GET['dateTo']) !== '' ? $_GET['dateTo'] : null;
$ticket_id        = isset($_GET['ticket_id']) && trim($_GET['ticket_id']) !== '' ? $_GET['ticket_id'] : null;
$created_by       = isset($_GET['created_by']) && trim($_GET['created_by']) !== '' ? $_GET['created_by'] : null;

$where = [];
$params = [];

// ✅ Filtrare după perioadă
if ($period === 'day') {
    $where[] = "CAST(t.start_date AS DATE) = CAST(GETDATE() AS DATE)";
} elseif ($period === 'week') {
    $startOfWeek = (new DateTime('monday this week'))->setTime(0, 0);
    $endOfWeek = (clone $startOfWeek)->modify('+7 days');
    $where[] = "(t.start_date >= ? AND t.start_date < ?)";
    $params[] = $startOfWeek->format('Y-m-d H:i:s');
    $params[] = $endOfWeek->format('Y-m-d H:i:s');
} elseif ($period === 'month') {
    $where[] = "MONTH(t.start_date) = MONTH(GETDATE()) AND YEAR(t.start_date) = YEAR(GETDATE())";
} elseif ($period === 'year') {
    $where[] = "YEAR(t.start_date) = YEAR(GETDATE())";
}

// ✅ Filtre adiționale
if (!is_null($search)) {
    $where[] = "(t.ticket_id LIKE ? OR t.incident_title LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if (!is_null($ticket_id)) {
    $where[] = "t.ticket_id LIKE ?";
    $params[] = "%$ticket_id%";
}
if (!is_null($priority)) {
    $where[] = "p.priority = ?";
    $params[] = $priority;
}
if (!is_null($status)) {
    $where[] = "t.status = ?";
    $params[] = $status;
}
if (!is_null($project)) {
    $where[] = "t.project LIKE ?";
    $params[] = "%$project%";
}
if (!is_null($assigned_person)) {
    $where[] = "t.assigned_person LIKE ?";
    $params[] = "%$assigned_person%";
}
if (!is_null($created_by)) {
    $where[] = "t.created_by LIKE ?";
    $params[] = "%$created_by%";
}
if (!is_null($dateFrom)) {
    $where[] = "CAST(t.start_date AS DATE) >= ?";
    $params[] = $dateFrom;
}
if (!is_null($dateTo)) {
    $where[] = "CAST(t.start_date AS DATE) <= ?";
    $params[] = $dateTo;
}

$whereSQL = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

// ✅ Query pentru total
$countQuery = "
    SELECT COUNT(*) AS total
    FROM Tickets t
    LEFT JOIN Priority p ON t.priority_id = p.id
    $whereSQL
";
$countStmt = sqlsrv_query($conn, $countQuery, $params);
if ($countStmt === false) {
    echo json_encode(["error" => "Eroare la count query", "details" => sqlsrv_errors()]);
    exit;
}
$countRow = sqlsrv_fetch_array($countStmt, SQLSRV_FETCH_ASSOC);
$total = $countRow['total'] ?? 0;

// ✅ Query pentru tickete
$sql = "
SELECT 
    t.id, t.ticket_id, t.incident_title, t.status, t.project, t.description, t.comment,
    t.assigned_person, t.team_assigned_person, t.created_by, t.team_created_by,
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
$whereSQL
ORDER BY t.id ASC
OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
";

$params[] = $offset;
$params[] = $limit;

$stmt = sqlsrv_query($conn, $sql, $params);
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
