<?php
// Uncomment and use these settings
ini_set('session.cookie_lifetime', 86400); // 1 day
ini_set('session.gc_maxlifetime', 86400);
session_set_cookie_params([
    'lifetime' => 86400,
    'path' => '/',
    'domain' => $_SERVER['HTTP_HOST'],
    'secure' => isset($_SERVER['HTTPS']), // if using HTTPS
    'httponly' => true,
    'samesite' => 'Lax' // Changed from Strict to Lax for better navigation
]);