<?php
// Authentication check for protected pages
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is authenticated
if (!isset($_SESSION['user_authenticated']) || $_SESSION['user_authenticated'] !== true) {
    // Not authenticated, redirect to login
    if (!headers_sent()) {
        header('Location: login.php');
        exit();
    } else {
        // If headers already sent, use JavaScript redirect
        echo '<script>window.location.href="login.php";</script>';
        exit();
    }
}

// Optional: Check session timeout (24 hours)
$session_timeout = 24 * 60 * 60; // 24 hours in seconds
if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > $session_timeout) {
    // Session expired
    session_destroy();
    if (!headers_sent()) {
        header('Location: login.php?expired=1');
        exit();
    } else {
        echo '<script>window.location.href="login.php?expired=1";</script>';
        exit();
    }
}

// Update last activity time
$_SESSION['last_activity'] = time();
?>