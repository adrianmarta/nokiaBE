<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

include '../db.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(["error" => "Connection failed"]);
    exit;
}

$statuses = [];
$sql = "SELECT DISTINCT status FROM Tickets";
$stmt = sqlsrv_query($conn, $sql);

if ($stmt === false) {
    die(print_r(sqlsrv_errors(), true));
}

while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $statuses[] = $row['status'];
}

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

foreach ($statuses as $status) {
    $where = "t.status = ? AND YEAR(t.start_date) = ?";
    $params = [$status, $currentYear];

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

    // TICKETS query
    $sqlTickets = "
        SELECT t.*
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
        'status' => $status,
        'count' => $count,
        'tickets' => $tickets,
    ];
}

echo json_encode($response, JSON_PRETTY_PRINT);

sqlsrv_free_stmt($stmt);
sqlsrv_close($conn);
?>
