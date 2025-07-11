<?php
// index.php - Main landing page

// Include the header
include 'header.php';
?>

<main class="text-center py-10">
    <h2 class="text-4xl font-extrabold text-gray-900 mb-6">Welcome to Your Bank Management System</h2>
    <p class="text-lg text-gray-700 mb-8 max-w-2xl mx-auto">
        Manage your accounts, transactions, and financial data with ease. Our system provides a secure and efficient way to handle all your banking needs.
    </p>
    <div class="flex flex-col sm:flex-row justify-center items-center gap-4">
        <a href="bank_login.php" class="bg-green-600 hover:bg-green-700 text-white font-bold py-3 px-6 rounded-lg shadow-lg transition duration-300 ease-in-out transform hover:scale-105">
            Get Started
        </a>
        <a href="#" class="bg-gray-200 hover:bg-gray-300 text-gray-800 font-bold py-3 px-6 rounded-lg shadow-lg transition duration-300 ease-in-out transform hover:scale-105">
            Learn More
        </a>
    </div>
</main>

<?php
// Include the footer
include 'footer.php';
?>