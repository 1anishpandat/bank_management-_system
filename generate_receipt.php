<?php
require_once 'db_connect.php'; // Include your database connection file
require('fpdf/fpdf.php'); // Include the FPDF library. Adjust path if your fpdf.php is not directly in 'fpdf/'

// Ensure no output is sent before the PDF headers
ob_end_clean();

// Get the transaction_id from the GET request
$transaction_id = isset($_GET['transaction_id']) ? intval($_GET['transaction_id']) : 0;

if ($transaction_id > 0) {
    // Fetch comprehensive transaction details from the database
    // This query is aligned with the one used in transaction_details.php for richer data
    $query = "SELECT t.*, a.account_number, a.account_name, a.balance as account_balance, a.currency, at.type_name as account_type_name,
                     c.first_name, c.last_name, c.email as customer_email, c.phone as customer_phone,
                     e.employees_first_name as emp_first_name, e.employees_last_name as emp_last_name,
                     cat.category_name, cat.category_type, cat.icon as category_icon
              FROM transactions t
              JOIN accounts a ON t.account_id = a.account_id
              JOIN account_types at ON a.account_type_id = at.type_id
              JOIN customers c ON a.user_id = c.customer_id
              LEFT JOIN categories cat ON t.category_id = cat.category_id
              LEFT JOIN employee e ON t.approved_by = e.employee_id
              WHERE t.transaction_id = ?";

    $stmt = $conn->prepare($query);

    if ($stmt === false) {
        // Log the error for debugging purposes
        error_log("Prepare failed for transaction details query in generate_receipt.php: " . $conn->error);
        echo "An error occurred while preparing the data. Please try again later.";
        exit;
    }

    $stmt->bind_param("i", $transaction_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $transaction = $result->fetch_assoc();
    $stmt->close();

    if ($transaction) {
        // Create new PDF document
        $pdf = new FPDF();
        $pdf->AddPage();

        // Set font for Title
        $pdf->SetFont('Arial', 'B', 18);
        $pdf->Cell(0, 10, 'Transaction Receipt', 0, 1, 'C');
        $pdf->Ln(10); // Line break

        // Bank/Company Information (Optional - add your bank's details here)
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(0, 5, 'Your Bank Name', 0, 1, 'L');
        $pdf->Cell(0, 5, 'Bank Address Line 1', 0, 1, 'L');
        $pdf->Cell(0, 5, 'Bank Address Line 2', 0, 1, 'L');
        $pdf->Cell(0, 5, 'Phone: (123) 456-7890 | Email: info@yourbank.com', 0, 1, 'L');
        $pdf->Ln(10);

        // Set font for details
        $pdf->SetFont('Arial', '', 12);

        // Customer Details Section
        $pdf->SetFillColor(230, 230, 230); // Light gray background
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 10, 'Customer Information', 0, 1, 'L', true);
        $pdf->SetFont('Arial', '', 12);
        $pdf->Cell(60, 7, 'Name:', 0, 0);
        $pdf->Cell(0, 7, $transaction['first_name'] . ' ' . $transaction['last_name'], 0, 1);
        $pdf->Cell(60, 7, 'Email:', 0, 0);
        $pdf->Cell(0, 7, $transaction['customer_email'], 0, 1);
        $pdf->Cell(60, 7, 'Phone:', 0, 0);
        $pdf->Cell(0, 7, $transaction['customer_phone'], 0, 1);
        $pdf->Ln(5);

        // Transaction Details Section
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 10, 'Transaction Details', 0, 1, 'L', true);
        $pdf->SetFont('Arial', '', 12);
        $pdf->Cell(60, 7, 'Transaction ID:', 0, 0);
        $pdf->Cell(0, 7, $transaction['transaction_id'], 0, 1);
        $pdf->Cell(60, 7, 'Date:', 0, 0);
        $pdf->Cell(0, 7, date('M d, Y H:i', strtotime($transaction['transaction_date'])), 0, 1);
        $pdf->Cell(60, 7, 'Type:', 0, 0);
        $pdf->Cell(0, 7, ucfirst(strtolower($transaction['transaction_type'] ?? $transaction['category_type'] ?? 'Unknown')), 0, 1);
        $pdf->Cell(60, 7, 'Description:', 0, 0);
        $pdf->MultiCell(0, 7, $transaction['description'] ?? 'N/A', 0, 'L'); // MultiCell for potentially long descriptions
        $pdf->Cell(60, 7, 'Category:', 0, 0);
        $pdf->Cell(0, 7, $transaction['category_name'] ?? 'N/A', 0, 1);
        $pdf->Cell(60, 7, 'Reference Number:', 0, 0);
        $pdf->Cell(0, 7, $transaction['reference_number'] ?? 'N/A', 0, 1);
        $pdf->Cell(60, 7, 'Status:', 0, 0);
        $pdf->Cell(0, 7, ucfirst($transaction['approval_status']), 0, 1);
        if ($transaction['approved_by']) {
            $pdf->Cell(60, 7, 'Approved By:', 0, 0);
            $pdf->Cell(0, 7, $transaction['emp_first_name'] . ' ' . $transaction['emp_last_name'], 0, 1);
        }
        $pdf->Ln(5);

        // Account Details Section
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 10, 'Account Information', 0, 1, 'L', true);
        $pdf->SetFont('Arial', '', 12);
        $pdf->Cell(60, 7, 'Account Number:', 0, 0);
        $pdf->Cell(0, 7, $transaction['account_number'], 0, 1);
        $pdf->Cell(60, 7, 'Account Name:', 0, 0);
        $pdf->Cell(0, 7, $transaction['account_name'], 0, 1);
        $pdf->Cell(60, 7, 'Account Type:', 0, 0);
        $pdf->Cell(0, 7, $transaction['account_type_name'], 0, 1);
        $pdf->Cell(60, 7, 'Account Balance:', 0, 0);
        $pdf->Cell(0, 7, ($transaction['currency'] ?? 'USD') . ' ' . number_format($transaction['account_balance'], 2), 0, 1);
        $pdf->Ln(5);

        // Transaction Amount (Highlighted)
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(0, 15, 'Amount: ' . ($transaction['currency'] ?? 'USD') . ' ' . number_format($transaction['amount'], 2), 1, 1, 'C');
        $pdf->Ln(10);

        // Footer Message
        $pdf->SetFont('Arial', 'I', 10);
        $pdf->Cell(0, 10, 'Thank you for your business. For any queries, please contact our support.', 0, 1, 'C');

        // Output the PDF for download
        // 'D' forces download, 'transaction_receipt_' . $transaction_id . '.pdf' is the filename
        $pdf->Output('D', 'transaction_receipt_' . $transaction_id . '.pdf');
        exit;

    } else {
        echo "Transaction not found.";
    }
} else {
    echo "Invalid transaction ID provided.";
}

$conn->close();
?>