<?php
// employee_register.php - Handles registration of employees for an existing bank

session_start(); // Start session for potential messages/authentication checks
include 'db_connect.php'; // Include the database connection file

$message = '';
$error = '';

// Handle Employee Registration
if (isset($_POST['register_employees'])) {
    // Get bank details from the form to identify the target bank
    $reg_bank_id_from_form = trim($_POST['reg_bank_id'] ?? '');
    $reg_bank_name_from_form = trim($_POST['reg_bank_name'] ?? '');

    // Basic validation for bank lookup fields
    if (empty($reg_bank_id_from_form) || !filter_var($reg_bank_id_from_form, FILTER_VALIDATE_INT)) {
        $error = "Bank ID (Branch ID) must be a valid number.";
    } elseif (empty($reg_bank_name_from_form)) {
        $error = "Bank Name cannot be empty.";
    }

    $actual_bank_id = null;
    $actual_bank_name = null;
    $employee_registration_success = false;

    if (empty($error)) { // Proceed only if initial validation passes
        // Start transaction for employee registration
        $conn->begin_transaction();

        try {
            // Verify bank exists and get its internal bank_id and name from bank_details
            $stmt_get_bank = $conn->prepare("SELECT bank_id, bank_name FROM bank_details WHERE branch_id = ? AND bank_name = ?");
            if (!$stmt_get_bank) {
                throw new Exception("Database prepare error for bank lookup: " . $conn->error);
            }
            $stmt_get_bank->bind_param("is", $reg_bank_id_from_form, $reg_bank_name_from_form);
            $stmt_get_bank->execute();
            $stmt_get_bank->bind_result($fetched_bank_id, $fetched_bank_name);
            $stmt_get_bank->fetch();
            $stmt_get_bank->close(); // Close after fetching result

            if ($fetched_bank_id) {
                $actual_bank_id = $fetched_bank_id;
                $actual_bank_name = $fetched_bank_name;
            } else {
                throw new Exception("Bank not found or details mismatch. Please ensure the bank is registered and correct Bank Name/Branch ID are provided.");
            }

            // Process all employees
            $employee_ids = $_POST['employee_id'] ?? [];
            $employee_passwords_raw = $_POST['employee_password'] ?? [];
            $first_names = $_POST['employees_first_name'] ?? [];
            $last_names = $_POST['employees_last_name'] ?? [];
            $job_titles = $_POST['employees_job_title'] ?? [];
            $departments = $_POST['employees_department'] ?? [];
            $roles = $_POST['employee_role'] ?? [];
            // $_FILES['employee_photo'] will contain an array of arrays for each file input

            // Validate we have the same number of entries for each field
            $employee_count = count($employee_ids);
            if ($employee_count === 0) {
                throw new Exception("At least one employee must be registered.");
            }
            if ($employee_count != count($employee_passwords_raw) ||
                $employee_count != count($first_names) ||
                $employee_count != count($last_names) ||
                $employee_count != count($job_titles) ||
                $employee_count != count($departments) ||
                $employee_count != count($roles)) {
                throw new Exception("Employee data mismatch or missing fields. Please ensure all employee fields are filled.");
            }

            // Prepare employee insert statement outside the loop for efficiency
            $stmt_employee = $conn->prepare("
                INSERT INTO employee
                (employee_id, bank_id, bank_name, bank_password, employees_first_name,
                 employees_last_name, employees_job_title, employees_department, role, photo_path)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            if (!$stmt_employee) {
                throw new Exception("Database prepare error for employee insert: " . $conn->error);
            }

            // Create directory for employee photos if it doesn't exist
            $target_dir = "uploads/employee_photos/";
            if (!is_dir($target_dir)) {
                mkdir($target_dir, 0777, true);
            }

            // Insert each employee
            for ($i = 0; $i < $employee_count; $i++) {
                // --- Server-Side Input Validation for Each Employee ---
                $emp_id = trim($employee_ids[$i]);
                $emp_pass_raw = $employee_passwords_raw[$i];
                $first_name = trim($first_names[$i]);
                $last_name = trim($last_names[$i]);
                $job_title = trim($job_titles[$i]);
                $department = trim($departments[$i]);
                $role = trim($roles[$i]);

                // Validate individual employee fields
                if (empty($emp_id) || !filter_var($emp_id, FILTER_VALIDATE_INT)) {
                    throw new Exception("Employee ID for employee #" . ($i + 1) . " must be a valid number.");
                }
                if (empty($emp_pass_raw) || strlen($emp_pass_raw) < 8) { // Example password strength check
                    throw new Exception("Password for employee #" . ($i + 1) . " must be at least 8 characters long.");
                }
                if (empty($first_name) || empty($last_name) || empty($job_title) || empty($department) || empty($role)) {
                    throw new Exception("All fields for employee #" . ($i + 1) . " must be filled.");
                }
                $allowed_roles = ['teller', 'manager', 'admin']; // Define allowed roles
                if (!in_array($role, $allowed_roles)) {
                    throw new Exception("Invalid role for employee #" . ($i + 1) . ".");
                }

           // Check for duplicate employee ID within the system (globally unique)
$stmt_check_emp_id = $conn->prepare("SELECT employee_id FROM employee WHERE employee_id = ?");
$stmt_check_emp_id->bind_param("i", $emp_id);
$stmt_check_emp_id->execute(); // Corrected line: changed $stmt_check_emp_emp_id to $stmt_check_emp_id
$stmt_check_emp_id->store_result();
if ($stmt_check_emp_id->num_rows > 0) {
    throw new Exception("Employee ID '$emp_id' is already registered. Please use a different ID.");
}
$stmt_check_emp_id->close();
                

                $employee_photo_path = null; // Reset for each employee
                // Handle photo upload for current employee
                if (isset($_FILES['employee_photo']['error'][$i]) && $_FILES['employee_photo']['error'][$i] == UPLOAD_ERR_OK) {
                    $file_extension = pathinfo($_FILES['employee_photo']['name'][$i], PATHINFO_EXTENSION);
                    $new_file_name = uniqid('emp_photo_', true) . '.' . $file_extension;
                    $target_file = $target_dir . $new_file_name;

                    if (move_uploaded_file($_FILES['employee_photo']['tmp_name'][$i], $target_file)) {
                        $employee_photo_path = $target_file;
                    } else {
                        error_log("Error uploading employee photo for ID $emp_id.");
                    }
                }

                $emp_pass_hashed = password_hash($emp_pass_raw, PASSWORD_DEFAULT);

                $stmt_employee->bind_param(
                    "iissssssss", // 'i' for employee_id, 'i' for bank_id, then 8 's' for strings
                    $emp_id, $actual_bank_id, $actual_bank_name, $emp_pass_hashed,
                    $first_name, $last_name, $job_title, $department, $role, $employee_photo_path
                );

                if (!$stmt_employee->execute()) {
                    throw new Exception("Error registering employee ID $emp_id: " . $stmt_employee->error);
                }
            }

            $conn->commit();
            $employee_registration_success = true;
            $message = "Employees registered successfully for " . htmlspecialchars($actual_bank_name) . " (Branch ID: " . htmlspecialchars($reg_bank_id_from_form) . ")!";

        } catch (Exception $e) {
            $conn->rollback();
            $error = "Employee Registration failed: " . $e->getMessage();
            error_log("Employee registration error: " . $e->getMessage());
        } finally {
            if ($stmt_employee) $stmt_employee->close(); // Ensure statement is closed
        }
    }
}

// Ensure the form action is correct for this file
$form_action = "employee_register";

include 'header.php'; // Includes common header and styling
?>

<main class="flex flex-col items-center justify-center py-10 w-full">
    <div class="bg-white p-8 rounded-lg shadow-xl w-full max-w-4xl">
        <h2 class="text-3xl font-bold text-gray-900 mb-6 text-center">Employee Registration</h2>

        <?php if ($error): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                <span class="block sm:inline"><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>
        <?php if ($message): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
                <span class="block sm:inline"><?= htmlspecialchars($message) ?></span>
            </div>
        <?php endif; ?>

        <form action="<?= $form_action ?>" method="POST" class="space-y-4" id="employeeRegistrationForm" enctype="multipart/form-data">
            <h3 class="text-xl font-semibold text-gray-800 mb-4">Bank Details for Employee Assignment</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="reg_bank_name" class="block text-sm font-medium text-gray-700">Bank Name</label>
                    <input type="text" id="reg_bank_name" name="reg_bank_name" required
                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                           value="<?= htmlspecialchars($_POST['reg_bank_name'] ?? '') ?>">
                </div>
                <div>
                    <label for="reg_bank_id" class="block text-sm font-medium text-gray-700">Bank ID (Branch ID)</label>
                    <input type="number" id="reg_bank_id" name="reg_bank_id" required
                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                           value="<?= htmlspecialchars($_POST['reg_bank_id'] ?? '') ?>">
                </div>
            </div>

            <div class="pt-6 border-t border-gray-200">
                <h3 class="text-xl font-semibold text-gray-800 mb-4">Employee Details</h3>
                <div id="employeeFields">
                    <!-- Default first employee entry -->
                    <div class="employee-entry bg-gray-50 p-4 rounded-lg mb-4">
                        <div class="flex justify-between items-center mb-3">
                            <h4 class="text-lg font-medium text-gray-700">Employee #1</h4>
                            <button type="button" class="remove-employee text-red-600 hover:text-red-800 hidden">
                                Remove
                            </button>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Employee ID</label>
                                <input type="number" name="employee_id[]" required
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                                       value="<?= htmlspecialchars($_POST['employee_id'][0] ?? '') ?>">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Password</label>
                                <input type="password" name="employee_password[]" required
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">First Name</label>
                                <input type="text" name="employees_first_name[]" required
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                                       value="<?= htmlspecialchars($_POST['employees_first_name'][0] ?? '') ?>">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Last Name</label>
                                <input type="text" name="employees_last_name[]" required
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                                       value="<?= htmlspecialchars($_POST['employees_last_name'][0] ?? '') ?>">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Job Title</label>
                                <input type="text" name="employees_job_title[]" required
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                                       value="<?= htmlspecialchars($_POST['employees_job_title'][0] ?? '') ?>">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Department</label>
                                <input type="text" name="employees_department[]" required
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                                       value="<?= htmlspecialchars($_POST['employees_department'][0] ?? '') ?>">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Role</label>
                                <select name="employee_role[]" required
                                        class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                    <option value="teller" <?= (($_POST['employee_role'][0] ?? '') === 'teller') ? 'selected' : '' ?>>Teller</option>
                                    <option value="manager" <?= (($_POST['employee_role'][0] ?? '') === 'manager') ? 'selected' : '' ?>>Manager</option>
                                    <option value="admin" <?= (($_POST['employee_role'][0] ?? '') === 'admin') ? 'selected' : '' ?>>Admin</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Employee Photo</label>
                                <input type="file" name="employee_photo[]" accept="image/*"
                                       class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                                <p class="mt-1 text-sm text-gray-500">Upload a photo of the employee (optional).</p>
                            </div>
                        </div>
                    </div>
                </div>

                <button type="button" id="addEmployeeBtn" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    <svg class="-ml-1 mr-2 h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z" clip-rule="evenodd" />
                    </svg>
                    Add Another Employee
                </button>
            </div>

            <div class="pt-4">
                <button type="submit" name="register_employees" class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                    Register Employees for this Bank
                </button>
            </div>
        </form>

        <div class="mt-6 text-center">
            <p class="text-sm text-gray-600">Already have an account? <a href="bank_login" class="font-medium text-indigo-600 hover:text-indigo-500">Log in</a></p>
        </div>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const employeeFields = document.getElementById('employeeFields');
    const addEmployeeBtn = document.getElementById('addEmployeeBtn');
    
    // Initial number of employees already in the HTML
    let employeeCount = employeeFields.querySelectorAll('.employee-entry').length;

    // Function to re-index employee numbers and manage remove button visibility
    function updateEmployeeEntryDisplay() {
        const entries = employeeFields.querySelectorAll('.employee-entry');
        entries.forEach((entry, index) => {
            // Update title
            const titleElement = entry.querySelector('h4');
            if (titleElement) {
                titleElement.textContent = `Employee #${index + 1}`;
            }

            // Update remove button visibility
            const removeBtn = entry.querySelector('.remove-employee');
            if (removeBtn) {
                if (entries.length > 1) { // Only show remove button if there's more than one employee
                    removeBtn.classList.remove('hidden');
                } else {
                    removeBtn.classList.add('hidden'); // Hide if only one employee
                }
            }
        });
        employeeCount = entries.length; // Keep employeeCount accurate
    }

    // Call on load to set initial state
    updateEmployeeEntryDisplay();

    addEmployeeBtn.addEventListener('click', function() {
        // Create new employee entry
        const newEmployeeDiv = document.createElement('div');
        newEmployeeDiv.className = 'employee-entry bg-gray-50 p-4 rounded-lg mb-4';
        newEmployeeDiv.innerHTML = `
            <div class="flex justify-between items-center mb-3">
                <h4 class="text-lg font-medium text-gray-700">Employee #</h4>
                <button type="button" class="remove-employee text-red-600 hover:text-red-800">
                    Remove
                </button>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Employee ID</label>
                    <input type="number" name="employee_id[]" required
                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Password</label>
                    <input type="password" name="employee_password[]" required
                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">First Name</label>
                    <input type="text" name="employees_first_name[]" required
                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Last Name</label>
                    <input type="text" name="employees_last_name[]" required
                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Job Title</label>
                    <input type="text" name="employees_job_title[]" required
                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Department</label>
                    <input type="text" name="employees_department[]" required
                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Role</label>
                    <select name="employee_role[]" required
                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                        <option value="teller">Teller</option>
                        <option value="manager">Manager</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Employee Photo</label>
                    <input type="file" name="employee_photo[]" accept="image/*"
                           class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                    <p class="mt-1 text-sm text-gray-500">Upload a photo of the employee (optional).</p>
                </div>
            </div>
        `;
        employeeFields.appendChild(newEmployeeDiv);

        // Add event listener to the remove button of the newly added div
        newEmployeeDiv.querySelector('.remove-employee').addEventListener('click', function() {
            employeeFields.removeChild(newEmployeeDiv);
            updateEmployeeEntryDisplay(); // Update display after removal
        });

        updateEmployeeEntryDisplay(); // Update display after adding
    });

    // Delegated event listener for remove buttons (for dynamically added elements)
    employeeFields.addEventListener('click', function(event) {
        if (event.target.classList.contains('remove-employee')) {
            const employeeEntryDiv = event.target.closest('.employee-entry');
            if (employeeEntryDiv) {
                employeeFields.removeChild(employeeEntryDiv);
                updateEmployeeEntryDisplay(); // Update display after removal
            }
        }
    });
});
</script>

<?php
include 'footer.php';
$conn->close();
?>