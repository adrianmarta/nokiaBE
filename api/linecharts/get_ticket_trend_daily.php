<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

include '../db.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(["error" => "Connection failed"]);
    exit;
}

$startDate = date('Y-01-01', strtotime('-1 year'));
$endDate = date('Y-m-d');

$teamCreatedByName = isset($_GET['team_created_by_name']) ? $_GET['team_created_by_name'] : null;
$teamAssignedPersonName = isset($_GET['team_assigned_person_name']) ? $_GET['team_assigned_person_name'] : null;
$priority = isset($_GET['priority']) ? $_GET['priority'] : null;
$project = isset($_GET['project']) ? $_GET['project'] : null;
$status = isset($_GET['status']) ? $_GET['status'] : null;
$sla = isset($_GET['sla']) ? $_GET['sla'] : null;
if ($sla) {
    $sla = rtrim($sla, "h");
}
$slaStatus = isset($_GET['slaStatus']) ? $_GET['slaStatus'] : null;

$sql = "
    SELECT
        t.*,
        p.priority AS priority_name,              -- Added for tooltip
        tcb.name AS team_created_by_name,         -- Added for tooltip
        tap.name AS team_assigned_person_name,    -- Added for tooltip
        pr.provider AS project_name,              -- Added for tooltip
        sla.duration_hours,                       -- Added for SLA calculation in tooltip
        DATEDIFF(HOUR, t.start_date, ISNULL(t.closed_date, GETDATE())) AS hours_taken, -- Added for SLA calculation in tooltip
        CASE                                      -- Added for SLA calculation in tooltip
            WHEN t.closed_date IS NOT NULL AND DATEDIFF(HOUR, t.start_date, t.closed_date) <= sla.duration_hours THEN 'Met'
            WHEN t.closed_date IS NULL AND DATEDIFF(HOUR, t.start_date, GETDATE()) > sla.duration_hours THEN 'Exceeded'
            WHEN t.closed_date IS NULL AND DATEDIFF(HOUR, t.start_date, GETDATE()) <= sla.duration_hours THEN 'In Progress'
            ELSE 'Other'
        END AS sla_status
    FROM Tickets t
    LEFT JOIN Priority p ON t.priority_id = p.id
    LEFT JOIN Team tcb ON t.team_created_by = tcb.id_team
    LEFT JOIN Team tap ON t.team_assigned_person = tap.id_team
    LEFT JOIN Project pr ON t.project = pr.id_project
    LEFT JOIN SLA sla ON t.priority_id = sla.priority_id
    WHERE
        ((t.start_date BETWEEN ? AND ?)
        OR (t.closed_date BETWEEN ? AND ?))
";


$params = [$startDate, $endDate, $startDate, $endDate];

if ($teamCreatedByName) {
    $sql .= " AND tcb.name = ?";
    $params[] = $teamCreatedByName;
}
if ($teamAssignedPersonName) {
    $sql .= " AND tap.name = ?";
    $params[] = $teamAssignedPersonName;
}
if ($priority) {
    $sql .= " AND p.priority = ?";
    $params[] = $priority;
}
if ($project) {
    $sql .= " AND pr.provider = ?";
    $params[] = $project;
}
if ($status) {
    $sql .= " AND t.status = ?";
    $params[] = $status;
}
if ($sla) {
    $sql .= " AND sla.duration_hours = ?";
    $params[] = $sla;
}
if ($slaStatus) {
    $sql .= " AND (
        CASE
            WHEN t.closed_date IS NOT NULL AND DATEDIFF(HOUR, t.start_date, t.closed_date) <= sla.duration_hours THEN 'Met'
            WHEN t.closed_date IS NULL AND DATEDIFF(HOUR, t.start_date, GETDATE()) > sla.duration_hours THEN 'Exceeded'
            WHEN t.closed_date IS NULL AND DATEDIFF(HOUR, t.start_date, GETDATE()) <= sla.duration_hours THEN 'In Progress'
            ELSE 'Other'
        END
    ) = ?";
    $params[] = $slaStatus;
}

$stmt = sqlsrv_query($conn, $sql, $params);
if ($stmt === false) {
    die(print_r(sqlsrv_errors(), true));
}

$tickets = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $tickets[] = $row;
}

$result = [];

foreach ($tickets as $ticket) {
    if (!empty($ticket['start_date']) && $ticket['start_date'] instanceof DateTime) {
        $startDateKey = $ticket['start_date']->format('l, M j, Y');
        if (!isset($result[$startDateKey])) {
            $result[$startDateKey] = [
                'date' => $startDateKey,
                'startCount' => 0,
                'startedTickets' => [],
                'closedCount' => 0,
                'closedTickets' => []
            ];
        }
        $result[$startDateKey]['startCount']++;
        $result[$startDateKey]['startedTickets'][] = $ticket;
    }
    if (!empty($ticket['closed_date']) && $ticket['closed_date'] instanceof DateTime) {
        $closeDateKey = $ticket['closed_date']->format('l, M j, Y');
        if (!isset($result[$closeDateKey])) {
            $result[$closeDateKey] = [
                'date' => $closeDateKey,
                'startCount' => 0,
                'startedTickets' => [],
                'closedCount' => 0,
                'closedTickets' => []
            ];
        }
        $result[$closeDateKey]['closedCount']++;
        $result[$closeDateKey]['closedTickets'][] = $ticket;
    }
}

uksort($result, function($a, $b) {
    return strtotime($a) <=> strtotime($b);
});

$output = array_values($result);

echo json_encode($output, JSON_PRETTY_PRINT);

sqlsrv_free_stmt($stmt);
sqlsrv_close($conn);
?>
