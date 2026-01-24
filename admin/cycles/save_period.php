<?php
require_once '../../config/constants.php';
require_once '../../config/database.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'] ?? '';
    $days = $_POST['days'] ?? 0;
    $description = $_POST['description'] ?? '';
    $is_default = isset($_POST['is_default']) ? 1 : 0;
    
    if (empty($name) || $days < 1) {
        header('Location: record_payout.php?cycle_id=' . $_GET['cycle_id'] . '&error=Invalid+period+data');
        exit;
    }
    
    $db = getDB();
    
    // If setting as default, unset other defaults
    if ($is_default) {
        $db->query("UPDATE payout_periods SET is_default = 0");
    }
    
    $stmt = $db->prepare("
        INSERT INTO payout_periods (name, days, description, is_default) 
        VALUES (:name, :days, :description, :is_default)
    ");
    
    $stmt->execute([
        ':name' => $name,
        ':days' => $days,
        ':description' => $description,
        ':is_default' => $is_default
    ]);
    
    logActivity("Created payout period: $name ($days days)");
    header('Location: record_payout.php?cycle_id=' . $_GET['cycle_id'] . '&success=Period+created+successfully');
    exit;
}
?>