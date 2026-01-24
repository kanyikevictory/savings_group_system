<?php
require_once '../../config/constants.php';
require_once '../../config/database.php';
requireAdmin();

$db = getDB();

// Get member ID from URL
$member_id = $_GET['id'] ?? 0;

// Get member details with user info
$stmt = $db->prepare("
    SELECT m.*, u.email, u.full_name, u.phone_number, u.created_at as account_created
    FROM members m 
    JOIN users u ON m.user_id = u.id 
    WHERE m.id = :member_id
");
$stmt->execute([':member_id' => $member_id]);
$member = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$member) {
    header("Location: index.php?error=Member not found");
    exit();
}

// Get member's total contributions (ALL cycles)
$stmt = $db->prepare("
    SELECT COALESCE(SUM(amount), 0) as total_contributions 
    FROM contributions 
    WHERE member_id = :member_id
");
$stmt->execute([':member_id' => $member_id]);
$total_contributions = $stmt->fetchColumn();

// Get member's total payouts (ALL cycles)
$stmt = $db->prepare("
    SELECT COALESCE(SUM(payout_amount), 0) as total_payouts 
    FROM cycle_assignments 
    WHERE member_id = :member_id AND payout_status = 'paid'
");
$stmt->execute([':member_id' => $member_id]);
$total_payouts = $stmt->fetchColumn();

$current_balance = $total_contributions - $total_payouts;

// Get member's contributions for CURRENT active cycle
$current_cycle_contributions = 0;
$stmt = $db->prepare("
    SELECT COALESCE(SUM(c.amount), 0) as cycle_contributions
    FROM contributions c
    JOIN cycles cy ON c.cycle_id = cy.id
    WHERE c.member_id = :member_id 
    AND cy.status = 'active'
");
$stmt->execute([':member_id' => $member_id]);
$current_cycle_contributions = $stmt->fetchColumn();

// Get recent contributions (last 10)
$stmt = $db->prepare("
    SELECT c.*, cy.name as cycle_name 
    FROM contributions c 
    LEFT JOIN cycles cy ON c.cycle_id = cy.id 
    WHERE c.member_id = :member_id 
    ORDER BY c.contribution_date DESC 
    LIMIT 10
");
$stmt->execute([':member_id' => $member_id]);
$recent_contributions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get payout history
$stmt = $db->prepare("
    SELECT 
        ca.payout_date,
        ca.payout_amount as amount,
        c.name as cycle_name,
        ca.payout_status,
        ca.verified_by,
        ca.verified_at,
        ca.verification_notes as notes,
        ca.random_number,
        ca.payout_status as status
    FROM cycle_assignments ca 
    JOIN cycles c ON ca.cycle_id = c.id 
    WHERE ca.member_id = :member_id 
    AND ca.payout_status IN ('paid', 'pending_verification')
    ORDER BY ca.payout_date DESC
");
$stmt->execute([':member_id' => $member_id]);
$payout_history = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get current cycle assignment
$stmt = $db->prepare("
    SELECT ca.*, c.name as cycle_name, c.id as cycle_id
    FROM cycle_assignments ca 
    JOIN cycles c ON ca.cycle_id = c.id 
    WHERE ca.member_id = :member_id 
    AND c.status = 'active' 
    LIMIT 1
");
$stmt->execute([':member_id' => $member_id]);
$current_assignment = $stmt->fetch(PDO::FETCH_ASSOC);

// ========== EXPECTED PAYOUT CALCULATION ==========
$expected_payout = 0;
$total_cycle_savings = 0;
$total_members_in_cycle = 0;
$payout_period_info = "Weekly (7 days)"; // Default

if ($current_assignment) {
    // Get the current cycle's total contributions from ALL members
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(amount), 0) as total_cycle_savings
        FROM contributions 
        WHERE cycle_id = :cycle_id
    ");
    $stmt->execute([':cycle_id' => $current_assignment['cycle_id']]);
    $total_cycle_savings = $stmt->fetchColumn();
    
    // Get total active members in this cycle
    $stmt = $db->prepare("
        SELECT COUNT(DISTINCT member_id) as total_members
        FROM cycle_assignments 
        WHERE cycle_id = :cycle_id
    ");
    $stmt->execute([':cycle_id' => $current_assignment['cycle_id']]);
    $total_members_in_cycle = $stmt->fetchColumn();
    
    // Get payout period setting
    try {
        $stmt = $db->query("SELECT * FROM payout_periods WHERE is_default = 1 LIMIT 1");
        $default_period = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($default_period) {
            $payout_period_info = $default_period['name'] . " (" . $default_period['days'] . " days)";
        }
    } catch (Exception $e) {
        // Table doesn't exist, use default
        $payout_period_info = "Weekly (7 days)";
    }
    
    // Calculate expected payout: Total savings ÷ Number of members
    if ($total_members_in_cycle > 0) {
        $expected_payout = $total_cycle_savings / $total_members_in_cycle;
    }
}

// Get pending verification count for this member
$stmt = $db->prepare("
    SELECT COUNT(*) as pending_count
    FROM cycle_assignments 
    WHERE member_id = :member_id 
    AND payout_status = 'pending_verification'
    AND cycle_id IN (SELECT id FROM cycles WHERE status = 'active')
");
$stmt->execute([':member_id' => $member_id]);
$pending_verification_count = $stmt->fetchColumn();

// Handle status change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_status') {
        $new_status = $_POST['status'] === 'active' ? 'active' : 'inactive';
        
        try {
            $stmt = $db->prepare("UPDATE members SET status = :status WHERE id = :id");
            $stmt->execute([
                ':status' => $new_status,
                ':id' => $member_id
            ]);
            
            logActivity('Update Member Status', "Updated member ID $member_id to $new_status");
            
            $_SESSION['success'] = "Member status updated successfully!";
            header("Location: view.php?id=$member_id");
            exit();
        } catch (Exception $e) {
            $error = "Error updating status: " . $e->getMessage();
        }
    }
    
    // Handle saving admin notes
    if ($_POST['action'] === 'save_notes') {
        $admin_notes = $_POST['admin_notes'] ?? '';
        
        try {
            $stmt = $db->prepare("UPDATE members SET admin_notes = :notes WHERE id = :id");
            $stmt->execute([
                ':notes' => $admin_notes,
                ':id' => $member_id
            ]);
            
            logActivity('Update Member Notes', "Updated notes for member ID $member_id");
            
            $_SESSION['success'] = "Notes saved successfully!";
            header("Location: view.php?id=$member_id");
            exit();
        } catch (Exception $e) {
            $error = "Error saving notes: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Member Profile - Savings Group System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-50">
    <?php include '../../includes/admin_sidebar.php'; ?>
    
    <div class="ml-0 md:ml-64 min-h-screen">
        <!-- Mobile Header -->
        <header class="md:hidden bg-white shadow p-4 flex justify-between items-center">
            <button onclick="toggleSidebar()" class="text-gray-700">
                <i class="fas fa-bars text-xl"></i>
            </button>
            <h1 class="text-lg font-semibold">Member Profile</h1>
        </header>

        <main class="p-6">
            <div class="max-w-6xl mx-auto">
                <!-- Header with Back Button -->
                <div class="mb-8">
                    <div class="flex justify-between items-center">
                        <div>
                            <a href="index.php" class="text-emerald-600 hover:text-emerald-800 mb-4 inline-flex items-center">
                                <i class="fas fa-arrow-left mr-2"></i>
                                Back to Members
                            </a>
                            <h1 class="text-2xl font-bold text-gray-900">Member Profile</h1>
                            <p class="text-gray-600">View and manage member details</p>
                        </div>
                        
                        <!-- Status Badge -->
                        <form method="POST" action="" class="flex items-center space-x-4">
                            <span class="px-3 py-1 rounded-full text-sm font-medium
                                <?php echo $member['status'] == 'active' 
                                    ? 'bg-emerald-100 text-emerald-800' 
                                    : 'bg-gray-100 text-gray-800'; ?>">
                                <?php echo ucfirst($member['status']); ?>
                            </span>
                            
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="status" value="<?php echo $member['status'] == 'active' ? 'inactive' : 'active'; ?>">
                            
                            <button type="submit" 
                                    class="px-4 py-2 text-sm rounded-lg 
                                        <?php echo $member['status'] == 'active' 
                                            ? 'bg-red-100 text-red-700 hover:bg-red-200' 
                                            : 'bg-emerald-100 text-emerald-700 hover:bg-emerald-200'; ?>">
                                <?php echo $member['status'] == 'active' ? 'Deactivate' : 'Activate'; ?>
                            </button>
                        </form>
                    </div>
                </div>

                <?php if (isset($_SESSION['success'])): ?>
                    <div class="mb-6 bg-emerald-50 border border-emerald-200 text-emerald-700 px-4 py-3 rounded-lg">
                        <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                    </div>
                <?php endif; ?>

                <?php if (isset($error)): ?>
                    <div class="mb-6 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <!-- Pending Verification Warning -->
                <?php if ($pending_verification_count > 0): ?>
                <div class="mb-6 bg-amber-50 border border-amber-200 rounded-xl p-4">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i class="fas fa-exclamation-triangle text-amber-600 text-xl"></i>
                        </div>
                        <div class="ml-3">
                            <h3 class="text-sm font-medium text-amber-800">Pending Verification Required</h3>
                            <p class="text-sm text-amber-700">
                                This member has <?php echo $pending_verification_count; ?> payout(s) waiting for verification.
                            </p>
                        </div>
                        <div class="ml-auto">
                            <?php if ($current_assignment): ?>
                            <a href="../cycles/record_payout.php?cycle_id=<?php echo $current_assignment['cycle_id']; ?>&action=verify" 
                               class="bg-amber-600 text-white px-3 py-1 rounded-lg hover:bg-amber-700 text-sm">
                                Verify Now
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Member Profile Card -->
                <div class="mb-8 bg-white rounded-xl shadow overflow-hidden">
                    <div class="md:flex">
                        <!-- Left: Profile Info -->
                        <div class="md:w-2/3 p-6">
                            <div class="flex items-center mb-6">
                                <div class="flex-shrink-0 h-20 w-20 bg-emerald-100 rounded-full flex items-center justify-center">
                                    <span class="text-emerald-600 font-bold text-3xl">
                                        <?php echo strtoupper(substr($member['full_name'], 0, 1)); ?>
                                    </span>
                                </div>
                                <div class="ml-6">
                                    <h2 class="text-2xl font-bold text-gray-900"><?php echo htmlspecialchars($member['full_name']); ?></h2>
                                    <p class="text-gray-600">Member Code: <?php echo htmlspecialchars($member['member_code'] ?? 'N/A'); ?></p>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <!-- Contact Information -->
                                <div>
                                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Contact Information</h3>
                                    <div class="space-y-3">
                                        <div class="flex items-center">
                                            <i class="fas fa-envelope text-gray-400 w-5 mr-3"></i>
                                            <div>
                                                <p class="text-sm text-gray-500">Email</p>
                                                <p class="text-gray-900"><?php echo htmlspecialchars($member['email']); ?></p>
                                            </div>
                                        </div>
                                        
                                        <div class="flex items-center">
                                            <i class="fas fa-phone text-gray-400 w-5 mr-3"></i>
                                            <div>
                                                <p class="text-sm text-gray-500">Phone</p>
                                                <p class="text-gray-900"><?php echo htmlspecialchars($member['phone_number'] ?? 'N/A'); ?></p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Membership Details -->
                                <div>
                                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Membership Details</h3>
                                    <div class="space-y-3">
                                        <div class="flex items-center">
                                            <i class="fas fa-calendar-day text-gray-400 w-5 mr-3"></i>
                                            <div>
                                                <p class="text-sm text-gray-500">Join Date</p>
                                                <p class="text-gray-900"><?php echo date('M d, Y', strtotime($member['join_date'])); ?></p>
                                            </div>
                                        </div>
                                        
                                        <div class="flex items-center">
                                            <i class="fas fa-user-clock text-gray-400 w-5 mr-3"></i>
                                            <div>
                                                <p class="text-sm text-gray-500">Account Created</p>
                                                <p class="text-gray-900"><?php echo date('M d, Y', strtotime($member['account_created'])); ?></p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Right: Financial Stats -->
                        <div class="md:w-1/3 bg-gray-50 p-6">
                            <h3 class="text-lg font-semibold text-gray-900 mb-6">Financial Summary</h3>
                            
                            <div class="space-y-6">
                                <!-- Current Balance -->
                                <div class="text-center p-4 bg-white rounded-lg shadow-sm border <?php echo $current_balance >= 0 ? 'border-emerald-200' : 'border-red-200'; ?>">
                                    <p class="text-sm text-gray-500">Current Balance</p>
                                    <p class="text-3xl font-bold <?php echo $current_balance >= 0 ? 'text-emerald-600' : 'text-red-600'; ?>">
                                        UGX <?php echo number_format($current_balance, 2); ?>
                                    </p>
                                    <p class="text-xs <?php echo $current_balance >= 0 ? 'text-emerald-600' : 'text-red-600'; ?> mt-1">
                                        <?php echo $current_balance >= 0 ? 'Positive balance' : 'Negative balance'; ?>
                                    </p>
                                </div>

                                <!-- Current Cycle Contributions -->
                                <div class="text-center p-4 bg-white rounded-lg shadow-sm">
                                    <p class="text-sm text-gray-500">Current Cycle</p>
                                    <p class="text-2xl font-bold text-blue-600">
                                        UGX <?php echo number_format($current_cycle_contributions, 2); ?>
                                    </p>
                                    <p class="text-xs text-gray-500 mt-1">
                                        Contributions in active cycle
                                    </p>
                                </div>

                                <!-- Total Contributions -->
                                <div class="text-center p-4 bg-white rounded-lg shadow-sm">
                                    <p class="text-sm text-gray-500">Total Contributions</p>
                                    <p class="text-2xl font-bold text-purple-600">
                                        UGX <?php echo number_format($total_contributions, 2); ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Current Cycle Assignment -->
                <?php if ($current_assignment): ?>
                <div class="mb-8 bg-white rounded-xl shadow p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-semibold text-gray-900">Current Cycle Assignment</h3>
                        <?php if ($current_assignment['payout_status'] == 'pending'): ?>
                            <a href="../cycles/record_payout.php?cycle_id=<?php echo $current_assignment['cycle_id']; ?>&member_id=<?php echo $member_id; ?>" 
                               class="bg-emerald-600 text-white px-4 py-2 rounded-lg hover:bg-emerald-700 text-sm">
                                <i class="fas fa-check-circle mr-2"></i>
                                Record Payout
                            </a>
                        <?php elseif ($current_assignment['payout_status'] == 'pending_verification'): ?>
                            <a href="../cycles/record_payout.php?cycle_id=<?php echo $current_assignment['cycle_id']; ?>&action=verify" 
                               class="bg-amber-600 text-white px-4 py-2 rounded-lg hover:bg-amber-700 text-sm">
                                <i class="fas fa-clipboard-check mr-2"></i>
                                Verify Payout
                            </a>
                        <?php endif; ?>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                        <div class="text-center p-4 bg-blue-50 rounded-lg">
                            <p class="text-sm text-gray-500">Cycle</p>
                            <p class="text-xl font-bold text-blue-600"><?php echo htmlspecialchars($current_assignment['cycle_name']); ?></p>
                        </div>
                        
                        <div class="text-center p-4 bg-emerald-50 rounded-lg">
                            <p class="text-sm text-gray-500">Random Number</p>
                            <p class="text-3xl font-bold text-emerald-600"><?php echo $current_assignment['random_number']; ?></p>
                            <p class="text-xs text-gray-500 mt-1">Position in queue</p>
                        </div>
                        
                        <div class="text-center p-4 <?php echo $current_assignment['payout_status'] == 'paid' ? 'bg-emerald-50' : 'bg-amber-50'; ?> rounded-lg">
                            <p class="text-sm text-gray-500">Payout Status</p>
                            <p class="text-xl font-bold <?php echo $current_assignment['payout_status'] == 'paid' ? 'text-emerald-600' : 'text-amber-600'; ?>">
                                <?php echo ucfirst($current_assignment['payout_status']); ?>
                            </p>
                            <?php if ($current_assignment['payout_date']): ?>
                                <p class="text-xs text-gray-500 mt-1">
                                    Paid on: <?php echo date('M d, Y', strtotime($current_assignment['payout_date'])); ?>
                                </p>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Expected Payout Card - UPDATED -->
                        <div class="text-center p-4 bg-purple-50 rounded-lg">
                            <p class="text-sm text-gray-500">Expected Payout</p>
                            <p class="text-xl font-bold text-purple-600">
                                UGX <?php echo number_format($expected_payout, 2); ?>
                            </p>
                            <p class="text-xs text-gray-500 mt-1">
                                <?php echo $payout_period_info; ?>
                            </p>
                        </div>
                    </div>
                    
                    <!-- Cycle Statistics -->
                    <div class="mt-6 pt-6 border-t">
                        <h4 class="text-sm font-medium text-gray-900 mb-4">Cycle Statistics</h4>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div class="text-center p-4 bg-gray-50 rounded-lg">
                                <p class="text-sm text-gray-500">Total Cycle Savings</p>
                                <p class="text-lg font-bold text-gray-900">
                                    UGX <?php echo isset($total_cycle_savings) ? number_format($total_cycle_savings, 2) : '0.00'; ?>
                                </p>
                                <p class="text-xs text-gray-500 mt-1">All members combined</p>
                            </div>
                            
                            <div class="text-center p-4 bg-gray-50 rounded-lg">
                                <p class="text-sm text-gray-500">Active Members</p>
                                <p class="text-lg font-bold text-gray-900">
                                    <?php echo isset($total_members_in_cycle) ? $total_members_in_cycle : '0'; ?>
                                </p>
                                <p class="text-xs text-gray-500 mt-1">In this cycle</p>
                            </div>
                            
                            <div class="text-center p-4 bg-gray-50 rounded-lg">
                                <p class="text-sm text-gray-500">Your Share</p>
                                <p class="text-lg font-bold text-gray-900">
                                    UGX <?php echo number_format($current_cycle_contributions, 2); ?>
                                </p>
                                <p class="text-xs text-gray-500 mt-1">Your contributions</p>
                            </div>
                        </div>
                        
                        <!-- Payout Formula Explanation -->
                        <div class="mt-4 p-4 bg-blue-50 rounded-lg">
                            <p class="text-sm font-medium text-blue-900 mb-2">How Expected Payout is Calculated:</p>
                            <p class="text-xs text-blue-700">
                                <span class="font-medium">Total Cycle Savings ÷ Number of Members = Expected Payout per Member</span><br>
                                <?php if (isset($total_cycle_savings) && isset($total_members_in_cycle) && $total_members_in_cycle > 0): ?>
                                    UGX <?php echo number_format($total_cycle_savings, 2); ?> ÷ <?php echo $total_members_in_cycle; ?> members = 
                                    UGX <?php echo number_format($expected_payout, 2); ?> per member
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                    
                    <!-- Payout Details (if paid) -->
                    <?php if ($current_assignment['payout_status'] == 'paid' && $current_assignment['payout_amount']): ?>
                    <div class="mt-6 pt-6 border-t">
                        <h4 class="text-sm font-medium text-gray-900 mb-4">Payout Details</h4>
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                            <div class="text-center p-4 bg-green-50 rounded-lg">
                                <p class="text-sm text-gray-500">Amount Paid</p>
                                <p class="text-2xl font-bold text-green-600">
                                    UGX <?php echo number_format($current_assignment['payout_amount'], 2); ?>
                                </p>
                            </div>
                            
                            <?php if ($current_assignment['payout_date']): ?>
                            <div class="text-center p-4 bg-green-50 rounded-lg">
                                <p class="text-sm text-gray-500">Paid On</p>
                                <p class="text-lg font-bold text-green-600">
                                    <?php echo date('M d, Y', strtotime($current_assignment['payout_date'])); ?>
                                </p>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($current_assignment['verified_at']): ?>
                            <div class="text-center p-4 bg-green-50 rounded-lg">
                                <p class="text-sm text-gray-500">Verified On</p>
                                <p class="text-lg font-bold text-green-600">
                                    <?php echo date('M d, Y', strtotime($current_assignment['verified_at'])); ?>
                                </p>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($current_assignment['verification_notes']): ?>
                            <div class="text-center p-4 bg-blue-50 rounded-lg">
                                <p class="text-sm text-gray-500">Verification Notes</p>
                                <p class="text-sm text-gray-700"><?php echo htmlspecialchars($current_assignment['verification_notes']); ?></p>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Payout Comparison -->
                        <?php 
                        $difference = $current_assignment['payout_amount'] - $expected_payout;
                        $difference_percentage = $expected_payout > 0 ? ($difference / $expected_payout) * 100 : 0;
                        ?>
                        <div class="mt-4 p-4 <?php echo $difference >= 0 ? 'bg-emerald-50' : 'bg-amber-50'; ?> rounded-lg">
                            <p class="text-sm font-medium <?php echo $difference >= 0 ? 'text-emerald-900' : 'text-amber-900'; ?> mb-2">
                                <?php echo $difference >= 0 ? '✓ Payout Matched Expected' : '⚠ Payout Different from Expected'; ?>
                            </p>
                            <p class="text-xs <?php echo $difference >= 0 ? 'text-emerald-700' : 'text-amber-700'; ?>">
                                Expected: UGX <?php echo number_format($expected_payout, 2); ?> | 
                                Actual: UGX <?php echo number_format($current_assignment['payout_amount'], 2); ?> | 
                                Difference: 
                                <span class="font-medium <?php echo $difference >= 0 ? 'text-emerald-600' : 'text-amber-600'; ?>">
                                    <?php echo $difference >= 0 ? '+' : ''; ?>UGX <?php echo number_format($difference, 2); ?>
                                    (<?php echo $difference >= 0 ? '+' : ''; ?><?php echo number_format($difference_percentage, 1); ?>%)
                                </span>
                            </p>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Tabs for Details -->
                <div class="mb-8">
                    <!-- Tab Headers -->
                    <div class="border-b border-gray-200">
                        <nav class="-mb-px flex space-x-8">
                            <button onclick="showTab('contributions')" 
                                    id="tab-contributions" 
                                    class="tab-button py-4 px-1 border-b-2 font-medium text-sm border-emerald-500 text-emerald-600">
                                <i class="fas fa-money-bill-wave mr-2"></i>
                                Contributions
                                <?php if (count($recent_contributions) > 0): ?>
                                    <span class="ml-2 px-2 py-1 text-xs bg-emerald-100 text-emerald-800 rounded-full">
                                        <?php echo count($recent_contributions); ?>
                                    </span>
                                <?php endif; ?>
                            </button>
                            <button onclick="showTab('payouts')" 
                                    id="tab-payouts" 
                                    class="tab-button py-4 px-1 border-b-2 font-medium text-sm border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300">
                                <i class="fas fa-gift mr-2"></i>
                                Payouts
                                <?php if (count($payout_history) > 0): ?>
                                    <span class="ml-2 px-2 py-1 text-xs bg-blue-100 text-blue-800 rounded-full">
                                        <?php echo count($payout_history); ?>
                                    </span>
                                <?php endif; ?>
                            </button>
                            <button onclick="showTab('notes')" 
                                    id="tab-notes" 
                                    class="tab-button py-4 px-1 border-b-2 font-medium text-sm border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300">
                                <i class="fas fa-sticky-note mr-2"></i>
                                Notes
                            </button>
                        </nav>
                    </div>

                    <!-- Tab Contents -->
                    <div class="mt-6">
                        <!-- Contributions Tab -->
                        <div id="content-contributions" class="tab-content">
                            <?php if (empty($recent_contributions)): ?>
                                <div class="text-center py-12 text-gray-500">
                                    <i class="fas fa-money-bill-wave text-4xl mb-4 text-gray-300"></i>
                                    <p>No contributions recorded yet</p>
                                    <?php if ($current_assignment): ?>
                                        <a href="../savings/record.php?member_id=<?php echo $member_id; ?>&cycle_id=<?php echo $current_assignment['cycle_id']; ?>" 
                                           class="mt-4 inline-block bg-emerald-600 text-white px-4 py-2 rounded-lg hover:bg-emerald-700">
                                            Record First Contribution
                                        </a>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="bg-white rounded-xl shadow overflow-hidden">
                                    <div class="overflow-x-auto">
                                        <table class="min-w-full divide-y divide-gray-200">
                                            <thead class="bg-gray-50">
                                                <tr>
                                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Amount</th>
                                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Cycle</th>
                                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Notes</th>
                                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Recorded</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-200">
                                                <?php foreach ($recent_contributions as $contribution): ?>
                                                <tr class="hover:bg-gray-50">
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                        <?php echo date('M d, Y', strtotime($contribution['contribution_date'])); ?>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-emerald-600">
                                                        UGX <?php echo number_format($contribution['amount'], 2); ?>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                        <?php echo $contribution['cycle_name'] ? htmlspecialchars($contribution['cycle_name']) : 'N/A'; ?>
                                                    </td>
                                                    <td class="px-6 py-4 text-sm text-gray-500">
                                                        <?php echo htmlspecialchars($contribution['notes'] ?? '-'); ?>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                        <?php echo date('M d, Y', strtotime($contribution['recorded_at'])); ?>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="px-6 py-4 border-t flex justify-between items-center">
                                        <div>
                                            <span class="text-sm text-gray-600">
                                                Showing <?php echo count($recent_contributions); ?> most recent contributions
                                            </span>
                                        </div>
                                        <a href="../savings/history.php?member_id=<?php echo $member_id; ?>" 
                                           class="text-emerald-600 hover:text-emerald-800 text-sm">
                                            View all contributions
                                            <i class="fas fa-arrow-right ml-1"></i>
                                        </a>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Payouts Tab -->
                        <div id="content-payouts" class="tab-content hidden">
                            <?php if (empty($payout_history)): ?>
                                <div class="text-center py-12 text-gray-500">
                                    <i class="fas fa-gift text-4xl mb-4 text-gray-300"></i>
                                    <p>No payouts recorded yet</p>
                                    <?php if ($current_assignment && $current_assignment['payout_status'] == 'pending'): ?>
                                        <a href="../cycles/record_payout.php?cycle_id=<?php echo $current_assignment['cycle_id']; ?>&member_id=<?php echo $member_id; ?>" 
                                           class="mt-4 inline-block bg-emerald-600 text-white px-4 py-2 rounded-lg hover:bg-emerald-700">
                                            Record First Payout
                                        </a>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="bg-white rounded-xl shadow overflow-hidden">
                                    <div class="overflow-x-auto">
                                        <table class="min-w-full divide-y divide-gray-200">
                                            <thead class="bg-gray-50">
                                                <tr>
                                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Amount</th>
                                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Cycle</th>
                                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Notes</th>
                                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Verified</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-200">
                                                <?php foreach ($payout_history as $payout): ?>
                                                <tr class="hover:bg-gray-50">
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                        <?php echo date('M d, Y', strtotime($payout['payout_date'])); ?>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-emerald-600">
                                                        UGX <?php echo number_format($payout['amount'], 2); ?>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                        <?php echo htmlspecialchars($payout['cycle_name']); ?>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap">
                                                        <?php if ($payout['status'] == 'paid'): ?>
                                                            <span class="px-2 py-1 text-xs bg-emerald-100 text-emerald-800 rounded-full">
                                                                Paid
                                                            </span>
                                                        <?php elseif ($payout['status'] == 'pending_verification'): ?>
                                                            <span class="px-2 py-1 text-xs bg-amber-100 text-amber-800 rounded-full">
                                                                Pending Verification
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="px-2 py-1 text-xs bg-gray-100 text-gray-800 rounded-full">
                                                                <?php echo ucfirst($payout['status']); ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="px-6 py-4 text-sm text-gray-500 max-w-xs truncate">
                                                        <?php echo htmlspecialchars($payout['notes'] ?? '-'); ?>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                        <?php if ($payout['verified_at']): ?>
                                                            <span class="text-xs text-emerald-600">
                                                                <i class="fas fa-check-circle"></i>
                                                                <?php echo date('M d, Y', strtotime($payout['verified_at'])); ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="text-xs text-gray-500">Not verified</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="px-6 py-4 border-t">
                                        <div class="flex justify-between items-center">
                                            <div>
                                                <span class="text-sm text-gray-600">
                                                    Total payouts: UGX <?php echo number_format($total_payouts, 2); ?>
                                                </span>
                                            </div>
                                            <div>
                                                <?php 
                                                $paid_count = array_reduce($payout_history, function($carry, $item) {
                                                    return $carry + ($item['status'] == 'paid' ? 1 : 0);
                                                }, 0);
                                                ?>
                                                <span class="text-sm text-gray-600">
                                                    Paid: <?php echo $paid_count; ?> | Pending: <?php echo count($payout_history) - $paid_count; ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Notes Tab -->
                        <div id="content-notes" class="tab-content hidden">
                            <div class="bg-white rounded-xl shadow p-6">
                                <form id="notesForm" method="POST" action="">
                                    <input type="hidden" name="action" value="save_notes">
                                    <div class="mb-4">
                                        <label class="block text-sm font-medium text-gray-700 mb-2">
                                            Administrative Notes
                                        </label>
                                        <textarea name="admin_notes" rows="6"
                                                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500"
                                                  placeholder="Add any administrative notes about this member..."><?php echo htmlspecialchars($member['admin_notes'] ?? ''); ?></textarea>
                                        <p class="text-xs text-gray-500 mt-1">
                                            These notes are only visible to administrators
                                        </p>
                                    </div>
                                    <button type="submit" 
                                            class="bg-emerald-600 text-white px-4 py-2 rounded-lg hover:bg-emerald-700">
                                        Save Notes
                                    </button>
                                </form>
                                
                                <!-- Quick Actions -->
                                <div class="mt-8 border-t pt-6">
                                    <h4 class="text-sm font-medium text-gray-900 mb-3">Quick Actions</h4>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                                        <a href="../savings/record.php?member_id=<?php echo $member_id; ?>" 
                                           class="bg-blue-100 text-blue-700 px-4 py-3 rounded-lg hover:bg-blue-200 text-center">
                                            <i class="fas fa-money-bill-wave mb-2"></i>
                                            <div class="font-medium">Record Contribution</div>
                                            <div class="text-xs text-blue-600 mt-1">Add new savings</div>
                                        </a>
                                        
                                        <?php if ($current_assignment): ?>
                                            <?php if ($current_assignment['payout_status'] == 'pending'): ?>
                                            <a href="../cycles/record_payout.php?cycle_id=<?php echo $current_assignment['cycle_id']; ?>&member_id=<?php echo $member_id; ?>" 
                                               class="bg-emerald-100 text-emerald-700 px-4 py-3 rounded-lg hover:bg-emerald-200 text-center">
                                                <i class="fas fa-check-circle mb-2"></i>
                                                <div class="font-medium">Record Payout</div>
                                                <div class="text-xs text-emerald-600 mt-1">Mark as paid</div>
                                            </a>
                                            <?php elseif ($current_assignment['payout_status'] == 'pending_verification'): ?>
                                            <a href="../cycles/record_payout.php?cycle_id=<?php echo $current_assignment['cycle_id']; ?>&action=verify" 
                                               class="bg-amber-100 text-amber-700 px-4 py-3 rounded-lg hover:bg-amber-200 text-center">
                                                <i class="fas fa-clipboard-check mb-2"></i>
                                                <div class="font-medium">Verify Payout</div>
                                                <div class="text-xs text-amber-600 mt-1">Approve payment</div>
                                            </a>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        
                                        <a href="edit.php?id=<?php echo $member_id; ?>" 
                                           class="bg-purple-100 text-purple-700 px-4 py-3 rounded-lg hover:bg-purple-200 text-center">
                                            <i class="fas fa-edit mb-2"></i>
                                            <div class="font-medium">Edit Member</div>
                                            <div class="text-xs text-purple-600 mt-1">Update details</div>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Danger Zone -->
                <div class="bg-red-50 border border-red-200 rounded-xl p-6">
                    <h3 class="text-lg font-semibold text-red-900 mb-2">Danger Zone</h3>
                    <p class="text-red-700 text-sm mb-4">
                        These actions are irreversible. Use with extreme caution.
                    </p>
                    <div class="space-y-4">
                        <button onclick="resetMemberPassword()" 
                                class="bg-red-600 text-white px-4 py-2 rounded-lg hover:bg-red-700 text-sm">
                            <i class="fas fa-key mr-2"></i>
                            Reset Member Password
                        </button>
                        
                        <?php if ($member['status'] == 'active'): ?>
                            <form method="POST" action="" class="inline">
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="status" value="inactive">
                                <button type="submit" 
                                        onclick="return confirm('Are you sure you want to deactivate this member?')"
                                        class="bg-red-600 text-white px-4 py-2 rounded-lg hover:bg-red-700 text-sm">
                                    <i class="fas fa-user-slash mr-2"></i>
                                    Deactivate Member
                                </button>
                            </form>
                        <?php else: ?>
                            <form method="POST" action="" class="inline">
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="status" value="active">
                                <button type="submit" 
                                        onclick="return confirm('Are you sure you want to activate this member?')"
                                        class="bg-emerald-600 text-white px-4 py-2 rounded-lg hover:bg-emerald-700 text-sm">
                                    <i class="fas fa-user-check mr-2"></i>
                                    Activate Member
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('active');
        }
        
        // Tab functionality
        function showTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.add('hidden');
            });
            
            // Remove active class from all tabs
            document.querySelectorAll('.tab-button').forEach(button => {
                button.classList.remove('border-emerald-500', 'text-emerald-600');
                button.classList.add('border-transparent', 'text-gray-500');
            });
            
            // Show selected tab content
            document.getElementById('content-' + tabName).classList.remove('hidden');
            
            // Activate selected tab button
            document.getElementById('tab-' + tabName).classList.remove('border-transparent', 'text-gray-500');
            document.getElementById('tab-' + tabName).classList.add('border-emerald-500', 'text-emerald-600');
        }
        
        // Password reset function
        function resetMemberPassword() {
            const newPassword = prompt('Enter new password for member (minimum 6 characters):');
            if (newPassword && newPassword.length >= 6) {
                if (confirm('Are you sure you want to reset the password? This cannot be undone.')) {
                    // Send AJAX request to reset password
                    fetch('../../api/members.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            action: 'reset_password',
                            member_id: <?php echo $member_id; ?>,
                            new_password: newPassword
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('Password reset successfully!');
                        } else {
                            alert('Error: ' + data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('An error occurred');
                    });
                }
            } else if (newPassword) {
                alert('Password must be at least 6 characters');
            }
        }
        
        // Initialize first tab
        document.addEventListener('DOMContentLoaded', function() {
            showTab('contributions');
        });
    </script>
</body>
</html>