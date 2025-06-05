<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

include '../db.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(["error" => "Connection failed"]);
    exit;
}

$teamCreatedByName = $_GET['team_created_by_name'] ?? null;
$teamAssignedPersonName = $_GET['team_assigned_person_name'] ?? null;
$priority = $_GET['priority'] ?? null;
$project = $_GET['project'] ?? null;
$status = $_GET['status'] ?? null;
$slaStatus = $_GET['slaStatus'] ?? null;
$startDate = $_GET['startDate'] ?? null;
$endDate = $_GET['endDate'] ?? null;

// Handle default dates
if (!$startDate && !$endDate) {
    $startDate = date('Y-m-d', strtotime('-1 year'));
    $endDate = date('Y-m-d');
} elseif ($startDate && !$endDate) {
    $endDate = date('Y-m-d');
} elseif (!$startDate && $endDate) {
    $startDate = date('Y-m-d', strtotime($endDate . ' -1 year'));
}

// 1. Get all DISTINCT SLA durations
$slaDurations = [];
$sql = "SELECT DISTINCT duration_hours FROM SLA ORDER BY duration_hours";
$stmt = sqlsrv_query($conn, $sql);
if ($stmt === false) {
    die(print_r(sqlsrv_errors(), true));
}
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $slaDurations[] = $row['duration_hours'];
}
sqlsrv_free_stmt($stmt); // Free the statement after use

// 2. Build data grouped by SLA duration
$response = [];

foreach ($slaDurations as $duration) {
    $whereConditions = ["sla.duration_hours = ?"];
    $params = [$duration];

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

    if ($status) {
        $whereConditions[] = "t.status = ?";
        $params[] = $status;
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

    if ($startDate && $endDate) {
        // Assuming 'assigned_date' is the relevant date column for filtering in this context
        $whereConditions[] = "t.assigned_date BETWEEN ? AND ?";
        $params[] = $startDate;
        $params[] = $endDate . " 23:59:59";
    }

    $where = "WHERE " . implode(" AND ", $whereConditions);

    // Get tickets with added join fields for names and SLA calculation
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
        INNER JOIN Priority p ON t.priority_id = p.id
        INNER JOIN SLA sla ON p.id = sla.priority_id
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
    sqlsrv_free_stmt($stmtTickets); // Free the statement after use

    $response[] = [
        'status' => $duration . 'h', // Keeping this as 'status' based on your original code
        'value' => count($tickets),
        'tickets' => $tickets,
    ];
}

echo json_encode($response, JSON_PRETTY_PRINT);

sqlsrv_close($conn);
?>