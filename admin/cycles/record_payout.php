
<?php
require_once '../../config/constants.php';
require_once '../../config/database.php';
requireAdmin();

$db = getDB();

// Get cycle ID from URL
$cycle_id = $_GET['cycle_id'] ?? 0;

// Get cycle details with contribution info
$stmt = $db->prepare("SELECT * FROM cycles WHERE id = :id");
$stmt->execute([':id' => $cycle_id]);
$cycle = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$cycle) {
    header("Location: index.php?error=Cycle not found");
    exit();
}

// Handle payout recording
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $member_id = $_POST['member_id'] ?? null;
    $payout_amount = $_POST['payout_amount'] ?? 0;
    $payout_date = $_POST['payout_date'] ?? date('Y-m-d');
    $verification_required = isset($_POST['verification_required']) ? 1 : 0;
    $notes = $_POST['notes'] ?? '';
    
    if ($member_id && $payout_amount > 0) {
        // Check if member has paid full contribution
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(amount), 0) as total_paid 
            FROM contributions 
            WHERE member_id = :member_id AND cycle_id = :cycle_id
        ");
        $stmt->execute([
            ':member_id' => $member_id,
            ':cycle_id' => $cycle_id
        ]);
        $total_paid = $stmt->fetchColumn();
        
        // Get expected contribution amount
        $expected_amount = $cycle['contribution_amount'];
        
        // Verify member has paid at least the expected amount
        if ($total_paid < $expected_amount) {
            $error = "Member has only paid KSh " . number_format($total_paid, 2) . ". Expected contribution is KSh " . number_format($expected_amount, 2);
            header("Location: record_payout.php?cycle_id=$cycle_id&error=" . urlencode($error));
            exit();
        }
        
        // Update cycle assignment
        $status = $verification_required ? 'pending_verification' : 'paid';
        
        $stmt = $db->prepare("
            UPDATE cycle_assignments 
            SET payout_status = :status, 
                payout_date = :payout_date,
                payout_amount = :payout_amount,
                verification_notes = :notes
            WHERE cycle_id = :cycle_id AND member_id = :member_id
        ");
        
        $stmt->execute([
            ':status' => $status,
            ':payout_date' => $payout_date,
            ':payout_amount' => $payout_amount,
            ':notes' => $notes,
            ':cycle_id' => $cycle_id,
            ':member_id' => $member_id
        ]);
        
        logActivity("Recorded payout", "Cycle: {$cycle['name']}, Member ID: $member_id, Amount: KSh " . number_format($payout_amount, 2));
        
        header("Location: record_payout.php?cycle_id=$cycle_id&success=Payout recorded successfully");
        exit();
    }
}

// Get specific member if provided
$member_id = $_GET['member_id'] ?? null;
$selected_member = null;

if ($member_id) {
    $stmt = $db->prepare("
        SELECT ca.*, u.full_name, u.email, u.phone_number, 
               m.member_code, m.join_date,
               (SELECT COALESCE(SUM(amount), 0) FROM contributions WHERE member_id = m.id AND cycle_id = :cycle_id) as cycle_contributions
        FROM cycle_assignments ca
        JOIN members m ON ca.member_id = m.id
        JOIN users u ON m.user_id = u.id
        WHERE ca.cycle_id = :cycle_id AND ca.member_id = :member_id
    ");
    
    $stmt->execute([
        ':cycle_id' => $cycle_id,
        ':member_id' => $member_id
    ]);
    
    $selected_member = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get all cycle assignments
$stmt = $db->prepare("
    SELECT ca.*, u.full_name, u.email, u.phone_number, 
           m.member_code, m.join_date,
           (SELECT COALESCE(SUM(amount), 0) FROM contributions WHERE member_id = m.id AND cycle_id = :cycle_id) as cycle_contributions
    FROM cycle_assignments ca
    JOIN members m ON ca.member_id = m.id
    JOIN users u ON m.user_id = u.id
    WHERE ca.cycle_id = :cycle_id
    ORDER BY ca.random_number ASC
");
$stmt->execute([':cycle_id' => $cycle_id]);
$assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get pending payouts for verification (from cycle_assignments)
$stmt = $db->prepare("
    SELECT ca.*, u.full_name, m.member_code
    FROM cycle_assignments ca
    JOIN members m ON ca.member_id = m.id
    JOIN users u ON m.user_id = u.id
    WHERE ca.cycle_id = :cycle_id AND ca.payout_status = 'pending_verification'
    ORDER BY ca.payout_date ASC
");
$stmt->execute([':cycle_id' => $cycle_id]);
$pending_verifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$total_members = count($assignments);
$paid_count = array_reduce($assignments, function($carry, $item) {
    return $carry + ($item['payout_status'] === 'paid' ? 1 : 0);
}, 0);
$pending_count = array_reduce($assignments, function($carry, $item) {
    return $carry + ($item['payout_status'] === 'pending' ? 1 : 0);
}, 0);
$pending_verification_count = array_reduce($assignments, function($carry, $item) {
    return $carry + ($item['payout_status'] === 'pending_verification' ? 1 : 0);
}, 0);

// Get next member to receive payout
$next_member = null;
foreach ($assignments as $assignment) {
    if ($assignment['payout_status'] === 'pending') {
        $next_member = $assignment;
        break;
    }
}

// Calculate total contributions for cycle
$stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM contributions WHERE cycle_id = :cycle_id");
$stmt->execute([':cycle_id' => $cycle_id]);
$total_cycle_contributions = $stmt->fetchColumn();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Record Payout - Savings Group System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-50">
    <!-- Sidebar -->
    <?php include '../../includes/admin_sidebar.php'; ?>
    
    <div class="ml-0 md:ml-64 min-h-screen">
        <!-- Mobile Header -->
        <header class="md:hidden bg-white shadow p-4 flex justify-between items-center">
            <button onclick="toggleSidebar()" class="text-gray-700">
                <i class="fas fa-bars text-xl"></i>
            </button>
            <h1 class="text-lg font-semibold">Record Payout</h1>
        </header>

        <main class="p-6">
            <div class="max-w-7xl mx-auto">
                <div class="mb-8">
                    <div class="flex justify-between items-start">
                        <div>
                            <h1 class="text-2xl font-bold text-gray-900">Record Payout</h1>
                            <p class="text-gray-600">Manage payouts for cycle: <strong><?php echo htmlspecialchars($cycle['name']); ?></strong></p>
                        </div>
                        <a href="payout_order.php?cycle_id=<?php echo $cycle_id; ?>" 
                           class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 flex items-center">
                            <i class="fas fa-list-ol mr-2"></i>
                            View Payout Order
                        </a>
                    </div>
                </div>

                <!-- Success/Error Messages -->
                <?php if (isset($_GET['success'])): ?>
                    <div class="mb-6 bg-emerald-50 border border-emerald-200 rounded-lg p-4">
                        <div class="flex items-center">
                            <i class="fas fa-check-circle text-emerald-600 mr-3"></i>
                            <p class="text-emerald-800"><?php echo htmlspecialchars($_GET['success']); ?></p>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (isset($_GET['error'])): ?>
                    <div class="mb-6 bg-red-50 border border-red-200 rounded-lg p-4">
                        <div class="flex items-center">
                            <i class="fas fa-exclamation-circle text-red-600 mr-3"></i>
                            <p class="text-red-800"><?php echo htmlspecialchars($_GET['error']); ?></p>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Cycle Info Banner -->
                <div class="mb-8 bg-gradient-to-r from-emerald-600 to-blue-600 rounded-xl shadow-lg p-6 text-white">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between">
                        <div>
                            <h2 class="text-xl font-bold mb-2"><?php echo htmlspecialchars($cycle['name']); ?></h2>
                            <div class="flex items-center space-x-4 text-sm">
                                <span><i class="fas fa-calendar mr-2"></i>Started: <?php echo date('M d, Y', strtotime($cycle['start_date'])); ?></span>
                                <?php if ($cycle['expected_end_date']): ?>
                                    <span><i class="fas fa-flag-checkered mr-2"></i>Ends: <?php echo date('M d, Y', strtotime($cycle['expected_end_date'])); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="mt-4 md:mt-0 flex items-center space-x-6">
                            <div class="text-center">
                                <div class="text-2xl font-bold">KSh <?php echo number_format($cycle['contribution_amount'], 2); ?></div>
                                <div class="text-xs opacity-90">Fixed Contribution</div>
                            </div>
                            <div class="text-center">
                                <div class="text-2xl font-bold"><?php echo ucfirst($cycle['payment_frequency']); ?></div>
                                <div class="text-xs opacity-90">Payment Frequency</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                    <!-- Left Column: Payout Recording & Verification -->
                    <div class="lg:col-span-2 space-y-8">
                        <!-- Record Payout Form -->
                        <div class="bg-white rounded-xl shadow">
                            <div class="px-6 py-4 border-b">
                                <h2 class="text-lg font-semibold text-gray-900">Record New Payout</h2>
                                <p class="text-sm text-gray-600">Select a member and record their payout</p>
                            </div>
                            
                            <div class="p-6">
                                <form method="POST" action="" id="payoutForm">
                                    <input type="hidden" name="cycle_id" value="<?php echo $cycle_id; ?>">
                                    
                                    <!-- Member Selection -->
                                    <div class="mb-6">
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Select Member</label>
                                        <select name="member_id" id="memberSelect" required 
                                                class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500"
                                                onchange="loadMemberDetails(this.value)">
                                            <option value="">Select a member...</option>
                                            <?php foreach ($assignments as $assignment): ?>
                                                <?php if ($assignment['payout_status'] === 'pending'): ?>
                                                    <?php
                                                    $has_full_contribution = $assignment['cycle_contributions'] >= $cycle['contribution_amount'];
                                                    $status_class = $has_full_contribution ? '' : 'text-amber-600';
                                                    ?>
                                                    <option value="<?php echo $assignment['member_id']; ?>" 
                                                            data-contributions="<?php echo $assignment['cycle_contributions']; ?>"
                                                            data-expected="<?php echo $cycle['contribution_amount']; ?>"
                                                            <?php echo ($member_id == $assignment['member_id']) ? 'selected' : ''; ?>
                                                            class="<?php echo $status_class; ?>">
                                                        #<?php echo $assignment['random_number']; ?> - 
                                                        <?php echo htmlspecialchars($assignment['full_name']); ?>
                                                        (Paid: KSh <?php echo number_format($assignment['cycle_contributions'], 2); ?>)
                                                        <?php if (!$has_full_contribution): ?>
                                                            ⚠️ Insufficient
                                                        <?php endif; ?>
                                                    </option>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <?php if ($selected_member): ?>
                                        <?php
                                        $has_full_contribution = $selected_member['cycle_contributions'] >= $cycle['contribution_amount'];
                                        $shortfall = max(0, $cycle['contribution_amount'] - $selected_member['cycle_contributions']);
                                        ?>
                                        
                                        <!-- Member Details Card -->
                                        <div class="bg-gray-50 rounded-lg p-4 mb-6">
                                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                                <div>
                                                    <p class="text-sm text-gray-500">Member Name</p>
                                                    <p class="font-medium"><?php echo htmlspecialchars($selected_member['full_name']); ?></p>
                                                </div>
                                                <div>
                                                    <p class="text-sm text-gray-500">Random Number</p>
                                                    <p class="font-medium">#<?php echo $selected_member['random_number']; ?></p>
                                                </div>
                                            </div>
                                            
                                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-3">
                                                <div>
                                                    <p class="text-sm text-gray-500">Expected Contribution</p>
                                                    <p class="font-medium text-blue-600">KSh <?php echo number_format($cycle['contribution_amount'], 2); ?></p>
                                                </div>
                                                <div>
                                                    <p class="text-sm text-gray-500">Total Paid</p>
                                                    <p class="font-medium <?php echo $has_full_contribution ? 'text-emerald-600' : 'text-amber-600'; ?>">
                                                        KSh <?php echo number_format($selected_member['cycle_contributions'], 2); ?>
                                                    </p>
                                                </div>
                                                <?php if (!$has_full_contribution): ?>
                                                <div>
                                                    <p class="text-sm text-gray-500">Shortfall</p>
                                                    <p class="font-medium text-red-600">KSh <?php echo number_format($shortfall, 2); ?></p>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <?php if (!$has_full_contribution): ?>
                                            <div class="mt-3 bg-amber-50 border border-amber-200 rounded-lg p-3">
                                                <div class="flex items-start">
                                                    <i class="fas fa-exclamation-triangle text-amber-600 mt-1 mr-3"></i>
                                                    <div>
                                                        <p class="text-sm font-medium text-amber-800">Insufficient Contributions</p>
                                                        <p class="text-xs text-amber-700">
                                                            Member has only paid KSh <?php echo number_format($selected_member['cycle_contributions'], 2); ?> 
                                                            of the expected KSh <?php echo number_format($cycle['contribution_amount'], 2); ?>.
                                                            They need to pay KSh <?php echo number_format($shortfall, 2); ?> more before receiving payout.
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <!-- Payout Details -->
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 mb-2">Payout Amount</label>
                                                <input type="number" name="payout_amount" required min="0" step="0.01"
                                                       value="<?php echo $cycle['contribution_amount']; ?>"
                                                       <?php echo !$has_full_contribution ? 'disabled' : ''; ?>
                                                       class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 <?php echo !$has_full_contribution ? 'bg-gray-100' : ''; ?>">
                                                <?php if (!$has_full_contribution): ?>
                                                    <p class="text-xs text-red-600 mt-1">Cannot process payout until full contribution is paid</p>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 mb-2">Payout Date</label>
                                                <input type="date" name="payout_date" required 
                                                       value="<?php echo date('Y-m-d'); ?>"
                                                       class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                                            </div>
                                        </div>
                                        
                                        <!-- Verification Settings -->
                                        <div class="mb-6">
                                            <div class="flex items-center mb-4">
                                                <input type="checkbox" name="verification_required" id="verification_required" 
                                                       class="h-4 w-4 text-emerald-600 focus:ring-emerald-500 border-gray-300 rounded">
                                                <label for="verification_required" class="ml-2 text-sm text-gray-700">
                                                    Require admin verification before marking as paid
                                                </label>
                                            </div>
                                            
                                            <div id="verificationNote" class="hidden bg-blue-50 border border-blue-200 rounded-lg p-4">
                                                <div class="flex">
                                                    <i class="fas fa-info-circle text-blue-500 mt-1 mr-3"></i>
                                                    <div>
                                                        <p class="text-sm text-blue-800 font-medium">Payout will be marked as "Pending Verification"</p>
                                                        <p class="text-xs text-blue-600 mt-1">
                                                            The payout will remain in pending status until an administrator verifies it.
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Notes -->
                                        <div class="mb-6">
                                            <label class="block text-sm font-medium text-gray-700 mb-2">Notes (Optional)</label>
                                            <textarea name="notes" rows="3" 
                                                      class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500"
                                                      placeholder="Add any notes about this payout..."></textarea>
                                        </div>
                                        
                                        <!-- Submit Button -->
                                        <div class="flex justify-end">
                                            <button type="submit" 
                                                    <?php echo !$has_full_contribution ? 'disabled' : ''; ?>
                                                    class="px-6 py-3 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 font-medium <?php echo !$has_full_contribution ? 'opacity-50 cursor-not-allowed' : ''; ?>">
                                                <i class="fas fa-check-circle mr-2"></i>
                                                Record Payout
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <div class="text-center py-8">
                                            <i class="fas fa-user text-4xl text-gray-300 mb-4"></i>
                                            <p class="text-gray-500">Select a member to record payout</p>
                                            <p class="text-sm text-gray-400 mt-2">Only members with pending status are shown</p>
                                        </div>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </div>

                        <!-- Payout Verification Section -->
                        <?php if (!empty($pending_verifications)): ?>
                        <div class="bg-white rounded-xl shadow">
                            <div class="px-6 py-4 border-b">
                                <h2 class="text-lg font-semibold text-gray-900 flex items-center">
                                    <i class="fas fa-clipboard-check text-orange-500 mr-2"></i>
                                    Pending Verifications
                                </h2>
                                <p class="text-sm text-gray-600">Review and verify pending payouts</p>
                            </div>
                            
                            <div class="p-6">
                                <div class="space-y-4">
                                    <?php foreach ($pending_verifications as $payout): ?>
                                        <div class="border rounded-lg p-4">
                                            <div class="flex justify-between items-start mb-3">
                                                <div>
                                                    <h4 class="font-medium text-gray-900"><?php echo htmlspecialchars($payout['full_name']); ?></h4>
                                                    <p class="text-sm text-gray-500">
                                                        Member #<?php echo $payout['member_code']; ?> 
                                                        • Random #<?php echo $payout['random_number']; ?>
                                                    </p>
                                                </div>
                                                <span class="px-3 py-1 bg-orange-100 text-orange-800 rounded-full text-sm">
                                                    Needs Verification
                                                </span>
                                            </div>
                                            
                                            <div class="grid grid-cols-2 gap-4 mb-4">
                                                <div>
                                                    <p class="text-sm text-gray-500">Payout Amount</p>
                                                    <p class="font-semibold text-emerald-600">KSh <?php echo number_format($payout['payout_amount'], 2); ?></p>
                                                </div>
                                                <div>
                                                    <p class="text-sm text-gray-500">Payout Date</p>
                                                    <p class="font-semibold"><?php echo date('M d, Y', strtotime($payout['payout_date'])); ?></p>
                                                </div>
                                            </div>
                                            
                                            <?php if ($payout['verification_notes']): ?>
                                                <div class="mb-4">
                                                    <p class="text-sm text-gray-500">Notes</p>
                                                    <p class="text-sm text-gray-700"><?php echo htmlspecialchars($payout['verification_notes']); ?></p>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <div class="flex space-x-3">
                                                <a href="verify_payout.php?cycle_id=<?php echo $cycle_id; ?>&member_id=<?php echo $payout['member_id']; ?>&action=verify" 
                                                   class="flex-1 px-4 py-2 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 text-center"
                                                   onclick="return confirm('Verify this payout?')">
                                                    <i class="fas fa-check mr-2"></i>Verify
                                                </a>
                                                <a href="verify_payout.php?cycle_id=<?php echo $cycle_id; ?>&member_id=<?php echo $payout['member_id']; ?>&action=reject" 
                                                   class="flex-1 px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 text-center"
                                                   onclick="return confirm('Reject this payout?')">
                                                    <i class="fas fa-times mr-2"></i>Reject
                                                </a>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Right Column: Stats & Quick Actions -->
                    <div class="space-y-8">
                        <!-- Cycle Stats -->
                        <div class="bg-white rounded-xl shadow">
                            <div class="px-6 py-4 border-b">
                                <h2 class="text-lg font-semibold text-gray-900">Cycle Stats</h2>
                            </div>
                            
                            <div class="p-6">
                                <div class="space-y-4">
                                    <div class="grid grid-cols-2 gap-4">
                                        <div>
                                            <p class="text-sm text-gray-500">Total Members</p>
                                            <p class="text-2xl font-bold"><?php echo $total_members; ?></p>
                                        </div>
                                        
                                        <div>
                                            <p class="text-sm text-gray-500">Fixed Amount</p>
                                            <p class="text-2xl font-bold text-purple-600">KSh <?php echo number_format($cycle['contribution_amount'], 2); ?></p>
                                        </div>
                                    </div>
                                    
                                    <div>
                                        <p class="text-sm text-gray-500">Total Contributions</p>
                                        <p class="text-2xl font-bold text-emerald-600">
                                            KSh <?php echo number_format($total_cycle_contributions, 2); ?>
                                        </p>
                                    </div>
                                    
                                    <div class="grid grid-cols-3 gap-2">
                                        <div class="text-center p-2 bg-emerald-50 rounded">
                                            <p class="text-xs text-gray-500">Paid</p>
                                            <p class="text-lg font-bold text-emerald-600"><?php echo $paid_count; ?></p>
                                        </div>
                                        <div class="text-center p-2 bg-amber-50 rounded">
                                            <p class="text-xs text-gray-500">Pending</p>
                                            <p class="text-lg font-bold text-amber-600"><?php echo $pending_count; ?></p>
                                        </div>
                                        <div class="text-center p-2 bg-orange-50 rounded">
                                            <p class="text-xs text-gray-500">Verify</p>
                                            <p class="text-lg font-bold text-orange-600"><?php echo $pending_verification_count; ?></p>
                                        </div>
                                    </div>
                                    
                                    <div>
                                        <p class="text-sm text-gray-500">Next to Receive</p>
                                        <p class="text-lg font-bold">
                                            <?php echo $next_member ? htmlspecialchars($next_member['full_name']) : 'None'; ?>
                                        </p>
                                        <?php if ($next_member): ?>
                                            <p class="text-xs text-gray-500">
                                                Random #<?php echo $next_member['random_number']; ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <!-- Progress Bar -->
                                <div class="mt-6">
                                    <div class="flex justify-between text-sm text-gray-600 mb-1">
                                        <span>Payout Progress</span>
                                        <span><?php echo $paid_count; ?>/<?php echo $total_members; ?></span>
                                    </div>
                                    <div class="w-full bg-gray-200 rounded-full h-2">
                                        <div class="bg-emerald-600 h-2 rounded-full" 
                                             style="width: <?php echo ($paid_count / max($total_members, 1)) * 100; ?>%">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Quick Actions -->
                        <div class="bg-white rounded-xl shadow">
                            <div class="px-6 py-4 border-b">
                                <h2 class="text-lg font-semibold text-gray-900">Quick Actions</h2>
                            </div>
                            
                            <div class="p-6 space-y-3">
                                <a href="payout_order.php?cycle_id=<?php echo $cycle_id; ?>" 
                                   class="block w-full px-4 py-3 bg-blue-50 text-blue-700 rounded-lg hover:bg-blue-100 text-center">
                                    <i class="fas fa-list-ol mr-2"></i>View Payout Order
                                </a>
                                
                                <a href="assign.php?cycle_id=<?php echo $cycle_id; ?>" 
                                   class="block w-full px-4 py-3 bg-purple-50 text-purple-700 rounded-lg hover:bg-purple-100 text-center">
                                    <i class="fas fa-random mr-2"></i>Re-assign Numbers
                                </a>
                                
                                <a href="index.php" 
                                   class="block w-full px-4 py-3 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 text-center">
                                    <i class="fas fa-arrow-left mr-2"></i>Back to Cycles
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('active');
        }

        function loadMemberDetails(memberId) {
            if (memberId) {
                window.location.href = `record_payout.php?cycle_id=<?php echo $cycle_id; ?>&member_id=${memberId}`;
            }
        }

        // Verification checkbox toggle
        const verificationCheckbox = document.getElementById('verification_required');
        const verificationNote = document.getElementById('verificationNote');
        
        if (verificationCheckbox) {
            verificationCheckbox.addEventListener('change', function() {
                if (this.checked) {
                    verificationNote.classList.remove('hidden');
                } else {
                    verificationNote.classList.add('hidden');
                }
            });
        }
    </script>
</body>
</html>