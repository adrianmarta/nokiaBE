<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

include '../db.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(["error" => "Connection failed"]);
    exit;
}

// Fetch all distinct statuses from the Tickets table
$statuses = [];
$sql = "SELECT DISTINCT status FROM Tickets ORDER BY status";
$stmt = sqlsrv_query($conn, $sql);

if ($stmt === false) {
    die(print_r(sqlsrv_errors(), true));
}

while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $statuses[] = $row['status'];
}
sqlsrv_free_stmt($stmt);

// Capture filter parameters from GET request
$teamCreatedByName = $_GET['team_created_by_name'] ?? null;
$teamAssignedPersonName = $_GET['team_assigned_person_name'] ?? null;
$priority = $_GET['priority'] ?? null;
$project = $_GET['project'] ?? null;
$filterStatusParam = $_GET['status'] ?? null; // Renamed to avoid conflict with $statusLoopValue
$sla = $_GET['sla'] ?? null;
if ($sla) {
    $sla = rtrim($sla, "h");
}
$slaStatus = $_GET['slaStatus'] ?? null;

$startDate = $_GET['startDate'] ?? null;
$endDate = $_GET['endDate'] ?? null;

// Handle default dates (moved up for clarity and correct application)
if (!$startDate && !$endDate) {
    $startDate = date('Y-m-d', strtotime('-1 year'));
    $endDate = date('Y-m-d');
} elseif ($startDate && !$endDate) {
    $endDate = date('Y-m-d');
} elseif (!$startDate && $endDate) {
    $startDate = date('Y-m-d', strtotime($endDate . ' -1 year'));
}

$response = [];

foreach ($statuses as $statusLoopValue) { // This loop processes each distinct status
    $whereConditions = ["t.status = ?"]; // Initial condition based on the current status in the loop
    $params = [$statusLoopValue];

    // Removed: YEAR(t.start_date) = YEAR(GETDATE()) - 1 condition, as it's handled by startDate/endDate.

    if ($teamCreatedByName) {
        $whereConditions[] = "tcb.name = ?";
        $params[] = $teamCreatedByName;
    }

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

    // Removed: redundant if ($status) check as the loop variable $statusLoopValue already handles it.
    // Also, if $filterStatusParam (from $_GET) is set, it would narrow down the list of statuses
    // that this script would fetch initially. Assuming the intent is to iterate all statuses,
    // and if a specific status is filtered via GET, it should affect the overall result, not
    // this loop's iteration.
    // If you want to filter the *initial list* of statuses based on $filterStatusParam:
    if ($filterStatusParam && $filterStatusParam !== $statusLoopValue) {
        continue; // Skip this iteration if the GET status doesn't match the current loop status
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

    // Apply date range filtering
    if ($startDate && $endDate) {
        $whereConditions[] = "t.assigned_date BETWEEN ? AND ?";
        $params[] = $startDate;
        $params[] = $endDate . " 23:59:59";
    }

    $where = "WHERE " . implode(" AND ", $whereConditions);

    // COUNT query: ensure all necessary LEFT JOINs are present for filtering
    $sqlCount = "
        SELECT COUNT(*) AS cnt
        FROM Tickets t
        LEFT JOIN Priority p ON t.priority_id = p.id
        LEFT JOIN Team tcb ON t.team_created_by = tcb.id_team
        LEFT JOIN Team tap ON t.team_assigned_person = tap.id_team
        LEFT JOIN Project tp ON t.project = tp.id_project
        LEFT JOIN SLA sla ON t.priority_id = sla.priority_id
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

    // TICKETS query: include all necessary fields for tooltips/details
    $sqlTickets = "
        SELECT
            t.*,
            p.priority AS priority_name,
            tcb.name AS team_created_by_name,
            tap.name AS team_assigned_person_name,
            tp.provider AS project_name,
            sla.duration_hours,
            DATEDIFF(HOUR, t.start_date, ISNULL(t.closed_date, GETDATE())) AS hours_taken,
            CASE
                WHEN t.closed_date IS NOT NULL AND DATEDIFF(HOUR, t.start_date, t.closed_date) <= sla.duration_hours THEN 'Met'
                WHEN t.closed_date IS NULL AND DATEDIFF(HOUR, t.start_date, GETDATE()) > sla.duration_hours THEN 'Exceeded'
                WHEN t.closed_date IS NULL AND DATEDIFF(HOUR, t.start_date, GETDATE()) <= sla.duration_hours THEN 'In Progress'
                ELSE 'Other'
            END AS sla_status
        FROM Tickets t
        LEFT JOIN Priority p ON t.priority_id = p.id
        LEFT JOIN Team tcb ON t.team_created_by = tcb.id_team
        LEFT JOIN Team tap ON t.team_assigned_person = tap.id_team
        LEFT JOIN Project tp ON t.project = tp.id_project
        LEFT JOIN SLA sla ON t.priority_id = sla.priority_id
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
        'status' => $statusLoopValue,
        'count' => $count,
        'tickets' => $tickets,
    ];
}

echo json_encode($response, JSON_PRETTY_PRINT);

sqlsrv_close($conn);
?>