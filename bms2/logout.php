<?php
// logout.php - Handles user logout

session_start(); // Start the session

// Unset all session variables
$_SESSION = array();

// Destroy the session
session_destroy();

// Redirect to the login page
// Updated redirect to use clean URL
header("Location: bank_login");
exit();
?>
