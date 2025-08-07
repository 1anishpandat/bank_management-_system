<?php
// edit_customer.php
session_start();
require 'db_connect.php';
require 'security_functions.php';

// Authentication check
if (!isset($_SESSION['employee_id'])) {
    header("Location: bank_login.php");
    exit();
}

$employee_role = $_SESSION['role'] ?? 'teller';
// Only managers/admins can edit
if ($employee_role != 'manager' && $employee_role != 'admin') {
    header("Location: customer_management.php");
    exit();
}

$customer_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($customer_id <= 0) {
    header("Location: customer_management.php");
    exit();
}

function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Get customer data with account information
$customer = [];
$stmt = $conn->prepare("
    SELECT c.*, 
           a.account_number as main_account_number,
           a.balance as main_account_balance,
           at.type_name as main_account_type,
           e.employees_first_name,
           e.employees_last_name,
           b.bank_name
    FROM customers c
    LEFT JOIN accounts a ON c.customer_id = a.user_id
    LEFT JOIN account_types at ON a.account_type_id = at.type_id
    LEFT JOIN employee e ON a.employee_id = e.employee_id
    LEFT JOIN bank_details b ON c.bank_id = b.bank_id
    WHERE c.customer_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $customer_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $customer = $result->fetch_assoc();
} else {
    header("Location: customer_management.php");
    exit();
}
$stmt->close();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $errors = [];
    
    // Validate and sanitize input
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
    if (empty($date_of_birth)) {
        $errors['date_of_birth'] = "Date of birth is required";
    } else {
        $dob = new DateTime($date_of_birth);
        $today = new DateTime();
        $age = $today->diff($dob)->y;
        if ($age < 5) {
            $errors['date_of_birth'] = "Customer must be at least 5 years old";
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

    // Handle photo upload
    $photo_path = $customer['photo_path']; // Keep existing photo by default
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] == UPLOAD_ERR_OK) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
        $file_info = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($file_info, $_FILES['photo']['tmp_name']);
        
        if (in_array($mime_type, $allowed_types)) {
            $upload_dir = 'uploads/customer_photos/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            // Delete old photo if exists
            if (!empty($photo_path) && file_exists($photo_path)) {
                unlink($photo_path);
            }
            
            $extension = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
            $filename = 'photo_' . uniqid() . '.' . $extension;
            $destination = $upload_dir . $filename;
            
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $destination)) {
                $photo_path = $destination;
            } else {
                $errors['photo'] = "Error moving uploaded file.";
            }
        } else {
            $errors['photo'] = "Invalid file type. Only JPG, PNG, and GIF are allowed.";
        }
    }

    if (empty($errors)) {
        try {
            $stmt = $conn->prepare("UPDATE customers SET 
                first_name = ?, 
                last_name = ?, 
                email = ?, 
                phone = ?, 
                address = ?, 
                date_of_birth = ?, 
                id_proof_type = ?, 
                id_proof_number = ?,
                photo_path = ?,
                updated_at = NOW()
                WHERE customer_id = ?");
            
            $stmt->bind_param("sssssssssi", 
                $first_name,
                $last_name,
                $email,
                $phone,
                $address,
                $date_of_birth,
                $id_proof_type,
                $id_proof_number,
                $photo_path,
                $customer_id
            );
            
            if ($stmt->execute()) {
                $success_message = "Customer updated successfully!";
                // Update the customer array with new values
                $customer['first_name'] = $first_name;
                $customer['last_name'] = $last_name;
                $customer['email'] = $email;
                $customer['phone'] = $phone;
                $customer['address'] = $address;
                $customer['date_of_birth'] = $date_of_birth;
                $customer['id_proof_type'] = $id_proof_type;
                $customer['id_proof_number'] = $id_proof_number;
                $customer['photo_path'] = $photo_path;
            } else {
                $error_message = "Error updating customer: " . $stmt->error;
            }
            $stmt->close();
        } catch (Exception $e) {
            $error_message = "Error: " . $e->getMessage();
        }
    } else {
        $error_message = "Please correct the errors below.";
    }
}

include 'header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Customer</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
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
    </style>
</head>
<body class="bg-gray-100">

<div class="container mx-auto p-4">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">Edit Customer Details</h1>
        <div>
            <a href="customer_management" class="bg-gray-500 hover:bg-gray-700 text-white px-4 py-2 rounded">Back to Customer List</a>
            <a href="dashboard" class="bg-gray-500 hover:bg-gray-700 text-white px-4 py-2 rounded ml-2">Back to Dashboard</a>
        </div>
    </div>

    <?php if (isset($success_message)): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
            <?= htmlspecialchars($success_message) ?>
        </div>
    <?php endif; ?>

    <?php if (isset($error_message)): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
            <?= htmlspecialchars($error_message) ?>
            <?php if (!empty($errors)): ?>
                <ul class="mt-2 list-disc list-inside">
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="bg-white p-6 rounded-lg shadow">
        <form method="POST" enctype="multipart/form-data">
            <div class="flex flex-col md:flex-row gap-6">
                <!-- Customer Photo Section -->
                <div class="w-full md:w-1/3">
                    <div class="border rounded-lg p-4">
                        <div class="text-center mb-4">
                            <?php if (!empty($customer['photo_path'])): ?>
                                <img src="<?= htmlspecialchars($customer['photo_path']) ?>" 
                                     alt="Customer Photo" 
                                     class="w-48 h-48 object-cover rounded-full mx-auto">
                            <?php else: ?>
                                <div class="w-48 h-48 bg-gray-200 rounded-full mx-auto flex items-center justify-center">
                                    <span class="text-gray-500">No Photo</span>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Update Photo</label>
                            <input type="file" name="photo" accept="image/jpeg,image/png,image/gif" 
                                   class="block w-full text-sm text-gray-500
                                          file:mr-4 file:py-2 file:px-4
                                          file:rounded-md file:border-0
                                          file:text-sm file:font-semibold
                                          file:bg-blue-50 file:text-blue-700
                                          hover:file:bg-blue-100">
                            <?php if (isset($errors['photo'])): ?>
                                <p class="text-red-500 text-xs"><?= htmlspecialchars($errors['photo']) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Customer Details Section -->
                <div class="w-full md:w-2/3">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">First Name:</label>
                            <input type="text" name="first_name" required class="form-control uppercase" 
                                   value="<?= htmlspecialchars($customer['first_name']) ?>">
                            <?php if (isset($errors['first_name'])): ?>
                                <p class="text-red-500 text-xs"><?= htmlspecialchars($errors['first_name']) ?></p>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Last Name:</label>
                            <input type="text" name="last_name" required class="form-control uppercase" 
                                   value="<?= htmlspecialchars($customer['last_name']) ?>">
                            <?php if (isset($errors['last_name'])): ?>
                                <p class="text-red-500 text-xs"><?= htmlspecialchars($errors['last_name']) ?></p>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Email:</label>
                            <input type="email" name="email" required class="form-control" 
                                   value="<?= htmlspecialchars($customer['email']) ?>">
                            <?php if (isset($errors['email'])): ?>
                                <p class="text-red-500 text-xs"><?= htmlspecialchars($errors['email']) ?></p>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Phone:</label>
                            <input type="tel" name="phone" required class="form-control" 
                                   value="<?= htmlspecialchars($customer['phone']) ?>">
                            <?php if (isset($errors['phone'])): ?>
                                <p class="text-red-500 text-xs"><?= htmlspecialchars($errors['phone']) ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700">Address:</label>
                            <textarea name="address" required class="form-control" rows="3"><?= nl2br(htmlspecialchars($customer['address'])) ?></textarea>
                            <?php if (isset($errors['address'])): ?>
                                <p class="text-red-500 text-xs"><?= htmlspecialchars($errors['address']) ?></p>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Date of Birth:</label>
                            <input type="date" name="date_of_birth" required class="form-control" 
                                   value="<?= htmlspecialchars($customer['date_of_birth']) ?>">
                            <?php if (isset($errors['date_of_birth'])): ?>
                                <p class="text-red-500 text-xs"><?= htmlspecialchars($errors['date_of_birth']) ?></p>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">ID Proof Type:</label>
                            <select name="id_proof_type" required class="form-control">
                                <option value="Passport" <?= $customer['id_proof_type'] == 'Passport' ? 'selected' : '' ?>>Passport</option>
                                <option value="Driver License" <?= $customer['id_proof_type'] == 'Driver License' ? 'selected' : '' ?>>Driver License</option>
                                <option value="National ID" <?= $customer['id_proof_type'] == 'National ID' ? 'selected' : '' ?>>National ID</option>
                                <option value="PAN Card" <?= $customer['id_proof_type'] == 'PAN Card' ? 'selected' : '' ?>>PAN Card</option>
                                <option value="Aadhaar Card" <?= $customer['id_proof_type'] == 'Aadhaar Card' ? 'selected' : '' ?>>Aadhaar Card</option>
                                <option value="Birth Certificate" <?= $customer['id_proof_type'] == 'Birth Certificate' ? 'selected' : '' ?>>Birth Certificate</option>
                            </select>
                            <?php if (isset($errors['id_proof_type'])): ?>
                                <p class="text-red-500 text-xs"><?= htmlspecialchars($errors['id_proof_type']) ?></p>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">ID Proof Number:</label>
                            <input type="text" name="id_proof_number" required class="form-control uppercase" 
                                   value="<?= htmlspecialchars($customer['id_proof_number']) ?>">
                            <?php if (isset($errors['id_proof_number'])): ?>
                                <p class="text-red-500 text-xs"><?= htmlspecialchars($errors['id_proof_number']) ?></p>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Bank Name:</label>
                            <p class="mt-1 text-gray-900"><?= htmlspecialchars($customer['bank_name'] ?? 'N/A') ?></p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Main Account Number:</label>
                            <p class="mt-1 text-gray-900"><?= htmlspecialchars($customer['main_account_number'] ?? 'N/A') ?></p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Account Type:</label>
                            <p class="mt-1 text-gray-900"><?= htmlspecialchars($customer['main_account_type'] ?? 'N/A') ?></p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Account Balance:</label>
                            <p class="mt-1 text-gray-900">$<?= number_format($customer['main_account_balance'] ?? 0, 2) ?></p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Created Date:</label>
                            <p class="mt-1 text-gray-900"><?= htmlspecialchars($customer['created_at']) ?></p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Last Updated:</label>
                            <p class="mt-1 text-gray-900"><?= htmlspecialchars($customer['updated_at'] ?? 'N/A') ?></p>
                        </div>
                    </div>

                    <div class="mt-6 flex justify-end">
                        <button type="submit" class="bg-blue-500 hover:bg-blue-700 text-white px-4 py-2 rounded">
                            Update Customer
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
    // Form submission confirmation
    document.querySelector('form').addEventListener('submit', function(e) {
        e.preventDefault();
        
        Swal.fire({
            title: 'Confirm Update',
            text: 'Are you sure you want to update this customer information?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Yes, update it!'
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
</script>

<?php include 'footer.php'; ?>
</body>
</html>