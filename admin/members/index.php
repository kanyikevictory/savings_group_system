<?php
require_once '../../config/constants.php';
require_once '../../config/database.php';
requireAdmin();

$db = getDB();

// Get all members
$stmt = $db->query("
    SELECT m.*, u.full_name, u.email, u.phone_number, 
           COALESCE(SUM(c.amount), 0) as total_contributions,
           COALESCE(SUM(p.amount), 0) as total_payouts
    FROM members m
    JOIN users u ON m.user_id = u.id
    LEFT JOIN contributions c ON m.id = c.member_id
    LEFT JOIN payouts p ON m.id = p.member_id
    GROUP BY m.id
    ORDER BY m.created_at DESC
");
$members = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Members Management - Savings Group System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-50">
    <!-- Include Sidebar -->
    <?php include '../../includes/admin_sidebar.php'; ?>
    
    <div class="ml-0 md:ml-64 min-h-screen">
        <!-- Mobile Header -->
        <header class="md:hidden bg-white shadow p-4 flex justify-between items-center">
            <button onclick="toggleSidebar()" class="text-gray-700">
                <i class="fas fa-bars text-xl"></i>
            </button>
            <h1 class="text-lg font-semibold">Members</h1>
        </header>

        <main class="p-6">
            <div class="flex justify-between items-center mb-6">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Members Management</h1>
                    <p class="text-gray-600">Manage group members and their information</p>
                </div>
                <a href="add.php" class="bg-emerald-600 text-white px-4 py-2 rounded-lg hover:bg-emerald-700 flex items-center">
                    <i class="fas fa-user-plus mr-2"></i>
                    Add Member
                </a>
            </div>

            <!-- Members Table -->
            <div class="bg-white rounded-xl shadow overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Member</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Contact</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Join Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Balance</th>
                                <th class="px6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach ($members as $member): 
                                $balance = $member['total_contributions'] - $member['total_payouts'];
                            ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4">
                                    <div class="flex items-center">
                                        <div class="flex-shrink-0 h-10 w-10 bg-emerald-100 rounded-full flex items-center justify-center">
                                            <span class="text-emerald-600 font-semibold">
                                                <?php echo strtoupper(substr($member['full_name'], 0, 1)); ?>
                                            </span>
                                        </div>
                                        <div class="ml-4">
                                            <div class="text-sm font-medium text-gray-900">
                                                <?php echo htmlspecialchars($member['full_name']); ?>
                                            </div>
                                            <div class="text-sm text-gray-500">
                                                <?php echo htmlspecialchars($member['member_code'] ?? 'N/A'); ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="text-sm text-gray-900"><?php echo htmlspecialchars($member['email']); ?></div>
                                    <div class="text-sm text-gray-500"><?php echo htmlspecialchars($member['phone_number']); ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo date('M d, Y', strtotime($member['join_date'])); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="text-sm font-medium <?php echo $balance >= 0 ? 'text-emerald-600' : 'text-red-600'; ?>">
                                        UGX <?php echo number_format($balance, 2); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-3 py-1 text-xs rounded-full 
                                        <?php echo $member['status'] == 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-gray-100 text-gray-800'; ?>">
                                        <?php echo ucfirst($member['status']); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <a href="view.php?id=<?php echo $member['id']; ?>" class="text-emerald-600 hover:text-emerald-900 mr-3">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                    <button onclick="toggleStatus(<?php echo $member['id']; ?>, '<?php echo $member['status']; ?>')" 
                                            class="text-blue-600 hover:text-blue-900">
                                        <?php if ($member['status'] == 'active'): ?>
                                            <i class="fas fa-user-slash"></i> Deactivate
                                        <?php else: ?>
                                            <i class="fas fa-user-check"></i> Activate
                                        <?php endif; ?>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($members)): ?>
                            <tr>
                                <td colspan="6" class="px-6 py-12 text-center text-gray-500">
                                    <i class="fas fa-users text-4xl mb-4 text-gray-300"></i>
                                    <p class="text-lg">No members found</p>
                                    <p class="text-sm mt-2">Add your first member to get started</p>
                                    <a href="add.php" class="mt-4 inline-block bg-emerald-600 text-white px-4 py-2 rounded-lg hover:bg-emerald-700">
                                        Add Member
                                    </a>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <script>
        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('active');
        }

        function toggleStatus(memberId, currentStatus) {
            const newStatus = currentStatus === 'active' ? 'inactive' : 'active';
            const confirmMsg = newStatus === 'inactive' 
                ? 'Are you sure you want to deactivate this member?'
                : 'Are you sure you want to activate this member?';

            if (confirm(confirmMsg)) {
                fetch('../../api/members.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        action: 'update_status',
                        member_id: memberId,
                        status: newStatus
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
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
    </script>
</body>
</html>