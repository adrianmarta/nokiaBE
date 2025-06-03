<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

include '../db.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(["error" => "Connection failed"]);
    exit;
}

$response = [];

$sqlAssignedTeams = "
    SELECT DISTINCT tm.name
    FROM Tickets t
    INNER JOIN Team tm ON t.team_assigned_person = tm.id_team
    ORDER BY tm.name
";
$stmt = sqlsrv_query($conn, $sqlAssignedTeams);
$assignedTeams = [];
if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $assignedTeams[] = $row['name'];
    }
} else {
    die(print_r(sqlsrv_errors(), true));
}
$response['assignedTeams'] = $assignedTeams;

$sqlCreatedTeams = "
    SELECT DISTINCT tcb.name
    FROM Tickets t
    INNER JOIN Team tcb ON t.team_created_by = tcb.id_team
    ORDER BY tcb.name
";
$stmt = sqlsrv_query($conn, $sqlCreatedTeams);
$createdTeams = [];
if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $createdTeams[] = $row['name'];
    }
} else {
    die(print_r(sqlsrv_errors(), true));
}
$response['createdTeams'] = $createdTeams;

$sqlPriorities = "
    SELECT DISTINCT p.priority
    FROM Priority p
    ORDER BY p.priority
";
$stmt = sqlsrv_query($conn, $sqlPriorities);
$priorities = [];
if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $priorities[] = $row['priority'];
    }
} else {
    die(print_r(sqlsrv_errors(), true));
}
$response['priorities'] = $priorities;

$sqlProjects = "
    SELECT DISTINCT tp.provider
    FROM Project tp
    ORDER BY tp.provider
";
$stmt = sqlsrv_query($conn, $sqlProjects);
$projects = [];
if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $projects[] = $row['provider'];
    }
} else {
    die(print_r(sqlsrv_errors(), true));
}
$response['projects'] = $projects;

$sqlStatuses = "
    SELECT DISTINCT status
    FROM Tickets
    ORDER BY status
";
$stmt = sqlsrv_query($conn, $sqlStatuses);
$statuses = [];
if ($stmt !== false) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $statuses[] = $row['status'];
    }
} else {
    die(print_r(sqlsrv_errors(), true));
}
$response['statuses'] = $statuses;

echo json_encode($response, JSON_PRETTY_PRINT);

sqlsrv_free_stmt($stmt);
sqlsrv_close($conn);
?>
