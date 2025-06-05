<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

include '../db.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(["error" => "Connection failed"]);
    exit;
}

$teams = [];
// Fetch both ID and Name for the Team table
$sql = "SELECT id_team, name FROM Team ORDER BY id_team";
$stmt = sqlsrv_query($conn, $sql);

if ($stmt === false) {
    die(print_r(sqlsrv_errors(), true));
}

while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $teams[$row['id_team']] = $row['name']; // Store ID => Name mapping
}

$statuses = [];
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

if ($status) {
    $sql = "SELECT DISTINCT status FROM Tickets";
    $stmt = sqlsrv_query($conn, $sql);
    if ($stmt === false) {
        die(print_r(sqlsrv_errors(), true));
    }
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $statuses[] = $row['status'];
    }
}

$response = [];

foreach ($teams as $filterTeamId => $filterTeamName) { // Use both ID for query, Name for response
    $where = "WHERE t.team_assigned_person = ?";
    $params = [$filterTeamId]; // Filter by ID

    // This condition might make sense if the chart specifically shows last year's data by default
    if (!$startDate && !$endDate) {
        $where .= " AND YEAR(t.start_date) = YEAR(GETDATE()) - 1";
    }

    // This condition ensures only 'Met' SLA tickets are counted/listed by default for this API.
    // If you need other SLA statuses, this would need modification or removal.
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
        $where .= " AND tp.provider = ?"; // Assuming 'provider' is the project name column
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
        LEFT JOIN Project tp ON t.project = tp.id_project
    ";

    $sqlCount = "
        SELECT COUNT(*) AS cnt
        $joins
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

    // --- MODIFIED SQL TICKETS SELECT ---
    $sqlTickets = "
        SELECT
            t.*,
            tm.name AS team_assigned_person_name, -- Renamed for consistency
            p.priority AS priority_name,
            tp.provider AS project_name,
            tcb.name AS team_created_by_name
        $joins
        $where
    ";
    // --- END MODIFIED SQL TICKETS SELECT ---

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
        'team_assigned_person' => $filterTeamName, // Use the fetched team name
        'count' => $count,
        'tickets' => $tickets,
    ];
}

echo json_encode($response, JSON_PRETTY_PRINT);
?>