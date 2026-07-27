<?php
require_once __DIR__ . '/includes/functions.php';

$trade_name = get_setting('trade_name', 'Orange Events');
$owner_full_name = get_setting('owner_full_name', 'Ebin Benny');
$registered_address = get_setting('registered_address', 'Thumpoly, Alappuzha, Kerala - 688008');
$company_email = get_setting('company_email', 'orangedecorations@gmail.com');
$company_phone = get_setting('company_phone', '+91 99467 31720');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privacy Policy | <?= h($trade_name) ?></title>
    <meta name="description" content="Privacy Policy for <?= h($trade_name) ?> website and booking services. Learn how we handle and protect customer personal information.">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/public.css">

    <style>
        .policy-container {
            max-width: 1000px;
            margin: 120px auto 60px auto;
            padding: 0 1.5rem;
        }
        .policy-card {
            background: rgba(18, 22, 33, 0.85);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px;
            padding: 2.5rem;
            backdrop-filter: blur(12px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.4);
        }
        .policy-header {
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        .policy-section {
            margin-bottom: 2rem;
        }
        .policy-section h3 {
            font-family: 'Outfit', sans-serif;
            font-size: 1.25rem;
            color: var(--text-primary, #fff);
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .policy-section h3 i {
            color: var(--primary, #FF6B00);
        }
        .policy-section p, .policy-section ul {
            color: rgba(255, 255, 255, 0.8);
            line-height: 1.7;
            font-size: 0.95rem;
            margin-bottom: 1rem;
        }
        .policy-section ul {
            padding-left: 1.25rem;
        }
        .policy-section li {
            margin-bottom: 0.5rem;
        }
        .verification-badge {
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 1.25rem;
            margin-top: 2rem;
            font-size: 0.9rem;
            color: rgba(255,255,255,0.75);
        }
    </style>
</head>
<body>

    <!-- Header Navigation -->
    <header class="scrolled">
        <div class="nav-container">
            <a href="index.php" class="logo">
                <img src="assets/images/logo.png" alt="Logo" style="height: 40px; margin-right: 5px;">
                ORANGE<span>EVENTS</span>
            </a>

            <button class="hamburger" id="mobileMenuBtn">
                <i class="fa-solid fa-bars"></i>
            </button>

            <nav class="nav-links" id="navLinks">
                <a href="index.php#home" class="nav-link">Home</a>
                <a href="index.php#about" class="nav-link">About Us</a>
                <a href="index.php#services" class="nav-link">Services & Pricing</a>
                <a href="terms.php" class="nav-link">Terms & Conditions</a>
                <a href="privacy.php" class="nav-link active" style="color: var(--primary, #FF6B00);">Privacy Policy</a>
                <a href="refund-policy.php" class="nav-link">Refund Policy</a>
                <a href="cancellation-policy.php" class="nav-link">Cancellation</a>
                <a href="checkout.php" class="btn-primary nav-link">Book Now</a>
            </nav>
        </div>
    </header>

    <main class="policy-container">
        <div class="policy-card">
            <div class="policy-header">
                <div class="badge" style="display:inline-block; margin-bottom: 0.5rem;">Data Protection</div>
                <h1 style="font-size: 2.2rem; margin: 0.25rem 0 0.75rem 0; color: #fff;">Privacy Policy</h1>
                <p style="color: rgba(255,255,255,0.6); font-size: 0.9rem;">Last Updated: <?= date('F d, Y') ?></p>
            </div>

            <div class="policy-section">
                <h3><i class="fa-solid fa-user-shield"></i> 1. Information We Collect</h3>
                <p>At <strong><?= h($trade_name) ?></strong> (operated by <?= h($owner_full_name) ?>), we are committed to respecting and protecting your privacy. When you request a quote, book a service, or contact us online, we collect personal information necessary to fulfill your event requirements, including:</p>
                <ul>
                    <li>Full Name and Contact Number</li>
                    <li>Email Address and Event Venue Location / Address</li>
                    <li>Event Specifications (Date, Guest Count, Service Preferences)</li>
                    <li>Payment information required for order verification</li>
                </ul>
            </div>

            <div class="policy-section">
                <h3><i class="fa-solid fa-gears"></i> 2. How We Use Your Information</h3>
                <p>Your personal data is strictly used for the following legitimate business purposes:</p>
                <ul>
                    <li>Processing booking inquiries, service reservations, and invoicing</li>
                    <li>Communicating event logistics and coordinating setup with venue managers</li>
                    <li>Sending payment receipts, invoice statements, and booking confirmations</li>
                    <li>Improving our website performance and customer support services</li>
                </ul>
            </div>

            <div class="policy-section">
                <h3><i class="fa-solid fa-lock"></i> 3. Data Protection & Payment Security</h3>
                <p>We employ standard encryption, firewall protections, and secure SSL connections across our platform. Payment processing is conducted securely via verified third-party payment gateways (such as Cashfree Payment Gateway & Official UPI). We do NOT store your sensitive card PINs, CVVs, or bank login credentials on our servers.</p>
            </div>

            <div class="policy-section">
                <h3><i class="fa-solid fa-user-xmark"></i> 4. Data Sharing & Privacy Rights</h3>
                <p><?= h($trade_name) ?> does NOT sell, rent, or trade your personal information to any third-party marketing companies. Data is disclosed only when required by law or to authorized event service partners directly involved in fulfilling your event services (such as transport & venue security).</p>
            </div>

            <div class="policy-section">
                <h3><i class="fa-solid fa-cookie-bite"></i> 5. Cookies & Analytics</h3>
                <p>Our website uses minimal operational session cookies to remember your navigation preferences and ensure smooth checkout session handling. You can adjust your browser settings to disable cookies if preferred.</p>
            </div>

            <div class="verification-badge">
                <h4 style="margin: 0 0 0.5rem 0; color: #fff; display: flex; align-items: center; gap: 0.5rem;">
                    <i class="fa-solid fa-circle-info" style="color: #FF6B00;"></i> Privacy Officer Contact Information
                </h4>
                <p style="margin: 0; line-height: 1.6;">
                    <strong>Operating Business:</strong> <?= h($trade_name) ?><br>
                    <strong>Proprietor:</strong> <?= h($owner_full_name) ?><br>
                    <strong>Registered Address:</strong> <?= h($registered_address) ?><br>
                    <strong>Privacy Email:</strong> <?= h($company_email) ?> | <strong>Phone:</strong> <?= h($company_phone) ?>
                </p>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer style="margin-top: 3rem;">
        <div class="footer-content">
            <div class="footer-brand">
                <div class="logo">ORANGE<span>EVENTS</span></div>
                <p>This website is operated by <?= h($trade_name) ?> (Proprietor: <?= h($owner_full_name) ?>). Registered Address: <?= h($registered_address) ?>.</p>
            </div>

            <div class="footer-links">
                <h4>Legal & Policies</h4>
                <ul>
                    <li><a href="terms.php"><i class="fa-solid fa-angle-right"></i> Terms & Conditions</a></li>
                    <li><a href="privacy.php"><i class="fa-solid fa-angle-right"></i> Privacy Policy</a></li>
                    <li><a href="refund-policy.php"><i class="fa-solid fa-angle-right"></i> Refund Policy</a></li>
                    <li><a href="cancellation-policy.php"><i class="fa-solid fa-angle-right"></i> Cancellation Policy</a></li>
                </ul>
            </div>

            <div class="footer-links">
                <h4>Registered Contact</h4>
                <ul class="contact-info">
                    <li><i class="fa-solid fa-location-dot"></i> <span><?= h($registered_address) ?></span></li>
                    <li><i class="fa-solid fa-user-check"></i> <span>Proprietor: <?= h($owner_full_name) ?></span></li>
                    <li><i class="fa-solid fa-phone"></i> <span><?= h($company_phone) ?></span></li>
                    <li><i class="fa-solid fa-envelope"></i> <span><?= h($company_email) ?></span></li>
                </ul>
            </div>
        </div>

        <div class="footer-bottom">
            <p>&copy; <?= date('Y') ?> <?= h($trade_name) ?>. Operated by <?= h($owner_full_name) ?>. All rights reserved.</p>
        </div>
    </footer>

    <script>
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const navLinks = document.getElementById('navLinks');
        if (mobileMenuBtn && navLinks) {
            mobileMenuBtn.addEventListener('click', () => {
                navLinks.classList.toggle('active');
            });
        }
    </script>
</body>
</html>
