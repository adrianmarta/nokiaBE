<?php
ini_set('display_errors', 0);
error_reporting(0);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["error" => "Method not allowed"]);
    exit();
}

include 'db.php';

if (!$conn) {
    http_response_code(500);
    echo json_encode(["error" => "Database connection failed"]);
    exit();
}

$input_raw = file_get_contents('php://input');
if (!$input_raw) {
    http_response_code(400);
    echo json_encode(["error" => "No input data received"]);
    exit();
}

$input = json_decode($input_raw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid JSON input"]);
    exit();
}

if (!isset($input['ticket_id']) || !isset($input['assigned_person'])) {
    http_response_code(400);
    echo json_encode(["error" => "Missing required fields: ticket_id and assigned_person"]);
    exit();
}

$ticket_id = $input['ticket_id'];
$assigned_email = trim($input['assigned_person']);

if (!is_numeric($ticket_id)) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid ticket ID"]);
    exit();
}

try {
    // 1. Găsim id_user și id_team al utilizatorului
    $findUserSql = "SELECT id_user, id_team FROM Utilizator WHERE mail = ?";
    $findUserStmt = sqlsrv_prepare($conn, $findUserSql, [$assigned_email]);

    if (!$findUserStmt || !sqlsrv_execute($findUserStmt) || !sqlsrv_fetch($findUserStmt)) {
        http_response_code(404);
        echo json_encode(["error" => "Emailul nu a fost găsit în baza de date"]);
        exit();
    }

    $user_id = sqlsrv_get_field($findUserStmt, 0);
    $user_team_id = sqlsrv_get_field($findUserStmt, 1);
    sqlsrv_free_stmt($findUserStmt);

    // 2. Obținem informațiile despre ticket pentru a calcula response_time
    $getTicketSql = "SELECT start_date, assigned_person FROM tickets WHERE id = ?";
    $getTicketStmt = sqlsrv_prepare($conn, $getTicketSql, [$ticket_id]);

    if (!$getTicketStmt || !sqlsrv_execute($getTicketStmt) || !sqlsrv_fetch($getTicketStmt)) {
        http_response_code(404);
        echo json_encode(["error" => "Ticket-ul nu a fost găsit"]);
        exit();
    }

    $start_date = sqlsrv_get_field($getTicketStmt, 0);
    $current_assigned_person = sqlsrv_get_field($getTicketStmt, 1);
    sqlsrv_free_stmt($getTicketStmt);

    // 3. Calculăm response_time
    $response_time = null;
    $assigned_date_value = 'GETDATE()';
    
    // Dacă ticket-ul nu avea deja o persoană asignată, calculăm response_time
    if (is_null($current_assigned_person) || $current_assigned_person == 0) {
        if ($start_date) {
            // Calculăm diferența în ore între start_date și momentul curent
            $current_time = new DateTime();
            $start_datetime = $start_date;
            
            // Folosim SQL pentru a calcula diferența în ore
            $response_time_calculation = "DATEDIFF(HOUR, start_date, GETDATE())";
        } else {
            $response_time_calculation = "0";
        }
    } else {
        // Dacă ticket-ul avea deja o persoană asignată, păstrăm assigned_date existent
        $assigned_date_value = 'assigned_date';
        $response_time_calculation = "response_time"; // Păstrăm valoarea existentă
    }

    // 4. Update tickets cu calculul response_time
    $updateSql = "
        UPDATE tickets 
        SET assigned_person = ?, 
            team_assigned_person = ?, 
            last_modified_date = GETDATE(),
            assigned_date = CASE 
                WHEN assigned_person IS NULL OR assigned_person = 0 
                THEN GETDATE() 
                ELSE assigned_date 
            END,
            response_time = CASE 
                WHEN assigned_person IS NULL OR assigned_person = 0 
                THEN " . $response_time_calculation . "
                ELSE response_time 
            END
        WHERE id = ?
    ";

    $updateStmt = sqlsrv_prepare($conn, $updateSql, [$user_id, $user_team_id, $ticket_id]);

    if (!$updateStmt || !sqlsrv_execute($updateStmt)) {
        throw new Exception("Database update failed");
    }

    $rowsAffected = sqlsrv_rows_affected($updateStmt);
    sqlsrv_free_stmt($updateStmt);

    if ($rowsAffected > 0) {
        // 5. Obținem response_time calculat pentru a-l returna în răspuns
        $getResponseTimeSql = "SELECT response_time FROM tickets WHERE id = ?";
        $getResponseTimeStmt = sqlsrv_prepare($conn, $getResponseTimeSql, [$ticket_id]);
        
        $calculated_response_time = null;
        if ($getResponseTimeStmt && sqlsrv_execute($getResponseTimeStmt) && sqlsrv_fetch($getResponseTimeStmt)) {
            $calculated_response_time = sqlsrv_get_field($getResponseTimeStmt, 0);
        }
        sqlsrv_free_stmt($getResponseTimeStmt);

        http_response_code(200);
        echo json_encode([
            "success" => true,
            "message" => "Responsabil și echipă atribuite cu succes",
            "ticket_id" => (int)$ticket_id,
            "assigned_user_id" => (int)$user_id,
            "assigned_email" => $assigned_email,
            "team_assigned_person_id" => $user_team_id,
            "response_time" => $calculated_response_time,
            "was_previously_assigned" => !is_null($current_assigned_person) && $current_assigned_person != 0
        ]);
    } else {
        http_response_code(400);
        echo json_encode(["error" => "Nicio modificare realizată"]);
    }

} catch (Exception $e) {
    $sqlErrors = sqlsrv_errors();
    http_response_code(500);
    echo json_encode([
        "error" => "Database operation failed: " . $e->getMessage(),
        "sqlsrv_errors" => $sqlErrors
    ]);
}

sqlsrv_close($conn);
?>