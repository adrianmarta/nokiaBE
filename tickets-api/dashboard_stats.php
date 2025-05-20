<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

include 'db.php';

if (!$conn) {
    echo json_encode(["error" => "Conexiune eșuată"]);
    exit;
}

$period = $_GET['period'] ?? 'all';
$periodCondition = "1=1";

if ($period === 'day') {
    $periodCondition = "start_date >= CAST(GETDATE() AS DATE)";
} elseif ($period === 'week') {
    $periodCondition = "start_date >= DATEADD(day, -7, GETDATE())";
} elseif ($period === 'month') {
    $periodCondition = "start_date >= DATEADD(month, -1, GETDATE())";
} elseif ($period === 'year') {
    $periodCondition = "start_date >= DATEADD(year, -1, GETDATE())";
}

// 1. Statistici generale
$statsQuery = "
SELECT 
    (SELECT COUNT(*) FROM Tickets WHERE $periodCondition) AS total,
    (SELECT COUNT(*) FROM Tickets WHERE status = 'Closed' AND $periodCondition) AS closed,
    (SELECT AVG(DATEDIFF(hour, start_date, closed_date)) FROM Tickets WHERE closed_date IS NOT NULL AND $periodCondition) AS avgTime
";
$statsResult = sqlsrv_query($conn, $statsQuery);
$stats = sqlsrv_fetch_array($statsResult, SQLSRV_FETCH_ASSOC) ?? ["total" => 0, "closed" => 0, "avgTime" => 0];
$stats['avgTime'] = $stats['avgTime'] ?? 0;

// 2. Tickete pe zile (opțional)
$dailyQuery = "
SELECT 
    FORMAT(start_date, 'yyyy-MM-dd') AS day,
    COUNT(*) AS total
FROM Tickets
WHERE $periodCondition
GROUP BY FORMAT(start_date, 'yyyy-MM-dd')
ORDER BY day ASC
";
$dailyResult = sqlsrv_query($conn, $dailyQuery);
$dailyChart = [];
while ($row = sqlsrv_fetch_array($dailyResult, SQLSRV_FETCH_ASSOC)) {
    $dailyChart[] = $row;
}

// 3. Lista tickete
$ticketsQuery = "
SELECT 
    t.id,
    t.incident_title,
    t.status,
    t.project,
    FORMAT(t.start_date, 'yyyy-MM-dd HH:mm:ss') as start_date,
    p.priority AS priority_name,
    t.assigned_person,
    t.description,
    t.comment,
    t.team_assigned_person,
    t.team_created_by,
    t.response_time,
    t.created_by,
    s.duration_hours,
    CASE 
        WHEN t.response_time IS NULL THEN '-' 
        WHEN s.duration_hours - t.response_time < 0 THEN 'OUT'
        ELSE 'IN'
    END AS IN_OUT_SLA,
    FORMAT(t.last_modified_date, 'yyyy-MM-dd HH:mm:ss') as last_modified_date,
    FORMAT(t.closed_date, 'yyyy-MM-dd HH:mm:ss') as closed_date
FROM Tickets t
JOIN Priority p ON t.priority_id = p.id
JOIN SLA s ON s.id = t.priority_id
WHERE $periodCondition
ORDER BY t.start_date DESC
";
$ticketsResult = sqlsrv_query($conn, $ticketsQuery);
$tickets = [];
while ($row = sqlsrv_fetch_array($ticketsResult, SQLSRV_FETCH_ASSOC)) {
    $tickets[] = $row;
}

// 4. Status breakdown pentru Pie Chart
$statusQuery = "
SELECT 
    status,
    COUNT(*) as count
FROM Tickets
WHERE $periodCondition
GROUP BY status
";
$statusResult = sqlsrv_query($conn, $statusQuery);
$statusChart = [];
while ($row = sqlsrv_fetch_array($statusResult, SQLSRV_FETCH_ASSOC)) {
    $statusChart[] = $row;
}

// 5. Tickete Deschise și Închise pe Echipă (corect)
$teamQuery = "
SELECT 
    team_assigned_person,
    SUM(CASE WHEN status != 'Closed' THEN 1 ELSE 0 END) AS openTickets,
    SUM(CASE WHEN status = 'Closed' THEN 1 ELSE 0 END) AS closedTickets
FROM Tickets
WHERE $periodCondition
GROUP BY team_assigned_person
ORDER BY team_assigned_person
";
$teamResult = sqlsrv_query($conn, $teamQuery);
$teamChart = [];
while ($row = sqlsrv_fetch_array($teamResult, SQLSRV_FETCH_ASSOC)) {
    $teamChart[] = $row;
}

// OUTPUT
echo json_encode([
    "stats" => $stats,
    "dailyChart" => $dailyChart,
    "tickets" => $tickets,
    "statusChart" => $statusChart,
    "teamChart" => $teamChart
]);

sqlsrv_close($conn);
?>
