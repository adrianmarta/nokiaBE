<?php
/*
  get_audit_stare.php

  Returns paginated audit logs for tickets, filtered by team, date range, project, user, and ticket.
  - Role 2 (admin) sees only logs for tickets whose team_assigned_person equals the admin’s own team.
  - Role 3 (super-admin) can optionally filter by any team_id, project_id, user_id, ticket_id, and date range.
*/

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

try {
    // 1) Pagination parameters
    $page     = isset($_GET['page'])     ? max((int)$_GET['page'], 1)     : 1;
    $per_page = isset($_GET['per_page']) ? max((int)$_GET['per_page'], 1) : 15;
    $offset   = ($page - 1) * $per_page;

    // 2) Authenticate the caller (allow roles 2 = admin, 3 = super-admin)
    $user    = authenticate([2, 3]);
    $id_user = (int)$user['id_user'];
    $id_rol  = (int)$user['id_rol'];

    // 3) Look up the admin’s own team if role = 2
    $id_team = null;
    if ($id_rol === 2) {
        $lookupTeamSql  = "SELECT id_team FROM Utilizator WHERE id_user = ?";
        $lookupTeamStmt = sqlsrv_query($conn, $lookupTeamSql, [$id_user]);
        if (!$lookupTeamStmt) {
            throw new Exception("Cannot fetch team for user {$id_user}");
        }
        $lookupRow = sqlsrv_fetch_array($lookupTeamStmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($lookupTeamStmt);

        if (isset($lookupRow['id_team'])) {
            $id_team = (int)$lookupRow['id_team'];
        } else {
            // If for some reason there's no team for this admin, return an empty result set
            echo json_encode([
                "success" => true,
                "rows"    => [],
                "page"    => $page,
                "per_page"=> $per_page,
                "total"   => 0
            ]);
            exit;
        }
    }

    // 4) Build dynamic WHERE clauses
    $filters = [];
    $params  = [];

 if ($id_rol === 2) {
    if (!empty($_GET['team_id'])) {
        // Restrict to specific team but ONLY if owned by admin
        $filters[] = "t.team_assigned_person = ? AND t.team_assigned_person IN (
            SELECT t2.id_team
            FROM Team AS t2
            JOIN Project AS p ON t2.id_project = p.id_project
            WHERE p.id_user = ?
        )";
        $params[] = (int)$_GET['team_id'];
        $params[] = $id_user;
    } else {
        // No team filter → fetch all teams from admin’s projects
        $filters[] = "t.team_assigned_person IN (
            SELECT t2.id_team
            FROM Team AS t2
            JOIN Project AS p ON t2.id_project = p.id_project
            WHERE p.id_user = ?
        )";
        $params[] = $id_user;
    }
}
    if ($id_rol === 3 && !empty($_GET['team_id'])) {
    $filters[] = "t.team_assigned_person = ?";
    $params[]  = (int)$_GET['team_id'];
}
    // 4b) Date range filters:
    if (!empty($_GET['start_date'])) {
        // Assume format YYYY-MM-DD; include rows at or after start_date 00:00:00
        $filters[] = "a.timp >= ?";
        $params[]  = $_GET['start_date'] . " 00:00:00";
    }
    if (!empty($_GET['end_date'])) {
        // Include rows up to end_date 23:59:59
        $filters[] = "a.timp <= ?";
        $params[]  = $_GET['end_date'] . " 23:59:59";
    }

    // 4c) Project filter:
    if (!empty($_GET['project_id'])) {
        $filters[] = "a.id_project = ?";
        $params[]  = (int)$_GET['project_id'];
    }

 if (!empty($_GET['user_id'])) {
    if ($id_rol === 2) {
        $filters[] = "a.id_user = ? AND a.id_user IN (
            SELECT u2.id_user
            FROM Utilizator u2
            JOIN Team t2 ON u2.id_team = t2.id_team
            JOIN Project p ON t2.id_project = p.id_project
            WHERE p.id_user = ?
        )";
        $params[] = (int)$_GET['user_id'];
        $params[] = $id_user;
    } else {
        $filters[] = "a.id_user = ?";
        $params[] = (int)$_GET['user_id'];
    }

}


    // 4e) Ticket filter:
  if (!empty($_GET['ticket_id'])) {
    $filters[] = "(t.ticket_id LIKE ? OR CAST(a.id_ticket AS NVARCHAR) LIKE ?)";
    $params[]  = '%' . $_GET['ticket_id'] . '%';
    $params[]  = '%' . $_GET['ticket_id'] . '%';
}
    // Combine filters into a single WHERE clause
    $where = "";
    if (count($filters) > 0) {
        $where = "WHERE " . implode(" AND ", $filters);
    }

    // 5) First, get total row count for pagination
    $countSql = "
        SELECT COUNT(*) AS totalRows
        FROM audit_stare AS a
        JOIN Tickets       AS t  ON a.id_ticket            = t.id
        JOIN Team          AS tm ON t.team_assigned_person = tm.id_team
        JOIN Project       AS p  ON a.id_project           = p.id_project
        JOIN Utilizator    AS u  ON a.id_user              = u.id_user
        JOIN actiune       AS act ON a.id_actiune          = act.id
        LEFT JOIN status_ticket AS s1 ON a.id_stare_curenta  = s1.id_status
        LEFT JOIN status_ticket AS s2 ON a.id_stare_trecuta  = s2.id_status
        $where
    ";
    $countStmt = sqlsrv_query($conn, $countSql, $params);
    if (!$countStmt) {
        throw new Exception("Count query failed");
    }
    $totalRow  = sqlsrv_fetch_array($countStmt, SQLSRV_FETCH_ASSOC);
    $totalRows = (int)$totalRow['totalRows'];
    sqlsrv_free_stmt($countStmt);

    // 6) Now fetch the requested page of audit rows
    $sql = "
        SELECT
            ROW_NUMBER() OVER (ORDER BY a.timp DESC) AS row_number,
            u.nume                AS nume_utilizator,
            act.actiune           AS actiune,
            tm.name               AS echipa,
            p.provider            AS provider,
            s1.nume               AS stare_curenta,
            s2.nume               AS stare_trecuta,
            t.ticket_id           AS ticket_id,
            a.id_ticket           AS id_ticket,
            a.timp                AS timp
        FROM audit_stare AS a
        JOIN Tickets       AS t  ON a.id_ticket            = t.id
        JOIN Team          AS tm ON t.team_assigned_person = tm.id_team
        JOIN Project       AS p  ON a.id_project           = p.id_project
        JOIN Utilizator    AS u  ON a.id_user              = u.id_user
        JOIN actiune       AS act ON a.id_actiune          = act.id
        LEFT JOIN status_ticket AS s1 ON a.id_stare_curenta  = s1.id_status
        LEFT JOIN status_ticket AS s2 ON a.id_stare_trecuta  = s2.id_status
        $where
        ORDER BY a.timp DESC
        OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
    ";
    // Append OFFSET and per_page to the parameters
    $params[] = $offset;
    $params[] = $per_page;

    $stmt = sqlsrv_query($conn, $sql, $params);
    if (!$stmt) {
        $err = sqlsrv_errors()[0]['message'] ?? 'Unknown error';
        throw new Exception("Query failed: $err");
    }

    // 7) Fetch rows into an array
    $results = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $results[] = $row;
    }
    sqlsrv_free_stmt($stmt);

    // 8) Return JSON with pagination info
    echo json_encode([
        "success"   => true,
        "rows"      => $results,
        "page"      => $page,
        "per_page"  => $per_page,
        "total"     => $totalRows
    ]);
    sqlsrv_close($conn);
}
catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error"   => $e->getMessage()
    ]);
    if (isset($conn)) { sqlsrv_close($conn); }
}
?>
