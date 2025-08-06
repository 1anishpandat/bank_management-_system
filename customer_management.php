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


    // Handle captured photo
if (!empty($_POST['captured_photo'])) {
    $photo_data = $_POST['captured_photo'];
    $photo_data = str_replace('data:image/jpeg;base64,', '', $photo_data);
    $photo_data = str_replace(' ', '+', $photo_data);
    $photo_data = base64_decode($photo_data);
    
    $target_dir = "uploads/customer_photos/";
    if (!is_dir($target_dir)) {
        mkdir($target_dir, 0777, true);
    }
    
    $new_file_name = uniqid('photo_', true) . '.jpg';
    $target_file = $target_dir . $new_file_name;
    
    if (file_put_contents($target_file, $photo_data)) {
        $photo_path = $target_file;
    } else {
        $errors['customer_photo'] = "Error saving captured photo";
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
        .form-section {
            margin-bottom: 20px;
            border: 1px solid #ddd;
            padding: 15px;
            border-radius: 5px;
        }
        .form-title {
            background-color: #f0f0f0;
            padding: 8px;
            margin-bottom: 15px;
            font-weight: bold;
            border-left: 4px solid #3b82f6;
        }
        .form-row {
            display: flex;
            flex-wrap: wrap;
            margin-bottom: 10px;
        }
        .form-group {
            flex: 1;
            min-width: 200px;
            margin-right: 15px;
            margin-bottom: 10px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }
        .form-control {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .required-field::after {
            content: "*";
            color: red;
            margin-left: 4px;
        }
        .bank-stamp {
            border: 1px dashed #999;
            padding: 10px;
            text-align: center;
            min-height: 100px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        #camera-container {
    width: 320px;
    height: 240px;
    background-color: #f0f0f0;
    margin-bottom: 10px;
    position: relative;
}

#camera-feed, #camera-canvas {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

#face-overlay {
    width: 150px;
    height: 150px;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    pointer-events: none;
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
            <div class="text-center mb-6">
                <h2 class="text-xl font-bold">ACCOUNT OPENING FORM FOR RESIDENT INDIVIDUAL</h2>
                <p class="text-sm">(Must be accompanied with Terms and Conditions)</p>
                <p class="text-sm font-bold">CUSTOMER INFORMATION SHEET (CIF Creation/Amendment)</p>
            </div>

            <div class="text-xs mb-6 italic">
                <p>(In case of Joint Accounts / Related Person / Guardian, Part - I (CIF Sheet) and Terms & Conditions to be taken for each customer)</p>
                <p>In case of current account, declaration cum undertaking, to be obtained</p>
            </div>

            <div class="flex justify-between mb-6">
                <div>
                    <p class="font-bold">Branch Name: <?= htmlspecialchars($bank_name) ?></p>
                </div>
                <div class="text-right">
                    <p class="text-sm">Fields marked asterisk (*) are mandatory. Please fill up in BLOCK letters only and use black ink for signature</p>
                    <p class="text-sm italic">(For office use only)</p>
                </div>
            </div>

            <div class="flex justify-between mb-6">
                <div class="bank-stamp w-1/3">
                    <p>Bank/Branch to affix rubber stamp of name and code no.</p>
                </div>
                
                <div class="w-2/3 pl-4">
                    <div class="grid grid-cols-3 gap-4 mb-2">
                        
                        <div>
                            <label class="block text-sm">Customer ID</label>
                            <div class="border-b border-black h-8"></div>
                        </div>
                        <div>
                            <label class="block text-sm">Account No.</label>
                            <div class="border-b border-black h-8"></div>
                        </div>
                        <div>
                            <label class="block text-sm">Account type</label>
                            <div class="flex items-center space-x-4 mt-1">
                                <label class="inline-flex items-center">
                                    <input type="checkbox" class="form-checkbox">
                                    <span class="ml-2">Normal</span>
                                </label>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" class="form-checkbox">
                                    <span class="ml-2">Small</span>
                                </label>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" class="form-checkbox">
                                    <span class="ml-2">Minor</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="grid grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm">Application Type</label>
                            <div class="flex items-center space-x-4 mt-1">
                                <label class="inline-flex items-center">
                                    <input type="radio" name="application_type" class="form-radio" checked>
                                    <span class="ml-2">New</span>
                                </label>
                                <label class="inline-flex items-center">
                                    <input type="radio" name="application_type" class="form-radio">
                                    <span class="ml-2">Update</span>
                                </label>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm">CKYC No.</label>
                            <div class="border-b border-black h-8"></div>
                        </div>
                        <div>
                            <label class="block text-sm">Staff PF NO.</label>
                            <div class="border-b border-black h-8"></div>
                        </div>
                    </div>
                </div>
            </div>

            <form method="POST" id="accountForm" enctype="multipart/form-data">
                <!-- Personal Details Section -->
                <div class="form-section">
                <div class="form-row">
    <div class="form-group">
        <label class="required-field">First Name*</label>
        <input type="text" name="first_name" required class="form-control uppercase" 
               value="<?= isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : '' ?>">
        <?php if (isset($errors['first_name'])): ?>
            <p class="text-red-500 text-xs"><?= $errors['first_name'] ?></p>
        <?php endif; ?>
    </div>
    <div class="form-group">
        <label class="required-field">Last Name*</label>
        <input type="text" name="last_name" required class="form-control uppercase" 
               value="<?= isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : '' ?>">
        <?php if (isset($errors['last_name'])): ?>
            <p class="text-red-500 text-xs"><?= $errors['last_name'] ?></p>
        <?php endif; ?>
    </div>
</div>
                    <div class="form-title">A. Personal Details</div>
                    <!-- Add this in the "Personal Details" section -->
<div class="form-row">
    <div class="form-group">
        <label class="required-field">Capture Photo*</label>
        <p class="text-xs">Take a clear photo of your face (well-lit environment)</p>
        
        <div class="border rounded p-2 mb-2">
            <div id="camera-container" class="relative">
                <video id="camera-feed" autoplay playsinline class="w-full h-auto"></video>
                <canvas id="camera-canvas" class="hidden"></canvas>
                <div id="face-overlay" class="absolute border-2 border-green-500 rounded-full hidden"></div>
            </div>
            
            <div class="flex space-x-2 mt-2">
                <button type="button" id="start-camera" class="bg-blue-500 hover:bg-blue-700 text-white px-3 py-1 rounded text-sm">
                    Start Camera
                </button>
                <button type="button" id="capture-btn" class="bg-green-500 hover:bg-green-700 text-white px-3 py-1 rounded text-sm hidden">
                    Capture Photo
                </button>
                <button type="button" id="retake-btn" class="bg-gray-500 hover:bg-gray-700 text-white px-3 py-1 rounded text-sm hidden">
                    Retake
                </button>
            </div>
        </div>
        
        <input type="hidden" id="captured-photo" name="captured_photo">
        <div id="photo-preview-container" class="hidden mt-2">
            <p class="text-sm">Captured Photo:</p>
            <img id="photo-preview" src="#" alt="Captured Photo" class="w-32 h-32 object-cover border">
        </div>
    </div>
</div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="required-field">1. Name* (Same as ID Proof)</label>
                            <input type="text" name="first_name" required class="form-control uppercase" 
                                   value="<?= isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : '' ?>">
                            <?php if (isset($errors['first_name'])): ?>
                                <p class="text-red-500 text-xs"><?= $errors['first_name'] ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label>2. Date of Birth*</label>
                            <input type="date" name="date_of_birth" required class="form-control" id="dobInput"
                                   value="<?= isset($_POST['date_of_birth']) ? htmlspecialchars($_POST['date_of_birth']) : '' ?>">
                            <?php if (isset($errors['date_of_birth'])): ?>
                                <p class="text-red-500 text-xs"><?= $errors['date_of_birth'] ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>4. Martial Status</label>
                            <select class="form-control">
                                <option value="">Select</option>
                                <option value="single">Single</option>
                                <option value="married">Married</option>
                                <option value="divorced">Divorced</option>
                                <option value="widowed">Widowed</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>5. Name of Dependents</label>
                            <input type="text" class="form-control">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>6. Name of* (Please tick one)</label>
                            <div class="mt-2">
                                <label class="inline-flex items-center">
                                    <input type="radio" name="relative_type" class="form-radio">
                                    <span class="ml-2">Father</span>
                                </label>
                                <label class="inline-flex items-center ml-4">
                                    <input type="radio" name="relative_type" class="form-radio">
                                    <span class="ml-2">Mother</span>
                                </label>
                                <label class="inline-flex items-center ml-4">
                                    <input type="radio" name="relative_type" class="form-radio" checked>
                                    <span class="ml-2">Spouse*</span>
                                </label>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>7. Name of Guardian (Father name is mandatory, #PAN is not provided)</label>
                            <input type="text" class="form-control" id="guardianNameInput" name="guardian_name"
                                   value="<?= isset($_POST['guardian_name']) ? htmlspecialchars($_POST['guardian_name']) : '' ?>">
                            <?php if (isset($errors['guardian_name'])): ?>
                                <p class="text-red-500 text-xs"><?= $errors['guardian_name'] ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>8. Nationality</label>
                            <div class="flex items-center mt-1">
                                <label class="inline-flex items-center">
                                    <input type="radio" name="nationality" class="form-radio" checked>
                                    <span class="ml-2">Indian</span>
                                </label>
                                <label class="inline-flex items-center ml-4">
                                    <input type="radio" name="nationality" class="form-radio">
                                    <span class="ml-2">Others</span>
                                    <input type="text" class="form-control ml-2 w-32" placeholder="Country Name">
                                </label>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>9. Citizenship</label>
                            <input type="text" class="form-control">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>10. Occupation</label>
                            <select class="form-control">
                                <option value="">Select</option>
                                <option value="service">Service</option>
                                <option value="business">Business</option>
                                <option value="professional">Professional</option>
                                <option value="retired">Retired</option>
                                <option value="student">Student</option>
                                <option value="housewife">Housewife</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>11. Occupation Type</label>
                            <select class="form-control">
                                <option value="">Select</option>
                                <option value="govt">State Govt.</option>
                                <option value="central">Central Govt.</option>
                                <option value="psu">Public Sector Undertaking</option>
                                <option value="defence">Defence</option>
                                <option value="private">Pvt. Sector</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>12. Annual Income*</label>
                            <input type="text" class="form-control" placeholder="Rs.">
                        </div>
                        <div class="form-group">
                            <label>13. Source of Funds</label>
                            <select class="form-control">
                                <option value="">Select</option>
                                <option value="salary">Salary</option>
                                <option value="business">Business Income</option>
                                <option value="agriculture">Agriculture</option>
                                <option value="investment">Investment</option>
                                <option value="pension">Pension</option>
                                <option value="other">Others</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>14. Religion</label>
                            <select class="form-control">
                                <option value="">Select</option>
                                <option value="hindu">Hindu</option>
                                <option value="muslim">Muslim</option>
                                <option value="christian">Christian</option>
                                <option value="sikh">Sikh</option>
                                <option value="other">Others</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>15. Category</label>
                            <div class="mt-2">
                                <label class="inline-flex items-center">
                                    <input type="radio" name="category" class="form-radio">
                                    <span class="ml-2">General</span>
                                </label>
                                <label class="inline-flex items-center ml-4">
                                    <input type="radio" name="category" class="form-radio">
                                    <span class="ml-2">OBC</span>
                                </label>
                                <label class="inline-flex items-center ml-4">
                                    <input type="radio" name="category" class="form-radio">
                                    <span class="ml-2">SC</span>
                                </label>
                                <label class="inline-flex items-center ml-4">
                                    <input type="radio" name="category" class="form-radio">
                                    <span class="ml-2">ST</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>16. Person with Disability</label>
                            <div class="mt-2">
                                <label class="inline-flex items-center">
                                    <input type="radio" name="disability" class="form-radio" value="yes">
                                    <span class="ml-2">Yes</span>
                                </label>
                                <label class="inline-flex items-center ml-4">
                                    <input type="radio" name="disability" class="form-radio" value="no" checked>
                                    <span class="ml-2">No</span>
                                </label>
                                <div class="mt-2 ml-6">
                                    <label class="inline-flex items-center">
                                        <input type="checkbox" class="form-checkbox">
                                        <span class="ml-2">Visually impaired</span>
                                    </label>
                                    <label class="inline-flex items-center ml-4">
                                        <input type="checkbox" class="form-checkbox">
                                        <span class="ml-2">Differently abled</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>17. Educational Qualification</label>
                            <select class="form-control">
                                <option value="">Select</option>
                                <option value="illiterate">Illiterate</option>
                                <option value="9th">Up to 9th Class</option>
                                <option value="10th">Passed 10th Class</option>
                                <option value="graduate">Graduate (Gen.)</option>
                                <option value="postgraduate">Post Graduate(Gen.)</option>
                                <option value="medical">Med. Graduate / Post Graduate</option>
                                <option value="engineering">Eng. Graduate / Post Graduate</option>
                                <option value="law">Law Graduate / Post Graduate</option>
                                <option value="ca">CA / ICWA / MBA / CFA</option>
                                <option value="computer">Computer Degree / Diploma / NCA</option>
                                <option value="other">Other Professional Degree/Diploma</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>18. Please Tick the Politically Exposed Person</label>
                            <div class="mt-2">
                                <label class="inline-flex items-center">
                                    <input type="radio" name="pep" class="form-radio" value="yes">
                                    <span class="ml-2">Politically Exposed Person</span>
                                </label>
                                <label class="inline-flex items-center ml-4">
                                    <input type="radio" name="pep" class="form-radio" value="related">
                                    <span class="ml-2">Related to Politically Exposed Person</span>
                                </label>
                                <label class="inline-flex items-center ml-4">
                                    <input type="radio" name="pep" class="form-radio" value="none" checked>
                                    <span class="ml-2">None Applicable</span>
                                </label>
                            </div>



                            <p class="text-xs mt-1">(Politically Exposed Persons are individuals who are or have been entrusted with prominent public functions in a foreign country e.g. Heads of State / Governments, Senior Politicians / Senior Governments / Judicials / Military Officers, Senior Executives of State-owned Corporations, Important Political Party Officials, etc.)</p>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>19. Country of Tax Residence in India only and not in any other country or territory outside India*</label>
                            <div class="mt-2">
                                <label class="inline-flex items-center">
                                    <input type="radio" name="tax_residence" class="form-radio" value="yes" checked>
                                    <span class="ml-2">Yes</span>
                                </label>
                                <label class="inline-flex items-center ml-4">
                                    <input type="radio" name="tax_residence" class="form-radio" value="no">
                                    <span class="ml-2">No</span>
                                </label>
                                <p class="text-xs mt-1">(If no, please fill the FATCA details form - Annexure I)</p>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>20. PAN* (If PAN is not submitted, submit Form 60 - Annexure I)</label>
                            <input type="text" class="form-control" placeholder="PAN Number">
                        </div>
                    </div>
                </div>

                <!-- Contact Details Section -->
                <div class="form-section">
                    <div class="form-title">B. Contact Details (All communications will be sent on provided Mobile No./Email-ID)</div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="required-field">Mobile No.*</label>
                            <input type="tel" name="phone" required class="form-control" 
                                   value="<?= isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : '' ?>">
                            <?php if (isset($errors['phone'])): ?>
                                <p class="text-red-500 text-xs"><?= $errors['phone'] ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label>STD Tel.: Off</label>
                            <input type="tel" class="form-control">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="required-field">Email*</label>
                            <input type="email" name="email" required class="form-control" 
                                   value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>">
                            <?php if (isset($errors['email'])): ?>
                                <p class="text-red-500 text-xs"><?= $errors['email'] ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Proof of Identity/Address Section -->
                <div class="form-section">
                    <div class="form-title">C. Proof of Identity/Address (Officially Valid Documents) [Please tick the appropriate Box (any one ID type) and give details]*</div>
                    
                    <div class="grid grid-cols-3 gap-4 mb-4">
                        <label class="inline-flex items-center">
                            <input type="radio" name="id_proof_type" value="Passport" class="form-radio" <?= (isset($_POST['id_proof_type']) && $_POST['id_proof_type'] == 'Passport') ? 'checked' : '' ?>>
                            <span class="ml-2">A-Passport</span>
                        </label>
                        <label class="inline-flex items-center">
                            <input type="radio" name="id_proof_type" value="Voter's Identity Card" class="form-radio" <?= (isset($_POST['id_proof_type']) && $_POST['id_proof_type'] == "Voter's Identity Card") ? 'checked' : '' ?>>
                            <span class="ml-2">B-Voter's Identity Card</span>
                        </label>
                        <label class="inline-flex items-center">
                            <input type="radio" name="id_proof_type" value="Driving Licence" class="form-radio" <?= (isset($_POST['id_proof_type']) && $_POST['id_proof_type'] == 'Driving Licence') ? 'checked' : '' ?>>
                            <span class="ml-2">C-Driving Licence</span>
                        </label>
                        <label class="inline-flex items-center">
                            <input type="radio" name="id_proof_type" value="Aadhaar Card" class="form-radio" <?= (isset($_POST['id_proof_type']) && $_POST['id_proof_type'] == 'Aadhaar Card') ? 'checked' : '' ?>>
                            <span class="ml-2">D-Proof of Possession of Aadhaar Number (Verification)</span>
                        </label>
                        <label class="inline-flex items-center">
                            <input type="radio" name="id_proof_type" value="KYC" class="form-radio" <?= (isset($_POST['id_proof_type']) && $_POST['id_proof_type'] == 'KYC') ? 'checked' : '' ?>>
                            <span class="ml-2">E-KYC Offline</span>
                        </label>
                        <label class="inline-flex items-center">
                            <input type="radio" name="id_proof_type" value="NREGA Job Card" class="form-radio" <?= (isset($_POST['id_proof_type']) && $_POST['id_proof_type'] == 'NREGA Job Card') ? 'checked' : '' ?>>
                            <span class="ml-2">E-NREGA Job Card</span>
                        </label>
                        <label class="inline-flex items-center">
                            <input type="radio" name="id_proof_type" value="NPR Letter" class="form-radio" <?= (isset($_POST['id_proof_type']) && $_POST['id_proof_type'] == 'NPR Letter') ? 'checked' : '' ?>>
                            <span class="ml-2">F-Letter Issued by National Population Register Containing Details of Name & Address</span>
                        </label>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Whether submitted document is equivalent e-document:</label>
                            <div class="mt-2">
                                <label class="inline-flex items-center">
                                    <input type="radio" name="e_document" class="form-radio" value="yes">
                                    <span class="ml-2">Yes</span>
                                </label>
                                <label class="inline-flex items-center ml-4">
                                    <input type="radio" name="e_document" class="form-radio" value="no" checked>
                                    <span class="ml-2">No</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="required-field">Document No. / Identification No.*</label>
                            <input type="text" name="id_proof_number" required class="form-control uppercase" 
                                   value="<?= isset($_POST['id_proof_number']) ? htmlspecialchars($_POST['id_proof_number']) : '' ?>">
                            <?php if (isset($errors['id_proof_number'])): ?>
                                <p class="text-red-500 text-xs"><?= $errors['id_proof_number'] ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label>Issued By:</label>
                            <input type="text" class="form-control">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Only for Foreign Nationals:</label>
                            <input type="text" class="form-control" placeholder="VISA Details (reference No.:)">
                        </div>
                        <div class="form-group">
                            <label>Issued By:</label>
                            <input type="text" class="form-control">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Small Accounts: Only Self-Attested Photograph</label>
                            <input type="file" class="form-control">
                        </div>
                    </div>
                </div>

                <!-- Address Details Section -->
                <div class="form-section">
                    <div class="form-title">D. Address details Current Overseas</div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Address type*</label>
                            <div class="mt-2">
                                <label class="inline-flex items-center">
                                    <input type="radio" name="address_type" class="form-radio" checked>
                                    <span class="ml-2">Residential</span>
                                </label>
                                <label class="inline-flex items-center ml-4">
                                    <input type="radio" name="address_type" class="form-radio">
                                    <span class="ml-2">Business</span>
                                </label>
                                <label class="inline-flex items-center ml-4">
                                    <input type="radio" name="address_type" class="form-radio">
                                    <span class="ml-2">Registered Office</span>
                                </label>
                                <label class="inline-flex items-center ml-4">
                                    <input type="radio" name="address_type" class="form-radio">
                                    <span class="ml-2">Unspecified</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="required-field">Address*</label>
                            <textarea name="address" required class="form-control" rows="3"><?= isset($_POST['address']) ? htmlspecialchars($_POST['address']) : '' ?></textarea>
                            <?php if (isset($errors['address'])): ?>
                                <p class="text-red-500 text-xs"><?= $errors['address'] ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>City/Village*</label>
                            <input type="text" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>District**</label>
                            <input type="text" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>State**</label>
                            <input type="text" class="form-control">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Pincode**</label>
                            <input type="text" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Country Name**</label>
                            <input type="text" class="form-control" value="India">
                        </div>
                    </div>
                </div>

                <!-- Correspondence Address Section -->
                <div class="form-section">
                    <div class="form-title">E. Address details Correspondence Same as Current/Overseas Address</div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Address type*</label>
                            <div class="mt-2">
                                <label class="inline-flex items-center">
                                    <input type="radio" name="correspondence_address_type" class="form-radio" checked>
                                    <span class="ml-2">Residential/Business</span>
                                </label>
                                <label class="inline-flex items-center ml-4">
                                    <input type="radio" name="correspondence_address_type" class="form-radio">
                                    <span class="ml-2">Residential</span>
                                </label>
                                <label class="inline-flex items-center ml-4">
                                    <input type="radio" name="correspondence_address_type" class="form-radio">
                                    <span class="ml-2">Business</span>
                                </label>
                                <label class="inline-flex items-center ml-4">
                                    <input type="radio" name="correspondence_address_type" class="form-radio">
                                    <span class="ml-2">Registered Office</span>
                                </label>
                                <label class="inline-flex items-center ml-4">
                                    <input type="radio" name="correspondence_address_type" class="form-radio">
                                    <span class="ml-2">Unspecified</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Address*</label>
                            <textarea class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>City/Village*</label>
                            <input type="text" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>District**</label>
                            <input type="text" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>State**</label>
                            <input type="text" class="form-control">
                        </div>
                    </div>
                </div>

                <!-- Additional Documents Section -->
                <div class="form-section">
                    <div class="form-title">F. If the Officially Valid Document (OVD) does not contain current address-please provide any of the documents below. (Not more than 2 months old)</div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Utility Bill</label>
                            <input type="file" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>PPO / FPPO</label>
                            <input type="file" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Property or Municipal tax receipt</label>
                            <input type="file" class="form-control">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Letter of allotment of accommodation issued by employer / Issued by State or Central Government departments, statutory or regulatory bodies, Public sector undertaking, scheduled commercial banks, financial institutions and listed companies. Similarly, leave and licence agreements with such employers allotting official accommodation.</label>
                            <input type="file" class="form-control">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Self-Declaration (If Aadhar is voluntarily provided for identification purpose and current address is different from address available in Central Identities Data Repository Authentication of Aadhaar number using e-KYC authentication facility provided by the UIDAI in mandatory)</label>
                            <input type="file" class="form-control">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Document No.</label>
                            <input type="text" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Date</label>
                            <input type="date" class="form-control">
                        </div>
                    </div>
                </div>

                <!-- Declaration Section -->
                <div class="form-section">
                    <div class="form-title">G. DECLARATION CUM UNDERTAKING CUM SELF-CERTIFICATION</div>
                    
                    <div class="mb-4">
                        <p>1. I have read the copy of Terms and Conditions of the Account Opening Form given to me. The Terms and Conditions have been explained to me / us and having understood, I accept the same.</p>
                        <p>2. Thereby declare that I have submitted the Aadhaar Card issued by UIDAI voluntarily for identification and / or address proof towards the compliance of KYC norms under the PMLA. 2002</p>
                        <p>3. Thereby consent that the Bank may verify the same with the UIDAI and authorise the UIDAI expressly to release the identity and address through biometric / OTP based authentication to the Bank.</p>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>YES</label>
                            <input type="radio" name="declaration" class="form-radio" checked>
                        </div>
                        <div class="form-group">
                            <label>NO</label>
                            <input type="radio" name="declaration" class="form-radio">
                        </div>
                        <p class="text-xs mt-1">(E-KYC authentication and Aadhaar seeding is mandatory for availing DBT benefit)</p>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="required-field">PHOTO*</label>
                            <p class="text-xs">Please Paste Recent passport Size (Do not Staple)</p>
                            <div class="mt-2">
                                <input type="file" name="customer_photo" id="photoUpload" required class="form-control">
                                <img id="photoPreview" src="#" alt="Photo Preview" class="mt-2">
                                <?php if (isset($errors['customer_photo'])): ?>
                                    <p class="text-red-500 text-xs"><?= $errors['customer_photo'] ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Signature/Thumb impression of the Applicant</label>
                            <p class="text-xs">Please sign in black ink only</p>
                            <div class="border-b border-black h-20 mt-2"></div>
                        </div>
                        <div class="form-group">
                            <label>Place</label>
                            <input type="text" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Date</label>
                            <input type="date" class="form-control">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Documents received</label>
                            <div class="mt-2">
                                <label class="inline-flex items-center">
                                    <input type="checkbox" class="form-checkbox">
                                    <span class="ml-2">Self-certified True Copies</span>
                                </label>
                                <label class="inline-flex items-center ml-4">
                                    <input type="checkbox" class="form-checkbox">
                                    <span class="ml-2">Notary</span>
                                </label>
                                <label class="inline-flex items-center ml-4">
                                    <input type="checkbox" class="form-checkbox">
                                    <span class="ml-2">Equivalent e-Documents</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Account Information Section -->
                <div class="form-section">
                    <div class="form-title">ACCOUNT OPENING FORM FOR INDIVIDUAL (PART - II) (SAVING BANK, CURRENT ACCOUNT AND TERM DEPOSITS)</div>
                    
                    <div class="flex justify-between mb-6">
                        <div>
                            <p class="text-sm">Fields marked asterisk (*) are mandatory.</p>
                            <p class="text-sm">Please fill up in BLOCK letters only and use black ink for signature</p>
                            <p class="text-sm italic">(For office use only)</p>
                        </div>
                        <div class="grid grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm">First Applicant Customer ID</label>
                                <div class="border-b border-black h-8"></div>
                            </div>
                            <div>
                                <label class="block text-sm">Second Applicant Customer ID</label>
                                <div class="border-b border-black h-8"></div>
                            </div>
                            <div>
                                <label class="block text-sm">Account No.</label>
                                <div class="border-b border-black h-8"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-6">
                        <p class="font-bold">I / We request you to open my / our deposit account with your branch / bank as under: (Tick (✓) relevant type of account)</p>
                    </div>
                    
                    <div class="form-section">
                        <div class="form-title">A. Type of Account (In case of current account, declaration cum undertaking, Annexure 3 to be obtained)</div>
                        
                        <div class="grid grid-cols-3 gap-4 mb-4">
                            <label class="inline-flex items-center">
                                <input type="radio" name="account_type" class="form-radio" value="savings" checked>
                                <span class="ml-2">Savings Bank Account</span>
                            </label>
                            <label class="inline-flex items-center">
                                <input type="radio" name="account_type" class="form-radio" value="bsbda">
                                <span class="ml-2">BSBDA</span>
                            </label>
                            <label class="inline-flex items-center">
                                <input type="radio" name="account_type" class="form-radio" value="bsbda_small">
                                <span class="ml-2">BSBDA Small Account</span>
                            </label>
                            <label class="inline-flex items-center">
                                <input type="radio" name="account_type" class="form-radio" value="current">
                                <span class="ml-2">Current Account (Individual)</span>
                            </label>
                            <label class="inline-flex items-center">
                                <input type="radio" name="account_type" class="form-radio" value="fixed">
                                <span class="ml-2">Fixed Deposit / MOD / RD</span>
                            </label>
                            <label class="inline-flex items-center">
                                <input type="radio" name="account_type" class="form-radio" value="caps">
                                <span class="ml-2">Caps Gain (SB)</span>
                            </label>
                            <label class="inline-flex items-center">
                                <input type="radio" name="account_type" class="form-radio" value="savings_plus">
                                <span class="ml-2">Savings Plus Account</span>
                            </label>
                        </div>
                        <p class="text-xs">(In case of Current Account, declaration cum undertaking to be obtained)</p>
                    </div>
                    
                    <div class="form-section">
                        <div class="form-title">B. Mode of Operation</div>
                        
                        <div class="grid grid-cols-3 gap-4">
                            <label class="inline-flex items-center">
                                <input type="radio" name="operation_mode" class="form-radio" value="self" checked>
                                <span class="ml-2">Self</span>
                            </label>
                            <label class="inline-flex items-center">
                                <input type="radio" name="operation_mode" class="form-radio" value="either">
                                <span class="ml-2">Either or Survivor</span>
                            </label>
                            <label class="inline-flex items-center">
                                <input type="radio" name="operation_mode" class="form-radio" value="former">
                                <span class="ml-2">Former or Survivor</span>
                            </label>
                            <label class="inline-flex items-center">
                                <input type="radio" name="operation_mode" class="form-radio" value="anyone">
                                <span class="ml-2">Any one or Survivor</span>
                            </label>
                            <label class="inline-flex items-center">
                                <input type="radio" name="operation_mode" class="form-radio" value="jointly">
                                <span class="ml-2">Jointly Operated</span>
                            </label>
                            <label class="inline-flex items-center">
                                <input type="radio" name="operation_mode" class="form-radio" value="other">
                                <span class="ml-2">Other...</span>
                            </label>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <div class="form-title">C. Services Required</div>
                        
                        <div class="mb-4">
                            <p class="font-bold">1. ATM-CUM-DEBIT CARD</p>
                            <div class="grid grid-cols-3 gap-4 mt-2">
                                <div>
                                    <label class="block">1st Applicant</label>
                                    <div class="mt-1">
                                        <label class="inline-flex items-center">
                                            <input type="radio" name="atm_card_1" class="form-radio" value="yes" checked>
                                            <span class="ml-2">Yes</span>
                                        </label>
                                        <label class="inline-flex items-center ml-4">
                                            <input type="radio" name="atm_card_1" class="form-radio" value="no">
                                            <span class="ml-2">No</span>
                                        </label>
                                    </div>
                                </div>
                                <div>
                                    <label class="block">2nd Applicant</label>
                                    <div class="mt-1">
                                        <label class="inline-flex items-center">
                                            <input type="radio" name="atm_card_2" class="form-radio" value="yes">
                                            <span class="ml-2">Yes</span>
                                        </label>
                                        <label class="inline-flex items-center ml-4">
                                            <input type="radio" name="atm_card_2" class="form-radio" value="no" checked>
                                            <span class="ml-2">No</span>
                                        </label>
                                    </div>
                                </div>
                                <div>
                                    <label class="block">Name as would appear on the card</label>
                                    <input type="text" class="form-control mt-1">
                                </div>
                            </div>
                            <p class="text-xs mt-2">Additional Factor of authentication is not mandatory for transactions on International E-Commerce merchant&Card will be supplied with international transactions disabled status which can be enabled with available channel as and when required&Card can be used for Contactless transaction upto limit prescribed by the Banks from time to time without PIN.</p>
                        </div>
                        
                        <div class="mb-4">
                            <p class="font-bold">2. CHEQUE BOOK</p>
                            <div class="mt-1">
                                <label class="inline-flex items-center">
                                    <input type="radio" name="cheque_book" class="form-radio" value="yes" checked>
                                    <span class="ml-2">Yes</span>
                                </label>
                                <label class="inline-flex items-center ml-4">
                                    <input type="radio" name="cheque_book" class="form-radio" value="no">
                                    <span class="ml-2">No</span>
                                </label>
                            </div>
                            <p class="text-xs mt-1">(Only for Regular SB/Current Accounts/Caps Gain(SB) (Not available for Regular BSDG/Small Accounts)</p>
                        </div>
                        
                        <div class="mb-4">
                            <p class="font-bold">3. INTERNET BANKING REQUIRED:</p>
                            <div class="grid grid-cols-3 gap-4 mt-2">
                                <div>
                                    <label class="block">1st Applicant</label>
                                    <div class="mt-1">
                                        <label class="inline-flex items-center">
                                            <input type="radio" name="internet_banking_1" class="form-radio" value="yes">
                                            <span class="ml-2">Yes</span>
                                        </label>
                                        <label class="inline-flex items-center ml-4">
                                            <input type="radio" name="internet_banking_1" class="form-radio" value="no" checked>
                                            <span class="ml-2">No</span>
                                        </label>
                                    </div>
                                </div>
                                <div>
                                    <label class="block">2nd Applicant</label>
                                    <div class="mt-1">
                                        <label class="inline-flex items-center">
                                            <input type="radio" name="internet_banking_2" class="form-radio" value="yes">
                                            <span class="ml-2">Yes</span>
                                        </label>
                                        <label class="inline-flex items-center ml-4">
                                            <input type="radio" name="internet_banking_2" class="form-radio" value="no" checked>
                                            <span class="ml-2">No</span>
                                        </label>
                                    </div>
                                </div>
                                <div>
                                    <label class="block">Transaction rights required</label>
                                    <div class="mt-1">
                                        <label class="inline-flex items-center">
                                            <input type="checkbox" class="form-checkbox">
                                            <span class="ml-2">Yes</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <p class="text-xs mt-2">(Available only for singly operated accounts and joint accounts operated by Either or Survivor mode, In case of accounts operated as Former or Survivor mode (No facility is available to 1st applicant-only)</p>
                        </div>
                        
                        <div class="mb-4">
                            <p class="font-bold">4. SMS ALERTS on Registered mobile number</p>
                            <div class="mt-1">
                                <label class="inline-flex items-center">
                                    <input type="radio" name="sms_alerts" class="form-radio" value="yes" checked>
                                    <span class="ml-2">Yes</span>
                                </label>
                                <label class="inline-flex items-center ml-4">
                                    <input type="radio" name="sms_alerts" class="form-radio" value="no">
                                    <span class="ml-2">No</span>
                                </label>
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <p class="font-bold">5. PHONE BANKING SERVICES:</p>
                            <div class="mt-1">
                                <label class="inline-flex items-center">
                                    <input type="radio" name="phone_banking" class="form-radio" value="yes">
                                    <span class="ml-2">Yes</span>
                                </label>
                                <label class="inline-flex items-center ml-4">
                                    <input type="radio" name="phone_banking" class="form-radio" value="no" checked>
                                    <span class="ml-2">No</span>
                                </label>
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <p class="font-bold">6. MOBILE BANKING:</p>
                            <div class="mt-1">
                                <label class="inline-flex items-center">
                                    <input type="radio" name="mobile_banking" class="form-radio" value="yes">
                                    <span class="ml-2">Yes</span>
                                </label>
                                <label class="inline-flex items-center ml-4">
                                    <input type="radio" name="mobile_banking" class="form-radio" value="no" checked>
                                    <span class="ml-2">No</span>
                                </label>
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <p class="font-bold">7. PASSBOOK REQUIRED:</p>
                            <div class="mt-1">
                                <label class="inline-flex items-center">
                                    <input type="radio" name="passbook" class="form-radio" value="yes" checked>
                                    <span class="ml-2">Yes</span>
                                </label>
                                <label class="inline-flex items-center ml-4">
                                    <input type="radio" name="passbook" class="form-radio" value="no">
                                    <span class="ml-2">No</span>
                                </label>
                            </div>
                            <p class="text-xs mt-1">(For Savings Bank Account)</p>
                        </div>
                        
                        <div class="mb-4">
                            <p class="font-bold">8. e-Statement (at monthly intervals), In lieu of paper copy:</p>
                            <div class="mt-1">
                                <label class="inline-flex items-center">
                                    <input type="radio" name="e_statement" class="form-radio" value="required">
                                    <span class="ml-2">Required</span>
                                </label>
                                <label class="inline-flex items-center ml-4">
                                    <input type="radio" name="e_statement" class="form-radio" value="not_required" checked>
                                    <span class="ml-2">Not Required</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <div class="form-title">D. Term Deposit</div>
                        
                        <p class="mb-4">1) In Case of Joint Accounts, Income Tax provision will applicable to primary / First Account holder only.</p>
                        
                        <div class="mb-4">
                            <p class="font-bold">D. (1) Fixed Deposit : For the following products/facilities, please furnish options/details:</p>
                            <div class="grid grid-cols-4 gap-4 mt-2">
                                <div>
                                    <label class="block">TERM DEPOSIT</label>
                                    <input type="radio" name="term_deposit_type" class="form-radio" value="term_deposit" checked>
                                </div>
                                <div>
                                    <label class="block">TERM DEPOSIT (REINVESTMENT)</label>
                                    <input type="radio" name="term_deposit_type" class="form-radio" value="term_deposit_reinvestment">
                                </div>
                                <div>
                                    <label class="block">ANNUITY DEPOSIT</label>
                                    <input type="radio" name="term_deposit_type" class="form-radio" value="annuity_deposit">
                                </div>
                                <div>
                                    <label class="block">TAX-SAVING SCHEME</label>
                                    <input type="radio" name="term_deposit_type" class="form-radio" value="tax_saving">
                                </div>
                                <div>
                                    <label class="block">CAPS GAIN (TDR)</label>
                                    <input type="radio" name="term_deposit_type" class="form-radio" value="caps_gain">
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-3 gap-4 mt-4">
                                <div>
                                    <label class="block">Amount: Rs.</label>
                                    <input type="text" class="form-control">
                                </div>
                                <div>
                                    <label class="block">Period:</label>
                                    <div class="flex">
                                        <input type="text" class="form-control w-16" placeholder="Year(s)">
                                        <input type="text" class="form-control w-16 ml-2" placeholder="Month(s)">
                                        <input type="text" class="form-control w-16 ml-2" placeholder="Days">
                                    </div>
                                </div>
                                <div>
                                    <label class="block">In case of Term Deposit, interest payable*</label>
                                    <select class="form-control">
                                        <option value="monthly">Monthly</option>
                                        <option value="quarterly">Quarterly</option>
                                        <option value="calendar_quarter">Calendar Quarter</option>
                                        <option value="half_yearly">Half Yearly</option>
                                        <option value="yearly">Yearly</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="mt-4">
                                <label class="block">Maturity instructions</label>
                                <div class="mt-1">
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="maturity_instruction" class="form-radio" value="auto_renew_principal" checked>
                                        <span class="ml-2">Auto renew* principal & payback interest</span>
                                    </label>
                                    <label class="inline-flex items-center ml-4">
                                        <input type="radio" name="maturity_instruction" class="form-radio" value="auto_renew_both">
                                        <span class="ml-2">Auto renew* principal & interest</span>
                                    </label>
 

                                    <label class="inline-flex items-center ml-4">
                                        <input type="radio" name="maturity_instruction" class="form-radio" value="pay_both">
                                        <span class="ml-2">Pay principal & interest</span>
                                    </label>
                                    <label class="inline-flex items-center ml-4">
                                        <input type="radio" name="maturity_instruction" class="form-radio" value="auto_renew_part">
                                        <span class="ml-2">Auto Renew* with part amount for Rs......</span>
                                    </label>
                                </div>
                                <p class="text-xs mt-1">* (Auto Renew will be done for the similar term at the prevailing interest rate on the date of renewal)</p>
                                <p class="text-xs">(a) All interest payable and Maturity instructions options will not be offered by all Banks. Contact respective Banks for the options available.)</p>
                                
                                <div class="mt-2">
                                    <label class="block">Payment instruction (Maturity Proceeds/Fee/skid amount):</label>
                                    <div class="mt-1">
                                        <label class="inline-flex items-center">
                                            <input type="radio" name="payment_instruction" class="form-radio" value="credit_account" checked>
                                            <span class="ml-2">By credit to my Bank Account No.</span>
                                            <input type="text" class="form-control ml-2 w-32">
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <p class="font-bold">D. (2) MULTI-OPTION DEPOSIT SCHEME (MOD) / AUTO SWEEP</p>
                            <div class="grid grid-cols-2 gap-4 mt-2">
                                <div>
                                    <label class="block">Type of Deposit</label>
                                    <div class="mt-1">
                                        <label class="inline-flex items-center">
                                            <input type="radio" name="mod_type" class="form-radio" value="term_deposit" checked>
                                            <span class="ml-2">Term Deposit</span>
                                        </label>
                                        <label class="inline-flex items-center ml-4">
                                            <input type="radio" name="mod_type" class="form-radio" value="term_deposit_reinvestment">
                                            <span class="ml-2">Term Deposit (Reinvestment)</span>
                                        </label>
                                    </div>
                                </div>
                                <div>
                                    <label class="block">Period of Deposit</label>
                                    <div class="flex">
                                        <input type="text" class="form-control w-16" placeholder="Year(s)">
                                        <input type="text" class="form-control w-16 ml-2" placeholder="Month(s)">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mt-4">
                                <p>(We hereby give consent for debiting my/our account for recovering service charges as normally applicable to Savings Bank and Current Account.</p>
                                <p>(We hereby give consent for debiting my/our Savings Bank/ Current Account for creating MODS/AUTO SWEEP as per the Terms and Conditions.</p>
                                
                                <div class="mt-2">
                                    <label class="block">Linked Savings Bank/Current Account No.</label>
                                    <input type="text" class="form-control w-64">
                                </div>
                                
                                <div class="mt-2">
                                    <label class="block">Under reverse sweep facility for breaking the MOD, the MOD to be broken by:*</label>
                                    <div class="mt-1">
                                        <label class="inline-flex items-center">
                                            <input type="radio" name="mod_break" class="form-radio" value="last_in_first_out" checked>
                                            <span class="ml-2">Last in first out</span>
                                        </label>
                                        <label class="inline-flex items-center ml-4">
                                            <input type="radio" name="mod_break" class="form-radio" value="first_in_first_out">
                                            <span class="ml-2">First in first out</span>
                                        </label>
                                    </div>
                                    <p class="text-xs mt-1">(* In case the applicant does not opt for any option, Last in first out will be the default option.)</p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <p class="font-bold">D. (3) RECURRING DEPOSIT</p>
                            <div class="grid grid-cols-3 gap-4 mt-2">
                                <div>
                                    <label class="block">Monthly / Core Monthly instalments: Rs.</label>
                                    <input type="text" class="form-control">
                                    <input type="text" class="form-control mt-1" placeholder="Rs. (in words)">
                                </div>
                                <div>
                                    <label class="block">Period:</label>
                                    <div class="flex">
                                        <input type="text" class="form-control w-16" placeholder="Years:">
                                        <input type="text" class="form-control w-16 ml-2" placeholder="Month(s)">
                                    </div>
                                </div>
                                <div>
                                    <label class="block">Standing Instruction (if any)</label>
                                    <input type="text" class="form-control" placeholder="Debit Account No.">
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-2 gap-4 mt-4">
                                <div>
                                    <label class="block">On Maturity, credit proceeds to Account No.</label>
                                    <input type="text" class="form-control">
                                </div>
                                <div>
                                    <label class="block">Issue Banker's Cheque / Draft</label>
                                    <input type="text" class="form-control">
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-3 gap-4 mt-4">
                                <div>
                                    <label class="block">Issue STDR for a period of</label>
                                    <div class="flex">
                                        <input type="text" class="form-control w-16" placeholder="Year(s)">
                                        <input type="text" class="form-control w-16 ml-2" placeholder="Month(s)">
                                        <input type="text" class="form-control w-16 ml-2" placeholder="Day(s)">
                                    </div>
                                </div>
                                <div>
                                    <label class="block">For the above Term Deposit Account, please deduct applicable TDS from</label>
                                    <input type="text" class="form-control" placeholder="(SB/CA Account No.)">
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <p class="font-bold">D. (4)</p>
                            <p>If Term Deposit Accounts are opened with operating instructions 'Either or Survivor' OR 'Former or Survivor', the signatures of both the depositors need not be obtained for payment of the amount of the deposits on maturity. However, signatures of both the depositors have to be obtained. In case the deposit is to be paid before maturity.</p>
                        </div>
                        
                        <div class="mb-4">
                            <p class="font-bold">D. (5)</p>
                            <p>A. If the operating instruction is 'Either or Survivor' and one of the depositors expires before maturity, no pre-payment of the term deposit may be allowed without concurrence of the legal heirs of the deceased/joint holder. This, however, would not stand in the way of making payment to the survivor on maturity.</p>
                            <p class="mt-2">If the operating instruction is 'Former or Outer' and if the former expires before maturity, the 'Survivor' can withdraw the deposit on maturity. Premature withdrawal would however require consent of the surviving depositor and legal heirs of the deceased, in case of death of one of the depositors.</p>
                            <p class="mt-2">B. Premature withdrawal of the deposit on death of one of the depositors: instead of the concurrence of legal heirs of the deceased depositors as provided in Clause D (5)(1), the Bank on death of any one of the may allow premature withdrawal of the deposit by the same group depositor without seeking consent from the legal heirs of the deceased depositor. This mandate will remain valid during the term of the deposit and also, during any renewed term(s) (whether for full or partial amount) unless, it is specifically withdrawn or modified by us jointly, either during the original or modified term(s), if any.</p>
                            
                            <div class="mt-4">
                                <label class="block">Please select:</label>
                                <div class="mt-1">
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="premature_withdrawal" class="form-radio" value="agree">
                                        <span class="ml-2">Yes, I/We agree. As a result, we understand that the guidelines contained in Clause D (5)(A) as regards premature withdrawal of the deposit on death of one of the depositors, shall not apply. Other guidelines contained in Clause D (5)(A) shall apply to the deposit.</span>
                                    </label>
                                    <label class="inline-flex items-center mt-2">
                                        <input type="radio" name="premature_withdrawal" class="form-radio" value="disagree" checked>
                                        <span class="ml-2">No, I/We do not agree. As a result, we understand that the guidelines contained in Clause D (5)(A) shall apply to the deposit in entirety.</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <div class="form-title">E. Saving Plus Account</div>
                        
                        <div class="grid grid-cols-3 gap-4">
                            <div>
                                <label class="block">Threshold</label>
                                <input type="text" class="form-control">
                            </div>
                            <div>
                                <label class="block">Restraint Balance</label>
                                <input type="text" class="form-control">
                            </div>
                            <div>
                                <label class="block">Sweep Multiple</label>
                                <input type="text" class="form-control">
                            </div>
                        </div>
                        
                        <div class="mt-4">
                            <label class="block">Frequency:</label>
                            <div class="grid grid-cols-4 gap-4 mt-1">
                                <label class="inline-flex items-center">
                                    <input type="radio" name="frequency" class="form-radio" value="weekly">
                                    <span class="ml-2">Weekly</span>
                                </label>
                                <label class="inline-flex items-center">
                                    <input type="radio" name="frequency" class="form-radio" value="fortnightly">
                                    <span class="ml-2">Fortnightly</span>
                                </label>
                                <label class="inline-flex items-center">
                                    <input type="radio" name="frequency" class="form-radio" value="monthly" checked>
                                    <span class="ml-2">Monthly</span>
                                </label>
                                <label class="inline-flex items-center">
                                    <input type="radio" name="frequency" class="form-radio" value="bi_monthly">
                                    <span class="ml-2">Bi-Monthly</span>
                                </label>
                                <label class="inline-flex items-center">
                                    <input type="radio" name="frequency" class="form-radio" value="quarterly">
                                    <span class="ml-2">Quarterly</span>
                                </label>
                                <label class="inline-flex items-center">
                                    <input type="radio" name="frequency" class="form-radio" value="half_yearly">
                                    <span class="ml-2">Half Yearly</span>
                                </label>
                                <label class="inline-flex items-center">
                                    <input type="radio" name="frequency" class="form-radio" value="yearly">
                                    <span class="ml-2">Yearly</span>
                                </label>
                            </div>
                        </div>
                        
                        <div class="mt-4">
                            <label class="block">MOD to be broken:</label>
                            <div class="mt-1">
                                <label class="inline-flex items-center">
                                    <input type="radio" name="mod_break_saving" class="form-radio" value="last_in_first_out" checked>
                                    <span class="ml-2">Last In First Out</span>
                                </label>
                                <label class="inline-flex items-center ml-4">
                                    <input type="radio" name="mod_break_saving" class="form-radio" value="first_in_first_out">
                                    <span class="ml-2">First In First Out</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <div class="form-title">F. Nomination (If required, fill Form DA-1)</div>
                        
                        <div class="mb-4">
                            <p class="font-bold">FORM DA-1 (Nomination Form)</p>
                            <p>Details of Nomination:</p>
                            <p>Nomination under section 452A of the Banking Regulation Act, 1949 and Rules 1985 in respect of Bank Deposits.</p>
                            
                            <div class="mt-2">
                                <label class="block">Registration No.</label>
                                <input type="text" class="form-control">
                            </div>
                            
                            <div class="mt-2">
                                <p>I/We <input type="text" class="form-control inline w-64" placeholder="(Name(s) and Address(es))"> nominate the following person to whom in the event of my/our/minor's death the amount of this deposit, particulars of which are given below, may be returned by the State Bank of India.</p>
                                <input type="text" class="form-control mt-1" placeholder="(Name & address of the branch / office in which the deposit is held.)">
                            </div>
                            
                            <div class="mt-2">
                                <label class="inline-flex items-center">
                                    <input type="checkbox" class="form-checkbox">
                                    <span class="ml-2">I/We want the name of the nominee to be printed on the passbook</span>
                                </label>
                            </div>
                            
                            <div class="mt-4">
                                <p class="font-bold">Details of Deposit: Type of Deposit: Account Number:</p>
                                <input type="text" class="form-control mt-1">
                            </div>
                            
                            <div class="mt-4">
                                <p class="font-bold">Details of Nominee</p>
                                <div class="grid grid-cols-2 gap-4 mt-2">
                                    <div>
                                        <label class="block">Name:</label>
                                        <input type="text" class="form-control">
                                    </div>
                                    <div>
                                        <label class="block">Address of the nominee:</label>
                                        <input type="text" class="form-control">
                                    </div>
                                    <div>
                                        <label class="block">Additional Details (if any):</label>
                                        <input type="text" class="form-control">
                                    </div>
                                    <div>
                                        <label class="block">Mobile Number of the Nominee</label>
                                        <input type="text" class="form-control">
                                    </div>
                                    <div>
                                        <label class="block">Date of Birth of nominee (in case of minor)</label>
                                        <input type="date" class="form-control">
                                    </div>
                                    <div>
                                        <label class="block">Relationship with the Depositor:</label>
                                        <input type="text" class="form-control">
                                    </div>
                                    <div>
                                        <label class="block">Age Years</label>
                                        <input type="text" class="form-control">
                                    </div>
                                </div>
                                
                                <div class="mt-4">
                                    <p>As the nominee is a minor on this date, I appoint Shri / Smt / Kum <input type="text" class="form-control inline w-32"> Age <input type="text" class="form-control inline w-16"> Years</p>
                                    <input type="text" class="form-control mt-1" placeholder="Address">
                                    <p class="mt-1">(to receive the amount of deposit on behalf of the nominee in the event of my / our / minor death during the minority of the nominee</p>
                                    <p class="text-xs mt-1">(Nomination in favour of other than individual is invalid)</p>
                                </div>
                                
                                <div class="mt-4">
                                    <label class="block">Signature of the Applicant / Thumb impression of the Applicant</label>
                                    <div class="border-b border-black h-20 mt-2"></div>
                                </div>
                                
                                <div class="mt-4">
                                    <label class="block">*Signature of the first witness</label>
                                    <div class="grid grid-cols-3 gap-4 mt-2">
                                        <div>
                                            <label class="block">Name:</label>
                                            <input type="text" class="form-control">
                                        </div>
                                        <div>
                                            <label class="block">Signature:</label>
                                            <div class="border-b border-black h-12 mt-2"></div>
                                        </div>
                                        <div>
                                            <label class="block">Address</label>
                                            <input type="text" class="form-control">
                                        </div>
                                    </div>
                                    <p class="text-xs mt-1">(*Witnesses are mandatory only in case of the applicant is affixing his/her thumb impression)</p>
                                </div>
                                
                                <div class="mt-4">
                                    <label class="inline-flex items-center">
                                        <input type="checkbox" class="form-checkbox">
                                        <span class="ml-2">I/We do not want to nominate any person in this account</span>
                                    </label>
                                </div>
                                
                                <div class="mt-4">
                                    <label class="block">Signature of the Applicant / Thumb impression of the Applicant</label>
                                    <div class="border-b border-black h-20 mt-2"></div>
                                </div>
                                
                                <div class="mt-4">
                                    <label class="block">*Signature of the second witness</label>
                                    <div class="grid grid-cols-3 gap-4 mt-2">
                                        <div>
                                            <label class="block">Name:</label>
                                            <input type="text" class="form-control">
                                        </div>
                                        <div>
                                            <label class="block">Signature:</label>
                                            <div class="border-b border-black h-12 mt-2"></div>
                                        </div>
                                        <div>
                                            <label class="block">Address</label>
                                            <input type="text" class="form-control">
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mt-4">
                                    <div class="grid grid-cols-2 gap-4">
                                        <div>
                                            <label class="block">Date</label>
                                            <input type="date" class="form-control">
                                        </div>
                                        <div>
                                            <label class="block">Place</label>
                                            <input type="text" class="form-control">
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mt-4">
                                    <label class="block">Signature of the Applicant / Thumb impression of the Applicant</label>
                                    <div class="border-b border-black h-20 mt-2"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <div class="form-title">G. DECLARATION CUM UNDERTAKING CUM SELF - CERTIFICATION</div>
                        
                        <div class="mb-4">
                            <p>1. I/We have read the copy of Terms and Conditions of the Account Opening Form given to me / us. The Terms and Conditions have been explained to me/us and having understood, I/we accept the same (in case of those accounts)</p>
                            <p>2. I hereby declare that the date of birth of the minor who is my <input type="text" class="form-control inline w-32"> and am his/her natural and lawful guardian/guardian appointed by court order dated <input type="date" class="form-control inline"> (copy enclosed) shall represent the said minor in all future transactions of any description in the above account until the said minor attains majority. I shall indemnify the bank against the claim of the above minor for any withdrawal/(transactions made by me in his/her account).</p>
                            <p>3. I hereby declare that I do not maintain a Basic Savings Bank Deposit Account (BSBDA) with any other Bank/Branch (Applicable in case of BSBD Account)</p>
                        </div>
                        
                        <div class="mt-4">
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block">Place:</label>
                                    <input type="text" class="form-control">
                                </div>
                                <div>
                                    <label class="block">Date:</label>
                                    <input type="date" class="form-control">
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-4">
                            <label class="block">Signature of the Applicant / Thumb impression of the Applicant</label>
                            <div class="border-b border-black h-20 mt-2"></div>
                        </div>
                        
                        <div class="mt-4">
                            <label class="block">Signature of the Applicant / Thumb impression of the Applicant</label>
                            <div class="border-b border-black h-20 mt-2"></div>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <div class="form-title">FOR OFFICE USE / ATTESTATION</div>
                        <p class="text-xs italic">(for office use only)</p>
                        
                        <div class="grid grid-cols-3 gap-4 mt-4">
                            <div>
                                <label class="block">Open Account</label>
                                <div class="border-b border-black h-12 mt-2"></div>
                                <p class="text-xs">Date: (Authorised signatory)</p>
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-4 gap-4 mt-4">
                            <div>
                                <label class="block">i) Internet Banking MBI Kit No.:</label>
                                <input type="text" class="form-control" placeholder="INT.TALS">
                            </div>
                            <div>
                                <label class="block">ii) NB Viewing rights Transaction rights given on:</label>
                                <input type="text" class="form-control" placeholder="INT.TALS">
                            </div>
                            <div>
                                <label class="block">iii) ATM Card data transmitted on:</label>
                                <input type="text" class="form-control" placeholder="INT.TALS">
                            </div>
                            <div>
                                <label class="block">iv) Nomination Serial No.:</label>
                                <input type="text" class="form-control" placeholder="INT.TALS">
                            </div>
                            <div>
                                <label class="block">v) Threshold RYC Limit:</label>
                                <input type="text" class="form-control" placeholder="INT.TALS">
                            </div>
                            <div>
                                <label class="block">vi) Phone Banking</label>
                                <input type="text" class="form-control" placeholder="INT.TALS">
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-8 gap-4 mt-4">
                            <div>
                                <label class="block">Queue No.</label>
                                <input type="text" class="form-control">
                            </div>
                            <div>
                                <label class="block">Initials</label>
                                <input type="text" class="form-control">
                            </div>
                            <div>
                                <label class="block">Account</label>
                                <input type="text" class="form-control">
                            </div>
                            <div>
                                <label class="block">CFI Linking</label>
                                <input type="text" class="form-control">
                            </div>
                            <div>
                                <label class="block">Personalised Cheque</label>
                                <input type="text" class="form-control">
                            </div>
                            <div>
                                <label class="block">RMB</label>
                                <input type="text" class="form-control">
                            </div>
                            <div>
                                <label class="block">MBS</label>
                                <input type="text" class="form-control">
                            </div>
                            <div>
                                <label class="block">SMS Alert</label>
                                <input type="text" class="form-control">
                            </div>
                            <div>
                                <label class="block">Removal of Posting</label>
                                <input type="text" class="form-control">
                            </div>
                            <div>
                                <label class="block">Scanning</label>
                                <input type="text" class="form-control">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Account Information Section (for form processing) -->
                <div class="form-section bg-gray-100 p-4 mt-6">
                    <h2 class="text-lg font-bold mb-4">Account Information (For Bank Use)</h2>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="required-field">Account Type*</label>
                            <select name="account_type_id" required class="form-control">
                                <option value="">Select Account Type</option>
                                <?php foreach ($account_types as $type): ?>
                                    <option value="<?= $type['type_id'] ?>" <?= (isset($_POST['account_type_id']) && $_POST['account_type_id'] == $type['type_id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($type['type_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($errors['account_type_id'])): ?>
                                <p class="text-red-500 text-xs"><?= $errors['account_type_id'] ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label class="required-field">Account Name*</label>
                            <input type="text" name="account_name" required class="form-control" 
                                   value="<?= isset($_POST['account_name']) ? htmlspecialchars($_POST['account_name']) : '' ?>">
                            <?php if (isset($errors['account_name'])): ?>
                                <p class="text-red-500 text-xs"><?= $errors['account_name'] ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="required-field">Initial Deposit* (USD)</label>
                            <input type="number" name="initial_deposit" required class="form-control" min="0" step="0.01"
                                   value="<?= isset($_POST['initial_deposit']) ? htmlspecialchars($_POST['initial_deposit']) : '' ?>">
                            <?php if (isset($errors['initial_deposit'])): ?>
                                <p class="text-red-500 text-xs"><?= $errors['initial_deposit'] ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="mt-6 flex justify-between">
                    <a href="customer_management" class="bg-gray-500 hover:bg-gray-700 text-white px-4 py-2 rounded">Cancel</a>
                    <button type="submit" class="bg-blue-500 hover:bg-blue-700 text-white px-4 py-2 rounded">Submit Account Opening Form</button>
                </div>
            </form>
        </div>
    <?php else: ?>
        <!-- Customer List View -->
        <div class="bg-white p-6 rounded-lg shadow">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-xl font-bold">Customer Accounts</h2>
                <div class="flex items-center">
                    <input type="text" id="searchInput" placeholder="Search customers..." class="border rounded px-3 py-1">
                    <span class="ml-2 text-sm text-gray-600"><?= count($customers) ?> records</span>
                </div>
            </div>
            
            <div class="overflow-x-auto">
                <table class="min-w-full bg-white">
                    <thead>
                        <tr class="bg-gray-200 text-gray-700">
                            <th class="py-2 px-4 text-left">Customer ID</th>
                            <th class="py-2 px-4 text-left">Name</th>
                            <th class="py-2 px-4 text-left">Email</th>
                            <th class="py-2 px-4 text-left">Phone</th>
                            <th class="py-2 px-4 text-left">Account No.</th>
                            <th class="py-2 px-4 text-left">Account Type</th>
                            <th class="py-2 px-4 text-left">Balance</th>
                            <th class="py-2 px-4 text-left">Opened By</th>
                            <th class="py-2 px-4 text-left">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="customerTable">
                        <?php if (empty($customers)): ?>
                            <tr>
                                <td colspan="9" class="py-4 px-4 text-center text-gray-500">No customers found</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($customers as $customer): ?>
                                <tr class="border-b hover:bg-gray-50">
                                    <td class="py-2 px-4"><?= htmlspecialchars($customer['customer_id']) ?></td>
                                    <td class="py-2 px-4">
                                        <?= htmlspecialchars($customer['first_name'] . ' ' . $customer['last_name']) ?>
                                        <?php if ($customer['is_minor']): ?>
                                            <span class="text-xs bg-yellow-100 text-yellow-800 px-2 py-1 rounded ml-2">Minor</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-2 px-4"><?= htmlspecialchars($customer['email']) ?></td>
                                    <td class="py-2 px-4"><?= htmlspecialchars($customer['phone']) ?></td>
                                    <td class="py-2 px-4">
                                        <?= $customer['main_account_number'] ? htmlspecialchars($customer['main_account_number']) : 'N/A' ?>
                                    </td>
                                    <td class="py-2 px-4">
                                        <?= $customer['main_account_type'] ? htmlspecialchars($customer['main_account_type']) : 'N/A' ?>
                                    </td>
                                    <td class="py-2 px-4">
                                        <?= $customer['main_account_balance'] ? '$' . number_format($customer['main_account_balance'], 2) : 'N/A' ?>
                                    </td>
                                    <td class="py-2 px-4">
                                        <?= $customer['employees_first_name'] ? htmlspecialchars($customer['employees_first_name'] . ' ' . $customer['employees_last_name']) : 'System' ?>
                                    </td>
                                    <td class="py-2 px-4">
                                        <div class="flex space-x-2">
                                            <a href="customer_details?id=<?= $customer['customer_id'] ?>" class="text-blue-500 hover:text-blue-700">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                                    <path d="M10 12a2 2 0 100-4 2 2 0 000 4z" />
                                                    <path fill-rule="evenodd" d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clip-rule="evenodd" />
                                                </svg>
                                            </a>
                                            <a href="?action=edit&id=<?= $customer['customer_id'] ?>" class="text-green-500 hover:text-green-700">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                                    <path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z" />
                                                </svg>
                                            </a>
                                            <?php if ($employee_role == 'manager' || $employee_role == 'admin'): ?>
                                                <a href="?action=delete&id=<?= $customer['customer_id'] ?>" class="text-red-500 hover:text-red-700" onclick="return confirm('Are you sure you want to delete this customer and all associated accounts?')">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                                        <path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd" />
                                                    </svg>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>
<!-- Add these in the head section -->
<script src="https://cdn.jsdelivr.net/npm/@tensorflow/tfjs@2.0.0/dist/tf.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>
<script>

document.getElementById('accountForm').addEventListener('submit', function(e) {
    // Check if photo was captured
    const capturedPhoto = document.getElementById('captured-photo').value;
    if (!capturedPhoto) {
        e.preventDefault();
        Swal.fire({
            icon: 'error',
            title: 'Photo Required',
            text: 'Please capture a photo using the camera',
        });
        return;
    }
    
    // Rest of your validation code...
});

    // Camera and face detection functionality
let stream = null;
let capturedImage = null;

// Load face detection models
async function loadModels() {
    await faceapi.nets.tinyFaceDetector.loadFromUri('https://justadudewhohacks.github.io/face-api.js/models');
    await faceapi.nets.faceLandmark68Net.loadFromUri('https://justadudewhohacks.github.io/face-api.js/models');
}

// Initialize camera
document.getElementById('start-camera').addEventListener('click', async function() {
    try {
        await loadModels();
        
        stream = await navigator.mediaDevices.getUserMedia({ 
            video: { 
                width: 320, 
                height: 240,
                facingMode: 'user' 
            }, 
            audio: false 
        });
        
        const video = document.getElementById('camera-feed');
        video.srcObject = stream;
        
        this.classList.add('hidden');
        document.getElementById('capture-btn').classList.remove('hidden');
        
        // Start face detection
        detectFaces();
    } catch (err) {
        console.error("Error accessing camera: ", err);
        Swal.fire('Camera Error', 'Could not access the camera. Please make sure you have granted camera permissions.', 'error');
    }
});

// Face detection function
async function detectFaces() {
    const video = document.getElementById('camera-feed');
    const overlay = document.getElementById('face-overlay');
    
    if (!stream) return;
    
    const displaySize = { width: video.width, height: video.height };
    faceapi.matchDimensions(overlay, displaySize);
    
    setInterval(async () => {
        const detections = await faceapi.detectAllFaces(video, new faceapi.TinyFaceDetectorOptions())
            .withFaceLandmarks();
        
        if (detections.length > 0) {
            const resizedDetections = faceapi.resizeResults(detections, displaySize);
            const box = resizedDetections[0].detection.box;
            
            // Position overlay on the face
            overlay.style.width = `${box.width * 1.5}px`;
            overlay.style.height = `${box.height * 1.5}px`;
            overlay.style.left = `${box.x - (box.width * 0.25)}px`;
            overlay.style.top = `${box.y - (box.height * 0.25)}px`;
            overlay.classList.remove('hidden');
        } else {
            overlay.classList.add('hidden');
        }
    }, 100);
}

// Capture photo
document.getElementById('capture-btn').addEventListener('click', function() {
    const video = document.getElementById('camera-feed');
    const canvas = document.getElementById('camera-canvas');
    const context = canvas.getContext('2d');
    
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    context.drawImage(video, 0, 0, canvas.width, canvas.height);
    
    // Stop camera stream
    if (stream) {
        stream.getTracks().forEach(track => track.stop());
        stream = null;
    }
    
    // Process the image (simple background removal - you might want to use a more sophisticated solution)
    processImage(canvas);
    
    // Show preview
    capturedImage = canvas.toDataURL('image/jpeg');
    document.getElementById('photo-preview').src = capturedImage;
    document.getElementById('photo-preview-container').classList.remove('hidden');
    document.getElementById('captured-photo').value = capturedImage;
    
    // Update buttons
    this.classList.add('hidden');
    document.getElementById('retake-btn').classList.remove('hidden');
});

// Retake photo
document.getElementById('retake-btn').addEventListener('click', function() {
    document.getElementById('photo-preview-container').classList.add('hidden');
    document.getElementById('captured-photo').value = '';
    document.getElementById('start-camera').click();
    this.classList.add('hidden');
});

// Simple background removal (basic implementation)
function processImage(canvas) {
    const context = canvas.getContext('2d');
    const imageData = context.getImageData(0, 0, canvas.width, canvas.height);
    const data = imageData.data;
    
    // This is a very basic background removal - for production, consider using a proper library
    for (let i = 0; i < data.length; i += 4) {
        // Simple green screen effect (adjust thresholds as needed)
        const r = data[i];
        const g = data[i + 1];
        const b = data[i + 2];
        
        // If pixel is within face area or doesn't match background, keep it
        if (!(g > r && g > b && g > 100)) { // Adjust this condition based on your needs
            // Keep the pixel
        } else {
            // Make pixel transparent
            data[i + 3] = 0;
        }
    }
    
    context.putImageData(imageData, 0, 0);
}
    // Photo preview functionality
    document.getElementById('photoUpload').addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(event) {
                const preview = document.getElementById('photoPreview');
                preview.src = event.target.result;
                preview.style.display = 'block';
            };
            reader.readAsDataURL(file);
        }
    });

    // Guardian section toggle based on age
    document.getElementById('dobInput').addEventListener('change', function() {
        const dob = new Date(this.value);
        const today = new Date();
        let age = today.getFullYear() - dob.getFullYear();
        const monthDiff = today.getMonth() - dob.getMonth();
        
        if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < dob.getDate())) {
            age--;
        }
        
        const guardianSection = document.querySelector('.guardian-section');
        if (age < 18) {
            guardianSection.style.display = 'block';
            document.getElementById('guardianNameInput').required = true;
        } else {
            guardianSection.style.display = 'none';
            document.getElementById('guardianNameInput').required = false;
        }
    });

    // Search functionality
    document.getElementById('searchInput').addEventListener('keyup', function() {
        const input = this.value.toLowerCase();
        const rows = document.querySelectorAll('#customerTable tr');
        
        rows.forEach(row => {
            if (row.textContent.toLowerCase().indexOf(input) > -1) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    });

     // Form submission confirmation
     document.getElementById('accountForm').addEventListener('submit', function(e) {
        // Validate form before submission
        const requiredFields = this.querySelectorAll('[required]');
        let isValid = true;
        
        requiredFields.forEach(field => {
            if (!field.value.trim()) {
                isValid = false;
                field.classList.add('border-red-500');
            } else {
                field.classList.remove('border-red-500');
            }
        });
        
        if (!isValid) {
            e.preventDefault();
            Swal.fire({
                icon: 'error',
                title: 'Missing Information',
                text: 'Please fill in all required fields',
            });
            return;
        }
        
        // Confirm submission
        e.preventDefault();
        Swal.fire({
            title: 'Confirm Account Opening',
            text: 'Are you sure you want to submit this account opening form?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Yes, submit it!'
        }).then((result) => {
            if (result.isConfirmed) {
                this.submit();
            }
        });
    });

    // Auto-format phone number
    document.querySelector('input[name="phone"]').addEventListener('input', function(e) {
        let value = this.value.replace(/\D/g, '');
        if (value.length > 10) {
            value = value.substring(0, 10);
        }
        this.value = value;
    });

    // Auto-format names to uppercase
    document.querySelector('input[name="first_name"]').addEventListener('input', function(e) {
        this.value = this.value.toUpperCase();
    });
    document.querySelector('input[name="last_name"]').addEventListener('input', function(e) {
        this.value = this.value.toUpperCase();
    });
    document.querySelector('input[name="id_proof_number"]').addEventListener('input', function(e) {
        this.value = this.value.toUpperCase();
    });

    // Initialize date picker with max date as today
    document.getElementById('dobInput').max = new Date().toISOString().split('T')[0];
</script>

<?php include 'footer.php'; ?>