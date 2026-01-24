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

// Check if numbers are already assigned
$stmt = $db->prepare("SELECT COUNT(*) FROM cycle_assignments WHERE cycle_id = :cycle_id");
$stmt->execute([':cycle_id' => $cycle_id]);
$already_assigned = $stmt->fetchColumn() > 0;

// Get active members count
$stmt = $db->query("SELECT COUNT(*) FROM members WHERE status = 'active'");
$active_members_count = $stmt->fetchColumn();

// Handle assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$already_assigned) {
    try {
        $db->beginTransaction();
        
        // Get all active members
        $stmt = $db->query("SELECT id FROM members WHERE status = 'active' ORDER BY RAND()");
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
        
        $_SESSION['success'] = "Random numbers assigned successfully!";
        header("Location: payout_order.php?cycle_id=$cycle_id");
        exit();
    } catch (Exception $e) {
        $db->rollBack();
        $error = "Error assigning numbers: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assign Random Numbers - Savings Group System</title>
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
            <h1 class="text-lg font-semibold">Assign Numbers</h1>
        </header>

        <main class="p-6">
            <div class="max-w-4xl mx-auto">
                <div class="mb-8">
                    <h1 class="text-2xl font-bold text-gray-900">Assign Random Numbers</h1>
                    <p class="text-gray-600">Assign random numbers to determine payout order</p>
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

                <!-- Cycle Info Card -->
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
                        <span class="px-3 py-1 bg-emerald-100 text-emerald-800 rounded-full text-sm font-medium">
                            <?php echo ucfirst($cycle['status']); ?>
                        </span>
                    </div>
                </div>

                <!-- Assignment Status -->
                <div class="mb-8">
                    <?php if ($already_assigned): ?>
                        <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-6">
                            <div class="flex items-center">
                                <i class="fas fa-exclamation-triangle text-yellow-600 text-xl mr-3"></i>
                                <div>
                                    <h3 class="font-semibold text-yellow-900">Numbers Already Assigned</h3>
                                    <p class="text-yellow-800 mt-1">
                                        Random numbers have already been assigned for this cycle. 
                                        <a href="payout_order.php?cycle_id=<?php echo $cycle_id; ?>" class="font-medium underline">
                                            View payout order
                                        </a>
                                    </p>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <!-- Assignment Form -->
                        <div class="bg-white rounded-xl shadow">
                            <div class="p-6 border-b">
                                <h3 class="text-lg font-semibold text-gray-900">Assign Random Numbers</h3>
                            </div>
                            
                            <div class="p-6">
                                <div class="mb-6">
                                    <div class="flex items-center justify-between p-4 bg-blue-50 rounded-lg">
                                        <div>
                                            <p class="font-medium text-blue-900">Active Members Ready for Assignment</p>
                                            <p class="text-blue-700 text-sm mt-1">
                                                Total active members: <span class="font-bold"><?php echo $active_members_count; ?></span>
                                            </p>
                                        </div>
                                        <div class="text-3xl font-bold text-blue-600">
                                            <?php echo $active_members_count; ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- How it works -->
                                <div class="mb-8 bg-gray-50 border border-gray-200 rounded-lg p-4">
                                    <h4 class="font-medium text-gray-900 mb-2">How Random Assignment Works:</h4>
                                    <ul class="text-gray-700 space-y-2 text-sm">
                                        <li class="flex items-start">
                                            <i class="fas fa-random text-emerald-600 mt-1 mr-2"></i>
                                            <span>Each active member receives a unique random number (1 to <?php echo $active_members_count; ?>)</span>
                                        </li>
                                        <li class="flex items-start">
                                            <i class="fas fa-sort-numeric-up text-emerald-600 mt-1 mr-2"></i>
                                            <span>Payout order is determined by ascending number order</span>
                                        </li>
                                        <li class="flex items-start">
                                            <i class="fas fa-lock text-emerald-600 mt-1 mr-2"></i>
                                            <span>Once assigned, numbers cannot be changed for transparency</span>
                                        </li>
                                        <li class="flex items-start">
                                            <i class="fas fa-eye text-emerald-600 mt-1 mr-2"></i>
                                            <span>All members can see the payout order for transparency</span>
                                        </li>
                                    </ul>
                                </div>

                                <!-- Warning -->
                                <div class="mb-8 bg-red-50 border border-red-200 rounded-lg p-4">
                                    <div class="flex items-start">
                                        <i class="fas fa-exclamation-circle text-red-600 mt-1 mr-3"></i>
                                        <div>
                                            <h4 class="font-medium text-red-900">Important Notice</h4>
                                            <p class="text-red-800 text-sm mt-1">
                                                Once you assign random numbers, <strong class="font-bold">they cannot be changed</strong>. 
                                                This ensures fairness and transparency. Please double-check that all active members 
                                                are present before proceeding.
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <!-- Members List -->
                                <div class="mb-8">
                                    <h4 class="font-medium text-gray-900 mb-4">Active Members to be Assigned:</h4>
                                    <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
                                        <table class="min-w-full divide-y divide-gray-200">
                                            <thead class="bg-gray-50">
                                                <tr>
                                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">#</th>
                                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Member</th>
                                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Join Date</th>
                                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-200">
                                                <?php
                                                $stmt = $db->query("
                                                    SELECT m.*, u.full_name 
                                                    FROM members m 
                                                    JOIN users u ON m.user_id = u.id 
                                                    WHERE m.status = 'active' 
                                                    ORDER BY u.full_name
                                                ");
                                                $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                                
                                                if (empty($members)): ?>
                                                    <tr>
                                                        <td colspan="4" class="px-4 py-8 text-center text-gray-500">
                                                            No active members found
                                                        </td>
                                                    </tr>
                                                <?php else: 
                                                    foreach ($members as $index => $member): ?>
                                                    <tr class="hover:bg-gray-50">
                                                        <td class="px-4 py-3 text-sm text-gray-900"><?php echo $index + 1; ?></td>
                                                        <td class="px-4 py-3">
                                                            <div class="flex items-center">
                                                                <div class="flex-shrink-0 h-8 w-8 bg-emerald-100 rounded-full flex items-center justify-center">
                                                                    <span class="text-emerald-600 font-semibold text-sm">
                                                                        <?php echo strtoupper(substr($member['full_name'], 0, 1)); ?>
                                                                    </span>
                                                                </div>
                                                                <div class="ml-3">
                                                                    <div class="text-sm font-medium text-gray-900">
                                                                        <?php echo htmlspecialchars($member['full_name']); ?>
                                                                    </div>
                                                                    <div class="text-xs text-gray-500">
                                                                        <?php echo htmlspecialchars($member['member_code'] ?? 'N/A'); ?>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td class="px-4 py-3 text-sm text-gray-900">
                                                            <?php echo date('M d, Y', strtotime($member['join_date'])); ?>
                                                        </td>
                                                        <td class="px-4 py-3">
                                                            <span class="px-2 py-1 text-xs rounded-full bg-emerald-100 text-emerald-800">
                                                                Active
                                                            </span>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach;
                                                endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <!-- Submit Button -->
                                <form method="POST" action="">
                                    <button type="submit" 
                                            class="w-full bg-emerald-600 text-white px-4 py-3 rounded-lg hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-emerald-500 font-medium">
                                        <i class="fas fa-random mr-2"></i>
                                        Assign Random Numbers to <?php echo $active_members_count; ?> Members
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Navigation -->
                <div class="flex justify-between mt-8">
                    <a href="index.php" class="text-emerald-600 hover:text-emerald-800">
                        <i class="fas fa-arrow-left mr-2"></i>
                        Back to Cycles
                    </a>
                    
                    <?php if ($already_assigned): ?>
                        <a href="payout_order.php?cycle_id=<?php echo $cycle_id; ?>" 
                           class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700">
                            View Payout Order
                            <i class="fas fa-arrow-right ml-2"></i>
                        </a>
                    <?php endif; ?>
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