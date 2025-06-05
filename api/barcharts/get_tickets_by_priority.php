<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

include '../db.php'; // Ensure this path is correct for your database connection

if (!$conn) {
    http_response_code(500);
    echo json_encode(["error" => "Connection failed"]);
    exit;
}

$priorities = [];
// This SELECT is fine as it fetches the list of available priorities for the loop
$sql = "SELECT priority FROM Priority ORDER BY id";
$stmt = sqlsrv_query($conn, $sql);

if ($stmt === false) {
    die(print_r(sqlsrv_errors(), true));
}

while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $priorities[] = $row['priority'];
}

// $statuses variable is declared but not used in this particular file, which is fine.

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

// defaults for dates
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
    $where = "p.priority = ? AND YEAR(t.start_date) = ?";
    $params = [$filterValue, $currentYear];

    if ($teamCreatedByName) {
        $where .= " AND tcb.name = ?";
        $params[] = $teamCreatedByName;
    }

    if ($teamAssignedPersonName) {
        $where .= " AND tap.name = ?";
        $params[] = $teamAssignedPersonName;
    }

    // Only add priority filter if it's explicitly passed AND it's different from the current loop's $filterValue
    // Otherwise, it's redundant or could conflict.
    if ($priority && $priority !== $filterValue) { // Added condition to prevent redundant filter
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

    // COUNT query - No changes needed here as it only counts
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

    // --- CRITICAL CHANGE START: MODIFYING THE TICKETS QUERY TO INCLUDE NAMES ---
    $sqlTickets = "
        SELECT
            t.*,                                   -- Select all columns from Tickets table
            p.priority AS priority_name,           -- Select 'priority' from Priority table, alias as 'priority_name'
            tp.provider AS project_name,           -- Select 'provider' from Project table, alias as 'project_name'
            tcb.name AS team_created_by_name,      -- Select 'name' from Team table (for created by), alias as 'team_created_by_name'
            tap.name AS team_assigned_person_name, -- Select 'name' from Team table (for assigned person), alias as 'team_assigned_person_name'
            sla.duration_hours AS sla_duration     -- (Optional: Include SLA duration if your React tooltip needs it for display/logic)
        FROM
            Tickets t
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
        // Add null coalescing to ensure these fields always have a string value
        $row['priority_name'] = $row['priority_name'] ?? 'N/A'; // Changed default to 'N/A' as seen in your image
        $row['project_name'] = $row['project_name'] ?? 'N/A';
        $row['team_created_by_name'] = $row['team_created_by_name'] ?? 'N/A';
        $row['team_assigned_person_name'] = $row['team_assigned_person_name'] ?? 'N/A';

        $tickets[] = $row;
    }
    // --- CRITICAL CHANGE END ---

    $response[] = [
        'priority' => $filterValue, // This 'priority' is the category name for the chart, e.g., "Critical"
        'count' => $count,
        'tickets' => $tickets, // This array now contains ticket objects with 'priority_name', 'project_name', 'team_created_by_name', 'team_assigned_person_name'
    ];
}

echo json_encode($response, JSON_PRETTY_PRINT);

// No need to free $stmt and close $conn here if you iterate, as they are used in the loop
// and will be closed automatically when script finishes or if db.php handles persistent connection.
// If your db.php requires explicit closing at the end of the script, place it outside the loop.

?>