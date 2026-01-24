<?php
require_once '../../config/constants.php';
require_once '../../config/database.php';
requireAdmin();

$db = getDB();

// Get filter parameters
$member_id = $_GET['member_id'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

// Build query
$query = "
    SELECT c.*, u.full_name, cy.name as cycle_name 
    FROM contributions c 
    JOIN members m ON c.member_id = m.id 
    JOIN users u ON m.user_id = u.id 
    LEFT JOIN cycles cy ON c.cycle_id = cy.id 
    WHERE 1=1
";

$params = [];

if ($member_id) {
    $query .= " AND c.member_id = :member_id";
    $params[':member_id'] = $member_id;
}

if ($start_date) {
    $query .= " AND c.contribution_date >= :start_date";
    $params[':start_date'] = $start_date;
}

if ($end_date) {
    $query .= " AND c.contribution_date <= :end_date";
    $params[':end_date'] = $end_date;
}

$query .= " ORDER BY c.contribution_date DESC, c.recorded_at DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$contributions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total
$total_query = "SELECT COALESCE(SUM(amount), 0) FROM contributions";
$total = $db->query($total_query)->fetchColumn();

// Get members for filter dropdown
$members_stmt = $db->query("
    SELECT m.id, u.full_name 
    FROM members m 
    JOIN users u ON m.user_id = u.id 
    ORDER BY u.full_name
");
$members = $members_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Savings History - Savings Group System</title>
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
            <h1 class="text-lg font-semibold">Savings History</h1>
        </header>

        <main class="p-6">
            <div class="mb-8">
                <h1 class="text-2xl font-bold text-gray-900">Savings Contributions History</h1>
                <p class="text-gray-600">View and filter all savings contributions</p>
            </div>

            <!-- Filters -->
            <div class="bg-white rounded-xl shadow p-6 mb-8">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Filter Contributions</h3>
                <form method="GET" action="" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <!-- Member Filter -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Member</label>
                        <select name="member_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                            <option value="">All Members</option>
                            <?php foreach ($members as $m): ?>
                                <option value="<?php echo $m['id']; ?>" <?php echo $member_id == $m['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($m['full_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Start Date -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">From Date</label>
                        <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                    </div>

                    <!-- End Date -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">To Date</label>
                        <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                    </div>

                    <!-- Actions -->
                    <div class="flex items-end">
                        <button type="submit" 
                                class="bg-emerald-600 text-white px-4 py-2 rounded-lg hover:bg-emerald-700 w-full">
                            <i class="fas fa-filter mr-2"></i>
                            Apply Filters
                        </button>
                        <?php if ($member_id || $start_date || $end_date): ?>
                            <a href="history.php" 
                               class="ml-2 bg-gray-600 text-white px-4 py-2 rounded-lg hover:bg-gray-700">
                                Clear
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                <div class="bg-white rounded-xl shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-lg bg-emerald-100 text-emerald-600 mr-4">
                            <i class="fas fa-piggy-bank text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Total Savings</p>
                            <p class="text-2xl font-bold">UGX <?php echo number_format($total, 2); ?></p>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-xl shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-lg bg-blue-100 text-blue-600 mr-4">
                            <i class="fas fa-list-ol text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Total Contributions</p>
                            <p class="text-2xl font-bold"><?php echo count($contributions); ?></p>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-xl shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-lg bg-purple-100 text-purple-600 mr-4">
                            <i class="fas fa-calendar-alt text-2xl"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Filtered Results</p>
                            <p class="text-2xl font-bold"><?php echo count($contributions); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Contributions Table -->
            <div class="bg-white rounded-xl shadow overflow-hidden">
                <div class="px-6 py-4 border-b">
                    <h2 class="text-lg font-semibold text-gray-900">Contributions List</h2>
                    <p class="text-sm text-gray-600">Showing <?php echo count($contributions); ?> records</p>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Member</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Amount</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Cycle</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Notes</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Recorded At</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php if (empty($contributions)): ?>
                                <tr>
                                    <td colspan="6" class="px-6 py-12 text-center text-gray-500">
                                        <i class="fas fa-search text-4xl mb-4 text-gray-300"></i>
                                        <p class="text-lg">No contributions found</p>
                                        <p class="text-sm mt-2">Try changing your filters or record new contributions</p>
                                    </td>
                                </tr>
                            <?php else: 
                                foreach ($contributions as $contribution): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo date('M d, Y', strtotime($contribution['contribution_date'])); ?>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="text-sm font-medium text-gray-900">
                                            <?php echo htmlspecialchars($contribution['full_name']); ?>
                                        </div>
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
                                        <?php echo date('M d, Y H:i', strtotime($contribution['recorded_at'])); ?>
                                    </td>
                                </tr>
                            <?php endforeach;
                            endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Export Option -->
                <div class="px-6 py-4 border-t">
                    <div class="flex justify-between items-center">
                        <div class="text-sm text-gray-600">
                            Showing <?php echo count($contributions); ?> of <?php echo count($contributions); ?> records
                        </div>
                        <button onclick="window.print()" 
                                class="bg-gray-600 text-white px-4 py-2 rounded-lg hover:bg-gray-700">
                            <i class="fas fa-print mr-2"></i>
                            Print Report
                        </button>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('active');
        }
        
        // Set date ranges
        const today = new Date().toISOString().split('T')[0];
        document.querySelectorAll('input[type="date"]').forEach(input => {
            if (!input.value) {
                // Default to first of current month for start date
                if (input.name === 'start_date') {
                    const firstDay = new Date();
                    firstDay.setDate(1);
                    input.value = firstDay.toISOString().split('T')[0];
                }
                // Default to today for end date
                if (input.name === 'end_date') {
                    input.value = today;
                }
            }
        });
    </script>
</body>
</html>