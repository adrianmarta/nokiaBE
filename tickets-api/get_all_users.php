<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

require_once "db.php";
ini_set('display_errors', 1);
error_reporting(E_ALL);

$sql = "
    SELECT u.id_user, u.mail, u.nume, u.id_rol, t.name AS team_name
    FROM Utilizator u
    LEFT JOIN Team t ON u.id_team = t.id_team
    WHERE u.id_rol NOT IN (2, 3)
";

$stmt = sqlsrv_query($conn, $sql);

$data = [];
if ($stmt) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $rol = match ($row["id_rol"]) {
            1 => "user",
            2 => "admin",
            3 => "superuser",
            default => "necunoscut"
        };
        $data[] = [
            "id_user" => $row["id_user"],
            "mail" => $row["mail"],
            "nume" => $row["nume"],
            "rol" => $rol,
            "team_name" => $row["team_name"] ?? null
        ];
    }
    echo json_encode($data);
} else {
    echo json_encode(["error" => "Query failed", "details" => sqlsrv_errors()]);
}
