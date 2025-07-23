<?php
session_start();
require 'db_connect.php';

// Verify the user is logged in
if (!isset($_SESSION['employee_id'])) {
    header("Location: bank_login.php");
    exit();
}

$report_type = $_GET['report'] ?? 'daily_transactions';
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$format = $_GET['format'] ?? 'excel';

// Generate reports data
$report_data = [];
$report_title = '';
$columns = [];

if ($report_type == 'daily_transactions') {
    $report_title = "Daily Transactions: " . date('F j, Y', strtotime($start_date)) . " to " . date('F j, Y', strtotime($end_date));
    $columns = ['Date', 'Account', 'Customer', 'Type', 'Category', 'Amount'];
    
    $stmt = $conn->prepare("
        SELECT t.transaction_id, t.transaction_date, t.amount, t.transaction_type,
               a.account_number, c.first_name, c.last_name, cat.category_name
        FROM transactions t
        JOIN accounts a ON t.account_id = a.account_id
        JOIN customers c ON a.user_id = c.customer_id
        JOIN transaction_categories cat ON t.category_id = cat.category_id
        WHERE t.transaction_date BETWEEN ? AND ?
        ORDER BY t.transaction_date DESC, t.transaction_id DESC
    ");
    $stmt->bind_param("ss", $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $raw_data = $result->fetch_all(MYSQLI_ASSOC);
    
    // Format data for display
    foreach ($raw_data as $row) {
        $report_data[] = [
            'Date' => date('m/d/Y H:i', strtotime($row['transaction_date'])),
            'Account' => $row['account_number'],
            'Customer' => $row['last_name'] . ', ' . $row['first_name'],
            'Type' => ucfirst($row['transaction_type']),
            'Category' => $row['category_name'],
            'Amount' => '$' . number_format($row['amount'], 2)
        ];
    }
    
} elseif ($report_type == 'cash_flow') {
    $report_title = "Cash Flow Report: " . date('F j, Y', strtotime($start_date)) . " to " . date('F j, Y', strtotime($end_date));
    $columns = ['Date', 'Total Deposits', 'Total Withdrawals', 'Net Flow'];
    
    $stmt = $conn->prepare("
        SELECT
            DATE(t.transaction_date) as date,
            SUM(CASE WHEN t.transaction_type = 'INCOME' THEN t.amount ELSE 0 END) as total_deposits,
            SUM(CASE WHEN t.transaction_type = 'EXPENSE' THEN t.amount ELSE 0 END) as total_withdrawals,
            SUM(CASE WHEN t.transaction_type = 'INCOME' THEN t.amount ELSE -t.amount END) as net_flow
        FROM transactions t
        WHERE t.transaction_date BETWEEN ? AND ?
        GROUP BY DATE(t.transaction_date)
        ORDER BY DATE(t.transaction_date) DESC
    ");
    $stmt->bind_param("ss", $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $raw_data = $result->fetch_all(MYSQLI_ASSOC);
    
    // Format data for display
    foreach ($raw_data as $row) {
        $report_data[] = [
            'Date' => date('m/d/Y', strtotime($row['date'])),
            'Total Deposits' => '$' . number_format($row['total_deposits'], 2),
            'Total Withdrawals' => '$' . number_format($row['total_withdrawals'], 2),
            'Net Flow' => '$' . number_format($row['net_flow'], 2)
        ];
    }
    
} elseif ($report_type == 'account_balances') {
    $report_title = "Account Balances as of " . date('F j, Y', strtotime($end_date));
    $columns = ['Account Number', 'Account Type', 'Customer', 'Email', 'Date Opened', 'Balance'];
    
    $stmt = $conn->prepare("
        SELECT
            a.account_number,
            at.type_name as account_type,
            a.balance,
            c.first_name,
            c.last_name,
            c.email,
            a.created_at as date_opened
        FROM accounts a
        JOIN account_types at ON a.account_type_id = at.type_id
        JOIN customers c ON a.user_id = c.customer_id
        WHERE a.created_at <= ? AND a.is_active = 1
        ORDER BY a.balance DESC
    ");
    $stmt->bind_param("s", $end_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $raw_data = $result->fetch_all(MYSQLI_ASSOC);
    
    // Format data for display
    foreach ($raw_data as $row) {
        $report_data[] = [
            'Account Number' => $row['account_number'],
            'Account Type' => ucfirst($row['account_type']),
            'Customer' => $row['last_name'] . ', ' . $row['first_name'],
            'Email' => $row['email'],
            'Date Opened' => date('m/d/Y', strtotime($row['date_opened'])),
            'Balance' => '$' . number_format($row['balance'], 2)
        ];
    }
}

// Handle export
if ($format == 'excel') {
    exportToExcel($report_title, $columns, $report_data);
} elseif ($format == 'pdf') {
    exportToPDF($report_title, $columns, $report_data);
} elseif ($format == 'csv') {
    exportToCSV($report_title, $columns, $report_data);
}

// Export Functions
function exportToExcel($title, $columns, $data) {
    // Clean any output buffer
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . sanitizeFilename($title) . '_' . date('Y-m-d') . '.xls"');
    header('Cache-Control: max-age=0');
    
    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel">';
    echo '<head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"></head>';
    echo '<body>';
    echo '<table border="1">';
    
    // Title row
    echo '<tr><th colspan="' . count($columns) . '" style="font-weight:bold; font-size:14px; text-align:center; background-color:#4CAF50; color:white;">' . htmlspecialchars($title) . '</th></tr>';
    
    // Header row
    echo '<tr>';
    foreach ($columns as $col) {
        echo '<th style="font-weight:bold; background-color:#f2f2f2; text-align:center;">' . htmlspecialchars($col) . '</th>';
    }
    echo '</tr>';
    
    // Data rows
    foreach ($data as $row) {
        echo '<tr>';
        foreach ($columns as $col) {
            $value = isset($row[$col]) ? $row[$col] : '';
            echo '<td>' . htmlspecialchars($value) . '</td>';
        }
        echo '</tr>';
    }
    
    echo '</table>';
    echo '</body></html>';
    exit();
}

function exportToPDF($title, $columns, $data) {
    // Check if TCPDF is available
    if (!class_exists('TCPDF')) {
        // If TCPDF is not available, use HTML to PDF conversion
        exportToPDFHTML($title, $columns, $data);
        return;
    }
    
    require_once('tcpdf/tcpdf.php');
    
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor('Bank Management System');
    $pdf->SetTitle($title);
    $pdf->SetSubject('Bank Report');
    
    $pdf->AddPage();
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, $title, 0, 1, 'C');
    $pdf->Ln(5);
    $pdf->SetFont('helvetica', '', 8);
    
    // Create table
    $html = '<table border="1" cellpadding="4" cellspacing="0" style="border-collapse:collapse;">';
    
    // Header
    $html .= '<tr style="background-color:#f2f2f2;">';
    foreach ($columns as $col) {
        $html .= '<th style="font-weight:bold; text-align:center; padding:5px;">' . htmlspecialchars($col) . '</th>';
    }
    $html .= '</tr>';
    
    // Data rows
    foreach ($data as $row) {
        $html .= '<tr>';
        foreach ($columns as $col) {
            $value = isset($row[$col]) ? $row[$col] : '';
            $html .= '<td style="padding:3px;">' . htmlspecialchars($value) . '</td>';
        }
        $html .= '</tr>';
    }
    
    $html .= '</table>';
    
    $pdf->writeHTML($html, true, false, false, false, '');
    
    // Clean any output buffer
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    $pdf->Output(sanitizeFilename($title) . '_' . date('Y-m-d') . '.pdf', 'D');
    exit();
}

function exportToPDFHTML($title, $columns, $data) {
    // Fallback HTML-based PDF generation
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . sanitizeFilename($title) . '_' . date('Y-m-d') . '.pdf"');
    
    echo '<!DOCTYPE html>';
    echo '<html><head>';
    echo '<title>' . htmlspecialchars($title) . '</title>';
    echo '<style>';
    echo 'body { font-family: Arial, sans-serif; margin: 20px; }';
    echo 'table { width: 100%; border-collapse: collapse; margin-top: 20px; }';
    echo 'th, td { border: 1px solid #000; padding: 8px; text-align: left; }';
    echo 'th { background-color: #f2f2f2; font-weight: bold; }';
    echo 'h1 { text-align: center; color: #333; }';
    echo '</style>';
    echo '</head><body>';
    
    echo '<h1>' . htmlspecialchars($title) . '</h1>';
    echo '<table>';
    
    // Header
    echo '<tr>';
    foreach ($columns as $col) {
        echo '<th>' . htmlspecialchars($col) . '</th>';
    }
    echo '</tr>';
    
    // Data
    foreach ($data as $row) {
        echo '<tr>';
        foreach ($columns as $col) {
            $value = isset($row[$col]) ? $row[$col] : '';
            echo '<td>' . htmlspecialchars($value) . '</td>';
        }
        echo '</tr>';
    }
    
    echo '</table>';
    echo '</body></html>';
    exit();
}

function exportToCSV($title, $columns, $data) {
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . sanitizeFilename($title) . '_' . date('Y-m-d') . '.csv"');
    header('Cache-Control: max-age=0');
    
    $output = fopen('php://output', 'w');
    
    // Write title
    fputcsv($output, [$title]);
    fputcsv($output, []); // Empty row
    
    // Write headers
    fputcsv($output, $columns);
    
    // Write data
    foreach ($data as $row) {
        $csvRow = [];
        foreach ($columns as $col) {
            $csvRow[] = isset($row[$col]) ? $row[$col] : '';
        }
        fputcsv($output, $csvRow);
    }
    
    fclose($output);
    exit();
}

function sanitizeFilename($filename) {
    // Remove or replace invalid characters
    $filename = preg_replace('/[^a-zA-Z0-9\s\-_]/', '', $filename);
    $filename = preg_replace('/\s+/', '_', $filename);
    return trim($filename, '_');
}
?>