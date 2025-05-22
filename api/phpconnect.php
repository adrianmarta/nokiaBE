<?php
$serverName = "localhost\SQLEXPRESS";
$database="TicketsDB";
$uid = "root";
$pass = "root";

$connection = [
"Database" => $database,
"Uid" => $uid,
"PWD" => $pass
];

$conn = sqlsrv_connect($serverName,$connection);
if(!$conn) {
    die(print_r(sqlsrv_errors(), true));
} else {
    echo 'connection established';
    $tsql = "select * from Tickets";
    $stmt = sqlsrv_query($conn, $tsql);

    if($stmt == false) {
        echo 'Error';
    }

    $results = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $results[] = $row;
    }
    echo json_encode($results);

    sqlsrv_free_stmt($stmt);
    sqlsrv_close($conn);
}
?>