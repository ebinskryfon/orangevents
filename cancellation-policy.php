<?php
require_once __DIR__ . '/includes/functions.php';

$trade_name = get_setting('trade_name', 'Orange Events');
$owner_full_name = get_setting('owner_full_name', 'Ebin Benny');
$registered_address = get_setting('registered_address', 'Thumpoly, Alappuzha, Kerala - 688008');
$company_email = get_setting('company_email', 'orangedecorations@gmail.com');
$company_phone = get_setting('company_phone', '+91 99467 31720');

$cancellation_duration = get_setting('policy_cancellation_duration', 'Up to 48 hours prior to scheduled event date');
$refund_duration = get_setting('policy_refund_duration', '5 to 7 business days');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cancellation Policy | <?= h($trade_name) ?></title>
    <meta name="description" content="Cancellation Policy for <?= h($trade_name) ?> event bookings. Duration for cancellations: <?= h($cancellation_duration) ?>.">
    
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
        .highlight-box {
            background: rgba(255, 107, 0, 0.1);
            border: 1px solid rgba(255, 107, 0, 0.3);
            border-radius: 12px;
            padding: 1.5rem;
            margin: 1.5rem 0;
            display: flex;
            align-items: center;
            gap: 1.25rem;
        }
        .highlight-box i {
            color: #FF6B00;
            font-size: 2.2rem;
        }
        .highlight-box h4 {
            font-size: 0.9rem;
            color: #FF6B00;
            margin: 0 0 0.25rem 0;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .highlight-box p {
            margin: 0;
            font-size: 1.1rem;
            color: #fff;
            font-weight: 600;
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
        .timeline-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 1rem;
            margin: 1.5rem 0;
        }
        .timeline-card {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 10px;
            padding: 1.25rem;
        }
        .timeline-card h4 {
            color: #FFA500;
            font-size: 1rem;
            margin: 0 0 0.5rem 0;
        }
        .timeline-card p {
            margin: 0;
            font-size: 0.9rem;
            color: rgba(255,255,255,0.75);
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
                <a href="privacy.php" class="nav-link">Privacy Policy</a>
                <a href="refund-policy.php" class="nav-link">Refund Policy</a>
                <a href="cancellation-policy.php" class="nav-link active" style="color: var(--primary, #FF6B00);">Cancellation</a>
                <a href="checkout.php" class="btn-primary nav-link">Book Now</a>
            </nav>
        </div>
    </header>

    <main class="policy-container">
        <div class="policy-card">
            <div class="policy-header">
                <div class="badge" style="display:inline-block; margin-bottom: 0.5rem;">Booking Terms</div>
                <h1 style="font-size: 2.2rem; margin: 0.25rem 0 0.75rem 0; color: #fff;">Cancellation Policy</h1>
                <p style="color: rgba(255,255,255,0.6); font-size: 0.9rem;">Last Updated: <?= date('F d, Y') ?></p>
            </div>

            <!-- DURATION HIGHLIGHT BOX -->
            <div class="highlight-box">
                <i class="fa-solid fa-calendar-xmark"></i>
                <div>
                    <h4>Cancellation Window & Duration</h4>
                    <p><?= h($cancellation_duration) ?></p>
                </div>
            </div>

            <div class="policy-section">
                <h3><i class="fa-solid fa-ban"></i> 1. Cancellation Rules & Refund Percentages</h3>
                <p>We understand that event schedules may need to change. Cancellation requests submitted to <strong><?= h($trade_name) ?></strong> are processed according to the following notice duration prior to your scheduled event date:</p>

                <div class="timeline-grid">
                    <div class="timeline-card">
                        <h4><i class="fa-solid fa-circle-check" style="color:#2ED573;"></i> Notice > 15 Days</h4>
                        <p>Full 100% refund of advance booking deposit (processed within <?= h($refund_duration) ?>).</p>
                    </div>
                    <div class="timeline-card">
                        <h4><i class="fa-solid fa-triangle-exclamation" style="color:#FFA500;"></i> Notice 7 to 15 Days</h4>
                        <p>50% refund of advance deposit to cover initial flower procurement and scheduling reservations.</p>
                    </div>
                    <div class="timeline-card">
                        <h4><i class="fa-solid fa-circle-xmark" style="color:#FF4757;"></i> Notice < 7 Days</h4>
                        <p>Non-refundable as fresh perishables, culinary prep, and staff assignments are already locked in.</p>
                    </div>
                </div>
            </div>

            <div class="policy-section">
                <h3><i class="fa-solid fa-rotate"></i> 2. Date Rescheduling Option</h3>
                <p>Instead of cancelling, clients have the option to reschedule their event to a future date within 6 months at no additional penalty, subject to date availability. Requests must be submitted at least 5 days prior to the original event date.</p>
            </div>

            <div class="policy-section">
                <h3><i class="fa-solid fa-paper-plane"></i> 3. How to Cancel Your Booking</h3>
                <p>Cancellation requests must be submitted in writing by the primary booking contact:</p>
                <ul>
                    <li>Email <strong><?= h($company_email) ?></strong> with your Booking ID / Customer Phone Number.</li>
                    <li>Or contact our customer helpline at <strong><?= h($company_phone) ?></strong> for assistance.</li>
                </ul>
            </div>

            <div class="verification-badge">
                <h4 style="margin: 0 0 0.5rem 0; color: #fff; display: flex; align-items: center; gap: 0.5rem;">
                    <i class="fa-solid fa-building" style="color: #FF6B00;"></i> Registered Office & Verification
                </h4>
                <p style="margin: 0; line-height: 1.6;">
                    <strong>Trade Name:</strong> <?= h($trade_name) ?><br>
                    <strong>Proprietor:</strong> <?= h($owner_full_name) ?><br>
                    <strong>Registered Address (Aadhar):</strong> <?= h($registered_address) ?><br>
                    <strong>Helpline Phone:</strong> <?= h($company_phone) ?> | <strong>Email:</strong> <?= h($company_email) ?>
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
