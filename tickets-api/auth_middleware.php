<?php
require_once __DIR__ . '/vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// Config
$secretKey = 'secretulTauFoarteSecret'; // trebuie să fie IDENTIC cu cel din login.php

// Preia tokenul din Header Authorization: Bearer {token}
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';

if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    http_response_code(401);
    echo json_encode(["error" => "Token lipsă."]);
    exit;
}

$token = $matches[1];

try {
    $decoded = JWT::decode($token, new Key($secretKey, 'HS256'));
    $userData = (array) $decoded->data;
    // Acum $userData['username'], $userData['role'] sunt disponibile
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["error" => "Token invalid sau expirat."]);
    exit;
}
?>
