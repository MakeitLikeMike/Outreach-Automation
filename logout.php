<?php
require_once 'auth.php';

// Check if user is authenticated before logout
if ($auth->isAuthenticated()) {
    // Log the logout action (optional)
    $user = $auth->getCurrentUser();
    error_log("User logout: " . $user['username'] . " at " . date('Y-m-d H:i:s'));
    
    // Perform logout
    $auth->logout();
}

// Redirect to login page with logout message
if (!headers_sent()) {
    header('Location: login.php?logged_out=1');
    exit();
} else {
    echo '<script>window.location.href="login.php?logged_out=1";</script>';
    exit();
}
?>