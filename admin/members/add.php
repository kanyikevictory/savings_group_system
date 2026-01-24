<?php
require_once '../../config/constants.php';
require_once '../../config/database.php';
requireAdmin();

$db = getDB();
$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone_number = trim($_POST['phone_number'] ?? '');
    $join_date = $_POST['join_date'] ?? date('Y-m-d');
    $member_code = trim($_POST['member_code'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Validation
    if (empty($full_name) || empty($email) || empty($password)) {
        $error = "Full name, email and password are required";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email address format";
    } else {
        try {
            $db->beginTransaction();
            
            // Check if email already exists
            $stmt = $db->prepare("SELECT id FROM users WHERE email = :email");
            $stmt->execute([':email' => $email]);
            if ($stmt->fetch()) {
                throw new Exception("Email address already registered");
            }
            
            // Check if member code already exists (if provided)
            if ($member_code) {
                $stmt = $db->prepare("SELECT id FROM members WHERE member_code = :member_code");
                $stmt->execute([':member_code' => $member_code]);
                if ($stmt->fetch()) {
                    throw new Exception("Member code already exists");
                }
            }
            
            // Generate member code if not provided
            if (empty($member_code)) {
                $member_code = 'M' . str_pad(rand(1, 99999), 5, '0', STR_PAD_LEFT);
                
                // Ensure unique
                $stmt = $db->prepare("SELECT id FROM members WHERE member_code = :member_code");
                $stmt->execute([':member_code' => $member_code]);
                while ($stmt->fetch()) {
                    $member_code = 'M' . str_pad(rand(1, 99999), 5, '0', STR_PAD_LEFT);
                    $stmt->execute([':member_code' => $member_code]);
                }
            }
            
            // Create user account
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            
            $stmt = $db->prepare("
                INSERT INTO users (email, password_hash, full_name, phone_number, role) 
                VALUES (:email, :password_hash, :full_name, :phone_number, 'member')
            ");
            
            $stmt->execute([
                ':email' => $email,
                ':password_hash' => $password_hash,
                ':full_name' => $full_name,
                ':phone_number' => $phone_number ?: null
            ]);
            
            $user_id = $db->lastInsertId();
            
            // Create member record
            $stmt = $db->prepare("
                INSERT INTO members (user_id, member_code, join_date, status) 
                VALUES (:user_id, :member_code, :join_date, 'active')
            ");
            
            $stmt->execute([
                ':user_id' => $user_id,
                ':member_code' => $member_code,
                ':join_date' => $join_date
            ]);
            
            $member_id = $db->lastInsertId();
            
            logActivity('Add Member', "Added new member: $full_name (ID: $member_id, Code: $member_code)");
            
            $db->commit();
            
            $_SESSION['success'] = "Member '$full_name' added successfully!";
            header("Location: index.php");
            exit();
        } catch (Exception $e) {
            $db->rollBack();
            $error = "Error adding member: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Member - Savings Group System</title>
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
            <h1 class="text-lg font-semibold">Add Member</h1>
        </header>

        <main class="p-6">
            <div class="max-w-2xl mx-auto">
                <div class="mb-8">
                    <h1 class="text-2xl font-bold text-gray-900">Add New Member</h1>
                    <p class="text-gray-600">Register a new member to the savings group</p>
                </div>

                <?php if ($error): ?>
                    <div class="mb-6 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <!-- Add Member Form -->
                <div class="bg-white rounded-xl shadow p-6">
                    <form method="POST" action="">
                        <div class="space-y-6">
                            <!-- Personal Information Section -->
                            <div>
                                <h3 class="text-lg font-semibold text-gray-900 mb-4">Personal Information</h3>
                                
                                <!-- Full Name -->
                                <div class="mb-4">
                                    <label class="block text-sm font-medium text-gray-700 mb-2">
                                        Full Name <span class="text-red-500">*</span>
                                    </label>
                                    <input type="text" name="full_name" required maxlength="255"
                                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500"
                                           placeholder="John Doe"
                                           value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>">
                                </div>

                                <!-- Email -->
                                <div class="mb-4">
                                    <label class="block text-sm font-medium text-gray-700 mb-2">
                                        Email Address <span class="text-red-500">*</span>
                                    </label>
                                    <input type="email" name="email" required maxlength="255"
                                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500"
                                           placeholder="john@example.com"
                                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                                </div>

                                <!-- Phone Number -->
                                <div class="mb-4">
                                    <label class="block text-sm font-medium text-gray-700 mb-2">
                                        Phone Number
                                    </label>
                                    <input type="tel" name="phone_number" maxlength="20"
                                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500"
                                           placeholder="+254700000000"
                                           value="<?php echo htmlspecialchars($_POST['phone_number'] ?? ''); ?>">
                                </div>
                            </div>

                            <!-- Member Details Section -->
                            <div>
                                <h3 class="text-lg font-semibold text-gray-900 mb-4">Member Details</h3>
                                
                                <!-- Member Code -->
                                <div class="mb-4">
                                    <label class="block text-sm font-medium text-gray-700 mb-2">
                                        Member Code (Optional)
                                    </label>
                                    <div class="flex items-center">
                                        <input type="text" name="member_code" maxlength="20"
                                               class="flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500"
                                               placeholder="e.g., MEM001"
                                               value="<?php echo htmlspecialchars($_POST['member_code'] ?? ''); ?>">
                                        <button type="button" id="generateCode" 
                                                class="ml-2 bg-gray-200 text-gray-700 px-3 py-2 rounded-lg hover:bg-gray-300 text-sm">
                                            Generate
                                        </button>
                                    </div>
                                    <p class="text-xs text-gray-500 mt-1">
                                        Leave blank to auto-generate a unique member code
                                    </p>
                                </div>

                                <!-- Join Date -->
                                <div class="mb-4">
                                    <label class="block text-sm font-medium text-gray-700 mb-2">
                                        Join Date <span class="text-red-500">*</span>
                                    </label>
                                    <input type="date" name="join_date" required
                                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500"
                                           value="<?php echo htmlspecialchars($_POST['join_date'] ?? date('Y-m-d')); ?>">
                                </div>
                            </div>

                            <!-- Account Security Section -->
                            <div>
                                <h3 class="text-lg font-semibold text-gray-900 mb-4">Account Security</h3>
                                
                                <!-- Password -->
                                <div class="mb-4">
                                    <label class="block text-sm font-medium text-gray-700 mb-2">
                                        Password <span class="text-red-500">*</span>
                                    </label>
                                    <div class="relative">
                                        <input type="password" name="password" required minlength="6" 
                                               id="password"
                                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500"
                                               placeholder="••••••••">
                                        <button type="button" onclick="togglePassword('password')" 
                                                class="absolute right-3 top-2 text-gray-500 hover:text-gray-700">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                    <p class="text-xs text-gray-500 mt-1">
                                        Minimum 6 characters
                                    </p>
                                </div>

                                <!-- Confirm Password -->
                                <div class="mb-4">
                                    <label class="block text-sm font-medium text-gray-700 mb-2">
                                        Confirm Password <span class="text-red-500">*</span>
                                    </label>
                                    <div class="relative">
                                        <input type="password" name="confirm_password" required minlength="6"
                                               id="confirm_password"
                                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500"
                                               placeholder="••••••••">
                                        <button type="button" onclick="togglePassword('confirm_password')" 
                                                class="absolute right-3 top-2 text-gray-500 hover:text-gray-700">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Submit Button -->
                            <div class="pt-4">
                                <button type="submit" 
                                        class="w-full bg-emerald-600 text-white px-4 py-3 rounded-lg hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-emerald-500 font-medium">
                                    <i class="fas fa-user-plus mr-2"></i>
                                    Add New Member
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Quick Stats -->
                <div class="mt-8 grid grid-cols-1 md:grid-cols-2 gap-6">
                    <?php
                    // Get stats
                    $stmt = $db->query("SELECT COUNT(*) FROM members WHERE status = 'active'");
                    $active_members = $stmt->fetchColumn();
                    
                    $stmt = $db->query("SELECT COUNT(*) FROM members");
                    $total_members = $stmt->fetchColumn();
                    ?>
                    
                    <div class="bg-white rounded-xl shadow p-6">
                        <div class="flex items-center">
                            <div class="p-3 rounded-lg bg-emerald-100 text-emerald-600 mr-4">
                                <i class="fas fa-user-check text-2xl"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Active Members</p>
                                <p class="text-2xl font-bold"><?php echo $active_members; ?></p>
                            </div>
                        </div>
                    </div>

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
                </div>

                <!-- Back Link -->
                <div class="mt-8 text-center">
                    <a href="index.php" class="text-emerald-600 hover:text-emerald-800">
                        <i class="fas fa-arrow-left mr-2"></i>
                        Back to Members List
                    </a>
                </div>
            </div>
        </main>
    </div>

    <script>
        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('active');
        }
        
        // Generate member code
        document.getElementById('generateCode').addEventListener('click', function() {
            const code = 'M' + Math.floor(10000 + Math.random() * 90000);
            document.querySelector('input[name="member_code"]').value = code;
        });
        
        // Toggle password visibility
        function togglePassword(inputId) {
            const input = document.getElementById(inputId);
            const icon = input.nextElementSibling.querySelector('i');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
        
        // Set max date to today for join date
        const today = new Date().toISOString().split('T')[0];
        document.querySelector('input[name="join_date"]').max = today;
        
        // Password strength indicator
        document.getElementById('password').addEventListener('input', function() {
            const password = this.value;
            const strength = checkPasswordStrength(password);
            
            // You can add visual feedback here if needed
            console.log('Password strength:', strength);
        });
        
        function checkPasswordStrength(password) {
            let strength = 0;
            if (password.length >= 8) strength++;
            if (/[A-Z]/.test(password)) strength++;
            if (/[a-z]/.test(password)) strength++;
            if (/[0-9]/.test(password)) strength++;
            if (/[^A-Za-z0-9]/.test(password)) strength++;
            return strength;
        }
    </script>
</body>
</html>