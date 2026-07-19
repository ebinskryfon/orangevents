<?php
require_once __DIR__ . '/includes/auth.php';

// If not logged in, redirect to login page
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orange Events - Select Module</title>
    <!-- PWA Manifest & Service Worker -->
    <link rel="manifest" href="manifest.json">
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('sw.js')
                    .then(reg => console.log('Service Worker registered', reg))
                    .catch(err => console.error('Service Worker registration failed', err));
            });
        }
    </script>
    <!-- FontAwesome CDNs -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Style -->
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            background: radial-gradient(circle at center, #1b263b 0%, #0a0e17 100%);
            padding: 1.5rem;
            font-family: 'Inter', sans-serif;
            color: var(--text-primary);
        }
        
        .container {
            width: 100%;
            max-width: 900px;
            text-align: center;
        }
        
        .header {
            margin-bottom: 3rem;
        }
        
        .logo-container {
            margin-bottom: 1.25rem;
        }
        
        .logo-img {
            height: 70px;
            width: auto;
            display: block;
            margin: 0 auto 1rem auto;
        }
        
        .logo-title {
            font-size: 2.2rem;
            font-weight: 800;
            font-family: 'Outfit', sans-serif;
            letter-spacing: 0.05em;
            line-height: 1.1;
        }
        
        .logo-title span:first-child { color: #f07c1b; }
        .logo-title span:last-child { color: #ffffff; }
        
        .subtitle {
            color: var(--text-secondary);
            font-size: 1.1rem;
            max-width: 500px;
            margin: 0 auto;
        }
        
        .modules-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
            margin-top: 2rem;
        }
        
        .module-card {
            background: rgba(24, 34, 53, 0.6);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: var(--border-radius-lg);
            padding: 3rem 2rem;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.4);
            text-align: center;
            text-decoration: none;
            color: white;
            transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        
        .module-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(255,255,255,0.05) 0%, rgba(255,255,255,0) 100%);
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .module-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 30px 60px rgba(0, 0, 0, 0.5);
            border-color: rgba(240, 124, 27, 0.4);
        }
        
        .module-card:hover::before {
            opacity: 1;
        }
        
        .module-icon {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            margin-bottom: 1.5rem;
            transition: all 0.3s ease;
            position: relative;
            z-index: 1;
        }
        
        .module-card.event .module-icon {
            background: rgba(240, 124, 27, 0.15);
            color: #f07c1b;
            box-shadow: 0 0 20px rgba(240, 124, 27, 0.2);
        }
        
        .module-card.rental .module-icon {
            background: rgba(56, 182, 255, 0.15);
            color: #38b6ff;
            box-shadow: 0 0 20px rgba(56, 182, 255, 0.2);
        }

        .module-card.billing .module-icon {
            background: rgba(46, 213, 115, 0.15);
            color: #2ed573;
            box-shadow: 0 0 20px rgba(46, 213, 115, 0.2);
        }
        
        .module-card:hover .module-icon {
            transform: scale(1.1);
        }
        
        .module-card.event:hover .module-icon {
            background: rgba(240, 124, 27, 0.25);
            box-shadow: 0 0 30px rgba(240, 124, 27, 0.4);
        }
        
        .module-card.rental:hover .module-icon {
            background: rgba(56, 182, 255, 0.25);
            box-shadow: 0 0 30px rgba(56, 182, 255, 0.4);
        }

        .module-card.billing:hover .module-icon {
            background: rgba(46, 213, 115, 0.25);
            box-shadow: 0 0 30px rgba(46, 213, 115, 0.4);
        }
        
        .module-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.75rem;
            font-family: 'Outfit', sans-serif;
            position: relative;
            z-index: 1;
        }
        
        .module-desc {
            font-size: 0.95rem;
            color: var(--text-secondary);
            line-height: 1.5;
            position: relative;
            z-index: 1;
        }
        
        .module-action {
            margin-top: 2rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 600;
            font-size: 0.9rem;
            opacity: 0.8;
            transition: all 0.3s ease;
            position: relative;
            z-index: 1;
        }
        
        .module-card.event .module-action { color: #f07c1b; }
        .module-card.rental .module-action { color: #38b6ff; }
        .module-card.billing .module-action { color: #2ed573; }
        
        .module-card:hover .module-action {
            opacity: 1;
            gap: 0.75rem;
        }
        
        .logout-container {
            margin-top: 3rem;
        }
        
        .logout-btn {
            background: transparent;
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: var(--text-secondary);
            padding: 0.75rem 1.5rem;
            border-radius: var(--border-radius-md);
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }
        
        .logout-btn:hover {
            background: rgba(255, 255, 255, 0.05);
            color: white;
            border-color: rgba(255, 255, 255, 0.2);
        }
        
        @media (max-width: 768px) {
            .modules-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo-container">
                <img src="assets/images/logo.png" alt="Orange Events Logo" class="logo-img">
                <div class="logo-title"><span>ORANGE</span> <span>EVENTS</span></div>
            </div>
            <p class="subtitle">Welcome back, <?= htmlspecialchars(ucfirst($_SESSION['admin_username'])) ?>. Please select the management module you want to access.</p>
        </div>
        
        <div class="modules-grid">
            <!-- Event Management Card -->
            <a href="admin/index.php?module=event" class="module-card event">
                <div class="module-icon">
                    <i class="fa-solid fa-calendar-star"></i>
                </div>
                <h2 class="module-title">Event Management</h2>
                <p class="module-desc">Manage event bookings, catering menus, stage setups, and generate comprehensive invoices for clients.</p>
                <div class="module-action">
                    <span>Enter Module</span>
                    <i class="fa-solid fa-arrow-right"></i>
                </div>
            </a>
            
            <!-- Rental Management Card -->
            <a href="admin/rentals.php?module=rental" class="module-card rental">
                <div class="module-icon">
                    <i class="fa-solid fa-handshake-angle"></i>
                </div>
                <h2 class="module-title">Rental Management</h2>
                <p class="module-desc">Track equipment rental orders, monitor stock availability, manage advance payments, and handle item returns.</p>
                <div class="module-action">
                    <span>Enter Module</span>
                    <i class="fa-solid fa-arrow-right"></i>
                </div>
            </a>

            <!-- POS Billing Card -->
            <a href="admin/billing.php?module=billing" class="module-card billing">
                <div class="module-icon">
                    <i class="fa-solid fa-calculator"></i>
                </div>
                <h2 class="module-title">POS Billing</h2>
                <p class="module-desc">Quick checkout terminal for birthday and event items with size-based variants, discount controls, and UPI payment QR codes.</p>
                <div class="module-action">
                    <span>Enter Module</span>
                    <i class="fa-solid fa-arrow-right"></i>
                </div>
            </a>
        </div>
        
        <div class="logout-container">
            <a href="logout.php" class="logout-btn">
                <i class="fa-solid fa-right-from-bracket"></i>
                <span>Sign Out</span>
            </a>
        </div>
    </div>
</body>
</html>
