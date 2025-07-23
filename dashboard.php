<?php
// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require 'db_connect.php';
require 'security_functions.php';

// Authentication check
if (!isset($_SESSION['employee_id'])) {
    header("Location: bank_login.php");
    exit();
}

// Get employee role for conditional display
$employee_role = $_SESSION['role'] ?? 'teller';

// Get dashboard statistics with error handling
$stats = [];
try {
    $stats['total_customers'] = $conn->query("SELECT COUNT(*) FROM customers")->fetch_row()[0] ?? 0;
    $stats['active_accounts'] = $conn->query("SELECT COUNT(*) FROM accounts WHERE is_active = 1")->fetch_row()[0] ?? 0;
    $stats['today_transactions'] = $conn->query("SELECT COUNT(*) FROM transactions WHERE DATE(transaction_date) = CURDATE()")->fetch_row()[0] ?? 0;
    $stats['total_balance'] = $conn->query("SELECT SUM(balance) FROM accounts WHERE is_active = 1")->fetch_row()[0] ?? 0;
} catch (Exception $e) {
    error_log("Dashboard stats error: " . $e->getMessage());
}

// Recent transactions with error handling
$recent_transactions = [];
try {
    $result = $conn->query("
        SELECT t.transaction_id, t.transaction_date, t.amount, t.transaction_type, 
               a.account_number, c.first_name, c.last_name
        FROM transactions t
        JOIN accounts a ON t.account_id = a.account_id
        JOIN customers c ON t.user_id = c.customer_id
        ORDER BY t.transaction_date DESC
        LIMIT 5
    ");
    if ($result) {
        $recent_transactions = $result->fetch_all(MYSQLI_ASSOC);
    }
} catch (Exception $e) {
    error_log("Recent transactions error: " . $e->getMessage());
}

include 'header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Banking Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .card-hover:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .stat-card-2 {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }
        .stat-card-3 {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }
        .stat-card-4 {
            background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
        }
    </style>
</head>
<body class="bg-gray-100">

<div class="container mx-auto p-4">
    <!-- Welcome Header -->
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold text-gray-800">Banking Dashboard</h1>
        <div class="text-sm text-gray-600 bg-white px-4 py-2 rounded-lg shadow">
            <span class="font-medium">Logged in as:</span> <?= htmlspecialchars($_SESSION['bank_name'] ?? 'Bank') ?> 
            <span class="text-blue-600">(<?= htmlspecialchars($employee_role) ?>)</span>
        </div>
    </div>
    
    <!-- Stats Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
        <div class="stat-card text-white p-6 rounded-lg shadow-lg card-hover transition-all duration-300">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-white text-sm font-medium opacity-90">Total Customers</h3>
                    <p class="text-3xl font-bold"><?= number_format($stats['total_customers']) ?></p>
                </div>
                <div class="bg-white bg-opacity-20 p-3 rounded-full">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                    </svg>
                </div>
            </div>
            <a href="customer_management" class="text-white text-sm hover:underline mt-3 inline-block opacity-90">View All →</a>
        </div>
        
        <div class="stat-card-2 text-white p-6 rounded-lg shadow-lg card-hover transition-all duration-300">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-white text-sm font-medium opacity-90">Active Accounts</h3>
                    <p class="text-3xl font-bold"><?= number_format($stats['active_accounts']) ?></p>
                </div>
                <div class="bg-white bg-opacity-20 p-3 rounded-full">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path>
                    </svg>
                </div>
            </div>
            <a href="account_management" class="text-white text-sm hover:underline mt-3 inline-block opacity-90">Manage Accounts →</a>
        </div>
        
        <div class="stat-card-3 text-white p-6 rounded-lg shadow-lg card-hover transition-all duration-300">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-white text-sm font-medium opacity-90">Today's Transactions</h3>
                    <p class="text-3xl font-bold"><?= number_format($stats['today_transactions']) ?></p>
                </div>
                <div class="bg-white bg-opacity-20 p-3 rounded-full">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
            </div>
            <a href="transaction_processing" class="text-white text-sm hover:underline mt-3 inline-block opacity-90">Process Transactions →</a>
        </div>
        
        <div class="stat-card-4 text-white p-6 rounded-lg shadow-lg card-hover transition-all duration-300">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-white text-sm font-medium opacity-90">Total Deposits</h3>
                    <p class="text-3xl font-bold">$<?= number_format($stats['total_balance'] ?? 0, 2) ?></p>
                </div>
                <div class="bg-white bg-opacity-20 p-3 rounded-full">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                    </svg>
                </div>
            </div>
            <a href="reports?report=balances" class="text-white text-sm hover:underline mt-3 inline-block opacity-90">View Report →</a>
        </div>
    </div>
    
    <!-- Recent Activity Section -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
        <!-- Recent Transactions -->
        <div class="bg-white p-6 rounded-lg shadow-lg col-span-2">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-xl font-semibold text-gray-800">Recent Transactions</h2>
                <a href="transactions" class="text-blue-600 text-sm hover:underline font-medium">View All →</a>
            </div>
            
            <?php if (!empty($recent_transactions)): ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full bg-white">
                        <thead>
                            <tr class="border-b border-gray-200">
                                <th class="py-3 px-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                <th class="py-3 px-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Account</th>
                                <th class="py-3 px-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Customer</th>
                                <th class="py-3 px-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                <th class="py-3 px-4 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($recent_transactions as $transaction): ?>
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="py-4 px-4 text-sm text-gray-900"><?= date('m/d/Y H:i', strtotime($transaction['transaction_date'])) ?></td>
                                <td class="py-4 px-4 text-sm text-gray-900 font-medium"><?= htmlspecialchars($transaction['account_number']) ?></td>
                                <td class="py-4 px-4 text-sm text-gray-900"><?= htmlspecialchars($transaction['last_name'] . ', ' . $transaction['first_name']) ?></td>
                                <td class="py-4 px-4 text-sm">
                                    <span class="px-2 py-1 text-xs font-medium rounded-full <?= $transaction['transaction_type'] == 'INCOME' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                        <?= ucfirst(strtolower($transaction['transaction_type'])) ?>
                                    </span>
                                </td>
                                <td class="py-4 px-4 text-right text-sm font-medium text-gray-900">$<?= number_format($transaction['amount'], 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-12">
                    <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                    </svg>
                    <p class="mt-2 text-sm text-gray-500">No recent transactions found</p>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="bg-white p-6 rounded-lg shadow-lg">
    <h2 class="text-xl font-semibold mb-6 text-gray-800">Quick Actions</h2>
    <div class="space-y-4">
        <a href="customer_management?action=add" class="block bg-blue-50 hover:bg-blue-100 text-blue-700 p-4 rounded-lg transition-colors border border-blue-200">
            <div class="flex items-center">
                <svg class="w-6 h-6 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path>
                </svg>
                <span class="font-medium">Add New Customer</span>
            </div>
        </a>
        
        <a href="account_management?action=open" class="block bg-green-50 hover:bg-green-100 text-green-700 p-4 rounded-lg transition-colors border border-green-200">
            <div class="flex items-center">
                <svg class="w-6 h-6 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                </svg>
                <span class="font-medium">Open New Account</span>
            </div>
        </a>
        
        <a href="transaction_processing?type=deposit" class="block bg-purple-50 hover:bg-purple-100 text-purple-700 p-4 rounded-lg transition-colors border border-purple-200">
            <div class="flex items-center">
                <svg class="w-6 h-6 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <span class="font-medium">Process transaction</span>
            </div>
        </a>
        
        <!-- New Employee Attendance Button -->
        <a href="employee_attendance" class="block bg-indigo-50 hover:bg-indigo-100 text-indigo-700 p-4 rounded-lg transition-colors border border-indigo-200">
            <div class="flex items-center">
                <svg class="w-6 h-6 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path>
                </svg>
                <span class="font-medium">Employee Attendance</span>
            </div>
        </a>
        
        <?php if ($employee_role == 'manager' || $employee_role == 'admin'): ?>
        <a href="reports.php" class="block bg-yellow-50 hover:bg-yellow-100 text-yellow-700 p-4 rounded-lg transition-colors border border-yellow-200">
            <div class="flex items-center">
                <svg class="w-6 h-6 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
                <span class="font-medium">Generate Reports</span>
            </div>
        </a>
        <?php endif; ?>
        
        <?php if ($employee_role == 'admin'): ?>
        <a href="user_management.php" class="block bg-gray-50 hover:bg-gray-100 text-gray-700 p-4 rounded-lg transition-colors border border-gray-200">
            <div class="flex items-center">
                <svg class="w-6 h-6 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                </svg>
                <span class="font-medium">System Settings</span>
            </div>
        </a>
        <?php endif; ?>
    </div>
</div>
    
    <!-- System Alerts (conditional) -->
    <?php if ($employee_role == 'admin'): ?>
    <div class="bg-white p-6 rounded-lg shadow-lg mb-8">
        <h2 class="text-xl font-semibold mb-6 text-gray-800">System Alerts</h2>
        <div class="space-y-4">
            <div class="flex items-start p-4 bg-red-50 rounded-lg border border-red-200">
                <div class="flex-shrink-0 text-red-500 mr-3">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                    </svg>
                </div>
                <div>
                    <h3 class="font-medium text-red-800">Pending Approvals</h3>
                    <p class="text-sm text-red-700 mt-1">5 transactions require manager approval</p>
                    <a href="approvals.php" class="text-sm text-red-600 hover:underline font-medium mt-2 inline-block">Review Now →</a>
                </div>
            </div>
            
            <div class="flex items-start p-4 bg-yellow-50 rounded-lg border border-yellow-200">
                <div class="flex-shrink-0 text-yellow-500 mr-3">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
                <div>
                    <h3 class="font-medium text-yellow-800">System Maintenance</h3>
                    <p class="text-sm text-yellow-700 mt-1">Scheduled maintenance on Sunday 2:00 AM - 4:00 AM</p>
                    <a href="maintenance.php" class="text-sm text-yellow-600 hover:underline font-medium mt-2 inline-block">View Details →</a>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Footer -->
    <div class="text-center text-gray-500 text-sm mt-8">
    
    </div>
</div>

</body>
</html>

<?php include 'footer.php'; ?>