<?php
// aprove_request.php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    echo json_encode(["message" => "Preflight OK"]);
    exit;
}

include 'db.php';
require_once 'auth.php';

try {
    // 1) Authenticate: only role 2 (admin) or 3 (super-admin) can approve
    $user    = authenticate([2, 3]);
    $idUser  = $user['id_user'];
    $idRol   = $user['id_rol'];

    // 2) Read JSON payload from the frontend
    $input      = json_decode(file_get_contents("php://input"), true);
    $idCerere   = $input['id_cerere']  ?? null;
    $idTeam     = $input['id_team']    ?? null;
    $idProject  = $input['id_project'] ?? null;

    if (!$idCerere) {
        throw new Exception("Missing request ID (id_cerere)", 400);
    }

    // 3) Fetch the pending request from 'Cereri'
    $sqlCerere  = "SELECT * FROM dbo.Cereri WHERE id_cerere = ?";
    $stmtCerere = sqlsrv_query($conn, $sqlCerere, [$idCerere]);
    if (!$stmtCerere || !sqlsrv_has_rows($stmtCerere)) {
        throw new Exception("Request not found", 404);
    }
    $data = sqlsrv_fetch_array($stmtCerere, SQLSRV_FETCH_ASSOC);

    // 4) If the request itself is for an admin account (id_rol = 2),
    //    only super-admins (rol=3) may approve it.
    if ($data['id_rol'] == 2 && $idRol !== 3) {
        throw new Exception("Only super-admins may approve or reject admin requests.", 403);
    }

    // 5) CASE A: If this request wants to create a “regular user” (id_rol = 1):
    if ($data['id_rol'] == 1) {
        // -- We expect $idTeam to be passed. -- 
        if (!$idTeam) {
            throw new Exception("Missing team ID (id_team) for user creation", 400);
        }

        // 5a) Verify that the provided id_team belongs to a project this approver controls.
        //     If approver is rol=3, they can choose ANY team. If approver is rol=2, they can only choose
        //     from their own project’s teams (like before).
        $sqlCheckTeam = "
            SELECT 1
            FROM dbo.Team AS t
            INNER JOIN dbo.Project AS p
              ON t.id_project = p.id_project
            WHERE 
              t.id_team = ?
              AND p.id_user = ?
        ";
        $stmtCheck = sqlsrv_query($conn, $sqlCheckTeam, [$idTeam, $idUser]);
        if (!$stmtCheck || !sqlsrv_has_rows($stmtCheck)) {
            throw new Exception("Invalid team or not under your project", 403);
        }

        // 5b) Insert the new user with role = 1
        //      Assume 'parola' in Cereri is already hashed.
        $hashedPassword = $data['parola'];

        // Use OUTPUT + SCOPE_IDENTITY to get the new id_user back:
        $insertSql  = "
            INSERT INTO dbo.Utilizator (nume, mail, parola, id_rol, id_team)
            OUTPUT INSERTED.id_user AS new_id
            VALUES (?, ?, ?, 1, ?);
        ";
        $paramsInsert = [
            $data['nume'],
            $data['mail'],
            $hashedPassword,
            $idTeam
        ];
        $stmtInsert = sqlsrv_query($conn, $insertSql, $paramsInsert);
        if (!$stmtInsert) {
            throw new Exception("Failed to create user", 500);
        }
        // Fetch the newly created id_user
        $rowNew = sqlsrv_fetch_array($stmtInsert, SQLSRV_FETCH_ASSOC);
        $newUserId = (int)$rowNew['new_id'];

        // 5c) Mark Cereri as approved
        $updateSql = "UPDATE dbo.Cereri SET id_status = 2 WHERE id_cerere = ?";
        sqlsrv_query($conn, $updateSql, [$idCerere]);

        echo json_encode([
            "message"    => "User created (role=1) and assigned to team.",
            "new_userId" => $newUserId
        ]);
        exit;
    }

    // 6) CASE B: If this request wants to create an “admin” (id_rol = 2):
    if ($data['id_rol'] == 2) {
        // -- We expect $idProject to be passed. --
        if (!$idProject) {
            throw new Exception("Missing project ID (id_project) for admin creation", 400);
        }

        // 6a) Verify that the provided project belongs to this super-admin.
        //     Only rol=3 is allowed here (checked above). So we just ensure the project exists.
        $sqlCheckProj = "
            SELECT 1
            FROM dbo.Project
            WHERE id_project = ?
        ";
        $stmtCheckP = sqlsrv_query($conn, $sqlCheckProj, [$idProject]);
        if (!$stmtCheckP || !sqlsrv_has_rows($stmtCheckP)) {
            throw new Exception("Invalid project ID", 400);
        }

        // 6b) Insert the new admin user with role=2 (no team, so id_team = NULL)
        $hashedPassword = $data['parola'];
        $insertSql  = "
            INSERT INTO dbo.Utilizator (nume, mail, parola, id_rol, id_team)
            OUTPUT INSERTED.id_user AS new_id
            VALUES (?, ?, ?, 2, NULL);
        ";
        $paramsInsert = [
            $data['nume'],
            $data['mail'],
            $hashedPassword
        ];
        $stmtInsert = sqlsrv_query($conn, $insertSql, $paramsInsert);
        if (!$stmtInsert) {
            throw new Exception("Failed to create admin user", 500);
        }
        $rowNew = sqlsrv_fetch_array($stmtInsert, SQLSRV_FETCH_ASSOC);
        $newAdminId = (int)$rowNew['new_id'];

        // 6c) Now update the Project row to assign this new admin as its owner:
        $updateProjectSql = "
            UPDATE dbo.Project
            SET id_user = ?
            WHERE id_project = ?
        ";
        $resUpd = sqlsrv_query($conn, $updateProjectSql, [$newAdminId, $idProject]);
        if (!$resUpd) {
            throw new Exception("Failed to assign project to new admin", 500);
        }

        // 6d) Mark Cereri as approved
        $updateCerereSql = "UPDATE dbo.Cereri SET id_status = 2 WHERE id_cerere = ?";
        sqlsrv_query($conn, $updateCerereSql, [$idCerere]);

        echo json_encode([
            "message"        => "Admin user created and assigned to project.",
            "new_adminId"    => $newAdminId
        ]);
        exit;
    }

    // 7) If id_rol has some other unexpected value, refuse
    throw new Exception("Unsupported request type", 400);

} catch (Throwable $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "error" => $e->getMessage()
    ]);
}
