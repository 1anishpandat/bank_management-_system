<?php
// bank_login.php - Handles bank employee login and initial choice

include 'db_connect.php'; // Include the database connection file

$message = '';
$error = '';
$show_login_form = false; // Flag to control form visibility

// Handle Login
if (isset($_POST['login'])) {
    $login_employee_id = $_POST['login_employee_id'];
    $login_password = $_POST['login_password'];

    // Fetch employee details
    $stmt_login = $conn->prepare("SELECT employee.bank_id, employee.bank_password, employee.role, bank_details.bank_name 
    FROM employee 
    JOIN bank_details ON employee.bank_id = bank_details.bank_id 
    WHERE employee.employee_id = ?");
    $stmt_login->bind_param("i", $login_employee_id);
    $stmt_login->execute();
    $result = $stmt_login->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        if (password_verify($login_password, $row['bank_password'])) {
            // Login successful
            session_start(); // Start session
         // After successful login verification, set the role in session
$_SESSION['employee_id'] = $login_employee_id;
$_SESSION['bank_id'] = $row['bank_id'];
$_SESSION['bank_name'] = $row['bank_name'];
$_SESSION['role'] = $row['role']; // This is the crucial lin
            // Updated redirect to use clean URL
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
                <span class="block sm:inline"><?php echo $message; ?></span>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                <span class="block sm:inline"><?php echo $error; ?></span>
            </div>
        <?php endif; ?>

        <?php if (!$show_login_form): // Show initial options ?>
            <div class="flex flex-col space-y-4">
                <!-- Updated link to use clean URL -->
                <a href="bank_register" class="w-full flex justify-center py-3 px-4 border border-transparent rounded-md shadow-sm text-lg font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition duration-300 ease-in-out transform hover:scale-105">
                    Register
                </a>
                <!-- Updated link to use clean URL -->
                <a href="bank_login?action=login" class="w-full flex justify-center py-3 px-4 border border-transparent rounded-md shadow-sm text-lg font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition duration-300 ease-in-out transform hover:scale-105">
                    Login
                </a>
            </div>
        <?php else: // Show login form ?>
            <h3 class="text-2xl font-semibold text-gray-800 mb-4 text-center">Login</h3>
            <form action="bank_login" method="POST" class="space-y-4"> <!-- Updated form action -->
                <div>
                    <label for="login_employee_id" class="block text-sm font-medium text-gray-700">Employee ID</label>
                    <input type="number" id="login_employee_id" name="login_employee_id" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
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
        
        </div>
    </div>
</main>

<?php
// Include the footer
include 'footer.php';
$conn->close(); // Close the database connection
?>
