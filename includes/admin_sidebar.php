<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Savings Group System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .sidebar { transition: all 0.3s; }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.active { transform: translateX(0); }
        }
    </style>
</head>
<body class="bg-gray-50">
<div class="sidebar fixed inset-y-0 left-0 z-50 w-64 bg-white shadow-lg">
        <div class="p-6">
            <h1 class="text-2xl font-bold text-emerald-600">Savings Group</h1>
            <p class="text-gray-600 text-sm mt-1">Digital Savings Book</p>
        </div>
        
        <nav class="mt-6">
            <div class="px-4 mb-4">
                <p class="text-xs font-semibold text-gray-500 uppercase">Main</p>
            </div>
            
            <a href="../dashboard.php" class="flex items-center px-6 py-3 text-emerald-600 bg-emerald-50 border-r-4 border-emerald-500">
                <i class="fas fa-home mr-3"></i>
                Dashboard
            </a>
            
            <a href="../members/index.php" class="flex items-center px-6 py-3 text-gray-700 hover:bg-gray-100">
                <i class="fas fa-users mr-3"></i>
                Members
            </a>
            
            <a href="../savings/record.php" class="flex items-center px-6 py-3 text-gray-700 hover:bg-gray-100">
                <i class="fas fa-money-bill-wave mr-3"></i>
                Record Savings
            </a>

            <a href="../savings/history.php" class="flex items-center px-6 py-3 text-gray-700 hover:bg-gray-100">
                <i class="fas fa-history mr-3"></i>
                Savings History
            </a>
            
            <div class="px-4 my-4">
                <p class="text-xs font-semibold text-gray-500 uppercase">Cycle Management</p>
            </div>
            
            <a href="../cycles/index.php" class="flex items-center px-6 py-3 text-gray-700 hover:bg-gray-100">
                <i class="fas fa-sync-alt mr-3"></i>
                Cycles
            </a>
            
            <div class="absolute bottom-0 w-full p-6 border-t">
                <div class="flex items-center">
                    <div class="ml-3">
                        <p class="text-sm font-medium text-gray-900"><?php echo $_SESSION['full_name']; ?></p>
                        <p class="text-xs text-gray-500">Administrator</p>
                    </div>
                </div>
                <a href="../../auth/logout.php" class="mt-4 flex items-center text-red-600 hover:text-red-800">
                    <i class="fas fa-sign-out-alt mr-2"></i>
                    Logout
                </a>
            </div>
        </nav>
    </div>
    <script>
        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('active');
        }
        </script>
</body>