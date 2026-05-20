<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
check_admin_auth();

// Determine current page for active menu highlighting
$current_page = basename($_SERVER['PHP_SELF']);
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
</head>
<body>
    <div class="app-container">
        <!-- Sidebar Navigation -->
        <aside class="sidebar">
            <div class="brand">
                <div class="brand-logo" style="display: flex; align-items: center; gap: 0.5rem;">
                    <img src="../assets/images/logo.png" alt="Orange Events Logo" style="height: 36px; width: auto;">
                    <span style="color: #f07c1b; font-weight: 800;">oe</span><span style="color: var(--text-primary); font-weight: 800;">Events</span>
                </div>
            </div>
            
            <nav class="nav-container">
                <ul class="nav-links">
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
                    <li class="nav-item <?= $current_page == 'settings.php' ? 'active' : '' ?>">
                        <a href="settings.php">
                            <i class="fa-solid fa-gears"></i>
                            <span>Settings</span>
                        </a>
                    </li>
                </ul>
            </nav>
            
            <div class="sidebar-footer">
                <div class="user-info">
                    <div class="user-avatar">
                        <?= strtoupper(substr($_SESSION['admin_username'], 0, 1)) ?>
                    </div>
                    <div class="user-details">
                        <h4><?= h(ucfirst($_SESSION['admin_username'])) ?></h4>
                        <p><?= h(ucfirst($_SESSION['admin_role'])) ?></p>
                    </div>
                </div>
                <form action="../logout.php" method="POST">
                    <button type="submit" class="btn-logout">
                        <i class="fa-solid fa-right-from-bracket"></i>
                        <span>Logout</span>
                    </button>
                </form>
            </div>
        </aside>
        
        <!-- Main Content Area Wrapper -->
        <main class="main-content">
