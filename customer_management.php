<?php
// customer_management.php
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

// Get logged-in employee's bank_id
$loggedInBankId = $_SESSION['bank_id'];
$employee_id = $_SESSION['employee_id'];
$employee_role = $_SESSION['role'] ?? 'teller';
$action = $_GET['action'] ?? 'list';

// Get account types for dropdown
$account_types = [];
$stmt_types = $conn->prepare("SELECT * FROM account_types");
$stmt_types->execute();
$result_types = $stmt_types->get_result();
if ($result_types) {
    $account_types = $result_types->fetch_all(MYSQLI_ASSOC);
}
$stmt_types->close();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if ($action == 'add') {
        // Customer personal information
        $first_name = substr(sanitize_input($_POST['first_name']), 0, 20); // Enforce 20 char limit
        $last_name = substr(sanitize_input($_POST['last_name']), 0, 20); // Enforce 20 char limit
        $email = sanitize_input($_POST['email']);
        $phone = sanitize_input($_POST['phone']);
        $address = sanitize_input($_POST['address']);
        $date_of_birth = sanitize_input($_POST['date_of_birth']);
        $id_proof_type = sanitize_input($_POST['id_proof_type']);
        $id_proof_number = sanitize_input($_POST['id_proof_number']);
        
        // Account information
        $account_type_id = sanitize_input($_POST['account_type_id']);
        $initial_deposit = sanitize_input($_POST['initial_deposit']);
        $account_name = sanitize_input($_POST['account_name']);

        $photo_path = null;

        // Handle photo upload
        if (isset($_FILES['customer_photo']) && $_FILES['customer_photo']['error'] == UPLOAD_ERR_OK) {
            $target_dir = "uploads/customer_photos/";
            if (!is_dir($target_dir)) {
                mkdir($target_dir, 0777, true);
            }
            $file_extension = pathinfo($_FILES['customer_photo']['name'], PATHINFO_EXTENSION);
            $new_file_name = uniqid('photo_', true) . '.' . $file_extension;
            $target_file = $target_dir . $new_file_name;

            if (move_uploaded_file($_FILES['customer_photo']['tmp_name'], $target_file)) {
                $photo_path = $target_file;
            } else {
                $error_message = "Error uploading photo.";
            }
        } elseif (isset($_FILES['customer_photo']) && $_FILES['customer_photo']['error'] != UPLOAD_ERR_NO_FILE) {
            $error_message = "File upload error: " . $_FILES['customer_photo']['error'];
        }

        // Start transaction
        $conn->begin_transaction();

        try {
            // Insert customer
            $stmt_customer = $conn->prepare("INSERT INTO customers 
                (bank_id, first_name, last_name, email, phone, address, date_of_birth, 
                id_proof_type, id_proof_number, photo_path, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            
            if (!$stmt_customer) {
                throw new Exception("SQL prepare error for customer: " . $conn->error);
            }
            
            $stmt_customer->bind_param("isssssssss", $loggedInBankId, $first_name, $last_name, 
                $email, $phone, $address, $date_of_birth, $id_proof_type, $id_proof_number, $photo_path);
            
            if (!$stmt_customer->execute()) {
                throw new Exception("Error adding customer: " . $stmt_customer->error);
            }
            
            $customer_id = $stmt_customer->insert_id;
            $stmt_customer->close();

            // Generate account number (simple random for demo)
            $account_number = mt_rand(1000000000, 9999999999);
            
            // Insert account
            $stmt_account = $conn->prepare("INSERT INTO accounts 
                (user_id, employee_id, account_type_id, account_name, account_number, 
                balance, currency, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, 'USD', NOW())");
            
            if (!$stmt_account) {
                throw new Exception("SQL prepare error for account: " . $conn->error);
            }

            $stmt_account->bind_param("iiisid", $customer_id, $employee_id, $account_type_id, 
                $account_name, $account_number, $initial_deposit);
            
            if (!$stmt_account->execute()) {
                throw new Exception("Error creating account: " . $stmt_account->error);
            }
            
            $account_id = $stmt_account->insert_id;
            $stmt_account->close();

            // Record initial deposit transaction
            if ($initial_deposit > 0) {
                // Corrected: user_id should be customer_id, and employee_id is also logged
                $stmt_transaction = $conn->prepare("INSERT INTO transactions 
                    (user_id, customer_id, account_id, category_id, transaction_type, 
                    amount, description, transaction_date, employee_id) 
                    VALUES (?, ?, ?, 1, 'INCOME', ?, 'Initial deposit', CURDATE(), ?)");
                
                if (!$stmt_transaction) {
                    throw new Exception("SQL prepare error for transaction: " . $conn->error);
                }

                // Bind parameters: (user_id as customer_id), (customer_id), (account_id), (amount), (employee_id)
                $stmt_transaction->bind_param("iiidi", $customer_id, $customer_id, $account_id, $initial_deposit, $employee_id);
                
                if (!$stmt_transaction->execute()) {
                    throw new Exception("Error recording transaction: " . $stmt_transaction->error);
                }
                
                $stmt_transaction->close();
            }

            // Commit transaction
            $conn->commit();
            
            $success_message = "Customer account opened successfully! Account Number: " . $account_number;
            $action = 'list';
        } catch (Exception $e) {
            $conn->rollback();
            $error_message = $e->getMessage();
        }
    }
}

// Get customers list
$customers = [];
try {
    $stmt_customers = $conn->prepare("SELECT * FROM customers WHERE bank_id = ? ORDER BY created_at DESC");
    if ($stmt_customers === false) {
        throw new Exception("SQL prepare error: " . $conn->error);
    }
    $stmt_customers->bind_param("i", $loggedInBankId);
    $stmt_customers->execute();
    $result = $stmt_customers->get_result();

    if ($result) {
        $customers = $result->fetch_all(MYSQLI_ASSOC);
    } else {
        $error_message = "Error executing customer fetch query: " . $conn->error;
    }
    $stmt_customers->close();
} catch (Exception $e) {
    $error_message = "Error fetching customers: " . $e->getMessage();
}

include 'header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body class="bg-gray-100">

<div class="container mx-auto p-4">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">Customer Account Management</h1>
        <div>
            <a href="dashboard" class="bg-gray-500 hover:bg-gray-700 text-white px-4 py-2 rounded">Back to Dashboard</a>
            <?php if ($action == 'list'): ?>
                <a href="?action=add" class="bg-blue-500 hover:bg-blue-700 text-white px-4 py-2 rounded ml-2">Open New Account</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (isset($success_message)): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
            <?= $success_message ?>
        </div>
    <?php endif; ?>

    <?php if (isset($error_message)): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
            <?= $error_message ?>
        </div>
    <?php endif; ?>

    <?php if ($action == 'add'): ?>
        <div class="bg-white p-6 rounded-lg shadow mb-6">
            <h2 class="text-xl font-semibold mb-4">Open New Bank Account</h2>
            <form method="POST" id="accountForm" enctype="multipart/form-data">
                <div class="mb-6 border-b border-gray-200 pb-4">
                    <h3 class="text-lg font-medium text-gray-900">Personal Information</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">First Name*</label>
                            <input type="text" name="first_name" required maxlength="20"
                                   class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Last Name*</label>
                            <input type="text" name="last_name" required maxlength="20"
                                   class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Date of Birth*</label>
                            <input type="date" name="date_of_birth" required 
                                   class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">ID Proof Type*</label>
                            <select name="id_proof_type" required 
                                    class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2">
                                <option value="">Select ID Type</option>
                                <option value="Passport">Passport</option>
                                <option value="Driver License">Driver License</option>
                                <option value="National ID">National ID</option>
                                <option value="PAN Card">PAN Card</option>
                                <option value="Aadhaar Card">Aadhaar Card</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">ID Proof Number*</label>
                            <input type="text" name="id_proof_number" required 
                                   class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Phone Number*</label>
                            <input type="tel" name="phone" required 
                                   class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Email Address*</label>
                            <input type="email" name="email" required 
                                   class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2">
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700">Address*</label>
                            <textarea name="address" required rows="3"
                                      class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2"></textarea>
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700">Customer Photo</label>
                            <input type="file" name="customer_photo" accept="image/*"
                                   class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                            <p class="mt-1 text-sm text-gray-500">Upload a photo of the customer (optional).</p>
                        </div>
                    </div>
                </div>

                <div class="mb-6 border-b border-gray-200 pb-4">
                    <h3 class="text-lg font-medium text-gray-900">Account Information</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Account Type*</label>
                            <select name="account_type_id" required 
                                    class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2">
                                <option value="">Select Account Type</option>
                                <?php foreach ($account_types as $type): ?>
                                    <option value="<?= $type['type_id'] ?>">
                                        <?= $type['type_name'] ?> (<?= $type['category'] ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Account Name*</label>
                            <input type="text" name="account_name" required 
                                   class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2"
                                   placeholder="e.g., John's Savings Account">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Initial Deposit (USD)*</label>
                            <input type="number" name="initial_deposit" min="0" step="0.01" required 
                                   class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2">
                        </div>
                    </div>
                </div>

                <div class="mt-6 flex justify-end">
                    <a href="?action=list" class="bg-gray-500 hover:bg-gray-700 text-white px-4 py-2 rounded mr-2">Cancel</a>
                    <button type="submit" class="bg-blue-500 hover:bg-blue-700 text-white px-4 py-2 rounded">
                        Open Account
                    </button>
                </div>
            </form>
        </div>

        <script>
            document.getElementById('accountForm').addEventListener('submit', function(e) {
                const initialDeposit = parseFloat(document.querySelector('[name="initial_deposit"]').value);
                const accountType = document.querySelector('[name="account_type_id"]').value;
                
                // Check if initial deposit meets minimum requirements
                if (accountType === '3' && initialDeposit < 1000) { // Fixed Deposit
                    e.preventDefault();
                    Swal.fire({
                        title: 'Minimum Deposit Required',
                        text: 'Fixed Deposit accounts require a minimum initial deposit of $1000',
                        icon: 'warning',
                        confirmButtonText: 'OK'
                    });
                } else if (accountType === '6' && initialDeposit < 500) { // Credit Card
                    e.preventDefault();
                    Swal.fire({
                        title: 'Minimum Deposit Required',
                        text: 'Credit Card accounts require a minimum initial deposit of $500',
                        icon: 'warning',
                        confirmButtonText: 'OK'
                    });
                }
            });
        </script>
    <?php else: ?>
        <div class="bg-white p-6 rounded-lg shadow">
            <h2 class="text-xl font-semibold mb-4">Customer Accounts</h2>
            <?php if (empty($customers)): ?>
                <p class="text-gray-500">No customer accounts found.</p>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Photo</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Customer ID</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Phone</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID Proof</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($customers as $customer): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php if (!empty($customer['photo_path'])): ?>
                                            <img src="<?= htmlspecialchars($customer['photo_path']) ?>" alt="Customer Photo" class="h-10 w-10 rounded-full object-cover">
                                        <?php else: ?>
                                            <div class="h-10 w-10 rounded-full bg-gray-200 flex items-center justify-center text-gray-500 text-xs">No Photo</div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= htmlspecialchars($customer['customer_id']) ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        <?= htmlspecialchars($customer['first_name']) . ' ' . htmlspecialchars($customer['last_name']) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= htmlspecialchars($customer['email']) ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= htmlspecialchars($customer['phone']) ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= htmlspecialchars($customer['id_proof_type'] ?? 'N/A') ?>: <?= htmlspecialchars($customer['id_proof_number']) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= htmlspecialchars($customer['created_at']) ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <a href="customer_details?id=<?= $customer['customer_id'] ?>" 
                                           class="text-blue-600 hover:text-blue-900 mr-2">View Accounts</a>
                                        <?php if ($employee_role == 'manager' || $employee_role == 'admin'): ?>
                                            <a href="?action=edit&id=<?= $customer['customer_id'] ?>" 
                                               class="text-green-600 hover:text-green-900 mr-2">Edit</a>
                                            <a href="?action=delete&id=<?= $customer['customer_id'] ?>" 
                                               class="text-red-600 hover:text-red-900" 
                                               onclick="return confirm('Are you sure you want to delete this customer and all associated accounts?')">Delete</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>
</body>
</html>