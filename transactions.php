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

// Get logged-in employee's bank_id
$loggedInBankId = $_SESSION['bank_id'];

// Initialize filter variables
$account_filter = isset($_GET['account']) ? sanitize_input($_GET['account']) : '';
$type_filter = isset($_GET['type']) ? sanitize_input($_GET['type']) : '';
$from_date = isset($_GET['from']) ? sanitize_input($_GET['from']) : '';
$to_date = isset($_GET['to']) ? sanitize_input($_GET['to']) : '';
$customer_filter = isset($_GET['customer']) ? sanitize_input($_GET['customer']) : '';

// Build WHERE conditions for filters
$where_conditions = ["c.bank_id = ?"];
$params = [$loggedInBankId];
$param_types = 'i';

if (!empty($account_filter)) {
    $where_conditions[] = "a.account_number LIKE ?";
    $params[] = "%$account_filter%";
    $param_types .= 's';
}

if (!empty($type_filter)) {
    if ($type_filter == 'INCOME') {
        $where_conditions[] = "(t.transaction_type = 'INCOME' OR t.category_id = 1)";
    } elseif ($type_filter == 'EXPENSE') {
        $where_conditions[] = "(t.transaction_type = 'EXPENSE' OR t.category_id = 2)";
    } else {
        $where_conditions[] = "t.transaction_type = ?";
        $params[] = $type_filter;
        $param_types .= 's';
    }
}

if (!empty($from_date)) {
    $where_conditions[] = "DATE(t.transaction_date) >= ?";
    $params[] = $from_date;
    $param_types .= 's';
}

if (!empty($to_date)) {
    $where_conditions[] = "DATE(t.transaction_date) <= ?";
    $params[] = $to_date;
    $param_types .= 's';
}

if (!empty($customer_filter)) {
    $where_conditions[] = "(c.first_name LIKE ? OR c.last_name LIKE ?)";
    $params[] = "%$customer_filter%";
    $params[] = "%$customer_filter%";
    $param_types .= 'ss';
}

$where_clause = implode(' AND ', $where_conditions);

// Pagination setup
$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$start = ($page > 1) ? ($page * $limit) - $limit : 0;

// Get total number of transactions
$total_query = "SELECT COUNT(*) as total FROM transactions t
                JOIN accounts a ON t.account_id = a.account_id
                JOIN customers c ON a.user_id = c.customer_id
                WHERE $where_clause";

$stmt_total = $conn->prepare($total_query);
if ($stmt_total === false) {
    error_log("Prepare failed for total count: " . $conn->error);
    $total = 0;
} else {
    $stmt_total->bind_param($param_types, ...$params);
    $stmt_total->execute();
    $total_result = $stmt_total->get_result();
    $total = $total_result->fetch_assoc()['total'];
    $stmt_total->close();
}
$pages = ceil($total / $limit);

// Get transactions with pagination
$query = "SELECT t.*, a.account_number, at.type_name as account_type,
                 c.first_name, c.last_name, 
                 e.employees_first_name as emp_first_name, e.employees_last_name as emp_last_name,
                 cat.category_name, cat.category_type
          FROM transactions t
          JOIN accounts a ON t.account_id = a.account_id
          JOIN account_types at ON a.account_type_id = at.type_id
          JOIN customers c ON a.user_id = c.customer_id
          LEFT JOIN categories cat ON t.category_id = cat.category_id
          LEFT JOIN employee e ON t.approved_by = e.employee_id
          WHERE $where_clause
          ORDER BY t.transaction_date DESC, t.created_at DESC
          LIMIT ?, ?";

// Add pagination parameters
$pagination_params = $params;
$pagination_params[] = $start;
$pagination_params[] = $limit;
$pagination_param_types = $param_types . 'ii';

$fetched_transactions_array = [];

$stmt_transactions = $conn->prepare($query);
if ($stmt_transactions === false) {
    error_log("Prepare failed for main query: " . $conn->error);
} else {
    $stmt_transactions->bind_param($pagination_param_types, ...$pagination_params);
    $stmt_transactions->execute();
    $transactions_result = $stmt_transactions->get_result();
    
    while ($row = $transactions_result->fetch_assoc()) {
        $fetched_transactions_array[] = $row;
    }
    $transactions_result->free();
    $stmt_transactions->close();
}

// Get summary statistics
$summary_query = "SELECT 
    COUNT(*) as total_count,
    SUM(CASE WHEN (t.transaction_type = 'INCOME' OR t.category_id = 1) THEN t.amount ELSE 0 END) as total_deposits,
    SUM(CASE WHEN (t.transaction_type = 'EXPENSE' OR t.category_id = 2) THEN t.amount ELSE 0 END) as total_withdrawals
    FROM transactions t
    JOIN accounts a ON t.account_id = a.account_id
    JOIN customers c ON a.user_id = c.customer_id
    WHERE $where_clause";

$stmt_summary = $conn->prepare($summary_query);
if ($stmt_summary === false) {
    error_log("Prepare failed for summary query: " . $conn->error);
    $summary = ['total_count' => 0, 'total_deposits' => 0, 'total_withdrawals' => 0];
} else {
    $stmt_summary->bind_param($param_types, ...$params);
    $stmt_summary->execute();
    $summary = $stmt_summary->get_result()->fetch_assoc();
    $stmt_summary->close();
}

include 'header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaction History - Bank Management System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .stat-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .stat-card-2 { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .stat-card-3 { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
        .transaction-row:hover { background-color: #f8fafc; }
    </style>
</head>
<body class="bg-gray-100">
<div class="container mx-auto px-4 py-8">
    <div class="flex justify-between items-center mb-8">
        <h1 class="text-3xl font-bold text-gray-800">Transaction History</h1>
        <div class="flex space-x-4">
            <a href="transaction_processing" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center">
                <i class="fas fa-exchange-alt mr-2"></i> New Transaction
            </a>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
        <div class="stat-card text-white p-6 rounded-lg shadow-lg">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-white text-sm font-medium opacity-90">Total Transactions</h3>
                    <p class="text-3xl font-bold"><?= number_format($summary['total_count'] ?? 0) ?></p>
                </div>
                <div class="bg-white bg-opacity-20 p-3 rounded-full">
                    <i class="fas fa-list-alt text-xl"></i>
                </div>
            </div>
        </div>
        
        <div class="stat-card-2 text-white p-6 rounded-lg shadow-lg">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-white text-sm font-medium opacity-90">Total Deposits</h3>
                    <p class="text-3xl font-bold">$<?= number_format($summary['total_deposits'] ?? 0, 2) ?></p>
                </div>
                <div class="bg-white bg-opacity-20 p-3 rounded-full">
                    <i class="fas fa-arrow-down text-xl"></i>
                </div>
            </div>
        </div>
        
        <div class="stat-card-3 text-white p-6 rounded-lg shadow-lg">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-white text-sm font-medium opacity-90">Total Withdrawals</h3>
                    <p class="text-3xl font-bold">$<?= number_format($summary['total_withdrawals'] ?? 0, 2) ?></p>
                </div>
                <div class="bg-white bg-opacity-20 p-3 rounded-full">
                    <i class="fas fa-arrow-up text-xl"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="bg-white p-6 rounded-lg shadow mb-8">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Account Number</label>
                <input type="text" name="account" placeholder="Search by account" 
                    value="<?= htmlspecialchars($account_filter) ?>"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Customer Name</label>
                <input type="text" name="customer" placeholder="Search by customer" 
                    value="<?= htmlspecialchars($customer_filter) ?>"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Transaction Type</label>
                <select name="type" class="w-full px-3 py-2 border border-gray-300 rounded-md">
                    <option value="">All Types</option>
                    <option value="INCOME" <?= $type_filter === 'INCOME' ? 'selected' : '' ?>>Deposit</option>
                    <option value="EXPENSE" <?= $type_filter === 'EXPENSE' ? 'selected' : '' ?>>Withdrawal</option>
                    <option value="TRANSFER" <?= $type_filter === 'TRANSFER' ? 'selected' : '' ?>>Transfer</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Date From</label>
                <input type="date" name="from" value="<?= htmlspecialchars($from_date) ?>"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Date To</label>
                <input type="date" name="to" value="<?= htmlspecialchars($to_date) ?>"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md">
                <button type="submit" class="mt-2 w-full bg-blue-600 text-white px-4 py-2 rounded-md">
                    <i class="fas fa-search mr-2"></i>Search
                </button>
            </div>
        </form>
    </div>

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="py-3 px-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                        <th class="py-3 px-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Account</th>
                        <th class="py-3 px-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Customer</th>
                        <th class="py-3 px-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                        <th class="py-3 px-4 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                        <th class="py-3 px-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="py-3 px-4 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (!empty($fetched_transactions_array)): ?>
                        <?php foreach($fetched_transactions_array as $transaction): 
                            $display_type = $transaction['transaction_type'] ?? ($transaction['category_type'] ?? 'Unknown');
                            $is_income = (strtoupper($display_type) == 'INCOME' || $transaction['category_id'] == 1);
                        ?>
                        <tr class="transaction-row">
                            <td class="py-4 px-4 whitespace-nowrap">
                                <div class="text-sm text-gray-900"><?= date('m/d/Y', strtotime($transaction['transaction_date'])) ?></div>
                                <div class="text-sm text-gray-500"><?= date('H:i', strtotime($transaction['created_at'])) ?></div>
                            </td>
                            <td class="py-4 px-4">
                                <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($transaction['account_number']) ?></div>
                                <div class="text-sm text-gray-500"><?= htmlspecialchars($transaction['account_type']) ?></div>
                            </td>
                            <td class="py-4 px-4">
                                <div class="text-sm text-gray-900"><?= htmlspecialchars($transaction['last_name']) ?>, <?= htmlspecialchars($transaction['first_name']) ?></div>
                            </td>
                            <td class="py-4 px-4">
                                <span class="px-2 py-1 text-xs font-medium rounded-full <?= $is_income ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                    <?= ucfirst(strtolower($display_type)) ?>
                                </span>
                            </td>
                            <td class="py-4 px-4 text-right text-sm font-medium text-gray-900">
                                <?= ($is_income ? '+' : '-') ?>
                                $<?= number_format($transaction['amount'], 2) ?>
                            </td>
                            <td class="py-4 px-4">
                                <?php if($transaction['approval_status'] == 'approved'): ?>
                                    <span class="px-2 py-1 text-xs font-medium rounded-full bg-green-100 text-green-800">
                                        Approved
                                    </span>
                                <?php elseif($transaction['approval_status'] == 'pending'): ?>
                                    <span class="px-2 py-1 text-xs font-medium rounded-full bg-yellow-100 text-yellow-800">
                                        Pending
                                    </span>
                                <?php else: ?>
                                    <span class="px-2 py-1 text-xs font-medium rounded-full bg-red-100 text-red-800">
                                        Rejected
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="py-4 px-4 text-right">
                                <div class="flex justify-end space-x-2">
                                    <button onclick="showTransactionDetails(<?= $transaction['transaction_id'] ?>)" 
                                            class="text-blue-600 hover:text-blue-900 p-1 rounded-full hover:bg-blue-50">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="px-6 py-4 text-center text-sm text-gray-500">
                                No transactions found matching your criteria.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <?php if ($pages > 1): ?>
        <div class="px-6 py-4 border-t border-gray-200">
            <div class="flex items-center justify-between">
                <div class="text-sm text-gray-700">
                    Showing <span class="font-medium"><?= $start + 1 ?></span> to 
                    <span class="font-medium"><?= min($start + $limit, $total) ?></span> of 
                    <span class="font-medium"><?= $total ?></span> results
                </div>
                <div class="flex space-x-2">
                    <?php if ($page > 1): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" 
                           class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                            Previous
                        </a>
                    <?php endif; ?>
                    
                    <?php if ($page < $pages): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" 
                           class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                            Next
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function showTransactionDetails(id) {
    window.location.href = 'transaction_details?id=' + id;
}
</script>

<?php include 'footer.php'; ?>
</body>
</html>
<?php
$conn->close();
?>