<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
check_admin_auth();

// Determine current page for active menu highlighting
$current_page = basename($_SERVER['PHP_SELF']);

// Determine active module
$event_pages = ['index.php', 'event-form.php', 'catering-items.php', 'stage-items.php', 'invoices.php', 'view-invoice.php', 'edit-invoice.php'];
$rental_pages = ['rentals.php', 'rental-form.php', 'view-rental.php', 'rental-items.php', 'rental-invoice.php'];
$billing_pages = ['billing.php', 'barcode-billing.php', 'billing-categories.php', 'billing-products.php', 'billing-invoice.php', 'billing-invoices.php', 'billing-cart.php', 'register-sessions.php', 'register-history.php', 'pos-returns.php', 'return-receipt.php'];

if (isset($_GET['module'])) {
    $_SESSION['current_module'] = $_GET['module'];
} elseif (in_array($current_page, $event_pages)) {
    $_SESSION['current_module'] = 'event';
} elseif (in_array($current_page, $rental_pages)) {
    $_SESSION['current_module'] = 'rental';
} elseif (in_array($current_page, $billing_pages)) {
    $_SESSION['current_module'] = 'billing';
}

$current_module = $_SESSION['current_module'] ?? 'event';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orange Events - Management Panel</title>
    <!-- PWA Manifest & Service Worker -->
    <link rel="manifest" href="../manifest.json" crossorigin="use-credentials">
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('../sw.js')
                    .then(reg => console.log('Service Worker registered', reg))
                    .catch(err => console.error('Service Worker registration failed', err));
            });
        }
    </script>
    <!-- FontAwesome Icon CDN -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Base Stylesheet -->
    <link rel="stylesheet" href="../assets/css/style.css">
    <!-- Template Stylesheet (For previews) -->
    <link rel="stylesheet" href="../assets/css/templates.css">
    <style>
        /* Collapsible Sidebar Styles */
        .sidebar {
            transition: transform var(--transition-normal);
        }
        
        .main-content {
            transition: margin-left var(--transition-normal);
        }
        
        .sidebar-toggle-btn {
            position: fixed;
            top: 20px;
            left: 205px;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background-color: var(--bg-card);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            z-index: 1001;
            box-shadow: var(--box-shadow);
            transition: left var(--transition-normal), transform var(--transition-normal), background-color var(--transition-fast);
            padding: 0;
            outline: none;
        }
        
        .sidebar-toggle-btn:hover {
            background-color: var(--accent-color);
            color: white;
            border-color: var(--accent-color);
        }
        
        /* Collapsed state rules */
        .sidebar-collapsed .sidebar {
            transform: translateX(-220px);
        }
        
        .sidebar-collapsed .main-content {
            margin-left: 0;
        }
        
        .sidebar-collapsed .sidebar-toggle-btn {
            left: 15px;
        }

        /* Prevent layout flash on initial page load */
        .sidebar-collapsed-init .sidebar {
            transform: translateX(-220px) !important;
            transition: none !important;
        }
        
        .sidebar-collapsed-init .main-content {
            margin-left: 0 !important;
            transition: none !important;
        }
        
        .sidebar-collapsed-init .sidebar-toggle-btn {
            left: 15px !important;
            transition: none !important;
        }
    </style>
    <script>
        // Check for saved theme preference or use default (dark)
        const savedTheme = localStorage.getItem('theme') || 'dark';
        document.documentElement.setAttribute('data-theme', savedTheme);

        function toggleSidebar() {
            const container = document.getElementById('appContainer');
            const icon = document.getElementById('sidebarToggleIcon');
            if (!container) return;
            
            container.classList.toggle('sidebar-collapsed');
            const isCollapsed = container.classList.contains('sidebar-collapsed');
            localStorage.setItem('sidebar_collapsed', isCollapsed ? 'true' : 'false');
            
            if (icon) {
                icon.className = isCollapsed ? 'fa-solid fa-chevron-right' : 'fa-solid fa-chevron-left';
            }
        }

        // Apply saved sidebar preference immediately to avoid layout flash
        (function() {
            const savedCollapsed = localStorage.getItem('sidebar_collapsed');
            if (savedCollapsed === 'true') {
                document.documentElement.classList.add('sidebar-collapsed-init');
            }
        })();

        // Match classes on DOM load
        document.addEventListener('DOMContentLoaded', () => {
            const container = document.getElementById('appContainer');
            const icon = document.getElementById('sidebarToggleIcon');
            
            if (document.documentElement.classList.contains('sidebar-collapsed-init')) {
                if (container) {
                    container.classList.add('sidebar-collapsed');
                }
                if (icon) {
                    icon.className = 'fa-solid fa-chevron-right';
                }
                document.documentElement.classList.remove('sidebar-collapsed-init');
            }
        });
    </script>
</head>
<body>
    <div class="app-container" id="appContainer">
        <!-- Sidebar Toggle Button -->
        <button type="button" class="sidebar-toggle-btn" id="sidebarToggleBtn" onclick="toggleSidebar()" title="Toggle Sidebar">
            <i class="fa-solid fa-chevron-left" id="sidebarToggleIcon"></i>
        </button>
        <!-- Sidebar Navigation -->
        <aside class="sidebar">
            <div class="brand">
                <a href="../select-module.php" style="text-decoration: none;">
                    <div class="brand-logo" style="display: flex; align-items: center; gap: 0.5rem;">
                        <img src="../assets/images/logo.png" alt="Orange Events Logo" style="height: 36px; width: auto;">
                        <span style="color: var(--text-primary); font-weight: 800; font-size: 1.35rem; letter-spacing: 0.05em;">
                            <?= $current_module === 'event' ? 'Events' : ($current_module === 'rental' ? 'Rentals' : 'POS Billing') ?>
                        </span>
                    </div>
                </a>
            </div>
            
            <nav class="nav-container">
                <ul class="nav-links">
                    <?php if ($current_module === 'event'): ?>
                        <li class="nav-item <?= $current_page == 'index.php' ? 'active' : '' ?>">
                            <a href="index.php">
                                <i class="fa-solid fa-chart-pie"></i>
                                <span>Dashboard</span>
                            </a>
                        </li>
                        <li class="nav-item <?= $current_page == 'event-form.php' ? 'active' : '' ?>">
                            <a href="event-form.php">
                                <i class="fa-solid fa-calendar-check"></i>
                                <span>New Booking</span>
                            </a>
                        </li>
                        <li class="nav-item <?= $current_page == 'catering-items.php' ? 'active' : '' ?>">
                            <a href="catering-items.php">
                                <i class="fa-solid fa-utensils"></i>
                                <span>Catering Dishes</span>
                            </a>
                        </li>
                        <li class="nav-item <?= $current_page == 'stage-items.php' ? 'active' : '' ?>">
                            <a href="stage-items.php">
                                <i class="fa-solid fa-holly-berry"></i>
                                <span>Stage Work</span>
                            </a>
                        </li>
                        <li class="nav-item <?= in_array($current_page, ['invoices.php', 'view-invoice.php', 'edit-invoice.php']) ? 'active' : '' ?>">
                            <a href="invoices.php">
                                <i class="fa-solid fa-file-invoice-dollar"></i>
                                <span>Invoices</span>
                            </a>
                        </li>
                    <?php endif; ?>
                    
                    <?php if ($current_module === 'rental'): ?>
                        <li class="nav-item <?= in_array($current_page, ['rentals.php','rental-form.php','view-rental.php']) ? 'active' : '' ?>">
                            <a href="rentals.php">
                                <i class="fa-solid fa-hand-holding-box"></i>
                                <span>Rental Orders</span>
                            </a>
                        </li>
                        <li class="nav-item <?= $current_page == 'rental-items.php' ? 'active' : '' ?>">
                            <a href="rental-items.php">
                                <i class="fa-solid fa-boxes-stacked"></i>
                                <span>Rental Catalog</span>
                            </a>
                        </li>
                    <?php endif; ?>
                    
                    <?php if ($current_module === 'billing'): ?>
                        <li class="nav-item <?= $current_page == 'billing.php' ? 'active' : '' ?>">
                            <a href="billing.php">
                                <i class="fa-solid fa-calculator"></i>
                                <span>POS Terminal</span>
                            </a>
                        </li>
                        <li class="nav-item <?= $current_page == 'barcode-billing.php' ? 'active' : '' ?>">
                            <a href="barcode-billing.php">
                                <i class="fa-solid fa-barcode"></i>
                                <span>Barcode Billing</span>
                            </a>
                        </li>
                        <li class="nav-item <?= $current_page == 'billing-categories.php' ? 'active' : '' ?>">
                            <a href="billing-categories.php">
                                <i class="fa-solid fa-folder-tree"></i>
                                <span>Categories</span>
                            </a>
                        </li>
                        <li class="nav-item <?= $current_page == 'billing-products.php' ? 'active' : '' ?>">
                            <a href="billing-products.php">
                                <i class="fa-solid fa-box-open"></i>
                                <span>Products & Catalog</span>
                            </a>
                        </li>
                        <li class="nav-item <?= in_array($current_page, ['billing-invoices.php', 'billing-invoice.php']) ? 'active' : '' ?>">
                            <a href="billing-invoices.php">
                                <i class="fa-solid fa-file-invoice-dollar"></i>
                                <span>POS Invoices</span>
                            </a>
                        </li>
                        <li class="nav-item <?= in_array($current_page, ['pos-returns.php', 'return-receipt.php']) ? 'active' : '' ?>">
                            <a href="pos-returns.php">
                                <i class="fa-solid fa-rotate-left"></i>
                                <span>Returns & Exchanges</span>
                            </a>
                        </li>
                        <li class="nav-item <?= $current_page == 'register-sessions.php' ? 'active' : '' ?>">
                            <a href="register-sessions.php">
                                <i class="fa-solid fa-cash-register"></i>
                                <span>Cash Register</span>
                            </a>
                        </li>
                        <li class="nav-item <?= $current_page == 'register-history.php' ? 'active' : '' ?>">
                            <a href="register-history.php">
                                <i class="fa-solid fa-receipt"></i>
                                <span>Register History</span>
                            </a>
                        </li>
                    <?php endif; ?>
                    
                    <?php if (has_permission('user_manage')): ?>
                    <li class="nav-item <?= $current_page == 'users.php' ? 'active' : '' ?>">
                        <a href="users.php">
                            <i class="fa-solid fa-users-gear"></i>
                            <span>User Manager</span>
                        </a>
                    </li>
                    <?php endif; ?>
                    
                    <li class="nav-item <?= $current_page == 'migrations.php' ? 'active' : '' ?>">
                        <a href="migrations.php">
                            <i class="fa-solid fa-database"></i>
                            <span>Migrations</span>
                        </a>
                    </li>
                    
                    <li class="nav-item <?= $current_page == 'settings.php' ? 'active' : '' ?>">
                        <a href="settings.php">
                            <i class="fa-solid fa-gears"></i>
                            <span>Settings</span>
                        </a>
                    </li>
                </ul>
            </nav>
            
            <div class="sidebar-footer">
                <!-- Theme Switcher -->
                <div class="theme-switch-wrapper">
                    <div class="theme-switch" id="themeSwitch">
                        <div class="theme-switch-indicator"></div>
                        <button type="button" class="theme-switch-option active" data-theme="dark" title="Dark Theme">
                            <i class="fa-solid fa-moon"></i>
                            <span>Dark</span>
                        </button>
                        <button type="button" class="theme-switch-option" data-theme="light" title="Light Theme">
                            <i class="fa-solid fa-sun"></i>
                            <span>Light</span>
                        </button>
                    </div>
                </div>

                <div class="user-info">
                    <div class="user-avatar">
                        <?= strtoupper(substr($_SESSION['admin_username'], 0, 1)) ?>
                    </div>
                    <div class="user-details">
                        <h4><?= h(ucfirst($_SESSION['admin_username'])) ?></h4>
                        <p><?= h(ucfirst($_SESSION['admin_role'])) ?></p>
                    </div>
                </div>
                <div style="display: flex; flex-direction: column; gap: 0.4rem;">
                    <a href="../select-module.php" class="btn-logout" style="text-align: center; justify-content: center; background-color: rgba(56, 182, 255, 0.1); color: #38b6ff; border: 1px solid rgba(56, 182, 255, 0.2); text-decoration: none;">
                        <i class="fa-solid fa-layer-group"></i>
                        <span>Modules</span>
                    </a>
                    <form action="../logout.php" method="POST" style="margin: 0;">
                        <button type="submit" class="btn-logout" style="width: 100%; justify-content: center;">
                            <i class="fa-solid fa-right-from-bracket"></i>
                            <span>Logout</span>
                        </button>
                    </form>
                </div>
            </div>
        </aside>
        
        <!-- Main Content Area Wrapper -->
        <main class="main-content">
