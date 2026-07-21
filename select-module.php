<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$db = get_db_connection();

// Fetch live metrics for module cards
$upcoming_events = 0;
$today_pos_sales = 0.00;
$today_pos_orders = 0;
$active_rentals = 0;

try {
    $upcoming_events = (int)$db->query("SELECT COUNT(*) FROM events WHERE event_date >= CURDATE()")->fetchColumn();
} catch (Exception $e) {}

try {
    $stmt_pos = $db->query("SELECT COUNT(*) as count, COALESCE(SUM(final_amount), 0) as total FROM billing_orders WHERE DATE(created_at) = CURDATE()");
    $pos_res = $stmt_pos->fetch();
    $today_pos_orders = (int)$pos_res['count'];
    $today_pos_sales = (float)$pos_res['total'];
} catch (Exception $e) {}

try {
    $active_rentals = (int)$db->query("SELECT COUNT(*) FROM rental_orders WHERE status != 'returned'")->fetchColumn();
} catch (Exception $e) {}

// Check open cash register session
$active_register = null;
try {
    $stmt_reg = $db->query("SELECT * FROM cash_register_sessions WHERE status = 'open' ORDER BY id DESC LIMIT 1");
    $active_register = $stmt_reg->fetch();
} catch (Exception $e) {}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orange Events - Select Management Module</title>
    <!-- PWA Manifest & Service Worker -->
    <link rel="manifest" href="manifest.json" crossorigin="use-credentials">
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('sw.js')
                    .then(reg => console.log('Service Worker registered', reg))
                    .catch(err => console.error('Service Worker registration failed', err));
            });
        }
    </script>
    <!-- FontAwesome Icon CDN -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@400;600;700;800;900&display=swap">
    <!-- Stylesheet -->
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            background: radial-gradient(circle at top center, #1a2536 0%, #0a0e17 80%);
            padding: 2rem 1.5rem;
            font-family: 'Inter', sans-serif;
            color: var(--text-primary);
            position: relative;
            overflow-x: hidden;
        }

        /* Ambient Glow Background Orbs */
        body::before {
            content: '';
            position: absolute;
            top: -10%;
            left: 50%;
            transform: translateX(-50%);
            width: 600px;
            height: 600px;
            background: radial-gradient(circle, rgba(255, 107, 53, 0.12) 0%, rgba(0, 0, 0, 0) 70%);
            pointer-events: none;
            z-index: 0;
        }

        .select-container {
            width: 100%;
            max-width: 1100px;
            text-align: center;
            position: relative;
            z-index: 1;
        }

        .brand-header {
            margin-bottom: 2.5rem;
        }

        .brand-logo-img {
            height: 65px;
            width: auto;
            display: block;
            margin: 0 auto 0.75rem auto;
            filter: drop-shadow(0 4px 15px rgba(255, 107, 53, 0.3));
        }

        .brand-title {
            font-size: 2.2rem;
            font-weight: 900;
            font-family: 'Outfit', sans-serif;
            letter-spacing: 0.04em;
            line-height: 1.1;
            margin-bottom: 0.35rem;
        }
        .brand-title span:first-child { color: var(--accent-color); }
        .brand-title span:last-child { color: #ffffff; }

        .user-welcome-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-color);
            padding: 0.35rem 1rem;
            border-radius: 30px;
            font-size: 0.85rem;
            color: var(--text-secondary);
            margin-top: 0.5rem;
        }

        /* Modules Grid Layout */
        .modules-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(310px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2.5rem;
        }

        .module-card {
            background: rgba(24, 34, 53, 0.65);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: var(--border-radius-lg);
            padding: 2.25rem 1.75rem;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.4);
            text-align: left;
            text-decoration: none;
            color: white;
            transition: all 0.35s cubic-bezier(0.25, 0.8, 0.25, 1);
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .module-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(255,255,255,0.06) 0%, rgba(255,255,255,0) 100%);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .module-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.5);
            border-color: rgba(255, 107, 53, 0.4);
        }

        .module-card:hover::before {
            opacity: 1;
        }

        .card-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1.5rem;
        }

        .module-icon {
            width: 70px;
            height: 70px;
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            transition: all 0.3s ease;
            position: relative;
            z-index: 1;
        }

        .module-card.event .module-icon {
            background: rgba(255, 107, 53, 0.15);
            color: #ff6b35;
            box-shadow: 0 0 20px rgba(255, 107, 53, 0.2);
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

        .module-card.analytics .module-icon {
            background: rgba(155, 89, 182, 0.15);
            color: #9b59b6;
            box-shadow: 0 0 20px rgba(155, 89, 182, 0.2);
        }

        .module-card:hover .module-icon {
            transform: scale(1.08);
        }

        .stat-badge {
            font-size: 0.72rem;
            font-weight: 700;
            padding: 0.3rem 0.65rem;
            border-radius: 20px;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: var(--text-primary);
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }

        .module-title {
            font-size: 1.4rem;
            font-weight: 800;
            margin-bottom: 0.5rem;
            font-family: 'Outfit', sans-serif;
            position: relative;
            z-index: 1;
        }

        .module-desc {
            font-size: 0.88rem;
            color: var(--text-secondary);
            line-height: 1.5;
            position: relative;
            z-index: 1;
            margin-bottom: 1.5rem;
        }

        .module-action {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-weight: 700;
            font-size: 0.85rem;
            opacity: 0.85;
            transition: all 0.3s ease;
            position: relative;
            z-index: 1;
            padding-top: 1rem;
            border-top: 1px solid rgba(255, 255, 255, 0.06);
        }

        .module-card.event .module-action { color: #ff6b35; }
        .module-card.rental .module-action { color: #38b6ff; }
        .module-card.billing .module-action { color: #2ed573; }
        .module-card.analytics .module-action { color: #9b59b6; }

        .module-card:hover .module-action {
            opacity: 1;
        }

        /* Register Status Banner */
        .register-status-bar {
            background: rgba(24, 34, 53, 0.6);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius-md);
            padding: 0.75rem 1.25rem;
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        /* Quick Action Toolbar Footer */
        .select-footer-actions {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .footer-action-btn {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-color);
            color: var(--text-secondary);
            padding: 0.5rem 1.1rem;
            border-radius: var(--border-radius-md);
            font-size: 0.82rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            text-decoration: none;
        }

        .footer-action-btn:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border-color: rgba(255, 255, 255, 0.2);
        }
    </style>
</head>
<body>
    <div class="select-container">
        <!-- Brand Header -->
        <div class="brand-header">
            <img src="assets/images/logo.png" alt="Orange Events Logo" class="brand-logo-img">
            <div class="brand-title"><span>ORANGE</span> <span>EVENTS</span></div>
            
            <div class="user-welcome-badge">
                <i class="fa-solid fa-user-circle" style="color:var(--accent-color);"></i>
                <span>Logged in as <strong><?= htmlspecialchars(ucfirst($_SESSION['admin_username'])) ?></strong> (<?= htmlspecialchars(ucfirst($_SESSION['admin_role'])) ?>)</span>
            </div>
        </div>

        <!-- Cash Register Open Session Alert if open -->
        <?php if ($active_register): ?>
            <div class="register-status-bar">
                <div style="display:flex; align-items:center; gap:0.6rem; font-size:0.85rem;">
                    <span style="width:10px; height:10px; border-radius:50%; background:var(--success); display:inline-block; box-shadow:0 0 10px var(--success);"></span>
                    <span>Till Register #<?= (int)$active_register['id'] ?> is currently <strong>OPEN</strong> (Opened by <?= h($active_register['opened_by']) ?> with ₹<?= number_format($active_register['opening_float'], 2) ?> float)</span>
                </div>
                <a href="admin/register-sessions.php?module=billing" class="btn btn-secondary" style="height:28px; font-size:0.75rem; padding:0 0.6rem;">
                    <i class="fa-solid fa-cash-register"></i> Manage Till
                </a>
            </div>
        <?php endif; ?>

        <!-- Modules Grid -->
        <div class="modules-grid">
            <!-- 1. Event Management -->
            <a href="admin/index.php?module=event" class="module-card event">
                <div>
                    <div class="card-top">
                        <div class="module-icon">
                            <i class="fa-solid fa-calendar-star"></i>
                        </div>
                        <div class="stat-badge">
                            <i class="fa-solid fa-clock" style="color:#ff6b35;"></i>
                            <span><?= number_format($upcoming_events) ?> Upcoming</span>
                        </div>
                    </div>
                    <h2 class="module-title">Event Catering & Stage</h2>
                    <p class="module-desc">Manage client bookings, catering menus, stage decoration work, venue logistics, and print formal invoices.</p>
                </div>
                <div class="module-action">
                    <span>Enter Event Module</span>
                    <i class="fa-solid fa-arrow-right"></i>
                </div>
            </a>

            <!-- 2. POS Billing -->
            <a href="admin/billing.php?module=billing" class="module-card billing">
                <div>
                    <div class="card-top">
                        <div class="module-icon">
                            <i class="fa-solid fa-calculator"></i>
                        </div>
                        <div class="stat-badge">
                            <i class="fa-solid fa-cash-register" style="color:#2ed573;"></i>
                            <span>₹<?= number_format($today_pos_sales, 0) ?> Today</span>
                        </div>
                    </div>
                    <h2 class="module-title">POS Quick Billing</h2>
                    <p class="module-desc">Over-the-counter sales terminal for birthday & event items, barcode scanner support, size variants, and thermal receipt printing.</p>
                </div>
                <div class="module-action">
                    <span>Launch POS Terminal</span>
                    <i class="fa-solid fa-arrow-right"></i>
                </div>
            </a>

            <!-- 3. Rental Management -->
            <a href="admin/rentals.php?module=rental" class="module-card rental">
                <div>
                    <div class="card-top">
                        <div class="module-icon">
                            <i class="fa-solid fa-handshake-angle"></i>
                        </div>
                        <div class="stat-badge">
                            <i class="fa-solid fa-boxes-stacked" style="color:#38b6ff;"></i>
                            <span><?= number_format($active_rentals) ?> Active Orders</span>
                        </div>
                    </div>
                    <h2 class="module-title">Equipment Rentals</h2>
                    <p class="module-desc">Track rental items, manage stock availability, monitor advance deposits, handle order returns, and issue rental invoices.</p>
                </div>
                <div class="module-action">
                    <span>Enter Rental Module</span>
                    <i class="fa-solid fa-arrow-right"></i>
                </div>
            </a>

            <!-- 4. Sales Analytics & Intelligence -->
            <a href="admin/analytics.php?module=billing" class="module-card analytics">
                <div>
                    <div class="card-top">
                        <div class="module-icon">
                            <i class="fa-solid fa-chart-line"></i>
                        </div>
                        <div class="stat-badge">
                            <i class="fa-solid fa-chart-pie" style="color:#9b59b6;"></i>
                            <span>Real-Time BI</span>
                        </div>
                    </div>
                    <h2 class="module-title">Sales Analytics</h2>
                    <p class="module-desc">Interactive revenue growth charts, top selling products ranking, payment method breakdown, and CSV financial exports.</p>
                </div>
                <div class="module-action">
                    <span>View Business Analytics</span>
                    <i class="fa-solid fa-arrow-right"></i>
                </div>
            </a>
        </div>

        <!-- Footer Shortcuts -->
        <div class="select-footer-actions">
            <a href="admin/barcode-billing.php?module=billing" class="footer-action-btn">
                <i class="fa-solid fa-barcode" style="color:var(--accent-color);"></i>
                <span>Barcode Billing</span>
            </a>
            <a href="admin/settings.php" class="footer-action-btn">
                <i class="fa-solid fa-gears"></i>
                <span>Store Settings</span>
            </a>
            <?php if (isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'admin'): ?>
                <a href="admin/users.php" class="footer-action-btn">
                    <i class="fa-solid fa-users-gear"></i>
                    <span>User Management</span>
                </a>
            <?php endif; ?>
            <a href="logout.php" class="footer-action-btn" style="border-color:rgba(255, 71, 87, 0.3); color:#ff4757;">
                <i class="fa-solid fa-right-from-bracket"></i>
                <span>Sign Out</span>
            </a>
        </div>
    </div>
</body>
</html>
