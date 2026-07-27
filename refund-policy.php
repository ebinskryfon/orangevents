<?php
require_once __DIR__ . '/includes/functions.php';

$trade_name = get_setting('trade_name', 'Orange Events');
$owner_full_name = get_setting('owner_full_name', 'Ebin Benny');
$registered_address = get_setting('registered_address', 'Thumpoly, Alappuzha, Kerala - 688008');
$company_email = get_setting('company_email', 'orangedecorations@gmail.com');
$company_phone = get_setting('company_phone', '+91 99467 31720');

$refund_duration = get_setting('policy_refund_duration', '5 to 7 business days');
$refund_mode = get_setting('policy_refund_mode', 'Original Mode of Payment (UPI / Net Banking / Credit or Debit Card)');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Refund Policy | <?= h($trade_name) ?></title>
    <meta name="description" content="Refund Policy for <?= h($trade_name) ?>. Duration: <?= h($refund_duration) ?>. Refund Mode: <?= h($refund_mode) ?>.">
    
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
            background: rgba(46, 213, 115, 0.1);
            border: 1px solid rgba(46, 213, 115, 0.3);
            border-radius: 12px;
            padding: 1.5rem;
            margin: 1.5rem 0;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.25rem;
        }
        .highlight-item {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
        }
        .highlight-item i {
            color: #2ED573;
            font-size: 1.5rem;
            margin-top: 0.2rem;
        }
        .highlight-item h4 {
            font-size: 0.95rem;
            color: #2ED573;
            margin: 0 0 0.25rem 0;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .highlight-item p {
            margin: 0;
            font-size: 1rem;
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
                <a href="refund-policy.php" class="nav-link active" style="color: var(--primary, #FF6B00);">Refund Policy</a>
                <a href="cancellation-policy.php" class="nav-link">Cancellation</a>
                <a href="checkout.php" class="btn-primary nav-link">Book Now</a>
            </nav>
        </div>
    </header>

    <main class="policy-container">
        <div class="policy-card">
            <div class="policy-header">
                <div class="badge" style="display:inline-block; margin-bottom: 0.5rem;">Financial Compliance</div>
                <h1 style="font-size: 2.2rem; margin: 0.25rem 0 0.75rem 0; color: #fff;">Refund Policy</h1>
                <p style="color: rgba(255,255,255,0.6); font-size: 0.9rem;">Last Updated: <?= date('F d, Y') ?></p>
            </div>

            <!-- DURATION AND REFUND MODE HIGHLIGHT BOX -->
            <div class="highlight-box">
                <div class="highlight-item">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                    <div>
                        <h4>Refund Duration</h4>
                        <p><?= h($refund_duration) ?></p>
                    </div>
                </div>
                <div class="highlight-item">
                    <i class="fa-solid fa-money-bill-transfer"></i>
                    <div>
                        <h4>Refund Mode</h4>
                        <p><?= h($refund_mode) ?></p>
                    </div>
                </div>
            </div>

            <div class="policy-section">
                <h3><i class="fa-solid fa-hand-holding-dollar"></i> 1. Eligibility for Refunds</h3>
                <p>At <strong><?= h($trade_name) ?></strong>, we strive to deliver 100% satisfaction for all our event management, stage decor, and catering services. Refunds are applicable under the following circumstances:</p>
                <ul>
                    <li><strong>Approved Event Cancellations:</strong> Booking deposit refunds initiated within the eligible cancellation timeframe.</li>
                    <li><strong>Service Non-Fulfillment:</strong> In the rare event that <?= h($trade_name) ?> is unable to fulfill a confirmed service booking due to unforeseen technical or logistical reasons.</li>
                    <li><strong>Overpayments or Duplicate Charges:</strong> Any erroneous duplicate transaction or overpayment made during online checkout.</li>
                </ul>
            </div>

            <div class="policy-section">
                <h3><i class="fa-solid fa-hourglass-half"></i> 2. Refund Processing Timeframe (Duration)</h3>
                <p>Once a refund request is reviewed and approved by <?= h($trade_name) ?> management:</p>
                <ul>
                    <li>Refunds are submitted to our payment processor immediately upon approval.</li>
                    <li>The refund amount will reflect in your account within <strong><?= h($refund_duration) ?></strong>, depending on your financial institution's processing cycles.</li>
                </ul>
            </div>

            <div class="policy-section">
                <h3><i class="fa-solid fa-credit-card"></i> 3. Refund Mode & Channels</h3>
                <p>All approved refunds will be remitted exclusively via the <strong><?= h($refund_mode) ?></strong> used during the initial booking transaction.</p>
                <ul>
                    <li><strong>UPI Payments:</strong> Refunded directly to the linked VPA / Bank Account within 5–7 business days.</li>
                    <li><strong>Debit / Credit Cards:</strong> Credited back to the original issuing bank account / card statement within 5–7 business days.</li>
                    <li><strong>Net Banking:</strong> Processed via direct electronic bank transfer (NEFT/RTGS/IMPS) back to the sender's bank account.</li>
                </ul>
            </div>

            <div class="policy-section">
                <h3><i class="fa-solid fa-envelope-open-text"></i> 4. How to Request a Refund</h3>
                <p>To request a refund, please contact our support team with your Booking ID / Receipt Number:</p>
                <ul>
                    <li><strong>Email:</strong> Send a request to <strong><?= h($company_email) ?></strong> with subject line <em>"Refund Request - Booking #[Your ID]"</em></li>
                    <li><strong>Phone Support:</strong> Call us directly at <strong><?= h($company_phone) ?></strong> between 9:00 AM and 8:00 PM.</li>
                </ul>
            </div>

            <div class="verification-badge">
                <h4 style="margin: 0 0 0.5rem 0; color: #fff; display: flex; align-items: center; gap: 0.5rem;">
                    <i class="fa-solid fa-building" style="color: #FF6B00;"></i> Merchant Verification Details
                </h4>
                <p style="margin: 0; line-height: 1.6;">
                    <strong>Trade Name:</strong> <?= h($trade_name) ?><br>
                    <strong>Proprietor:</strong> <?= h($owner_full_name) ?><br>
                    <strong>Registered Office Address:</strong> <?= h($registered_address) ?><br>
                    <strong>Support Email:</strong> <?= h($company_email) ?> | <strong>Phone:</strong> <?= h($company_phone) ?>
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
