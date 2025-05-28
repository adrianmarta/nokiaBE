<?php
require_once '/../db.php';

function authenticate($requiredRoles = null) {
    // Grab and validate the Bearer token
    $headers    = getallheaders();
    $authHeader = $headers['Authorization'] ?? '';
    if (! preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        http_response_code(401);
        echo json_encode(["error" => "Missing or invalid token"]);
        exit;
    }
    $token = $matches[1];

    // Lookup token → user + role
    $sql  = "
      SELECT t.id_user, u.id_rol 
      FROM Tokens t
      JOIN Utilizator u ON t.id_user = u.id_user
      WHERE t.token = ?
    ";
    $stmt = sqlsrv_query($GLOBALS['conn'], $sql, [$token]);
    if (! $stmt || ! sqlsrv_has_rows($stmt)) {
        http_response_code(403);
        echo json_encode(["error" => "Invalid token"]);
        exit;
    }
    $user = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

    // If caller asked for specific roles, enforce them
    if ($requiredRoles !== null) {
        // normalize to array
        $allowed = is_array($requiredRoles)
            ? $requiredRoles
            : [$requiredRoles];

        if (! in_array((int)$user['id_rol'], $allowed, true)) {
            http_response_code(403);
            echo json_encode(["error" => "Insufficient permissions"]);
            exit;
        }
    }

    return $user;
}
