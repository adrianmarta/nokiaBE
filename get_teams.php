<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

include 'db.php';
require_once 'auth.php';

// Allow both super-admins (3) and admins (2) to fetch teams
$user = authenticate([2, 3]);
$id_user = $user['id_user'];
$id_rol  = $user['id_rol'];

try {
    $params = [];
    $sql    = "";

    if ($id_rol === 3) {
        // Super-admin: return all teams (optionally filtered by project_id if provided)
        $whereClauses = [];
        if (!empty($_GET['project_id'])) {
            $whereClauses[]    = 't.id_project = ?';
            $params[]          = (int) $_GET['project_id'];
        }

        if (count($whereClauses) > 0) {
            $where = "WHERE " . implode(" AND ", $whereClauses);
        } else {
            $where = "";
        }

        $sql = "
            SELECT
                t.id_team,
                t.name
            FROM
                Team AS t
            {$where}
            ORDER BY
                t.name
        ";
    } else {
       $sql = "
            SELECT
                t.id_team,
                t.name
            FROM
                Team AS t
                INNER JOIN Project AS p
                    ON t.id_project = p.id_project
            WHERE
                p.id_user = ?
            ORDER BY
                t.name
        ";
        $params = [$id_user];
    }

    $stmt = sqlsrv_query($conn, $sql, $params);
    if (!$stmt) {
        throw new Exception(sqlsrv_errors()[0]['message'] ?? 'Query error', 500);
    }

    $rows = [];
    while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $rows[] = $r;
    }

    echo json_encode([
        "success" => true,
        "rows"    => $rows
    ]);

} catch (Throwable $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "success" => false,
        "error"   => $e->getMessage()
    ]);
}
