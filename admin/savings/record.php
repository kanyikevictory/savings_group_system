<?php
require_once '../../config/constants.php';
require_once '../../config/database.php';
requireAdmin();

$db = getDB();

// Get active members for dropdown
$stmt = $db->query("
    SELECT m.id, u.full_name, u.email 
    FROM members m 
    JOIN users u ON m.user_id = u.id 
    WHERE m.status = 'active' 
    ORDER BY u.full_name
");
$active_members = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get current active cycle
$stmt = $db->query("SELECT id, name FROM cycles WHERE status = 'active' LIMIT 1");
$current_cycle = $stmt->fetch(PDO::FETCH_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $member_id = $_POST['member_id'] ?? '';
    $amount = $_POST['amount'] ?? '';
    $contribution_date = $_POST['contribution_date'] ?? date('Y-m-d');
    $notes = $_POST['notes'] ?? '';
    $cycle_id = $current_cycle['id'] ?? null;
    
    if ($member_id && $amount && $amount > 0) {
        try {
            $db->beginTransaction();
            
            $stmt = $db->prepare("
                INSERT INTO contributions (member_id, cycle_id, amount, contribution_date, recorded_by, notes)
                VALUES (:member_id, :cycle_id, :amount, :contribution_date, :recorded_by, :notes)
            ");
            
            $stmt->execute([
                ':member_id' => $member_id,
                ':cycle_id' => $cycle_id,
                ':amount' => $amount,
                ':contribution_date' => $contribution_date,
                ':recorded_by' => $_SESSION['user_id'],
                ':notes' => $notes
            ]);
            
            logActivity('Record Savings', "Recorded KES $amount for member ID: $member_id");
            
            $db->commit();
            
            $_SESSION['success'] = "Savings contribution recorded successfully!";
            header("Location: record.php");
            exit();
        } catch (Exception $e) {
            $db->rollBack();
            $error = "Error recording contribution: " . $e->getMessage();
        }
    } else {
        $error = "Please fill all required fields correctly";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Record Savings - Savings Group System</title>
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
            <h1 class="text-lg font-semibold">Record Savings</h1>
        </header>

        <main class="p-6">
            <div class="mb-6">
                <h1 class="text-2xl font-bold text-gray-900">Record Savings Contribution</h1>
                <p class="text-gray-600">Record member contributions to the group savings</p>
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

            <div class="max-w-2xl">
                <div class="bg-white rounded-xl shadow p-6">
                    <form method="POST" action="">
                        <div class="space-y-6">
                            <!-- Member Selection -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Member <span class="text-red-500">*</span>
                                </label>
                                <select name="member_id" required 
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                                    <option value="">Select a member</option>
                                    <?php foreach ($active_members as $member): ?>
                                        <option value="<?php echo $member['id']; ?>">
                                            <?php echo htmlspecialchars($member['full_name']); ?> (<?php echo htmlspecialchars($member['email']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Amount -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Amount (UGX) <span class="text-red-500">*</span>
                                </label>
                                <div class="relative">
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <span class="text-gray-500">UGX</span>
                                    </div>
                                    <input type="number" name="amount" required min="1" step="0.01"
                                           class="w-full pl-16 pr-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500"
                                           placeholder="0.00">
                                </div>
                            </div>

                            <!-- Date -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Contribution Date <span class="text-red-500">*</span>
                                </label>
                                <input type="date" name="contribution_date" required 
                                       value="<?php echo date('Y-m-d'); ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                            </div>

                            <!-- Current Cycle -->
                            <?php if ($current_cycle): ?>
                                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                                    <div class="flex items-center">
                                        <i class="fas fa-sync-alt text-blue-600 mr-3"></i>
                                        <div>
                                            <p class="font-medium text-blue-900">Current Cycle: <?php echo htmlspecialchars($current_cycle['name']); ?></p>
                                            <p class="text-sm text-blue-700">This contribution will be linked to the active cycle</p>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Notes -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Notes (Optional)
                                </label>
                                <textarea name="notes" rows="3"
                                          class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500"
                                          placeholder="Add any notes about this contribution..."></textarea>
                            </div>

                            <!-- Submit Button -->
                            <div class="pt-4">
                                <button type="submit" 
                                        class="w-full bg-emerald-600 text-white px-4 py-3 rounded-lg hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-emerald-500 font-medium">
                                    <i class="fas fa-save mr-2"></i>
                                    Record Contribution
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Quick Stats -->
                <div class="mt-8 grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="bg-white rounded-xl shadow p-6">
                        <div class="flex items-center">
                            <div class="p-3 rounded-lg bg-emerald-100 text-emerald-600 mr-4">
                                <i class="fas fa-users text-2xl"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Active Members</p>
                                <p class="text-2xl font-bold"><?php echo count($active_members); ?></p>
                            </div>
                        </div>
                    </div>

                    <?php if ($current_cycle): ?>
                    <div class="bg-white rounded-xl shadow p-6">
                        <div class="flex items-center">
                            <div class="p-3 rounded-lg bg-blue-100 text-blue-600 mr-4">
                                <i class="fas fa-sync-alt text-2xl"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Active Cycle</p>
                                <p class="text-lg font-bold"><?php echo htmlspecialchars($current_cycle['name']); ?></p>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <script>
        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('active');
        }

        // Auto-focus first field
        document.querySelector('select[name="member_id"]').focus();
    </script>
</body>
</html>