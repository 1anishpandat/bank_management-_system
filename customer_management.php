I'll fix these issues in your customer management code. Here are the problems and solutions:

    Missing Database Columns:

        title, is_minor, guardian_name, guardian_relation, guardian_id_proof in customers table

        is_minor_account in accounts table

    Guardian Info Not Showing:

        The JavaScript to show/hide guardian section needs fixing

    Age Calculation Issue:

        The age calculation needs to be more precise

Here's the fixed code:
php

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
        $first_name = substr(sanitize_input($_POST['first_name'] ?? ''), 0, 50);
        if (empty($first_name)) {
            $errors['first_name'] = "First name is required";
        } elseif (!preg_match("/^[a-zA-Z-' .]*$/", $first_name)) {
            $errors['first_name'] = "Only letters, spaces, dots and hyphens allowed";
        }

        $last_name = substr(sanitize_input($_POST['last_name'] ?? ''), 0, 50);
        if (empty($last_name)) {
            $errors['last_name'] = "Last name is required";
        } elseif (!preg_match("/^[a-zA-Z-' ]*$/", $last_name)) {
            $errors['last_name'] = "Only letters and white space allowed";
        }

        $email = sanitize_input($_POST['email'] ?? '');
        if (empty($email)) {
            $errors['email'] = "Email is required";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = "Invalid email format";
        }

        $phone = sanitize_input($_POST['phone'] ?? '');
        if (empty($phone)) {
            $errors['phone'] = "Phone number is required";
        } elseif (!preg_match("/^[0-9]{10,15}$/", $phone)) {
            $errors['phone'] = "Invalid phone number format (10-15 digits)";
        }

        $address = sanitize_input($_POST['address'] ?? '');
        if (empty($address)) {
            $errors['address'] = "Address is required";
        } elseif (strlen($address) > 255) {
            $errors['address'] = "Address too long (max 255 characters)";
        }

        $date_of_birth = sanitize_input($_POST['date_of_birth'] ?? '');
        $is_minor = false;
        if (empty($date_of_birth)) {
            $errors['date_of_birth'] = "Date of birth is required";
        } else {
            $dob = new DateTime($date_of_birth);
            $today = new DateTime();
            $age = $today->diff($dob)->y;
            if ($age < 5) {
                $errors['date_of_birth'] = "Customer must be at least 5 years old";
            } elseif ($age < 18) {
                $is_minor = true;
            }
        }

        $id_proof_type = sanitize_input($_POST['id_proof_type'] ?? '');
        $allowed_id_types = ['Passport', 'Driver License', 'National ID', 'PAN Card', 'Aadhaar Card', 'Birth Certificate'];
        if (empty($id_proof_type)) {
            $errors['id_proof_type'] = "ID proof type is required";
        } elseif (!in_array($id_proof_type, $allowed_id_types)) {
            $errors['id_proof_type'] = "Invalid ID proof type";
        }

        $id_proof_number = sanitize_input($_POST['id_proof_number'] ?? '');
        if (empty($id_proof_number)) {
            $errors['id_proof_number'] = "ID proof number is required";
        } elseif (strlen($id_proof_number) > 50) {
            $errors['id_proof_number'] = "ID number too long (max 50 characters)";
        }

        // Guardian information for minors
        $guardian_name = '';
        $guardian_relation = '';
        $guardian_id_proof = '';
        if ($is_minor) {
            $guardian_name = sanitize_input($_POST['guardian_name'] ?? '');
            if (empty($guardian_name)) {
                $errors['guardian_name'] = "Guardian name is required for minors";
            }

            $guardian_relation = sanitize_input($_POST['guardian_relation'] ?? '');
            if (empty($guardian_relation)) {
                $errors['guardian_relation'] = "Guardian relation is required for minors";
            }

            $guardian_id_proof = sanitize_input($_POST['guardian_id_proof'] ?? '');
            if (empty($guardian_id_proof)) {
                $errors['guardian_id_proof'] = "Guardian ID proof is required for minors";
            }
        }

        // Account information validation
        $account_type_id = sanitize_input($_POST['account_type_id'] ?? '');
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

        $initial_deposit = sanitize_input($_POST['initial_deposit'] ?? '');
        if (empty($initial_deposit)) {
            $errors['initial_deposit'] = "Initial deposit is required";
        } elseif (!is_numeric($initial_deposit) || $initial_deposit < 0) {
            $errors['initial_deposit'] = "Initial deposit must be a positive number";
        } elseif ($account_type_id == 3 && $initial_deposit < 1000) {
            $errors['initial_deposit'] = "Fixed Deposit requires minimum $1000";
        } elseif ($account_type_id == 6 && $initial_deposit < 500) {
            $errors['initial_deposit'] = "Credit Card requires minimum $500";
        } elseif ($is_minor && $initial_deposit > 10000) {
            $errors['initial_deposit'] = "Minors cannot deposit more than $10,000";
        }

        $account_name = sanitize_input($_POST['account_name'] ?? '');
        if (empty($account_name)) {
            $errors['account_name'] = "Account name is required";
        } elseif (strlen($account_name) > 50) {
            $errors['account_name'] = "Account name too long (max 50 characters)";
        }

        $photo_path = null;
        $photo_error = false;
        // Handle photo upload validation (now mandatory)
        if (!isset($_FILES['customer_photo']) || $_FILES['customer_photo']['error'] == UPLOAD_ERR_NO_FILE) {
            $errors['customer_photo'] = "Customer photo is required";
            $photo_error = true;
        } elseif ($_FILES['customer_photo']['error'] != UPLOAD_ERR_OK) {
            $errors['customer_photo'] = "File upload error: " . $_FILES['customer_photo']['error'];
            $photo_error = true;
        }
        
        if (!$photo_error) {
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
        }

        // If no validation errors, proceed with database operations
        if (empty($errors)) {
            // Start transaction
            $conn->begin_transaction();

            try {
                // Insert customer with bank_id (removed title and minor-related fields)
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
                
                // Insert account with bank_name and employee_id (removed is_minor_account)
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
                if ($is_minor) {
                    $success_message .= " (Minor Account)";
                }
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

// Get customers list with their main accounts (asset accounts)
$customers = [];
try {
    $stmt_customers = $conn->prepare("
        SELECT c.*, 
               e.employees_first_name, 
               e.employees_last_name, 
               b.bank_name,
               a.account_id as main_account_id,
               a.account_number as main_account_number,
               a.balance as main_account_balance,
               at.type_name as main_account_type
        FROM customers c
        LEFT JOIN accounts a ON c.customer_id = a.user_id AND a.account_number IS NOT NULL
        LEFT JOIN account_types at ON a.account_type_id = at.type_id AND at.category = 'ASSET'
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

// Handle delete action
if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['action'])) {
    if ($_GET['action'] == 'delete' && isset($_GET['id'])) {
        // Only allow managers/admins to delete
        if ($employee_role == 'manager' || $employee_role == 'admin') {
            $customer_id = intval($_GET['id']);
            
            try {
                // Start transaction
                $conn->begin_transaction();
                
                // First, get all account IDs for this customer
                $stmt_get_accounts = $conn->prepare("SELECT account_id FROM accounts WHERE user_id = ?");
                $stmt_get_accounts->bind_param("i", $customer_id);
                $stmt_get_accounts->execute();
                $result = $stmt_get_accounts->get_result();
                $account_ids = [];
                while ($row = $result->fetch_assoc()) {
                    $account_ids[] = $row['account_id'];
                }
                $stmt_get_accounts->close();
                
                if (!empty($account_ids)) {
                    // Delete transactions for these accounts
                    $placeholders = implode(',', array_fill(0, count($account_ids), '?'));
                    $stmt_del_trans = $conn->prepare("DELETE FROM transactions WHERE account_id IN ($placeholders)");
                    $stmt_del_trans->bind_param(str_repeat('i', count($account_ids)), ...$account_ids);
                    $stmt_del_trans->execute();
                    $stmt_del_trans->close();
                    
                    // Delete the accounts
                    $stmt_del_accounts = $conn->prepare("DELETE FROM accounts WHERE user_id = ?");
                    $stmt_del_accounts->bind_param("i", $customer_id);
                    $stmt_del_accounts->execute();
                    $stmt_del_accounts->close();
                }
                
                // Finally, delete the customer
                $stmt_del_customer = $conn->prepare("DELETE FROM customers WHERE customer_id = ?");
                $stmt_del_customer->bind_param("i", $customer_id);
                $stmt_del_customer->execute();
                
                if ($stmt_del_customer->affected_rows > 0) {
                    $conn->commit();
                    $success_message = "Customer and all associated accounts deleted successfully.";
                } else {
                    $conn->rollback();
                    $error_message = "Customer not found or already deleted.";
                }
                $stmt_del_customer->close();
                
                // Refresh the page to show updated list
                header("Location: customer_management");
                exit();
                
            } catch (Exception $e) {
                $conn->rollback();
                $error_message = "Error deleting customer: " . $e->getMessage();
            }
        } else {
            $error_message = "You don't have permission to delete customers.";
        }
    }
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
    <style>
        #photoPreview {
            max-width: 200px;
            max-height: 200px;
            display: none;
        }
        .guardian-section {
            display: none;
        }
    </style>
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
                <div class="flex flex-wrap -mx-4">
                    <div class="w-full md:w-2/3 px-4">
                        <div class="mb-6 border-b border-gray-200 pb-4">
                            <h3 class="text-lg font-medium text-gray-900">Personal Information</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">First Name*</label>
                                    <input type="text" name="first_name" required maxlength="50"
                                           class="mt-1 block w-full border <?= isset($errors['first_name']) ? 'border-red-500' : 'border-gray-300' ?> rounded-md px-3 py-2"
                                           value="<?= isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : '' ?>"
                                           placeholder="e.g., John">
                                    <?php if (isset($errors['first_name'])): ?>
                                        <p class="mt-1 text-sm text-red-600"><?= $errors['first_name'] ?></p>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Last Name*</label>
                                    <input type="text" name="last_name" required maxlength="50"
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
                                           value="<?= isset($_POST['date_of_birth']) ? htmlspecialchars($_POST['date_of_birth']) : '' ?>"
                                           id="dobInput">
                                    <?php if (isset($errors['date_of_birth'])): ?>
                                        <p class="mt-1 text-sm text-red-600"><?= $errors['date_of_birth'] ?></p>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">ID Proof Type*</label>
                                    <select name="id_proof_type" required 
                                            class="mt-1 block w-full border <?= isset($errors['id_proof_type']) ? 'border-red-500' : 'border-gray-300' ?> rounded-md px-3 py-2"
                                            id="idProofType">
                                        <option value="">Select ID Type</option>
                                        <option value="Passport" <?= (isset($_POST['id_proof_type']) && $_POST['id_proof_type'] == 'Passport') ? 'selected' : '' ?>>Passport</option>
                                        <option value="Driver License" <?= (isset($_POST['id_proof_type']) && $_POST['id_proof_type'] == 'Driver License') ? 'selected' : '' ?>>Driver License</option>
                                        <option value="National ID" <?= (isset($_POST['id_proof_type']) && $_POST['id_proof_type'] == 'National ID') ? 'selected' : '' ?>>National ID</option>
                                        <option value="PAN Card" <?= (isset($_POST['id_proof_type']) && $_POST['id_proof_type'] == 'PAN Card') ? 'selected' : '' ?>>PAN Card</option>
                                        <option value="Aadhaar Card" <?= (isset($_POST['id_proof_type']) && $_POST['id_proof_type'] == 'Aadhaar Card') ? 'selected' : '' ?>>Aadhaar Card</option>
                                        <option value="Birth Certificate" <?= (isset($_POST['id_proof_type']) && $_POST['id_proof_type'] == 'Birth Certificate') ? 'selected' : '' ?>>Birth Certificate</option>
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
                            </div>
                        </div>

                        <!-- Guardian Information Section (shown only for minors) -->
                        <div class="mb-6 border-b border-gray-200 pb-4 guardian-section" id="guardianSection">
                            <h3 class="text-lg font-medium text-gray-900">Guardian Information (For Minors)</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Guardian Name*</label>
                                    <input type="text" name="guardian_name" maxlength="50"
                                           class="mt-1 block w-full border <?= isset($errors['guardian_name']) ? 'border-red-500' : 'border-gray-300' ?> rounded-md px-3 py-2"
                                           value="<?= isset($_POST['guardian_name']) ? htmlspecialchars($_POST['guardian_name']) : '' ?>">
                                    <?php if (isset($errors['guardian_name'])): ?>
                                        <p class="mt-1 text-sm text-red-600"><?= $errors['guardian_name'] ?></p>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Relation*</label>
                                    <input type="text" name="guardian_relation" maxlength="50"
                                           class="mt-1 block w-full border <?= isset($errors['guardian_relation']) ? 'border-red-500' : 'border-gray-300' ?> rounded-md px-3 py-2"
                                           value="<?= isset($_POST['guardian_relation']) ? htmlspecialchars($_POST['guardian_relation']) : '' ?>">
                                    <?php if (isset($errors['guardian_relation'])): ?>
                                        <p class="mt-1 text-sm text-red-600"><?= $errors['guardian_relation'] ?></p>
                                    <?php endif; ?>
                                </div>
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-medium text-gray-700">Guardian ID Proof*</label>
                                    <input type="text" name="guardian_id_proof" maxlength="50"
                                           class="mt-1 block w-full border <?= isset($errors['guardian_id_proof']) ? 'border-red-500' : 'border-gray-300' ?> rounded-md px-3 py-2"
                                           value="<?= isset($_POST['guardian_id_proof']) ? htmlspecialchars($_POST['guardian_id_proof']) : '' ?>">
                                    <?php if (isset($errors['guardian_id_proof'])): ?>
                                        <p class="mt-1 text-sm text-red-600"><?= $errors['guardian_id_proof'] ?></p>
                                    <?php endif; ?>
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
                    </div>

                    <div class="w-full md:w-1/3 px-4">
                        <div class="mb-6">
                            <h3 class="text-lg font-medium text-gray-900">Customer Photo*</h3>
                            <div class="mt-4">
                                <div class="flex items-center justify-center w-full">
                                    <label for="customer_photo" class="flex flex-col items-center justify-center w-full h-64 border-2 border-gray-300 border-dashed rounded-lg cursor-pointer bg-gray-50 hover:bg-gray-100">
                                        <div class="flex flex-col items-center justify-center pt-5 pb-6">
                                            <svg class="w-8 h-8 mb-4 text-gray-500" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                                            </svg>
                                            <p class="mb-2 text-sm text-gray-500"><span class="font-semibold">Click to upload</span> or drag and drop</p>
                                            <p class="text-xs text-gray-500">PNG, JPG, GIF (MAX. 2MB)</p>
                                        </div>
                                        <input id="customer_photo" name="customer_photo" type="file" class="hidden" accept="image/*" required />
                                    </label>
                                </div>
                                <img id="photoPreview" src="#" alt="Preview" class="mt-4 rounded-lg mx-auto">
                                <?php if (isset($errors['customer_photo'])): ?>
                                    <p class="mt-1 text-sm text-red-600"><?= $errors['customer_photo'] ?></p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="bg-blue-50 p-4 rounded-lg">
                            <h3 class="text-lg font-medium text-blue-800">Bank Information</h3>
                            <div class="mt-2 text-sm text-blue-700">
                                <p><strong>Bank:</strong> <?= htmlspecialchars($bank_name) ?></p>
                                <p><strong>Branch ID:</strong> <?= htmlspecialchars($loggedInBankId) ?></p>
                                <p><strong>Account Officer:</strong> <?= htmlspecialchars($_SESSION['employee_name'] ?? 'N/A') ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-6 flex justify-end">
                    <a href="customer_management" class="bg-gray-500 hover:bg-gray-700 text-white px-4 py-2 rounded mr-2">Cancel</a>
                    <button type="submit" class="bg-blue-500 hover:bg-blue-700 text-white px-4 py-2 rounded">Open Account</button>
                </div>
            </form>
        </div>

        <script>
            // Photo preview functionality
            document.getElementById('customer_photo').addEventListener('change', function(e) {
                const file = e.target.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        const preview = document.getElementById('photoPreview');
                        preview.src = e.target.result;
                        preview.style.display = 'block';
                    }
                    reader.readAsDataURL(file);
                }
            });

            // Age calculation and guardian section toggle
            document.getElementById('dobInput').addEventListener('change', function() {
                const dob = new Date(this.value);
                const today = new Date();
                let age = today.getFullYear() - dob.getFullYear();
                const monthDiff = today.getMonth() - dob.getMonth();
                
                if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < dob.getDate())) {
                    age--;
                }
                
                const guardianSection = document.getElementById('guardianSection');
                if (age < 18) {
                    guardianSection.style.display = 'block';
                    // Make guardian fields required
                    document.querySelectorAll('#guardianSection input').forEach(input => {
                        input.required = true;
                    });
                } else {
                    guardianSection.style.display = 'none';
                    // Remove required from guardian fields
                    document.querySelectorAll('#guardianSection input').forEach(input => {
                        input.required = false;
                    });
                }
            });

            // Initialize guardian section if DOB is already filled (form submission with errors)
            document.addEventListener('DOMContentLoaded', function() {
                const dobInput = document.getElementById('dobInput');
                if (dobInput.value) {
                    const event = new Event('change');
                    dobInput.dispatchEvent(event);
                }
            });
        </script>

    <?php else: ?>
        <!-- Customer List View -->
        <div class="bg-white p-6 rounded-lg shadow">
            <div class="mb-4 flex justify-between items-center">
                <h2 class="text-xl font-semibold">Customer Accounts</h2>
                <div class="relative">
                    <input type="text" id="searchInput" placeholder="Search customers..." 
                           class="border border-gray-300 rounded-md px-3 py-2 pl-10 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <svg class="absolute left-3 top-2.5 h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                </div>
            </div>

            <?php if (empty($customers)): ?>
                <div class="text-center py-8">
                    <p class="text-gray-500">No customers found for this bank.</p>
                    <a href="?action=add" class="mt-4 inline-block bg-blue-500 hover:bg-blue-700 text-white px-4 py-2 rounded">Add First Customer</a>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Customer</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Contact</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Account</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Balance</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created</th>
                                <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($customers as $customer): ?>
                                <tr class="hover:bg-gray-50 customer-row">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="flex-shrink-0 h-10 w-10">
                                                <?php if (!empty($customer['photo_path'])): ?>
                                                    <img class="h-10 w-10 rounded-full object-cover" src="<?= htmlspecialchars($customer['photo_path']) ?>" alt="">
                                                <?php else: ?>
                                                    <div class="h-10 w-10 rounded-full bg-gray-300 flex items-center justify-center">
                                                        <span class="text-gray-600 text-sm"><?= substr($customer['first_name'], 0, 1) . substr($customer['last_name'], 0, 1) ?></span>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="ml-4">
                                                <div class="text-sm font-medium text-gray-900">
                                                    <?= htmlspecialchars($customer['first_name'] . ' ' . htmlspecialchars($customer['last_name'])) ?>
                                                    <?php 
                                                        $dob = new DateTime($customer['date_of_birth']);
                                                        $today = new DateTime();
                                                        $age = $today->diff($dob)->y;
                                                        if ($age < 18): 
                                                    ?>
                                                        <span class="ml-2 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">Minor</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="text-sm text-gray-500">ID: <?= htmlspecialchars($customer['id_proof_type']) ?> <?= htmlspecialchars($customer['id_proof_number']) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900"><?= htmlspecialchars($customer['email']) ?></div>
                                        <div class="text-sm text-gray-500"><?= htmlspecialchars($customer['phone']) ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php if ($customer['main_account_number']): ?>
                                            <div class="text-sm font-medium text-gray-900">
                                                <?= htmlspecialchars($customer['main_account_type']) ?>
                                                <span class="text-gray-500 ml-2">(<?= htmlspecialchars($customer['main_account_number']) ?>)</span>
                                            </div>
                                            <div class="text-sm text-gray-500">
                                                Opened by <?= htmlspecialchars($customer['employees_first_name'] . ' ' . htmlspecialchars($customer['employees_last_name'])) ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">No Account</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php if ($customer['main_account_balance'] !== null): ?>
                                            <div class="text-sm font-medium <?= $customer['main_account_balance'] >= 0 ? 'text-green-600' : 'text-red-600' ?>">
                                                $<?= number_format($customer['main_account_balance'], 2) ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-gray-500 text-sm">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= date('M d, Y', strtotime($customer['created_at'])) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <a href="customer_details?id=<?= $customer['customer_id'] ?>" class="text-blue-600 hover:text-blue-900 mr-3">View</a>
                                        <a href="edit_customer?action=edit&id=<?= $customer['customer_id'] ?>" class="text-indigo-600 hover:text-indigo-900 mr-3">Edit</a>
                                        <?php if ($employee_role == 'manager' || $employee_role == 'admin'): ?>
                                            <a href="#" onclick="confirmDelete(<?= $customer['customer_id'] ?>)" class="text-red-600 hover:text-red-900">Delete</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination would go here -->
                <div class="mt-4 flex justify-between items-center">
                    <div class="text-sm text-gray-700">
                        Showing <span class="font-medium">1</span> to <span class="font-medium"><?= count($customers) ?></span> of <span class="font-medium"><?= count($customers) ?></span> results
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <script>
            // Search functionality
            document.getElementById('searchInput').addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                const rows = document.querySelectorAll('.customer-row');
                
                rows.forEach(row => {
                    const text = row.textContent.toLowerCase();
                    if (text.includes(searchTerm)) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
            });

            // Delete confirmation
            function confirmDelete(customerId) {
                Swal.fire({
                    title: 'Are you sure?',
                    text: "You won't be able to revert this!",
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#d33',
                    cancelButtonColor: '#3085d6',
                    confirmButtonText: 'Yes, delete it!'
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.location.href = `?action=delete&id=${customerId}`;
                    }
                });
            }
        </script>
    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>
</body>
</html>