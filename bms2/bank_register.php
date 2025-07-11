<?php
// bank_register.php - Handles bank employee registration

include 'db_connect.php'; // Include the database connection file

$message = '';
$error = '';

// Handle Registration
if (isset($_POST['register'])) {
    $bank_name = $_POST['reg_bank_name'];
    $bank_id = $_POST['reg_bank_id']; // This will be the branch_id in bank_details
    $bank_password = password_hash($_POST['reg_bank_password'], PASSWORD_DEFAULT); // Hash the password
    $employee_id = $_POST['reg_employee_id'];
    $employee_password = password_hash($_POST['reg_employee_password'], PASSWORD_DEFAULT); // Hash employee password

    // Check if bank_id (branch_id) already exists in bank_details
    $stmt_check_bank = $conn->prepare("SELECT bank_id FROM bank_details WHERE branch_id = ?");
    $stmt_check_bank->bind_param("i", $bank_id);
    $stmt_check_bank->execute();
    $stmt_check_bank->store_result();

    if ($stmt_check_bank->num_rows > 0) {
        $error = "Bank ID (Branch ID) already registered. Please use a different one.";
    } else {
        // Insert into bank_details table
        $stmt_bank = $conn->prepare("INSERT INTO bank_details (bank_name, branch_id, password) VALUES (?, ?, ?)");
        $stmt_bank->bind_param("sis", $bank_name, $bank_id, $bank_password);

        if ($stmt_bank->execute()) {
            $new_bank_id = $stmt_bank->insert_id; // Get the auto-generated bank_id

            // Insert into employee table
            $stmt_employee = $conn->prepare("INSERT INTO employee (employee_id, bank_id, bank_name, bank_password) VALUES (?, ?, ?, ?)");
            $stmt_employee->bind_param("iiss", $employee_id, $new_bank_id, $bank_name, $employee_password);

            if ($stmt_employee->execute()) {
                // Redirect to login page with a success message
                // Updated redirect to use clean URL
                header("Location: bank_login?registration_success=true");
                exit();
            } else {
                $error = "Error registering employee: " . $stmt_employee->error;
                // If employee registration fails, rollback bank_details insertion
                $conn->query("DELETE FROM bank_details WHERE bank_id = $new_bank_id");
            }
            $stmt_employee->close();
        } else {
            $error = "Error registering bank details: " . $stmt_bank->error;
        }
        $stmt_bank->close();
    }
}

// Include the header
include 'header.php';
?>

<main class="flex flex-col items-center justify-center py-10 w-full">
    <div class="bg-white p-8 rounded-lg shadow-xl w-full max-w-md">
        <h2 class="text-3xl font-bold text-gray-900 mb-6 text-center">Register Bank</h2>

        <?php if ($message): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
                <span class="block sm:inline"><?php echo $message; ?></span>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                <span class="block sm:inline"><?php echo $error; ?></span>
            </div>
        <?php endif; ?>

        <!-- Registration Form -->
        <form action="bank_register" method="POST" class="space-y-4"> <!-- Updated form action -->
            <div>
                <label for="reg_bank_name" class="block text-sm font-medium text-gray-700">Bank Name</label>
                <input type="text" id="reg_bank_name" name="reg_bank_name" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
            </div>
            <div>
                <label for="reg_bank_id" class="block text-sm font-medium text-gray-700">Bank ID (Branch ID)</label>
                <input type="number" id="reg_bank_id" name="reg_bank_id" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
            </div>
            <div>
                <label for="reg_bank_password" class="block text-sm font-medium text-gray-700">Bank Password</label>
                <input type="password" id="reg_bank_password" name="reg_bank_password" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
            </div>
            <div>
                <label for="reg_employee_id" class="block text-sm font-medium text-gray-700">Employee ID</label>
                <input type="number" id="reg_employee_id" name="reg_employee_id" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
            </div>
            <div>
                <label for="reg_employee_password" class="block text-sm font-medium text-gray-700">Employee Password</label>
                <input type="password" id="reg_employee_password" name="reg_employee_password" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
            </div>
            <button type="submit" name="register" class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition duration-300 ease-in-out">
                Register
            </button>
        </form>

        <div class="mt-6 text-center">
        
        </div>
    </div>
</main>

<?php
// Include the footer
include 'footer.php';
$conn->close(); // Close the database connection
?>
