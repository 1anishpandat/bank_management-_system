<?php
// admin.php - Admin dashboard page

session_start(); // Start the session

// Include database connection (if needed for admin functionalities)
include 'db_connect.php';

// Check if the user is logged in and is an admin (you might add more robust admin checks here)
// For simplicity, we're currently just checking if an employee is logged in.
// In a real application, you'd have an 'is_admin' flag in your employee table.
if (!isset($_SESSION['employee_id'])) {
    header("Location: bank_login"); // Redirect to login if not logged in
    exit();
}

// Include the header
include 'header.php';
?>

<main class="flex flex-col items-center justify-center py-10 w-full">
    <div class="bg-white p-8 rounded-lg shadow-xl w-full max-w-2xl text-center">
        <h2 class="text-3xl font-bold text-gray-900 mb-6">Welcome to the Admin Panel, <?php echo htmlspecialchars($_SESSION['bank_name']); ?> Employee!</h2>
        <p class="text-lg text-gray-700 mb-4">
            You are logged in as Employee ID: <span class="font-semibold"><?php echo htmlspecialchars($_SESSION['employee_id']); ?></span>.
        </p>
        <p class="text-md text-gray-600 mb-8">
            This is the administrative dashboard. You can add admin-specific functionalities here.
        </p>
        <div class="flex flex-col sm:flex-row justify-center items-center gap-4">
            <!-- Example Admin Links -->
            <a href="#" class="bg-purple-600 hover:bg-purple-700 text-white font-bold py-2 px-4 rounded-lg shadow-lg transition duration-300 ease-in-out transform hover:scale-105">
                Manage Users
            </a>
            <a href="#" class="bg-teal-600 hover:bg-teal-700 text-white font-bold py-2 px-4 rounded-lg shadow-lg transition duration-300 ease-in-out transform hover:scale-105">
                View Reports
            </a>
            <a href="logout" class="bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded-lg shadow-lg transition duration-300 ease-in-out transform hover:scale-105">
                Logout
            </a>
        </div>
        <div class="mt-6 text-center">
            <button onclick="history.back()" class="bg-gray-500 hover:bg-gray-600 text-white font-bold py-2 px-4 rounded-md shadow-md transition duration-300 ease-in-out">
                &larr; Go Back
            </button>
        </div>
    </div>
</main>

<script>
    // This script runs after the page content is loaded.
    // It replaces the current history entry with itself.
    // This makes the admin page the effective "start" of the history stack,
    // so pressing the back button (or Escape key if not in full-screen)
    // will not go back to the login page.
    if (window.history && window.history.replaceState) {
        window.history.replaceState(null, '', location.href);
    }
</script>

<?php
// Include the footer
include 'footer.php';
?>
