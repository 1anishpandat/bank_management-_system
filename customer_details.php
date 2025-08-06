<?php
// customer_details.php
session_start();
require 'db_connect.php';
require 'security_functions.php';

// Authentication check
if (!isset($_SESSION['employee_id'])) {
    header("Location: bank_login.php");
    exit();
}

$customer = null;
$error_message = '';
$success_message = '';

// Handle delete action
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id']) && is_numeric($_GET['id'])) {
    $customer_id = (int)$_GET['id'];
    
    // Verify user has permission to delete
    if (($_SESSION['role'] ?? 'teller') == 'manager' || ($_SESSION['role'] ?? 'teller') == 'admin') {
        // First get the photo path to delete the file
        $stmt = $conn->prepare("SELECT photo_path FROM customers WHERE customer_id = ?");
        if ($stmt === false) {
            $error_message = "SQL prepare error: " . $conn->error;
        } else {
            $stmt->bind_param("i", $customer_id);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($result->num_rows > 0) {
                    $customer = $result->fetch_assoc();
                    // Delete the photo file if it exists
                    if (!empty($customer['photo_path']) && file_exists($customer['photo_path'])) {
                        unlink($customer['photo_path']);
                    }
                }
                $stmt->close();
                
                // Now delete the customer record
                $stmt = $conn->prepare("DELETE FROM customers WHERE customer_id = ?");
                if ($stmt === false) {
                    $error_message = "SQL prepare error: " . $conn->error;
                } else {
                    $stmt->bind_param("i", $customer_id);
                    if ($stmt->execute()) {
                        $success_message = "Customer deleted successfully.";
                        // Redirect to customer list after deletion
                        header("Location: customer_management.php?success=" . urlencode($success_message));
                        exit();
                    } else {
                        $error_message = "Error deleting customer: " . $stmt->error;
                    }
                    $stmt->close();
                }
            } else {
                $error_message = "Error fetching customer details: " . $stmt->error;
                $stmt->close();
            }
        }
    } else {
        $error_message = "You don't have permission to delete customers.";
    }
}

// Handle photo upload if form was submitted
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_photo']) && isset($_GET['id']) && is_numeric($_GET['id'])) {
    $customer_id = (int)$_GET['id'];
    
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
}

// Check if customer_id is provided in the URL for viewing
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $customer_id = (int)$_GET['id'];

    // Prepare and execute the query to fetch customer details
    $stmt = $conn->prepare("SELECT c.*, 
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
                    LIMIT 1");

    if ($stmt === false) {
        $error_message = "SQL prepare error: " . $conn->error;
    } else {
        $stmt->bind_param("i", $customer_id);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                $customer = $result->fetch_assoc();
            } else {
                $error_message = "Customer not found.";
            }
        } else {
            $error_message = "Error fetching customer details: " . $stmt->error;
        }
        $stmt->close();
    }
} else {
    $error_message = "No customer ID provided.";
}

include 'header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Details</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">

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
        <h1 class="text-2xl font-bold">Customer Details</h1>
        <div>
            <a href="customer_management" class="bg-gray-500 hover:bg-gray-700 text-white px-4 py-2 rounded">Back to Customer List</a>
            <a href="dashboard" class="bg-gray-500 hover:bg-gray-700 text-white px-4 py-2 rounded ml-2">Back to Dashboard</a>
        </div>
    </div>

    <?php if ($customer): ?>
        <div class="bg-white p-6 rounded-lg shadow">
            <h2 class="text-xl font-semibold mb-4">Details for Customer ID: <?= htmlspecialchars($customer['customer_id']) ?></h2>
            
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
                        
                        <?php if (($_SESSION['role'] ?? 'teller') == 'manager' || ($_SESSION['role'] ?? 'teller') == 'admin'): ?>
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
                
                <!-- Customer Details Section -->
                <div class="w-full md:w-2/3">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">First Name:</label>
                            <p class="mt-1 text-gray-900"><?= htmlspecialchars($customer['first_name']) ?></p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Last Name:</label>
                            <p class="mt-1 text-gray-900"><?= htmlspecialchars($customer['last_name']) ?></p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Email:</label>
                            <p class="mt-1 text-gray-900"><?= htmlspecialchars($customer['email']) ?></p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Phone:</label>
                            <p class="mt-1 text-gray-900"><?= htmlspecialchars($customer['phone']) ?></p>
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700">Address:</label>
                            <p class="mt-1 text-gray-900"><?= nl2br(htmlspecialchars($customer['address'])) ?></p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Date of Birth:</label>
                            <p class="mt-1 text-gray-900"><?= htmlspecialchars($customer['date_of_birth']) ?></p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">ID Proof Type:</label>
                            <p class="mt-1 text-gray-900"><?= htmlspecialchars($customer['id_proof_type']) ?></p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">ID Proof Number:</label>
                            <p class="mt-1 text-gray-900"><?= htmlspecialchars($customer['id_proof_number']) ?></p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Bank Name:</label>
                            <p class="mt-1 text-gray-900"><?= htmlspecialchars($customer['bank_name']) ?></p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Main Account Number:</label>
                            <p class="mt-1 text-gray-900"><?= htmlspecialchars($customer['main_account_number']) ?></p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Account Type:</label>
                            <p class="mt-1 text-gray-900"><?= htmlspecialchars($customer['main_account_type']) ?></p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Account Balance:</label>
                            <p class="mt-1 text-gray-900">$<?= number_format($customer['main_account_balance'], 2) ?></p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Created Date:</label>
                            <p class="mt-1 text-gray-900"><?= htmlspecialchars($customer['created_at']) ?></p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Last Updated:</label>
                            <p class="mt-1 text-gray-900"><?= htmlspecialchars($customer['updated_at'] ?? 'N/A') ?></p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Status:</label>
                            <p class="mt-1 text-gray-900">
                                <span class="px-2 py-1 rounded-full text-xs font-semibold 
                                    <?= $customer['status'] == 'active' ? 'bg-green-100 text-green-800' : 
                                       ($customer['status'] == 'inactive' ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800') ?>">
                                    <?= ucfirst(htmlspecialchars($customer['status'] ?? 'active')) ?>
                                </span>
                            </p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Opened By:</label>
                            <p class="mt-1 text-gray-900">
                                <?= htmlspecialchars($customer['employees_first_name'] . ' ' . htmlspecialchars($customer['employees_last_name'])) ?>
                            </p>
                        </div>
                    </div>

                    <div class="mt-6 flex justify-end">
                        <?php if (($_SESSION['role'] ?? 'teller') == 'manager' || ($_SESSION['role'] ?? 'teller') == 'admin'): ?>
                            <a href="edit_customer?action=edit&id=<?= htmlspecialchars($customer['customer_id']) ?>" 
                               class="bg-green-500 hover:bg-green-700 text-white px-4 py-2 rounded mr-2">
                                Edit Customer
                            </a>
                            <a href="customer_management?action=delete&id=<?= htmlspecialchars($customer['customer_id']) ?>" 
                               class="bg-red-500 hover:bg-red-700 text-white px-4 py-2 rounded" 
                               onclick="return confirm('Are you sure you want to delete this customer?')">
                                Delete Customer
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>
</body>
</html>