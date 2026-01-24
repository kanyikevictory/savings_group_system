<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('SITE_NAME', 'Savings Group System');
define('PRIMARY_COLOR', '#10B981'); // Emerald Green
define('SECONDARY_COLOR', '#1F2937'); // Gray-800

// Check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Check if user is admin
function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

// Check if user is member
function isMember() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'member';
}

// Redirect if not logged in
function requireLogin() {
    if (!isLoggedIn()) {
        header("Location: /auth/login.php");
        exit();
    }
}

// Redirect if not admin
function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        header("Location: /member/dashboard.php");
        exit();
    }
}

// Redirect if not member
function requireMember() {
    requireLogin();
    if (!isMember()) {
        header("Location: /admin/dashboard.php");
        exit();
    }
}

// Log activity
function logActivity($action, $details = '') {
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO audit_logs (user_id, action, details, ip_address) 
                          VALUES (:user_id, :action, :details, :ip_address)");
    $stmt->execute([
        ':user_id' => $_SESSION['user_id'] ?? null,
        ':action' => $action,
        ':details' => $details,
        ':ip_address' => $_SERVER['REMOTE_ADDR']
    ]);
}
?>