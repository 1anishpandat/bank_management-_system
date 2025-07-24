<?php
session_start();
require 'db_connect.php';

// Define the sanitize_input function
if (!function_exists('sanitize_input')) {
    function sanitize_input($data) {
        $data = trim($data);
        $data = stripslashes($data);
        $data = htmlspecialchars($data);
        return $data;
    }
}

// Authentication check
if (!isset($_SESSION['employee_id'])) {
    header("Location: bank_login.php");
    exit();
}

$transaction = null;
$error_message = '';

if (isset($_GET['id']) && !empty($_GET['id'])) {
    $transaction_id = sanitize_input($_GET['id']);

    // Prepare the query to fetch transaction details
    // ADDED 'a.balance' and 'a.currency' to the SELECT statement
    $query = "SELECT t.*, a.account_number, a.account_name, a.balance, a.currency, at.type_name as account_type,
                     c.first_name, c.last_name, c.email as customer_email, c.phone as customer_phone,
                     e.employees_first_name as emp_first_name, e.employees_last_name as emp_last_name,
                     cat.category_name, cat.category_type, cat.icon as category_icon
              FROM transactions t
              JOIN accounts a ON t.account_id = a.account_id
              JOIN account_types at ON a.account_type_id = at.type_id
              JOIN customers c ON a.user_id = c.customer_id
              LEFT JOIN categories cat ON t.category_id = cat.category_id
              LEFT JOIN employee e ON t.approved_by = e.employee_id
              WHERE t.transaction_id = ? AND c.bank_id = ?"; // Ensure transaction belongs to logged-in bank

    $stmt = $conn->prepare($query);

    if ($stmt === false) {
        error_log("Prepare failed for transaction details query: " . $conn->error);
        $error_message = "Failed to prepare the transaction details query.";
    } else {
        $loggedInBankId = $_SESSION['bank_id'];
        $stmt->bind_param("ii", $transaction_id, $loggedInBankId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $transaction = $result->fetch_assoc();
        } else {
            $error_message = "Transaction not found or you do not have permission to view it.";
        }
        $stmt->close();
    }
} else {
    $error_message = "No transaction ID provided.";
}

include 'header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaction Details - Bank Management System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .detail-item {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px dashed #e5e7eb;
        }
        .detail-item:last-child {
            border-bottom: none;
        }
        .detail-label {
            font-weight: 500;
            color: #4b5563;
        }
        .detail-value {
            color: #1f2937;
            text-align: right;
        }
    </style>
</head>
<body class="bg-gray-100">
<div class="container mx-auto px-4 py-8">
    <div class="flex justify-between items-center mb-8">
        <h1 class="text-3xl font-bold text-gray-800">Transaction Details</h1>
        <a href="transactions" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg flex items-center">
            <i class="fas fa-arrow-left mr-2"></i> Back to History
        </a>
    </div>

    <?php if ($transaction): ?>
    <div class="bg-white p-6 rounded-lg shadow-lg mb-8">
        <h2 class="text-2xl font-semibold text-gray-800 mb-6">Transaction ID: #<?= htmlspecialchars($transaction['transaction_id']) ?></h2>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-12 gap-y-4">
            <div>
                <h3 class="text-lg font-bold text-gray-700 mb-4">Transaction Information</h3>
                <div class="detail-item">
                    <span class="detail-label">Date:</span>
                    <span class="detail-value"><?= date('M d, Y H:i', strtotime($transaction['transaction_date'])) ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Type:</span>
                    <span class="detail-value">
                        <span class="px-2 py-1 text-xs font-medium rounded-full 
                            <?= (strtoupper($transaction['transaction_type']) == 'INCOME' || $transaction['category_type'] == 'INCOME') ? 'bg-green-100 text-green-800' : ((strtoupper($transaction['transaction_type']) == 'EXPENSE' || $transaction['category_type'] == 'EXPENSE') ? 'bg-red-100 text-red-800' : 'bg-blue-100 text-blue-800') ?>">
                            <?= ucfirst(strtolower($transaction['transaction_type'] ?? $transaction['category_type'] ?? 'Unknown')) ?>
                        </span>
                    </span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Category:</span>
                    <span class="detail-value">
                        <?php if (!empty($transaction['category_icon'])): ?>
                            <i class="<?= htmlspecialchars($transaction['category_icon']) ?> mr-1 text-gray-500"></i>
                        <?php endif; ?>
                        <?= htmlspecialchars($transaction['category_name'] ?? 'N/A') ?>
                    </span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Amount:</span>
                    <span class="detail-value text-xl font-bold 
                        <?= (strtoupper($transaction['transaction_type']) == 'INCOME' || $transaction['category_type'] == 'INCOME') ? 'text-green-600' : ((strtoupper($transaction['transaction_type']) == 'EXPENSE' || $transaction['category_type'] == 'EXPENSE') ? 'text-red-600' : 'text-blue-600') ?>">
                        <?= (strtoupper($transaction['transaction_type']) == 'INCOME' || $transaction['category_type'] == 'INCOME' ? '+' : '-') ?>
                        $<?= number_format($transaction['amount'], 2) ?>
                    </span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Description:</span>
                    <span class="detail-value"><?= htmlspecialchars($transaction['description'] ?? 'N/A') ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Reference Number:</span>
                    <span class="detail-value"><?= htmlspecialchars($transaction['reference_number'] ?? 'N/A') ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Status:</span>
                    <span class="detail-value">
                        <?php if($transaction['approval_status'] == 'approved'): ?>
                            <span class="px-2 py-1 text-xs font-medium rounded-full bg-green-100 text-green-800">Approved</span>
                        <?php elseif($transaction['approval_status'] == 'pending'): ?>
                            <span class="px-2 py-1 text-xs font-medium rounded-full bg-yellow-100 text-yellow-800">Pending</span>
                        <?php else: ?>
                            <span class="px-2 py-1 text-xs font-medium rounded-full bg-red-100 text-red-800">Rejected</span>
                        <?php endif; ?>
                    </span>
                </div>
                <?php if ($transaction['approved_by']): ?>
                <div class="detail-item">
                    <span class="detail-label">Approved By:</span>
                    <span class="detail-value"><?= htmlspecialchars($transaction['emp_first_name'] . ' ' . $transaction['emp_last_name']) ?></span>
                </div>
                <?php endif; ?>
            </div>

            <div>
                <h3 class="text-lg font-bold text-gray-700 mb-4">Account Information</h3>
                <div class="detail-item">
                    <span class="detail-label">Account Number:</span>
                    <span class="detail-value"><?= htmlspecialchars($transaction['account_number']) ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Account Name:</span>
                    <span class="detail-value"><?= htmlspecialchars($transaction['account_name']) ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Account Type:</span>
                    <span class="detail-value"><?= htmlspecialchars($transaction['account_type']) ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Current Balance:</span>
                    <span class="detail-value">
                        <?php 
                            // Display balance with currency, defaulting to 'USD' if not found
                            $currency = htmlspecialchars($transaction['currency'] ?? 'USD');
                            echo $currency . ' ' . number_format($transaction['balance'], 2); 
                        ?>
                    </span>
                </div>

                <h3 class="text-lg font-bold text-gray-700 mb-4 mt-6">Customer Information</h3>
                <div class="detail-item">
                    <span class="detail-label">Customer Name:</span>
                    <span class="detail-value"><?= htmlspecialchars($transaction['first_name'] . ' ' . $transaction['last_name']) ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Customer Email:</span>
                    <span class="detail-value"><?= htmlspecialchars($transaction['customer_email']) ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">Customer Phone:</span>
                    <span class="detail-value"><?= htmlspecialchars($transaction['customer_phone']) ?></span>
                </div>
            </div>
        </div>
        
        <div class="flex justify-center mt-8">
            <a href="generate_receipt?transaction_id=<?= htmlspecialchars($transaction['transaction_id']) ?>" 
               class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg flex items-center text-lg font-semibold"
               target="_blank">
                <i class="fas fa-download mr-2"></i> Download Receipt
            </a>
        </div>
    </div>
    <?php else: ?>
    <div class="bg-white p-6 rounded-lg shadow-lg">
        <p class="text-red-600 text-center text-lg"><?= htmlspecialchars($error_message) ?></p>
    </div>
    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>
</body>
</html>
<?php
$conn->close();
?>