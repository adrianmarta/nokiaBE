<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

include '../db.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(["error" => "Connection failed"]);
    exit;
}

$startDate = date('Y-01-01', strtotime('first day of January last year'));
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

$where = "
    t.closed_date IS NOT NULL
    AND t.closed_date BETWEEN ? AND ?
";
$params = [$startDate, $endDate];

// Adding filters here **without changing original functionality**
if ($teamCreatedByName) {
    $where .= " AND tcb.name = ?";
    $params[] = $teamCreatedByName;
}
if ($teamAssignedPersonName) {
    $where .= " AND tm.name = ?";
    $params[] = $teamAssignedPersonName;
}
if ($priority) {
    $where .= " AND p.priority = ?";
    $params[] = $priority;
}
if ($project) {
    $where .= " AND pr.provider = ?";
    $params[] = $project;
}
if ($status) {
    $where .= " AND t.status = ?";
    $params[] = $status;
}
if ($sla) {
    $where .= " AND sla.duration_hours = ?";
    $params[] = $sla;
}
if ($slaStatus) {
    $where .= " AND (
        CASE
            WHEN t.closed_date IS NOT NULL AND DATEDIFF(HOUR, t.start_date, t.closed_date) <= sla.duration_hours THEN 'Met'
            WHEN t.closed_date IS NOT NULL AND DATEDIFF(HOUR, t.start_date, t.closed_date) > sla.duration_hours THEN 'Exceeded'
            WHEN t.closed_date IS NULL AND DATEDIFF(HOUR, t.start_date, GETDATE()) > sla.duration_hours THEN 'Exceeded'
            ELSE 'Other'
        END
    ) = ?";
    $params[] = $slaStatus;
}

$sql = "
    SELECT 
        t.*,
        pr.provider AS project_name,
        p.id AS priority_id,
        sla.duration_hours,
        DATEDIFF(HOUR, t.start_date, t.closed_date) AS hours_taken,
        CASE
            WHEN t.closed_date IS NOT NULL AND DATEDIFF(HOUR, t.start_date, t.closed_date) <= sla.duration_hours THEN 'Met'
            WHEN t.closed_date IS NOT NULL AND DATEDIFF(HOUR, t.start_date, t.closed_date) > sla.duration_hours THEN 'Exceeded'
            WHEN t.closed_date IS NULL AND DATEDIFF(HOUR, t.start_date, GETDATE()) > sla.duration_hours THEN 'Exceeded'
            ELSE 'Other'
        END AS sla_status,
        FORMAT(t.closed_date, 'MMM yyyy') AS closed_month_year
    FROM Tickets t
    INNER JOIN Priority p ON t.priority_id = p.id
    INNER JOIN Project pr ON t.project = pr.id_project
    INNER JOIN SLA sla ON p.id = sla.priority_id
    INNER JOIN Team tm ON t.team_assigned_person = tm.id_team
    LEFT JOIN Team tcb ON t.team_created_by = tcb.id_team
    WHERE $where
    ORDER BY t.closed_date
";

$stmt = sqlsrv_query($conn, $sql, $params);
if ($stmt == false) {
    http_response_code(500);
    echo json_encode(["error" => sqlsrv_errors()]);
    exit;
}

$tickets = [];
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    foreach ($row as $key => $value) {
        if ($value instanceof DateTime) {
            $row[$key] = $value->format('Y-m-d H:i:s');
        }
    }
    $tickets[] = $row;
}

$grouped = [];

$period = new DatePeriod(
    new DateTime($startDate),
    new DateInterval('P1M'),
    (new DateTime ($endDate))->modify('+1 month')
);

foreach ($tickets as $ticket) {
    $month = $ticket['closed_month_year'];

    if (!isset($grouped[$month])) {
        $grouped[$month] = [
            'Exceeded' => 0,
            'Met' => 0,
            'date' => $month,
            'exceededTickets' => [],
            'metTickets' => []
        ];
    }

    if ($ticket['sla_status'] === 'Exceeded') {
        $grouped[$month]['Exceeded']++;
        $grouped[$month]['exceededTickets'][] = $ticket;
    } elseif ($ticket['sla_status'] === 'Met') {
        $grouped[$month]['Met']++;
        $grouped[$month]['metTickets'][] = $ticket;
    }
}

$response = array_values($grouped);
echo json_encode($response, JSON_PRETTY_PRINT);

sqlsrv_free_stmt($stmt);
sqlsrv_close($conn);
?>
