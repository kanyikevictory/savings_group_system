<?php
require_once '../../config/constants.php';
require_once '../../config/database.php';
requireAdmin();

$db = getDB();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'] ?? '';
    $contribution_amount = $_POST['contribution_amount'] ?? 0;
    $payment_frequency = $_POST['payment_frequency'] ?? 'monthly';
    $start_date = $_POST['start_date'] ?? '';
    $description = $_POST['description'] ?? '';
    
    if ($name && $contribution_amount > 0 && $start_date) {
        try {
            $db->beginTransaction();
            
            // Check if columns exist, if not add them (optional - you can run this manually)
            try {
                $db->query("SELECT contribution_amount FROM cycles LIMIT 1");
            } catch (Exception $e) {
                // Columns don't exist, add them
                $db->exec("ALTER TABLE cycles 
                          ADD COLUMN contribution_amount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER name,
                          ADD COLUMN payment_frequency ENUM('weekly', 'monthly') NOT NULL DEFAULT 'monthly' AFTER contribution_amount");
            }
            
            $stmt = $db->prepare("
                INSERT INTO cycles (name, contribution_amount, payment_frequency, start_date, status) 
                VALUES (:name, :contribution_amount, :payment_frequency, :start_date, 'pending')
            ");
            
            $stmt->execute([
                ':name' => $name,
                ':contribution_amount' => $contribution_amount,
                ':payment_frequency' => $payment_frequency,
                ':start_date' => $start_date,
            
            ]);
            
            $cycle_id = $db->lastInsertId();
            
            $db->commit();
            
            logActivity('Create Cycle', "Created cycle: $name with amount: $contribution_amount, frequency: $payment_frequency");
            
            $_SESSION['success'] = "Cycle created successfully! Now you can assign members.";
            header("Location: assign.php?cycle_id=" . $cycle_id);
            exit();
            
        } catch (Exception $e) {
            $db->rollBack();
            $error = "Error creating cycle: " . $e->getMessage();
        }
    } else {
        $error = "Please fill in all required fields";
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
    <style>
        .frequency-card {
            transition: all 0.2s ease;
            cursor: pointer;
            border: 2px solid transparent;
        }
        .frequency-card.selected {
            border-color: #059669;
            background-color: #ecfdf5;
        }
        .frequency-card:hover:not(.selected) {
            border-color: #d1d5db;
            background-color: #f9fafb;
        }
    </style>
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
            <h1 class="text-lg font-semibold">Create Cycle</h1>
        </header>

        <main class="p-6">
            <!-- Header -->
            <div class="mb-6 flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Create New Cycle</h1>
                    <p class="text-gray-600">Set up a new rotating savings cycle</p>
                </div>
                <a href="index.php" class="text-gray-600 hover:text-gray-900">
                    <i class="fas fa-times text-xl"></i>
                </a>
            </div>

            <!-- Error Message -->
            <?php if (isset($error)): ?>
                <div class="mb-6 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-circle mr-3"></i>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Create Form -->
            <div class="bg-white rounded-xl shadow-lg max-w-3xl">
                <form method="POST" class="p-8 space-y-6" id="cycleForm">
                    <!-- Cycle Name -->
                    <div>
                        <label for="name" class="block text-sm font-medium text-gray-700 mb-2">
                            Cycle Name <span class="text-red-500">*</span>
                        </label>
                        <input type="text" 
                               id="name" 
                               name="name" 
                               required
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 text-gray-900"
                               placeholder="e.g., Q1 2024 Savings Cycle"
                               value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
                    </div>

                    <!-- Contribution Amount -->
                    <div>
                        <label for="contribution_amount" class="block text-sm font-medium text-gray-700 mb-2">
                            Contribution Amount (UGX) <span class="text-red-500">*</span>
                        </label>
                        <div class="relative">
                            <span class="absolute left-4 top-3 text-gray-500 font-medium">UGX</span>
                            <input type="number" 
                                   id="contribution_amount" 
                                   name="contribution_amount" 
                                   required
                                   min="1"
                                   step="0.01"
                                   class="w-full pl-14 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 text-gray-900"
                                   placeholder="0.00"
                                   value="<?php echo htmlspecialchars($_POST['contribution_amount'] ?? ''); ?>">
                        </div>
                        <p class="mt-2 text-sm text-gray-500 flex items-center">
                            <i class="fas fa-info-circle mr-2 text-emerald-500"></i>
                            Fixed amount each member will contribute per payment period
                        </p>
                    </div>

                    <!-- Payment Frequency -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-3">
                            Payment Frequency <span class="text-red-500">*</span>
                        </label>
                        <div class="grid grid-cols-2 gap-4">
                            <!-- Weekly Option -->
                            <div class="frequency-card p-4 border border-gray-200 rounded-lg <?php echo ($_POST['payment_frequency'] ?? 'monthly') == 'weekly' ? 'selected' : ''; ?>" 
                                 data-value="weekly"
                                 onclick="selectFrequency('weekly')">
                                <div class="flex items-start">
                                    <div class="flex-1">
                                        <div class="flex items-center">
                                            <i class="fas fa-calendar-week text-emerald-600 mr-2"></i>
                                            <span class="font-medium text-gray-900">Weekly</span>
                                        </div>
                                        <p class="text-xs text-gray-500 mt-1">Contributions due every week</p>
                                    </div>
                                    <div class="ml-3">
                                        <div class="w-5 h-5 rounded-full border-2 frequency-radio <?php echo ($_POST['payment_frequency'] ?? 'monthly') == 'weekly' ? 'bg-emerald-600 border-emerald-600' : 'border-gray-300'; ?>"></div>
                                    </div>
                                </div>
                            </div>

                            <!-- Monthly Option -->
                            <div class="frequency-card p-4 border border-gray-200 rounded-lg <?php echo ($_POST['payment_frequency'] ?? 'monthly') == 'monthly' ? 'selected' : ''; ?>" 
                                 data-value="monthly"
                                 onclick="selectFrequency('monthly')">
                                <div class="flex items-start">
                                    <div class="flex-1">
                                        <div class="flex items-center">
                                            <i class="fas fa-calendar-alt text-emerald-600 mr-2"></i>
                                            <span class="font-medium text-gray-900">Monthly</span>
                                        </div>
                                        <p class="text-xs text-gray-500 mt-1">Contributions due every month</p>
                                    </div>
                                    <div class="ml-3">
                                        <div class="w-5 h-5 rounded-full border-2 frequency-radio <?php echo ($_POST['payment_frequency'] ?? 'monthly') == 'monthly' ? 'bg-emerald-600 border-emerald-600' : 'border-gray-300'; ?>"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <input type="hidden" name="payment_frequency" id="payment_frequency" value="<?php echo htmlspecialchars($_POST['payment_frequency'] ?? 'monthly'); ?>">
                    </div>

                    <!-- Start Date -->
                    <div>
                        <label for="start_date" class="block text-sm font-medium text-gray-700 mb-2">
                            Start Date <span class="text-red-500">*</span>
                        </label>
                        <input type="date" 
                               id="start_date" 
                               name="start_date" 
                               required
                               min="<?php echo date('Y-m-d'); ?>"
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 text-gray-900"
                               value="<?php echo htmlspecialchars($_POST['start_date'] ?? ''); ?>">
                        <p class="mt-2 text-sm text-gray-500 flex items-center">
                            <i class="fas fa-info-circle mr-2 text-emerald-500"></i>
                            First contribution/payout cycle begins on this date
                        </p>
                    </div>

                    
                    <!-- Summary Preview -->
                    <div class="bg-gradient-to-r from-emerald-50 to-blue-50 rounded-xl p-6 mt-6">
                        <h3 class="text-sm font-medium text-gray-700 mb-4 flex items-center">
                            <i class="fas fa-chart-pie mr-2 text-emerald-600"></i>
                            Cycle Summary Preview
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div class="bg-white rounded-lg p-4 shadow-sm">
                                <p class="text-xs text-gray-500 mb-1">Contribution Amount</p>
                                <p class="text-xl font-bold text-gray-900" id="previewAmount">UGX 0.00</p>
                            </div>
                            <div class="bg-white rounded-lg p-4 shadow-sm">
                                <p class="text-xs text-gray-500 mb-1">Payment Frequency</p>
                                <p class="text-xl font-bold text-gray-900" id="previewFrequency">Monthly</p>
                            </div>
                            <div class="bg-white rounded-lg p-4 shadow-sm">
                                <p class="text-xs text-gray-500 mb-1">Start Date</p>
                                <p class="text-xl font-bold text-gray-900" id="previewDate">Not set</p>
                            </div>
                        </div>
                    </div>

                    <!-- Form Actions -->
                    <div class="flex justify-end space-x-4 pt-6 border-t">
                        <a href="index.php" 
                           class="px-6 py-3 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 font-medium transition-colors">
                            Cancel
                        </a>
                        <button type="submit" 
                                class="px-6 py-3 bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 font-medium transition-colors flex items-center">
                            <i class="fas fa-plus-circle mr-2"></i>
                            Create Cycle
                        </button>
                    </div>
                </form>
            </div>

            <!-- Help Section -->
            <div class="mt-8 bg-white rounded-xl shadow-lg p-6 max-w-3xl">
                <h3 class="text-lg font-semibold text-gray-900 mb-4 flex items-center">
                    <i class="fas fa-question-circle text-emerald-600 mr-2"></i>
                    About Cycle Settings
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="flex items-start">
                        <div class="flex-shrink-0 w-8 h-8 bg-emerald-100 rounded-full flex items-center justify-center">
                            <i class="fas fa-coins text-emerald-600 text-sm"></i>
                        </div>
                        <div class="ml-3">
                            <h4 class="font-medium text-gray-900">Fixed Contribution</h4>
                            <p class="text-sm text-gray-600 mt-1">All members contribute the same amount per payment period for fairness and simplicity.</p>
                        </div>
                    </div>
                    <div class="flex items-start">
                        <div class="flex-shrink-0 w-8 h-8 bg-emerald-100 rounded-full flex items-center justify-center">
                            <i class="fas fa-clock text-emerald-600 text-sm"></i>
                        </div>
                        <div class="ml-3">
                            <h4 class="font-medium text-gray-900">Payment Frequency</h4>
                            <p class="text-sm text-gray-600 mt-1">Choose how often members should make contributions - weekly or monthly.</p>
                        </div>
                    </div>
                    <div class="flex items-start">
                        <div class="flex-shrink-0 w-8 h-8 bg-emerald-100 rounded-full flex items-center justify-center">
                            <i class="fas fa-calendar-check text-emerald-600 text-sm"></i>
                        </div>
                        <div class="ml-3">
                            <h4 class="font-medium text-gray-900">Start Date</h4>
                            <p class="text-sm text-gray-600 mt-1">The cycle begins on this date. First contributions will be due based on this date.</p>
                        </div>
                    </div>
                    <div class="flex items-start">
                        <div class="flex-shrink-0 w-8 h-8 bg-emerald-100 rounded-full flex items-center justify-center">
                            <i class="fas fa-random text-emerald-600 text-sm"></i>
                        </div>
                        <div class="ml-3">
                            <h4 class="font-medium text-gray-900">Next: Random Assignment</h4>
                            <p class="text-sm text-gray-600 mt-1">After creating the cycle, you'll assign random numbers to determine payout order.</p>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('active');
        }

        // Frequency selection
        function selectFrequency(value) {
            // Update hidden input
            document.getElementById('payment_frequency').value = value;
            
            // Update UI
            const cards = document.querySelectorAll('.frequency-card');
            cards.forEach(card => {
                const radio = card.querySelector('.frequency-radio');
                if (card.dataset.value === value) {
                    card.classList.add('selected');
                    radio.classList.add('bg-emerald-600', 'border-emerald-600');
                    radio.classList.remove('border-gray-300');
                } else {
                    card.classList.remove('selected');
                    radio.classList.remove('bg-emerald-600', 'border-emerald-600');
                    radio.classList.add('border-gray-300');
                }
            });
            
            // Update preview
            updatePreview();
        }

        // Live preview update
        function updatePreview() {
            // Amount preview
            const amount = parseFloat(document.getElementById('contribution_amount').value) || 0;
            document.getElementById('previewAmount').textContent = 'KSh ' + amount.toFixed(2);
            
            // Frequency preview
            const frequency = document.getElementById('payment_frequency').value;
            document.getElementById('previewFrequency').textContent = frequency.charAt(0).toUpperCase() + frequency.slice(1);
            
            // Date preview
            const date = document.getElementById('start_date').value;
            if (date) {
                const formattedDate = new Date(date).toLocaleDateString('en-US', { 
                    year: 'numeric', 
                    month: 'short', 
                    day: 'numeric' 
                });
                document.getElementById('previewDate').textContent = formattedDate;
            } else {
                document.getElementById('previewDate').textContent = 'Not set';
            }
        }

        // Add event listeners
        document.getElementById('contribution_amount').addEventListener('input', updatePreview);
        document.getElementById('start_date').addEventListener('change', updatePreview);
        
        // Initialize preview on page load
        document.addEventListener('DOMContentLoaded', function() {
            updatePreview();
        });

        // Form validation
        document.getElementById('cycleForm').addEventListener('submit', function(e) {
            const amount = parseFloat(document.getElementById('contribution_amount').value);
            if (amount < 1) {
                e.preventDefault();
                alert('Please enter a valid contribution amount (minimum 1)');
                return;
            }
        });
    </script>
</body>
</html>