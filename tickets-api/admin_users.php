<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

require 'jwt_utils.php'; // Include funcțiile JWT (le-am discutat înainte)

$method = $_SERVER['REQUEST_METHOD'];
$headers = getallheaders();

// Verificăm JWT
if (!isset($headers['Authorization'])) {
    http_response_code(401);
    echo json_encode(["error" => "Missing Authorization Header"]);
    exit;
}

$jwt = str_replace('Bearer ', '', $headers['Authorization']);
$payload = verify_jwt($jwt);

if (!$payload) {
    http_response_code(401);
    echo json_encode(["error" => "Invalid token"]);
    exit;
}

// Verificăm rolul
if ($payload['role'] !== 'superuser') {
    http_response_code(403);
    echo json_encode(["error" => "Access denied"]);
    exit;
}

// Conectare la baza de date
$serverName = "DESKTOP-6LMVUCH";
$connectionOptions = [
    "Database" => "TicketsDB",
    "TrustServerCertificate" => true,
    "Encrypt" => false
];
$conn = sqlsrv_connect($serverName, $connectionOptions);

if (!$conn) {
    echo json_encode(["error" => "Database connection failed"]);
    exit;
}

// Operăm GET / POST / DELETE
switch ($method) {
    case 'GET':
        $query = "SELECT id, username, role FROM users ORDER BY id ASC";
        $stmt = sqlsrv_query($conn, $query);
        $users = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $users[] = $row;
        }
        echo json_encode($users);
        break;

    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        $username = $data['username'] ?? '';
        $password = $data['password'] ?? '';
        $role = $data['role'] ?? 'user';

        if (!$username || !$password) {
            http_response_code(400);
            echo json_encode(["error" => "Username and password required"]);
            exit;
        }

        $passwordHash = password_hash($password, PASSWORD_BCRYPT);
        $insert = "INSERT INTO users (username, password, role) VALUES (?, ?, ?)";
        $stmt = sqlsrv_query($conn, $insert, [$username, $passwordHash, $role]);

        if ($stmt) {
            echo json_encode(["success" => "User created"]);
        } else {
            echo json_encode(["error" => "Failed to create user"]);
        }
        break;

    case 'DELETE':
        parse_str(file_get_contents('php://input'), $data);
        $userId = $data['id'] ?? null;

        if (!$userId) {
            http_response_code(400);
            echo json_encode(["error" => "User ID required"]);
            exit;
        }

        $delete = "DELETE FROM users WHERE id = ?";
        $stmt = sqlsrv_query($conn, $delete, [$userId]);

        if ($stmt) {
            echo json_encode(["success" => "User deleted"]);
        } else {
            echo json_encode(["error" => "Failed to delete user"]);
        }
        break;
}

sqlsrv_close($conn);
?>
