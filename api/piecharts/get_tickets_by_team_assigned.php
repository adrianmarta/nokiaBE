<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

include '../db.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(["error" => "Connection failed"]);
    exit;
}

// 1. Get all teams with their IDs and Names
$teamsData = [];
$sql = "SELECT id_team, name FROM Team ORDER BY name"; // Changed to order by name for cleaner output
$stmt = sqlsrv_query($conn, $sql);

if ($stmt === false) {
    die(print_r(sqlsrv_errors(), true));
}

while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $teamsData[$row['id_team']] = $row['name']; // Store as ID => Name for easy lookup
}
sqlsrv_free_stmt($stmt);

// Capture filter parameters
$teamCreatedByName = $_GET['team_created_by_name'] ?? null;
$teamAssignedPersonName = $_GET['team_assigned_person_name'] ?? null;
$priority = $_GET['priority'] ?? null;
$project = $_GET['project'] ?? null;
$filterStatus = $_GET['status'] ?? null; // Renamed to avoid conflict with $status in query conditions
$sla = $_GET['sla'] ?? null;
if ($sla) {
    $sla = rtrim($sla, "h");
}
$slaStatus = $_GET['slaStatus'] ?? null;
$startDate = $_GET['startDate'] ?? null;
$endDate = $_GET['endDate'] ?? null;

// Handle default dates (moved this block up for clarity)
if (!$startDate && !$endDate) {
    $startDate = date('Y-m-d', strtotime('-1 year'));
    $endDate = date('Y-m-d');
} elseif ($startDate && !$endDate) {
    $endDate = date('Y-m-d');
} elseif (!$startDate && $endDate) {
    $startDate = date('Y-m-d', strtotime($endDate . ' -1 year'));
}

// Removed the redundant $statuses fetching block as it's not used.

$response = [];

foreach ($teamsData as $teamId => $teamName) {
    $whereConditions = ["t.team_assigned_person = ?"];
    $params = [$teamId];

    // Removed the YEAR(GETDATE()) - 1 condition as it's redundant with startDate/endDate defaults
    // and would likely never be met.

    if ($teamCreatedByName) {
        $whereConditions[] = "tcb.name = ?";
        $params[] = $teamCreatedByName;
    }

    // This filter is for team_assigned_person name, which is the current loop variable
    // If teamAssignedPersonName GET parameter is set, it will filter the OUTER loop.
    // If you intend to filter *within* the current team in the loop, this logic needs adjustment.
    // Assuming this parameter is meant to filter which teams are even considered in the loop,
    // it should be applied *before* the foreach loop or lead to an `continue` statement.
    // For now, I'm keeping it as a condition for the individual team's query.
    if ($teamAssignedPersonName) {
        $whereConditions[] = "tap.name = ?";
        $params[] = $teamAssignedPersonName;
    }

    if ($priority) {
        $whereConditions[] = "p.priority = ?";
        $params[] = $priority;
    }

    if ($project) {
        $whereConditions[] = "tp.provider = ?";
        $params[] = $project;
    }

    if ($filterStatus) { // Use the renamed variable
        $whereConditions[] = "t.status = ?";
        $params[] = $filterStatus;
    }

    if ($sla) {
        $whereConditions[] = "sla.duration_hours = ?";
        $params[] = $sla;
    }

    if ($slaStatus) {
        $whereConditions[] = " (
            CASE
                WHEN t.closed_date IS NOT NULL AND DATEDIFF(HOUR, t.start_date, t.closed_date) <= sla.duration_hours THEN 'Met'
                WHEN t.closed_date IS NULL AND DATEDIFF(HOUR, t.start_date, GETDATE()) > sla.duration_hours THEN 'Exceeded'
                WHEN t.closed_date IS NULL AND DATEDIFF(HOUR, t.start_date, GETDATE()) <= sla.duration_hours THEN 'In Progress'
                ELSE 'Other'
            END
        ) = ?";
        $params[] = $slaStatus;
    }

    // Always apply date range filtering based on assigned_date
    if ($startDate && $endDate) {
        $whereConditions[] = "t.assigned_date BETWEEN ? AND ?";
        $params[] = $startDate;
        $params[] = $endDate . " 23:59:59";
    }

    $where = "WHERE " . implode(" AND ", $whereConditions);

    // COUNT query
    $sqlCount = "
        SELECT COUNT(*) AS cnt
        FROM Tickets t
        LEFT JOIN Priority p ON t.priority_id = p.id
        LEFT JOIN SLA sla ON t.priority_id = sla.priority_id
        LEFT JOIN Team tcb ON t.team_created_by = tcb.id_team
        LEFT JOIN Team tap ON t.team_assigned_person = tap.id_team
        LEFT JOIN Project tp ON t.project = tp.id_project
        $where
    ";
    $stmtCount = sqlsrv_query($conn, $sqlCount, $params);
    if ($stmtCount === false) {
        die(print_r(sqlsrv_errors(), true));
    }

    $count = 0;
    if ($row = sqlsrv_fetch_array($stmtCount, SQLSRV_FETCH_ASSOC)) {
        $count = $row['cnt'];
    }
    sqlsrv_free_stmt($stmtCount);

    // TICKETS query - Added all necessary display fields
    $sqlTickets = "
        SELECT
            t.*,
            p.priority AS priority_name,              -- Added for tooltip
            tcb.name AS team_created_by_name,         -- Added for tooltip
            tap.name AS team_assigned_person_name,    -- Added for tooltip
            tp.provider AS project_name,              -- Added for tooltip
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
        LEFT JOIN SLA sla ON t.priority_id = sla.priority_id
        LEFT JOIN Team tcb ON t.team_created_by = tcb.id_team
        LEFT JOIN Team tap ON t.team_assigned_person = tap.id_team
        LEFT JOIN Project tp ON t.project = tp.id_project
        $where
    ";
    $stmtTickets = sqlsrv_query($conn, $sqlTickets, $params);
    if ($stmtTickets === false) {
        die(print_r(sqlsrv_errors(), true));
    }

    $tickets = [];
    while ($row = sqlsrv_fetch_array($stmtTickets, SQLSRV_FETCH_ASSOC)) {
        foreach ($row as $key => $value) {
            if ($value instanceof DateTime) {
                $row[$key] = $value->format('Y-m-d H:i:s');
            }
        }
        $tickets[] = $row;
    }
    sqlsrv_free_stmt($stmtTickets);

    $response[] = [
        'team' => $teamName, // Directly use the team name from the initial fetch
        'count' => $count,
        'tickets' => $tickets,
    ];
}

echo json_encode($response, JSON_PRETTY_PRINT);

sqlsrv_close($conn);
?>