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

// Get cycle assignments with member details
$stmt = $db->prepare("
    SELECT ca.*, u.full_name, u.email, u.phone_number, 
           m.member_code, m.join_date,
           (SELECT COALESCE(SUM(amount), 0) FROM contributions WHERE member_id = m.id AND cycle_id = :cycle_id) as cycle_contributions,
           (SELECT COUNT(*) FROM contributions WHERE member_id = m.id AND cycle_id = :cycle_id) as contribution_count
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

// Calculate expected payout per member (based on cycle settings)
$expected_payout_per_member = $cycle['contribution_amount'] ?? 0;

// Calculate expected total collections
$expected_total_collections = $expected_payout_per_member * $total_members;

// Calculate contribution progress
$contribution_progress = 0;
if ($expected_total_collections > 0) {
    $contribution_progress = ($total_cycle_contributions / $expected_total_collections) * 100;
}

// Count different statuses
$paid_count = 0;
$pending_count = 0;
$pending_verification_count = 0;
$total_expected_payouts = 0;

foreach ($assignments as $assignment) {
    switch ($assignment['payout_status']) {
        case 'paid':
            $paid_count++;
            $total_expected_payouts += $expected_payout_per_member;
            break;
        case 'pending':
            $pending_count++;
            break;
        case 'pending_verification':
            $pending_verification_count++;
            break;
    }
}

// Get upcoming payout dates
$upcoming_payouts = [];
$current_date = new DateTime();
$start_date = new DateTime($cycle['start_date']);

for ($i = 0; $i < $total_members; $i++) {
    $payout_date = clone $start_date;
    if ($cycle['payment_frequency'] == 'weekly') {
        $payout_date->modify('+' . $i . ' weeks');
    } else {
        $payout_date->modify('+' . $i . ' months');
    }
    
    if ($payout_date >= $current_date && isset($assignments[$i])) {
        $upcoming_payouts[] = [
            'date' => $payout_date->format('Y-m-d'),
            'member' => $assignments[$i]['full_name'],
            'random_number' => $assignments[$i]['random_number']
        ];
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
                            <div class="text-center">
                                <div class="text-2xl font-bold"><?php echo $total_members; ?></div>
                                <div class="text-xs opacity-90">Total Members</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Cycle Stats -->
                <!-- Cycle Stats -->
<div class="grid grid-cols-1 md:grid-cols-6 gap-6 mb-8">
    <div class="bg-white rounded-xl shadow p-6 flex items-start">
        <div class="flex-shrink-0 p-3 rounded-lg bg-blue-100 text-blue-600 mr-4">
            <i class="fas fa-users text-2xl"></i>
        </div>
        <div class="flex-1 min-w-0">
            <p class="text-sm text-gray-500 truncate">Total Members</p>
            <p class="text-2xl font-bold text-gray-900"><?php echo $total_members; ?></p>
        </div>
    </div>

    <div class="bg-white rounded-xl shadow p-6 flex items-start">
        <div class="flex-shrink-0 p-3 rounded-lg bg-purple-100 text-purple-600 mr-4">
            <i class="fas fa-coins text-2xl"></i>
        </div>
        <div class="flex-1 min-w-0">
            <p class="text-sm text-gray-500 truncate">Contribution</p>
            <p class="text-2xl font-bold text-gray-900">KSh <?php echo number_format($cycle['contribution_amount'], 2); ?></p>
            <p class="text-xs text-gray-500 mt-0.5">per <?php echo $cycle['payment_frequency']; ?></p>
        </div>
    </div>

    

    <div class="bg-white rounded-xl shadow p-6 flex items-start">
        <div class="flex-shrink-0 p-3 rounded-lg bg-amber-100 text-amber-600 mr-4">
            <i class="fas fa-money-bill-wave text-2xl"></i>
        </div>
        <div class="flex-1 min-w-0">
            <p class="text-sm text-gray-500 truncate">Expected Payout</p>
            <p class="text-2xl font-bold text-gray-900">KSh <?php echo number_format($expected_payout_per_member, 2); ?></p>
            <p class="text-xs text-gray-500 mt-0.5">Per member</p>
        </div>
    </div>

    <div class="bg-white rounded-xl shadow p-6 flex items-start">
        <div class="flex-shrink-0 p-3 rounded-lg bg-green-100 text-green-600 mr-4">
            <i class="fas fa-percentage text-2xl"></i>
        </div>
        <div class="flex-1 min-w-0">
            <p class="text-sm text-gray-500 truncate">Progress</p>
            <p class="text-2xl font-bold text-gray-900"><?php echo round($contribution_progress); ?>%</p>
            <p class="text-xs text-gray-500 mt-0.5">Contributions</p>
        </div>
    </div>

    <div class="bg-white rounded-xl shadow p-6 flex items-start">
        <div class="flex-shrink-0 p-3 rounded-lg bg-indigo-100 text-indigo-600 mr-4">
            <i class="fas fa-clock text-2xl"></i>
        </div>
        <div class="flex-1 min-w-0">
            <p class="text-sm text-gray-500 truncate">Next Payout</p>
            <?php if ($next_member): ?>
                <p class="text-xl font-bold text-gray-900 truncate" title="<?php echo htmlspecialchars($next_member['full_name']); ?>">
                    <?php echo htmlspecialchars($next_member['full_name']); ?>
                </p>
                <p class="text-xs text-gray-500 mt-0.5">#<?php echo $next_member['random_number']; ?></p>
            <?php else: ?>
                <p class="text-xl font-bold text-gray-900">None</p>
                <p class="text-xs text-gray-500 mt-0.5">&nbsp;</p>
            <?php endif; ?>
        </div>
    </div>
</div>

              <!-- Outstanding Contributions Card -->
<?php
// Calculate members with outstanding contributions (if not already calculated)
if (!isset($total_outstanding)) {
    $expected_payout_per_member = $cycle['contribution_amount'] ?? 0;
    $total_outstanding = 0;
    $fully_paid_members = 0;
    $partial_paid_members = 0;
    $zero_paid_members = 0;
    $outstanding_members = [];
    
    foreach ($assignments as $assignment) {
        $expected = $expected_payout_per_member;
        $paid = $assignment['cycle_contributions'];
        $outstanding = max(0, $expected - $paid);
        
        if ($outstanding > 0) {
            $outstanding_members[] = [
                'name' => $assignment['full_name'],
                'random_number' => $assignment['random_number'],
                'expected' => $expected,
                'paid' => $paid,
                'outstanding' => $outstanding,
                'percentage' => $expected > 0 ? ($paid / $expected) * 100 : 0,
                'member_id' => $assignment['member_id']
            ];
            $total_outstanding += $outstanding;
            
            if ($paid == 0) {
                $zero_paid_members++;
            } else {
                $partial_paid_members++;
            }
        } else {
            $fully_paid_members++;
        }
    }
    
    // Sort by highest outstanding first
    usort($outstanding_members, function($a, $b) {
        return $b['outstanding'] <=> $a['outstanding'];
    });
    
    $top_outstanding = array_slice($outstanding_members, 0, 5);
}
?>

<div class="bg-white rounded-xl shadow overflow-hidden mb-8 <?php echo $total_outstanding > 0 ? 'border-l-4 border-red-500' : 'border-l-4 border-emerald-500'; ?>">
    <div class="px-6 py-4 bg-gradient-to-r from-red-50 to-orange-50 border-b">
        <div class="flex justify-between items-center">
            <div>
                <h3 class="text-lg font-semibold text-gray-900 flex items-center">
                    <i class="fas fa-exclamation-triangle text-red-500 mr-2"></i>
                    Outstanding Contributions
                </h3>
                <p class="text-sm text-gray-600">Members who haven't paid their full expected amount</p>
            </div>
            <div class="text-right">
                <p class="text-2xl font-bold <?php echo $total_outstanding > 0 ? 'text-red-600' : 'text-emerald-600'; ?>">
                    KSh <?php echo number_format($total_outstanding, 2); ?>
                </p>
                <p class="text-xs text-gray-500">Total Outstanding</p>
            </div>
        </div>
    </div>

    <!-- Summary Stats -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 p-6 bg-gray-50 border-b">
        <div class="bg-white rounded-lg p-4 shadow-sm">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500">Total Members</p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo $total_members; ?></p>
                </div>
                <div class="w-10 h-10 bg-blue-100 rounded-full flex items-center justify-center">
                    <i class="fas fa-users text-blue-600"></i>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg p-4 shadow-sm">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500">Fully Paid</p>
                    <p class="text-2xl font-bold text-emerald-600"><?php echo $fully_paid_members; ?></p>
                </div>
                <div class="w-10 h-10 bg-emerald-100 rounded-full flex items-center justify-center">
                    <i class="fas fa-check-circle text-emerald-600"></i>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg p-4 shadow-sm">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500">Partial Paid</p>
                    <p class="text-2xl font-bold text-amber-600"><?php echo $partial_paid_members; ?></p>
                </div>
                <div class="w-10 h-10 bg-amber-100 rounded-full flex items-center justify-center">
                    <i class="fas fa-exclamation-circle text-amber-600"></i>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg p-4 shadow-sm">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500">Not Paid</p>
                    <p class="text-2xl font-bold text-red-600"><?php echo $zero_paid_members; ?></p>
                </div>
                <div class="w-10 h-10 bg-red-100 rounded-full flex items-center justify-center">
                    <i class="fas fa-times-circle text-red-600"></i>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($top_outstanding)): ?>
    <!-- Top Outstanding Members -->
    <div class="p-6">
        <h4 class="text-sm font-medium text-gray-700 mb-4">Members with Highest Outstanding Balances</h4>
        <div class="space-y-4">
            <?php foreach ($top_outstanding as $member): ?>
            <div class="flex items-center justify-between p-4 bg-white border border-gray-200 rounded-lg hover:shadow-md transition-shadow">
                <div class="flex items-center flex-1">
                    <div class="flex-shrink-0 w-10 h-10 <?php echo $member['percentage'] == 0 ? 'bg-red-100' : 'bg-amber-100'; ?> rounded-full flex items-center justify-center">
                        <span class="<?php echo $member['percentage'] == 0 ? 'text-red-600' : 'text-amber-600'; ?> font-bold">
                            #<?php echo $member['random_number']; ?>
                        </span>
                    </div>
                    <div class="ml-4 flex-1">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($member['name']); ?></p>
                                <p class="text-xs text-gray-500">
                                    Paid: KSh <?php echo number_format($member['paid'], 2); ?> 
                                    of KSh <?php echo number_format($member['expected'], 2); ?>
                                </p>
                            </div>
                            <div class="text-right">
                                <p class="text-sm font-bold text-red-600">KSh <?php echo number_format($member['outstanding'], 2); ?></p>
                                <p class="text-xs text-gray-500">Outstanding</p>
                            </div>
                        </div>
                        
                        <!-- Progress Bar -->
                        <div class="mt-2">
                            <div class="flex justify-between text-xs text-gray-600 mb-1">
                                <span>Progress</span>
                                <span><?php echo round($member['percentage']); ?>%</span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-2">
                                <div class="<?php echo $member['percentage'] >= 100 ? 'bg-emerald-600' : ($member['percentage'] >= 50 ? 'bg-amber-600' : 'bg-red-600'); ?> h-2 rounded-full" 
                                     style="width: <?php echo min($member['percentage'], 100); ?>%">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Action Buttons -->
                <div class="ml-4 flex space-x-2">
                    <a href="record_contribution.php?cycle_id=<?php echo $cycle_id; ?>&member_id=<?php echo $member['member_id']; ?>" 
                       class="px-3 py-1 bg-emerald-100 text-emerald-700 rounded-lg hover:bg-emerald-200 text-sm">
                        <i class="fas fa-plus-circle mr-1"></i>
                        Record Payment
                    </a>
                    <button onclick="sendReminder(<?php echo $cycle_id; ?>, <?php echo $member['member_id']; ?>, '<?php echo addslashes($member['name']); ?>')"
                            class="px-3 py-1 bg-blue-100 text-blue-700 rounded-lg hover:bg-blue-200 text-sm">
                        <i class="fas fa-bell mr-1"></i>
                        Remind
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- View All Link -->
        <?php if (count($outstanding_members) > 5): ?>
        <div class="mt-4 text-center">
            <a href="outstanding_members.php?cycle_id=<?php echo $cycle_id; ?>" 
               class="text-sm text-emerald-600 hover:text-emerald-800 font-medium">
                View all <?php echo count($outstanding_members); ?> members with outstanding balances
                <i class="fas fa-arrow-right ml-1"></i>
            </a>
        </div>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <!-- No Outstanding Members -->
    <div class="p-12 text-center">
        <div class="inline-flex items-center justify-center w-16 h-16 bg-emerald-100 rounded-full mb-4">
            <i class="fas fa-check-circle text-3xl text-emerald-600"></i>
        </div>
        <h4 class="text-lg font-medium text-gray-900 mb-2">All Caught Up! 🎉</h4>
        <p class="text-gray-600">All members have paid their expected contributions in full.</p>
    </div>
    <?php endif; ?>
</div>

<!-- Quick Actions for Outstanding Balances -->
<?php if ($total_outstanding > 0): ?>
<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
    <div class="bg-gradient-to-r from-blue-500 to-blue-600 rounded-xl shadow p-6 text-white">
        <div class="flex items-center justify-between mb-4">
            <i class="fas fa-envelope text-3xl opacity-80"></i>
            <span class="text-sm font-medium bg-white bg-opacity-20 px-3 py-1 rounded-full">Bulk Action</span>
        </div>
        <h4 class="text-lg font-semibold mb-1">Send Reminders</h4>
        <p class="text-sm opacity-90 mb-4">Send payment reminders to all members with outstanding balances</p>
        <button onclick="sendBulkReminders(<?php echo $cycle_id; ?>)" 
                class="inline-flex items-center text-sm font-medium bg-white text-blue-600 px-4 py-2 rounded-lg hover:bg-opacity-90">
            <i class="fas fa-paper-plane mr-2"></i>
            Send to <?php echo count($outstanding_members); ?> Members
        </button>
    </div>

    <div class="bg-gradient-to-r from-amber-500 to-amber-600 rounded-xl shadow p-6 text-white">
        <div class="flex items-center justify-between mb-4">
            <i class="fas fa-file-export text-3xl opacity-80"></i>
            <span class="text-sm font-medium bg-white bg-opacity-20 px-3 py-1 rounded-full">Export</span>
        </div>
        <h4 class="text-lg font-semibold mb-1">Export Report</h4>
        <p class="text-sm opacity-90 mb-4">Download list of members with outstanding payments</p>
        <a href="export_outstanding.php?cycle_id=<?php echo $cycle_id; ?>" 
           class="inline-flex items-center text-sm font-medium bg-white text-amber-600 px-4 py-2 rounded-lg hover:bg-opacity-90">
            <i class="fas fa-download mr-2"></i>
            Export CSV
        </a>
    </div>

    <div class="bg-gradient-to-r from-purple-500 to-purple-600 rounded-xl shadow p-6 text-white">
        <div class="flex items-center justify-between mb-4">
            <i class="fas fa-chart-line text-3xl opacity-80"></i>
            <span class="text-sm font-medium bg-white bg-opacity-20 px-3 py-1 rounded-full">Analytics</span>
        </div>
        <h4 class="text-lg font-semibold mb-1">Payment Analytics</h4>
        <p class="text-sm opacity-90 mb-4">View detailed payment trends and forecasts</p>
        <a href="payment_analytics.php?cycle_id=<?php echo $cycle_id; ?>" 
           class="inline-flex items-center text-sm font-medium bg-white text-purple-600 px-4 py-2 rounded-lg hover:bg-opacity-90">
            <i class="fas fa-chart-bar mr-2"></i>
            View Analytics
        </a>
    </div>
</div>
<?php endif; ?>

                <!-- 
                Contribution Progress Bar -->
                <div class="bg-white rounded-xl shadow p-6 mb-8">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-semibold text-gray-900">Contribution Progress</h3>
                        <div class="text-sm text-gray-600">
                            <span class="font-medium">KSh <?php echo number_format($total_cycle_contributions, 2); ?></span> collected
                            of <span class="font-medium">KSh <?php echo number_format($expected_total_collections, 2); ?></span> expected
                        </div>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-4">
                        <div class="bg-emerald-600 h-4 rounded-full" style="width: <?php echo $contribution_progress; ?>%"></div>
                    </div>
                    
                    <!-- Expected vs Actual -->
                    <div class="grid grid-cols-2 gap-4 mt-4">
                        <div class="text-sm">
                            <span class="text-gray-500">Expected per member:</span>
                            <span class="ml-2 font-medium">KSh <?php echo number_format($expected_payout_per_member, 2); ?></span>
                        </div>
                        <div class="text-sm">
                            <span class="text-gray-500">Average collected:</span>
                            <span class="ml-2 font-medium">
                                KSh <?php echo number_format($total_members > 0 ? $total_cycle_contributions / $total_members : 0, 2); ?>
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
                            <div class="text-sm bg-blue-50 px-4 py-2 rounded-lg">
                                <span class="text-gray-600">Fixed Contribution: </span>
                                <span class="font-bold text-blue-600">KSh <?php echo number_format($cycle['contribution_amount'], 2); ?></span>
                                <span class="text-gray-500 text-xs ml-2">per <?php echo $cycle['payment_frequency']; ?></span>
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
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Expected</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Payout Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Amount Paid</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php if (empty($assignments)): ?>
                                    <tr>
                                        <td colspan="9" class="px-6 py-12 text-center text-gray-500">
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
                                        
                                        // Calculate contribution status
                                        $contribution_percentage = 0;
                                        if ($expected_payout_per_member > 0) {
                                            $contribution_percentage = ($assignment['cycle_contributions'] / $expected_payout_per_member) * 100;
                                        }
                                        
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
                                        <td class="px-6 py-4">
                                            <div>
                                                <div class="text-sm font-medium <?php echo $contribution_percentage >= 100 ? 'text-emerald-600' : ($contribution_percentage >= 50 ? 'text-amber-600' : 'text-red-600'); ?>">
                                                    KSh <?php echo number_format($assignment['cycle_contributions'], 2); ?>
                                                </div>
                                                <div class="flex items-center mt-1">
                                                    <div class="w-16 bg-gray-200 rounded-full h-1.5">
                                                        <div class="<?php echo $contribution_percentage >= 100 ? 'bg-emerald-600' : ($contribution_percentage >= 50 ? 'bg-amber-600' : 'bg-red-600'); ?> h-1.5 rounded-full" 
                                                             style="width: <?php echo min($contribution_percentage, 100); ?>%">
                                                        </div>
                                                    </div>
                                                    <span class="ml-2 text-xs text-gray-500">
                                                        <?php echo round($contribution_percentage); ?>%
                                                    </span>
                                                </div>
                                                <div class="text-xs text-gray-500">
                                                    <?php echo $assignment['contribution_count']; ?> payments
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm font-medium text-gray-900">
                                                KSh <?php echo number_format($expected_payout_per_member, 2); ?>
                                            </div>
                                            <div class="text-xs text-gray-500">
                                                <?php echo $cycle['payment_frequency']; ?>
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
                                                    KSh <?php echo number_format($assignment['payout_amount'], 2); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-gray-500">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <?php if ($assignment['payout_status'] === 'pending'): ?>
                                                <?php if ($contribution_percentage >= 100): ?>
                                                    <a href="record_payout.php?cycle_id=<?php echo $cycle_id; ?>&member_id=<?php echo $assignment['member_id']; ?>" 
                                                       class="text-emerald-600 hover:text-emerald-900 mr-3">
                                                        <i class="fas fa-check-circle mr-1"></i>
                                                        Mark as Paid
                                                    </a>
                                                <?php else: ?>
                                                    <span class="text-gray-400 cursor-not-allowed" title="Member hasn't completed contributions">
                                                        <i class="fas fa-ban mr-1"></i>
                                                        Insufficient
                                                    </span>
                                                <?php endif; ?>
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

                <!-- Upcoming Payout Schedule -->
                <?php if (!empty($upcoming_payouts)): ?>
                <div class="bg-white rounded-xl shadow p-6 mb-8">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Upcoming Payout Schedule</h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <?php foreach (array_slice($upcoming_payouts, 0, 3) as $payout): ?>
                        <div class="border border-gray-200 rounded-lg p-4">
                            <div class="flex justify-between items-start">
                                <div>
                                    <p class="text-sm text-gray-500">Payout Date</p>
                                    <p class="text-lg font-bold text-gray-900"><?php echo date('M d, Y', strtotime($payout['date'])); ?></p>
                                    <p class="text-sm text-gray-600 mt-2"><?php echo htmlspecialchars($payout['member']); ?></p>
                                </div>
                                <div class="bg-blue-100 rounded-full w-10 h-10 flex items-center justify-center">
                                    <span class="text-blue-600 font-bold">#<?php echo $payout['random_number']; ?></span>
                                </div>
                            </div>
                            <div class="mt-3 pt-3 border-t border-gray-100">
                                <div class="flex justify-between text-sm">
                                    <span class="text-gray-500">Amount:</span>
                                    <span class="font-medium">KSh <?php echo number_format($expected_payout_per_member, 2); ?></span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

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
                                <p class="text-xs text-gray-500">KSh <?php echo number_format($total_expected_payouts, 2); ?></p>
                            </div>
                            
                            <div class="text-center p-4 bg-amber-50 rounded-lg">
                                <p class="text-sm text-gray-500">Pending</p>
                                <p class="text-2xl font-bold text-amber-600"><?php echo $pending_count; ?></p>
                                <p class="text-xs text-gray-500">Awaiting payout</p>
                            </div>
                            
                            <div class="text-center p-4 bg-orange-50 rounded-lg">
                                <p class="text-sm text-gray-500">Need Verification</p>
                                <p class="text-2xl font-bold text-orange-600"><?php echo $pending_verification_count; ?></p>
                                <p class="text-xs text-gray-500">Pending approval</p>
                            </div>
                            
                            <div class="text-center p-4 bg-blue-50 rounded-lg">
                                <p class="text-sm text-gray-500">Fixed Contribution</p>
                                <p class="text-lg font-bold text-blue-600">KSh <?php echo number_format($cycle['contribution_amount'], 2); ?></p>
                                <p class="text-xs text-gray-500">per <?php echo $cycle['payment_frequency']; ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Financial Summary -->
                <div class="bg-white rounded-xl shadow p-6 mb-8">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Financial Summary</h3>
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                        <div class="text-center p-6 bg-blue-50 rounded-lg">
                            <p class="text-sm text-gray-500">Expected Collections</p>
                            <p class="text-2xl font-bold text-blue-600">
                                KSh <?php echo number_format($expected_total_collections, 2); ?>
                            </p>
                            <p class="text-xs text-gray-500 mt-2"><?php echo $total_members; ?> members × KSh <?php echo number_format($cycle['contribution_amount'], 2); ?></p>
                        </div>
                        
                        <div class="text-center p-6 bg-emerald-50 rounded-lg">
                            <p class="text-sm text-gray-500">Actual Collections</p>
                            <p class="text-2xl font-bold text-emerald-600">
                                KSh <?php echo number_format($total_cycle_contributions, 2); ?>
                            </p>
                            <p class="text-xs text-gray-500 mt-2"><?php echo round($contribution_progress); ?>% of expected</p>
                        </div>
                        
                        <div class="text-center p-6 bg-purple-50 rounded-lg">
                            <p class="text-sm text-gray-500">Total Payouts Made</p>
                            <p class="text-2xl font-bold text-purple-600">
                                KSh <?php echo number_format($total_expected_payouts, 2); ?>
                            </p>
                            <p class="text-xs text-gray-500 mt-2"><?php echo $paid_count; ?> members paid</p>
                        </div>
                        
                        <div class="text-center p-6 bg-amber-50 rounded-lg">
                            <p class="text-sm text-gray-500">Remaining Balance</p>
                            <p class="text-2xl font-bold text-amber-600">
                                KSh <?php echo number_format($total_cycle_contributions - $total_expected_payouts, 2); ?>
                            </p>
                            <p class="text-xs text-gray-500 mt-2">Available for next payouts</p>
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
        function sendReminder(cycleId, memberId, memberName) {
    if (confirm(`Send payment reminder to ${memberName}?`)) {
        // You can implement AJAX call here or redirect to a reminder page
        window.location.href = `send_reminder.php?cycle_id=${cycleId}&member_id=${memberId}`;
    }
}

function sendBulkReminders(cycleId) {
    if (confirm(`Send payment reminders to all ${<?php echo count($outstanding_members); ?>} members with outstanding balances?`)) {
        window.location.href = `send_bulk_reminders.php?cycle_id=${cycleId}`;
    }
}
    </script>
</body>
</html>