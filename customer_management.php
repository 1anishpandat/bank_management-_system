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

// Get logged-in employee's bank_id and bank name
$loggedInBankId = $_SESSION['bank_id'];
$employee_id = $_SESSION['employee_id'];
$employee_role = $_SESSION['role'] ?? 'teller';
$action = $_GET['action'] ?? 'list';

// Get bank name
$bank_name = '';
$stmt_bank = $conn->prepare("SELECT bank_name FROM bank_details WHERE bank_id = ?");
$stmt_bank->bind_param("i", $loggedInBankId);
$stmt_bank->execute();
$result_bank = $stmt_bank->get_result();
if ($result_bank && $result_bank->num_rows > 0) {
    $bank = $result_bank->fetch_assoc();
    $bank_name = $bank['bank_name'];
}
$stmt_bank->close();

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
        // Initialize error array
        $errors = [];
        
        // Customer personal information validation
        $first_name = substr(sanitize_input($_POST['first_name']), 0, 20);
        if (empty($first_name)) {
            $errors['first_name'] = "First name is required";
        } elseif (!preg_match("/^[a-zA-Z-' ]*$/", $first_name)) {
            $errors['first_name'] = "Only letters and white space allowed";
        }

        $last_name = substr(sanitize_input($_POST['last_name']), 0, 20);
        if (empty($last_name)) {
            $errors['last_name'] = "Last name is required";
        } elseif (!preg_match("/^[a-zA-Z-' ]*$/", $last_name)) {
            $errors['last_name'] = "Only letters and white space allowed";
        }

        $email = sanitize_input($_POST['email']);
        if (empty($email)) {
            $errors['email'] = "Email is required";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = "Invalid email format";
        }

        $phone = sanitize_input($_POST['phone']);
        if (empty($phone)) {
            $errors['phone'] = "Phone number is required";
        } elseif (!preg_match("/^[0-9]{10,15}$/", $phone)) {
            $errors['phone'] = "Invalid phone number format (10-15 digits)";
        }

        $address = sanitize_input($_POST['address']);
        if (empty($address)) {
            $errors['address'] = "Address is required";
        } elseif (strlen($address) > 255) {
            $errors['address'] = "Address too long (max 255 characters)";
        }

        $date_of_birth = sanitize_input($_POST['date_of_birth']);
        if (empty($date_of_birth)) {
            $errors['date_of_birth'] = "Date of birth is required";
        } else {
            $dob = new DateTime($date_of_birth);
            $today = new DateTime();
            $age = $today->diff($dob)->y;
            if ($age < 18) {
                $errors['date_of_birth'] = "Customer must be at least 18 years old";
            }
        }

        $id_proof_type = sanitize_input($_POST['id_proof_type']);
        $allowed_id_types = ['Passport', 'Driver License', 'National ID', 'PAN Card', 'Aadhaar Card'];
        if (empty($id_proof_type)) {
            $errors['id_proof_type'] = "ID proof type is required";
        } elseif (!in_array($id_proof_type, $allowed_id_types)) {
            $errors['id_proof_type'] = "Invalid ID proof type";
        }

        $id_proof_number = sanitize_input($_POST['id_proof_number']);
        if (empty($id_proof_number)) {
            $errors['id_proof_number'] = "ID proof number is required";
        } elseif (strlen($id_proof_number) > 50) {
            $errors['id_proof_number'] = "ID number too long (max 50 characters)";
        }

        // Account information validation
        $account_type_id = sanitize_input($_POST['account_type_id']);
        if (empty($account_type_id)) {
            $errors['account_type_id'] = "Account type is required";
        } else {
            $valid_account_type = false;
            foreach ($account_types as $type) {
                if ($type['type_id'] == $account_type_id) {
                    $valid_account_type = true;
                    break;
                }
            }
            if (!$valid_account_type) {
                $errors['account_type_id'] = "Invalid account type selected";
            }
        }

        $initial_deposit = sanitize_input($_POST['initial_deposit']);
        if (empty($initial_deposit)) {
            $errors['initial_deposit'] = "Initial deposit is required";
        } elseif (!is_numeric($initial_deposit) || $initial_deposit < 0) {
            $errors['initial_deposit'] = "Initial deposit must be a positive number";
        } elseif ($account_type_id == 3 && $initial_deposit < 1000) {
            $errors['initial_deposit'] = "Fixed Deposit requires minimum $1000";
        } elseif ($account_type_id == 6 && $initial_deposit < 500) {
            $errors['initial_deposit'] = "Credit Card requires minimum $500";
        }

        $account_name = sanitize_input($_POST['account_name']);
        if (empty($account_name)) {
            $errors['account_name'] = "Account name is required";
        } elseif (strlen($account_name) > 50) {
            $errors['account_name'] = "Account name too long (max 50 characters)";
        }

        $photo_path = null;
        // Handle photo upload validation
        if (isset($_FILES['customer_photo']) && $_FILES['customer_photo']['error'] != UPLOAD_ERR_NO_FILE) {
            if ($_FILES['customer_photo']['error'] == UPLOAD_ERR_OK) {
                $target_dir = "uploads/customer_photos/";
                if (!is_dir($target_dir)) {
                    mkdir($target_dir, 0777, true);
                }
                
                // Check file type
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
                $file_type = $_FILES['customer_photo']['type'];
                if (!in_array($file_type, $allowed_types)) {
                    $errors['customer_photo'] = "Only JPG, PNG, and GIF files are allowed";
                }
                
                // Check file size (max 2MB)
                $max_size = 2 * 1024 * 1024; // 2MB
                if ($_FILES['customer_photo']['size'] > $max_size) {
                    $errors['customer_photo'] = "File size must be less than 2MB";
                }
                
                if (!isset($errors['customer_photo'])) {
                    $file_extension = pathinfo($_FILES['customer_photo']['name'], PATHINFO_EXTENSION);
                    $new_file_name = uniqid('photo_', true) . '.' . $file_extension;
                    $target_file = $target_dir . $new_file_name;

                    if (move_uploaded_file($_FILES['customer_photo']['tmp_name'], $target_file)) {
                        $photo_path = $target_file;
                    } else {
                        $errors['customer_photo'] = "Error uploading photo";
                    }
                }
            } else {
                $errors['customer_photo'] = "File upload error: " . $_FILES['customer_photo']['error'];
            }
        }

        // If no validation errors, proceed with database operations
        if (empty($errors)) {
            // Start transaction
            $conn->begin_transaction();

            try {
                // Insert customer with bank_id (removed employee_id from customers table)
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
                
                // Insert account with bank_name and employee_id
                $stmt_account = $conn->prepare("INSERT INTO accounts 
                    (user_id, employee_id, account_type_id, account_name, bank_name, account_number, 
                    balance, currency, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'USD', NOW())");
                
                if (!$stmt_account) {
                    throw new Exception("SQL prepare error for account: " . $conn->error);
                }

                $stmt_account->bind_param("iiisssd", $customer_id, $employee_id, $account_type_id, 
                    $account_name, $bank_name, $account_number, $initial_deposit);
                
                if (!$stmt_account->execute()) {
                    throw new Exception("Error creating account: " . $stmt_account->error);
                }
                
                $account_id = $stmt_account->insert_id;
                $stmt_account->close();

                // Record initial deposit transaction
                if ($initial_deposit > 0) {
                    $stmt_transaction = $conn->prepare("INSERT INTO transactions 
                        (user_id, customer_id, account_id, category_id, transaction_type, 
                        amount, description, transaction_date, employee_id) 
                        VALUES (?, ?, ?, 1, 'INCOME', ?, 'Initial deposit', CURDATE(), ?)");
                    
                    if (!$stmt_transaction) {
                        throw new Exception("SQL prepare error for transaction: " . $conn->error);
                    }

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
        } else {
            $error_message = "Please correct the following errors:";
        }
    }
}

// Get customers list with employee and bank information
$customers = [];
try {
    $stmt_customers = $conn->prepare("
        SELECT c.*, 
               e.employees_first_name, 
               e.employees_last_name, 
               b.bank_name 
        FROM customers c
        LEFT JOIN accounts a ON c.customer_id = a.user_id
        LEFT JOIN employee e ON a.employee_id = e.employee_id
        LEFT JOIN bank_details b ON c.bank_id = b.bank_id
        WHERE c.bank_id = ? 
        GROUP BY c.customer_id
        ORDER BY c.created_at DESC
    ");
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
            <?php if (!empty($errors)): ?>
                <ul class="mt-2 list-disc list-inside">
                    <?php foreach ($errors as $error): ?>
                        <li><?= $error ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
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
                                   class="mt-1 block w-full border <?= isset($errors['first_name']) ? 'border-red-500' : 'border-gray-300' ?> rounded-md px-3 py-2"
                                   value="<?= isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : '' ?>">
                            <?php if (isset($errors['first_name'])): ?>
                                <p class="mt-1 text-sm text-red-600"><?= $errors['first_name'] ?></p>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Last Name*</label>
                            <input type="text" name="last_name" required maxlength="20"
                                   class="mt-1 block w-full border <?= isset($errors['last_name']) ? 'border-red-500' : 'border-gray-300' ?> rounded-md px-3 py-2"
                                   value="<?= isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : '' ?>">
                            <?php if (isset($errors['last_name'])): ?>
                                <p class="mt-1 text-sm text-red-600"><?= $errors['last_name'] ?></p>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Date of Birth*</label>
                            <input type="date" name="date_of_birth" required 
                                   class="mt-1 block w-full border <?= isset($errors['date_of_birth']) ? 'border-red-500' : 'border-gray-300' ?> rounded-md px-3 py-2"
                                   value="<?= isset($_POST['date_of_birth']) ? htmlspecialchars($_POST['date_of_birth']) : '' ?>">
                            <?php if (isset($errors['date_of_birth'])): ?>
                                <p class="mt-1 text-sm text-red-600"><?= $errors['date_of_birth'] ?></p>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">ID Proof Type*</label>
                            <select name="id_proof_type" required 
                                    class="mt-1 block w-full border <?= isset($errors['id_proof_type']) ? 'border-red-500' : 'border-gray-300' ?> rounded-md px-3 py-2">
                                <option value="">Select ID Type</option>
                                <option value="Passport" <?= (isset($_POST['id_proof_type']) && $_POST['id_proof_type'] == 'Passport') ? 'selected' : '' ?>>Passport</option>
                                <option value="Driver License" <?= (isset($_POST['id_proof_type']) && $_POST['id_proof_type'] == 'Driver License') ? 'selected' : '' ?>>Driver License</option>
                                <option value="National ID" <?= (isset($_POST['id_proof_type']) && $_POST['id_proof_type'] == 'National ID') ? 'selected' : '' ?>>National ID</option>
                                <option value="PAN Card" <?= (isset($_POST['id_proof_type']) && $_POST['id_proof_type'] == 'PAN Card') ? 'selected' : '' ?>>PAN Card</option>
                                <option value="Aadhaar Card" <?= (isset($_POST['id_proof_type']) && $_POST['id_proof_type'] == 'Aadhaar Card') ? 'selected' : '' ?>>Aadhaar Card</option>
                            </select>
                            <?php if (isset($errors['id_proof_type'])): ?>
                                <p class="mt-1 text-sm text-red-600"><?= $errors['id_proof_type'] ?></p>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">ID Proof Number*</label>
                            <input type="text" name="id_proof_number" required 
                                   class="mt-1 block w-full border <?= isset($errors['id_proof_number']) ? 'border-red-500' : 'border-gray-300' ?> rounded-md px-3 py-2"
                                   value="<?= isset($_POST['id_proof_number']) ? htmlspecialchars($_POST['id_proof_number']) : '' ?>">
                            <?php if (isset($errors['id_proof_number'])): ?>
                                <p class="mt-1 text-sm text-red-600"><?= $errors['id_proof_number'] ?></p>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Phone Number*</label>
                            <input type="tel" name="phone" required 
                                   class="mt-1 block w-full border <?= isset($errors['phone']) ? 'border-red-500' : 'border-gray-300' ?> rounded-md px-3 py-2"
                                   value="<?= isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : '' ?>">
                            <?php if (isset($errors['phone'])): ?>
                                <p class="mt-1 text-sm text-red-600"><?= $errors['phone'] ?></p>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Email Address*</label>
                            <input type="email" name="email" required 
                                   class="mt-1 block w-full border <?= isset($errors['email']) ? 'border-red-500' : 'border-gray-300' ?> rounded-md px-3 py-2"
                                   value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>">
                            <?php if (isset($errors['email'])): ?>
                                <p class="mt-1 text-sm text-red-600"><?= $errors['email'] ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700">Address*</label>
                            <textarea name="address" required rows="3"
                                      class="mt-1 block w-full border <?= isset($errors['address']) ? 'border-red-500' : 'border-gray-300' ?> rounded-md px-3 py-2"><?= isset($_POST['address']) ? htmlspecialchars($_POST['address']) : '' ?></textarea>
                            <?php if (isset($errors['address'])): ?>
                                <p class="mt-1 text-sm text-red-600"><?= $errors['address'] ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700">Customer Photo</label>
                            <input type="file" name="customer_photo" accept="image/*"
                                   class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                            <?php if (isset($errors['customer_photo'])): ?>
                                <p class="mt-1 text-sm text-red-600"><?= $errors['customer_photo'] ?></p>
                            <?php endif; ?>
                            <p class="mt-1 text-sm text-gray-500">Upload a photo of the customer (optional). Max 2MB. Allowed types: JPG, PNG, GIF.</p>
                        </div>
                    </div>
                </div>

                <div class="mb-6 border-b border-gray-200 pb-4">
                    <h3 class="text-lg font-medium text-gray-900">Account Information</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Account Type*</label>
                            <select name="account_type_id" required 
                                    class="mt-1 block w-full border <?= isset($errors['account_type_id']) ? 'border-red-500' : 'border-gray-300' ?> rounded-md px-3 py-2">
                                <option value="">Select Account Type</option>
                                <?php foreach ($account_types as $type): ?>
                                    <option value="<?= $type['type_id'] ?>" <?= (isset($_POST['account_type_id']) && $_POST['account_type_id'] == $type['type_id']) ? 'selected' : '' ?>>
                                        <?= $type['type_name'] ?> (<?= $type['category'] ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($errors['account_type_id'])): ?>
                                <p class="mt-1 text-sm text-red-600"><?= $errors['account_type_id'] ?></p>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Account Name*</label>
                            <input type="text" name="account_name" required 
                                   class="mt-1 block w-full border <?= isset($errors['account_name']) ? 'border-red-500' : 'border-gray-300' ?> rounded-md px-3 py-2"
                                   placeholder="e.g., John's Savings Account"
                                   value="<?= isset($_POST['account_name']) ? htmlspecialchars($_POST['account_name']) : '' ?>">
                            <?php if (isset($errors['account_name'])): ?>
                                <p class="mt-1 text-sm text-red-600"><?= $errors['account_name'] ?></p>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Initial Deposit (USD)*</label>
                            <input type="number" name="initial_deposit" min="0" step="0.01" required 
                                   class="mt-1 block w-full border <?= isset($errors['initial_deposit']) ? 'border-red-500' : 'border-gray-300' ?> rounded-md px-3 py-2"
                                   value="<?= isset($_POST['initial_deposit']) ? htmlspecialchars($_POST['initial_deposit']) : '' ?>">
                            <?php if (isset($errors['initial_deposit'])): ?>
                                <p class="mt-1 text-sm text-red-600"><?= $errors['initial_deposit'] ?></p>
                            <?php endif; ?>
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
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Bank</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created By</th>
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
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= htmlspecialchars($customer['bank_name']) ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= htmlspecialchars($customer['employees_first_name'] ?? 'Unknown') . ' ' . htmlspecialchars($customer['employees_last_name'] ?? '') ?>
                                    </td>
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