<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

include 'db.php';
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (!$conn) {
    echo json_encode(["error" => "Conexiune eșuată"]);
    exit;
}

$period = $_GET['period'] ?? 'all';
$whereClause = "1=1";
$params = [];

if ($period === 'day') {
    $whereClause = "CAST(start_date AS DATE) = CAST(GETDATE() AS DATE)";
} elseif ($period === 'week') {
    $startOfWeek = (new DateTime('monday this week'))->setTime(0, 0);
    $endOfWeek = (clone $startOfWeek)->modify('+7 days');
    $whereClause = "(start_date >= ? AND start_date < ?)";
    $params[] = $startOfWeek->format('Y-m-d H:i:s');
    $params[] = $endOfWeek->format('Y-m-d H:i:s');
} elseif ($period === 'month') {
    $whereClause = "MONTH(start_date) = MONTH(GETDATE()) AND YEAR(start_date) = YEAR(GETDATE())";
} elseif ($period === 'year') {
    $whereClause = "YEAR(start_date) = YEAR(GETDATE())";
}

// ✅ 1. Stats card
$statsQuery = "
    SELECT 
        COUNT(*) AS total,
        SUM(CASE WHEN status = 'Closed' THEN 1 ELSE 0 END) AS closed,
        ROUND(AVG(CASE WHEN closed_date IS NOT NULL THEN DATEDIFF(HOUR, start_date, closed_date) ELSE NULL END), 1) AS avgTime
    FROM Tickets
    WHERE $whereClause
";
$statsResult = sqlsrv_query($conn, $statsQuery, $params);
if (!$statsResult) {
    echo json_encode(["error" => "Eroare la stats query", "details" => sqlsrv_errors()]);
    exit;
}
$stats = sqlsrv_fetch_array($statsResult, SQLSRV_FETCH_ASSOC) ?? ["total" => 0, "closed" => 0, "avgTime" => 0];

// ✅ 2. Daily chart
$dailyChart = [];
$dailyQuery = "
    SELECT 
        FORMAT(start_date, 'yyyy-MM-dd') AS day,
        COUNT(*) AS total
    FROM Tickets
    WHERE $whereClause
    GROUP BY FORMAT(start_date, 'yyyy-MM-dd')
    ORDER BY day ASC
";
$dailyResult = sqlsrv_query($conn, $dailyQuery, $params);
while ($row = sqlsrv_fetch_array($dailyResult, SQLSRV_FETCH_ASSOC)) {
    $dailyChart[] = $row;
}

// ✅ 3. Status chart
$statusChart = [];
$statusQuery = "
    SELECT status, COUNT(*) AS count
    FROM Tickets
    WHERE $whereClause
    GROUP BY status
";
$statusResult = sqlsrv_query($conn, $statusQuery, $params);
while ($row = sqlsrv_fetch_array($statusResult, SQLSRV_FETCH_ASSOC)) {
    $statusChart[] = $row;
}

// ✅ 4. Team chart
$teamChart = [];
$teamQuery = "
    SELECT 
        team_assigned_person,
        SUM(CASE WHEN status != 'Closed' THEN 1 ELSE 0 END) AS openTickets,
        SUM(CASE WHEN status = 'Closed' THEN 1 ELSE 0 END) AS closedTickets
    FROM Tickets
    WHERE $whereClause
    GROUP BY team_assigned_person
    ORDER BY team_assigned_person
";
$teamResult = sqlsrv_query($conn, $teamQuery, $params);
while ($row = sqlsrv_fetch_array($teamResult, SQLSRV_FETCH_ASSOC)) {
    $teamChart[] = $row;
}

// ✅ Output final
echo json_encode([
    "stats" => $stats,
    "dailyChart" => $dailyChart,
    "statusChart" => $statusChart,
    "teamChart" => $teamChart
]);

sqlsrv_close($conn);
