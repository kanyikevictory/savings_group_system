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

// Get current cycle assignment - FIXED QUERY
$stmt = $db->prepare("
    SELECT ca.*, c.name as cycle_name 
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
}

// Get recent contributions
$stmt = $db->prepare("
    SELECT c.* 
    FROM contributions c 
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
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                <!-- Total Balance Card -->
                <div class="bg-white rounded-xl shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-lg bg-emerald-100 text-emerald-600 mr-4">
                            <i class="fas fa-wallet text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Your Total Balance</p>
                            <p class="text-2xl font-bold text-emerald-600">
                                UGX <?php echo number_format($balance, 2); ?>
                            </p>
                            <p class="text-xs text-gray-500 mt-1">
                                Contributions: UGX <?php echo number_format($total_contributions, 2); ?> • 
                                Payouts: UGX <?php echo number_format($total_payouts, 2); ?>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Cycle Assignment Card -->
                <div class="bg-white rounded-xl shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-lg bg-blue-100 text-blue-600 mr-4">
                            <i class="fas fa-sync-alt text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Current Cycle</p>
                            <?php if ($assignment && isset($assignment['cycle_name'])): ?>
                                <p class="text-lg font-bold"><?php echo $assignment['cycle_name']; ?></p>
                                <p class="text-sm text-gray-500 mt-1">
                                    Number: <?php echo $assignment['random_number'] ?? 'N/A'; ?> • 
                                    Position: <?php echo $position ?? 'N/A'; ?> • 
                                    Status: <span class="<?php echo ($assignment['payout_status'] ?? '') == 'paid' ? 'text-emerald-600' : 'text-amber-600'; ?>">
                                        <?php echo ucfirst($assignment['payout_status'] ?? 'pending'); ?>
                                    </span>
                                </p>
                            <?php else: ?>
                                <p class="text-lg font-bold text-gray-500">No active cycle</p>
                                <p class="text-sm text-gray-500 mt-1">You are not assigned to any active cycle</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Member Info Card -->
                <div class="bg-white rounded-xl shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-lg bg-purple-100 text-purple-600 mr-4">
                            <i class="fas fa-user text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Member Since</p>
                            <p class="text-lg font-bold"><?php echo date('M d, Y', strtotime($member['join_date'] ?? date('Y-m-d'))); ?></p>
                            <p class="text-xs text-gray-500 mt-1">Status: 
                                <span class="<?php echo ($member['status'] ?? '') == 'active' ? 'text-emerald-600' : 'text-red-600'; ?>">
                                    <?php echo ucfirst($member['status'] ?? 'active'); ?>
                                </span>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Contributions -->
            <div class="bg-white rounded-xl shadow">
                <div class="px-6 py-4 border-b">
                    <h2 class="text-lg font-semibold text-gray-900">Recent Contributions</h2>
                    <a href="history.php" class="text-sm text-emerald-600 hover:text-emerald-800 float-right">
                        View All History
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
                                        <?php echo $contribution['cycle_id'] ? 'Cycle #' . $contribution['cycle_id'] : 'N/A'; ?>
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
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Payout Information -->
            <?php if ($assignment && isset($assignment['payout_status'])): ?>
            <div class="mt-8 bg-white rounded-xl shadow">
                <div class="px-6 py-4 border-b">
                    <h2 class="text-lg font-semibold text-gray-900">Payout Information</h2>
                </div>
                <div class="p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-500">Your Payout Status</p>
                            <p class="text-xl font-bold <?php echo $assignment['payout_status'] == 'paid' ? 'text-emerald-600' : 'text-amber-600'; ?>">
                                <?php echo ucfirst($assignment['payout_status']); ?>
                            </p>
                            <?php if ($assignment['payout_status'] == 'paid' && isset($assignment['payout_date'])): ?>
                                <p class="text-sm text-gray-500 mt-1">
                                    Paid on: <?php echo date('M d, Y', strtotime($assignment['payout_date'])); ?>
                                </p>
                            <?php endif; ?>
                        </div>
                        
                        <div class="text-center">
                            <p class="text-sm text-gray-500">Your Position in Queue</p>
                            <p class="text-3xl font-bold text-blue-600"><?php echo $position ?? 'N/A'; ?></p>
                            <p class="text-sm text-gray-500">out of <?php echo $assignment['total_members'] ?? '?'; ?></p>
                        </div>
                        
                        <div class="text-center">
                            <p class="text-sm text-gray-500">Your Random Number</p>
                            <?php if (isset($assignment['random_number'])): ?>
                            <div class="text-3xl font-bold bg-blue-100 text-blue-600 rounded-full w-16 h-16 flex items-center justify-center mx-auto">
                                <?php echo $assignment['random_number']; ?>
                            </div>
                            <?php else: ?>
                            <div class="text-3xl font-bold bg-gray-100 text-gray-400 rounded-full w-16 h-16 flex items-center justify-center mx-auto">
                                -
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <?php if (isset($assignment['payout_status']) && $assignment['payout_status'] == 'pending'): ?>
                        <div class="mt-6 p-4 bg-amber-50 border border-amber-200 rounded-lg">
                            <div class="flex items-center">
                                <i class="fas fa-info-circle text-amber-600 mr-3"></i>
                                <div>
                                    <p class="text-sm text-amber-800">
                                        You are position <?php echo $position ?? 'N/A'; ?> in the payout queue. 
                                        The system ensures fair and transparent rotation based on randomly assigned numbers.
                                    </p>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
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
                // On desktop, ensure sidebar is visible and overlay is hidden
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
                document.body.style.overflow = '';
            } else {
                // On mobile, ensure sidebar is hidden by default
                if (sidebar.classList.contains('active')) {
                    overlay.classList.add('active');
                }
            }
        });
        
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', () => {
            if (window.innerWidth >= 769) {
                // On desktop, ensure sidebar is visible
                document.querySelector('.sidebar').classList.remove('active');
                document.getElementById('sidebarOverlay').classList.remove('active');
            }
        });
    </script>
</body>
</html>