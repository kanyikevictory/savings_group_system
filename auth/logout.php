<?php
require_once '../config/constants.php';

// If not logged in, redirect to login
if (!isLoggedIn()) {
    header("Location: login.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logout - Savings Group System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center">
    <div class="max-w-md w-full space-y-8 p-8 bg-white rounded-xl shadow-lg">
        <div class="text-center">
            <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-red-100 mb-4">
                <i class="fas fa-sign-out-alt text-red-600 text-xl"></i>
            </div>
            <h2 class="text-3xl font-bold text-gray-900">
                Logout Confirmation
            </h2>
            <p class="mt-2 text-gray-600">
                Are you sure you want to log out?
            </p>
        </div>
        
        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
            <div class="flex items-center">
                <i class="fas fa-exclamation-triangle text-yellow-600 mr-3"></i>
                <div>
                    <p class="text-sm text-yellow-800">
                        You will need to log in again to access the system.
                    </p>
                </div>
            </div>
        </div>

        <!-- User Info -->
        <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
            <div class="flex items-center">
                <div class="flex-shrink-0 h-10 w-10 bg-emerald-100 rounded-full flex items-center justify-center">
                    <span class="text-emerald-600 font-semibold">
                        <?php echo strtoupper(substr($_SESSION['full_name'], 0, 1)); ?>
                    </span>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-900"><?php echo $_SESSION['full_name']; ?></p>
                    <p class="text-xs text-gray-500"><?php echo ucfirst($_SESSION['role']); ?></p>
                </div>
            </div>
        </div>

        <div class="flex space-x-4">
            <a href="<?php 
                // Determine where to go back based on role
                echo $_SESSION['role'] === 'admin' ? '../admin/dashboard.php' : '../member/dashboard.php';
            ?>" 
               class="flex-1 bg-gray-200 text-gray-800 px-4 py-3 rounded-lg hover:bg-gray-300 text-center font-medium">
                <i class="fas fa-arrow-left mr-2"></i>
                Go Back
            </a>
            
            <a href="process_logout.php" 
               class="flex-1 bg-red-600 text-white px-4 py-3 rounded-lg hover:bg-red-700 text-center font-medium">
                <i class="fas fa-sign-out-alt mr-2"></i>
                Yes, Logout
            </a>
        </div>
        
        <div class="text-center text-sm text-gray-500">
            <p>Session active since: <?php echo date('M d, Y H:i', strtotime($_SESSION['login_time'] ?? date('Y-m-d H:i:s'))); ?></p>
        </div>
    </div>
</body>
</html>