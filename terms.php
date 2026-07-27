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
    <title>Terms & Conditions | <?= h($trade_name) ?></title>
    <meta name="description" content="Terms & Conditions for <?= h($trade_name) ?> event management, catering, stage decoration, and equipment rental services in Kerala.">
    
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
        .operating-line-banner {
            background: rgba(255, 107, 0, 0.12);
            border: 1px solid rgba(255, 107, 0, 0.3);
            border-radius: 10px;
            padding: 1rem 1.25rem;
            margin: 1.5rem 0;
            color: #FFA500;
            font-size: 1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.75rem;
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
                <a href="terms.php" class="nav-link active" style="color: var(--primary, #FF6B00);">Terms & Conditions</a>
                <a href="privacy.php" class="nav-link">Privacy Policy</a>
                <a href="refund-policy.php" class="nav-link">Refund Policy</a>
                <a href="cancellation-policy.php" class="nav-link">Cancellation</a>
                <a href="checkout.php" class="btn-primary nav-link">Book Now</a>
            </nav>
        </div>
    </header>

    <main class="policy-container">
        <div class="policy-card">
            <div class="policy-header">
                <div class="badge" style="display:inline-block; margin-bottom: 0.5rem;">Legal Notice</div>
                <h1 style="font-size: 2.2rem; margin: 0.25rem 0 0.75rem 0; color: #fff;">Terms & Conditions</h1>
                <p style="color: rgba(255,255,255,0.6); font-size: 0.9rem;">Last Updated: <?= date('F d, Y') ?></p>
            </div>

            <!-- MANDATORY MERCHANDISING TRADE NAME LINE -->
            <div class="operating-line-banner">
                <i class="fa-solid fa-building-shield" style="font-size: 1.25rem;"></i>
                <span>This website is operated by <?= h($trade_name) ?>.</span>
            </div>

            <div class="policy-section">
                <h3><i class="fa-solid fa-file-contract"></i> 1. Overview & Agreement</h3>
                <p>Welcome to <strong><?= h($trade_name) ?></strong> ("we", "us", "our"). By accessing or using our website, booking our event management, stage decor, catering, or rental services, you agree to be bound by these Terms & Conditions. Please read them carefully before confirming any order or booking.</p>
            </div>

            <div class="policy-section">
                <h3><i class="fa-solid fa-calendar-check"></i> 2. Booking & Reservations</h3>
                <ul>
                    <li>An event date is considered confirmed only upon receipt of the designated advance booking deposit.</li>
                    <li>All booking details including venue location, date, guest counts, dish selection, and stage decor requirements must be finalized at least 7 days prior to the event.</li>
                    <li>Any last-minute additions to catering guest counts or extra decor items will be billed separately based on available inventory.</li>
                </ul>
            </div>

            <div class="policy-section">
                <h3><i class="fa-solid fa-credit-card"></i> 3. Pricing & Payments</h3>
                <ul>
                    <li>All pricing displayed on our website or provided in customized quotes is in Indian Rupees (INR).</li>
                    <li>Payment modes accepted include UPI, Online Bank Transfer, Debit/Credit Cards, and Cash.</li>
                    <li>The remaining balance payment must be settled on or before the conclusion of the event date as per the agreement.</li>
                </ul>
            </div>

            <div class="policy-section">
                <h3><i class="fa-solid fa-dolly"></i> 4. Rental Equipment & Decor Property</h3>
                <ul>
                    <li>All stage setups, lighting equipment, audio-visual systems, props, furniture, and catering items rented from <?= h($trade_name) ?> remain the exclusive property of <?= h($trade_name) ?>.</li>
                    <li>The client is responsible for ensuring reasonable safety of the venue decor and equipment during the event duration. Damage caused by gross negligence or vandalism may incur reasonable repair/replacement charges.</li>
                </ul>
            </div>

            <div class="policy-section">
                <h3><i class="fa-solid fa-shield-halved"></i> 5. Limitation of Liability</h3>
                <p><?= h($trade_name) ?> shall not be held liable for failure to perform obligations due to natural force majeure events, severe weather disasters, government restrictions, or power outages beyond our control. In such cases, we will make every reasonable effort to reschedule or accommodate alternative solutions.</p>
            </div>

            <div class="verification-badge">
                <h4 style="margin: 0 0 0.5rem 0; color: #fff; display: flex; align-items: center; gap: 0.5rem;">
                    <i class="fa-solid fa-certificate" style="color: #FF6B00;"></i> Registered Business Details
                </h4>
                <p style="margin: 0; line-height: 1.6;">
                    <strong>Operating Trade Name:</strong> <?= h($trade_name) ?><br>
                    <strong>Proprietor Name:</strong> <?= h($owner_full_name) ?><br>
                    <strong>Registered Business Address:</strong> <?= h($registered_address) ?><br>
                    <strong>Contact Phone:</strong> <?= h($company_phone) ?> | <strong>Email:</strong> <?= h($company_email) ?>
                </p>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer style="margin-top: 3rem;">
        <div class="footer-content">
            <div class="footer-brand">
                <div class="logo">ORANGE<span>EVENTS</span></div>
                <p>This website is operated by <?= h($trade_name) ?> (Proprietor: <?= h($owner_full_name) ?>). Registered Office: <?= h($registered_address) ?>.</p>
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
