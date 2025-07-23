<?php
// customer_management.php
session_start();
require 'db_connect.php';
require 'security_functions.php';

function sanitize_input($data) {
    // Remove whitespace from the beginning and end of the input
    $data = trim($data);
    // Remove backslashes (\)
    $data = stripslashes($data);
    // Convert special characters to HTML entities to prevent XSS
    $data = htmlspecialchars($data);
    return $data;
}

// Authentication check
if (!isset($_SESSION['employee_id'])) {
    header("Location: bank_login.php");
    exit();
}

$employee_role = $_SESSION['role'] ?? 'teller';
$action = $_GET['action'] ?? 'list';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if ($action == 'add') {
        $first_name = sanitize_input($_POST['first_name']);
        $last_name = sanitize_input($_POST['last_name']);
        $email = sanitize_input($_POST['email']);
        $phone = sanitize_input($_POST['phone']);
        $address = sanitize_input($_POST['address']);
        $id_proof_number = sanitize_input($_POST['id_proof_number']); // <--- Add this line

        $stmt = $conn->prepare("INSERT INTO customers (first_name, last_name, email, phone, address, id_proof_number, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
        //                                                                                                ^ Add id_proof_number here

        if ($stmt === false) {
            $error_message = "SQL prepare error: " . $conn->error;
        } else {
            $stmt->bind_param("ssssss", $first_name, $last_name, $email, $phone, $address, $id_proof_number);
            //                                             ^ Add 's' for id_proof_number (string type)

            if ($stmt->execute()) {
                $success_message = "Customer added successfully!";
                $action = 'list';
            } else {
                $error_message = "Error adding customer: " . $stmt->error;
            }
            $stmt->close();
        }
    }
}
// Get customers list
$customers = [];
try {
    $result = $conn->query("SELECT * FROM customers ORDER BY created_at DESC");
    if ($result) {
        $customers = $result->fetch_all(MYSQLI_ASSOC);
    } else {
        // If $result is false, it means the query itself failed
        $error_message = "Error executing customer fetch query: " . $conn->error;
    }
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
</head>
<body class="bg-gray-100">

<div class="container mx-auto p-4">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">Customer Management</h1>
        <div>
            <a href="dashboard" class="bg-gray-500 hover:bg-gray-700 text-white px-4 py-2 rounded">Back to Dashboard</a>
            <?php if ($action == 'list'): ?>
                <a href="?action=add" class="bg-blue-500 hover:bg-blue-700 text-white px-4 py-2 rounded ml-2">Add New Customer</a>
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
            <h2 class="text-xl font-semibold mb-4">Add New Customer</h2>
            <form method="POST">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">First Name</label>
                        <input type="text" name="first_name" required class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Last Name</label>
                        <input type="text" name="last_name" required class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2">
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <div>
        <label class="block text-sm font-medium text-gray-700">ID Proof Number</label>
        <input type="text" name="id_proof_number" required class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2">
    </div>
    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Email</label>
                        <input type="email" name="email" required class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Phone</label>
                        <input type="tel" name="phone" required class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700">Address</label>
                        <textarea name="address" required class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2"></textarea>
                    </div>
                </div>
                <div class="mt-6 flex justify-end">
                    <a href="?action=list" class="bg-gray-500 hover:bg-gray-700 text-white px-4 py-2 rounded mr-2">Cancel</a>
                    <button type="submit" class="bg-blue-500 hover:bg-blue-700 text-white px-4 py-2 rounded">Save Customer</button>
                </div>
            </form>
        </div>
    <?php else: ?>
        <div class="bg-white p-6 rounded-lg shadow">
            <h2 class="text-xl font-semibold mb-4">Customer List</h2>
            <?php if (empty($customers)): ?>
                <p class="text-gray-500">No customers found.</p>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Phone</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($customers as $customer): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= htmlspecialchars($customer['customer_id']) ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        <?= htmlspecialchars($customer['first_name']) . ' ' . htmlspecialchars($customer['last_name']) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= htmlspecialchars($customer['email']) ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= htmlspecialchars($customer['phone']) ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= htmlspecialchars($customer['created_at']) ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <a href="customer_details?id=<?= $customer['customer_id'] ?>" class="text-blue-600 hover:text-blue-900 mr-2">View</a>
                                        <?php if ($employee_role == 'manager' || $employee_role == 'admin'): ?>
                                            <a href="?action=edit&id=<?= $customer['customer_id'] ?>" class="text-green-600 hover:text-green-900 mr-2">Edit</a>
                                            <a href="?action=delete&id=<?= $customer['customer_id'] ?>" class="text-red-600 hover:text-red-900" onclick="return confirm('Are you sure you want to delete this customer?')">Delete</a>
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