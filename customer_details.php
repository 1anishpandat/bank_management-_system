<?php
// customer_details.php
session_start();
require 'db_connect.php';
require 'security_functions.php'; // Assuming this contains sanitize_input, etc.

// Authentication check (similar to customer_management.php)
if (!isset($_SESSION['employee_id'])) {
    header("Location: bank_login.php");
    exit();
}

$customer = null; // Initialize customer variable
$error_message = '';

// Check if customer_id is provided in the URL
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $customer_id = (int)$_GET['id']; // Cast to integer for security

    // Prepare and execute the query to fetch customer details
    $stmt = $conn->prepare("SELECT * FROM customers WHERE customer_id = ?");

    if ($stmt === false) {
        $error_message = "SQL prepare error: " . $conn->error;
    } else {
        $stmt->bind_param("i", $customer_id); // 'i' for integer

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

// Include the header
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
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">Customer Details</h1>
        <div>
            <a href="customer_management" class="bg-gray-500 hover:bg-gray-700 text-white px-4 py-2 rounded">Back to Customer List</a>
            <a href="dashboard" class="bg-gray-500 hover:bg-gray-700 text-white px-4 py-2 rounded ml-2">Back to Dashboard</a>
        </div>
    </div>

    <?php if (isset($error_message) && $error_message): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
            <?= htmlspecialchars($error_message) ?>
        </div>
    <?php endif; ?>

    <?php if ($customer): ?>
        <div class="bg-white p-6 rounded-lg shadow">
            <h2 class="text-xl font-semibold mb-4">Details for Customer ID: <?= htmlspecialchars($customer['customer_id']) ?></h2>
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
                <?php if (isset($customer['id_proof_number'])): ?>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">ID Proof Number:</label>
                        <p class="mt-1 text-gray-900"><?= htmlspecialchars($customer['id_proof_number']) ?></p>
                    </div>
                <?php endif; ?>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Created Date:</label>
                    <p class="mt-1 text-gray-900"><?= htmlspecialchars($customer['created_at']) ?></p>
                </div>
                <?php if (isset($customer['last_updated'])): ?>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Last Updated:</label>
                        <p class="mt-1 text-gray-900"><?= htmlspecialchars($customer['last_updated']) ?></p>
                    </div>
                <?php endif; ?>

                <?php /*
                <?php if (isset($customer['status'])): ?>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Status:</label>
                        <p class="mt-1 text-gray-900"><?= htmlspecialchars($customer['status']) ?></p>
                    </div>
                <?php endif; ?>
                */ ?>
            </div>

            <div class="mt-6 flex justify-end">
                <?php // Optional: Add Edit/Delete buttons if allowed by role ?>
                <?php if (($_SESSION['role'] ?? 'teller') == 'manager' || ($_SESSION['role'] ?? 'teller') == 'admin'): ?>
                    <a href="customer_management.php?action=edit&id=<?= htmlspecialchars($customer['customer_id']) ?>" class="bg-green-500 hover:bg-green-700 text-white px-4 py-2 rounded mr-2">Edit Customer</a>
                    <a href="customer_management.php?action=delete&id=<?= htmlspecialchars($customer['customer_id']) ?>" class="bg-red-500 hover:bg-red-700 text-white px-4 py-2 rounded" onclick="return confirm('Are you sure you want to delete this customer?')">Delete Customer</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>
</body>
</html>