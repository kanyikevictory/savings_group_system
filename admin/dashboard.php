<?php
session_start();
require_once '../config/constants.php';
require_once '../config/database.php';
requireAdmin();

$db = getDB();

// Get total group savings from ACTIVE cycles only
$stmt = $db->query("
    SELECT COALESCE(SUM(c.amount), 0) as total 
    FROM contributions c 
    JOIN cycles cy ON c.cycle_id = cy.id 
    WHERE cy.status = 'active'
");
$total_savings = $stmt->fetchColumn();

// Get total members
$stmt = $db->query("SELECT COUNT(*) as total FROM members WHERE status = 'active'");
$total_members = $stmt->fetchColumn();

// Get current active cycle
$stmt = $db->query("SELECT * FROM cycles WHERE status = 'active' LIMIT 1");
$current_cycle = $stmt->fetch(PDO::FETCH_ASSOC);

// Get next member to receive payout
$next_member = null;
if ($current_cycle) {
    $stmt = $db->prepare("
       SELECT m.*, u.full_name, ca.random_number 
        FROM cycle_assignments ca 
        JOIN members m ON ca.member_id = m.id 
        JOIN users u ON m.user_id = u.id  
        WHERE ca.cycle_id = :cycle_id 
        AND ca.payout_status = 'pending' 
        ORDER BY ca.random_number ASC 
        LIMIT 1
    ");
    $stmt->execute([':cycle_id' => $current_cycle['id']]);
    $next_member = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get recent contributions
$stmt = $db->query("
    SELECT c.*, u.full_name 
    FROM contributions c 
    JOIN members m ON c.member_id = m.id 
    JOIN users u ON m.user_id = u.id 
    ORDER BY c.recorded_at DESC 
    LIMIT 10
");
$recent_contributions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get members who received payouts (recent)
$stmt = $db->prepare("
    SELECT ca.*, u.full_name, m.member_code, cy.name as cycle_name
    FROM cycle_assignments ca
    JOIN members m ON ca.member_id = m.id
    JOIN users u ON m.user_id = u.id
    JOIN cycles cy ON ca.cycle_id = cy.id
    WHERE ca.payout_status = 'paid'
    ORDER BY ca.payout_date DESC 
    LIMIT 5
");
$stmt->execute();
$recent_payouts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get pending verification count
$stmt = $db->prepare("
    SELECT COUNT(*) as pending_count
    FROM cycle_assignments ca
    JOIN cycles cy ON ca.cycle_id = cy.id
    WHERE ca.payout_status = 'pending_verification'
    AND cy.status = 'active'
");
$stmt->execute();
$pending_verifications = $stmt->fetchColumn();

// Get paid members count for current cycle
$paid_members_count = 0;
if ($current_cycle) {
    $stmt = $db->prepare("
        SELECT COUNT(*) as paid_count
        FROM cycle_assignments 
        WHERE cycle_id = :cycle_id 
        AND payout_status = 'paid'
    ");
    $stmt->execute([':cycle_id' => $current_cycle['id']]);
    $paid_members_count = $stmt->fetchColumn();
}

// Get total paid out amount for current cycle
$total_paid_out = 0;
if ($current_cycle) {
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(payout_amount), 0) as total_paid
        FROM cycle_assignments 
        WHERE cycle_id = :cycle_id 
        AND payout_status = 'paid'
    ");
    $stmt->execute([':cycle_id' => $current_cycle['id']]);
    $total_paid_out = $stmt->fetchColumn();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Savings Group System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .sidebar { transition: all 0.3s; }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.active { transform: translateX(0); }
        }
        .stat-card { min-height: 120px; }
        .dashboard-grid { display: grid; grid-template-columns: repeat(12, 1fr); gap: 1.5rem; }
        @media (max-width: 1024px) {
            .dashboard-grid { grid-template-columns: repeat(1, 1fr); }
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Sidebar -->
    <div class="sidebar fixed inset-y-0 left-0 z-50 w-64 bg-white shadow-lg">
        <div class="p-6">
            <h1 class="text-2xl font-bold text-emerald-600">Savings Group</h1>
            <p class="text-gray-600 text-sm mt-1">Digital Savings Book</p>
        </div>
        
        <nav class="mt-6">
            <div class="px-4 mb-4">
                <p class="text-xs font-semibold text-gray-500 uppercase">Main</p>
            </div>
            
            <a href="dashboard.php" class="flex items-center px-6 py-3 text-emerald-600 bg-emerald-50 border-r-4 border-emerald-500">
                <i class="fas fa-home mr-3"></i>
                Dashboard
            </a>
            
            <a href="members/index.php" class="flex items-center px-6 py-3 text-gray-700 hover:bg-gray-100">
                <i class="fas fa-users mr-3"></i>
                Members
            </a>
            
            <a href="savings/record.php" class="flex items-center px-6 py-3 text-gray-700 hover:bg-gray-100">
                <i class="fas fa-money-bill-wave mr-3"></i>
                Record Savings
            </a>
            
            <a href="savings/history.php" class="flex items-center px-6 py-3 text-gray-700 hover:bg-gray-100">
                <i class="fas fa-history mr-3"></i>
                Savings History
            </a>
            
            <div class="px-4 my-4">
                <p class="text-xs font-semibold text-gray-500 uppercase">Cycle Management</p>
            </div>
            
            <a href="cycles/index.php" class="flex items-center px-6 py-3 text-gray-700 hover:bg-gray-100">
                <i class="fas fa-sync-alt mr-3"></i>
                Cycles
            </a>
            
            <div class="absolute bottom-0 w-full p-6 border-t">
                <div class="flex items-center">
                    <div class="ml-3">
                        <p class="text-sm font-medium text-gray-900"><?php echo $_SESSION['full_name']; ?></p>
                        <p class="text-xs text-gray-500">Administrator</p>
                    </div>
                </div>
                <a href="../auth/logout.php" class="mt-4 flex items-center text-red-600 hover:text-red-800">
                    <i class="fas fa-sign-out-alt mr-2"></i>
                    Logout
                </a>
            </div>
        </nav>
    </div>

    <!-- Main Content -->
    <div class="ml-0 md:ml-64 min-h-screen">
        <!-- Mobile Header -->
        <header class="md:hidden bg-white shadow p-4 flex justify-between items-center">
            <button id="menuToggle" class="text-gray-700">
                <i class="fas fa-bars text-xl"></i>
            </button>
            <h1 class="text-lg font-semibold">Dashboard</h1>
        </header>

        <!-- Main Content Area -->
        <main class="p-6">
            <div class="mb-8">
                <h1 class="text-2xl font-bold text-gray-900">Dashboard</h1>
                <p class="text-gray-600">Welcome back, <?php echo $_SESSION['full_name']; ?></p>
            </div>

            <!-- Balanced Stats Grid -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <!-- Total Savings Card -->
                <div class="bg-white rounded-xl shadow p-6 stat-card">
                    <div class="flex items-start h-full">
                        <div class="p-3 rounded-lg bg-emerald-100 text-emerald-600 mr-4">
                            <i class="fas fa-piggy-bank text-2xl"></i>
                        </div>
                        <div class="flex-1">
                            <p class="text-sm text-gray-500 mb-2">Total Group Savings</p>
                            <p class="text-2xl font-bold text-gray-900">UGX <?php echo number_format($total_savings, 2); ?></p>
                            <?php if ($current_cycle): ?>
                                <p class="text-xs text-gray-500 mt-1">Active cycle: <?php echo $current_cycle['name']; ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Total Members Card -->
                <div class="bg-white rounded-xl shadow p-6 stat-card">
                    <div class="flex items-start h-full">
                        <div class="p-3 rounded-lg bg-blue-100 text-blue-600 mr-4">
                            <i class="fas fa-users text-2xl"></i>
                        </div>
                        <div class="flex-1">
                            <p class="text-sm text-gray-500 mb-2">Total Members</p>
                            <p class="text-2xl font-bold text-gray-900"><?php echo $total_members; ?></p>
                            <p class="text-xs text-gray-500 mt-1">Active members in group</p>
                        </div>
                    </div>
                </div>

                <!-- Paid Members Card -->
                <div class="bg-white rounded-xl shadow p-6 stat-card">
                    <div class="flex items-start h-full">
                        <div class="p-3 rounded-lg bg-green-100 text-green-600 mr-4">
                            <i class="fas fa-check-circle text-2xl"></i>
                        </div>
                        <div class="flex-1">
                            <p class="text-sm text-gray-500 mb-2">Paid Members</p>
                            <p class="text-2xl font-bold text-gray-900"><?php echo $paid_members_count; ?></p>
                            <?php if ($current_cycle && $total_members > 0): ?>
                                <p class="text-xs text-gray-500 mt-1">
                                    <?php echo round(($paid_members_count / $total_members) * 100); ?>% of cycle complete
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Next Payout Card -->
                <div class="bg-white rounded-xl shadow p-6 stat-card">
                    <div class="flex items-start h-full">
                        <div class="p-3 rounded-lg bg-amber-100 text-amber-600 mr-4">
                            <i class="fas fa-gift text-2xl"></i>
                        </div>
                        <div class="flex-1">
                            <p class="text-sm text-gray-500 mb-2">Next to Receive</p>
                            <p class="text-lg font-bold text-gray-900"><?php echo $next_member ? $next_member['full_name'] : 'None'; ?></p>
                            <?php if ($next_member): ?>
                                <p class="text-xs text-gray-500 mt-1">Random #<?php echo $next_member['random_number']; ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Additional Stats Row -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-6 mb-8">
                <!-- Current Cycle Card -->
                <div class="bg-white rounded-xl shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-lg bg-purple-100 text-purple-600 mr-4">
                            <i class="fas fa-sync-alt text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Current Cycle</p>
                            <p class="text-lg font-bold text-gray-900"><?php echo $current_cycle ? $current_cycle['name'] : 'No active cycle'; ?></p>
                            <?php if ($current_cycle): ?>
                                <p class="text-xs text-gray-500 mt-1">
                                    Started: <?php echo date('M d, Y', strtotime($current_cycle['start_date'])); ?>
                                    <?php if ($current_cycle['expected_end_date']): ?>
                                        <br>Expected end: <?php echo date('M d, Y', strtotime($current_cycle['expected_end_date'])); ?>
                                    <?php endif; ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Pending Verifications Card -->
                <div class="bg-white rounded-xl shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-lg bg-orange-100 text-orange-600 mr-4">
                            <i class="fas fa-clock text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Pending Verifications</p>
                            <p class="text-2xl font-bold text-gray-900"><?php echo $pending_verifications; ?></p>
                            <?php if ($pending_verifications > 0): ?>
                                <p class="text-xs text-orange-600 mt-1">Needs immediate attention</p>
                            <?php else: ?>
                                <p class="text-xs text-gray-500 mt-1">All payouts verified</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Total Paid Out Card -->
                <div class="bg-white rounded-xl shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-lg bg-green-100 text-green-600 mr-4">
                            <i class="fas fa-money-check-alt text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Total Paid Out</p>
                            <p class="text-2xl font-bold text-gray-900">UGX <?php echo number_format($total_paid_out, 2); ?></p>
                            <p class="text-xs text-gray-500 mt-1">This cycle</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Two Column Layout -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                <!-- Recent Contributions -->
                <div class="bg-white rounded-xl shadow h-full">
                    <div class="px-6 py-4 border-b flex justify-between items-center">
                        <h2 class="text-lg font-semibold text-gray-900">Recent Contributions</h2>
                        <a href="savings/history.php" class="text-emerald-600 hover:text-emerald-800 text-sm">
                            View All <i class="fas fa-arrow-right ml-1"></i>
                        </a>
                    </div>
                    <div class="overflow-x-auto">
                        <?php if (!empty($recent_contributions)): ?>
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Member</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Amount</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Notes</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    <?php foreach ($recent_contributions as $contribution): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            <?php echo date('M d, Y', strtotime($contribution['contribution_date'])); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            <?php echo htmlspecialchars($contribution['full_name']); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-emerald-600">
                                            UGX <?php echo number_format($contribution['amount'], 2); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 max-w-xs truncate">
                                            <?php echo htmlspecialchars($contribution['notes'] ?? '-'); ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="text-center py-12 text-gray-500">
                                <i class="fas fa-money-bill-wave text-4xl mb-4 text-gray-300"></i>
                                <p>No contributions recorded yet</p>
                                <p class="text-sm text-gray-400 mt-2">Start recording member contributions</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Payouts -->
                <div class="bg-white rounded-xl shadow h-full">
                    <div class="px-6 py-4 border-b flex justify-between items-center">
                        <h2 class="text-lg font-semibold text-gray-900">Recent Payouts</h2>
                        <?php if ($current_cycle): ?>
                            <a href="cycles/payout_order.php?cycle_id=<?php echo $current_cycle['id']; ?>" 
                               class="text-emerald-600 hover:text-emerald-800 text-sm">
                                View All <i class="fas fa-arrow-right ml-1"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                    <div class="overflow-x-auto">
                        <?php if (!empty($recent_payouts)): ?>
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Member</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Amount</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    <?php foreach ($recent_payouts as $payout): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4">
                                            <div class="flex items-center">
                                                <div class="flex-shrink-0 h-8 w-8 bg-green-100 rounded-full flex items-center justify-center">
                                                    <span class="text-green-600 font-semibold text-xs">
                                                        <?php echo strtoupper(substr($payout['full_name'], 0, 1)); ?>
                                                    </span>
                                                </div>
                                                <div class="ml-3">
                                                    <div class="text-sm font-medium text-gray-900">
                                                        <?php echo htmlspecialchars($payout['full_name']); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-green-600">
                                            UGX <?php echo number_format($payout['payout_amount'] ?? 0, 2); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            <?php echo $payout['payout_date'] ? date('M d, Y', strtotime($payout['payout_date'])) : '-'; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="px-2 py-1 text-xs bg-green-100 text-green-800 rounded-full">
                                                Paid
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="text-center py-12 text-gray-500">
                                <i class="fas fa-gift text-4xl mb-4 text-gray-300"></i>
                                <p>No payouts recorded yet</p>
                                <?php if ($current_cycle): ?>
                                    <p class="text-sm text-gray-400 mt-2">Start recording payouts for current cycle</p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($current_cycle && $total_paid_out > 0): ?>
                        <div class="px-6 py-4 border-t bg-gray-50">
                            <div class="flex justify-between items-center">
                                <span class="text-sm text-gray-600">Total paid out this cycle:</span>
                                <span class="text-lg font-bold text-emerald-600">
                                    UGX <?php echo number_format($total_paid_out, 2); ?>
                                </span>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Cycle Progress & Quick Actions -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Cycle Progress -->
                <?php if ($current_cycle && $total_members > 0): ?>
                <div class="bg-white rounded-xl shadow p-6 lg:col-span-1">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Cycle Progress</h3>
                    <div class="mb-4">
                        <div class="flex justify-between text-sm text-gray-600 mb-1">
                            <span>Completion</span>
                            <span><?php echo $paid_members_count; ?>/<?php echo $total_members; ?></span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-3">
                            <div class="bg-emerald-600 h-3 rounded-full" 
                                 style="width: <?php echo ($paid_members_count / max($total_members, 1)) * 100; ?>%">
                            </div>
                        </div>
                    </div>
                    <div class="space-y-2">
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-600">Remaining</span>
                            <span class="font-medium"><?php echo $total_members - $paid_members_count; ?> members</span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-600">Next payout</span>
                            <span class="font-medium"><?php echo $next_member ? $next_member['full_name'] : 'None'; ?></span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Quick Actions -->
                <div class="bg-white rounded-xl shadow p-6 lg:col-span-2">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Quick Actions</h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <a href="members/add.php" class="bg-emerald-50 border border-emerald-200 rounded-lg p-4 hover:bg-emerald-100 transition-colors">
                            <div class="flex flex-col items-center text-center">
                                <div class="p-2 rounded-lg bg-emerald-100 text-emerald-600 mb-3">
                                    <i class="fas fa-user-plus text-xl"></i>
                                </div>
                                <span class="font-medium text-gray-900">Add Member</span>
                                <p class="text-xs text-gray-600 mt-1">Register new member</p>
                            </div>
                        </a>

                        <a href="savings/record.php" class="bg-blue-50 border border-blue-200 rounded-lg p-4 hover:bg-blue-100 transition-colors">
                            <div class="flex flex-col items-center text-center">
                                <div class="p-2 rounded-lg bg-blue-100 text-blue-600 mb-3">
                                    <i class="fas fa-money-bill-wave text-xl"></i>
                                </div>
                                <span class="font-medium text-gray-900">Record Savings</span>
                                <p class="text-xs text-gray-600 mt-1">Add contributions</p>
                            </div>
                        </a>

                        <a href="cycles/record_payout.php<?php echo $current_cycle ? '?cycle_id=' . $current_cycle['id'] : ''; ?>" 
                           class="bg-purple-50 border border-purple-200 rounded-lg p-4 hover:bg-purple-100 transition-colors">
                            <div class="flex flex-col items-center text-center">
                                <div class="p-2 rounded-lg bg-purple-100 text-purple-600 mb-3">
                                    <i class="fas fa-hand-holding-usd text-xl"></i>
                                </div>
                                <span class="font-medium text-gray-900">Record Payout</span>
                                <p class="text-xs text-gray-600 mt-1">Manage payouts</p>
                            </div>
                        </a>
                    </div>
                    
                    <!-- Additional Actions -->
                    <div class="mt-6 pt-6 border-t">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <a href="cycles/index.php" class="flex items-center p-3 bg-gray-50 hover:bg-gray-100 rounded-lg">
                                <i class="fas fa-sync-alt text-gray-600 mr-3"></i>
                                <div>
                                    <span class="text-sm font-medium text-gray-900">Manage Cycles</span>
                                    <p class="text-xs text-gray-500">View all cycles</p>
                                </div>
                            </a>
                            
                            <?php if ($pending_verifications > 0): ?>
                                <a href="cycles/record_payout.php?action=verify" class="flex items-center p-3 bg-orange-50 hover:bg-orange-100 rounded-lg">
                                    <i class="fas fa-clipboard-check text-orange-600 mr-3"></i>
                                    <div>
                                        <span class="text-sm font-medium text-orange-900">Verify Payouts</span>
                                        <p class="text-xs text-orange-700"><?php echo $pending_verifications; ?> pending</p>
                                    </div>
                                </a>
                            <?php else: ?>
                                <a href="savings/history.php" class="flex items-center p-3 bg-gray-50 hover:bg-gray-100 rounded-lg">
                                    <i class="fas fa-history text-gray-600 mr-3"></i>
                                    <div>
                                        <span class="text-sm font-medium text-gray-900">View History</span>
                                        <p class="text-xs text-gray-500">All transactions</p>
                                    </div>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Mobile menu toggle
        document.getElementById('menuToggle')?.addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('active');
        });

        // Close menu when clicking outside on mobile
        document.addEventListener('click', function(event) {
            const sidebar = document.querySelector('.sidebar');
            const menuToggle = document.getElementById('menuToggle');
            
            if (window.innerWidth <= 768 && 
                !sidebar.contains(event.target) && 
                event.target !== menuToggle && 
                !menuToggle?.contains(event.target)) {
                sidebar.classList.remove('active');
            }
        });
    </script>
</body>
</html>