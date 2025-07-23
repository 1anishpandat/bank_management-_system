<?php
// security_functions.php - Security-related functions

// Generate CSRF token
function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Validate CSRF token
function validateCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Secure session start
function secureSessionStart() {
    $session_name = 'bms_session';
    $secure = true; // Set to true if using HTTPS
    $httponly = true; // Prevent JavaScript access to session ID
    
    // Forces sessions to only use cookies
    ini_set('session.use_only_cookies', 1);
    
    // Gets current cookies params
    $cookieParams = session_get_cookie_params();
    session_set_cookie_params(
        $cookieParams["lifetime"],
        $cookieParams["path"],
        $cookieParams["domain"],
        $secure,
        $httponly
    );
    
    // Sets the session name to the one set above
    session_name($session_name);
    
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    
    // Regenerate session ID every 30 minutes
    if (!isset($_SESSION['last_regeneration'])) {
        session_regenerate_id(true);
        $_SESSION['last_regeneration'] = time();
    } elseif (time() - $_SESSION['last_regeneration'] > 1800) {
        session_regenerate_id(true);
        $_SESSION['last_regeneration'] = time();
    }
}

// Input sanitization
function sanitizeInput($data) {
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

// Check if user has required role
function checkRole($required_role) {
    if (!isset($_SESSION['role']) || $_SESSION['role'] != $required_role) {
        header("Location: unauthorized.php");
        exit();
    }
}

// Password hashing
function createPasswordHash($password) {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

// Check password strength
function isPasswordStrong($password) {
    return strlen($password) >= 8 && // at least 8 characters
           preg_match('/[A-Z]/', $password) && // at least one uppercase
           preg_match('/[a-z]/', $password) && // at least one lowercase
           preg_match('/[0-9]/', $password) && // at least one number
           preg_match('/[\W]/', $password); // at least one special char
}