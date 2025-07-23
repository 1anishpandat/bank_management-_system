<?php
// Remove the debug code and replace with this cleaned version

session_start();
require 'db_connect.php';

// Replace existing auth check with:
if (!isset($_SESSION['employee_id'])) {
    $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
    header("Location: bank_login.php");
    exit();
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Invalid form submission";
    } else {
        $action = $_POST['action'];
        
        try {
            $conn->begin_transaction();
            
            if ($action == 'open_account') {
                $customer_id = $_POST['customer_id'];
                $account_type = $_POST['account_type'];
                $initial_deposit = $_POST['initial_deposit'];
                
                // Check if customer exists
                $customer_check = $conn->prepare("SELECT customer_id FROM customers WHERE customer_id = ? AND status = 'active'");
                if (!$customer_check) {
                    throw new Exception("Database error: " . $conn->error);
                }
                
                $customer_check->bind_param("i", $customer_id);
                $customer_check->execute();
                $customer_result = $customer_check->get_result();
                
                if ($customer_result->num_rows == 0) {
                    throw new Exception("Active customer not found");
                }
                
                // Check if account type exists
                $type_check = $conn->prepare("SELECT type_id FROM account_types WHERE type_id = ?");
                if (!$type_check) {
                    throw new Exception("Database error: " . $conn->error);
                }
                
                $type_check->bind_param("i", $account_type);
                $type_check->execute();
                $type_result = $type_check->get_result();
                
                if ($type_result->num_rows == 0) {
                    throw new Exception("Account type not found");
                }
                
                // Generate account number
                $account_number = generateAccountNumber($conn);
                
                // Insert account (user_id now references customer_id due to foreign key change)
                $stmt = $conn->prepare("INSERT INTO accounts 
                    (user_id, account_type_id, account_name, account_number, balance, currency, is_active)
                    VALUES (?, ?, ?, ?, ?, 'USD', 1)");
                
                if (!$stmt) {
                    throw new Exception("Database error: " . $conn->error);
                }
                
                $account_name = "Primary Account";
                $stmt->bind_param("iissd", $customer_id, $account_type, $account_name, $account_number, $initial_deposit);
                
                if (!$stmt->execute()) {
                    throw new Exception("Failed to create account: " . $stmt->error);
                }
                
                $account_id = $conn->insert_id;
                
                // Record initial deposit transaction
                $trans_stmt = $conn->prepare("INSERT INTO transactions 
                    (user_id, account_id, category_id, transaction_type, amount, description, transaction_date)
                    VALUES (?, ?, 1, 'INCOME', ?, 'Initial deposit', CURDATE())");
                
                if ($trans_stmt) {
                    $trans_stmt->bind_param("iid", $customer_id, $account_id, $initial_deposit);
                    if (!$trans_stmt->execute()) {
                        error_log("Failed to record initial deposit transaction: " . $trans_stmt->error);
                    }
                }
                
                $message = "Account opened successfully. Account #: $account_number";
                
            } elseif ($action == 'close_account') {
                $account_id = $_POST['account_id'];
                $closing_notes = $_POST['closing_notes'];
                
                // Verify account exists and get balance
                $balance_check = $conn->prepare("SELECT balance FROM accounts WHERE account_id = ?");
                if (!$balance_check) {
                    throw new Exception("Database error: " . $conn->error);
                }
                
                $balance_check->bind_param("i", $account_id);
                $balance_check->execute();
                $balance_result = $balance_check->get_result();
                
                if ($balance_result->num_rows == 0) {
                    throw new Exception("Account not found");
                }
                
                $balance = $balance_result->fetch_assoc()['balance'];
                
                if ($balance != 0) {
                    throw new Exception("Account must have zero balance before closing");
                }
                
                // Mark account as inactive
                $stmt = $conn->prepare("UPDATE accounts SET is_active = 0, updated_at = NOW() WHERE account_id = ?");
                if (!$stmt) {
                    throw new Exception("Database error: " . $conn->error);
                }
                
                $stmt->bind_param("i", $account_id);
                
                if (!$stmt->execute()) {
                    throw new Exception("Failed to close account: " . $stmt->error);
                }
                
                $message = "Account closed successfully";
            }
            
            $conn->commit();
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Error: " . $e->getMessage();
        }
    }
}

// Fetch data for dropdowns and table
$customers = $conn->query("SELECT customer_id, first_name, last_name FROM customers WHERE status = 'active' ORDER BY last_name");
$account_types = $conn->query("SELECT type_id, type_name FROM account_types ORDER BY type_name");

// Fetch active accounts
$active_accounts = $conn->query("
    SELECT a.account_id, a.account_number, a.balance, t.type_name, c.first_name, c.last_name
    FROM accounts a
    JOIN account_types t ON a.account_type_id = t.type_id
    JOIN customers c ON a.user_id = c.customer_id
    WHERE a.is_active = 1
    ORDER BY a.account_id DESC
");

function generateAccountNumber($conn) {
    $prefix = date('Y');
    $random = mt_rand(100000, 999999);
    $account_number = $prefix . $random;
    
    // Verify uniqueness
    $check = $conn->prepare("SELECT COUNT(*) FROM accounts WHERE account_number = ?");
    if (!$check) {
        throw new Exception("Database error: " . $conn->error);
    }
    
    $check->bind_param("s", $account_number);
    $check->execute();
    $result = $check->get_result();
    $count = $result->fetch_row()[0];
    
    if ($count > 0) {
        return generateAccountNumber($conn);
    }
    
    return $account_number;
}

// Check for query errors
if (!$customers) {
    $error = "Failed to fetch customers: " . $conn->error;
}
if (!$account_types) {
    $error = "Failed to fetch account types: " . $conn->error;
}
if (!$active_accounts) {
    $error = "Failed to fetch active accounts: " . $conn->error;
}

include 'header.php';
?>


<div class="container mx-auto p-4">
    <h1 class="text-2xl font-bold mb-6">Account Management</h1>
    
    <!-- Messages -->
    <?php if ($message): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>
    
    <!-- Open Account Form -->
    <div class="bg-white p-6 rounded shadow mb-8">
   
        <h2 class="text-xl font-semibold mb-4">Open New Account</h2>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="action" value="open_account">
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-gray-700 mb-2">Customer</label>
                    <select name="customer_id" required class="w-full px-3 py-2 border rounded">
                        <option value="">Select Customer</option>
                        <?php 
                        if ($customers) {
                            $customers->data_seek(0);
                            while ($customer = $customers->fetch_assoc()): ?>
                                <option value="<?= $customer['customer_id'] ?>">
                                    <?= htmlspecialchars($customer['last_name'] . ', ' . $customer['first_name']) ?>
                                </option>
                            <?php endwhile;
                        } ?>
                    </select>
                </div>
                <div>
                    <label class="block text-gray-700 mb-2">Account Type</label>
                    <select name="account_type" required class="w-full px-3 py-2 border rounded">
                        <option value="">Select Account Type</option>
                        <?php 
                        if ($account_types) {
                            $account_types->data_seek(0);
                            while ($type = $account_types->fetch_assoc()): ?>
                                <option value="<?= $type['type_id'] ?>">
                                    <?= htmlspecialchars($type['type_name']) ?>
                                </option>
                            <?php endwhile;
                        } ?>
                    </select>
                </div>
            </div>
            
            <div class="mb-4">
                <label class="block text-gray-700 mb-2">Initial Deposit (USD)</label>
                <input type="number" name="initial_deposit" min="0" step="0.01" required class="w-full px-3 py-2 border rounded">
            </div>
            
            <button type="submit" class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600">
                Open Account
            </button>
            <a href="dashboard" class="bg-gray-500 hover:bg-gray-700 text-white px-4 py-2 rounded">Back to Dashboard</a>
        </form>
    </div>
    
    <!-- Active Accounts List -->
    <div class="bg-white p-6 rounded shadow">
        <h2 class="text-xl font-semibold mb-4">Active Accounts</h2>
        
        <div class="overflow-x-auto">
            <table class="min-w-full bg-white">
                <thead>
                    <tr>
                        <th class="py-2 px-4 border">Account #</th>
                        <th class="py-2 px-4 border">Customer</th>
                        <th class="py-2 px-4 border">Type</th>
                        <th class="py-2 px-4 border">Balance</th>
                        <th class="py-2 px-4 border">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    if ($active_accounts && $active_accounts->num_rows > 0) {
                        while ($account = $active_accounts->fetch_assoc()): ?>
                        <tr>
                            <td class="py-2 px-4 border"><?= htmlspecialchars($account['account_number']) ?></td>
                            <td class="py-2 px-4 border"><?= htmlspecialchars($account['last_name'] . ', ' . $account['first_name']) ?></td>
                            <td class="py-2 px-4 border"><?= htmlspecialchars($account['type_name']) ?></td>
                            <td class="py-2 px-4 border">$<?= number_format($account['balance'], 2) ?></td>
                            <td class="py-2 px-4 border">
                                <a href="account_details?id=<?= $account['account_id'] ?>" class="text-blue-500 hover:text-blue-700">View</a>
                                <?php if (isset($_SESSION['role']) && ($_SESSION['role'] == 'manager' || $_SESSION['role'] == 'admin')): ?>
                                    <a href="#" onclick="showCloseModal(<?= $account['account_id'] ?>)" class="text-red-500 hover:text-red-700 ml-2">Close</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile;
                    } else { ?>
                        <tr>
                            <td colspan="5" class="py-4 px-4 border text-center text-gray-500">No active accounts found</td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Close Account Modal -->
<div id="closeModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center p-4">
    <div class="bg-white rounded-lg p-6 w-full max-w-md">
        <h2 class="text-xl font-semibold mb-4">Close Account</h2>
        <form id="closeForm" method="POST">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="action" value="close_account">
            <input type="hidden" name="account_id" id="close_account_id">
            
            <div class="mb-4">
                <label class="block text-gray-700 mb-2">Closing Notes</label>
                <textarea name="closing_notes" required class="w-full px-3 py-2 border rounded"></textarea>
            </div>
            
            <div class="flex justify-end mt-4">
                <button type="button" onclick="document.getElementById('closeModal').classList.add('hidden')" class="bg-gray-500 text-white px-4 py-2 rounded mr-2">
                    Cancel
                </button>
                <button type="submit" class="bg-red-500 text-white px-4 py-2 rounded">
                    Confirm Close
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function showCloseModal(accountId) {
    document.getElementById('close_account_id').value = accountId;
    document.getElementById('closeModal').classList.remove('hidden');
}
</script>

<?php include 'footer.php'; ?>