<?php
$serverName = "DESKTOP-6LMVUCH"; 
$connectionOptions = [
    "Database" => "TicketsDB",
    "TrustServerCertificate" => true,
    "Encrypt" => false
];

$conn = sqlsrv_connect($serverName, $connectionOptions);

if (!$conn) {
    die(print_r(sqlsrv_errors(), true));
}

$users = [
    ["username" => "user", "password" => password_hash("user", PASSWORD_BCRYPT)],
    ["username" => "admin", "password" => password_hash("admin", PASSWORD_BCRYPT)],
    ["username" => "superuser", "password" => password_hash("super", PASSWORD_BCRYPT)],
];

foreach ($users as $u) {
    $sql = "UPDATE Users SET password = ? WHERE username = ?";
    $stmt = sqlsrv_query($conn, $sql, [$u['password'], $u['username']]);
    
    if ($stmt) {
        echo "Parola resetată pentru utilizatorul {$u['username']}<br>";
    } else {
        echo "Eroare la resetare pentru {$u['username']}<br>";
    }
}

sqlsrv_close($conn);
?>
