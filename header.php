<?php
// Move session_start() to the very top of header.php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
    
    // Regenerate session ID periodically for security
    if (!isset($_SESSION['created'])) {
        $_SESSION['created'] = time();
    } else if (time() - $_SESSION['created'] > 1800) { // 30 minutes
        session_regenerate_id(true);
        $_SESSION['created'] = time();
    }
}

$current_page = basename($_SERVER['PHP_SELF']);
$is_logged_in = isset($_SESSION['user_id']) || isset($_SESSION['employee_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bank Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            margin: 0;
            padding: 0;
            overflow-x: hidden;
        }

        /* Header Styles */
        header {
            position: fixed;
            top: 0;
            width: 100%;
            z-index: 1000;
            transition: all 0.3s;
        }

        /* Sidebar Styles - Only shown when logged in */
        .sidebar {
            width: 250px;
            height: calc(100vh - 64px);
            background: #2c3e50;
            color: #fff;
            position: fixed;
            left: -250px;
            top: 64px;
            z-index: 900;
            overflow-y: auto;
            transition: all 0.3s ease;
        }

        <?php if ($is_logged_in): ?>
        .sidebar.active {
            left: 0;
        }
        <?php endif; ?>

        .sidebar-menu {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .sidebar-menu li {
            position: relative;
        }

        .sidebar-menu li a {
            display: flex;
            align-items: center;
            padding: 15px 20px;
            color: #ecf0f1;
            text-decoration: none;
            transition: all 0.3s;
        }

        .sidebar-menu li a:hover {
            background: #34495e;
            color: #fff;
        }

        .sidebar-menu li.active > a {
            background: #3498db;
            color: #fff;
        }

        .sidebar-menu i {
            margin-right: 10px;
            font-size: 18px;
        }

        .dropdown-icon {
            margin-left: auto;
            transition: transform 0.3s;
        }

        .menu-dropdown.active .dropdown-icon {
            transform: rotate(180deg);
        }

        .submenu {
            list-style: none;
            padding-left: 20px;
            background: #34495e;
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease-out;
        }

        .menu-dropdown.active .submenu {
            max-height: 500px;
        }

        .submenu li a {
            padding: 12px 20px;
            font-size: 14px;
        }

        /* Main Content */
        .main-content {
            margin-top: 64px;
            padding: 20px;
            transition: all 0.3s;
        }

        <?php if ($is_logged_in): ?>
        .sidebar.active + .main-content {
            margin-left: 250px;
        }
        <?php endif; ?>

        /* Menu Toggle Button - Only shown when logged in */
        .menu-toggle {
            display: none;
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            margin-right: 15px;
        }

        <?php if ($is_logged_in): ?>
        .menu-toggle {
            display: block;
        }
        <?php endif; ?>

        /* Right-click prevention */
        body.no-right-click {
            -webkit-touch-callout: none;
            -webkit-user-select: none;
            -khtml-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
        }

        /* Fullscreen prompt */
        #fullscreen-prompt-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.95);
            color: white;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            z-index: 10001;
            text-align: center;
            font-family: 'Inter', sans-serif;
        }

        body.fullscreen-required .main-content,
        body.fullscreen-required header {
            display: none !important;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            <?php if ($is_logged_in): ?>
            .sidebar.active + .main-content {
                margin-left: 0;
                position: relative;
                left: 250px;
                width: calc(100% - 250px);
            }
            <?php endif; ?>
        }
    </style>
    <script>
        // Your existing fullscreen script remains exactly the same
        // ... [all your existing JavaScript code] ...
    </script>
</head>
<body oncontextmenu="return false;" class="no-right-click">

    <header class="bg-gray-800 text-white p-4 shadow-md">
        <div class="container mx-auto flex justify-between items-center">
            <div class="flex items-center">
                <?php if ($is_logged_in): ?>
                <button class="menu-toggle" id="sidebarToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <?php endif; ?>
                <h1 class="text-2xl font-bold">Bank Management System</h1>
            </div>
            <nav>
                <?php if ($is_logged_in): ?>
                <a href="logout" class="bg-red-600 hover:bg-red-700 text-white font-semibold py-2 px-4 rounded-md shadow-md transition duration-300 ease-in-out">
                    Logout
                </a>
                <?php else: ?>
                <a href="bank_login" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded-md shadow-md transition duration-300 ease-in-out">
                    Login
                </a>
                <?php endif; ?>
            </nav>
        </div>
    </header>

    <?php if ($is_logged_in): ?>
    <!-- Sidebar - Only shown when logged in -->
    <div class="sidebar" id="sidebar">
        <ul class="sidebar-menu">
            <li class="<?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>">
                <a href="dashboard">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            
            <li class="menu-dropdown <?php echo (in_array($current_page, ['accounts.php', 'account_types.php'])) ? 'active' : ''; ?>">
                <a href="#">
                    <i class="fas fa-wallet"></i>
                    <span>Accounts</span>
                    <i class="fas fa-chevron-down dropdown-icon"></i>
                </a>
                <ul class="submenu">
                    <li><a href="customer_management">My Accounts</a></li>
                    <li><a href="account_management">Account Types</a></li>
                </ul>
            </li>
            
            <li class="menu-dropdown <?php echo (in_array($current_page, ['transactions.php', 'transfer.php'])) ? 'active' : ''; ?>">
                <a href="#">
                    <i class="fas fa-exchange-alt"></i>
                    <span>Transactions</span>
                    <i class="fas fa-chevron-down dropdown-icon"></i>
                </a>
                <ul class="submenu">
                    <li><a href="transactions">Transaction History</a></li>
                    <li><a href="transactions">Transfer Funds</a></li>
                </ul>
            </li>
            
            <?php if (isset($_SESSION['employee_id'])): ?>
            <li class="<?php echo ($current_page == 'customers.php') ? 'active' : ''; ?>">
                <a href="customer_management">
                    <i class="fas fa-users"></i>
                    <span>Customer Management</span>
                </a>
            </li>
            <?php endif; ?>
            
            <li class="menu-dropdown <?php echo (in_array($current_page, ['reports.php', 'budgets.php'])) ? 'active' : ''; ?>">
                <a href="#">
                    <i class="fas fa-chart-pie"></i>
                    <span>Reports</span>
                    <i class="fas fa-chevron-down dropdown-icon"></i>
                </a>
                <ul class="submenu">
                    <li><a href="reports">Financial Reports</a></li>
                    <li><a href="budgets">Budget Analysis</a></li>
                </ul>
            </li>
            
            <li class="<?php echo ($current_page == 'settings.php') ? 'active' : ''; ?>">
                <a href="settings">
                    <i class="fas fa-cog"></i>
                    <span>Settings</span>
                </a>
            </li>
        </ul>
    </div>
    <?php endif; ?>

    <div class="main-content" id="mainContent">
        <!-- Your page content will be inserted here -->
    
    <?php if ($is_logged_in): ?>
    <script>
        // Sidebar toggle functionality - Only included when logged in
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('sidebar');
            const sidebarToggle = document.getElementById('sidebarToggle');
            const mainContent = document.getElementById('mainContent');
            
            // Initialize sidebar state based on screen size
            if (window.innerWidth > 768) {
                sidebar.classList.add('active');
            }
            
            // Toggle sidebar
            sidebarToggle.addEventListener('click', function() {
                sidebar.classList.toggle('active');
            });
            
            // Close sidebar when clicking outside
            document.addEventListener('click', function(event) {
                if (!sidebar.contains(event.target) && 
                    !sidebarToggle.contains(event.target) && 
                    sidebar.classList.contains('active')) {
                    sidebar.classList.remove('active');
                }
            });
            
            // Toggle dropdown menus
            document.querySelectorAll('.menu-dropdown > a').forEach(item => {
                item.addEventListener('click', function(e) {
                    if (window.innerWidth > 768) {
                        e.preventDefault();
                        const parent = this.parentElement;
                        parent.classList.toggle('active');
                        
                        // Close other open dropdowns
                        document.querySelectorAll('.menu-dropdown').forEach(dropdown => {
                            if (dropdown !== parent) {
                                dropdown.classList.remove('active');
                            }
                        });
                    }
                });
            });
            
            // Auto-close dropdowns on mobile when navigating
            if (window.innerWidth <= 768) {
                document.querySelectorAll('.sidebar-menu a').forEach(link => {
                    link.addEventListener('click', function() {
                        document.querySelectorAll('.menu-dropdown').forEach(dropdown => {
                            dropdown.classList.remove('active');
                        });
                        sidebar.classList.remove('active');
                    });
                });
            }
            
            // Make sidebar responsive
            window.addEventListener('resize', function() {
                if (window.innerWidth > 768) {
                    sidebar.classList.add('active');
                } else {
                    sidebar.classList.remove('active');
                }
            });
        });
    </script>
    <?php endif; ?>
</body>
</html>