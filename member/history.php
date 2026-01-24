<?php
require_once '../config/constants.php';
require_once '../config/database.php';
requireMember();

$db = getDB();
$member_id = $_SESSION['member_id'];

// Get member contributions
$stmt = $db->prepare("
    SELECT c.*, cy.name as cycle_name 
    FROM contributions c 
    LEFT JOIN cycles cy ON c.cycle_id = cy.id 
    WHERE c.member_id = :member_id 
    ORDER BY c.contribution_date DESC
");
$stmt->execute([':member_id' => $member_id]);
$contributions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get member details
$stmt = $db->prepare("
    SELECT m.*, u.full_name 
    FROM members m 
    JOIN users u ON m.user_id = u.id 
    WHERE m.id = :member_id
");
$stmt->execute([':member_id' => $member_id]);
$member = $stmt->fetch(PDO::FETCH_ASSOC);

// Calculate totals
$total_contributions = array_sum(array_column($contributions, 'amount'));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Savings History - Savings Group System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-50">
    <!-- Member Sidebar -->
    <div class="sidebar fixed inset-y-0 left-0 z-50 w-64 bg-white shadow-lg">
        <div class="p-6">
            <h1 class="text-2xl font-bold text-emerald-600">Savings Group</h1>
            <p class="text-gray-600 text-sm mt-1">Member Portal</p>
        </div>
        
        <nav class="mt-6">
            <a href="dashboard.php" class="flex items-center px-6 py-3 text-gray-700 hover:bg-gray-100">
                <i class="fas fa-home mr-3"></i>
                Dashboard
            </a>
            
            <a href="history.php" class="flex items-center px-6 py-3 text-emerald-600 bg-emerald-50 border-r-4 border-emerald-500">
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
            <button onclick="toggleSidebar()" class="text-gray-700">
                <i class="fas fa-bars text-xl"></i>
            </button>
            <h1 class="text-lg font-semibold">Savings History</h1>
        </header>

        <main class="p-6">
            <div class="mb-8">
                <h1 class="text-2xl font-bold text-gray-900">My Savings History</h1>
                <p class="text-gray-600">View all your contributions and savings records</p>
            </div>

            <!-- Summary Card -->
            <div class="bg-white rounded-xl shadow p-6 mb-8">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-500">Total Contributions</p>
                        <p class="text-3xl font-bold text-emerald-600">
                            UGX <?php echo number_format($total_contributions, 2); ?>
                        </p>
                        <p class="text-sm text-gray-500 mt-1">
                            <?php echo count($contributions); ?> contributions recorded
                        </p>
                    </div>
                    <div class="text-right">
                        <p class="text-sm text-gray-500">Member Since</p>
                        <p class="text-lg font-bold">
                            <?php echo date('M d, Y', strtotime($member['join_date'] ?? date('Y-m-d'))); ?>
                        </p>
                        <span class="mt-2 inline-block px-3 py-1 text-xs rounded-full bg-emerald-100 text-emerald-800">
                            Active Member
                        </span>
                    </div>
                </div>
            </div>

            <!-- Contributions Table -->
            <div class="bg-white rounded-xl shadow overflow-hidden">
                <div class="px-6 py-4 border-b">
                    <h2 class="text-lg font-semibold text-gray-900">My Contributions</h2>
                    <p class="text-sm text-gray-600">All your savings contributions in chronological order</p>
                </div>
                
                <div class="overflow-x-auto">
                    <?php if (empty($contributions)): ?>
                        <div class="px-6 py-12 text-center text-gray-500">
                            <i class="fas fa-money-bill-wave text-6xl mb-6 text-gray-300"></i>
                            <p class="text-lg">No contributions recorded yet</p>
                            <p class="text-sm mt-2">Your contributions will appear here once recorded</p>
                        </div>
                    <?php else: ?>
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
                                <?php foreach ($contributions as $contribution): ?>
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
                    <?php endif; ?>
                </div>
                
                <!-- Summary Footer -->
                <?php if (!empty($contributions)): ?>
                    <div class="px-6 py-4 border-t bg-gray-50">
                        <div class="flex justify-between items-center">
                            <div class="text-sm text-gray-600">
                                Showing <?php echo count($contributions); ?> contributions
                            </div>
                            <div class="text-right">
                                <p class="text-sm text-gray-500">Total Contributions</p>
                                <p class="text-lg font-bold text-emerald-600">
                                    UGX <?php echo number_format($total_contributions, 2); ?>
                                </p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Print Option -->
            <?php if (!empty($contributions)): ?>
                <div class="mt-6 text-center">
                    <button onclick="window.print()" 
                            class="bg-gray-600 text-white px-6 py-3 rounded-lg hover:bg-gray-700">
                        <i class="fas fa-print mr-2"></i>
                        Print My Savings Statement
                    </button>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('active');
        }
        
        // Print styling
        const style = document.createElement('style');
        style.textContent = `
            @media print {
                .sidebar, button, .no-print { display: none !important; }
                .ml-64 { margin-left: 0 !important; }
                body { background: white !important; }
                .bg-gray-50 { background: white !important; }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>