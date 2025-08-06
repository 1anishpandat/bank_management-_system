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
$error_message = '';
$success_message = '';

// Handle photo upload if form was submitted
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_photo']) && isset($_GET['id']) && is_numeric($_GET['id'])) {
    $account_id = (int)$_GET['id'];
    
    // First get the customer_id associated with this account
    $stmt = $conn->prepare("SELECT user_id FROM accounts WHERE account_id = ?");
    $stmt->bind_param("i", $account_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $account = $result->fetch_assoc();
        $customer_id = $account['user_id'];
        
        // Check if file was uploaded without errors
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] == UPLOAD_ERR_OK) {
            // Verify upload
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
            $file_info = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($file_info, $_FILES['photo']['tmp_name']);
            
            if (in_array($mime_type, $allowed_types)) {
                // Get the old photo path to delete it later
                $stmt = $conn->prepare("SELECT photo_path FROM customers WHERE customer_id = ?");
                $stmt->bind_param("i", $customer_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $old_photo_path = null;
                if ($result->num_rows > 0) {
                    $row = $result->fetch_assoc();
                    $old_photo_path = $row['photo_path'];
                }
                $stmt->close();
                
                // Create upload directory if it doesn't exist
                $upload_dir = 'uploads/customer_photos/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                // Generate unique filename
                $extension = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
                $filename = 'photo_' . uniqid() . '.' . $extension;
                $destination = $upload_dir . $filename;
                
                // Move the uploaded file
                if (move_uploaded_file($_FILES['photo']['tmp_name'], $destination)) {
                    // Update database with new photo path
                    $stmt = $conn->prepare("UPDATE customers SET photo_path = ? WHERE customer_id = ?");
                    $stmt->bind_param("si", $destination, $customer_id);
                    if ($stmt->execute()) {
                        // Delete old photo file if it exists
                        if (!empty($old_photo_path) && file_exists($old_photo_path)) {
                            unlink($old_photo_path);
                        }
                        $success_message = "Photo updated successfully.";
                    } else {
                        $error_message = "Error updating photo in database: " . $stmt->error;
                        // Delete the uploaded file since we couldn't update the database
                        unlink($destination);
                    }
                    $stmt->close();
                } else {
                    $error_message = "Error moving uploaded file.";
                }
            } else {
                $error_message = "Invalid file type. Only JPG, PNG, and GIF are allowed.";
            }
        } else {
            $error_message = "Error uploading file.";
        }
    } else {
        $error_message = "Account not found.";
    }
}

// Check if account ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: account_management");
    exit();
}

$account_id = (int)$_GET['id'];

// Fetch account details with security check to ensure it belongs to the logged-in bank
$account_stmt = $conn->prepare("
    SELECT a.*, t.type_name, c.first_name, c.last_name, c.customer_id, c.email, c.phone, c.photo_path
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
    header("Location: account_management");
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
    <?php if (isset($success_message) && $success_message): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
            <?= htmlspecialchars($success_message) ?>
        </div>
    <?php endif; ?>

    <?php if (isset($error_message) && $error_message): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
            <?= htmlspecialchars($error_message) ?>
        </div>
    <?php endif; ?>

    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">Account Details</h1>
        <a href="account_management" class="bg-gray-500 hover:bg-gray-700 text-white px-4 py-2 rounded">
            Back to Accounts
        </a>
    </div>

    <div class="bg-white p-6 rounded shadow mb-8">
        <div class="flex flex-col md:flex-row gap-6">
            <!-- Customer Photo Section -->
            <div class="w-full md:w-1/4">
                <div class="border rounded-lg p-4">
                    <div class="text-center mb-4">
                        <?php if (!empty($account['photo_path'])): ?>
                            <img src="<?= htmlspecialchars($account['photo_path']) ?>" 
                                 alt="Customer Photo" 
                                 class="w-48 h-48 object-cover rounded-full mx-auto">
                        <?php else: ?>
                            <div class="w-48 h-48 bg-gray-200 rounded-full mx-auto flex items-center justify-center">
                                <span class="text-gray-500">No Photo</span>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (isset($_SESSION['role']) && ($_SESSION['role'] == 'manager' || $_SESSION['role'] == 'admin')): ?>
                        <form method="POST" enctype="multipart/form-data" class="mt-4">
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700 mb-2">Update Photo</label>
                                <input type="file" name="photo" accept="image/jpeg,image/png,image/gif" 
                                       class="block w-full text-sm text-gray-500
                                              file:mr-4 file:py-2 file:px-4
                                              file:rounded-md file:border-0
                                              file:text-sm file:font-semibold
                                              file:bg-blue-50 file:text-blue-700
                                              hover:file:bg-blue-100">
                            </div>
                            <button type="submit" name="update_photo" 
                                    class="bg-blue-500 hover:bg-blue-700 text-white px-4 py-2 rounded w-full">
                                Upload New Photo
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Account and Customer Details Section -->
            <div class="w-full md:w-3/4">
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
                        <form method="POST" action="account_management" onsubmit="return confirm('Are you sure you want to close this account?');">
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
        </div>
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