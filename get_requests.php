<?php
require_once 'db.php';
require_once 'auth.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    echo json_encode(["message" => "Preflight OK"]);
    exit;
}

try {
    // 1) Authenticate: only admins (rol=2) or super-admins (rol=3)
    $user    = authenticate([2, 3]);
    $id_user = $user['id_user'];
    $id_rol  = $user['id_rol'];

    // 2) Read filter parameters from $_GET
    $startDate    = $_GET['start_date']   ?? '';
    $endDate      = $_GET['end_date']     ?? '';
    $filterRole   = $_GET['role']         ?? '';  // 'user' or 'admin' (only for super-admin)
    $filterStatus = $_GET['status']       ?? '';  // 'pending', 'accepted', 'refused'
    $filterTeam   = isset($_GET['team_id'])      ? (int)$_GET['team_id']    : null;
    $filterProject= isset($_GET['project_id'])   ? (int)$_GET['project_id'] : null;

    // 3) Read pagination parameters (default: page=1, per_page=10)
    $page     = isset($_GET['page'])     ? max((int)$_GET['page'], 1)     : 1;
    $per_page = isset($_GET['per_page']) ? max((int)$_GET['per_page'], 1) : 10;
    $offset   = ($page - 1) * $per_page;

    // Containers for dynamic WHERE clauses and params
    $whereClauses = [];
    $params       = [];

    // 4) Build the base SQL (select columns + FROM + necessary JOINs)
    if ($id_rol === 3) {
        // Super-admin: can see all requests
        $baseSelect = "
            SELECT
                ROW_NUMBER() OVER (ORDER BY c.data_cerere DESC) AS row_number,
                c.id_cerere    AS id,
                c.nume         AS fullName,
                c.mail         AS email,
                CASE c.id_rol
                    WHEN 3 THEN 'super_admin'
                    WHEN 2 THEN 'admin'
                    ELSE 'user'
                END              AS rol,
                s.status         AS status,
                t.name           AS team,       -- for approved user requests
                p2.provider      AS project,    -- for approved admin requests
                c.data_cerere    AS requestDate
        ";
        $baseSql = "
            FROM Cereri AS c
            JOIN status   AS s  ON c.id_status = s.id_status
            LEFT JOIN Utilizator AS u ON u.mail = c.mail
            LEFT JOIN Team     AS t  ON u.id_team = t.id_team
            LEFT JOIN Project  AS p2 ON p2.id_user = u.id_user
        ";
    } else {
        // Admin (rol=2): only user-level requests, plus approved only if in one of this admin’s teams
        $baseSelect = "
            SELECT
                ROW_NUMBER() OVER (ORDER BY c.data_cerere DESC) AS row_number,
                c.id_cerere    AS id,
                c.nume         AS fullName,
                c.mail         AS email,
                CASE c.id_rol
                    WHEN 3 THEN 'super_admin'
                    WHEN 2 THEN 'admin'
                    ELSE 'user'
                END              AS rol,
                s.status         AS status,
                t.name           AS team,
                p2.provider      AS project,
                c.data_cerere    AS requestDate
        ";
        $baseSql = "
            FROM Cereri AS c
            JOIN status   AS s  ON c.id_status = s.id_status
            LEFT JOIN Utilizator AS u ON u.mail = c.mail
            LEFT JOIN Team     AS t  ON u.id_team = t.id_team
            LEFT JOIN Project  AS p2 ON p2.id_user = u.id_user
            WHERE
                c.id_rol = 1
                AND (
                    c.id_status <> 2
                    OR t.id_team IN (
                        SELECT t2.id_team
                        FROM Team AS t2
                        JOIN Project AS p2b ON t2.id_project = p2b.id_project
                        WHERE p2b.id_user = ?
                    )
                )
        ";
        $params[] = $id_user;
    }

    // 5) Apply dynamic filters for both roles

    // 5a) Date filters on c.data_cerere
    if ($startDate !== '') {
        $whereClauses[] = "c.data_cerere >= ?";
        $params[]       = $startDate . " 00:00:00";
    }
    if ($endDate !== '') {
        $whereClauses[] = "c.data_cerere <= ?";
        $params[]       = $endDate . " 23:59:59";
    }

    // 5b) Role filter (only super-admin can use this)
    if ($id_rol === 3 && ($filterRole === 'user' || $filterRole === 'admin')) {
        $roleId = ($filterRole === 'admin') ? 2 : 1;
        $whereClauses[] = "c.id_rol = ?";
        $params[]       = $roleId;
    }

    // 5c) Status filter
    if ($filterStatus !== '') {
        $whereClauses[] = "s.status = ?";
        $params[]       = $filterStatus;
    }

    // 5d) Team filter
    if (!is_null($filterTeam)) {
        $whereClauses[] = "t.id_team = ?";
        $params[]       = $filterTeam;
    }

    // 5e) Project filter (only super-admin)
    if ($id_rol === 3 && !is_null($filterProject)) {
        $whereClauses[] = "p2.id_project = ?";
        $params[]       = $filterProject;
    }

    // 6) Combine WHERE clauses with the base SQL
    if (count($whereClauses) > 0) {
        // For super-admin, start a WHERE clause; for admin, append with AND
        if ($id_rol === 3) {
            $baseSql .= " WHERE " . implode(" AND ", $whereClauses);
        } else {
            // Admin's baseSql already has a WHERE, so use AND
            $baseSql .= " AND " . implode(" AND ", $whereClauses);
        }
    }

    // 7) Build and run COUNT query for total matching rows
    $countSql = "SELECT COUNT(*) AS total {$baseSql}";
    $countStmt = sqlsrv_query($conn, $countSql, $params);
    if (!$countStmt) {
        throw new Exception("Count query failed", 500);
    }
    $countRow  = sqlsrv_fetch_array($countStmt, SQLSRV_FETCH_ASSOC);
    $totalRows = (int)$countRow['total'];

    // 8) Build paginated SELECT query (with OFFSET/FETCH)
    $selectSql = "
        {$baseSelect}
        {$baseSql}
        ORDER BY c.data_cerere DESC
        OFFSET ? ROWS
        FETCH NEXT ? ROWS ONLY
    ";
    // Append pagination parameters (offset, per_page) to params
    $params[] = $offset;
    $params[] = $per_page;

    $stmt = sqlsrv_query($conn, $selectSql, $params);
    if (!$stmt) {
        throw new Exception("Data query failed", 500);
    }

    // 9) Fetch paginated results
    $requests = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $requests[] = [
            "rowNumber"  => $row['row_number'],
            "id"         => $row['id'],
            "fullName"   => $row['fullName'],
            "email"      => $row['email'],
            "rol"        => $row['rol'],
            "status"     => $row['status'],
            "team"       => $row['team'],       // null for admin requests or non-approved
            "project"    => $row['project'],    // null for user requests or non-approved
            "requestDate"=> $row['requestDate']->format('Y-m-d')  // just the date
        ];
    }

    // 10) Return JSON with paginated data and total count
    echo json_encode([
        "requests" => $requests,
        "total"    => $totalRows,
        "page"     => $page,
        "per_page" => $per_page
    ]);

} catch (Throwable $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([ "error" => $e->getMessage() ]);
}
