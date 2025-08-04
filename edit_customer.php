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

// Get customer data
$customer = [];
$stmt = $conn->prepare("SELECT * FROM customers WHERE customer_id = ?");
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
    $first_name = substr(sanitize_input($_POST['first_name']), 0, 20);
    if (empty($first_name)) {
        $errors['first_name'] = "First name is required";
    } elseif (!preg_match("/^[a-zA-Z-' ]*$/", $first_name)) {
        $errors['first_name'] = "Only letters and white space allowed";
    }

    // Sanitize other fields
    $last_name = sanitize_input($_POST['last_name']);
    $email = sanitize_input($_POST['email']);
    $phone = sanitize_input($_POST['phone']);
    $address = sanitize_input($_POST['address']);
    $date_of_birth = sanitize_input($_POST['date_of_birth']);
    $id_proof_type = sanitize_input($_POST['id_proof_type']);
    $id_proof_number = sanitize_input($_POST['id_proof_number']);

    // Handle photo upload
    $photo_path = $customer['photo_path']; // Keep existing photo by default
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] == UPLOAD_ERR_OK) {
        $upload_dir = 'uploads/customer_photos/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $file_ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        $allowed_ext = ['jpg', 'jpeg', 'png', 'gif'];
        
        if (in_array($file_ext, $allowed_ext)) {
            // Delete old photo if exists
          // Delete old photo if exists
if (!empty($photo_path)) {
    @unlink($photo_path);
}
        
            $new_filename = 'photo_' . uniqid() . '.' . $file_ext;
            $target_file = $upload_dir . $new_filename;
        
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $target_file)) {
                $photo_path = $target_file;
            } else {
                $errors['photo'] = "Error uploading photo";
            }
        } else {
            $errors['photo'] = "Invalid file type. Only JPG, JPEG, PNG, GIF are allowed.";
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
                photo_path = ?
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
                // Update the customer array with sanitized values
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
</head>
<body class="bg-gray-100">

<div class="container mx-auto p-4">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">Edit Customer</h1>
        <div>
            <a href="customer_management" class="bg-gray-500 hover:bg-gray-700 text-white px-4 py-2 rounded">Back to Customers</a>
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

    <div class="bg-white p-6 rounded-lg shadow mb-6">
        <form method="POST" enctype="multipart/form-data">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="md:col-span-2 flex items-center space-x-4">
                    <div>
                        <?php if (!empty($customer['photo_path'])): ?>
                            <img src="<?= htmlspecialchars($customer['photo_path']) ?>" 
                                 alt="Customer Photo" 
                                 class="w-24 h-24 rounded-full object-cover border-2 border-gray-300">
                        <?php else: ?>
                            <div class="w-24 h-24 rounded-full bg-gray-200 flex items-center justify-center text-gray-500">
                                No Photo
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="flex-1">
                        <label class="block text-sm font-medium text-gray-700">Update Photo</label>
                        <input type="file" name="photo" accept="image/*"
                               class="mt-1 block w-full text-sm text-gray-500
                                      file:mr-4 file:py-2 file:px-4
                                      file:rounded-md file:border-0
                                      file:text-sm file:font-semibold
                                      file:bg-blue-50 file:text-blue-700
                                      hover:file:bg-blue-100">
                        <?php if (isset($errors['photo'])): ?>
                            <p class="mt-1 text-sm text-red-600"><?= $errors['photo'] ?></p>
                        <?php endif; ?>
                        <p class="mt-1 text-xs text-gray-500">JPG, JPEG, PNG, GIF (Max 5MB)</p>
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700">First Name*</label>
                    <input type="text" name="first_name" required maxlength="20"
                           class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2"
                           value="<?= htmlspecialchars($customer['first_name']) ?>">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Last Name*</label>
                    <input type="text" name="last_name" required maxlength="20"
                           class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2"
                           value="<?= htmlspecialchars($customer['last_name']) ?>">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Email*</label>
                    <input type="email" name="email" required
                           class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2"
                           value="<?= htmlspecialchars($customer['email']) ?>">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Phone*</label>
                    <input type="tel" name="phone" required
                           class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2"
                           value="<?= htmlspecialchars($customer['phone']) ?>">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700">Address*</label>
                    <textarea name="address" required rows="3"
                              class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2"><?= htmlspecialchars($customer['address']) ?></textarea>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Date of Birth*</label>
                    <input type="date" name="date_of_birth" required
                           class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2"
                           value="<?= htmlspecialchars($customer['date_of_birth']) ?>">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">ID Proof Type*</label>
                    <select name="id_proof_type" required
                            class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2">
                        <option value="Passport" <?= $customer['id_proof_type'] == 'Passport' ? 'selected' : '' ?>>Passport</option>
                        <option value="Driver License" <?= $customer['id_proof_type'] == 'Driver License' ? 'selected' : '' ?>>Driver License</option>
                        <option value="National ID" <?= $customer['id_proof_type'] == 'National ID' ? 'selected' : '' ?>>National ID</option>
                        <option value="PAN Card" <?= $customer['id_proof_type'] == 'PAN Card' ? 'selected' : '' ?>>PAN Card</option>
                        <option value="Aadhaar Card" <?= $customer['id_proof_type'] == 'Aadhaar Card' ? 'selected' : '' ?>>Aadhaar Card</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">ID Proof Number*</label>
                    <input type="text" name="id_proof_number" required
                           class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2"
                           value="<?= htmlspecialchars($customer['id_proof_number']) ?>">
                </div>
            </div>

            <div class="mt-6 flex justify-end">
                <button type="submit" class="bg-blue-500 hover:bg-blue-700 text-white px-4 py-2 rounded">
                    Update Customer
                </button>
            </div>
        </form>
    </div>
</div>

<?php include 'footer.php'; ?>
</body>
</html>