<?php
session_start();
require 'db_connect.php';

// Authentication check
if (!isset($_SESSION['employee_id'])) {
    header("Location: bank_login.php");
    exit();
}

// Get logged-in employee's bank_id for security
$loggedInBankId = $_SESSION['bank_id'];

// Check if account ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: account_management.php");
    exit();
}

$account_id = (int)$_GET['id'];

// Fetch account details with security check to ensure it belongs to the logged-in bank
$account_stmt = $conn->prepare("
    SELECT a.*, t.type_name, c.first_name, c.last_name, c.customer_id, c.email, c.phone
    FROM accounts a
    JOIN account_types t ON a.account_type_id = t.type_id
    JOIN customers c ON a.user_id = c.customer_id
    WHERE a.account_id = ? AND c.bank_id = ?
");
if (!$account_stmt) {
    die("Database error: " . $conn->error);
}

$account_stmt->bind_param("ii", $account_id, $loggedInBankId);
$account_stmt->execute();
$account_result = $account_stmt->get_result();

if ($account_result->num_rows == 0) {
    // Account not found or doesn't belong to this bank
    header("Location: account_management.php");
    exit();
}

$account = $account_result->fetch_assoc();

// Fetch transaction history
$transactions_stmt = $conn->prepare("
    SELECT t.*, cat.category_name 
    FROM transactions t
    LEFT JOIN transaction_categories cat ON t.category_id = cat.category_id
    WHERE t.account_id = ?
    ORDER BY t.transaction_date DESC, t.transaction_id DESC
    LIMIT 50
");
if ($transactions_stmt) {
    $transactions_stmt->bind_param("i", $account_id);
    $transactions_stmt->execute();
    $transactions = $transactions_stmt->get_result();
} else {
    $transactions = null;
}

include 'header.php';
?>

<div class="container mx-auto p-4">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">Account Details</h1>
        <a href="account_management.php" class="bg-gray-500 hover:bg-gray-700 text-white px-4 py-2 rounded">
            Back to Accounts
        </a>
    </div>

    <div class="bg-white p-6 rounded shadow mb-8">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <h2 class="text-xl font-semibold mb-4">Account Information</h2>
                <div class="space-y-2">
                    <p><span class="font-semibold">Account Number:</span> <?= htmlspecialchars($account['account_number']) ?></p>
                    <p><span class="font-semibold">Type:</span> <?= htmlspecialchars($account['type_name']) ?></p>
                    <p><span class="font-semibold">Balance:</span> $<?= number_format($account['balance'], 2) ?></p>
                    <p><span class="font-semibold">Currency:</span> <?= htmlspecialchars($account['currency']) ?></p>
                    <p><span class="font-semibold">Status:</span> <?= $account['is_active'] ? 'Active' : 'Inactive' ?></p>
                    <p><span class="font-semibold">Opened On:</span> <?= date('M j, Y', strtotime($account['created_at'])) ?></p>
                </div>
            </div>

            <div>
                <h2 class="text-xl font-semibold mb-4">Customer Information</h2>
                <div class="space-y-2">
                    <p><span class="font-semibold">Customer ID:</span> <?= htmlspecialchars($account['customer_id']) ?></p>
                    <p><span class="font-semibold">Name:</span> <?= htmlspecialchars($account['first_name'] . ' ' . $account['last_name']) ?></p>
                    <p><span class="font-semibold">Email:</span> <?= htmlspecialchars($account['email']) ?></p>
                    <p><span class="font-semibold">Phone:</span> <?= htmlspecialchars($account['phone']) ?></p>
                </div>
            </div>
        </div>

        <?php if (isset($_SESSION['role']) && ($_SESSION['role'] == 'manager' || $_SESSION['role'] == 'admin')): ?>
            <div class="mt-6">
                <form method="POST" action="account_management.php" onsubmit="return confirm('Are you sure you want to close this account?');">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="action" value="close_account">
                    <input type="hidden" name="account_id" value="<?= $account['account_id'] ?>">
                    <input type="hidden" name="closing_notes" value="Closed via account details page">
                    
                    <button type="submit" class="bg-red-500 text-white px-4 py-2 rounded hover:bg-red-600">
                        Close Account
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <div class="bg-white p-6 rounded shadow">
        <h2 class="text-xl font-semibold mb-4">Transaction History</h2>
        
        <?php if ($transactions && $transactions->num_rows > 0): ?>
            <div class="overflow-x-auto">
                <table class="min-w-full bg-white">
                    <thead>
                        <tr>
                            <th class="py-2 px-4 border">Date</th>
                            <th class="py-2 px-4 border">Type</th>
                            <th class="py-2 px-4 border">Category</th>
                            <th class="py-2 px-4 border">Amount</th>
                            <th class="py-2 px-4 border">Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($transaction = $transactions->fetch_assoc()): ?>
                            <tr>
                                <td class="py-2 px-4 border"><?= date('M j, Y', strtotime($transaction['transaction_date'])) ?></td>
                                <td class="py-2 px-4 border"><?= htmlspecialchars($transaction['transaction_type']) ?></td>
                                <td class="py-2 px-4 border"><?= htmlspecialchars($transaction['category_name'] ?? 'N/A') ?></td>
                                <td class="py-2 px-4 border <?= $transaction['transaction_type'] == 'INCOME' ? 'text-green-600' : 'text-red-600' ?>">
                                    <?= $transaction['transaction_type'] == 'INCOME' ? '+' : '-' ?>
                                    $<?= number_format($transaction['amount'], 2) ?>
                                </td>
                                <td class="py-2 px-4 border"><?= htmlspecialchars($transaction['description']) ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="text-gray-500">No transactions found for this account.</p>
        <?php endif; ?>
    </div>
</div>

<?php include 'footer.php'; ?>