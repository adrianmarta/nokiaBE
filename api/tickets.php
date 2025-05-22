<?php
header("Access-Control-Allow-Origin: *");

$serverName = "localhost\\SQLEXPRESS"; 
$connectionOptions = [
    "Database" => "TicketsDB",     
    "Uid" => "root",       
    "PWD" => "root",        
    "CharacterSet" => "UTF-8"
];

$conn = sqlsrv_connect($serverName, $connectionOptions);

if (!$conn) {
    http_response_code(500);
    echo json_encode(["error" => "Connection failed"]);
    exit;
}

// Preluăm filtrele din query string
$filters = [
  'team_assigned_person' => $_GET['team_assigned_person'] ?? '',
  'team_created_by' => $_GET['team_created_by'] ?? '',
  'priority' => $_GET['priority'] ?? '',
  'project' => $_GET['project'] ?? '',
  'status' => $_GET['status'] ?? '',
  'sla' => $_GET['sla'] ?? '',
  'startDate' => $_GET['startDate'] ?? '',
  'endDate' => $_GET['endDate'] ?? '',
];

$sql = "
SELECT
    ts.id,
    ts.incident_title,
    ts.start_date,
    ts.closed_date,
    ts.status,
    ts.response_time,
    ts.last_modified_date,
    ts.comment,
    ts.project,
    ts.assigned_person,
    ts.team_assigned_person,
    ts.created_by,
    ts.team_created_by,
    ts.resolution,
    ts.description,
    p.priority,
    s.duration_hours
FROM
    Tickets ts
JOIN
    priority p ON ts.priority_id = p.id
JOIN
    sla s ON p.id = s.priority_id
";

$params = [];

if ($filters['team_assigned_person']) {
    $sql .= " AND ts.team_assigned_person = ?";
    $params[] = $filters['team_assigned_person'];
}
if ($filters['team_created_by']) {
    $sql .= " AND ts.team_created_by = ?";
    $params[] = $filters['team_created_by'];
}
if ($filters['priority']) {
    $sql .= " AND p.priority = ?";
    $params[] = $filters['priority'];
}
if ($filters['project']) {
    $sql .= " AND ts.project = ?";
    $params[] = $filters['project'];
}
if ($filters['status']) {
    $sql .= " AND ts.status = ?";
    $params[] = $filters['status'];
}
if ($filters['sla']) {
    $sql .= " AND s.duration_hours = ?";
    $params[] = str_replace("h", "", $filters['sla']); // ex: "8h" → 8
}
if ($filters['startDate']) {
    $sql .= " AND ts.created_date >= ?";
    $params[] = $filters['startDate'];
}
if ($filters['endDate']) {
    $sql .= " AND ts.created_date <= ?";
    $params[] = $filters['endDate'];
}

$stmt = sqlsrv_query($conn, $sql, $params);
$results = [];

if ($stmt) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $results[] = $row;
    }
    echo json_encode($results);
} else {
    echo json_encode(["error" => sqlsrv_errors()]);
}

sqlsrv_close($conn);
