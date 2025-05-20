<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

include 'db.php';


if (!$conn) {
    echo json_encode(["error" => "Conexiune eșuată"]);
    exit;
}

// Filtrăm ticketele doar dacă a fost dat status în URL
$status = $_GET['status'] ?? null;

$whereClause = '';
if ($status) {
    $whereClause = "WHERE status = ?";
}

$sql = "
SELECT 
    t.id, t.incident_title, t.status, t.project, t.description, t.comment, t.assigned_person, t.team_assigned_person, t.created_by, t.team_created_by, t.response_time, s.duration_hours,
     CASE 
        WHEN t.response_time IS NULL THEN '-' 
        WHEN s.duration_hours - t.response_time < 0 THEN 'OUT'
        ELSE 'IN'
    END AS IN_OUT_SLA,
    FORMAT(t.start_date, 'yyyy-MM-dd HH:mm:ss') as start_date,
    FORMAT(t.last_modified_date, 'yyyy-MM-dd HH:mm:ss') as last_modified_date,
    FORMAT(t.closed_date, 'yyyy-MM-dd HH:mm:ss') as closed_date,
    p.priority AS priority_name
FROM Tickets t
JOIN Priority p ON t.priority_id = p.id
JOIN SLA s ON s.id = t.priority_id
$whereClause
ORDER BY t.start_date DESC
";

$params = [];
if ($status) {
    $params[] = $status;
}

$stmt = sqlsrv_query($conn, $sql, $params);

$tickets = [];
if ($stmt) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $tickets[] = $row;
    }
}

echo json_encode($tickets);

sqlsrv_close($conn);
?>
