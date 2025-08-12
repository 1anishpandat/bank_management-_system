<?php
// Enable error reporting for debugging (REMOVE THIS IN PRODUCTION)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Include database connection
require_once 'db_connect.php';

// Check if user is logged in
if (isset($_SESSION['employee_id'])) {
    $employee_id = $_SESSION['employee_id'];
    
    try {
        // Update the user_sessions table to mark session as inactive
        $stmt = $conn->prepare("UPDATE user_sessions 
                               SET logout_time = NOW(), 
                                   is_active = 0 
                               WHERE employee_id = ? AND is_active = 1");
        $stmt->bind_param("i", $employee_id);
        $stmt->execute();
        
        // If no rows were affected, try to find the most recent session
        if ($stmt->affected_rows === 0) {
            $stmt = $conn->prepare("UPDATE user_sessions 
                                   SET logout_time = NOW(), 
                                       is_active = 0 
                                   WHERE employee_id = ? 
                                   ORDER BY login_time DESC 
                                   LIMIT 1");
            $stmt->bind_param("i", $employee_id);
            $stmt->execute();
        }
    } catch (Exception $e) {
        // Log the error but continue with logout
        error_log("Error updating session record: " . $e->getMessage());
    }
}

// Unset all session variables
$_SESSION = array();

// Destroy the session
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();

// Redirect to login page
header("Location: bank_login");
exit();
?>