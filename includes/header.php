<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
check_admin_auth();

// Determine current page for active menu highlighting
$current_page = basename($_SERVER['PHP_SELF']);

// Determine active module
$event_pages = ['index.php', 'event-form.php', 'catering-items.php', 'stage-items.php', 'invoices.php', 'view-invoice.php', 'edit-invoice.php'];
$rental_pages = ['rentals.php', 'rental-form.php', 'view-rental.php', 'rental-items.php', 'rental-invoice.php'];
$billing_pages = ['billing.php', 'billing-categories.php', 'billing-products.php', 'billing-invoice.php', 'billing-invoices.php'];

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
    <!-- FontAwesome Icon CDN -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Base Stylesheet -->
    <link rel="stylesheet" href="../assets/css/style.css">
    <!-- Template Stylesheet (For previews) -->
    <link rel="stylesheet" href="../assets/css/templates.css">
    <script>
        // Check for saved theme preference or use default (dark)
        const savedTheme = localStorage.getItem('theme') || 'dark';
        document.documentElement.setAttribute('data-theme', savedTheme);
    </script>
</head>
<body>
    <div class="app-container">
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
                <div style="display: flex; gap: 0.5rem;">
                    <a href="../select-module.php" class="btn-logout" style="flex: 1; text-align: center; justify-content: center; background-color: rgba(56, 182, 255, 0.1); color: #38b6ff; border: 1px solid rgba(56, 182, 255, 0.2); text-decoration: none;">
                        <i class="fa-solid fa-layer-group"></i>
                        <span>Modules</span>
                    </a>
                    <form action="../logout.php" method="POST" style="flex: 1; margin: 0;">
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
