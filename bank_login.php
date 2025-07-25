<?php
// bank_login.php - Handles bank employee login and initial choice

session_start(); // Start session at the very beginning
include 'db_connect.php'; // Include the database connection file

$message = '';
$error = '';
$show_login_form = false; // Flag to control form visibility

// Handle Login
if (isset($_POST['login'])) {
    $login_employee_id = trim($_POST['login_employee_id']); // Trim whitespace
    $login_password = $_POST['login_password'];

    // Basic validation
    if (empty($login_employee_id) || empty($login_password)) {
        $error = "Employee ID and Password are required.";
        $show_login_form = true;
    } else {
        // Fetch employee details
        // Include photo_path in the select if you want to display it immediately after login
        $stmt_login = $conn->prepare("SELECT employee.employee_id, employee.bank_password, employee.role, 
                                        bank_details.bank_id, bank_details.bank_name, employee.photo_path
                                      FROM employee 
                                      JOIN bank_details ON employee.bank_id = bank_details.bank_id 
                                      WHERE employee.employee_id = ?");

        if (!$stmt_login) {
            $error = "Database prepare error: " . $conn->error;
            $show_login_form = true;
        } else {
            $stmt_login->bind_param("i", $login_employee_id);
            $stmt_login->execute();
            $result = $stmt_login->get_result();

            if ($result->num_rows > 0) {
                $row = $result->fetch_assoc();
                if (password_verify($login_password, $row['bank_password'])) {
                    // Login successful
                    $_SESSION['employee_id'] = $row['employee_id']; // Use fetched ID to be safe
                    $_SESSION['bank_id'] = $row['bank_id'];
                    $_SESSION['bank_name'] = $row['bank_name'];
                    $_SESSION['role'] = $row['role']; // This is the crucial line
                    $_SESSION['employee_photo_path'] = $row['photo_path']; // Store photo path in session
                    $_SESSION['employee_name'] = htmlspecialchars($row['employees_first_name'] . ' ' . $row['employees_last_name']); // Assuming you have these columns and fetch them

                    // Redirect to dashboard
                    header("Location: dashboard");
                    exit();
                } else {
                    $error = "Invalid Employee ID or Password.";
                    $show_login_form = true; // Stay on login form if error
                }
            } else {
                $error = "Invalid Employee ID or Password.";
                $show_login_form = true; // Stay on login form if error
            }
            $stmt_login->close();
        }
    }
}

// Check if the login form should be displayed (e.g., if coming from a redirect or error)
if (isset($_GET['action']) && $_GET['action'] == 'login') {
    $show_login_form = true;
}
if (isset($_GET['registration_success']) && $_GET['registration_success'] == 'true') {
    $message = "Registration successful! You can now login.";
    $show_login_form = true; // Show login form after successful registration
}


// Include the header
include 'header.php';
?>

<main class="flex flex-col items-center justify-center py-10 w-full">
    <div class="bg-white p-8 rounded-lg shadow-xl w-full max-w-md">
        <h2 class="text-3xl font-bold text-gray-900 mb-6 text-center">Bank Employee Access</h2>

        <?php if ($message): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
                <span class="block sm:inline"><?php echo htmlspecialchars($message); ?></span>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                <span class="block sm:inline"><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>

        <?php if (!$show_login_form): // Show initial options ?>
            <div class="flex flex-col space-y-4">
                <a href="bank_register" class="w-full flex justify-center py-3 px-4 border border-transparent rounded-md shadow-sm text-lg font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition duration-300 ease-in-out transform hover:scale-105">
                    Register New Bank
                </a>
                <a href="bank_login?action=login" class="w-full flex justify-center py-3 px-4 border border-transparent rounded-md shadow-sm text-lg font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition duration-300 ease-in-out transform hover:scale-105">
                    Login as Employee
                </a>
                <a href="employee_register" class="w-full flex justify-center py-3 px-4 border border-transparent rounded-md shadow-sm text-lg font-medium text-white bg-purple-600 hover:bg-purple-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500 transition duration-300 ease-in-out transform hover:scale-105">
                    Register Employees for Existing Bank
                </a>
            </div>
        <?php else: // Show login form ?>
            <h3 class="text-2xl font-semibold text-gray-800 mb-4 text-center">Employee Login</h3>
            <form action="bank_login" method="POST" class="space-y-4"> <div>
                    <label for="login_employee_id" class="block text-sm font-medium text-gray-700">Employee ID</label>
                    <input type="number" id="login_employee_id" name="login_employee_id" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm" value="<?= htmlspecialchars($_POST['login_employee_id'] ?? '') ?>">
                </div>
                <div>
                    <label for="login_password" class="block text-sm font-medium text-gray-700">Password</label>
                    <input type="password" id="login_password" name="login_password" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                </div>
                <button type="submit" name="login" class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition duration-300 ease-in-out">
                    Login
                </button>
            </form>
        <?php endif; ?>

        <div class="mt-6 text-center">
            <?php if ($show_login_form): ?>
                <p class="text-sm text-gray-600">Not registered? <a href="bank_register" class="font-medium text-indigo-600 hover:text-indigo-500">Register Bank</a> or <a href="employee_register" class="font-medium text-purple-600 hover:text-purple-500">Register Employees</a></p>
            <?php endif; ?>
        </div>
    </div>
</main>

<?php
// Include the footer
include 'footer.php';
$conn->close(); // Close the database connection
?>