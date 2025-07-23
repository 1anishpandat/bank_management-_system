<?php
// db_connect.php - Handles database connection

$servername = "localhost"; // Your database server name
$username = "root"; // Your database username
$password = ""; // Your database password
$dbname = "bms"; // Your database name

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);


// Create connection with error handling
try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    
    // Check connection
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }
    
    // Set charset to utf8mb4 for full Unicode support
    if (!$conn->set_charset("utf8mb4")) {
        throw new Exception("Error setting charset: " . $conn->error);
    }
    
} catch (Exception $e) {
    // Log the error (in a real app, you'd log to a file)
    error_log("Database connection error: " . $e->getMessage());
    
    // Show user-friendly message
    die("We're experiencing technical difficulties. Please try again later.");
}
}

?>
