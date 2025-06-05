<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

include '../db.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(["error" => "Connection failed"]);
    exit;
}

$priorities = [];
$sql = "SELECT priority FROM Priority ORDER BY id";
$stmt = sqlsrv_query($conn, $sql);

if ($stmt === false) {
    die(print_r(sqlsrv_errors(), true));
}

while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $priorities[] = $row['priority'];
}

// statuses is declared but not used, so keeping it as is.
$statuses = [];

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

$startDate = isset($_GET['startDate']) ? $_GET['startDate'] : null;
$endDate = isset($_GET['endDate']) ? $_GET['endDate'] : null;

// defaults
if (!$startDate && !$endDate) {
    $startDate = date('Y-m-d', strtotime('-1 year'));
    $endDate = date('Y-m-d');
} elseif ($startDate && !$endDate) {
    $endDate = date('Y-m-d'); // default endDate = today
} elseif (!$startDate && $endDate) {
    // default startDate = one year before endDate
    $startDate = date('Y-m-d', strtotime($endDate . ' -1 year'));
}

$currentYear = date('Y');

$response = [];

foreach ($priorities as $filterValue) {
    // Start building the WHERE clause and parameters
    $whereConditions = ["p.priority = ?"];
    $params = [$filterValue];

    // Always filter by current year based on start_date, as per original logic
    // But be careful if this conflicts with startDate/endDate filter
    // If startDate/endDate are explicitly set, the YEAR filter might be redundant or conflicting
    // For now, keeping it as it was in your original code.
    $whereConditions[] = "YEAR(t.start_date) = ?";
    $params[] = $currentYear;


    if ($teamCreatedByName) {
        $whereConditions[] = "tcb.name = ?";
        $params[] = $teamCreatedByName;
    }

    if ($teamAssignedPersonName) {
        $whereConditions[] = "tap.name = ?";
        $params[] = $teamAssignedPersonName;
    }

    // The current loop is filtering by 'p.priority', so no need to add 'priority' filter here
    // if ($priority) {
    //     $whereConditions[] = "p.priority = ?";
    //     $params[] = $priority;
    // }

    if ($project) {
        $whereConditions[] = "tp.provider = ?";
        $params[] = $project;
    }

    if ($status) {
        $whereConditions[] = "t.status = ?";
        $params[] = $status;
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
    if ($startDate && $endDate) {
        // Correctly apply date range to start_date or closed_date, similar to trend charts
        // Assuming 'start_date' for consistency with YEAR filter, but you might need to adjust based on exact chart logic
        // If 'assigned_date' is a relevant column for this chart, use that.
        // Based on previous code, 'assigned_date' was used, so keeping it.
        $whereConditions[] = "t.assigned_date BETWEEN ? AND ?";
        $params[] = $startDate;
        $params[] = $endDate . " 23:59:59";
    }

    $where = implode(" AND ", $whereConditions);

    // COUNT query
    $sqlCount = "
        SELECT COUNT(*) AS cnt
        FROM Tickets t
        INNER JOIN Priority p ON t.priority_id = p.id
        LEFT JOIN Team tcb ON t.team_created_by = tcb.id_team
        LEFT JOIN Team tap ON t.team_assigned_person = tap.id_team
        LEFT JOIN Project tp ON t.project = tp.id_project
        INNER JOIN SLA sla ON t.priority_id = sla.priority_id
        WHERE $where
    ";
    $stmtCount = sqlsrv_query($conn, $sqlCount, $params);
    if ($stmtCount === false) {
        die(print_r(sqlsrv_errors(), true));
    }

    $count = 0;
    if ($row = sqlsrv_fetch_array($stmtCount, SQLSRV_FETCH_ASSOC)) {
        $count = $row['cnt'];
    }

    // TICKETS query - Added selected fields for tooltips
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
        LEFT JOIN Team tcb ON t.team_created_by = tcb.id_team
        LEFT JOIN Team tap ON t.team_assigned_person = tap.id_team
        LEFT JOIN Project tp ON t.project = tp.id_project
        INNER JOIN SLA sla ON t.priority_id = sla.priority_id
        WHERE $where
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

    $response[] = [
        'priority' => $filterValue,
        'count' => $count,
        'tickets' => $tickets,
    ];
}

echo json_encode($response, JSON_PRETTY_PRINT);

sqlsrv_free_stmt($stmt);
if (isset($stmtCount)) {
    sqlsrv_free_stmt($stmtCount);
}
if (isset($stmtTickets)) {
    sqlsrv_free_stmt($stmtTickets);
}
sqlsrv_close($conn);

?>