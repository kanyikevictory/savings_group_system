<?php
header('Content-Type: application/json');
require_once '../config/database.php';
require_once '../config/constants.php';

if (!isLoggedIn() || !isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if ($input['action'] === 'update_status') {
        $stmt = $db->prepare("UPDATE members SET status = :status WHERE id = :id");
        $stmt->execute([
            ':status' => $input['status'],
            ':id' => $input['member_id']
        ]);
        
        logActivity('Update Member Status', "Updated member ID {$input['member_id']} to {$input['status']}");
        
        echo json_encode(['success' => true]);
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    // ... existing update_status action ...
    
    if ($input['action'] === 'reset_password') {
        $member_id = $input['member_id'] ?? 0;
        $new_password = $input['new_password'] ?? '';
        
        if (empty($new_password) || strlen($new_password) < 6) {
            echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters']);
            exit();
        }
        
        // Get user ID from member
        $stmt = $db->prepare("SELECT user_id FROM members WHERE id = :member_id");
        $stmt->execute([':member_id' => $member_id]);
        $user_id = $stmt->fetchColumn();
        
        if (!$user_id) {
            echo json_encode(['success' => false, 'message' => 'Member not found']);
            exit();
        }
        
        // Update password
        $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $db->prepare("UPDATE users SET password_hash = :password_hash WHERE id = :user_id");
        $stmt->execute([
            ':password_hash' => $password_hash,
            ':user_id' => $user_id
        ]);
        
        logActivity('Reset Password', "Reset password for member ID: $member_id");
        
        echo json_encode(['success' => true, 'message' => 'Password reset successfully']);
    }
}

?>