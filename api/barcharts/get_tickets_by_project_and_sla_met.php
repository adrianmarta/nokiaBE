<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

include '../db.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(["error" => "Connection failed"]);
    exit;
}

$projects = [];
$sql = "SELECT id_project, provider FROM Project ORDER BY id_project"; // Get provider as well to use in the loop
$stmt = sqlsrv_query($conn, $sql);
if ($stmt === false) {
    die(print_r(sqlsrv_errors(), true));
}
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $projects[$row['id_project']] = $row['provider']; // Store id_project => provider mapping
}

$teamCreatedByName = $_GET['team_created_by_name'] ?? null;
$teamAssignedPersonName = $_GET['team_assigned_person_name'] ?? null;
$priority = $_GET['priority'] ?? null;
$project = $_GET['project'] ?? null;
$status = $_GET['status'] ?? null;
$sla = $_GET['sla'] ?? null;
$slaStatus = $_GET['slaStatus'] ?? null;
$startDate = $_GET['startDate'] ?? null;
$endDate = $_GET['endDate'] ?? null;

if ($sla) {
    $sla = rtrim($sla, "h");
}

if (!$startDate && !$endDate) {
    $startDate = date('Y-m-d', strtotime('-1 year'));
    $endDate = date('Y-m-d');
} elseif ($startDate && !$endDate) {
    $endDate = date('Y-m-d');
} elseif (!$startDate && $endDate) {
    $startDate = date('Y-m-d', strtotime($endDate . ' -1 year'));
}

$response = [];

foreach ($projects as $filterProjectId => $filterProjectName) { // Iterate using both ID and Name
    $where = "t.project = ?";
    $params = [$filterProjectId]; // Use ID for filtering

    // The condition 'AND DATEDIFF(HOUR, t.start_date, t.closed_date) <= sla.duration_hours'
    // implicitly filters for 'Met' SLA.
    // If this API should get ALL tickets, including 'Exceeded' or 'In Progress' for that project,
    // you would need to remove or modify this line based on what you want to filter for by default.
    // Given the file name "priority_and_sla_met", keeping this filter seems appropriate.
    $where .= " AND t.closed_date IS NOT NULL
                AND DATEDIFF(HOUR, t.start_date, t.closed_date) <= sla.duration_hours";

    if ($teamCreatedByName) {
        $where .= " AND tcb.name = ?";
        $params[] = $teamCreatedByName;
    }

    if ($teamAssignedPersonName) {
        $where .= " AND tap.name = ?";
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
                    WHEN t.closed_date IS NULL AND DATEDIFF(HOUR, t.start_date, GETDATE()) > sla.duration_hours THEN 'Exceeded'
                    WHEN t.closed_date IS NULL AND DATEDIFF(HOUR, t.start_date, GETDATE()) <= sla.duration_hours THEN 'In Progress'
                    ELSE 'Other'
                END
            ) = ?";
        $params[] = $slaStatus;
    }

    if ($startDate && $endDate) {
        $where .= " AND t.assigned_date BETWEEN ? AND ?";
        $params[] = $startDate;
        $params[] = $endDate . " 23:59:59";
    }

    $joins = "
        FROM Tickets t
        INNER JOIN Priority p ON t.priority_id = p.id
        INNER JOIN SLA sla ON p.id = sla.priority_id
        INNER JOIN Team tm ON t.team_assigned_person = tm.id_team
        LEFT JOIN Team tcb ON t.team_created_by = tcb.id_team
        LEFT JOIN Team tap ON t.team_assigned_person = tap.id_team
        LEFT JOIN Project pr ON t.project = pr.id_project
    ";

    $sqlCount = "
        SELECT COUNT(*) AS cnt
        $joins
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

    $sqlTickets = "
        SELECT
            t.*,
            p.priority AS priority_name,
            pr.provider AS project_name,
            tap.name AS team_assigned_person_name,
            tcb.name AS team_created_by_name
        $joins
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
        'project' => $filterProjectName, // Use the project name from the initial fetch
        'count' => $count,
        'tickets' => $tickets,
    ];
}

echo json_encode($response, JSON_PRETTY_PRINT);
?>