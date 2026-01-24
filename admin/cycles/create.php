<?php
require_once '../../config/constants.php';
require_once '../../config/database.php';
requireAdmin();

$db = getDB();
$error = '';
$success = '';

// Check if there's already an active cycle
$stmt = $db->query("SELECT id FROM cycles WHERE status = 'active' LIMIT 1");
$active_cycle = $stmt->fetch();

if ($active_cycle) {
    header("Location: index.php?error=There is already an active cycle. Complete it first.");
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $start_date = $_POST['start_date'] ?? '';
    $expected_end_date = $_POST['expected_end_date'] ?? '';
    
    // Validate
    if (empty($name) || empty($start_date)) {
        $error = "Cycle name and start date are required";
    } else {
        try {
            $stmt = $db->prepare("
                INSERT INTO cycles (name, start_date, expected_end_date, status) 
                VALUES (:name, :start_date, :expected_end_date, 'active')
            ");
            
            $stmt->execute([
                ':name' => $name,
                ':start_date' => $start_date,
                ':expected_end_date' => $expected_end_date ?: null
            ]);
            
            $cycle_id = $db->lastInsertId();
            logActivity('Create Cycle', "Created new cycle: $name (ID: $cycle_id)");
            
            $_SESSION['success'] = "Cycle '$name' created successfully!";
            header("Location: assign.php?cycle_id=$cycle_id");
            exit();
        } catch (Exception $e) {
            $error = "Error creating cycle: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create New Cycle - Savings Group System</title>
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
            <h1 class="text-lg font-semibold">Create Cycle</h1>
        </header>

        <main class="p-6">
            <div class="max-w-2xl mx-auto">
                <div class="mb-8">
                    <h1 class="text-2xl font-bold text-gray-900">Create New Payout Cycle</h1>
                    <p class="text-gray-600">Start a new rotating payout cycle for members</p>
                </div>

                <?php if ($error): ?>
                    <div class="mb-6 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <!-- Info Card -->
                <div class="mb-8 bg-blue-50 border border-blue-200 rounded-xl p-6">
                    <div class="flex items-start">
                        <i class="fas fa-info-circle text-blue-600 text-xl mt-1 mr-3"></i>
                        <div>
                            <h3 class="font-semibold text-blue-900">About Payout Cycles</h3>
                            <p class="text-blue-800 text-sm mt-2">
                                A payout cycle determines how members receive the group savings. Each cycle:
                            </p>
                            <ul class="text-blue-800 text-sm mt-2 space-y-1 ml-4 list-disc">
                                <li>Has a fixed number of members</li>
                                <li>Assigns random numbers to determine payout order</li>
                                <li>Each member receives payout once per cycle</li>
                                <li>Cycle completes when all members have been paid</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Create Cycle Form -->
                <div class="bg-white rounded-xl shadow p-6">
                    <form method="POST" action="">
                        <div class="space-y-6">
                            <!-- Cycle Name -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Cycle Name <span class="text-red-500">*</span>
                                </label>
                                <input type="text" name="name" required maxlength="255"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500"
                                       placeholder="e.g., January 2024 Cycle, Q1 Payout Cycle"
                                       value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
                                <p class="text-xs text-gray-500 mt-1">Give this cycle a descriptive name</p>
                            </div>

                            <!-- Start Date -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Start Date <span class="text-red-500">*</span>
                                </label>
                                <input type="date" name="start_date" required
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500"
                                       value="<?php echo htmlspecialchars($_POST['start_date'] ?? date('Y-m-d')); ?>">
                            </div>

                            <!-- Expected End Date -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    Expected End Date (Optional)
                                </label>
                                <input type="date" name="expected_end_date"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500"
                                       value="<?php echo htmlspecialchars($_POST['expected_end_date'] ?? ''); ?>">
                                <p class="text-xs text-gray-500 mt-1">Estimated completion date for planning</p>
                            </div>

                            <!-- Submit Button -->
                            <div class="pt-4">
                                <button type="submit" 
                                        class="w-full bg-emerald-600 text-white px-4 py-3 rounded-lg hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-emerald-500 font-medium">
                                    <i class="fas fa-plus-circle mr-2"></i>
                                    Create Payout Cycle
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Back Link -->
                <div class="mt-6 text-center">
                    <a href="index.php" class="text-emerald-600 hover:text-emerald-800">
                        <i class="fas fa-arrow-left mr-2"></i>
                        Back to Cycles
                    </a>
                </div>
            </div>
        </main>
    </div>

    <script>
        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('active');
        }
        
        // Set min date for expected end date to start date
        document.querySelector('input[name="start_date"]').addEventListener('change', function() {
            const endDateInput = document.querySelector('input[name="expected_end_date"]');
            endDateInput.min = this.value;
        });
    </script>
</body>
</html>