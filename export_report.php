<?php
session_start();
require 'db_connect.php';

// Ensure no output is sent before headers
ob_end_clean();

// Strict authentication check
if (!isset($_SESSION['employee_id']) || !isset($_SESSION['bank_id'])) {
    // Redirect to login page if not authenticated
    header("Location: bank_login.php");
    exit();
}

// Get the logged-in bank ID
$loggedInBankId = (int)$_SESSION['bank_id'];

// Get report parameters from GET request
$report_type = $_GET['report'] ?? 'daily_transactions';
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$format = $_GET['format'] ?? 'pdf'; // Default to PDF if not specified

// Validate dates
if (!strtotime($start_date) || !strtotime($end_date)) {
    die("Invalid date parameters.");
}

$report_data = [];
$report_title = '';

try {
    // --- Data Retrieval Logic (Copied directly from your reports.php) ---
    if ($report_type == 'daily_transactions') {
        $report_title = "Daily Transactions: " . date('F j, Y', strtotime($start_date)) . " to " . date('F j, Y', strtotime($end_date));

        $query = "SELECT t.transaction_id, t.transaction_date, t.amount, t.transaction_type, t.description,
                                  a.account_number, c.first_name, c.last_name, cat.category_name, a.currency
                          FROM transactions t
                          INNER JOIN accounts a ON t.account_id = a.account_id
                          INNER JOIN customers c ON a.user_id = c.customer_id AND c.bank_id = ?
                          LEFT JOIN categories cat ON t.category_id = cat.category_id -- Changed from transaction_categories to categories
                          WHERE t.transaction_date BETWEEN ? AND ?
                          ORDER BY t.transaction_date DESC, t.transaction_id DESC";

        $stmt = $conn->prepare($query);
        $stmt->bind_param("iss", $loggedInBankId, $start_date, $end_date);
        $stmt->execute();
        $result = $stmt->get_result();
        $report_data = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

    } elseif ($report_type == 'cash_flow') {
        $report_title = "Cash Flow Report: " . date('F j, Y', strtotime($start_date)) . " to " . date('F j, Y', strtotime($end_date));

        $query = "SELECT DATE(t.transaction_date) as date,
                                  SUM(CASE WHEN t.transaction_type = 'INCOME' THEN t.amount ELSE 0 END) as total_deposits,
                                  SUM(CASE WHEN t.transaction_type = 'EXPENSE' THEN t.amount ELSE 0 END) as total_withdrawals,
                                  SUM(CASE WHEN t.transaction_type = 'INCOME' THEN t.amount ELSE -t.amount END) as net_flow
                          FROM transactions t
                          INNER JOIN accounts a ON t.account_id = a.account_id
                          INNER JOIN customers c ON a.user_id = c.customer_id AND c.bank_id = ?
                          WHERE t.transaction_date BETWEEN ? AND ?
                          GROUP BY DATE(t.transaction_date)
                          ORDER BY DATE(t.transaction_date) DESC";

        $stmt = $conn->prepare($query);
        $stmt->bind_param("iss", $loggedInBankId, $start_date, $end_date);
        $stmt->execute();
        $result = $stmt->get_result();
        $report_data = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

    } elseif ($report_type == 'account_balances') {
        $report_title = "Account Balances as of " . date('F j, Y', strtotime($end_date));

        $query = "SELECT a.account_number, at.type_name as account_type, a.balance, a.currency,
                                  c.first_name, c.last_name, c.email, a.created_at as date_opened
                          FROM accounts a
                          INNER JOIN account_types at ON a.account_type_id = at.type_id
                          INNER JOIN customers c ON a.user_id = c.customer_id AND c.bank_id = ?
                          WHERE a.created_at <= ? AND a.is_active = 1
                          ORDER BY a.balance DESC";

        $stmt = $conn->prepare($query);
        $stmt->bind_param("is", $loggedInBankId, $end_date);
        $stmt->execute();
        $result = $stmt->get_result();
        $report_data = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
} catch (Exception $e) {
    error_log("Report generation error in export_report.php: " . $e->getMessage());
    die("Error generating report. Please try again.");
}

// --- Output Generation Logic ---
if (empty($report_data)) {
    die("No data found for the selected criteria to export.");
}

if ($format == 'csv' || $format == 'excel') {
    // CSV/Excel Export
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . str_replace(' ', '_', $report_type) . '_report_' . date('Ymd') . '.csv"');

    $output = fopen('php://output', 'w');

    // Define CSV Headers based on report type
    $headers = [];
    if ($report_type == 'daily_transactions') {
        $headers = ['Transaction ID', 'Date', 'Account Number', 'Customer Name', 'Type', 'Category', 'Description', 'Amount'];
    } elseif ($report_type == 'cash_flow') {
        $headers = ['Date', 'Total Deposits', 'Total Withdrawals', 'Net Flow'];
    } elseif ($report_type == 'account_balances') {
        $headers = ['Account Number', 'Account Type', 'Customer Name', 'Email', 'Date Opened', 'Balance'];
    }
    fputcsv($output, $headers);

    // Write Data Rows
    foreach ($report_data as $row) {
        $rowData = [];
        if ($report_type == 'daily_transactions') {
            $rowData = [
                $row['transaction_id'],
                date('m/d/Y H:i', strtotime($row['transaction_date'])),
                $row['account_number'],
                $row['last_name'] . ', ' . $row['first_name'],
                ucfirst($row['transaction_type']),
                $row['category_name'],
                $row['description'],
                ($row['currency'] ?? 'USD') . ' ' . number_format($row['amount'], 2)
            ];
        } elseif ($report_type == 'cash_flow') {
            $rowData = [
                date('m/d/Y', strtotime($row['date'])),
                number_format($row['total_deposits'], 2),
                number_format($row['total_withdrawals'], 2),
                number_format($row['net_flow'], 2)
            ];
        } elseif ($report_type == 'account_balances') {
            $rowData = [
                $row['account_number'],
                ucfirst($row['account_type']),
                $row['last_name'] . ', ' . $row['first_name'],
                $row['email'],
                date('m/d/Y', strtotime($row['date_opened'])),
                ($row['currency'] ?? 'USD') . ' ' . number_format($row['balance'], 2)
            ];
        }
        fputcsv($output, $rowData);
    }
    fclose($output);
    exit;

} elseif ($format == 'pdf') {
    // PDF Export (using FPDF)
    require('fpdf/fpdf.php'); // Ensure this path is correct

    class PDF extends FPDF
    {
        // Page header
        function Header()
        {
            global $report_title; // Access global report title
            $this->SetFont('Arial', 'B', 15);
            $this->Cell(0, 10, $report_title, 0, 1, 'C');
            $this->Ln(10);
        }

        // Page footer
        function Footer()
        {
            $this->SetY(-15);
            $this->SetFont('Arial', 'I', 8);
            $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
        }

        // Table Colored
        function FancyTable($header, $data, $report_type)
        {
            // Colors, line width and bold font
            $this->SetFillColor(200, 220, 255); // Light blue for header
            $this->SetTextColor(0);
            $this->SetDrawColor(128, 0, 0);
            $this->SetLineWidth(.3);
            $this->SetFont('Arial', 'B', 10);

            // Header
            $w = []; // Column widths
            if ($report_type == 'daily_transactions') {
                $w = [30, 30, 40, 25, 25, 20, 30]; // Adjusted widths for Daily Transactions
            } elseif ($report_type == 'cash_flow') {
                $w = [40, 50, 50, 50];
            } elseif ($report_type == 'account_balances') {
                $w = [35, 30, 40, 40, 25, 25];
            }

            for ($i = 0; $i < count($header); $i++) {
                $this->Cell($w[$i], 7, $header[$i], 1, 0, 'C', true);
            }
            $this->Ln();

            // Color and font for data rows
            $this->SetFillColor(245, 245, 245); // Lighter gray for data rows
            $this->SetTextColor(0);
            $this->SetFont('Arial', '', 9);

            // Data
            $fill = false;
            foreach ($data as $row) {
                if ($report_type == 'daily_transactions') {
                    $this->Cell($w[0], 6, date('m/d/Y H:i', strtotime($row['transaction_date'])), 'LR', 0, 'L', $fill);
                    $this->Cell($w[1], 6, $row['account_number'], 'LR', 0, 'L', $fill);
                    $this->Cell($w[2], 6, $row['last_name'] . ', ' . $row['first_name'], 'LR', 0, 'L', $fill);
                    $this->Cell($w[3], 6, ucfirst($row['transaction_type']), 'LR', 0, 'C', $fill);
                    $this->Cell($w[4], 6, $row['category_name'], 'LR', 0, 'L', $fill);
                    $this->Cell($w[5], 6, ($row['currency'] ?? 'USD'), 'LR', 0, 'R', $fill);
                    $this->Cell($w[6], 6, number_format($row['amount'], 2), 'LR', 0, 'R', $fill);
                } elseif ($report_type == 'cash_flow') {
                    $this->Cell($w[0], 6, date('m/d/Y', strtotime($row['date'])), 'LR', 0, 'L', $fill);
                    $this->Cell($w[1], 6, number_format($row['total_deposits'], 2), 'LR', 0, 'R', $fill);
                    $this->Cell($w[2], 6, number_format($row['total_withdrawals'], 2), 'LR', 0, 'R', $fill);
                    $this->Cell($w[3], 6, number_format($row['net_flow'], 2), 'LR', 0, 'R', $fill);
                } elseif ($report_type == 'account_balances') {
                    $this->Cell($w[0], 6, $row['account_number'], 'LR', 0, 'L', $fill);
                    $this->Cell($w[1], 6, ucfirst($row['account_type']), 'LR', 0, 'L', $fill);
                    $this->Cell($w[2], 6, $row['last_name'] . ', ' . $row['first_name'], 'LR', 0, 'L', $fill);
                    $this->Cell($w[3], 6, $row['email'], 'LR', 0, 'L', $fill);
                    $this->Cell($w[4], 6, date('m/d/Y', strtotime($row['date_opened'])), 'LR', 0, 'L', $fill);
                    $this->Cell($w[5], 6, ($row['currency'] ?? 'USD') . ' ' . number_format($row['balance'], 2), 'LR', 0, 'R', $fill);
                }
                $this->Ln();
                $fill = !$fill;
            }
            // Closing line
            $this->Cell(array_sum($w), 0, '', 'T');
            $this->Ln(10);
        }
    }

    $pdf = new PDF();
    $pdf->AliasNbPages(); // For 'Page X of Y' footer
    $pdf->AddPage();
    $pdf->SetFont('Arial', '', 12);

    // Headers for PDF Table
    $pdf_headers = [];
    if ($report_type == 'daily_transactions') {
        $pdf_headers = ['Date', 'Account', 'Customer', 'Type', 'Category', 'Curr.', 'Amount'];
    } elseif ($report_type == 'cash_flow') {
        $pdf_headers = ['Date', 'Total Deposits', 'Total Withdrawals', 'Net Flow'];
    } elseif ($report_type == 'account_balances') {
        $pdf_headers = ['Account Number', 'Account Type', 'Customer', 'Email', 'Date Opened', 'Balance'];
    }

    $pdf->FancyTable($pdf_headers, $report_data, $report_type);

    // Add summary information to PDF
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 10, 'Report Summary:', 0, 1, 'L');
    $pdf->SetFont('Arial', '', 12);

    if ($report_type == 'daily_transactions') {
        $total_transactions = count($report_data);
        $total_amount = array_sum(array_column($report_data, 'amount'));
        $income_count = count(array_filter($report_data, function($row) { return ($row['transaction_type'] ?? '') == 'INCOME'; }));
        $expense_count = $total_transactions - $income_count;

        $pdf->Cell(0, 7, 'Total Transactions: ' . number_format($total_transactions), 0, 1);
        $pdf->Cell(0, 7, 'Income Transactions: ' . number_format($income_count), 0, 1);
        $pdf->Cell(0, 7, 'Expense Transactions: ' . number_format($expense_count), 0, 1);
        $pdf->Cell(0, 7, 'Total Amount: ' . ($report_data[0]['currency'] ?? 'USD') . ' ' . number_format($total_amount, 2), 0, 1);
    } elseif ($report_type == 'cash_flow') {
        $total_deposits = array_sum(array_column($report_data, 'total_deposits'));
        $total_withdrawals = array_sum(array_column($report_data, 'total_withdrawals'));
        $net_flow = $total_deposits - $total_withdrawals;

        $pdf->Cell(0, 7, 'Total Deposits: $' . number_format($total_deposits, 2), 0, 1);
        $pdf->Cell(0, 7, 'Total Withdrawals: $' . number_format($total_withdrawals, 2), 0, 1);
        $pdf->Cell(0, 7, 'Net Flow: $' . number_format($net_flow, 2), 0, 1);
    } elseif ($report_type == 'account_balances') {
        $total_accounts = count($report_data);
        $total_balance = array_sum(array_column($report_data, 'balance'));
        $avg_balance = $total_accounts > 0 ? $total_balance / $total_accounts : 0;

        $pdf->Cell(0, 7, 'Total Accounts: ' . number_format($total_accounts), 0, 1);
        $pdf->Cell(0, 7, 'Total Balance: ' . ($report_data[0]['currency'] ?? 'USD') . ' ' . number_format($total_balance, 2), 0, 1);
        $pdf->Cell(0, 7, 'Average Balance: ' . ($report_data[0]['currency'] ?? 'USD') . ' ' . number_format($avg_balance, 2), 0, 1);
    }


    // Output the PDF
    $pdf->Output('D', str_replace(' ', '_', $report_type) . '_report_' . date('Ymd') . '.pdf');
    exit;

} else {
    die("Invalid export format specified.");
}

$conn->close();
?>