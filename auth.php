<?php
require_once 'db.php';

function authenticate($requiredRoleId = null) {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? '';

    if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        http_response_code(401);
        echo json_encode(["error" => "Missing or invalid token"]);
        exit;
    }

    $token = $matches[1];
    $sql = "SELECT t.id_user, u.id_rol FROM Tokens t JOIN Utilizator u ON t.id_user = u.id_user WHERE t.token = ?";
    $stmt = sqlsrv_query($GLOBALS['conn'], $sql, [$token]);

    if (!$stmt || !sqlsrv_has_rows($stmt)) {
        http_response_code(403);
        echo json_encode(["error" => "Invalid token"]);
        exit;
    }

    $user = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

    if ($requiredRoleId !== null && $user['id_rol'] != $requiredRoleId) {
        http_response_code(403);
        echo json_encode(["error" => "Insufficient permissions"]);
        exit;
    }

    return $user;
}
