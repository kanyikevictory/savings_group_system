<?php
// Start session and include necessary files
require_once '../config/constants.php';

// Store user info for logging before destroying session
$user_id = $_SESSION['user_id'] ?? null;
$user_name = $_SESSION['full_name'] ?? 'Unknown';
$user_role = $_SESSION['role'] ?? 'Unknown';
$login_time = $_SESSION['login_time'] ?? 'Unknown';

// Calculate session duration
$session_duration = '';
if (isset($_SESSION['login_time'])) {
    $login_time_obj = new DateTime($_SESSION['login_time']);
    $logout_time_obj = new DateTime();
    $interval = $logout_time_obj->diff($login_time_obj);
    $session_duration = $interval->format('%h hours %i minutes %s seconds');
}

// Log the logout activity
if ($user_id) {
    require_once '../config/database.php';
    $db = getDB();
    
    $stmt = $db->prepare("INSERT INTO audit_logs (user_id, action, details, ip_address) 
                          VALUES (:user_id, :action, :details, :ip_address)");
    $stmt->execute([
        ':user_id' => $user_id,
        ':action' => 'Logout',
        ':details' => "User $user_name ($user_role) logged out. Session duration: $session_duration",
        ':ip_address' => $_SERVER['REMOTE_ADDR']
    ]);
}

// Destroy the session completely
$_SESSION = array();

// Destroy session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Finally, destroy the session
session_destroy();

// Redirect to login page with success message
header("Location: login.php?message=You have been logged out successfully&success=true");
exit();
?>