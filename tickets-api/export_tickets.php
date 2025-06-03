<?php
ob_start(); // Prevent any accidental output

require 'vendor/autoload.php';
include 'db.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Accept POST request with JSON list of ticket_ids
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['ticket_ids'])) {
    http_response_code(400);
    die("No ticket_ids provided.");
}

$ticketIds = json_decode($_POST['ticket_ids'], true);
if (!is_array($ticketIds) || count($ticketIds) === 0) {
    http_response_code(400);
    die("Invalid ticket_ids.");
}

// Build dynamic placeholders (?, ?, ?, ...)
$placeholders = implode(',', array_fill(0, count($ticketIds), '?'));

// SQL to match exact order of IDs (CASE sort)
$sql = "
SELECT 
    t.ticket_id,
    t.status, 
    p.priority,
    t.project,
    t.assigned_person,
    t.created_by,
    t.start_date,
    t.closed_date,
    t.description,
    t.resolution,
    DATEDIFF(HOUR, t.start_date, COALESCE(t.closed_date, GETDATE())) AS duration_hours
FROM Tickets t
LEFT JOIN Priority p ON t.priority_id = p.id
WHERE t.ticket_id IN ($placeholders)
ORDER BY CASE t.ticket_id
";

foreach ($ticketIds as $index => $id) {
    $sql .= " WHEN ? THEN $index";
}
$sql .= " END";

// Execute query
$params = array_merge($ticketIds, $ticketIds); // for WHERE + ORDER
$stmt = sqlsrv_query($conn, $sql, $params);
if (!$stmt) {
    if (ob_get_length()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(["error" => "Query failed", "details" => sqlsrv_errors()]);
    exit;
}

// Create Excel spreadsheet
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle("Tickets Export");

// Headers
$headers = [
    'Ticket ID', 'Status', 'Priority', 'Project',
    'Assigned Person', 'Created By', 'Start Date',
    'Closed Date', 'Duration (Hours)', 'Description', 'Resolution'
];
$sheet->fromArray($headers, NULL, 'A1');

// Style header
$sheet->getStyle('A1:K1')->applyFromArray([
    'font' => ['bold' => true],
    'fill' => [
        'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
        'startColor' => ['rgb' => 'E3F2FD']
    ],
    'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER]
]);

foreach (range('A', 'K') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// Add rows
$rowNum = 2;
while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $sheet->setCellValue("A$rowNum", $row['ticket_id']);
    $sheet->setCellValue("B$rowNum", $row['status']);
    $sheet->setCellValue("C$rowNum", $row['priority']);
    $sheet->setCellValue("D$rowNum", $row['project']);
    $sheet->setCellValue("E$rowNum", $row['assigned_person']);
    $sheet->setCellValue("F$rowNum", $row['created_by']);
    $sheet->setCellValue("G$rowNum", $row['start_date'] instanceof DateTime ? $row['start_date']->format('Y-m-d H:i') : '');
    $sheet->setCellValue("H$rowNum", $row['closed_date'] instanceof DateTime ? $row['closed_date']->format('Y-m-d H:i') : '');
    $sheet->setCellValue("I$rowNum", $row['duration_hours']);
    $sheet->setCellValue("J$rowNum", $row['description']);
    $sheet->setCellValue("K$rowNum", $row['resolution']);
    $rowNum++;
}

// Format wrap text
$sheet->getStyle('J:K')->getAlignment()->setWrapText(true);

// Final headers for Excel download
if (ob_get_length()) ob_end_clean();
header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
header("Content-Disposition: attachment; filename=\"tickets_export.xlsx\"");
header("Cache-Control: max-age=0");

// Output file
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
