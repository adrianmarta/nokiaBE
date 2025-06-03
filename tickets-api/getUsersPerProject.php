<?php
include 'db.php';
require_once __DIR__ . '/../auth.php';

header('Content-Type: application/json');

$user = authenticate(); // returnează id_user, id_rol
$currentUserId = $user['id_user'];
$role = $user['id_rol'];

if ((int)$role !== 2 && (int)$role !== 3) { //2 = admin, 3 = superuser
    http_response_code(403);
    echo json_encode(["error" => "Nu ai permisiunea sa accesezi această resursă."]);
    exit;
}

// Obtine proiectul curentului user
$projectQuery = "SELECT id_project FROM Utilizator WHERE id_user = ?";
$projectStmt = sqlsrv_query($conn, $projectQuery, [$currentUserId]);

if (!$projectStmt || !sqlsrv_fetch($projectStmt)) {
    echo json_encode(["error" => "Nu s-a putut obtine proiectul utilizatorului."]);
    exit;
}

$project = sqlsrv_get_field($projectStmt, 0);

// Selectam toti utilizatorii din același proiect
$usersQuery = "SELECT id_user, nume, mail, id_rol FROM Utilizator WHERE id_project = ? AND id_rol = 1";
$usersStmt = sqlsrv_query($conn, $usersQuery, [$project]);

if ($usersStmt === false) {
    echo json_encode(["error" => "Eroare la interogarea utilizatorilor."]);
    exit;
}

$users = [];
while ($row = sqlsrv_fetch_array($usersStmt, SQLSRV_FETCH_ASSOC)) {
    $users[] = $row;
}

echo json_encode([
    "project" => $project,
    "users" => $users
]);

sqlsrv_close($conn);
?>
