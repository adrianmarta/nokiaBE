<?php
header("Access-Control-Allow-Origin: *");

include 'db.php';

if (!$conn) {
    echo json_encode(["error" => "Connection failed", "details" => sqlsrv_errors()]);
    exit;
}

function getDistinct($conn, $column, $table = "Tickets") {
    $query = "SELECT DISTINCT $column FROM $table WHERE $column IS NOT NULL";
    $stmt = sqlsrv_query($conn, $query);
    $results = [];
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $results[] = $row[$column];
        }
    } else {
        return ["SQL error" => sqlsrv_errors()];
    }
    return $results;
}


echo json_encode([
    "team_assigned_person" => getDistinct($conn, "team_assigned_person"),
    "team_created_by" => getDistinct($conn, "team_created_by"),
    "priorities" => getDistinct($conn, "priority", "priority"),
    "projects" => getDistinct($conn, "project"),
    "statuses" => getDistinct($conn, "status"),
    "slaTypes" => getDistinct($conn, "duration_hours", "sla"),
 
]);

sqlsrv_close($conn);
