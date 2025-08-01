<?php
// Enable error reporting for debugging (REMOVE THIS IN PRODUCTION)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is logged in
$is_logged_in = isset($_SESSION['user_id']) || isset($_SESSION['employee_id']);
$current_page = basename($_SERVER['PHP_SELF']);

// Get user role from session (don't default to 'customer' for employees)
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : null;

// Define strict role permissions - FIXED PERMISSION KEYS
// Define strict role permissions - FIXED PERMISSION KEYS
$role_permissions = [
    'teller' => [
        'dashboard' => true,
        'transactions' => true,
        'transaction_processing' => true
    ],
    'accounts' => [
        'dashboard' => true,
        'my_accounts' => true,
        'account_management' => true,
        'transactions' => true,
        'transaction_processing' => true,
        'reports' => true
    ],
    'credit_card' => [
        'dashboard' => true,
        'credit_card_management' => true
    ],
    'loan' => [
        'dashboard' => true,
        'loan_management' => true
    ],
    'manager' => [
        'all' => true,
        'employee_attendance' => true  // Specific permission for employee attendance
    ]
];

// // Add this right after getting the user_role
// echo "<div style='background:#fff;color:#000;padding:10px;position:fixed;top:0;right:0;z-index:9999;font-size:12px;'>
//       Employee ID: " . ($_SESSION['employee_id'] ?? 'Not set') . "<br>
//       Current Role: '" . htmlspecialchars($user_role ?? 'NULL') . "'<br>
//       Role from session: '" . ($_SESSION['role'] ?? 'Not set') . "'<br>
//       Is logged in: " . ($is_logged_in ? 'Yes' : 'No') . "<br>
//       Permissions: " . print_r($role_permissions[$user_role] ?? ['No permissions'], true) . "
//       </div>";

// Function to check permissions
if (!function_exists('has_permission')) {
function has_permission($role, $permission) {
    global $role_permissions;
    
    // Manager has all permissions
    if ($role === 'manager') return true;
    
    // Check if role exists and has the permission
    if (isset($role_permissions[$role])) {
        // Check for wildcard 'all' permission
        if (isset($role_permissions[$role]['all']) && $role_permissions[$role]['all']) {
            return true;
        }
        // Check specific permission
        return isset($role_permissions[$role][$permission]) && $role_permissions[$role][$permission];
    }
    
    return false;
}
}
// Only show sidebar if logged in and has a valid role
if ($is_logged_in && $user_role): 
   
?>
<style>
/* Enhanced sidebar and main content adjustment styles */
.sidebar {
    position: fixed;
    left: -250px; /* Start hidden on mobile */
    top: ;
    width: 250px;
    height: 100vh;
    background-color: #2c3e50;
    transition: left 0.3s ease;
    z-index: 1000;
    overflow-y: auto;
}

.sidebar.active {
    left: 0;
}

.main-content {
    margin-left: 0;
    transition: margin-left 0.3s ease;
    min-height: 100vh;
    padding: 20px;
}

/* Desktop styles */
@media (min-width: 769px) {
    .sidebar {
        left: 0; /* Always visible on desktop */
    }
    
    .main-content {
        margin-left: 250px; /* Default margin for sidebar width */
    }
    
    .sidebar.collapsed {
        left: -250px; /* Hidden when collapsed */
    }
    
    .sidebar.collapsed + .main-content,
    .main-content.sidebar-collapsed {
        margin-left: 0; /* No margin when sidebar is collapsed */
    }
}

/* Mobile styles */
@media (max-width: 768px) {
    .main-content {
        margin-left: 0 !important; /* Always no margin on mobile */
    }
    
    .sidebar-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.5);
        z-index: 999;
        opacity: 0;
        visibility: hidden;
        transition: all 0.3s ease;
    }
    
    .sidebar-overlay.active {
        opacity: 1;
        visibility: visible;
    }
}

/* Sidebar menu styles */
.sidebar-menu {
    list-style: none;
    padding: 0;
    margin: 0;
}

.sidebar-menu li {
    border-bottom: 1px solid #34495e;
}

.sidebar-menu a {
    display: flex;
    align-items: center;
    padding: 15px 20px;
    color: #ecf0f1;
    text-decoration: none;
    transition: background-color 0.3s ease;
}

.sidebar-menu a:hover {
    background-color: #34495e;
}

.sidebar-menu li.active > a {
    background-color: #3498db;
}

.sidebar-menu i {
    width: 20px;
    margin-right: 10px;
}

.dropdown-icon {
    margin-left: auto !important;
    transition: transform 0.3s ease;
}

.menu-dropdown.active .dropdown-icon {
    transform: rotate(180deg);
}

.submenu {
    list-style: none;
    padding: 0;
    margin: 0;
    max-height: 0;
    overflow: hidden;
    background-color: #34495e;
    transition: max-height 0.3s ease;
}

.menu-dropdown.active .submenu {
    max-height: 200px;
}

.submenu li a {
    padding-left: 50px;
    font-size: 14px;
}
</style>

<!-- Sidebar overlay for mobile -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<div class="sidebar" id="sidebar">
    <ul class="sidebar-menu">
        <!-- Dashboard - Available to all roles -->
        <li class="<?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>">
            <a href="dashboard">
                <i class="fas fa-tachometer-alt"></i>
                <span>Dashboard</span>
            </a>
        </li>
        
        <!-- Accounts Section - Only for accounts and manager -->
        <?php if (has_permission($user_role, 'my_accounts')): ?>
        <li class="menu-dropdown <?php echo (in_array($current_page, ['accounts', 'account_management'])) ? 'active' : ''; ?>">
            <a href="#">
                <i class="fas fa-wallet"></i>
                <span>Accounts</span>
                <i class="fas fa-chevron-down dropdown-icon"></i>
            </a>
            <ul class="submenu">
                <li><a href="customer_management">My Accounts</a></li>
                <?php if (has_permission($user_role, 'account_management')): ?>
                <li><a href="account_management">Account Types</a></li>
                <?php endif; ?>
            </ul>
        </li>
        <?php endif; ?>
        
        <!-- Transactions Section - For teller, accounts, and manager -->
        <?php if (has_permission($user_role, 'transactions')): ?>
        <li class="menu-dropdown <?php echo (in_array($current_page, ['transactions', 'transfer'])) ? 'active' : ''; ?>">
            <a href="#">
                <i class="fas fa-exchange-alt"></i>
                <span>Transactions</span>
                <i class="fas fa-chevron-down dropdown-icon"></i>
            </a>
            <ul class="submenu">
                <li><a href="transactions">Transaction History</a></li>
                <?php if (has_permission($user_role, 'transaction_processing')): ?>
                <li><a href="transaction_processing">Process Transactions</a></li>
                <?php endif; ?>
            </ul>
        </li>
        <?php endif; ?>
        
        <!-- Credit Card Management - Only for credit_card and manager -->
        <?php if (has_permission($user_role, 'credit_card_management')): ?>  <!-- ✅ Fixed permission key -->
        <li class="<?php echo ($current_page == 'credit_card.php') ? 'active' : ''; ?>">
            <a href="credit_card">
                <i class="fas fa-credit-card"></i>
                <span>Credit Card Management</span>
            </a>
        </li>
        <?php endif; ?>
        
        <!-- Loan Department - Only for loan and manager -->
        <?php if (has_permission($user_role, 'loan_management')): ?>  <!-- ✅ Fixed permission key -->
        <li class="<?php echo ($current_page == 'loan_department.php') ? 'active' : ''; ?>">
            <a href="loan_department">
                <i class="fas fa-hand-holding-usd"></i>
                <span>Loan Department</span>
            </a>
        </li>
        <?php endif; ?>
        
        <!-- Reports Section - Only for accounts and manager -->
        <?php if (has_permission($user_role, 'reports')): ?>
        <li class="menu-dropdown <?php echo (in_array($current_page, ['reports', 'budgets.php'])) ? 'active' : ''; ?>">
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
        <?php endif; ?>
        
        <!-- Settings - Available to all logged in users -->
        <li class="<?php echo ($current_page == 'settings.php') ? 'active' : ''; ?>">
            <a href="settings">
                <i class="fas fa-cog"></i>
                <span>Settings</span>
            </a>
        </li>
    </ul>
    <!-- Employee Attendance - Only for manager -->
<?php if (has_permission($user_role, 'employee_attendance')): ?>
<li class="<?php echo ($current_page == 'employee_attendance.php') ? 'active' : ''; ?>">
    <a href="employee_attendance">
        <i class="fas fa-user-clock"></i> <!-- Using a clock with user icon for attendance -->
        <span>Employee Attendance</span>
    </a>
</li>
<?php endif; ?>
</div>

<script>
    // Enhanced sidebar functionality with main content adjustment
    document.addEventListener('DOMContentLoaded', function() {
        const sidebar = document.getElementById('sidebar');
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        const mainContent = document.querySelector('.main-content');
        
        // Initialize sidebar state based on screen size
        function initializeSidebar() {
            if (window.innerWidth > 768) {
                sidebar.classList.add('active');
                sidebar.classList.remove('collapsed');
                if (sidebarOverlay) sidebarOverlay.classList.remove('active');
                if (mainContent) mainContent.classList.remove('sidebar-collapsed');
            } else {
                sidebar.classList.remove('active');
                if (sidebarOverlay) sidebarOverlay.classList.remove('active');
            }
        }
        
        // Initialize on load
        initializeSidebar();
        
        // Toggle sidebar
        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', function(e) {
                e.preventDefault();
                
                if (window.innerWidth > 768) {
                    // Desktop behavior - collapse/expand sidebar
                    sidebar.classList.toggle('collapsed');
                    if (mainContent) {
                        mainContent.classList.toggle('sidebar-collapsed');
                    }
                } else {
                    // Mobile behavior - slide in/out sidebar
                    sidebar.classList.toggle('active');
                    if (sidebarOverlay) {
                        sidebarOverlay.classList.toggle('active');
                    }
                }
            });
        }
        
        // Close sidebar when clicking overlay (mobile only)
        if (sidebarOverlay) {
            sidebarOverlay.addEventListener('click', function() {
                sidebar.classList.remove('active');
                sidebarOverlay.classList.remove('active');
            });
        }
        
        // Close sidebar when clicking outside (enhanced)
        document.addEventListener('click', function(event) {
            if (window.innerWidth <= 768) {
                if (sidebarToggle && !sidebar.contains(event.target) && 
                    !sidebarToggle.contains(event.target) && 
                    sidebar.classList.contains('active')) {
                    sidebar.classList.remove('active');
                    if (sidebarOverlay) sidebarOverlay.classList.remove('active');
                }
            }
        });
        
        // Toggle dropdown menus
        document.querySelectorAll('.menu-dropdown > a').forEach(item => {
            item.addEventListener('click', function(e) {
                e.preventDefault();
                const parent = this.parentElement;
                
                // Toggle current dropdown
                parent.classList.toggle('active');
                
                // Close other open dropdowns
                document.querySelectorAll('.menu-dropdown').forEach(dropdown => {
                    if (dropdown !== parent) {
                        dropdown.classList.remove('active');
                    }
                });
            });
        });
        
        // Auto-close dropdowns and sidebar on mobile when navigating
        document.querySelectorAll('.sidebar-menu a:not(.menu-dropdown > a)').forEach(link => {
            link.addEventListener('click', function() {
                if (window.innerWidth <= 768) {
                    // Close dropdowns
                    document.querySelectorAll('.menu-dropdown').forEach(dropdown => {
                        dropdown.classList.remove('active');
                    });
                    // Close sidebar
                    sidebar.classList.remove('active');
                    if (sidebarOverlay) sidebarOverlay.classList.remove('active');
                }
            });
        });
        
        // Handle window resize
        window.addEventListener('resize', function() {
            initializeSidebar();
        });
    });
</script>
<?php endif; ?>