<?php
require_once '../../config/constants.php';
require_once '../../config/database.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $member_id = $_POST['member_id'] ?? null;
    $cycle_id = $_POST['cycle_id'] ?? null;
    $action = $_POST['action'] ?? ''; // 'verify' or 'reject'
    $notes = $_POST['notes'] ?? '';
    
    if (!$member_id || !$cycle_id || !in_array($action, ['verify', 'reject'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid request']);
        exit;
    }
    
    $db = getDB();
    
    if ($action === 'verify') {
        $stmt = $db->prepare("
            UPDATE cycle_assignments 
            SET payout_status = 'paid',
                verified_by = :user_id,
                verified_at = NOW(),
                verification_notes = CONCAT(COALESCE(verification_notes, ''), '\\nVerified: ', :notes)
            WHERE cycle_id = :cycle_id AND member_id = :member_id
        ");
    } else {
        $stmt = $db->prepare("
            UPDATE cycle_assignments 
            SET payout_status = 'pending',
                verified_by = :user_id,
                verified_at = NOW(),
                verification_notes = CONCAT(COALESCE(verification_notes, ''), '\\nRejected: ', :notes)
            WHERE cycle_id = :cycle_id AND member_id = :member_id
        ");
    }
    
    $stmt->execute([
        ':user_id' => $_SESSION['user_id'],
        ':notes' => $notes,
        ':cycle_id' => $cycle_id,
        ':member_id' => $member_id
    ]);
    
    $action_text = $action === 'verify' ? 'verified' : 'rejected';
    logActivity("$action_text payout for member ID: $member_id", "Cycle: $cycle_id, Notes: $notes");
    
    echo json_encode([
        'success' => true, 
        'message' => "Payout $action_text successfully"
    ]);
}
?>