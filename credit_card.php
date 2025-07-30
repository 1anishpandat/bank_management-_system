<?php
// Start session and include files at the very top with no whitespace before
session_start();
require 'db_connect.php';

// Check if employee is logged in before any output
if (!isset($_SESSION['employee_id'])) {
    header("Location: bank_login.php");
    exit();
}

// Get employee's bank ID
$employee_id = $_SESSION['employee_id'];
$bank_id = $_SESSION['bank_id'] ?? null;
$employee_role = $_SESSION['employee_role'] ?? 'teller';

// Initialize variables
$credit_cards = [];
$customers = [];
$transactions = [];
$stats = [];
$error = '';
$success = '';

// Handle form submissions before any output
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['issue_card'])) {
            // Validate inputs
            $required_fields = ['customer_id', 'credit_limit', 'interest_rate', 'card_type'];
            foreach ($required_fields as $field) {
                if (empty($_POST[$field])) {
                    throw new Exception("All fields are required");
                }
            }

            $customer_id = (int)$_POST['customer_id'];
            $credit_limit = (float)$_POST['credit_limit'];
            $interest_rate = (float)$_POST['interest_rate'];
            $card_type = htmlspecialchars($_POST['card_type']);
            
            // Generate a random account number that doesn't exist
            do {
                $account_number = mt_rand(4000000000000000, 4999999999999999); // Visa-style number
                $check_stmt = $conn->prepare("SELECT account_id FROM accounts WHERE account_number = ?");
                if (!$check_stmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                $check_stmt->bind_param("s", $account_number);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
            } while ($check_result->num_rows > 0);
            
            // Generate expiry date (3 years from now)
            $expiry_month = str_pad(date('m'), 2, '0', STR_PAD_LEFT);
            $expiry_year = date('y') + 3;
            $expiry_date = $expiry_month . '/' . $expiry_year;
            
            // Generate CVV
            $cvv = mt_rand(100, 999);
     // In your credit card issuing code:
$stmt = $conn->prepare("
INSERT INTO accounts 
(user_id, employee_id, account_type_id, account_name, account_number, 
 balance, credit_limit, interest_rate, card_type, expiry_date, cvv,
 created_at, updated_at, is_active)
VALUES (?, ?, 6, ?, ?, 0, ?, ?, ?, ?, ?, NOW(), NOW(), 1)
");

if (!$stmt) {
throw new Exception("Prepare failed: " . $conn->error);
}

$account_name = $card_type . ' Credit Card'; // Create account name
$stmt->bind_param("iisddssss", 
$customer_id, 
$employee_id, 
$account_name,
$account_number,
$credit_limit, 
$interest_rate,
$card_type,
$expiry_date,
$cvv
);

if (!$stmt->execute()) {
throw new Exception("Failed to issue credit card: " . $stmt->error);
}
        } 
        elseif (isset($_POST['update_limit'])) {
            $account_id = (int)$_POST['account_id'];
            $new_limit = (float)$_POST['new_limit'];
            
            $stmt = $conn->prepare("
                UPDATE accounts 
                SET credit_limit = ?, updated_at = NOW()
                WHERE account_id = ? AND account_type_id = 6
            ");
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $stmt->bind_param("di", $new_limit, $account_id);
            
            if ($stmt->execute()) {
                // Skip activity logging if the table doesn't exist
                $_SESSION['success'] = "Credit limit updated successfully!";
                header("Location: credit_card.php");
                exit();
            } else {
                throw new Exception("Failed to update credit limit");
            }
        }
        elseif (isset($_POST['toggle_card_status'])) {
            $account_id = (int)$_POST['account_id'];
            $current_status = (int)$_POST['current_status'];
            $new_status = $current_status ? 0 : 1;
            $status_text = $new_status ? 'unblocked' : 'blocked';
            
            $stmt = $conn->prepare("
                UPDATE accounts 
                SET is_active = ?, updated_at = NOW()
                WHERE account_id = ? AND account_type_id = 6
            ");
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $stmt->bind_param("ii", $new_status, $account_id);
            
            if ($stmt->execute()) {
                // Skip activity logging if the table doesn't exist
                $_SESSION['success'] = "Credit card $status_text successfully!";
                header("Location: credit_card.php");
                exit();
            } else {
                throw new Exception("Failed to update card status");
            }
        }
        elseif (isset($_POST['add_transaction'])) {
            $account_id = (int)$_POST['account_id'];
            $amount = (float)$_POST['amount'];
            $description = htmlspecialchars($_POST['description']);
            $transaction_type = $_POST['transaction_type'] === 'credit' ? 'INCOME' : 'EXPENSE';
            
            // Start transaction
            $conn->begin_transaction();
            
            try {
                // Add the transaction
                $stmt = $conn->prepare("
                    INSERT INTO transactions 
                    (user_id, customer_id, account_id, category_id, transaction_type, 
                     amount, description, transaction_date, created_at, updated_at, 
                     employee_id, approval_status)
                    SELECT a.user_id, a.user_id, a.account_id, 
                           CASE WHEN ? = 'INCOME' THEN 1 ELSE 2 END, 
                           ?, ?, ?, CURDATE(), NOW(), NOW(), ?, 'approved'
                    FROM accounts a
                    WHERE a.account_id = ? AND a.account_type_id = 6
                ");
                if (!$stmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                $stmt->bind_param("ssdsii", $transaction_type, $transaction_type, $amount, 
                                 $description, $employee_id, $account_id);
                $stmt->execute();
                
                // Update account balance
                $update_stmt = $conn->prepare("
                    UPDATE accounts 
                    SET balance = balance + ? * CASE WHEN ? = 'INCOME' THEN 1 ELSE -1 END,
                        updated_at = NOW()
                    WHERE account_id = ?
                ");
                if (!$update_stmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                $update_stmt->bind_param("dsi", $amount, $transaction_type, $account_id);
                $update_stmt->execute();
                
                $conn->commit();
                $_SESSION['success'] = "Transaction added successfully!";
                header("Location: credit_card.php");
                exit();
            } catch (Exception $e) {
                $conn->rollback();
                throw new Exception("Failed to add transaction: " . $e->getMessage());
            }
        }
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
        header("Location: credit_card.php");
        exit();
    }
}

// Fetch data after handling POST requests
try {
    // Get credit card statistics for this bank
    $stats_stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_cards,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_cards,
            SUM(credit_limit) as total_credit,
            SUM(balance) as total_balance,
            AVG(interest_rate) as avg_interest_rate
        FROM accounts a
        JOIN customers c ON a.user_id = c.customer_id
        WHERE a.account_type_id = 6 AND c.bank_id = ?
    ");
    if ($stats_stmt) {
        $stats_stmt->bind_param("i", $bank_id);
        $stats_stmt->execute();
        $stats = $stats_stmt->get_result()->fetch_assoc();
    }

    // Get credit card accounts (type_id = 6 is credit card) with customer info
    $cards_stmt = $conn->prepare("
        SELECT 
            a.*, 
            c.first_name, c.last_name, c.email, c.phone,
            (SELECT COUNT(*) FROM transactions WHERE account_id = a.account_id) as transaction_count,
            (SELECT SUM(amount) FROM transactions WHERE account_id = a.account_id AND transaction_type = 'EXPENSE') as total_spent
        FROM accounts a
        JOIN customers c ON a.user_id = c.customer_id
        WHERE a.account_type_id = 6 AND c.bank_id = ?
        ORDER BY a.created_at DESC
        LIMIT 50
    ");
    if ($cards_stmt) {
        $cards_stmt->bind_param("i", $bank_id);
        $cards_stmt->execute();
        $credit_cards = $cards_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    // Get recent transactions for these cards
    if (!empty($credit_cards)) {
        $card_ids = array_column($credit_cards, 'account_id');
        $placeholders = implode(',', array_fill(0, count($card_ids), '?'));
        
        $txn_stmt = $conn->prepare("
            SELECT t.*, c.first_name, c.last_name, a.account_number
            FROM transactions t
            JOIN accounts a ON t.account_id = a.account_id
            JOIN customers c ON t.customer_id = c.customer_id
            WHERE t.account_id IN ($placeholders)
            ORDER BY t.transaction_date DESC, t.created_at DESC
            LIMIT 10
        ");
        if ($txn_stmt) {
            // Dynamic binding for variable number of card IDs
            $types = str_repeat('i', count($card_ids));
            $txn_stmt->bind_param($types, ...$card_ids);
            $txn_stmt->execute();
            $transactions = $txn_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
    }

    // Get all customers for this bank
    $cust_stmt = $conn->prepare("
        SELECT customer_id, first_name, last_name, email, phone 
        FROM customers 
        WHERE bank_id = ? AND status = 'active'
        ORDER BY first_name
    ");
    if ($cust_stmt) {
        $cust_stmt->bind_param("i", $bank_id);
        $cust_stmt->execute();
        $customers = $cust_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    // Skip activities section if the table doesn't exist
    $activities = [];

} catch (Exception $e) {
    $_SESSION['error'] = "Database error: " . $e->getMessage();
}

// Now include header after all possible redirects


?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Credit Card Management</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        
        .card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
        }
        .card:hover {
            box-shadow: 0 10px 15px rgba(0, 0, 0, 0.1);
            transform: translateY(-2px);
        }
        .card-header {
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }
        .status-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
        }
        .virtual-card {
            background: linear-gradient(135deg, #3a7bd5 0%, #00d2ff 100%);
            border-radius: 12px;
            color: white;
            perspective: 1000px;
        }
        .virtual-card-inner {
            position: relative;
            width: 100%;
            height: 100%;
            transform-style: preserve-3d;
            transition: transform 0.6s;
        }
        .virtual-card:hover .virtual-card-inner {
            transform: rotateY(180deg);
        }
        .virtual-card-front, .virtual-card-back {
            position: absolute;
            width: 100%;
            height: 100%;
            backface-visibility: hidden;
            padding: 1.5rem;
        }
        .virtual-card-back {
            transform: rotateY(180deg);
            background: linear-gradient(135deg, #2c3e50 0%, #4ca1af 100%);
        }
        .chip {
            width: 40px;
            height: 30px;
            background: linear-gradient(135deg, #f5af19 0%, #f12711 100%);
            border-radius: 5px;
        }
        .wave {
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            overflow: hidden;
            line-height: 0;
        }
        .wave svg {
            position: relative;
            display: block;
            width: calc(100% + 1.3px);
            height: 50px;
        }
        .wave .shape-fill {
            fill: #FFFFFF;
        }
        .transaction-income {
            border-left: 4px solid #10B981;
        }
        .transaction-expense {
            border-left: 4px solid #EF4444;
        }
    </style>
</head>
<?php include 'header.php'; ?>
<body class="bg-gray-50">

    <div class="flex h-screen overflow-hidden">

        
        <div class="main-content flex-1 overflow-auto">
            <div class="container mx-auto p-6">
                <div class="flex justify-between items-center mb-6">
                    <h1 class="text-3xl font-bold text-gray-800">Credit Card Management</h1>
                    <div class="flex space-x-2">
                        <button onclick="window.location.reload()" class="bg-blue-100 text-blue-600 px-3 py-1 rounded-md text-sm">
                            <i class="fas fa-sync-alt mr-1"></i> Refresh
                        </button>
                    </div>
                </div>
                
                <!-- Success/Error Messages -->
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6 flex justify-between">
                        <div>
                            <i class="fas fa-check-circle mr-2"></i>
                            <?= htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                        </div>
                        <button onclick="this.parentElement.remove()" class="text-green-700">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6 flex justify-between">
                        <div>
                            <i class="fas fa-exclamation-circle mr-2"></i>
                            <?= htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                        </div>
                        <button onclick="this.parentElement.remove()" class="text-red-700">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                <?php endif; ?>
                
                <!-- Stats Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                    <div class="card p-4">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-sm font-medium text-gray-500">Total Cards</p>
                                <h3 class="text-2xl font-bold text-gray-800"><?= $stats['total_cards'] ?? 0 ?></h3>
                            </div>
                            <div class="bg-blue-100 p-3 rounded-full text-blue-600">
                                <i class="fas fa-credit-card"></i>
                            </div>
                        </div>
                        <div class="mt-2">
                            <span class="text-sm text-gray-500">
                                <span class="text-green-500 font-medium"><?= $stats['active_cards'] ?? 0 ?></span> active
                            </span>
                        </div>
                    </div>
                    
                    <div class="card p-4">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-sm font-medium text-gray-500">Total Credit</p>
                                <h3 class="text-2xl font-bold text-gray-800">$<?= number_format($stats['total_credit'] ?? 0, 2) ?></h3>
                            </div>
                            <div class="bg-green-100 p-3 rounded-full text-green-600">
                                <i class="fas fa-wallet"></i>
                            </div>
                        </div>
                        <div class="mt-2">
                            <span class="text-sm text-gray-500">
                                Avg. limit: $<?= number_format(($stats['total_credit'] ?? 0) / max(1, ($stats['total_cards'] ?? 1)), 2) ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="card p-4">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-sm font-medium text-gray-500">Total Balance</p>
                                <h3 class="text-2xl font-bold text-gray-800">$<?= number_format($stats['total_balance'] ?? 0, 2) ?></h3>
                            </div>
                            <div class="bg-purple-100 p-3 rounded-full text-purple-600">
                                <i class="fas fa-money-bill-wave"></i>
                            </div>
                        </div>
                        <div class="mt-2">
                            <span class="text-sm text-gray-500">
                                Utilization: <?= $stats['total_credit'] ? number_format(($stats['total_balance'] / $stats['total_credit']) * 100, 2) : '0' ?>%
                            </span>
                        </div>
                    </div>
                    
                    <div class="card p-4">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-sm font-medium text-gray-500">Avg. Interest</p>
                                <h3 class="text-2xl font-bold text-gray-800"><?= number_format($stats['avg_interest_rate'] ?? 0, 2) ?>%</h3>
                            </div>
                            <div class="bg-yellow-100 p-3 rounded-full text-yellow-600">
                                <i class="fas fa-percentage"></i>
                            </div>
                        </div>
                        <div class="mt-2">
                            <span class="text-sm text-gray-500">
                                APR
                            </span>
                        </div>
                    </div>
                </div>
                
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Left Column -->
                    <div class="lg:col-span-2 space-y-6">
                        <!-- Credit Card List -->
                        <div class="card">
                            <div class="card-header p-4 bg-white border-b flex justify-between items-center">
                                <h2 class="text-lg font-semibold text-gray-800">Credit Cards</h2>
                                <div class="relative">
                                    <input type="text" id="cardSearch" placeholder="Search cards..." 
                                           class="pl-8 pr-4 py-2 border rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
                                </div>
                            </div>
                            <div class="p-4">
                                <?php if (empty($credit_cards)): ?>
                                    <div class="text-center py-8 text-gray-500">
                                        <i class="fas fa-credit-card fa-3x mb-4 text-gray-300"></i>
                                        <p>No credit cards found for this bank.</p>
                                        <button onclick="document.getElementById('issueCardModal').classList.remove('hidden')"
                                                class="mt-4 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md">
                                            <i class="fas fa-plus mr-2"></i> Issue New Card
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <div class="overflow-x-auto">
                                        <table class="min-w-full divide-y divide-gray-200" id="cardsTable">
                                            <thead class="bg-gray-50">
                                                <tr>
                                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Card</th>
                                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Customer</th>
                                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Balance</th>
                                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody class="bg-white divide-y divide-gray-200">
                                                <?php foreach ($credit_cards as $card): ?>
                                                    <tr class="hover:bg-gray-50 card-row" data-card-id="<?= $card['account_id'] ?>">
                                                        <td class="px-6 py-4 whitespace-nowrap">
                                                            <div class="flex items-center">
                                                                <div class="flex-shrink-0 h-10 w-10 rounded-full flex items-center justify-center 
                                                                    <?= $card['is_active'] ? 'bg-blue-100 text-blue-600' : 'bg-gray-100 text-gray-600' ?>">
                                                                    <i class="fas fa-credit-card"></i>
                                                                </div>
                                                                <div class="ml-4">
                                                                    <div class="text-sm font-medium text-gray-900">
                                                                        <?= htmlspecialchars($card['card_type'] ?? 'Credit Card') ?> 
                                                                        •••• <?= htmlspecialchars(substr($card['account_number'], -4)) ?>
                                                                    </div>
                                                                    <div class="text-sm text-gray-500">
                                                                        Exp: <?= htmlspecialchars($card['expiry_date'] ?? 'N/A') ?>
                                                                        | Limit: $<?= number_format($card['credit_limit'], 2) ?>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td class="px-6 py-4 whitespace-nowrap">
                                                            <div class="text-sm font-medium text-gray-900">
                                                                <?= htmlspecialchars($card['first_name'] . ' ' . $card['last_name']) ?>
                                                            </div>
                                                            <div class="text-sm text-gray-500">
                                                                <?= htmlspecialchars($card['phone']) ?>
                                                            </div>
                                                        </td>
                                                        <td class="px-6 py-4 whitespace-nowrap">
                                                            <div class="text-sm font-medium 
                                                                <?= $card['balance'] < 0 ? 'text-red-600' : 'text-gray-900' ?>">
                                                                $<?= number_format($card['balance'], 2) ?>
                                                            </div>
                                                            <div class="text-sm text-gray-500">
                                                                <?= $card['credit_limit'] ? number_format(($card['balance'] / $card['credit_limit']) * 100, 2) : '0' ?>% util.
                                                            </div>
                                                        </td>
                                                        <td class="px-6 py-4 whitespace-nowrap">
                                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                                                <?= $card['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                                                <?= $card['is_active'] ? 'Active' : 'Blocked' ?>
                                                            </span>
                                                        </td>
                                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                            <div class="flex justify-end space-x-2">
                                                                <button onclick="showCardDetails(<?= htmlspecialchars(json_encode($card)) ?>)"
                                                                        class="text-blue-600 hover:text-blue-900">
                                                                    <i class="fas fa-eye"></i>
                                                                </button>
                                                                <button onclick="showAddTransactionModal(<?= $card['account_id'] ?>)"
                                                                        class="text-green-600 hover:text-green-900">
                                                                    <i class="fas fa-exchange-alt"></i>
                                                                </button>
                                                                <?php if ($employee_role === 'manager' || $employee_role === 'admin'): ?>
                                                                    <button onclick="showEditCardModal(<?= $card['account_id'] ?>)"
                                                                            class="text-yellow-600 hover:text-yellow-900">
                                                                        <i class="fas fa-edit"></i>
                                                                    </button>
                                                                <?php endif; ?>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Recent Transactions -->
                        <div class="card">
                            <div class="card-header p-4 bg-white border-b">
                                <h2 class="text-lg font-semibold text-gray-800">Recent Transactions</h2>
                            </div>
                            <div class="p-4">
                                <?php if (empty($transactions)): ?>
                                    <div class="text-center py-8 text-gray-500">
                                        <i class="fas fa-exchange-alt fa-3x mb-4 text-gray-300"></i>
                                        <p>No recent transactions found.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="space-y-3">
                                        <?php foreach ($transactions as $txn): ?>
                                            <div class="p-3 rounded-md bg-white shadow-sm 
                                                <?= $txn['transaction_type'] === 'INCOME' ? 'transaction-income' : 'transaction-expense' ?>">
                                                <div class="flex justify-between items-center">
                                                    <div>
                                                        <div class="font-medium">
                                                            <?= htmlspecialchars($txn['description']) ?>
                                                        </div>
                                                        <div class="text-sm text-gray-500">
                                                            <?= htmlspecialchars($txn['first_name'] . ' ' . $txn['last_name']) ?> • 
                                                            •••• <?= htmlspecialchars(substr($txn['account_number'], -4)) ?>
                                                        </div>
                                                    </div>
                                                    <div class="text-right">
                                                        <div class="font-medium 
                                                            <?= $txn['transaction_type'] === 'INCOME' ? 'text-green-600' : 'text-red-600' ?>">
                                                            <?= $txn['transaction_type'] === 'INCOME' ? '+' : '-' ?>$<?= number_format($txn['amount'], 2) ?>
                                                        </div>
                                                        <div class="text-sm text-gray-500">
                                                            <?= date('M d, Y', strtotime($txn['transaction_date'])) ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="mt-4 text-center">
                                        <a href="transactions.php?type=credit_card" class="text-blue-600 hover:text-blue-800 text-sm">
                                            View all transactions <i class="fas fa-arrow-right ml-1"></i>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Right Column -->
                    <div class="space-y-6">
                        <!-- Quick Actions -->
                        <div class="card">
                            <div class="card-header p-4 bg-white border-b">
                                <h2 class="text-lg font-semibold text-gray-800">Quick Actions</h2>
                            </div>
                            <div class="p-4 space-y-3">
                                <button onclick="document.getElementById('issueCardModal').classList.remove('hidden')"
                                        class="w-full bg-blue-600 hover:bg-blue-700 text-white py-2 px-4 rounded-md flex items-center justify-center">
                                    <i class="fas fa-credit-card mr-2"></i> Issue New Card
                                </button>
                                
                                <?php if ($employee_role === 'manager' || $employee_role === 'admin'): ?>
                                    <button onclick="document.getElementById('bulkIssueModal').classList.remove('hidden')"
                                            class="w-full bg-purple-600 hover:bg-purple-700 text-white py-2 px-4 rounded-md flex items-center justify-center">
                                        <i class="fas fa-bolt mr-2"></i> Bulk Issue Cards
                                    </button>
                                    
                                    <button onclick="generateCreditReport()"
                                            class="w-full bg-green-600 hover:bg-green-700 text-white py-2 px-4 rounded-md flex items-center justify-center">
                                        <i class="fas fa-file-export mr-2"></i> Generate Report
                                    </button>
                                <?php endif; ?>
                                
                                <button onclick="showVirtualCardPreview()"
                                        class="w-full bg-gray-200 hover:bg-gray-300 text-gray-800 py-2 px-4 rounded-md flex items-center justify-center">
                                    <i class="fas fa-eye mr-2"></i> Virtual Card Preview
                                </button>
                            </div>
                        </div>
                        
                        <!-- Credit Utilization Chart -->
                        <div class="card">
                            <div class="card-header p-4 bg-white border-b">
                                <h2 class="text-lg font-semibold text-gray-800">Credit Utilization</h2>
                            </div>
                            <div class="p-4">
                                <canvas id="utilizationChart" height="200"></canvas>
                            </div>
                        </div>
                        
                        <!-- Recent Activities -->
                        <div class="card">
                            <div class="card-header p-4 bg-white border-b">
                                <h2 class="text-lg font-semibold text-gray-800">Recent Activities</h2>
                            </div>
                            <div class="p-4">
                                <?php if (empty($activities)): ?>
                                    <div class="text-center py-4 text-gray-500">
                                        <i class="fas fa-history fa-2x mb-2 text-gray-300"></i>
                                        <p>No recent activities</p>
                                    </div>
                                <?php else: ?>
                                    <div class="space-y-4">
                                        <?php foreach ($activities as $activity): ?>
                                            <div class="flex items-start">
                                                <div class="flex-shrink-0 h-10 w-10 rounded-full bg-blue-100 flex items-center justify-center text-blue-600 mr-3">
                                                    <i class="fas fa-history"></i>
                                                </div>
                                                <div class="flex-1 min-w-0">
                                                    <p class="text-sm font-medium text-gray-900">
                                                        <?= htmlspecialchars($activity['employees_first_name'] . ' ' . $activity['employees_last_name']) ?>
                                                    </p>
                                                    <p class="text-sm text-gray-500 truncate">
                                                        <?= htmlspecialchars($activity['action']) ?>
                                                    </p>
                                                    <p class="text-xs text-gray-400">
                                                        <?= date('M j, g:i a', strtotime($activity['created_at'])) ?>
                                                    </p>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="mt-4 text-center">
                                        <a href="activities.php" class="text-blue-600 hover:text-blue-800 text-sm">
                                            View all activities <i class="fas fa-arrow-right ml-1"></i>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modals -->
    <!-- Issue New Card Modal -->
    <div id="issueCardModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-1/2 lg:w-1/3 shadow-lg rounded-md bg-white">
            <div class="flex justify-between items-center border-b pb-3">
                <h3 class="text-lg font-semibold text-gray-800">Issue New Credit Card</h3>
                <button onclick="document.getElementById('issueCardModal').classList.add('hidden')"
                        class="text-gray-400 hover:text-gray-500">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" class="mt-4 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Customer</label>
                    <select name="customer_id" class="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                        <option value="">Select Customer</option>
                        <?php foreach ($customers as $customer): ?>
                            <option value="<?= htmlspecialchars($customer['customer_id']) ?>">
                                <?= htmlspecialchars($customer['first_name'] . ' ' . $customer['last_name']) ?> 
                                (<?= htmlspecialchars($customer['email']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Card Type</label>
                    <select name="card_type" class="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                        <option value="Standard">Standard</option>
                        <option value="Gold">Gold</option>
                        <option value="Platinum">Platinum</option>
                        <option value="Business">Business</option>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Credit Limit ($)</label>
                    <input type="number" name="credit_limit" min="500" step="100" 
                           class="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" 
                           placeholder="5000.00" required>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Interest Rate (%)</label>
                    <input type="number" name="interest_rate" min="0" max="30" step="0.1" 
                           class="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" 
                           placeholder="15.0" required>
                </div>
                
                <div class="flex justify-end space-x-3 pt-3 border-t">
                    <button type="button" onclick="document.getElementById('issueCardModal').classList.add('hidden')"
                            class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300">
                        Cancel
                    </button>
                    <button type="submit" name="issue_card"
                            class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                        Issue Card
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Card Details Modal -->
    <div id="cardDetailsModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-1/2 lg:w-1/3 shadow-lg rounded-md bg-white">
            <div class="flex justify-between items-center border-b pb-3">
                <h3 class="text-lg font-semibold text-gray-800">Card Details</h3>
                <button onclick="document.getElementById('cardDetailsModal').classList.add('hidden')"
                        class="text-gray-400 hover:text-gray-500">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="mt-4 space-y-4" id="cardDetailsContent">
                <!-- Content will be filled by JavaScript -->
            </div>
            <div class="flex justify-end space-x-3 pt-3 border-t">
                <button onclick="document.getElementById('cardDetailsModal').classList.add('hidden')"
                        class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300">
                    Close
                </button>
            </div>
        </div>
    </div>
    
    <!-- Add Transaction Modal -->
    <div id="addTransactionModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-1/2 lg:w-1/3 shadow-lg rounded-md bg-white">
            <div class="flex justify-between items-center border-b pb-3">
                <h3 class="text-lg font-semibold text-gray-800">Add Transaction</h3>
                <button onclick="document.getElementById('addTransactionModal').classList.add('hidden')"
                        class="text-gray-400 hover:text-gray-500">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" class="mt-4 space-y-4">
                <input type="hidden" name="account_id" id="transactionAccountId" value="">
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Transaction Type</label>
                    <div class="flex space-x-4">
                        <label class="inline-flex items-center">
                            <input type="radio" name="transaction_type" value="credit" checked 
                                   class="h-4 w-4 text-blue-600 focus:ring-blue-500">
                            <span class="ml-2 text-gray-700">Credit (Payment)</span>
                        </label>
                        <label class="inline-flex items-center">
                            <input type="radio" name="transaction_type" value="debit" 
                                   class="h-4 w-4 text-blue-600 focus:ring-blue-500">
                            <span class="ml-2 text-gray-700">Debit (Charge)</span>
                        </label>
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Amount ($)</label>
                    <input type="number" name="amount" min="0.01" step="0.01" 
                           class="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" 
                           placeholder="0.00" required>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                    <input type="text" name="description" 
                           class="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" 
                           placeholder="Transaction description" required>
                </div>
                
                <div class="flex justify-end space-x-3 pt-3 border-t">
                    <button type="button" onclick="document.getElementById('addTransactionModal').classList.add('hidden')"
                            class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300">
                        Cancel
                    </button>
                    <button type="submit" name="add_transaction"
                            class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                        Add Transaction
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Virtual Card Preview Modal -->
    <div id="virtualCardModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-96 shadow-lg rounded-md bg-white">
            <div class="flex justify-between items-center border-b pb-3">
                <h3 class="text-lg font-semibold text-gray-800">Virtual Card Preview</h3>
                <button onclick="document.getElementById('virtualCardModal').classList.add('hidden')"
                        class="text-gray-400 hover:text-gray-500">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="mt-4">
                <div class="virtual-card h-48 mb-4">
                    <div class="virtual-card-inner">
                        <!-- Front of the card -->
                        <div class="virtual-card-front flex flex-col justify-between">
                            <div class="flex justify-between items-start">
                                <div class="text-xl font-bold">BANK</div>
                                <div class="text-lg font-semibold">CREDIT</div>
                            </div>
                            
                            <div class="flex justify-between items-center">
                                <div class="chip"></div>
                                <div class="text-xs">
                                    <div>VALID THRU</div>
                                    <div id="previewExpiry">MM/YY</div>
                                </div>
                            </div>
                            
                            <div>
                                <div class="text-xl tracking-widest mb-2" id="previewCardNumber">•••• •••• •••• ••••</div>
                                <div class="flex justify-between items-center">
                                    <div class="text-sm uppercase" id="previewCardHolder">CARD HOLDER</div>
                                    <div class="text-xs">VISA</div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Back of the card -->
                        <div class="virtual-card-back flex flex-col justify-between">
                            <div class="h-8 bg-black"></div>
                            
                            <div class="flex justify-end px-4">
                                <div class="bg-white text-black px-2 py-1 rounded text-xs" id="previewCVV">•••</div>
                            </div>
                            
                            <div class="text-xs">
                                <p>This card is property of BANK. If found, please return to any branch.</p>
                                <p class="mt-1">Customer service: 1-800-BANK-HELP</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Card Type</label>
                        <select id="cardTypeSelect" class="w-full px-3 py-2 border rounded-md text-sm">
                            <option value="Standard">Standard</option>
                            <option value="Gold">Gold</option>
                            <option value="Platinum">Platinum</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Cardholder Name</label>
                        <input type="text" id="cardHolderInput" class="w-full px-3 py-2 border rounded-md text-sm" 
                               placeholder="Card Holder">
                    </div>
                </div>
                
                <button onclick="updateVirtualCardPreview()"
                        class="w-full bg-blue-600 hover:bg-blue-700 text-white py-2 px-4 rounded-md">
                    <i class="fas fa-sync-alt mr-2"></i> Refresh Preview
                </button>
            </div>
        </div>
    </div>
    
    <!-- Bulk Issue Modal (for managers/admins) -->
    <?php if ($employee_role === 'manager' || $employee_role === 'admin'): ?>
    <div id="bulkIssueModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-1/2 shadow-lg rounded-md bg-white">
            <div class="flex justify-between items-center border-b pb-3">
                <h3 class="text-lg font-semibold text-gray-800">Bulk Issue Credit Cards</h3>
                <button onclick="document.getElementById('bulkIssueModal').classList.add('hidden')"
                        class="text-gray-400 hover:text-gray-500">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="bulkIssueForm" class="mt-4 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Card Type</label>
                    <select name="card_type" class="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                        <option value="Standard">Standard</option>
                        <option value="Gold">Gold</option>
                        <option value="Platinum">Platinum</option>
                    </select>
                </div>
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Credit Limit ($)</label>
                        <input type="number" name="credit_limit" min="500" step="100" 
                               class="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" 
                               placeholder="5000.00" required>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Interest Rate (%)</label>
                        <input type="number" name="interest_rate" min="0" max="30" step="0.1" 
                               class="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" 
                               placeholder="15.0" required>
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Customers</label>
                    <select name="customer_ids[]" multiple 
                            class="w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 h-auto" 
                            size="5" required>
                        <?php foreach ($customers as $customer): ?>
                            <option value="<?= htmlspecialchars($customer['customer_id']) ?>">
                                <?= htmlspecialchars($customer['first_name'] . ' ' . $customer['last_name']) ?> 
                                (<?= htmlspecialchars($customer['email']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">Hold CTRL to select multiple customers</p>
                </div>
                
                <div class="flex justify-end space-x-3 pt-3 border-t">
                    <button type="button" onclick="document.getElementById('bulkIssueModal').classList.add('hidden')"
                            class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300">
                        Cancel
                    </button>
                    <button type="button" onclick="submitBulkIssue()"
                            class="px-4 py-2 bg-purple-600 text-white rounded-md hover:bg-purple-700">
                        Issue Cards
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
    <?php include 'footer.php'; ?>
    <script>
        // Initialize Chart
        function initUtilizationChart() {
            const ctx = document.getElementById('utilizationChart').getContext('2d');
            const utilizationData = {
                labels: ['0-10%', '10-30%', '30-50%', '50-70%', '70-90%', '90-100%'],
                datasets: [{
                    data: [12, 19, 8, 5, 3, 2], // These would come from your database in a real app
                    backgroundColor: [
                        '#10B981',
                        '#3B82F6',
                        '#F59E0B',
                        '#F97316',
                        '#EF4444',
                        '#DC2626'
                    ],
                    borderWidth: 0
                }]
            };
            
            new Chart(ctx, {
                type: 'doughnut',
                data: utilizationData,
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'bottom',
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.label + ': ' + context.raw + '% of cards';
                                }
                            }
                        }
                    },
                    cutout: '70%'
                }
            });
        }
        
        // Card search functionality
        document.getElementById('cardSearch').addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const rows = document.querySelectorAll('#cardsTable tbody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        });
        
        // Show card details modal
        function showCardDetails(card) {
            const modal = document.getElementById('cardDetailsModal');
            const content = document.getElementById('cardDetailsContent');
            
            // Format the card details
            const detailsHtml = `
                <div class="space-y-3">
                    <div class="flex justify-between">
                        <span class="text-gray-600">Card Number:</span>
                        <span class="font-medium">•••• •••• •••• ${card.account_number.slice(-4)}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Cardholder:</span>
                        <span class="font-medium">${card.first_name} ${card.last_name}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Card Type:</span>
                        <span class="font-medium">${card.card_type || 'Standard'} Card</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Expiry Date:</span>
                        <span class="font-medium">${card.expiry_date || 'MM/YY'}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Status:</span>
                        <span class="font-medium ${card.is_active ? 'text-green-600' : 'text-red-600'}">
                            ${card.is_active ? 'Active' : 'Blocked'}
                        </span>
                    </div>
                    <div class="border-t pt-3 mt-3">
                        <div class="flex justify-between">
                            <span class="text-gray-600">Credit Limit:</span>
                            <span class="font-medium">$${parseFloat(card.credit_limit).toFixed(2)}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600">Current Balance:</span>
                            <span class="font-medium ${card.balance < 0 ? 'text-red-600' : ''}">
                                $${parseFloat(card.balance).toFixed(2)}
                            </span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600">Available Credit:</span>
                            <span class="font-medium">
                                $${parseFloat(card.credit_limit - card.balance).toFixed(2)}
                            </span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600">Utilization:</span>
                            <span class="font-medium">
                                ${(card.credit_limit ? (card.balance / card.credit_limit * 100).toFixed(2) : 0)}%
                            </span>
                        </div>
                    </div>
                    <div class="border-t pt-3 mt-3">
                        <div class="flex justify-between">
                            <span class="text-gray-600">Interest Rate:</span>
                            <span class="font-medium">${parseFloat(card.interest_rate).toFixed(2)}% APR</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600">Transactions:</span>
                            <span class="font-medium">${card.transaction_count || 0}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600">Total Spent:</span>
                            <span class="font-medium">$${parseFloat(card.total_spent || 0).toFixed(2)}</span>
                        </div>
                    </div>
                    <div class="border-t pt-3 mt-3">
                        <div class="flex justify-between">
                            <span class="text-gray-600">Issued On:</span>
                            <span class="font-medium">${new Date(card.created_at).toLocaleDateString()}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600">Last Updated:</span>
                            <span class="font-medium">${new Date(card.updated_at).toLocaleDateString()}</span>
                        </div>
                    </div>
                </div>
            `;
            
            content.innerHTML = detailsHtml;
            modal.classList.remove('hidden');
        }
        
        // Show add transaction modal
        function showAddTransactionModal(accountId) {
            document.getElementById('transactionAccountId').value = accountId;
            document.getElementById('addTransactionModal').classList.remove('hidden');
        }
        
        // Show virtual card preview modal
        function showVirtualCardPreview() {
            document.getElementById('virtualCardModal').classList.remove('hidden');
            updateVirtualCardPreview();
        }
        
        // Update virtual card preview
        function updateVirtualCardPreview() {
            const cardType = document.getElementById('cardTypeSelect').value;
            const cardHolder = document.getElementById('cardHolderInput').value || 'CARD HOLDER';
            
            // Generate random card number (Visa starts with 4)
            const cardNumber = '4' + Array(15).fill(0).map(() => Math.floor(Math.random() * 9)).join('');
            
            // Format with spaces every 4 digits
            const formattedCardNumber = cardNumber.replace(/(\d{4})/g, '$1 ').trim();
            
            // Generate expiry date (3 years from now)
            const now = new Date();
            const expiryMonth = String(now.getMonth() + 1).padStart(2, '0');
            const expiryYear = String(now.getFullYear() + 3).slice(-2);
            
            // Generate random CVV
            const cvv = Math.floor(100 + Math.random() * 900);
            
            // Update the preview
            document.getElementById('previewCardNumber').textContent = formattedCardNumber;
            document.getElementById('previewExpiry').textContent = `${expiryMonth}/${expiryYear}`;
            document.getElementById('previewCardHolder').textContent = cardHolder.toUpperCase();
            document.getElementById('previewCVV').textContent = cvv;
            
            // Update card styling based on type
            const virtualCard = document.querySelector('.virtual-card');
            virtualCard.className = 'virtual-card h-48 mb-4';
            
            if (cardType === 'Gold') {
                virtualCard.classList.add('bg-gradient-to-r', 'from-yellow-600', 'to-yellow-400');
            } else if (cardType === 'Platinum') {
                virtualCard.classList.add('bg-gradient-to-r', 'from-gray-800', 'to-gray-600');
            } else {
                virtualCard.classList.add('bg-gradient-to-r', 'from-blue-600', 'to-blue-400');
            }
        }
        
        // Generate credit report
        function generateCreditReport() {
            Swal.fire({
                title: 'Generating Report',
                html: 'Please wait while we generate your credit card report...',
                timerProgressBar: true,
                didOpen: () => {
                    Swal.showLoading();
                    // Simulate report generation
                    setTimeout(() => {
                        Swal.fire({
                            title: 'Report Generated!',
                            text: 'Your credit card report has been generated successfully.',
                            icon: 'success',
                            confirmButtonText: 'Download Report'
                        });
                    }, 2000);
                }
            });
        }
        
        // Submit bulk issue form via AJAX
        function submitBulkIssue() {
            const form = document.getElementById('bulkIssueForm');
            const formData = new FormData(form);
            
            Swal.fire({
                title: 'Issuing Cards',
                html: 'Please wait while we process your bulk card issuance...',
                timerProgressBar: true,
                didOpen: () => {
                    Swal.showLoading();
                    
                    // Simulate AJAX request
                    setTimeout(() => {
                        Swal.fire({
                            title: 'Cards Issued!',
                            text: 'Your bulk card issuance has been processed successfully.',
                            icon: 'success'
                        });
                        document.getElementById('bulkIssueModal').classList.add('hidden');
                        // In a real app, you would refresh the card list here
                    }, 3000);
                }
            });
        }
        
        // Initialize when DOM is loaded
        document.addEventListener('DOMContentLoaded', function() {
            initUtilizationChart();
            
            // Add click handlers to card rows
            document.querySelectorAll('.card-row').forEach(row => {
                row.addEventListener('click', function(e) {
                    // Don't trigger if clicking on a button or link
                    if (!e.target.closest('button') && !e.target.closest('a')) {
                        const cardId = this.getAttribute('data-card-id');
                        // You would fetch the full card details here in a real app
                        console.log('View details for card ID:', cardId);
                    }
                });
            });
            
            // Close modals when clicking outside
            window.addEventListener('click', function(event) {
                const modals = ['issueCardModal', 'cardDetailsModal', 'addTransactionModal', 
                               'virtualCardModal', 'bulkIssueModal'];
                
                modals.forEach(modalId => {
                    const modal = document.getElementById(modalId);
                    if (modal && event.target === modal) {
                        modal.classList.add('hidden');
                    }
                });
            });
        });
            // Enhanced sidebar functionality with main content adjustment
    // document.addEventListener('DOMContentLoaded', function() {
    //     const sidebar = document.getElementById('sidebar');
    //     const sidebarToggle = document.getElementById('sidebarToggle');
    //     const sidebarOverlay = document.getElementById('sidebarOverlay');
    //     const mainContent = document.querySelector('.main-content');
        
    //     // Initialize sidebar state based on screen size
    //     function initializeSidebar() {
    //         if (window.innerWidth > 768) {
    //             sidebar.classList.add('active');
    //             sidebar.classList.remove('collapsed');
    //             if (sidebarOverlay) sidebarOverlay.classList.remove('active');
    //             if (mainContent) mainContent.classList.remove('sidebar-collapsed');
    //         } else {
    //             sidebar.classList.remove('active');
    //             if (sidebarOverlay) sidebarOverlay.classList.remove('active');
    //         }
    //     }
        
    //     // Initialize on load
    //     initializeSidebar();
        
    //     // Toggle sidebar
    //     if (sidebarToggle) {
    //         sidebarToggle.addEventListener('click', function(e) {
    //             e.preventDefault();
                
    //             if (window.innerWidth > 768) {
    //                 // Desktop behavior - collapse/expand sidebar
    //                 sidebar.classList.toggle('collapsed');
    //                 if (mainContent) {
    //                     mainContent.classList.toggle('sidebar-collapsed');
    //                 }
    //             } else {
    //                 // Mobile behavior - slide in/out sidebar
    //                 sidebar.classList.toggle('active');
    //                 if (sidebarOverlay) {
    //                     sidebarOverlay.classList.toggle('active');
    //                 }
    //             }
    //         });
    //     }
        
    //     // Close sidebar when clicking overlay (mobile only)
    //     if (sidebarOverlay) {
    //         sidebarOverlay.addEventListener('click', function() {
    //             sidebar.classList.remove('active');
    //             sidebarOverlay.classList.remove('active');
    //         });
    //     }
        
    //     // Close sidebar when clicking outside (enhanced)
    //     document.addEventListener('click', function(event) {
    //         if (window.innerWidth <= 768) {
    //             if (sidebarToggle && !sidebar.contains(event.target) && 
    //                 !sidebarToggle.contains(event.target) && 
    //                 sidebar.classList.contains('active')) {
    //                 sidebar.classList.remove('active');
    //                 if (sidebarOverlay) sidebarOverlay.classList.remove('active');
    //             }
    //         }
    //     });
        
    //     // Toggle dropdown menus
    //     document.querySelectorAll('.menu-dropdown > a').forEach(item => {
    //         item.addEventListener('click', function(e) {
    //             e.preventDefault();
    //             const parent = this.parentElement;
                
    //             // Toggle current dropdown
    //             parent.classList.toggle('active');
                
    //             // Close other open dropdowns
    //             document.querySelectorAll('.menu-dropdown').forEach(dropdown => {
    //                 if (dropdown !== parent) {
    //                     dropdown.classList.remove('active');
    //                 }
    //             });
    //         });
    //     });
        
    //     // Auto-close dropdowns and sidebar on mobile when navigating
    //     document.querySelectorAll('.sidebar-menu a:not(.menu-dropdown > a)').forEach(link => {
    //         link.addEventListener('click', function() {
    //             if (window.innerWidth <= 768) {
    //                 // Close dropdowns
    //                 document.querySelectorAll('.menu-dropdown').forEach(dropdown => {
    //                     dropdown.classList.remove('active');
    //                 });
    //                 // Close sidebar
    //                 sidebar.classList.remove('active');
    //                 if (sidebarOverlay) sidebarOverlay.classList.remove('active');
    //             }
    //         });
    //     });
        
    //     // Handle window resize
    //     window.addEventListener('resize', function() {
    //         initializeSidebar();
    //     });
        
    //     // Add smooth scrolling to main content when sidebar toggles
    //     if (mainContent) {
    //         const observer = new MutationObserver(function(mutations) {
    //             mutations.forEach(function(mutation) {
    //                 if (mutation.type === 'attributes' && mutation.attributeName === 'class') {
    //                     // Ensure smooth transition when classes change
    //                     mainContent.style.transition = 'margin-left 0.3s ease';
    //                 }
    //             });
    //         });
            
    //         observer.observe(mainContent, {
    //             attributes: true,
    //             attributeFilter: ['class']
    //         });
    //     }
    // });
    </script>
</body>
</html>