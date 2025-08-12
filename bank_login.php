<?php
// bank_login.php - Handles bank employee login with strict single-session enforcement

session_start(); // Start session at the very beginning
include 'db_connect.php'; // Include the database connection file

$message = '';
$error = '';
$show_login_form = false; // Flag to control form visibility

// Function to generate a unique session token
function generateSessionToken() {
    return bin2hex(random_bytes(32));
}

// Function to check if user is already logged in from another session
function isUserAlreadyLoggedIn($conn, $employee_id) {
    $stmt = $conn->prepare("SELECT session_id, session_token, last_activity, login_time FROM user_sessions WHERE employee_id = ? AND is_active = 1");
    if ($stmt) {
        $stmt->bind_param("i", $employee_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $session_data = $result->fetch_assoc();
            // Log the active session found
            error_log("Active session found for employee $employee_id: Session ID " . $session_data['session_id'] . 
                     " logged in at " . $session_data['login_time'] . 
                     " last activity at " . $session_data['last_activity']);
            $stmt->close();
            return $session_data; // Return session data instead of just true
        }
        $stmt->close();
    } else {
        error_log("Database error in isUserAlreadyLoggedIn: " . $conn->error);
    }
    return false;
}

// Function to force logout all sessions for an employee (admin function)
function forceLogoutEmployee($conn, $employee_id, $reason = 'Force logout') {
    try {
        // Update all active sessions to inactive
        $stmt = $conn->prepare("UPDATE user_sessions SET is_active = 0, logout_time = NOW() WHERE employee_id = ? AND is_active = 1");
        if ($stmt) {
            $stmt->bind_param("i", $employee_id);
            $result = $stmt->execute();
            $affected_rows = $stmt->affected_rows;
            $stmt->close();
            
            error_log("$reason: Deactivated $affected_rows sessions for employee $employee_id");
            return $affected_rows > 0;
        }
    } catch (Exception $e) {
        error_log("Exception in forceLogoutEmployee: " . $e->getMessage());
    }
    return false;
}

// Function to create a new user session (with strict enforcement)
function createUserSession($conn, $employee_id, $session_token) {
    try {
        // CRITICAL: First check if there are any active sessions
        $active_session = isUserAlreadyLoggedIn($conn, $employee_id);
        if ($active_session !== false) {
            error_log("Rejected login attempt for employee $employee_id - active session exists");
            return false; // Reject the new login attempt
        }
        
        // Double-check: Force cleanup any potentially missed active sessions
        $stmt_cleanup = $conn->prepare("UPDATE user_sessions SET is_active = 0, logout_time = NOW() WHERE employee_id = ? AND is_active = 1");
        if ($stmt_cleanup) {
            $stmt_cleanup->bind_param("i", $employee_id);
            $stmt_cleanup->execute();
            $cleanup_count = $stmt_cleanup->affected_rows;
            if ($cleanup_count > 0) {
                error_log("Cleaned up $cleanup_count stale sessions for employee $employee_id during login");
            }
            $stmt_cleanup->close();
        }
        
        // Create new session record
        $stmt = $conn->prepare("INSERT INTO user_sessions (employee_id, session_token, login_time, last_activity, is_active) VALUES (?, ?, NOW(), NOW(), 1)");
        if ($stmt) {
            $stmt->bind_param("is", $employee_id, $session_token);
            $result = $stmt->execute();
            if (!$result) {
                error_log("Session creation error: " . $stmt->error);
                return false;
            }
            $insert_id = $conn->insert_id;
            error_log("New session created with ID: $insert_id for employee: $employee_id");
            $stmt->close();
            return true;
        } else {
            error_log("Prepare statement error: " . $conn->error);
            return false;
        }
    } catch (Exception $e) {
        error_log("Exception in createUserSession: " . $e->getMessage());
        return false;
    }
}

// Function to update session activity
function updateSessionActivity($conn, $employee_id, $session_token) {
    $stmt = $conn->prepare("UPDATE user_sessions SET last_activity = NOW() WHERE employee_id = ? AND session_token = ? AND is_active = 1");
    if ($stmt) {
        $stmt->bind_param("is", $employee_id, $session_token);
        $result = $stmt->execute();
        $affected_rows = $stmt->affected_rows;
        $stmt->close();
        
        // If no rows were affected, the session might be invalid
        if ($affected_rows == 0) {
            error_log("Warning: Session activity update failed for employee $employee_id - session may be invalid");
            return false;
        }
        return true;
    }
    return false;
}

// Function to completely destroy session
function destroySession() {
    // Unset all session variables
    $_SESSION = array();
    
    // Delete the session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    // Destroy the session
    session_destroy();
}

// HANDLE LOGOUT FIRST (before any other processing)
if (isset($_GET['logout']) && $_GET['logout'] == 'true') {
    $logout_success = false;
    
    // Get employee ID before destroying session
    $logout_employee_id = isset($_SESSION['employee_id']) ? $_SESSION['employee_id'] : null;
    $session_token = isset($_SESSION['session_token']) ? $_SESSION['session_token'] : null;
    
    if ($logout_employee_id) {
        // Method 1: Deactivate specific session if token is available
        if ($session_token) {
            $stmt_logout_specific = $conn->prepare("UPDATE user_sessions SET is_active = 0, logout_time = NOW() WHERE employee_id = ? AND session_token = ? AND is_active = 1");
            if ($stmt_logout_specific) {
                $stmt_logout_specific->bind_param("is", $logout_employee_id, $session_token);
                $logout_success = $stmt_logout_specific->execute();
                $affected_rows = $stmt_logout_specific->affected_rows;
                $stmt_logout_specific->close();
                error_log("Logout: Deactivated $affected_rows specific session for employee $logout_employee_id");
            }
        }
        
        // Method 2: Fallback - deactivate ALL sessions for this employee
        if (!$logout_success) {
            $stmt_logout_all = $conn->prepare("UPDATE user_sessions SET is_active = 0, logout_time = NOW() WHERE employee_id = ? AND is_active = 1");
            if ($stmt_logout_all) {
                $stmt_logout_all->bind_param("i", $logout_employee_id);
                $logout_success = $stmt_logout_all->execute();
                $affected_rows = $stmt_logout_all->affected_rows;
                $stmt_logout_all->close();
                error_log("Logout: Deactivated $affected_rows sessions for employee $logout_employee_id");
            }
        }
    }
    
    // Completely destroy the session
    destroySession();
    
    // Start a new session for the logout message
    session_start();
    
    // Set logout message
    if ($logout_success || $logout_employee_id) {
        $_SESSION['logout_message'] = "You have been successfully logged out. You can now login from any device.";
    } else {
        $_SESSION['logout_message'] = "Logout completed. You can now login again.";
    }
    
    // Redirect to prevent resubmission and clear URL parameters
    header("Location: bank_login");
    exit();
}

// Check for logout message from redirect
if (isset($_SESSION['logout_message'])) {
    $message = $_SESSION['logout_message'];
    unset($_SESSION['logout_message']); // Remove after displaying
    $show_login_form = true;
}

// Check if current session is valid (for already logged in users)
if (isset($_SESSION['employee_id']) && isset($_SESSION['session_token'])) {
    $stmt_check = $conn->prepare("SELECT employee_id FROM user_sessions WHERE employee_id = ? AND session_token = ? AND is_active = 1");
    if ($stmt_check) {
        $stmt_check->bind_param("is", $_SESSION['employee_id'], $_SESSION['session_token']);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();
        
        if ($result_check->num_rows > 0) {
            // Update last activity to keep session alive
            if (updateSessionActivity($conn, $_SESSION['employee_id'], $_SESSION['session_token'])) {
                // User is already logged in and session is valid, redirect to dashboard
                header("Location: dashboard");
                exit();
            } else {
                // Session update failed, session might be invalid
                destroySession();
                session_start();
                $error = "Your session has expired. Please login again.";
                $show_login_form = true;
            }
        } else {
            // Session is invalid, clear session data
            destroySession();
            session_start();
            $error = "Your session has expired. Please login again.";
            $show_login_form = true;
        }
        $stmt_check->close();
    }
}

// Handle Login
if (isset($_POST['login'])) {
    $login_employee_id = trim($_POST['login_employee_id']); // Trim whitespace
    $login_password = $_POST['login_password'];

    // Basic validation
    if (empty($login_employee_id) || empty($login_password)) {
        $error = "Employee ID and Password are required.";
        $show_login_form = true;
    } else {
        // CRITICAL CHECK: Check if user is already logged in from another session
        $existing_session = isUserAlreadyLoggedIn($conn, $login_employee_id);
        if ($existing_session !== false) {
            $login_time = $existing_session['login_time'];
            $last_activity = $existing_session['last_activity'];
            
            $error = "This employee ID ($login_employee_id) is currently active on another device/browser. " .
                    "Login time: $login_time, Last activity: $last_activity. " .
                    "Please logout from the other session first, or use the 'Force Logout' option below if you're sure you want to terminate the other session.";
            $show_login_form = true;
        } else {
            // No active session found, proceed with login validation
            $stmt_login = $conn->prepare("SELECT employee.employee_id, employee.bank_password, employee.role, 
                                            bank_details.bank_id, bank_details.bank_name, employee.photo_path,
                                            employee.employees_first_name, employee.employees_last_name
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
                        // Generate session token
                        $session_token = generateSessionToken();
                        
                        // Create user session record with strict enforcement
                        if (createUserSession($conn, $row['employee_id'], $session_token)) {
                            // Login successful
                            $_SESSION['employee_id'] = $row['employee_id'];
                            $_SESSION['bank_id'] = $row['bank_id'];
                            $_SESSION['bank_name'] = $row['bank_name'];
                            $_SESSION['role'] = $row['role'];
                            $_SESSION['employee_photo_path'] = $row['photo_path'];
                            $_SESSION['session_token'] = $session_token;
                            $_SESSION['employee_name'] = htmlspecialchars($row['employees_first_name'] . ' ' . $row['employees_last_name']);

                            // Log successful login
                            error_log("Successful login for employee " . $row['employee_id'] . " from IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

                            // Redirect to dashboard
                            header("Location: dashboard");
                            exit();
                        } else {
                            $error = "Login failed: Unable to create session. This usually means another session is active. Please try again.";
                            $show_login_form = true;
                        }
                    } else {
                        $error = "Invalid Employee ID or Password.";
                        $show_login_form = true;
                    }
                } else {
                    $error = "Invalid Employee ID or Password.";
                    $show_login_form = true;
                }
                $stmt_login->close();
            }
        }
    }
}

// Handle force logout (admin/troubleshooting feature)
if (isset($_POST['force_logout'])) {
    $force_employee_id = trim($_POST['force_employee_id']);
    if (!empty($force_employee_id) && is_numeric($force_employee_id)) {
        if (forceLogoutEmployee($conn, $force_employee_id, "Force logout requested")) {
            $message = "All active sessions for employee ID $force_employee_id have been terminated. You can now login.";
        } else {
            $error = "No active sessions found for employee ID $force_employee_id, or logout failed.";
        }
    } else {
        $error = "Invalid employee ID for force logout.";
    }
    $show_login_form = true;
}

// Handle manual session cleanup (for debugging - keep your existing functionality)
if (isset($_GET['cleanup']) && $_GET['cleanup'] == 'true' && isset($_GET['emp_id'])) {
    $cleanup_emp_id = intval($_GET['emp_id']);
    if (forceLogoutEmployee($conn, $cleanup_emp_id, "Manual cleanup")) {
        $message = "All sessions for employee ID $cleanup_emp_id have been cleared.";
    } else {
        $error = "Failed to cleanup sessions for employee ID $cleanup_emp_id.";
    }
    $show_login_form = true;
}

// Show login form for specific actions
if (isset($_GET['action']) && $_GET['action'] == 'login') {
    $show_login_form = true;
}

if (isset($_GET['registration_success']) && $_GET['registration_success'] == 'true') {
    $message = "Registration successful! You can now login.";
    $show_login_form = true;
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
            <form action="bank_login" method="POST" class="space-y-4">
                <div>
                    <label for="login_employee_id" class="block text-sm font-medium text-gray-700">Employee ID</label>
                    <input type="number" id="login_employee_id" name="login_employee_id" required 
                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm" 
                           value="<?= htmlspecialchars($_POST['login_employee_id'] ?? '') ?>">
                </div>
                <div>
                    <label for="login_password" class="block text-sm font-medium text-gray-700">Password</label>
                    <input type="password" id="login_password" name="login_password" required 
                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                </div>
                <button type="submit" name="login" 
                        class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition duration-300 ease-in-out">
                    Login
                </button>
            </form>
        <?php endif; ?>

        <div class="mt-6 text-center">
            <?php if ($show_login_form): ?>
                <p class="text-sm text-gray-600">Not registered? <a href="bank_register" class="font-medium text-indigo-600 hover:text-indigo-500">Register Bank</a> or <a href="employee_register" class="font-medium text-purple-600 hover:text-purple-500">Register Employees</a></p>
                <div class="mt-4 p-3 bg-blue-50 rounded-md">
                    <p class="text-xs text-blue-800">
                        <strong>Security Note:</strong> Only one active session per employee is allowed. You must logout from other devices before logging in here.
                    </p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<?php
// Include the footer
include 'footer.php';
$conn->close(); // Close the database connection
?>

<script>
function checkSessions(employeeId) {
    if (employeeId && employeeId != '0') {
        fetch('check_sessions.php?emp_id=' + employeeId)
            .then(response => response.text())
            .then(data => {
                alert('Active sessions info:\n' + data);
            })
            .catch(error => {
                alert('Error checking sessions: ' + error);
            });
    } else {
        alert('Please enter employee ID first');
    }
}
</script>