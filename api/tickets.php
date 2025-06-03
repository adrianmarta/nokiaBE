<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

include 'db.php'; // conexiunea $conn

if (!$conn) {
    http_response_code(500);
    echo json_encode(["error" => "Connection failed"]);
    exit;
}

$search = isset($_GET['search']) && trim($_GET['search']) !== '' ? $_GET['search'] : null;
$priority = isset($_GET['priority']) && trim($_GET['priority']) !== '' ? $_GET['priority'] : null;
$status = isset($_GET['status']) && trim($_GET['status']) !== '' ? $_GET['status'] : null;
$project = isset($_GET['project']) && trim($_GET['project']) !== '' ? (int)$_GET['project'] : null;
$assigned_person = isset($_GET['assigned_person']) && trim($_GET['assigned_person']) !== '' ? (int)$_GET['assigned_person'] : null;
$created_by = isset($_GET['created_by']) && trim($_GET['created_by']) !== '' ? (int)$_GET['created_by'] : null;
$dateFrom = isset($_GET['dateFrom']) && trim($_GET['dateFrom']) !== '' ? $_GET['dateFrom'] : null;
$dateTo = isset($_GET['dateTo']) && trim($_GET['dateTo']) !== '' ? $_GET['dateTo'] : null;
$period = isset($_GET['period']) ? $_GET['period'] : null;
$team_assigned_person = isset($_GET['team_assigned_person']) && trim($_GET['team_assigned_person']) !== '' ? (int)$_GET['team_assigned_person'] : null;
$team_created_by = isset($_GET['team_created_by']) && trim($_GET['team_created_by']) !== '' ? (int)$_GET['team_created_by'] : null;
$slaStatus = isset($_GET['slaStatus']) && trim($_GET['slaStatus']) !== '' ? $_GET['slaStatus'] : null;
$sla = isset($_GET['sla']) && trim($_GET['sla']) !== '' ? $_GET['sla'] : null;

$where = [];
$params = [];

// Perioada
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

// Alte filtre
if (!is_null($team_assigned_person)) {
    $where[] = "t.team_assigned_person = ?";
    $params[] = $team_assigned_person;
}
if (!is_null($team_created_by)) {
    $where[] = "t.team_created_by = ?";
    $params[] = $team_created_by;
}
if (!is_null($priority)) {
    $where[] = "p.priority = ?";
    $params[] = $priority;
}
if (!is_null($project)) {
    $where[] = "t.project = ?";
    $params[] = $project;
}
if (!is_null($status)) {
    $where[] = "t.status = ?";
    $params[] = $status;
}
if (!is_null($dateFrom)) {
    $where[] = "t.start_date >= ?";
    $params[] = $dateFrom;
}
if (!is_null($dateTo)) {
    $where[] = "t.start_date <= ?";
    $params[] = $dateTo;
}

if (!is_null($sla)) {
    $duration = (int) filter_var($sla, FILTER_SANITIZE_NUMBER_INT);
    $where[] = "s.duration_hours = ?";
    $params[] = $duration;
}

// SLA Status
if (!is_null($slaStatus)) {
    if ($slaStatus === "Met") {
        $where[] = "(
            (t.closed_date IS NOT NULL AND DATEDIFF(HOUR, t.start_date, t.closed_date) <= ISNULL(s.duration_hours, 0))
            OR
            (t.closed_date IS NULL AND DATEDIFF(HOUR, t.start_date, GETDATE()) <= ISNULL(s.duration_hours, 0))
        )";
    } elseif ($slaStatus === "Exceeded") {
        $where[] = "(
            (t.closed_date IS NOT NULL AND DATEDIFF(HOUR, t.start_date, t.closed_date) > ISNULL(s.duration_hours, 0))
            OR
            (t.closed_date IS NULL AND DATEDIFF(HOUR, t.start_date, GETDATE()) > ISNULL(s.duration_hours, 0))
        )";
    }
}

// Construiește query-ul
$sql = "
SELECT 
    t.ticket_id,
    t.status,
    t.response_time,
    t.last_modified_date,
    t.comment,
    t.closed_date,
    t.description,
    t.incident_title,
    t.start_date,
    p.priority AS priority,
    ta.name AS team_assigned_person,
    tc.name AS team_created_by,
    cu.nume AS created_by_name,
    ap.nume AS assigned_person_name,
    pr.provider AS project,
    s.duration_hours -- timpul SLA aferent priorității
FROM TicketsDB.dbo.Tickets t
LEFT JOIN dbo.Priority p ON t.priority_id = p.id
LEFT JOIN dbo.SLA s ON p.id = s.priority_id
LEFT JOIN dbo.Team ta ON t.team_assigned_person = ta.id_team
LEFT JOIN dbo.Team tc ON t.team_created_by = tc.id_team
LEFT JOIN dbo.Utilizator cu ON t.created_by = cu.id_user
LEFT JOIN dbo.Utilizator ap ON t.assigned_person = ap.id_user
LEFT JOIN dbo.Project pr ON t.project = pr.id_project
WHERE 
    YEAR(t.start_date) = YEAR(GETDATE()) - 1

";

if (count($where) > 0) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

$sql .= " ORDER BY t.ticket_id ASC";

$stmt = sqlsrv_query($conn, $sql, $params);
if ($stmt === false) {
    http_response_code(500);
    echo json_encode(["error" => print_r(sqlsrv_errors(), true)]);
    exit;
}

$tickets = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    if (isset($row['last_modified_date']) && $row['last_modified_date'] instanceof DateTime) {
        $row['last_modified_date_only'] = $row['last_modified_date']->format('Y-m-d');
        $row['last_modified_time_only'] = $row['last_modified_date']->format('H:i:s');
    } else {
        $row['last_modified_date_only'] = null;
        $row['last_modified_time_only'] = null;
    }
    $tickets[] = $row;
}

echo json_encode($tickets);

sqlsrv_free_stmt($stmt);
sqlsrv_close($conn);
?>
