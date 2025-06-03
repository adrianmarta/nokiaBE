<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

include '../db.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(["error" => "Connection failed"]);
    exit;
}

$sql = "
    SELECT name FROM Team
";

$stmt = sqlsrv_query($conn, $sql);
if($stmt == false) {
    http_response_code(500);
    echo json_encode(["error" => sqlsrv_errors()]);
    exit;
}

$team_names = [];

while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $team_names[] = $row['name'];
}

$response = array_values($team_names);
echo json_encode($response, JSON_PRETTY_PRINT);

sqlsrv_free_stmt($stmt);
sqlsrv_close($conn);
?>