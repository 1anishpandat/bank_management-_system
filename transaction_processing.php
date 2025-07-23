<?php
// transaction_processing.php

session_start();
require 'db_connect.php';
require 'security_functions.php';

function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Authentication check
if (!isset($_SESSION['employee_id'])) {
    header("Location: bank_login.php");
    exit();
}

$employee_role = $_SESSION['role'] ?? 'teller';
$action = $_GET['action'] ?? 'list';

$employee_id = $_SESSION['employee_id']; 
$error_message = '';
$success_message = '';
$active_tab = sanitize_input($_GET['tab'] ?? 'withdrawal');

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_message = "Invalid form submission";
    } else {
        $account_number = sanitize_input($_POST['account_number']);
        $amount = filter_var(sanitize_input($_POST['amount']), FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        $description = sanitize_input($_POST['description'] ?? '');
        
        // Validate inputs
        if (empty($account_number) || empty($amount)) {
            $error_message = "Account number and amount are required!";
        } elseif (!is_numeric($amount) || $amount <= 0) {
            $error_message = "Amount must be a positive number!";
        } else {
            try {
                $conn->begin_transaction();
                
                // 1. Check if account exists and get current balance (with row locking)
                $stmt = $conn->prepare("SELECT account_id, balance, user_id FROM accounts WHERE account_number = ? FOR UPDATE");
                if ($stmt === false) {
                    throw new Exception("Database error: " . $conn->error);
                }
                
                if (!$stmt->bind_param("s", $account_number) || !$stmt->execute()) {
                    throw new Exception("Failed to retrieve account: " . $stmt->error);
                }
                
                $result = $stmt->get_result();
                
                if ($result->num_rows === 0) {
                    throw new Exception("Account not found!");
                }
                
                $account = $result->fetch_assoc();
                $current_balance = (float)$account['balance'];
                $account_id = (int)$account['account_id'];
                $user_id = (int)$account['user_id'];
                $transaction_type = sanitize_input($_POST['transaction_type']);
                
                // Process transaction based on type
                if ($transaction_type == 'withdrawal') {
                    if ($current_balance < $amount) {
                        throw new Exception("Insufficient funds!");
                    }
                    $new_balance = $current_balance - $amount;
                    $description = $description ?: 'Cash withdrawal';
                    $category_id = 2; // Withdrawal
                } else {
                    $new_balance = $current_balance + $amount;
                    $description = $description ?: 'Cash deposit';
                    $category_id = 1; // Deposit
                }
                
                // Verify category exists before proceeding
                $category_check = $conn->prepare("SELECT category_id FROM categories WHERE category_id = ?");
                $category_check->bind_param("i", $category_id);
                $category_check->execute();
                $category_check->store_result();
                
                if ($category_check->num_rows === 0) {
                    throw new Exception("Transaction category not found in system");
                }
                $category_check->close();
                
                // Update account balance
                $update_stmt = $conn->prepare("UPDATE accounts SET balance = ?, updated_at = NOW() WHERE account_id = ?");
                if ($update_stmt === false) {
                    throw new Exception("Database error: " . $conn->error);
                }
                
                if (!$update_stmt->bind_param("di", $new_balance, $account_id) || !$update_stmt->execute()) {
                    throw new Exception("Failed to update balance: " . $update_stmt->error);
                }
                
                // Record the transaction with all required fields
                // CHANGED: Now using customer_id instead of user_id to match accounts table
                $transaction_stmt = $conn->prepare("
                    INSERT INTO transactions 
                    (user_id, account_id, transaction_type, amount, description, 
                     employee_id, transaction_date, category_id, approval_status) 
                    VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, 'approved')
                ");
                
                if ($transaction_stmt === false) {
                    throw new Exception("Database error: " . $conn->error);
                }
                
                // CHANGED: Using customer_id instead of user_id in bind_param
                if (!$transaction_stmt->bind_param(
                    "iisdsii", 
                    $user_id,
                    $account_id, 
                    $transaction_type, 
                    $amount, 
                    $description, 
                    $employee_id,
                    $category_id
                ) || !$transaction_stmt->execute()) {
                    throw new Exception("Failed to record transaction: " . $transaction_stmt->error);
                }
                
                $conn->commit();
                $success_message = ucfirst($transaction_type) . " of $" . number_format($amount, 2) . " processed successfully!";
                
            } catch (Exception $e) {
                $conn->rollback();
                $error_message = "Error processing transaction: " . $e->getMessage();
                error_log("[Transaction Error] " . date('Y-m-d H:i:s') . " - " . $e->getMessage() . 
                         "\nAccount: $account_number\nAmount: $amount\nEmployee: $employee_id\n");
            }
        }
    }
}

// Generate new CSRF token for the form
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

include 'header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaction Processing</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
<div class="container mx-auto p-4">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">Transaction Processing</h1>
        <a href="dashboard" class="bg-gray-500 hover:bg-gray-700 text-white px-4 py-2 rounded">Back to Dashboard</a>
    </div>

    <?php if (!empty($success_message)): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
            <?= htmlspecialchars($success_message) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($error_message)): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
            <?= htmlspecialchars($error_message) ?>
        </div>
    <?php endif; ?>

    <div class="mb-4">
        <div class="flex border-b">
            <button id="withdrawal-tab" onclick="setActiveTab('withdrawal')" 
                class="tab-button px-4 py-2 font-medium <?= $active_tab === 'withdrawal' ? 'bg-blue-500 text-white' : 'bg-gray-200 hover:bg-gray-300' ?> rounded-t-lg">
                Withdrawal
            </button>
            <button id="deposit-tab" onclick="setActiveTab('deposit')" 
                class="tab-button px-4 py-2 font-medium <?= $active_tab === 'deposit' ? 'bg-blue-500 text-white' : 'bg-gray-200 hover:bg-gray-300' ?> rounded-t-lg">
                Deposit
            </button>
        </div>
    </div>

    <!-- Withdrawal Content -->
    <div id="withdrawal-content" class="tab-content bg-white p-6 rounded-lg shadow <?= $active_tab !== 'withdrawal' ? 'hidden' : '' ?>">
        <h2 class="text-xl font-semibold mb-4">Process Withdrawal</h2>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="transaction_type" value="withdrawal">
            <div class="grid grid-cols-1 gap-6">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Account Number</label>
                    <input type="text" name="account_number" required 
                           class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2"
                           pattern="[0-9]{10}" title="10-digit account number">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700">Amount ($)</label>
                    <input type="number" name="amount" step="0.01" min="0.01" required 
                           class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2"
                           placeholder="0.00">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700">Description (Optional)</label>
                    <input type="text" name="description" maxlength="255"
                           class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2"
                           placeholder="e.g., ATM withdrawal">
                </div>
                
                <div class="flex justify-end">
                    <button type="submit" 
                            class="bg-blue-500 hover:bg-blue-700 text-white px-4 py-2 rounded">
                        Process Withdrawal
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Deposit Content -->
    <div id="deposit-content" class="tab-content bg-white p-6 rounded-lg shadow <?= $active_tab !== 'deposit' ? 'hidden' : '' ?>">
        <h2 class="text-xl font-semibold mb-4">Process Deposit</h2>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="transaction_type" value="deposit">
            <div class="grid grid-cols-1 gap-6">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Account Number</label>
                    <input type="text" name="account_number" required 
                           class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2"
                           pattern="[0-9]{10}" title="10-digit account number">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700">Amount ($)</label>
                    <input type="number" name="amount" step="0.01" min="0.01" required 
                           class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2"
                           placeholder="0.00">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700">Description (Optional)</label>
                    <input type="text" name="description" maxlength="255"
                           class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2"
                           placeholder="e.g., Cash deposit">
                </div>
                
                <div class="flex justify-end">
                    <button type="submit" 
                            class="bg-green-500 hover:bg-green-700 text-white px-4 py-2 rounded">
                        Process Deposit
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
function setActiveTab(tab) {
    document.querySelectorAll('.tab-button').forEach(btn => {
        btn.classList.remove('bg-blue-500', 'text-white');
        btn.classList.add('bg-gray-200', 'hover:bg-gray-300');
    });
    document.getElementById(tab + '-tab').classList.add('bg-blue-500', 'text-white');
    document.getElementById(tab + '-tab').classList.remove('bg-gray-200', 'hover:bg-gray-300');
    
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.add('hidden');
    });
    document.getElementById(tab + '-content').classList.remove('hidden');
    
    // Update URL without reloading
    history.replaceState(null, null, '?tab=' + tab);
}
</script>

<?php include 'footer.php'; ?>
</body>
</html>