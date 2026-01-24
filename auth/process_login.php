<?php
require_once '../config/database.php';
require_once '../config/constants.php';
// Add login time to session
$_SESSION['login_time'] = date('Y-m-d H:i:s');

// Also add IP address for security tracking
$_SESSION['login_ip'] = $_SERVER['REMOTE_ADDR'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    
    $db = getDB();
    $stmt = $db->prepare("SELECT u.*, m.id as member_id FROM users u 
                         LEFT JOIN members m ON u.id = m.user_id 
                         WHERE u.email = :email AND u.is_active = 1");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['member_id'] = $user['member_id'] ?? null;
        
        logActivity('Login', "User logged in successfully");
        
        if ($user['role'] === 'admin') {
            header("Location: ../admin/dashboard.php");
        } else {
            header("Location: ../member/dashboard.php");
        }
        exit();
    } else {
        header("Location: login.php?error=Invalid credentials");
        exit();
    }
    
}
?>