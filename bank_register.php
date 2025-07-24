<?php
// bank_register.php - Handles bank employee registration with multiple employees

include 'db_connect.php';

$message = '';
$error = '';

// Handle Registration
if (isset($_POST['register'])) {
    // Bank details
    $bank_name = $_POST['reg_bank_name'];
    $bank_id = $_POST['reg_bank_id']; // This will be the branch_id in bank_details
    $bank_password = password_hash($_POST['reg_bank_password'], PASSWORD_DEFAULT);
    
    // Start transaction
    $conn->begin_transaction();
    $registration_success = false;

    try {
        // Check if bank exists, if not create it
        $stmt_check_bank = $conn->prepare("SELECT bank_id FROM bank_details WHERE branch_id = ?");
        $stmt_check_bank->bind_param("i", $bank_id);
        $stmt_check_bank->execute();
        $stmt_check_bank->store_result();

        $new_bank_id = null;
        
        if ($stmt_check_bank->num_rows > 0) {
            // Bank exists, get its ID
            $stmt_check_bank->bind_result($existing_bank_id);
            $stmt_check_bank->fetch();
            $new_bank_id = $existing_bank_id;
        } else {
            // Insert new bank details
            $stmt_bank = $conn->prepare("INSERT INTO bank_details (bank_name, branch_id, password) VALUES (?, ?, ?)");
            $stmt_bank->bind_param("sis", $bank_name, $bank_id, $bank_password);
            
            if ($stmt_bank->execute()) {
                $new_bank_id = $stmt_bank->insert_id;
            } else {
                throw new Exception("Error registering bank details: " . $stmt_bank->error);
            }
        }

        // Process all employees
        $employee_ids = $_POST['employee_id'];
        $employee_passwords = $_POST['employee_password'];
        $first_names = $_POST['employees_first_name'];
        $last_names = $_POST['employees_last_name'];
        $job_titles = $_POST['employees_job_title'];
        $departments = $_POST['employees_department'];
        $roles = $_POST['employee_role'];

        // Validate we have the same number of entries for each field
        $employee_count = count($employee_ids);
        if ($employee_count != count($employee_passwords) || 
            $employee_count != count($first_names) ||
            $employee_count != count($last_names) ||
            $employee_count != count($job_titles) ||
            $employee_count != count($departments) ||
            $employee_count != count($roles)) {
            throw new Exception("Employee data mismatch");
        }

        // Prepare employee insert statement
        $stmt_employee = $conn->prepare("
            INSERT INTO employee 
            (employee_id, bank_id, bank_name, bank_password, employees_first_name, 
             employees_last_name, employees_job_title, employees_department, role) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        // Insert each employee
        for ($i = 0; $i < $employee_count; $i++) {
            $emp_id = $employee_ids[$i];
            $emp_pass = password_hash($employee_passwords[$i], PASSWORD_DEFAULT);
            $first_name = $first_names[$i];
            $last_name = $last_names[$i];
            $job_title = $job_titles[$i];
            $department = $departments[$i];
            $role = $roles[$i];

            $stmt_employee->bind_param(
                "iisssssss", 
                $emp_id, $new_bank_id, $bank_name, $emp_pass, 
                $first_name, $last_name, $job_title, $department, $role
            );

            if (!$stmt_employee->execute()) {
                throw new Exception("Error registering employee ID $emp_id: " . $stmt_employee->error);
            }
        }

        $conn->commit();
        $registration_success = true;
    } catch (Exception $e) {
        $conn->rollback();
        $error = "Registration failed: " . $e->getMessage();
        error_log("Bank registration error: " . $e->getMessage());
    }

    if ($registration_success) {
        header("Location: bank_login?registration_success=true");
        exit();
    }
}

include 'header.php';
?>

<!-- The rest of your HTML form remains exactly the same -->

<main class="flex flex-col items-center justify-center py-10 w-full">
    <div class="bg-white p-8 rounded-lg shadow-xl w-full max-w-4xl"> <!-- Increased max width -->
        <h2 class="text-3xl font-bold text-gray-900 mb-6 text-center">Register Bank</h2>

        <?php if ($error): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                <span class="block sm:inline"><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <form action="bank_register" method="POST" class="space-y-4" id="registrationForm">
            <!-- Bank Details Section -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="reg_bank_name" class="block text-sm font-medium text-gray-700">Bank Name</label>
                    <input type="text" id="reg_bank_name" name="reg_bank_name" required 
                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                </div>
                <div>
                    <label for="reg_bank_id" class="block text-sm font-medium text-gray-700">Bank ID (Branch ID)</label>
                    <input type="number" id="reg_bank_id" name="reg_bank_id" required 
                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                </div>
            </div>
            <div>
                <label for="reg_bank_password" class="block text-sm font-medium text-gray-700">Bank Password</label>
                <input type="password" id="reg_bank_password" name="reg_bank_password" required 
                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
            </div>

            <!-- Employees Section -->
            <div class="pt-6 border-t border-gray-200">
                <h3 class="text-xl font-semibold text-gray-800 mb-4">Employee Details</h3>
                <div id="employeeFields">
                    <!-- First employee (required) -->
                    <div class="employee-entry bg-gray-50 p-4 rounded-lg mb-4">
                        <h4 class="text-lg font-medium text-gray-700 mb-3">Employee #1</h4>
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
                <button type="submit" name="register" class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                    Register Bank and Employees
                </button>
            </div>
        </form>

        <div class="mt-6 text-center">
            <p class="text-sm text-gray-600">Already have an account? <a href="bank_login.php" class="font-medium text-indigo-600 hover:text-indigo-500">Log in</a></p>
        </div>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const employeeFields = document.getElementById('employeeFields');
    const addEmployeeBtn = document.getElementById('addEmployeeBtn');
    let employeeCount = 1;

    addEmployeeBtn.addEventListener('click', function() {
        employeeCount++;
        const newEmployeeDiv = document.createElement('div');
        newEmployeeDiv.className = 'employee-entry bg-gray-50 p-4 rounded-lg mb-4';
        newEmployeeDiv.innerHTML = `
            <div class="flex justify-between items-center mb-3">
                <h4 class="text-lg font-medium text-gray-700">Employee #${employeeCount}</h4>
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
            </div>
        `;
        employeeFields.appendChild(newEmployeeDiv);

        // Add event listener to the remove button
        newEmployeeDiv.querySelector('.remove-employee').addEventListener('click', function() {
            employeeFields.removeChild(newEmployeeDiv);
            // Update employee numbers
            const entries = document.querySelectorAll('.employee-entry');
            entries.forEach((entry, index) => {
                entry.querySelector('h4').textContent = `Employee #${index + 1}`;
            });
            employeeCount = entries.length;
        });
    });
});
</script>

<?php
include 'footer.php';
$conn->close();
?>