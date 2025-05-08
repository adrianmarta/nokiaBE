<?php
$serverName = "DESKTOP-V4GM3GQ\SQLEXPRESS08"; // Change if your server name is different
$connectionOptions = [
    "Database" => "nokia",
    "Uid" => "my_user",              // Replace with your username
    "PWD" => "1q2w",     // Replace with your password
    "TrustServerCertificate" => true   // Optional for local dev, avoids cert issues
];

$conn = sqlsrv_connect($serverName, $connectionOptions);

if ($conn) {
    echo "✅ Connected successfully to SQL Server!";

    // Optional: Run a test query
    $sql = "SELECT name FROM sys.databases";
    $stmt = sqlsrv_query($conn, $sql);

    echo "<ul>";
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        echo "<li>" . $row['name'] . "</li>";
    }
    echo "</ul>";

    sqlsrv_close($conn);
} else {
    echo "❌ Connection failed:<br>";
    print_r(sqlsrv_errors());
}
?>
