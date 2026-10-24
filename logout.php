<?php
require_once 'config.php';
require_once 'includes/functions.php';

startSession();

// Clear session data
$_SESSION = array();

// Destroy session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy session
session_destroy();

// Redirect to login with message
redirect('/login', 'You have been logged out successfully.', 'success');
?>