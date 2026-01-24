<?php
require_once '../../config/constants.php';
require_once '../../config/database.php';
requireAdmin();

$db = getDB();

// Get cycle ID from URL
$cycle_id = $_GET['cycle_id'] ?? 0;

// Get cycle details
$stmt = $db->prepare("SELECT * FROM cycles WHERE id = :id");
$stmt->execute([':id' => $cycle_id]);
$cycle = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$cycle) {
    header("Location: index.php?error=Cycle not found");
    exit();
}

// Handle payout recording
// In the POST handler section, replace with:
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $member_id = $_POST['member_id'] ?? null;
    $payout_amount = $_POST['payout_amount'] ?? 0;
    $payout_date = $_POST['payout_date'] ?? date('Y-m-d');
    $verification_required = isset($_POST['verification_required']) ? 1 : 0;
    $notes = $_POST['notes'] ?? '';
    
    if ($member_id && $payout_amount > 0) {
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
        
        logActivity("Recorded payout for member ID: $member_id", "Amount: UGX " . number_format($payout_amount, 2) . ", Status: $status");
        
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

// Get pending payouts for verification
$stmt = $db->prepare("
    SELECT p.*, u.full_name, m.member_code, ca.random_number
    FROM payouts p
    JOIN members m ON p.member_id = m.id
    JOIN users u ON m.user_id = u.id
    JOIN cycle_assignments ca ON p.cycle_id = ca.cycle_id AND p.member_id = ca.member_id
    WHERE p.cycle_id = :cycle_id AND p.status = 'pending'
    ORDER BY p.created_at ASC
");
$stmt->execute([':cycle_id' => $cycle_id]);
$pending_verifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get payout periods
$stmt = $db->query("SELECT * FROM payout_periods ORDER BY is_default DESC, days ASC");
$payout_periods = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$total_members = count($assignments);
$paid_count = array_reduce($assignments, function($carry, $item) {
    return $carry + (in_array($item['payout_status'], ['paid', 'pending_verification']) ? 1 : 0);
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
    <style>
        .sidebar { transition: all 0.3s; }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.active { transform: translateX(0); }
        }
    </style>
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

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                    <!-- Left Column: Payout Recording & Verification -->
                    <div class="lg:col-span-2 space-y-8">
                        <!-- Payout Verification Section -->
                        <div class="bg-white rounded-xl shadow">
                            <div class="px-6 py-4 border-b">
                                <h2 class="text-lg font-semibold text-gray-900">Pending Verifications</h2>
                                <p class="text-sm text-gray-600">Review and verify pending payouts</p>
                            </div>
                            
                            <div class="p-6">
                                <?php if (!empty($pending_verifications)): ?>
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
                                                    <span class="px-3 py-1 bg-amber-100 text-amber-800 rounded-full text-sm">
                                                        Needs Verification
                                                    </span>
                                                </div>
                                                
                                                <div class="grid grid-cols-2 gap-4 mb-4">
                                                    <div>
                                                        <p class="text-sm text-gray-500">Payout Amount</p>
                                                        <p class="font-semibold text-emerald-600">UGX <?php echo number_format($payout['amount'], 2); ?></p>
                                                    </div>
                                                    <div>
                                                        <p class="text-sm text-gray-500">Payout Date</p>
                                                        <p class="font-semibold"><?php echo date('M d, Y', strtotime($payout['payout_date'])); ?></p>
                                                    </div>
                                                </div>
                                                
                                                <?php if ($payout['notes']): ?>
                                                    <div class="mb-4">
                                                        <p class="text-sm text-gray-500">Notes</p>
                                                        <p class="text-sm text-gray-700"><?php echo htmlspecialchars($payout['notes']); ?></p>
                                                    </div>
                                                <?php endif; ?>
                                                
                                                <div class="flex space-x-3">
                                                    <button onclick="verifyPayout(<?php echo $payout['id']; ?>, true)" 
                                                            class="flex-1 px-4 py-2 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700">
                                                        <i class="fas fa-check mr-2"></i>Verify
                                                    </button>
                                                    <button onclick="verifyPayout(<?php echo $payout['id']; ?>, false)" 
                                                            class="flex-1 px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                                                        <i class="fas fa-times mr-2"></i>Reject
                                                    </button>
                                                    <button onclick="viewPayoutDetails(<?php echo $payout['id']; ?>)" 
                                                            class="px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300">
                                                        <i class="fas fa-eye mr-2"></i>Details
                                                    </button>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-8">
                                        <i class="fas fa-check-circle text-4xl text-gray-300 mb-4"></i>
                                        <p class="text-gray-500">No pending verifications</p>
                                        <p class="text-sm text-gray-400 mt-2">All payouts are verified</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

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
                                                    <option value="<?php echo $assignment['member_id']; ?>" 
                                                            data-contributions="<?php echo $assignment['cycle_contributions']; ?>"
                                                            <?php echo ($member_id == $assignment['member_id']) ? 'selected' : ''; ?>>
                                                        #<?php echo $assignment['random_number']; ?> - 
                                                        <?php echo htmlspecialchars($assignment['full_name']); ?>
                                                        
                                                    </option>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <?php if ($selected_member): ?>
                                        <!-- Member Details Card -->
                                        <div class="bg-gray-50 rounded-lg p-4 mb-6">
                                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                                <div>
                                                    <p class="text-sm text-gray-500">Member Name</p>
                                                    <p class="font-medium"><?php echo htmlspecialchars($selected_member['full_name']); ?></p>
                                                </div>
                                                <div>
                                                    <p class="text-sm text-gray-500">Random Number</p>
                                                    <p class="font-medium"><?php echo $selected_member['random_number']; ?></p>
                                                </div>
                                                <div>
                                                    <p class="text-sm text-gray-500">Total Contributions</p>
                                                    <p class="font-medium text-emerald-600">
                                                        UGX <?php echo number_format($selected_member['cycle_contributions'], 2); ?>
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Payout Details -->
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 mb-2">Payout Amount</label>
                                                <input type="number" name="payout_amount" required min="0" step="0.01"
                                                       value="<?php echo $selected_member['cycle_contributions']; ?>"
                                                       class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
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
                                                            This adds an extra layer of security for large payouts.
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
                                                    class="px-6 py-3 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 font-medium">
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
                    </div>

                    <!-- Right Column: Payout Periods & Stats -->
                    <div class="space-y-8">
                        <!-- Payout Periods Management -->
                        <div class="bg-white rounded-xl shadow">
                            <div class="px-6 py-4 border-b">
                                <h2 class="text-lg font-semibold text-gray-900">Payout Periods</h2>
                                <p class="text-sm text-gray-600">Manage payout intervals</p>
                            </div>
                            
                            <div class="p-6">
                                <div class="space-y-4">
                                    <?php foreach ($payout_periods as $period): ?>
                                        <div class="border rounded-lg p-4">
                                            <div class="flex justify-between items-start mb-2">
                                                <div>
                                                    <h4 class="font-medium text-gray-900"><?php echo htmlspecialchars($period['name']); ?></h4>
                                                    <p class="text-sm text-gray-500"><?php echo $period['days']; ?> days</p>
                                                </div>
                                                <?php if ($period['is_default']): ?>
                                                    <span class="px-2 py-1 bg-emerald-100 text-emerald-800 rounded text-xs">
                                                        Default
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <?php if ($period['description']): ?>
                                                <p class="text-xs text-gray-600 mb-3"><?php echo htmlspecialchars($period['description']); ?></p>
                                            <?php endif; ?>
                                            
                                            <div class="flex space-x-2">
                                                <?php if (!$period['is_default']): ?>
                                                    <button onclick="setDefaultPeriod(<?php echo $period['id']; ?>)" 
                                                            class="text-xs px-3 py-1 bg-gray-200 text-gray-800 rounded hover:bg-gray-300">
                                                        Set Default
                                                    </button>
                                                <?php endif; ?>
                                                <button onclick="editPeriod(<?php echo $period['id']; ?>)" 
                                                        class="text-xs px-3 py-1 bg-blue-600 text-white rounded hover:bg-blue-700">
                                                    Edit
                                                </button>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                
                                <button onclick="showAddPeriodModal()" 
                                        class="mt-6 w-full px-4 py-2 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700">
                                    <i class="fas fa-plus mr-2"></i> Add Period
                                </button>
                            </div>
                        </div>

                        <!-- Cycle Stats -->
                        <div class="bg-white rounded-xl shadow">
                            <div class="px-6 py-4 border-b">
                                <h2 class="text-lg font-semibold text-gray-900">Cycle Stats</h2>
                            </div>
                            
                            <div class="p-6">
                                <div class="space-y-4">
                                    <div>
                                        <p class="text-sm text-gray-500">Total Members</p>
                                        <p class="text-2xl font-bold"><?php echo $total_members; ?></p>
                                    </div>
                                    
                                    <div>
                                        <p class="text-sm text-gray-500">Total Contributions</p>
                                        <p class="text-2xl font-bold text-emerald-600">
                                            UGX <?php echo number_format($total_cycle_contributions, 2); ?>
                                        </p>
                                    </div>
                                    
                                    <div>
                                        <p class="text-sm text-gray-500">Paid Members</p>
                                        <p class="text-2xl font-bold"><?php echo $paid_count; ?></p>
                                        <p class="text-xs text-gray-500">
                                            <?php echo round(($paid_count / max($total_members, 1)) * 100); ?>% complete
                                        </p>
                                    </div>
                                    
                                    <div>
                                        <p class="text-sm text-gray-500">Next to Receive</p>
                                        <p class="text-lg font-bold">
                                            <?php echo $next_member ? $next_member['full_name'] : 'None'; ?>
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
                                        <span>Cycle Progress</span>
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

    <!-- Add Period Modal -->
    <div id="addPeriodModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50 flex items-center justify-center">
        <div class="bg-white rounded-xl shadow-lg w-full max-w-md mx-4">
            <div class="px-6 py-4 border-b">
                <h3 id="modalTitle" class="text-lg font-semibold text-gray-900">Add Payout Period</h3>
            </div>
            <form id="periodForm" action="save_period.php" method="POST">
                <div class="p-6 space-y-4">
                    <input type="hidden" id="periodId" name="period_id">
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Period Name</label>
                        <input type="text" id="periodName" name="name" required 
                               class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Duration (days)</label>
                        <input type="number" id="periodDays" name="days" min="1" required 
                               class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                        <textarea id="periodDescription" name="description" rows="2" 
                                  class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500"></textarea>
                    </div>
                    
                    <div class="flex items-center">
                        <input type="checkbox" id="isDefault" name="is_default" 
                               class="h-4 w-4 text-emerald-600 focus:ring-emerald-500 border-gray-300 rounded">
                        <label for="is_default" class="ml-2 text-sm text-gray-700">Set as default period</label>
                    </div>
                </div>
                <div class="px-6 py-4 border-t flex justify-end space-x-3">
                    <button type="button" onclick="hideAddPeriodModal()" 
                            class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50">
                        Cancel
                    </button>
                    <button type="submit" 
                            class="px-4 py-2 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700">
                        Save Period
                    </button>
                </div>
            </form>
        </div>
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

        // Payout verification
        function verifyPayout(memberId, verify) {
    const action = verify ? 'verify' : 'reject';
    if (confirm(`Are you sure you want to ${action} this payout?`)) {
        const formData = new FormData();
        formData.append('member_id', memberId);
        formData.append('cycle_id', <?php echo $cycle_id; ?>);
        formData.append('action', action);
        formData.append('notes', prompt('Enter verification notes (optional):', ''));
        
        fetch('verify_payout.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(data.message);
                location.reload();
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred');
        });
    }
}

        function viewPayoutDetails(payoutId) {
            window.open(`payout_details.php?id=${payoutId}`, '_blank');
        }

        // Payout Period Management
        function showAddPeriodModal() {
            document.getElementById('modalTitle').textContent = 'Add Payout Period';
            document.getElementById('periodForm').action = 'save_period.php';
            document.getElementById('periodId').value = '';
            document.getElementById('periodName').value = '';
            document.getElementById('periodDays').value = '';
            document.getElementById('periodDescription').value = '';
            document.getElementById('isDefault').checked = false;
            document.getElementById('addPeriodModal').classList.remove('hidden');
        }

        function hideAddPeriodModal() {
            document.getElementById('addPeriodModal').classList.add('hidden');
        }

        function editPeriod(periodId) {
            fetch(`get_period.php?id=${periodId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const period = data.period;
                        document.getElementById('modalTitle').textContent = 'Edit Payout Period';
                        document.getElementById('periodForm').action = 'update_period.php';
                        document.getElementById('periodId').value = period.id;
                        document.getElementById('periodName').value = period.name;
                        document.getElementById('periodDays').value = period.days;
                        document.getElementById('periodDescription').value = period.description || '';
                        document.getElementById('isDefault').checked = period.is_default == 1;
                        showAddPeriodModal();
                    }
                });
        }

        function setDefaultPeriod(periodId) {
            if (confirm('Set this as the default payout period?')) {
                fetch('set_default_period.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ period_id: periodId })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Default period updated successfully');
                        location.reload();
                    }
                });
            }
        }

        // Close modal when clicking outside
        document.getElementById('addPeriodModal').addEventListener('click', function(e) {
            if (e.target === this) {
                hideAddPeriodModal();
            }
        });

        // Form submission for period management
        document.getElementById('periodForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            fetch(this.action, {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(data => {
                // Handle redirect or show message
                if (data.includes('success')) {
                    location.reload();
                } else {
                    alert('Error saving period');
                }
            });
        });
    </script>
</body>
</html>