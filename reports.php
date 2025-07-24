<?php
session_start();
require 'db_connect.php';

// Strict authentication check
if (!isset($_SESSION['employee_id']) || !isset($_SESSION['bank_id'])) {
    $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
    header("Location: bank_login.php");
    exit();
}

// Get the logged-in bank ID
$loggedInBankId = (int)$_SESSION['bank_id'];

// Debug output - verify we have the correct bank ID
error_log("Current Bank ID: " . $loggedInBankId);

// Default report parameters
$report_type = $_GET['report'] ?? 'daily_transactions';
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// Validate dates
if (!strtotime($start_date) || !strtotime($end_date)) {
    die("Invalid date parameters");
}

// Generate reports
$report_data = [];
$report_title = '';

try {
    if ($report_type == 'daily_transactions') {
        $report_title = "Daily Transactions: " . date('F j, Y', strtotime($start_date)) . " to " . date('F j, Y', strtotime($end_date));
        
        $query = "SELECT t.transaction_id, t.transaction_date, t.amount, t.transaction_type, 
                         a.account_number, c.first_name, c.last_name, cat.category_name
                  FROM transactions t
                  INNER JOIN accounts a ON t.account_id = a.account_id
                  INNER JOIN customers c ON a.user_id = c.customer_id AND c.bank_id = ?
                  INNER JOIN transaction_categories cat ON t.category_id = cat.category_id
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
        
        $query = "SELECT a.account_number, at.type_name as account_type, a.balance,
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
    error_log("Report generation error: " . $e->getMessage());
    die("Error generating report. Please try again.");
}

// Debug output - verify filtered data
error_log("Report Data Count: " . count($report_data));
if (!empty($report_data)) {
    error_log("First record bank verification: " . json_encode($report_data[0]));
}

include 'header.php';
?>

<!-- Rest of your HTML remains the same -->

<!-- Rest of your HTML remains the same -->

<div class="container mx-auto p-4">
    <h1 class="text-2xl font-bold mb-6">Bank Reports - <?= htmlspecialchars($_SESSION['bank_name'] ?? 'Bank') ?></h1>
    
    <div class="bg-white p-6 rounded shadow mb-8">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div>
                <label class="block text-gray-700 mb-2">Report Type</label>
                <select name="report" class="w-full px-3 py-2 border rounded">
                    <option value="daily_transactions" <?= $report_type == 'daily_transactions' ? 'selected' : '' ?>>Daily Transactions</option>
                    <option value="cash_flow" <?= $report_type == 'cash_flow' ? 'selected' : '' ?>>Cash Flow</option>
                    <option value="account_balances" <?= $report_type == 'account_balances' ? 'selected' : '' ?>>Account Balances</option>
                </select>
            </div>
            
            <div>
                <label class="block text-gray-700 mb-2">Start Date</label>
                <input type="date" name="start_date" value="<?= $start_date ?>" class="w-full px-3 py-2 border rounded">
            </div>
            
            <div>
                <label class="block text-gray-700 mb-2">End Date</label>
                <input type="date" name="end_date" value="<?= $end_date ?>" class="w-full px-3 py-2 border rounded">
            </div>
            
            <div class="flex items-end">
                <button type="submit" class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600">
                    Generate Report
                </button>
            </div>
        </form>
        
        <div class="mt-4">
            <a href="dashboard" class="bg-gray-500 hover:bg-gray-700 text-white px-4 py-2 rounded">Back to Dashboard</a>
        </div>
    </div>
    
    <div class="bg-white p-6 rounded shadow">
        <h2 class="text-xl font-semibold mb-4"><?= $report_title ?></h2>
        
        <?php if (empty($report_data)): ?>
            <div class="text-center text-gray-500 py-8">
                <p>No data found for the selected criteria.</p>
            </div>
        <?php else: ?>
            
            <?php if ($report_type == 'daily_transactions'): ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full bg-white mx-auto"> 
                        <thead>
                            <tr class="bg-gray-100">
                                <th class="py-2 px-4 border text-left">Date</th>
                                <th class="py-2 px-4 border text-left">Account</th>
                                <th class="py-2 px-4 border text-left">Customer</th>
                                <th class="py-2 px-4 border text-left">Type</th>
                                <th class="py-2 px-4 border text-left">Category</th>
                                <th class="py-2 px-4 border text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($report_data as $row): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="py-2 px-4 border"><?= date('m/d/Y H:i', strtotime($row['transaction_date'])) ?></td>
                                <td class="py-2 px-4 border"><?= htmlspecialchars($row['account_number']) ?></td>
                                <td class="py-2 px-4 border"><?= htmlspecialchars($row['last_name'] . ', ' . $row['first_name']) ?></td>
                                <td class="py-2 px-4 border">
                                    <span class="px-2 py-1 rounded text-xs <?= $row['transaction_type'] == 'INCOME' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                        <?= ucfirst($row['transaction_type']) ?>
                                    </span>
                                </td>
                                <td class="py-2 px-4 border"><?= htmlspecialchars($row['category_name']) ?></td>
                                <td class="py-2 px-4 border text-right font-semibold">$<?= number_format($row['amount'], 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
            <?php elseif ($report_type == 'cash_flow'): ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full bg-white mx-auto"> 
                        <thead>
                            <tr class="bg-gray-100">
                                <th class="py-2 px-4 border text-left">Date</th>
                                <th class="py-2 px-4 border text-right">Total Deposits</th>
                                <th class="py-2 px-4 border text-right">Total Withdrawals</th>
                                <th class="py-2 px-4 border text-right">Net Flow</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($report_data as $row): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="py-2 px-4 border"><?= date('m/d/Y', strtotime($row['date'])) ?></td>
                                <td class="py-2 px-4 border text-right text-green-600 font-semibold">$<?= number_format($row['total_deposits'], 2) ?></td>
                                <td class="py-2 px-4 border text-right text-red-600 font-semibold">$<?= number_format($row['total_withdrawals'], 2) ?></td>
                                <td class="py-2 px-4 border text-right font-semibold <?= $row['net_flow'] >= 0 ? 'text-green-600' : 'text-red-600' ?>">
                                    $<?= number_format($row['net_flow'], 2) ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
            <?php elseif ($report_type == 'account_balances'): ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full bg-white mx-auto"> 
                        <thead>
                            <tr class="bg-gray-100">
                                <th class="py-2 px-4 border text-left">Account Number</th>
                                <th class="py-2 px-4 border text-left">Account Type</th>
                                <th class="py-2 px-4 border text-left">Customer</th>
                                <th class="py-2 px-4 border text-left">Email</th>
                                <th class="py-2 px-4 border text-left">Date Opened</th>
                                <th class="py-2 px-4 border text-right">Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($report_data as $row): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="py-2 px-4 border font-mono"><?= htmlspecialchars($row['account_number']) ?></td>
                                <td class="py-2 px-4 border"><?= ucfirst($row['account_type']) ?></td>
                                <td class="py-2 px-4 border"><?= htmlspecialchars($row['last_name'] . ', ' . $row['first_name']) ?></td>
                                <td class="py-2 px-4 border"><?= htmlspecialchars($row['email']) ?></td>
                                <td class="py-2 px-4 border"><?= date('m/d/Y', strtotime($row['date_opened'])) ?></td>
                                <td class="py-2 px-4 border text-right font-semibold">$<?= number_format($row['balance'], 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            
        <?php endif; ?>
        
        <?php if (!empty($report_data)): ?>
        <div class="mt-6 flex flex-wrap gap-3">
            <a href="export_report?format=csv&report=<?= urlencode($report_type) ?>&start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>&bank_id=<?= $loggedInBankId ?>" 
               class="bg-green-500 text-white px-4 py-2 rounded hover:bg-green-600 flex items-center transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
                Export to CSV
            </a>
            
            <a href="export_report?format=excel&report=<?= urlencode($report_type) ?>&start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>&bank_id=<?= $loggedInBankId ?>" 
               class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600 flex items-center transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
                Export to Excel
            </a>
            
            <a href="export_report?format=pdf&report=<?= urlencode($report_type) ?>&start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>&bank_id=<?= $loggedInBankId ?>" 
               class="bg-red-500 text-white px-4 py-2 rounded hover:bg-red-600 flex items-center transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                </svg>
                Export to PDF
            </a>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($report_data)): ?>
        <div class="mt-6 bg-gray-50 p-4 rounded">
            <h3 class="text-lg font-semibold mb-2">Summary</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <?php if ($report_type == 'daily_transactions'): ?>
                    <?php 
                    $total_transactions = count($report_data);
                    $total_amount = array_sum(array_column($report_data, 'amount'));
                    $income_count = count(array_filter($report_data, function($row) { return $row['transaction_type'] == 'INCOME'; }));
                    $expense_count = $total_transactions - $income_count;
                    ?>
                    <div class="text-center">
                        <p class="text-2xl font-bold text-blue-600"><?= number_format($total_transactions) ?></p>
                        <p class="text-sm text-gray-600">Total Transactions</p>
                    </div>
                    <div class="text-center">
                        <p class="text-2xl font-bold text-green-600"><?= number_format($income_count) ?></p>
                        <p class="text-sm text-gray-600">Income Transactions</p>
                    </div>
                    <div class="text-center">
                        <p class="text-2xl font-bold text-red-600"><?= number_format($expense_count) ?></p>
                        <p class="text-sm text-gray-600">Expense Transactions</p>
                    </div>
                    
                <?php elseif ($report_type == 'cash_flow'): ?>
                    <?php 
                    $total_deposits = array_sum(array_column($report_data, 'total_deposits'));
                    $total_withdrawals = array_sum(array_column($report_data, 'total_withdrawals'));
                    $net_flow = $total_deposits - $total_withdrawals;
                    ?>
                    <div class="text-center">
                        <p class="text-2xl font-bold text-green-600">$<?= number_format($total_deposits, 2) ?></p>
                        <p class="text-sm text-gray-600">Total Deposits</p>
                    </div>
                    <div class="text-center">
                        <p class="text-2xl font-bold text-red-600">$<?= number_format($total_withdrawals, 2) ?></p>
                        <p class="text-sm text-gray-600">Total Withdrawals</p>
                    </div>
                    <div class="text-center">
                        <p class="text-2xl font-bold <?= $net_flow >= 0 ? 'text-green-600' : 'text-red-600' ?>">$<?= number_format($net_flow, 2) ?></p>
                        <p class="text-sm text-gray-600">Net Flow</p>
                    </div>
                    
                <?php elseif ($report_type == 'account_balances'): ?>
                    <?php 
                    $total_accounts = count($report_data);
                    $total_balance = array_sum(array_column($report_data, 'balance'));
                    $avg_balance = $total_accounts > 0 ? $total_balance / $total_accounts : 0;
                    ?>
                    <div class="text-center">
                        <p class="text-2xl font-bold text-blue-600"><?= number_format($total_accounts) ?></p>
                        <p class="text-sm text-gray-600">Total Accounts</p>
                    </div>
                    <div class="text-center">
                        <p class="text-2xl font-bold text-green-600">$<?= number_format($total_balance, 2) ?></p>
                        <p class="text-sm text-gray-600">Total Balance</p>
                    </div>
                    <div class="text-center">
                        <p class="text-2xl font-bold text-purple-600">$<?= number_format($avg_balance, 2) ?></p>
                        <p class="text-sm text-gray-600">Average Balance</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include 'footer.php'; ?>