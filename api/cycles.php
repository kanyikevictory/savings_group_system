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
    
    if ($input['action'] === 'assign_numbers') {
        $cycle_id = $input['cycle_id'];
        
        try {
            $db->beginTransaction();
            
            // Get active members
            $stmt = $db->query("SELECT id FROM members WHERE status = 'active'");
            $members = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (empty($members)) {
                throw new Exception('No active members found');
            }
            
            // Generate random numbers
            $numbers = range(1, count($members));
            shuffle($numbers);
            
            // Insert assignments
            $stmt = $db->prepare("
                INSERT INTO cycle_assignments (cycle_id, member_id, random_number, payout_order) 
                VALUES (:cycle_id, :member_id, :random_number, :payout_order)
            ");
            
            foreach ($members as $index => $member_id) {
                $stmt->execute([
                    ':cycle_id' => $cycle_id,
                    ':member_id' => $member_id,
                    ':random_number' => $numbers[$index],
                    ':payout_order' => $index + 1
                ]);
            }
            
            logActivity('Assign Numbers', "Assigned random numbers for cycle ID: $cycle_id");
            
            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Numbers assigned successfully']);
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
?>