<?php
$page_title = "My Dashboard";
// REMOVE this line: require_once '../includes/header.php';
require_once '../config/constants.php';
require_once '../config/database.php';
requireMember();

$db = getDB();
$member_id = $_SESSION['member_id'];

// Initialize $assignment as empty array to avoid undefined variable errors
$assignment = [];
$position = null;

// Get member details
$stmt = $db->prepare("
    SELECT m.*, u.email, u.phone_number 
    FROM members m 
    JOIN users u ON m.user_id = u.id 
    WHERE m.id = :member_id
");
$stmt->execute([':member_id' => $member_id]);
$member = $stmt->fetch(PDO::FETCH_ASSOC);

// Get total contributions
$stmt = $db->prepare("
    SELECT COALESCE(SUM(amount), 0) as total_contributions 
    FROM contributions 
    WHERE member_id = :member_id
");
$stmt->execute([':member_id' => $member_id]);
$total_contributions = $stmt->fetchColumn();

// Get total payouts
$stmt = $db->prepare("
    SELECT COALESCE(SUM(amount), 0) as total_payouts 
    FROM payouts 
    WHERE member_id = :member_id
");
$stmt->execute([':member_id' => $member_id]);
$total_payouts = $stmt->fetchColumn();

$balance = $total_contributions - $total_payouts;

// Get current cycle assignment
$stmt = $db->prepare("
    SELECT ca.*, c.name as cycle_name, c.contribution_amount, c.payment_frequency, c.start_date, c.expected_end_date
    FROM cycle_assignments ca 
    JOIN cycles c ON ca.cycle_id = c.id 
    WHERE ca.member_id = :member_id 
    AND c.status = 'active' 
    LIMIT 1
");
$stmt->execute([':member_id' => $member_id]);
$assignment = $stmt->fetch(PDO::FETCH_ASSOC);

// Get payout position - ONLY if assignment exists
if ($assignment) {
    $stmt = $db->prepare("
        SELECT COUNT(*) as position 
        FROM cycle_assignments 
        WHERE cycle_id = :cycle_id 
        AND random_number < :random_number
    ");
    $stmt->execute([
        ':cycle_id' => $assignment['cycle_id'],
        ':random_number' => $assignment['random_number']
    ]);
    $position = $stmt->fetchColumn() + 1;
    
    // Get total members in cycle
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM cycle_assignments WHERE cycle_id = :cycle_id
    ");
    $stmt->execute([':cycle_id' => $assignment['cycle_id']]);
    $total_cycle_members = $stmt->fetchColumn();
    
    // Get current period contributions
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(amount), 0) as current_period_contributions 
        FROM contributions 
        WHERE member_id = :member_id AND cycle_id = :cycle_id
    ");
    $stmt->execute([
        ':member_id' => $member_id,
        ':cycle_id' => $assignment['cycle_id']
    ]);
    $current_cycle_contributions = $stmt->fetchColumn();
    
    // Calculate expected contributions based on cycle duration
    $start_date = new DateTime($assignment['start_date']);
    $current_date = new DateTime();
    
    // Calculate how many payments should have been made
    $expected_payments = 0;
    $next_payment_date = null;
    $payment_status = 'on_time';
    
    if ($assignment['payment_frequency'] == 'weekly') {
        $interval = new DateInterval('P1W');
        $payment_period = 'week';
    } else {
        $interval = new DateInterval('P1M');
        $payment_period = 'month';
    }
    
    // Calculate expected payments up to current date
    $period_start = clone $start_date;
    while ($period_start <= $current_date) {
        $expected_payments++;
        $period_start->add($interval);
    }
    
    // Store the next payment date
    $next_payment_date = clone $period_start;
    
    // Calculate expected amount based on position in queue
    // Members should have paid for all periods before their payout turn
    $expected_amount = $assignment['contribution_amount'] * $position;
    $outstanding_amount = max(0, $expected_amount - $current_cycle_contributions);
    
    // Calculate if member is behind on payments
    // Calculate if member is behind on payments
$payments_made = 0;
$payments_behind = 0;
$payment_status = 'on_time';
}
if ($assignment['contribution_amount'] > 0) {
    $payments_made = floor($current_cycle_contributions / $assignment['contribution_amount']);
    $payments_behind = $expected_payments - $payments_made;
    
    if ($payments_behind > 0) {
        $payment_status = 'behind';
    } elseif ($payments_made > $expected_payments) {
        $payment_status = 'ahead';
    }
} else {
    // Handle case where contribution amount is zero (shouldn't happen, but just in case)
    $payment_status = 'unknown';
}
    
// Get recent contributions
$stmt = $db->prepare("
    SELECT c.*, cy.name as cycle_name
    FROM contributions c 
    LEFT JOIN cycles cy ON c.cycle_id = cy.id
    WHERE c.member_id = :member_id 
    ORDER BY c.contribution_date DESC 
    LIMIT 5
");
$stmt->execute([':member_id' => $member_id]);
$recent_contributions = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Member Dashboard - Savings Group System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { 
            font-family: 'Inter', sans-serif;
            overflow-x: hidden;
        }
        .sidebar { 
            transition: transform 0.3s ease-in-out;
            z-index: 40;
        }
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 30;
        }
        .sidebar-overlay.active {
            display: block;
        }
        @media (max-width: 768px) {
            .sidebar { 
                transform: translateX(-100%); 
            }
            .sidebar.active { 
                transform: translateX(0); 
            }
        }
        @media (min-width: 769px) {
            .sidebar { 
                transform: translateX(0) !important; 
            }
            .sidebar-overlay {
                display: none !important;
            }
        }
        .progress-ring {
            transition: stroke-dashoffset 0.3s ease;
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Sidebar Overlay for mobile (click to close sidebar) -->
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

    <!-- Member Sidebar -->
    <div class="sidebar fixed inset-y-0 left-0 z-40 w-64 bg-white shadow-lg">
        <div class="p-6">
            <h1 class="text-2xl font-bold text-emerald-600">Savings Group</h1>
            <p class="text-gray-600 text-sm mt-1">Member Portal</p>
        </div>
        
        <nav class="mt-6">
            <a href="dashboard.php" class="flex items-center px-6 py-3 text-emerald-600 bg-emerald-50 border-r-4 border-emerald-500">
                <i class="fas fa-home mr-3"></i>
                Dashboard
            </a>
            
            <a href="history.php" class="flex items-center px-6 py-3 text-gray-700 hover:bg-gray-100">
                <i class="fas fa-history mr-3"></i>
                Savings History
            </a>
            
            <div class="absolute bottom-0 w-full p-6 border-t">
                <div class="flex items-center">
                    <div class="flex-shrink-0 h-10 w-10 bg-emerald-100 rounded-full flex items-center justify-center">
                        <span class="text-emerald-600 font-semibold">
                            <?php echo strtoupper(substr($member['full_name'] ?? 'M', 0, 1)); ?>
                        </span>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm font-medium text-gray-900"><?php echo $member['full_name'] ?? 'Member'; ?></p>
                        <p class="text-xs text-gray-500">Member</p>
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
            <button onclick="toggleSidebar()" class="text-gray-700 z-50">
                <i class="fas fa-bars text-xl"></i>
            </button>
            <h1 class="text-lg font-semibold">Dashboard</h1>
        </header>

        <main class="p-6">
            <div class="mb-8">
                <h1 class="text-2xl font-bold text-gray-900">Welcome, <?php echo $member['full_name'] ?? 'Member'; ?></h1>
                <p class="text-gray-600">View your savings, payout status, and contribution history</p>
            </div>

            <!-- Stats Grid -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                <!-- Total Balance Card -->
                <div class="bg-white rounded-xl shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-lg bg-emerald-100 text-emerald-600 mr-4">
                            <i class="fas fa-wallet text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Total Balance</p>
                            <p class="text-2xl font-bold text-emerald-600">
                                UGX <?php echo number_format($balance, 2); ?>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Total Deposited Card -->
                <div class="bg-white rounded-xl shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-lg bg-blue-100 text-blue-600 mr-4">
                            <i class="fas fa-piggy-bank text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Total Deposited</p>
                            <p class="text-2xl font-bold text-blue-600">
                                UGX <?php echo number_format($total_contributions, 2); ?>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Total Received Card -->
                <div class="bg-white rounded-xl shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-lg bg-purple-100 text-purple-600 mr-4">
                            <i class="fas fa-money-bill-wave text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Total Received</p>
                            <p class="text-2xl font-bold text-purple-600">
                                UGX <?php echo number_format($total_payouts, 2); ?>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Member Since Card -->
                <div class="bg-white rounded-xl shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-lg bg-amber-100 text-amber-600 mr-4">
                            <i class="fas fa-calendar-alt text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Member Since</p>
                            <p class="text-lg font-bold"><?php echo date('M d, Y', strtotime($member['join_date'] ?? date('Y-m-d'))); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Current Cycle Information -->
            <?php if ($assignment && isset($assignment['cycle_name'])): ?>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                <!-- Cycle Info Card -->
                <div class="lg:col-span-2 bg-white rounded-xl shadow overflow-hidden">
                    <div class="px-6 py-4 bg-gradient-to-r from-emerald-600 to-blue-600 text-white">
                        <h2 class="text-lg font-semibold">Current Cycle: <?php echo htmlspecialchars($assignment['cycle_name']); ?></h2>
                        <p class="text-sm opacity-90 mt-1">Track your contributions and payout status</p>
                    </div>
                    
                    <div class="p-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                            <div>
                                <p class="text-sm text-gray-500">Fixed Contribution</p>
                                <p class="text-2xl font-bold text-gray-900">UGX <?php echo number_format($assignment['contribution_amount'], 2); ?></p>
                                <p class="text-xs text-gray-500 mt-1">per <?php echo $assignment['payment_frequency']; ?></p>
                            </div>
                            
                            <div>
                                <p class="text-sm text-gray-500">Your Position</p>
                                <p class="text-2xl font-bold text-blue-600"><?php echo $position ?? 'N/A'; ?> of <?php echo $total_cycle_members ?? '?'; ?></p>
                                <p class="text-xs text-gray-500 mt-1">Random #<?php echo $assignment['random_number'] ?? 'N/A'; ?></p>
                            </div>
                        </div>
                        
                        <!-- Contribution Progress -->
<div class="mb-6">
    <div class="flex justify-between items-center mb-2">
        <p class="text-sm font-medium text-gray-700">Expected Contributions by Position</p>
        <p class="text-sm font-medium">
            <span class="text-emerald-600">UGX <?php echo number_format($current_cycle_contributions ?? 0, 2); ?></span>
            <span class="text-gray-400"> / </span>
            <span class="text-gray-900">UGX <?php echo number_format($expected_amount ?? 0, 2); ?></span>
        </p>
    </div>
    <div class="w-full bg-gray-200 rounded-full h-3">
        <?php 
        // Safely calculate percentage with division by zero check
        $progress_percentage = 0;
        if (isset($expected_amount) && $expected_amount > 0) {
            $progress_percentage = min(100, ($current_cycle_contributions / $expected_amount) * 100);
        }
        ?>
        <div class="bg-emerald-600 h-3 rounded-full" style="width: <?php echo $progress_percentage; ?>%"></div>
    </div>
    <p class="text-xs text-gray-500 mt-2">
        Based on your position (#<?php echo $position ?? 'N/A'; ?>), you should have contributed 
        UGX <?php echo number_format($expected_amount ?? 0, 2); ?> by now.
    </p>
</div>
                        
                        <!-- Outstanding Balance Alert -->
                        <?php if ($outstanding_amount > 0): ?>
                        <div class="bg-red-50 border border-red-200 rounded-lg p-4">
                            <div class="flex items-start">
                                <div class="flex-shrink-0">
                                    <i class="fas fa-exclamation-circle text-red-600 text-xl"></i>
                                </div>
                                <div class="ml-3 flex-1">
                                    <h3 class="text-sm font-medium text-red-800">Outstanding Balance</h3>
                                    <p class="text-sm text-red-700 mt-1">
                                        You have an outstanding balance of 
                                        <span class="font-bold">UGX <?php echo number_format($outstanding_amount, 2); ?></span>
                                        to meet your position requirements.
                                    </p>
                                    <?php if ($payments_behind > 0): ?>
                                    <p class="text-xs text-red-600 mt-1">
                                        You are <?php echo $payments_behind; ?> payment(s) behind schedule.
                                    </p>
                                    <?php endif; ?>
                                    <div class="mt-3">
                                        <a href="make_payment.php?cycle_id=<?php echo $assignment['cycle_id']; ?>" 
                                           class="inline-flex items-center px-4 py-2 bg-red-600 text-white text-sm rounded-lg hover:bg-red-700">
                                            <i class="fas fa-credit-card mr-2"></i>
                                            Make Payment Now
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php elseif ($current_cycle_contributions >= $expected_amount && $expected_amount > 0): ?>
                        <div class="bg-emerald-50 border border-emerald-200 rounded-lg p-4">
                            <div class="flex items-start">
                                <div class="flex-shrink-0">
                                    <i class="fas fa-check-circle text-emerald-600 text-xl"></i>
                                </div>
                                <div class="ml-3">
                                    <h3 class="text-sm font-medium text-emerald-800">All Caught Up!</h3>
                                    <p class="text-sm text-emerald-700 mt-1">
                                        You have met all contribution requirements for your position.
                                        You're ready for payout when it's your turn.
                                    </p>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Next Payment Due -->
                        <?php if ($next_payment_date && $outstanding_amount > 0): ?>
                        <div class="mt-4 bg-blue-50 border border-blue-200 rounded-lg p-4">
                            <div class="flex items-center">
                                <i class="fas fa-calendar-alt text-blue-600 mr-3"></i>
                                <div>
                                    <p class="text-sm text-blue-800">
                                        <span class="font-medium">Next payment due:</span> 
                                        <?php echo $next_payment_date->format('M d, Y'); ?>
                                        (<?php echo $assignment['payment_frequency']; ?>)
                                    </p>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Payout Status Card -->
                <div class="bg-white rounded-xl shadow overflow-hidden">
                    <div class="px-6 py-4 border-b">
                        <h3 class="text-lg font-semibold text-gray-900">Payout Status</h3>
                    </div>
                    
                    <div class="p-6">
                        <div class="text-center mb-6">
                            <div class="inline-flex items-center justify-center w-24 h-24 rounded-full 
                                <?php echo $assignment['payout_status'] == 'paid' ? 'bg-emerald-100' : 
                                    ($assignment['payout_status'] == 'pending_verification' ? 'bg-orange-100' : 'bg-amber-100'); ?> mb-4">
                                <i class="fas <?php echo $assignment['payout_status'] == 'paid' ? 'fa-check-circle' : 
                                    ($assignment['payout_status'] == 'pending_verification' ? 'fa-clock' : 'fa-hourglass-half'); ?> 
                                    text-4xl <?php echo $assignment['payout_status'] == 'paid' ? 'text-emerald-600' : 
                                    ($assignment['payout_status'] == 'pending_verification' ? 'text-orange-600' : 'text-amber-600'); ?>">
                                </i>
                            </div>
                            <h4 class="text-xl font-bold mb-1"><?php echo ucfirst($assignment['payout_status']); ?></h4>
                            <?php if ($assignment['payout_status'] == 'paid'): ?>
                                <p class="text-sm text-gray-600">
                                    Paid on: <?php echo date('M d, Y', strtotime($assignment['payout_date'])); ?>
                                </p>
                                <p class="text-lg font-bold text-emerald-600 mt-2">
                                    UGX <?php echo number_format($assignment['payout_amount'] ?? 0, 2); ?>
                                </p>
                            <?php elseif ($assignment['payout_status'] == 'pending_verification'): ?>
                                <p class="text-sm text-gray-600 mt-2">Awaiting admin verification</p>
                            <?php else: ?>
                                <p class="text-sm text-gray-600 mt-2">
                                    <?php echo $position ? $total_cycle_members - $position : '?'; ?> members before you
                                </p>
                                <div class="mt-4 bg-amber-50 rounded-lg p-3">
                                    <p class="text-xs text-amber-800">
                                        <i class="fas fa-info-circle mr-1"></i>
                                        Ensure all contributions are paid before your turn
                                    </p>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($assignment['payout_status'] != 'paid'): ?>
                        <?php if (isset($total_cycle_members) && $total_cycle_members > 0 && isset($position)): ?>
<div class="border-t pt-4">
    <div class="flex justify-between text-sm mb-1">
        <span class="text-gray-600">Queue Position</span>
        <span class="font-medium"><?php echo $position; ?> of <?php echo $total_cycle_members; ?></span>
    </div>
    <div class="w-full bg-gray-200 rounded-full h-2">
        <?php 
        $queue_progress = ($position / $total_cycle_members) * 100;
        ?>
        <div class="bg-blue-600 h-2 rounded-full" style="width: <?php echo $queue_progress; ?>%"></div>
    </div>
</div>
<?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <!-- No Active Cycle Card -->
            <div class="mb-8 bg-white rounded-xl shadow p-8 text-center">
                <i class="fas fa-sync-alt text-5xl text-gray-300 mb-4"></i>
                <h3 class="text-xl font-semibold text-gray-900 mb-2">No Active Cycle</h3>
                <p class="text-gray-600 mb-6">You are currently not assigned to any active cycle.</p>
                <a href="history.php" class="inline-flex items-center px-6 py-3 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700">
                    <i class="fas fa-history mr-2"></i>
                    View Savings History
                </a>
            </div>
            <?php endif; ?>

            <!-- Recent Contributions -->
            <div class="bg-white rounded-xl shadow">
                <div class="px-6 py-4 border-b flex justify-between items-center">
                    <h2 class="text-lg font-semibold text-gray-900">Recent Contributions</h2>
                    <a href="history.php" class="text-sm text-emerald-600 hover:text-emerald-800">
                        View All <i class="fas fa-arrow-right ml-1"></i>
                    </a>
                </div>
                <div class="overflow-x-auto">
                    <?php if (!empty($recent_contributions)): ?>
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Amount</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Cycle</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Notes</th>
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
                                        <?php echo htmlspecialchars($contribution['cycle_name'] ?? 'N/A'); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo htmlspecialchars($contribution['notes'] ?? '-'); ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="px-6 py-8 text-center text-gray-500">
                            <i class="fas fa-money-bill-wave text-4xl mb-4 text-gray-300"></i>
                            <p>No contributions recorded yet</p>
                            <?php if ($assignment): ?>
                            <a href="make_payment.php?cycle_id=<?php echo $assignment['cycle_id']; ?>" 
                               class="mt-4 inline-flex items-center px-4 py-2 bg-emerald-600 text-white text-sm rounded-lg hover:bg-emerald-700">
                                <i class="fas fa-plus-circle mr-2"></i>
                                Make First Payment
                            </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Toggle sidebar function
        function toggleSidebar() {
            const sidebar = document.querySelector('.sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            
            sidebar.classList.toggle('active');
            overlay.classList.toggle('active');
            
            // Prevent body scroll when sidebar is open on mobile
            if (window.innerWidth < 769) {
                if (sidebar.classList.contains('active')) {
                    document.body.style.overflow = 'hidden';
                } else {
                    document.body.style.overflow = '';
                }
            }
        }
        
        // Close sidebar when clicking on a link (on mobile)
        document.querySelectorAll('.sidebar a').forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth < 769) {
                    toggleSidebar();
                }
            });
        });
        
        // Close sidebar when pressing Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                const sidebar = document.querySelector('.sidebar');
                if (sidebar.classList.contains('active')) {
                    toggleSidebar();
                }
            }
        });
        
        // Handle window resize
        window.addEventListener('resize', () => {
            const sidebar = document.querySelector('.sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            
            if (window.innerWidth >= 769) {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
                document.body.style.overflow = '';
            } else {
                if (sidebar.classList.contains('active')) {
                    overlay.classList.add('active');
                }
            }
        });
        
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', () => {
            if (window.innerWidth >= 769) {
                document.querySelector('.sidebar').classList.remove('active');
                document.getElementById('sidebarOverlay').classList.remove('active');
            }
        });
    </script>
</body>
</html>