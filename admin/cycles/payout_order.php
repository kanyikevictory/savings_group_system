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

// Get cycle assignments with member details
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

// Get total members in cycle
$total_members = count($assignments);

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

// Calculate expected payout per member
$expected_payout_per_member = 0;
if ($total_members > 0) {
    $expected_payout_per_member = $total_cycle_contributions / $total_members;
}

// Count different statuses
$paid_count = 0;
$pending_count = 0;
$pending_verification_count = 0;

foreach ($assignments as $assignment) {
    switch ($assignment['payout_status']) {
        case 'paid':
            $paid_count++;
            break;
        case 'pending':
            $pending_count++;
            break;
        case 'pending_verification':
            $pending_verification_count++;
            break;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payout Order - Savings Group System</title>
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
            <h1 class="text-lg font-semibold">Payout Order</h1>
        </header>

        <main class="p-6">
            <div class="max-w-6xl mx-auto">
                <div class="mb-8">
                    <div class="flex justify-between items-start">
                        <div>
                            <h1 class="text-2xl font-bold text-gray-900">Payout Order</h1>
                            <p class="text-gray-600">View and manage the payout sequence for this cycle</p>
                        </div>
                        <div class="flex space-x-3">
                            <a href="record_payout.php?cycle_id=<?php echo $cycle_id; ?>&action=verify" 
                               class="bg-amber-600 text-white px-4 py-2 rounded-lg hover:bg-amber-700 flex items-center">
                                <i class="fas fa-clipboard-check mr-2"></i>
                                Verify Payouts
                                <?php if ($pending_verification_count > 0): ?>
                                    <span class="ml-2 bg-white text-amber-600 text-xs font-bold px-2 py-1 rounded-full">
                                        <?php echo $pending_verification_count; ?>
                                    </span>
                                <?php endif; ?>
                            </a>
                            <a href="record_payout.php?cycle_id=<?php echo $cycle_id; ?>" 
                               class="bg-emerald-600 text-white px-4 py-2 rounded-lg hover:bg-emerald-700 flex items-center">
                                <i class="fas fa-money-check-alt mr-2"></i>
                                Record Payout
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Cycle Stats -->
                <div class="grid grid-cols-1 md:grid-cols-5 gap-6 mb-8">
                    <div class="bg-white rounded-xl shadow p-6">
                        <div class="flex items-center">
                            <div class="p-3 rounded-lg bg-blue-100 text-blue-600 mr-4">
                                <i class="fas fa-users text-2xl"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Total Members</p>
                                <p class="text-2xl font-bold"><?php echo $total_members; ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-xl shadow p-6">
                        <div class="flex items-center">
                            <div class="p-3 rounded-lg bg-emerald-100 text-emerald-600 mr-4">
                                <i class="fas fa-piggy-bank text-2xl"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Cycle Savings</p>
                                <p class="text-2xl font-bold">UGX <?php echo number_format($total_cycle_contributions, 2); ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-xl shadow p-6">
                        <div class="flex items-center">
                            <div class="p-3 rounded-lg bg-purple-100 text-purple-600 mr-4">
                                <i class="fas fa-money-bill-wave text-2xl"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Expected Payout</p>
                                <p class="text-lg font-bold">UGX <?php echo number_format($expected_payout_per_member, 2); ?></p>
                                <p class="text-xs text-gray-500">Per member</p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-xl shadow p-6">
                        <div class="flex items-center">
                            <div class="p-3 rounded-lg bg-amber-100 text-amber-600 mr-4">
                                <i class="fas fa-clock text-2xl"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Next to Receive</p>
                                <p class="text-lg font-bold"><?php echo $next_member ? $next_member['full_name'] : 'None'; ?></p>
                                <?php if ($next_member): ?>
                                    <p class="text-xs text-gray-500">Number: <?php echo $next_member['random_number']; ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-xl shadow p-6">
                        <div class="flex items-center">
                            <div class="p-3 rounded-lg bg-green-100 text-green-600 mr-4">
                                <i class="fas fa-percentage text-2xl"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Progress</p>
                                <p class="text-2xl font-bold">
                                    <?php echo round(($paid_count / max($total_members, 1)) * 100); ?>%
                                </p>
                                <p class="text-xs text-gray-500"><?php echo $paid_count; ?> of <?php echo $total_members; ?> paid</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Cycle Info -->
                <div class="mb-8 bg-white rounded-xl shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900"><?php echo htmlspecialchars($cycle['name']); ?></h2>
                            <p class="text-gray-600">
                                Start: <?php echo date('M d, Y', strtotime($cycle['start_date'])); ?>
                                <?php if ($cycle['expected_end_date']): ?>
                                    • Expected End: <?php echo date('M d, Y', strtotime($cycle['expected_end_date'])); ?>
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="flex items-center space-x-4">
                            <div class="flex space-x-2">
                                <span class="px-2 py-1 text-xs bg-emerald-100 text-emerald-800 rounded-full">
                                    Paid: <?php echo $paid_count; ?>
                                </span>
                                <span class="px-2 py-1 text-xs bg-amber-100 text-amber-800 rounded-full">
                                    Pending: <?php echo $pending_count; ?>
                                </span>
                                <?php if ($pending_verification_count > 0): ?>
                                    <span class="px-2 py-1 text-xs bg-orange-100 text-orange-800 rounded-full">
                                        Verify: <?php echo $pending_verification_count; ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <span class="px-3 py-1 bg-emerald-100 text-emerald-800 rounded-full text-sm font-medium">
                                <?php echo ucfirst($cycle['status']); ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Payout Order Table -->
                <div class="bg-white rounded-xl shadow overflow-hidden mb-8">
                    <div class="px-6 py-4 border-b">
                        <div class="flex justify-between items-center">
                            <div>
                                <h2 class="text-lg font-semibold text-gray-900">Payout Sequence</h2>
                                <p class="text-sm text-gray-600">Members are paid in ascending order of their random numbers</p>
                            </div>
                            <div class="text-sm text-gray-600">
                                Expected Payout: <span class="font-bold text-emerald-600">UGX <?php echo number_format($expected_payout_per_member, 2); ?></span> per member
                            </div>
                        </div>
                    </div>
                    
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Order</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Random No.</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Member</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Contributions</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Payout Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Amount</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php if (empty($assignments)): ?>
                                    <tr>
                                        <td colspan="8" class="px-6 py-12 text-center text-gray-500">
                                            <i class="fas fa-users-slash text-4xl mb-4 text-gray-300"></i>
                                            <p class="text-lg">No members assigned to this cycle</p>
                                            <p class="text-sm mt-2">Assign random numbers to get started</p>
                                            <a href="assign.php?cycle_id=<?php echo $cycle_id; ?>" class="mt-4 inline-block bg-emerald-600 text-white px-4 py-2 rounded-lg hover:bg-emerald-700">
                                                Assign Numbers
                                            </a>
                                        </td>
                                    </tr>
                                <?php else: 
                                    foreach ($assignments as $index => $assignment): 
                                        $is_next = $next_member && $assignment['id'] === $next_member['id'];
                                        $status_class = '';
                                        $status_text = ucfirst($assignment['payout_status']);
                                        
                                        switch ($assignment['payout_status']) {
                                            case 'paid':
                                                $status_class = 'bg-emerald-100 text-emerald-800';
                                                $status_text = 'Paid';
                                                break;
                                            case 'pending_verification':
                                                $status_class = 'bg-orange-100 text-orange-800';
                                                $status_text = 'Pending Verification';
                                                break;
                                            case 'pending':
                                                $status_class = 'bg-amber-100 text-amber-800';
                                                $status_text = 'Pending';
                                                break;
                                            default:
                                                $status_class = 'bg-gray-100 text-gray-800';
                                        }
                                ?>
                                    <tr class="<?php echo $is_next ? 'bg-amber-50' : 'hover:bg-gray-50'; ?>">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="text-lg font-bold text-gray-900">#<?php echo $index + 1; ?></div>
                                                <?php if ($is_next): ?>
                                                    <span class="ml-2 px-2 py-1 text-xs bg-amber-100 text-amber-800 rounded-full">
                                                        Next
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-center">
                                                <div class="text-2xl font-bold text-blue-600">
                                                    <?php echo $assignment['random_number']; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="flex items-center">
                                                <div class="flex-shrink-0 h-10 w-10 <?php echo $assignment['payout_status'] === 'paid' ? 'bg-emerald-100' : ($assignment['payout_status'] === 'pending_verification' ? 'bg-orange-100' : 'bg-blue-100'); ?> rounded-full flex items-center justify-center">
                                                    <span class="<?php echo $assignment['payout_status'] === 'paid' ? 'text-emerald-600' : ($assignment['payout_status'] === 'pending_verification' ? 'text-orange-600' : 'text-blue-600'); ?> font-semibold">
                                                        <?php echo strtoupper(substr($assignment['full_name'], 0, 1)); ?>
                                                    </span>
                                                </div>
                                                <div class="ml-4">
                                                    <div class="text-sm font-medium text-gray-900">
                                                        <?php echo htmlspecialchars($assignment['full_name']); ?>
                                                    </div>
                                                    <div class="text-sm text-gray-500">
                                                        <?php echo htmlspecialchars($assignment['email']); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900">
                                                UGX <?php echo number_format($assignment['cycle_contributions'], 2); ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="px-3 py-1 text-xs rounded-full <?php echo $status_class; ?>">
                                                <?php echo $status_text; ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            <?php echo $assignment['payout_date'] 
                                                ? date('M d, Y', strtotime($assignment['payout_date'])) 
                                                : '-'; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <?php if ($assignment['payout_amount']): ?>
                                                <span class="text-emerald-600">
                                                    UGX <?php echo number_format($assignment['payout_amount'], 2); ?>
                                                </span>
                                                <?php if ($expected_payout_per_member > 0): ?>
                                                    <?php 
                                                    $difference = $assignment['payout_amount'] - $expected_payout_per_member;
                                                    $difference_percentage = ($difference / $expected_payout_per_member) * 100;
                                                    ?>
                                                    <div class="text-xs <?php echo $difference >= 0 ? 'text-emerald-500' : 'text-amber-500'; ?>">
                                                        <?php echo $difference >= 0 ? '+' : ''; ?><?php echo number_format($difference_percentage, 1); ?>%
                                                    </div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-gray-500">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <?php if ($assignment['payout_status'] === 'pending'): ?>
                                                <a href="record_payout.php?cycle_id=<?php echo $cycle_id; ?>&member_id=<?php echo $assignment['member_id']; ?>" 
                                                   class="text-emerald-600 hover:text-emerald-900 mr-3">
                                                    <i class="fas fa-check-circle mr-1"></i>
                                                    Mark as Paid
                                                </a>
                                            <?php elseif ($assignment['payout_status'] === 'pending_verification'): ?>
                                                <a href="record_payout.php?cycle_id=<?php echo $cycle_id; ?>&member_id=<?php echo $assignment['member_id']; ?>&action=verify" 
                                                   class="text-orange-600 hover:text-orange-900 mr-3">
                                                    <i class="fas fa-clipboard-check mr-1"></i>
                                                    Verify
                                                </a>
                                            <?php else: ?>
                                                <span class="text-gray-400">
                                                    <i class="fas fa-check mr-1"></i>
                                                    Paid
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach;
                                endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Progress Visualization -->
                <div class="bg-white rounded-xl shadow p-6 mb-8">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Payout Progress</h3>
                    <div class="space-y-4">
                        <!-- Progress Bar -->
                        <div>
                            <div class="flex justify-between text-sm text-gray-600 mb-1">
                                <span>Cycle Completion</span>
                                <span><?php echo $paid_count; ?> of <?php echo $total_members; ?> members paid</span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-4">
                                <div class="bg-emerald-600 h-4 rounded-full" 
                                     style="width: <?php echo ($paid_count / max($total_members, 1)) * 100; ?>%">
                                </div>
                            </div>
                        </div>

                        <!-- Status Summary -->
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mt-6">
                            <div class="text-center p-4 bg-emerald-50 rounded-lg">
                                <p class="text-sm text-gray-500">Paid Members</p>
                                <p class="text-2xl font-bold text-emerald-600"><?php echo $paid_count; ?></p>
                                <p class="text-xs text-gray-500">Fully paid out</p>
                            </div>
                            
                            <div class="text-center p-4 bg-amber-50 rounded-lg">
                                <p class="text-sm text-gray-500">Pending</p>
                                <p class="text-2xl font-bold text-amber-600"><?php echo $pending_count; ?></p>
                                <p class="text-xs text-gray-500">Waiting for payout</p>
                            </div>
                            
                            <div class="text-center p-4 bg-orange-50 rounded-lg">
                                <p class="text-sm text-gray-500">Pending Verification</p>
                                <p class="text-2xl font-bold text-orange-600"><?php echo $pending_verification_count; ?></p>
                                <p class="text-xs text-gray-500">Needs verification</p>
                            </div>
                            
                            <div class="text-center p-4 bg-blue-50 rounded-lg">
                                <p class="text-sm text-gray-500">Expected Payout</p>
                                <p class="text-lg font-bold text-blue-600">UGX <?php echo number_format($expected_payout_per_member, 2); ?></p>
                                <p class="text-xs text-gray-500">Per member</p>
                            </div>
                        </div>

                        <!-- Status Legend -->
                        <div class="flex flex-wrap gap-4 mt-6">
                            <div class="flex items-center">
                                <div class="w-4 h-4 bg-amber-100 border border-amber-300 rounded mr-2"></div>
                                <span class="text-sm text-gray-700">Next to receive</span>
                            </div>
                            <div class="flex items-center">
                                <div class="w-4 h-4 bg-emerald-100 border border-emerald-300 rounded mr-2"></div>
                                <span class="text-sm text-gray-700">Paid</span>
                            </div>
                            <div class="flex items-center">
                                <div class="w-4 h-4 bg-amber-100 border border-amber-300 rounded mr-2"></div>
                                <span class="text-sm text-gray-700">Pending</span>
                            </div>
                            <div class="flex items-center">
                                <div class="w-4 h-4 bg-orange-100 border border-orange-300 rounded mr-2"></div>
                                <span class="text-sm text-gray-700">Pending Verification</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Financial Summary -->
                <div class="bg-white rounded-xl shadow p-6 mb-8">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Financial Summary</h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div class="text-center p-6 bg-blue-50 rounded-lg">
                            <p class="text-sm text-gray-500">Total Cycle Savings</p>
                            <p class="text-2xl font-bold text-blue-600">
                                UGX <?php echo number_format($total_cycle_contributions, 2); ?>
                            </p>
                            <p class="text-xs text-gray-500 mt-2">Total collected from all members</p>
                        </div>
                        
                        <div class="text-center p-6 bg-emerald-50 rounded-lg">
                            <p class="text-sm text-gray-500">Total Expected Payouts</p>
                            <p class="text-2xl font-bold text-emerald-600">
                                UGX <?php echo number_format($expected_payout_per_member * $total_members, 2); ?>
                            </p>
                            <p class="text-xs text-gray-500 mt-2">
                                <?php echo $total_members; ?> members × UGX <?php echo number_format($expected_payout_per_member, 2); ?>
                            </p>
                        </div>
                        
                        <div class="text-center p-6 bg-purple-50 rounded-lg">
                            <p class="text-sm text-gray-500">Remaining Balance</p>
                            <p class="text-2xl font-bold text-purple-600">
                                UGX <?php echo number_format($total_cycle_contributions - ($expected_payout_per_member * $paid_count), 2); ?>
                            </p>
                            <p class="text-xs text-gray-500 mt-2">After paying <?php echo $paid_count; ?> members</p>
                        </div>
                    </div>
                </div>

                <!-- Navigation -->
                <div class="flex justify-between">
                    <a href="index.php" class="text-emerald-600 hover:text-emerald-800">
                        <i class="fas fa-arrow-left mr-2"></i>
                        Back to Cycles
                    </a>
                    
                    <div class="flex space-x-4">
                        <?php if ($pending_verification_count > 0): ?>
                            <a href="record_payout.php?cycle_id=<?php echo $cycle_id; ?>&action=verify" 
                               class="bg-orange-600 text-white px-4 py-2 rounded-lg hover:bg-orange-700">
                                <i class="fas fa-clipboard-check mr-2"></i>
                                Verify Payouts (<?php echo $pending_verification_count; ?>)
                            </a>
                        <?php endif; ?>
                        
                        <a href="assign.php?cycle_id=<?php echo $cycle_id; ?>" 
                           class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700">
                            <i class="fas fa-random mr-2"></i>
                            Re-assign Numbers
                        </a>
                        <a href="record_payout.php?cycle_id=<?php echo $cycle_id; ?>" 
                           class="bg-emerald-600 text-white px-4 py-2 rounded-lg hover:bg-emerald-700">
                            <i class="fas fa-money-check-alt mr-2"></i>
                            Record Payout
                        </a>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('active');
        }
    </script>
</body>
</html>