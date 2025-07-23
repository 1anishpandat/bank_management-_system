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
    
    // New Employee details
    $employee_id = $_POST['reg_employee_id'];
    $employee_password = password_hash($_POST['reg_employee_password'], PASSWORD_DEFAULT); // Hash employee password
    $employees_first_name = $_POST['reg_employees_first_name']; // New field
    $employees_last_name = $_POST['reg_employees_last_name'];   // New field
    $employees_job_title = $_POST['reg_employees_job_title'];   // New field
    $employees_department = $_POST['reg_employees_department']; // New field


    // Check if bank_id (branch_id) already exists in bank_details
    $stmt_check_bank = $conn->prepare("SELECT bank_id FROM bank_details WHERE branch_id = ?");
    $stmt_check_bank->bind_param("i", $bank_id);
    $stmt_check_bank->execute();
    $stmt_check_bank->store_result();

    if ($stmt_check_bank->num_rows > 0) {
        $error = "Bank ID (Branch ID) already registered. Please use a different one.";
    } else {
        // Start a transaction for atomicity
        $conn->begin_transaction();
        $registration_success = false;

        try {
            // Insert into bank_details table
            $stmt_bank = $conn->prepare("INSERT INTO bank_details (bank_name, branch_id, password) VALUES (?, ?, ?)");
            if (!$stmt_bank) {
                throw new Exception("Prepare failed for bank_details: " . $conn->error);
            }
            $stmt_bank->bind_param("sis", $bank_name, $bank_id, $bank_password);

            if ($stmt_bank->execute()) {
                $new_bank_id = $stmt_bank->insert_id; // Get the auto-generated bank_id

                // Insert into employee table with new fields
                $stmt_employee = $conn->prepare("INSERT INTO employee (employee_id, bank_id, bank_name, bank_password, employees_first_name, employees_last_name, employees_job_title, employees_department) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                if (!$stmt_employee) {
                    throw new Exception("Prepare failed for employee: " . $conn->error);
                }
                $stmt_employee->bind_param("iissiiii", $employee_id, $new_bank_id, $bank_name, $employee_password, $employees_first_name, $employees_last_name, $employees_job_title, $employees_department);
                // NOTE: 'iissiiii' - Update type string based on your actual column types for first_name, last_name, job_title, department.
                // Assuming first/last name, job title, department are strings (s).
                // Corrected bind_param type string based on common usage: "iissiiii" -> "iississs" if they are VARCHAR.
                // Make sure `employees_first_name`, `employees_last_name`, `employees_job_title`, `employees_department` are all VARCHAR (strings).
                // If any of these new fields are numbers, adjust 's' to 'i' or 'd' accordingly.
                // Assuming they are strings:
                $stmt_employee->bind_param("iississs", $employee_id, $new_bank_id, $bank_name, $employee_password, $employees_first_name, $employees_last_name, $employees_job_title, $employees_department);

                if ($stmt_employee->execute()) {
                    $conn->commit(); // Commit transaction if all successful
                    $registration_success = true;
                } else {
                    throw new Exception("Error registering employee: " . $stmt_employee->error);
                }
                $stmt_employee->close();
            } else {
                throw new Exception("Error registering bank details: " . $stmt_bank->error);
            }
            $stmt_bank->close();

        } catch (Exception $e) {
            $conn->rollback(); // Rollback on any error
            $error = "Registration failed: " . $e->getMessage();
            error_log("Bank registration error: " . $e->getMessage()); // Log detailed error
        }

        if ($registration_success) {
            // Redirect to login page with a success message
            header("Location: bank_login?registration_success=true");
            exit();
        }
    }
    $stmt_check_bank->close();
}

// Include the header
include 'header.php';
?>

<main class="flex flex-col items-center justify-center py-10 w-full">
    <div class="bg-white p-8 rounded-lg shadow-xl w-full max-w-md">
        <h2 class="text-3xl font-bold text-gray-900 mb-6 text-center">Register Bank</h2>

        <?php if ($message): // Note: $message is not currently used for success messages here, only redirect ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
                <span class="block sm:inline"><?php echo $message; ?></span>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                <span class="block sm:inline"><?php echo $error; ?></span>
            </div>
        <?php endif; ?>

        <form action="bank_register" method="POST" class="space-y-4"> <div>
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

            <h3 class="text-xl font-semibold text-gray-800 pt-4 border-t border-gray-200 mt-6">Employee Details (First Admin User)</h3>
            <div>
                <label for="reg_employee_id" class="block text-sm font-medium text-gray-700">Employee ID</label>
                <input type="number" id="reg_employee_id" name="reg_employee_id" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
            </div>
            <div>
                <label for="reg_employee_password" class="block text-sm font-medium text-gray-700">Employee Password</label>
                <input type="password" id="reg_employee_password" name="reg_employee_password" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
            </div>
            <div>
                <label for="reg_employees_first_name" class="block text-sm font-medium text-gray-700">Employee First Name</label>
                <input type="text" id="reg_employees_first_name" name="reg_employees_first_name" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
            </div>
            <div>
                <label for="reg_employees_last_name" class="block text-sm font-medium text-gray-700">Employee Last Name</label>
                <input type="text" id="reg_employees_last_name" name="reg_employees_last_name" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
            </div>
            <div>
                <label for="reg_employees_job_title" class="block text-sm font-medium text-gray-700">Employee Job Title</label>
                <input type="text" id="reg_employees_job_title" name="reg_employees_job_title" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
            </div>
            <div>
                <label for="reg_employees_department" class="block text-sm font-medium text-gray-700">Employee Department</label>
                <input type="text" id="reg_employees_department" name="reg_employees_department" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
            </div>
            <button type="submit" name="register" class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition duration-300 ease-in-out">
                Register
            </button>
        </form>

        <div class="mt-6 text-center">
            <p class="text-sm text-gray-600">Already have an account? <a href="bank_login.php" class="font-medium text-indigo-600 hover:text-indigo-500">Log in</a></p>
        </div>
    </div>
</main>

<?php
// Include the footer
include 'footer.php';
$conn->close(); // Close the database connection
?>