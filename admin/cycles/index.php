<?php
require_once '../../config/constants.php';
require_once '../../config/database.php';
requireAdmin();

$db = getDB();

// Handle cycle deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_cycle') {
    $cycle_id = $_POST['cycle_id'] ?? 0;
    
    if ($cycle_id) {
        try {
            // Check if cycle has any contributions or payouts
            $stmt = $db->prepare("SELECT COUNT(*) FROM contributions WHERE cycle_id = :cycle_id");
            $stmt->execute([':cycle_id' => $cycle_id]);
            $has_contributions = $stmt->fetchColumn() > 0;
            
            $stmt = $db->prepare("SELECT COUNT(*) FROM cycle_assignments WHERE cycle_id = :cycle_id");
            $stmt->execute([':cycle_id' => $cycle_id]);
            $has_assignments = $stmt->fetchColumn() > 0;
            
            if ($has_contributions || $has_assignments) {
                // Show error message
                $_SESSION['error'] = "Cannot delete cycle with existing contributions or assignments. Archive it instead.";
            } else {
                // Delete the cycle
                $stmt = $db->prepare("DELETE FROM cycles WHERE id = :cycle_id");
                $stmt->execute([':cycle_id' => $cycle_id]);
                
                logActivity('Delete Cycle', "Deleted cycle ID: $cycle_id");
                $_SESSION['success'] = "Cycle deleted successfully!";
            }
            
            header("Location: index.php");
            exit();
        } catch (Exception $e) {
            $_SESSION['error'] = "Error deleting cycle: " . $e->getMessage();
            header("Location: index.php");
            exit();
        }
    }
}

// Get all cycles
$stmt = $db->query("
    SELECT c.*, 
           COUNT(DISTINCT ca.member_id) as total_members,
           COUNT(DISTINCT CASE WHEN ca.payout_status = 'paid' THEN ca.id END) as paid_count,
           COUNT(DISTINCT CASE WHEN ca.payout_status = 'pending' THEN ca.id END) as pending_count,
           COUNT(DISTINCT CASE WHEN ca.payout_status = 'pending_verification' THEN ca.id END) as pending_verification_count
    FROM cycles c
    LEFT JOIN cycle_assignments ca ON c.id = ca.cycle_id
    GROUP BY c.id
    ORDER BY 
        CASE WHEN c.status = 'active' THEN 1
             WHEN c.status = 'completed' THEN 2
             ELSE 3 END,
        c.start_date DESC
");
$cycles = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cycle Management - Savings Group System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .cycle-card {
            transition: all 0.3s ease;
        }
        .cycle-card:hover {
            transform: translateY(-2px);
        }
        .delete-btn {
            opacity: 0.7;
            transition: all 0.2s ease;
        }
        .delete-btn:hover {
            opacity: 1;
            transform: scale(1.1);
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
            <h1 class="text-lg font-semibold">Cycles</h1>
        </header>

        <main class="p-6">
            <!-- Success/Error Messages -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="mb-6 bg-emerald-50 border border-emerald-200 text-emerald-700 px-4 py-3 rounded-lg">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle mr-3"></i>
                        <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="mb-6 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-circle mr-3"></i>
                        <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="flex justify-between items-center mb-6">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Cycle Management</h1>
                    <p class="text-gray-600">Manage rotating payout cycles</p>
                </div>
                <a href="create.php" class="bg-emerald-600 text-white px-4 py-2 rounded-lg hover:bg-emerald-700 flex items-center">
                    <i class="fas fa-plus-circle mr-2"></i>
                    Create New Cycle
                </a>
            </div>

            <!-- Cycles Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach ($cycles as $cycle): 
                    $progress = $cycle['total_members'] > 0 ? ($cycle['paid_count'] / $cycle['total_members']) * 100 : 0;
                    $can_delete = ($cycle['total_members'] == 0) && ($cycle['status'] != 'active');
                    $can_edit = ($cycle['status'] != 'completed');
                ?>
                <div class="cycle-card bg-white rounded-xl shadow hover:shadow-lg">
                    <div class="p-6">
                        <!-- Header with Cycle Name and Delete Button -->
                        <div class="flex justify-between items-start mb-4">
                            <div class="flex-1">
                                <div class="flex items-center justify-between mb-2">
                                    <h3 class="text-lg font-semibold text-gray-900"><?php echo htmlspecialchars($cycle['name']); ?></h3>
                                    <!-- Delete Button - Always Visible -->
                                    <button onclick="showDeleteModal(<?php echo $cycle['id']; ?>, '<?php echo htmlspecialchars($cycle['name']); ?>')" 
                                            class="delete-btn text-red-500 hover:text-red-700"
                                            title="Delete Cycle"
                                            <?php if (!$can_delete): ?>disabled style="opacity: 0.3; cursor: not-allowed;"<?php endif; ?>>
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </div>
                                <div class="flex items-center justify-between">
                                    <p class="text-sm text-gray-500">
                                        <?php echo date('M d, Y', strtotime($cycle['start_date'])); ?> 
                                        <?php if ($cycle['expected_end_date']): ?>
                                            - <?php echo date('M d, Y', strtotime($cycle['expected_end_date'])); ?>
                                        <?php endif; ?>
                                    </p>
                                    <span class="px-3 py-1 text-xs rounded-full 
                                        <?php echo $cycle['status'] == 'active' ? 'bg-emerald-100 text-emerald-800' : 
                                               ($cycle['status'] == 'completed' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-800'); ?>">
                                        <?php echo ucfirst($cycle['status']); ?>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Progress Bar -->
                        <div class="mb-6">
                            <div class="flex justify-between text-sm text-gray-600 mb-1">
                                <span>Progress</span>
                                <span><?php echo $cycle['paid_count']; ?> of <?php echo $cycle['total_members']; ?> paid</span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-2">
                                <div class="bg-emerald-600 h-2 rounded-full" style="width: <?php echo $progress; ?>%"></div>
                            </div>
                        </div>

                        <!-- Stats Grid -->
                        <div class="grid grid-cols-4 gap-3 mb-6">
                            <div class="text-center p-3 bg-gray-50 rounded-lg">
                                <p class="text-xs text-gray-500 mb-1">Members</p>
                                <p class="text-lg font-bold text-gray-900"><?php echo $cycle['total_members']; ?></p>
                            </div>
                            <div class="text-center p-3 bg-green-50 rounded-lg">
                                <p class="text-xs text-gray-500 mb-1">Paid</p>
                                <p class="text-lg font-bold text-green-600"><?php echo $cycle['paid_count']; ?></p>
                            </div>
                            <div class="text-center p-3 bg-amber-50 rounded-lg">
                                <p class="text-xs text-gray-500 mb-1">Pending</p>
                                <p class="text-lg font-bold text-amber-600"><?php echo $cycle['pending_count']; ?></p>
                            </div>
                            <div class="text-center p-3 bg-orange-50 rounded-lg">
                                <p class="text-xs text-gray-500 mb-1">Verify</p>
                                <p class="text-lg font-bold text-orange-600"><?php echo $cycle['pending_verification_count']; ?></p>
                            </div>
                        </div>

                        <!-- Action Buttons - Redesigned -->
                        <div class="space-y-3">
                            <!-- Primary Action Row -->
                            <div class="flex space-x-2">
                                <?php if ($cycle['status'] == 'active'): ?>
                                    <?php if ($cycle['total_members'] == 0): ?>
                                        <a href="assign.php?cycle_id=<?php echo $cycle['id']; ?>" 
                                           class="flex-1 bg-blue-600 text-white text-center px-3 py-2 rounded-lg hover:bg-blue-700 text-sm font-medium">
                                            <i class="fas fa-random mr-1"></i> Assign Numbers
                                        </a>
                                    <?php else: ?>
                                        <a href="payout_order.php?cycle_id=<?php echo $cycle['id']; ?>" 
                                           class="flex-1 bg-emerald-600 text-white text-center px-3 py-2 rounded-lg hover:bg-emerald-700 text-sm font-medium">
                                            <i class="fas fa-list-ol mr-1"></i> Payout Order
                                        </a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <a href="payout_order.php?cycle_id=<?php echo $cycle['id']; ?>" 
                                       class="flex-1 bg-gray-600 text-white text-center px-3 py-2 rounded-lg hover:bg-gray-700 text-sm font-medium">
                                        <i class="fas fa-history mr-1"></i> View Details
                                    </a>
                                <?php endif; ?>
                                
                                <a href="record_payout.php?cycle_id=<?php echo $cycle['id']; ?>" 
                                   class="flex-1 bg-amber-600 text-white text-center px-3 py-2 rounded-lg hover:bg-amber-700 text-sm font-medium">
                                    <i class="fas fa-hand-holding-usd mr-1"></i> Record Payout
                                </a>
                            </div>
                            
                            <!-- Secondary Action Row -->
                            <div class="flex space-x-2">
                                <?php if ($can_edit): ?>
                                    <a href="edit.php?id=<?php echo $cycle['id']; ?>" 
                                       class="flex-1 bg-blue-100 text-blue-700 text-center px-3 py-2 rounded-lg hover:bg-blue-200 text-sm">
                                        <i class="fas fa-edit mr-1"></i> Edit
                                    </a>
                                <?php endif; ?>
                                
                                <!-- Archive/Complete Button -->
                                <?php if ($cycle['status'] == 'active' && $cycle['paid_count'] == $cycle['total_members'] && $cycle['total_members'] > 0): ?>
                                    <a href="complete.php?id=<?php echo $cycle['id']; ?>" 
                                       class="flex-1 bg-purple-100 text-purple-700 text-center px-3 py-2 rounded-lg hover:bg-purple-200 text-sm">
                                        <i class="fas fa-flag-checkered mr-1"></i> Complete
                                    </a>
                                <?php elseif ($cycle['status'] == 'active'): ?>
                                    <a href="archive.php?id=<?php echo $cycle['id']; ?>" 
                                       class="flex-1 bg-gray-100 text-gray-700 text-center px-3 py-2 rounded-lg hover:bg-gray-200 text-sm">
                                        <i class="fas fa-archive mr-1"></i> Archive
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Delete Warning (if cannot delete) -->
                        <?php if (!$can_delete && $cycle['total_members'] > 0): ?>
                            <div class="mt-4 pt-4 border-t border-gray-100">
                                <p class="text-xs text-gray-500">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    To delete this cycle, first remove all member assignments and contributions.
                                </p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                
                <?php if (empty($cycles)): ?>
                <div class="col-span-3">
                    <div class="bg-white rounded-xl shadow p-12 text-center">
                        <i class="fas fa-sync-alt text-6xl text-gray-300 mb-6"></i>
                        <h3 class="text-xl font-semibold text-gray-900 mb-2">No cycles yet</h3>
                        <p class="text-gray-600 mb-6">Create your first payout cycle to get started</p>
                        <a href="create.php" class="bg-emerald-600 text-white px-6 py-3 rounded-lg hover:bg-emerald-700 inline-flex items-center">
                            <i class="fas fa-plus-circle mr-2"></i>
                            Create First Cycle
                        </a>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50 flex items-center justify-center">
        <div class="bg-white rounded-xl shadow-lg w-full max-w-md mx-4">
            <div class="px-6 py-4 border-b">
                <h3 class="text-lg font-semibold text-gray-900">Delete Cycle</h3>
            </div>
            <div class="p-6">
                <div class="flex items-start mb-6">
                    <div class="flex-shrink-0 h-10 w-10 bg-red-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-exclamation-triangle text-red-600"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-gray-700">
                            Are you sure you want to delete cycle "<span id="cycleName" class="font-semibold"></span>"?
                        </p>
                        <p class="text-sm text-red-600 mt-2">
                            <i class="fas fa-exclamation-circle mr-1"></i>
                            This action cannot be undone.
                        </p>
                    </div>
                </div>
                <div class="flex justify-end space-x-3">
                    <button onclick="hideDeleteModal()" 
                            class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50">
                        Cancel
                    </button>
                    <form id="deleteForm" method="POST" action="">
                        <input type="hidden" name="action" value="delete_cycle">
                        <input type="hidden" id="deleteCycleId" name="cycle_id" value="">
                        <button type="submit" 
                                class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 font-medium">
                            <i class="fas fa-trash-alt mr-2"></i>
                            Delete Cycle
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('active');
        }

        // Delete Modal Functions
        function showDeleteModal(cycleId, cycleName) {
            // Check if delete is disabled
            const deleteBtn = event.currentTarget;
            if (deleteBtn.disabled) {
                return;
            }
            
            document.getElementById('cycleName').textContent = cycleName;
            document.getElementById('deleteCycleId').value = cycleId;
            document.getElementById('deleteModal').classList.remove('hidden');
        }

        function hideDeleteModal() {
            document.getElementById('deleteModal').classList.add('hidden');
        }

        // Close modal when clicking outside
        document.getElementById('deleteModal').addEventListener('click', function(e) {
            if (e.target === this) {
                hideDeleteModal();
            }
        });

        // Prevent form submission on Enter key in modal
        document.getElementById('deleteForm').addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
            }
        });

        // Handle Escape key to close modal
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && !document.getElementById('deleteModal').classList.contains('hidden')) {
                hideDeleteModal();
            }
        });

        // Add tooltip for disabled delete buttons
        document.addEventListener('DOMContentLoaded', function() {
            const disabledDeleteBtns = document.querySelectorAll('.delete-btn[disabled]');
            disabledDeleteBtns.forEach(btn => {
                btn.addEventListener('mouseenter', function() {
                    if (this.disabled) {
                        // You could add a tooltip here if needed
                    }
                });
            });
        });
    </script>
</body>
</html>