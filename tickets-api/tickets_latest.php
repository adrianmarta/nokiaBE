<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

$serverName = "DESKTOP-6LMVUCH";
$connectionOptions = [
    "Database" => "TicketsDB",
    "TrustServerCertificate" => true,
    "Encrypt" => false
];
$conn = sqlsrv_connect($serverName, $connectionOptions);

if (!$conn) {
    echo json_encode(["error" => "Conexiune eșuată"]);
    exit;
}

$sql = "
SELECT 
    t.id,
    t.incident_title,
    t.status,
    t.project,
    FORMAT(t.start_date, 'yyyy-MM-dd HH:mm:ss') AS start_date,
    p.priority AS priority_name
FROM Tickets t
JOIN Priority p ON t.priority_id = p.id
ORDER BY t.start_date DESC
";
$tickets = [];
$result = sqlsrv_query($conn, $sql);

while ($row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC)) {
    $tickets[] = $row;
}

echo json_encode($tickets);

sqlsrv_close($conn);
?>
