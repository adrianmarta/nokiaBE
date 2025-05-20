<?php
$serverName = "USER\SQLEXPRESS";

$connectionOptions = [
    "Database" => "TicketsDB",
    "Uid" => "root",            // Utilizatorul SQL
    "PWD" => "root"  
];


$conn = sqlsrv_connect($serverName, $connectionOptions);

if (!$conn) {
    die(json_encode(["error" => "Connection failed", "details" => sqlsrv_errors()]));
}
