<?php
// dashboard.php - Simple dashboard page after successful login

session_start(); // Start the session

// Check if the user is logged in, otherwise redirect to login page
if (!isset($_SESSION['employee_id'])) {
    // Updated redirect to use clean URL
    header("Location: bank_login");
    exit();
}

// Include the header
include 'header.php';
?>

<main class="flex flex-col items-center justify-center py-10 w-full">
    <div class="bg-white p-8 rounded-lg shadow-xl w-full max-w-2xl text-center">
        <h2 class="text-3xl font-bold text-gray-900 mb-6">Welcome to Your Dashboard, <?php echo htmlspecialchars($_SESSION['bank_name']); ?> Employee!</h2>
        <p class="text-lg text-gray-700 mb-4">
            You are logged in as Employee ID: <span class="font-semibold"><?php echo htmlspecialchars($_SESSION['employee_id']); ?></span>.
        </p>
        <p class="text-md text-gray-600 mb-8">
            This is a placeholder dashboard. You can add more functionality here.
        </p>
        <!-- Updated link to use clean URL -->
        <a href="logout" class="bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded-lg shadow-lg transition duration-300 ease-in-out transform hover:scale-105">
            Logout
        </a>
    </div>
</main>

<?php
// Include the footer
include 'footer.php';
?>
