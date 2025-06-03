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
$slaStatusFilter = $_GET['slaStatus'] ?? null;
$startDate = $_GET['startDate'] ?? null;
$endDate = $_GET['endDate'] ?? null;

if (!$startDate && !$endDate) {
    $startDate = date('Y-m-d', strtotime('-1 year'));
    $endDate = date('Y-m-d');
} elseif ($startDate && !$endDate) {
    $endDate = date('Y-m-d');
} elseif (!$startDate && $endDate) {
    $startDate = date('Y-m-d', strtotime($endDate . ' -1 year'));
}

$slaStatuses = $slaStatusFilter ? [$slaStatusFilter] : ['Met', 'Exceeded', 'In Progress'];

$response = [];

foreach ($slaStatuses as $slaStatus) {
    $where = "WHERE 
        CASE
            WHEN t.closed_date IS NOT NULL AND DATEDIFF(HOUR, t.start_date, t.closed_date) <= sla.duration_hours THEN 'Met'
            WHEN t.closed_date IS NULL AND DATEDIFF(HOUR, t.start_date, GETDATE()) > sla.duration_hours THEN 'Exceeded'
            WHEN t.closed_date IS NULL AND DATEDIFF(HOUR, t.start_date, GETDATE()) <= sla.duration_hours THEN 'In Progress'
            ELSE 'Other'
        END = ?";
    $params = [$slaStatus];

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
        $where .= " AND tp.provider = ?";
        $params[] = $project;
    }

    if ($status) {
        $where .= " AND t.status = ?";
        $params[] = $status;
    }

    if ($startDate && $endDate) {
        $where .= " AND t.start_date BETWEEN ? AND ?";
        $params[] = $startDate;
        $params[] = $endDate . " 23:59:59";
    }

    $sqlTickets = "
        SELECT t.*, tcb.name as created_by_name, tap.name as assigned_person_name, tp.provider as project_name, sla.duration_hours
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

    sqlsrv_free_stmt($stmtTickets);

    $response[] = [
        'status' => $slaStatus,
        'count' => count($tickets),
        'tickets' => $tickets,
    ];
}

sqlsrv_close($conn);

echo json_encode($response, JSON_PRETTY_PRINT);
