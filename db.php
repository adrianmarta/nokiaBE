<?php
$serverName = "DESKTOP-V4GM3GQ\SQLEXPRESS08";

 $connectionOptions = [
    "Database" => "nokia",
    "Uid" => "my_user",              // Replace with your username
    "PWD" => "1q2w",     // Replace with your password
    "TrustServerCertificate" => true  
 ];

$conn = sqlsrv_connect($serverName, $connectionOptions);

if (!$conn) {
    die(json_encode(["error" => "Connection failed", "details" => sqlsrv_errors()]));
}