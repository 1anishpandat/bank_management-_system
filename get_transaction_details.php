<?php
session_start();
require 'db_connect.php';
require 'security_functions.php';

// Check if user is logged in
if (!isset($_SESSION['employee_id'])) {
    header("HTTP/1.1 403 Forbidden");
    exit("Access denied");
}

// Check if transaction ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("HTTP/1.1 400 Bad Request");
    exit("Invalid request");
}

$transaction_id = (int)$_GET['id'];

// Get transaction details
$query = "SELECT 
            t.*, 
            a.account_number, 
            a.account_name,
            u.first_name, 
            u.last_name, 
            u.email,
            e.employees_first_name as emp_first_name, 
            e.employees_last_name as emp_last_name,
            tc.category_name,
            dest_a.account_number as dest_account_number,
            dest_u.first_name as dest_first_name,
            dest_u.last_name as dest_last_name
          FROM transactions t
          JOIN accounts a ON t.account_id = a.account_id
          JOIN users u ON t.user_id = u.user_id
          LEFT JOIN employee e ON t.approved_by = e.employee_id
          LEFT JOIN transaction_categories tc ON t.category_id = tc.category_id
          LEFT JOIN accounts dest_a ON t.to_account_id = dest_a.account_id
          LEFT JOIN users dest_u ON dest_a.user_id = dest_u.user_id
          WHERE t.transaction_id = ?";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $transaction_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header("HTTP/1.1 404 Not Found");
    exit("Transaction not found");
}

$transaction = $result->fetch_assoc();

// Format the response
$status_badge = '';
switch($transaction['approval_status']) {
    case 'approved':
        $status_badge = '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Approved</span>';
        break;
    case 'pending':
        $status_badge = '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">Pending</span>';
        break;
    case 'rejected':
        $status_badge = '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">Rejected</span>';
        break;
}

$type_badge = '';
switch($transaction['transaction_type']) {
    case 'DEPOSIT':
        $type_badge = '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Deposit</span>';
        break;
    case 'WITHDRAWAL':
        $type_badge = '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">Withdrawal</span>';
        break;
    case 'TRANSFER':
        $type_badge = '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">Transfer</span>';
        break;
}

// Prepare the HTML response
$html = <<<HTML
<div class="col-span-2 mb-4">
    <div class="flex justify-between items-center">
        <h4 class="text-lg font-medium">Transaction #{$transaction['transaction_id']}</h4>
        <div>
            {$status_badge}
            {$type_badge}
        </div>
    </div>
</div>

<div class="col-span-1">
    <p class="text-sm text-gray-500">Date</p>
    <p class="font-medium">{$transaction['transaction_date']}</p>
</div>

<div class="col-span-1">
    <p class="text-sm text-gray-500">Amount</p>
    <p class="font-medium">
        {$transaction['transaction_type'] == 'DEPOSIT' ? '+' : '-'}
        \$" . number_format($transaction['amount'], 2) . "
    </p>
</div>

<div class="col-span-1">
    <p class="text-sm text-gray-500">From Account</p>
    <p class="font-medium">{$transaction['account_number']} ({$transaction['account_name']})</p>
</div>

<div class="col-span-1">
    <p class="text-sm text-gray-500">Customer</p>
    <p class="font-medium">{$transaction['first_name']} {$transaction['last_name']}</p>
</div>
HTML;

// Add destination account if this is a transfer
if ($transaction['transaction_type'] == 'TRANSFER' && $transaction['dest_account_number']) {
    $html .= <<<HTML
<div class="col-span-1">
    <p class="text-sm text-gray-500">To Account</p>
    <p class="font-medium">{$transaction['dest_account_number']}</p>
</div>

<div class="col-span-1">
    <p class="text-sm text-gray-500">Recipient</p>
    <p class="font-medium">{$transaction['dest_first_name']} {$transaction['dest_last_name']}</p>
</div>
HTML;
}

// Add category if available
if ($transaction['category_name']) {
    $html .= <<<HTML
<div class="col-span-1">
    <p class="text-sm text-gray-500">Category</p>
    <p class="font-medium">{$transaction['category_name']}</p>
</div>
HTML;
}

// Add reference number if available
if ($transaction['reference_number']) {
    $html .= <<<HTML
<div class="col-span-1">
    <p class="text-sm text-gray-500">Reference Number</p>
    <p class="font-medium">{$transaction['reference_number']}</p>
</div>
HTML;
}

// Add description if available
if ($transaction['description']) {
    $html .= <<<HTML
<div class="col-span-2">
    <p class="text-sm text-gray-500">Description</p>
    <p class="font-medium">{$transaction['description']}</p>
</div>
HTML;
}

// Add approved by information
$approved_by = $transaction['emp_first_name'] ? 
    "{$transaction['emp_first_name']} {$transaction['emp_last_name']}" : 
    "System";
$html .= <<<HTML
<div class="col-span-1">
    <p class="text-sm text-gray-500">Approved By</p>
    <p class="font-medium">{$approved_by}</p>
</div>

<div class="col-span-1">
    <p class="text-sm text-gray-500">Approval Date</p>
    <p class="font-medium">{$transaction['updated_at']}</p>
</div>
HTML;

// Output the HTML
header('Content-Type: text/html');
echo $html;
?>