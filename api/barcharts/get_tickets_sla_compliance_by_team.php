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

$teams = [];
// --- MODIFIED: Fetch both ID and Name for Team table ---
$sql = "SELECT id_team, name FROM Team ORDER BY id_team";
$stmt = sqlsrv_query($conn, $sql);
if ($stmt === false) {
    die(print_r(sqlsrv_errors(), true));
}
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $teams[$row['id_team']] = $row['name']; // Store ID => Name mapping
}
// --- END MODIFIED ---

$response = [];

foreach ($teams as $teamId => $teamName) { // Iterate using both ID and Name
    $where = "
        t.team_assigned_person = ?
        AND YEAR(t.start_date) = YEAR(GETDATE()) - 1
        AND (
            (t.closed_date IS NOT NULL AND DATEDIFF(HOUR, t.start_date, t.closed_date) <= sla.duration_hours) OR
            (t.closed_date IS NULL AND DATEDIFF(HOUR, t.start_date, GETDATE()) > sla.duration_hours) OR
            (t.closed_date IS NULL AND DATEDIFF(HOUR, t.start_date, GETDATE()) <= sla.duration_hours)
        )
    ";
    $params = [$teamId]; // Use ID for filtering

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
        $where .= " AND pr.provider = ?"; // Assuming 'provider' is the project name column
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

    // --- MODIFIED SQLTickets SELECT clause ---
    $sqlTickets = "
        SELECT
            t.*,
            tm.name AS team_assigned_person_name, -- Renamed for consistency with frontend expectation
            p.priority AS priority_name, -- Added priority name
            pr.provider AS project_name, -- Added project name
            tcb.name AS team_created_by_name, -- Added created by team name
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
        INNER JOIN Team tm ON t.team_assigned_person = tm.id_team
        LEFT JOIN Team tcb ON t.team_created_by = tcb.id_team
        LEFT JOIN Team tap ON t.team_assigned_person = tap.id_team
        LEFT JOIN Project pr ON t.project = pr.id_project
        WHERE $where
    ";
    // --- END MODIFIED ---

    $stmtTickets = sqlsrv_query($conn, $sqlTickets, $params);
    if ($stmtTickets === false) {
        die(print_r(sqlsrv_errors(), true));
    }

    $tickets = [];
    $counts = [
        'Met' => 0,
        'Exceeded' => 0,
        'In Progress' => 0,
    ];

    while ($row = sqlsrv_fetch_array($stmtTickets, SQLSRV_FETCH_ASSOC)) {
        foreach ($row as $key => $value) {
            if ($value instanceof DateTime) {
                $row[$key] = $value->format('Y-m-d H:i:s');
            }
        }

        if (isset($counts[$row['sla_status']])) {
            $counts[$row['sla_status']]++;
        }

        $tickets[] = $row;
    }

    $response[] = [
        'name' => $teamName, // Use the fetched team name directly
        'Met' => $counts['Met'],
        'Exceeded' => $counts['Exceeded'],
        'In Progress' => $counts['In Progress'],
        'tickets' => $tickets,
    ];
}

echo json_encode($response, JSON_PRETTY_PRINT);
?>