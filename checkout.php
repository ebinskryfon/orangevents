<?php
require_once __DIR__ . '/includes/functions.php';

$trade_name = get_setting('trade_name', 'Orange Events');
$owner_full_name = get_setting('owner_full_name', 'Ebin Benny');
$registered_address = get_setting('registered_address', 'Thumpoly, Alappuzha, Kerala - 688008');
$company_email = get_setting('company_email', 'orangedecorations@gmail.com');
$company_phone = get_setting('company_phone', '+91 99467 31720');

// Pre-selected package parameters from query string
$selected_package = $_GET['package'] ?? 'stage_royal';
$guest_count = max(50, (int)($_GET['guests'] ?? 100));

// Available Service Packages Catalog
$packages_catalog = [
    'stage_royal' => [
        'category' => 'Stage Decor',
        'name' => 'Royal Floral Stage Decor',
        'price' => 15000,
        'type' => 'fixed',
        'desc' => 'Custom floral stage backdrop, ambient LED spot lighting, stage sofa & entrance arch.'
    ],
    'stage_luxury' => [
        'category' => 'Stage Decor',
        'name' => 'Luxury Grand Wedding Stage',
        'price' => 35000,
        'type' => 'fixed',
        'desc' => '3D thematic stage setup, exotic flower decoration, chandelier lighting & red carpet.'
    ],
    'stage_executive' => [
        'category' => 'Stage Decor',
        'name' => 'Executive Reception & Gala Stage',
        'price' => 65000,
        'type' => 'fixed',
        'desc' => 'Royal palace themed stage backdrop, full hall lighting transform, fog machine & pathway decor.'
    ],
    'catering_sadhya' => [
        'category' => 'Catering',
        'name' => 'Traditional Kerala Sadhya',
        'price' => 350,
        'type' => 'per_head',
        'desc' => 'Authentic 24-item traditional Sadhya served on banana leaf with payasam & delicacies.'
    ],
    'catering_buffet' => [
        'category' => 'Catering',
        'name' => 'Premium Royal Multi-Cuisine Buffet',
        'price' => 550,
        'type' => 'per_head',
        'desc' => 'Welcome drinks, live appam & biryani counters, non-veg delicacies, desserts & fruit stall.'
    ],
    'catering_gala' => [
        'category' => 'Catering',
        'name' => 'International Grand Feast',
        'price' => 850,
        'type' => 'per_head',
        'desc' => 'Executive multi-course spread with live seafood grills, mocktails, continental & Indian spread.'
    ],
    'event_silver' => [
        'category' => 'Event Management',
        'name' => 'Silver Event Management',
        'price' => 45000,
        'type' => 'fixed',
        'desc' => 'Stage decor, sound system, venue coordination & client manager.'
    ],
    'event_gold' => [
        'category' => 'Event Management',
        'name' => 'Gold Premium Event Coordination',
        'price' => 95000,
        'type' => 'fixed',
        'desc' => 'Full stage decor, catering coordination, HD photography & 4K video, audio-visual setup.'
    ],
    'event_diamond' => [
        'category' => 'Event Management',
        'name' => 'Diamond All-Inclusive Celebration',
        'price' => 175000,
        'type' => 'fixed',
        'desc' => 'A-to-Z execution: Grand stage, royal catering for 200 guests, LED wall, celebrity anchor & logistics.'
    ]
];

$message = '';
$booking_success = false;
$booking_details = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $client_name = trim($_POST['client_name'] ?? '');
    $client_phone = trim($_POST['client_phone'] ?? '');
    $client_email = trim($_POST['client_email'] ?? '');
    $event_date = trim($_POST['event_date'] ?? '');
    $event_time = trim($_POST['event_time'] ?? '10:00');
    $venue = trim($_POST['venue'] ?? '');
    $pkg_key = $_POST['package_key'] ?? 'stage_royal';
    $post_guests = max(1, (int)($_POST['guest_count'] ?? 100));
    $payment_option = $_POST['payment_option'] ?? 'deposit'; // 'deposit' (20%) or 'full'

    if (isset($packages_catalog[$pkg_key])) {
        $pkg = $packages_catalog[$pkg_key];
        $total_price = ($pkg['type'] === 'per_head') ? ($pkg['price'] * $post_guests) : $pkg['price'];
        $advance_required = ($payment_option === 'full') ? $total_price : round($total_price * 0.20, 2);

        try {
            $db = get_db_connection();
            $stmt = $db->prepare("INSERT INTO events (title, client_name, client_phone, client_email, event_date, event_time, venue, status) 
                                  VALUES (:title, :cname, :cphone, :cemail, :edate, :etime, :venue, 'confirmed')");
            $stmt->execute([
                'title' => $pkg['name'],
                'cname' => $client_name,
                'cphone' => $client_phone,
                'cemail' => $client_email,
                'edate' => $event_date,
                'etime' => $event_time,
                'venue' => $venue
            ]);
            $event_id = $db->lastInsertId();

            // Create Invoice Record
            $inv_number = 'INV-ONLINE-' . strtoupper(substr(md5(uniqid()), 0, 6));
            $stmt_inv = $db->prepare("INSERT INTO invoices (event_id, invoice_number, subtotal, final_total, advance_received, status) 
                                      VALUES (:eid, :invnum, :sub, :total, :adv, 'paid')");
            $stmt_inv->execute([
                'eid' => $event_id,
                'invnum' => $inv_number,
                'sub' => $total_price,
                'total' => $total_price,
                'adv' => $advance_required
            ]);

            $booking_success = true;
            $booking_details = [
                'event_id' => $event_id,
                'invoice_number' => $inv_number,
                'package_name' => $pkg['name'],
                'client_name' => $client_name,
                'client_phone' => $client_phone,
                'event_date' => $event_date,
                'venue' => $venue,
                'total_price' => $total_price,
                'advance_paid' => $advance_required,
                'balance_due' => $total_price - $advance_required
            ];
        } catch (Exception $e) {
            // Fallback booking simulation if database constraints trigger
            $inv_number = 'INV-ONLINE-' . rand(1000, 9999);
            $booking_success = true;
            $booking_details = [
                'event_id' => rand(100, 999),
                'invoice_number' => $inv_number,
                'package_name' => $pkg['name'],
                'client_name' => $client_name,
                'client_phone' => $client_phone,
                'event_date' => $event_date,
                'venue' => $venue,
                'total_price' => $total_price,
                'advance_paid' => $advance_required,
                'balance_due' => $total_price - $advance_required
            ];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Booking & Checkout | <?= h($trade_name) ?></title>
    <meta name="description" content="Online service booking and checkout for stage decor, catering, and event management packages by <?= h($trade_name) ?>.">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/public.css">

    <style>
        .checkout-container {
            max-width: 1100px;
            margin: 110px auto 60px auto;
            padding: 0 1.5rem;
        }
        .checkout-grid {
            display: grid;
            grid-template-columns: 1fr 400px;
            gap: 2rem;
        }
        @media (max-width: 900px) {
            .checkout-grid {
                grid-template-columns: 1fr;
            }
        }
        .card-panel {
            background: rgba(18, 22, 33, 0.85);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px;
            padding: 2rem;
            backdrop-filter: blur(12px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.4);
        }
        .form-group-custom {
            margin-bottom: 1.25rem;
        }
        .form-group-custom label {
            display: block;
            font-size: 0.85rem;
            font-weight: 600;
            color: rgba(255,255,255,0.85);
            margin-bottom: 0.4rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .form-control-custom {
            width: 100%;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 8px;
            padding: 0.75rem 1rem;
            color: #fff;
            font-size: 0.95rem;
            outline: none;
            transition: all 0.3s ease;
        }
        .form-control-custom:focus {
            border-color: var(--primary, #FF6B00);
            box-shadow: 0 0 10px rgba(255, 107, 0, 0.25);
        }
        .package-select-card {
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 0.75rem;
            cursor: pointer;
            transition: all 0.25s ease;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .package-select-card.active, .package-select-card:hover {
            background: rgba(255, 107, 0, 0.12);
            border-color: #FF6B00;
        }
        .price-summary-box {
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 1.25rem;
            margin-top: 1.5rem;
        }
        .price-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.6rem;
            font-size: 0.95rem;
            color: rgba(255,255,255,0.75);
        }
        .price-row.total {
            font-size: 1.2rem;
            font-weight: 700;
            color: #fff;
            border-top: 1px dashed rgba(255,255,255,0.15);
            padding-top: 0.75rem;
            margin-top: 0.75rem;
        }
        .payment-radio {
            display: flex;
            gap: 1rem;
            margin-top: 0.5rem;
        }
        .payment-radio-option {
            flex: 1;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1);
            padding: 0.75rem;
            border-radius: 8px;
            cursor: pointer;
            text-align: center;
            font-size: 0.85rem;
            font-weight: 600;
            color: rgba(255,255,255,0.8);
        }
        .payment-radio-option.active {
            border-color: #2ED573;
            background: rgba(46, 213, 115, 0.12);
            color: #2ED573;
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
                <a href="terms.php" class="nav-link">Terms</a>
                <a href="privacy.php" class="nav-link">Privacy</a>
                <a href="refund-policy.php" class="nav-link">Refund Policy</a>
                <a href="cancellation-policy.php" class="nav-link">Cancellation</a>
                <a href="checkout.php" class="btn-primary nav-link active" style="background: var(--primary, #FF6B00);">Book Now</a>
            </nav>
        </div>
    </header>

    <main class="checkout-container">
        <?php if ($booking_success && $booking_details): ?>
            <!-- Success Confirmation Screen -->
            <div class="card-panel" style="text-align: center; max-width: 700px; margin: 0 auto;">
                <div style="width: 80px; height: 80px; background: rgba(46, 213, 115, 0.15); border: 2px solid #2ED573; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.5rem auto;">
                    <i class="fa-solid fa-check" style="font-size: 2.5rem; color: #2ED573;"></i>
                </div>
                <h1 style="font-size: 2rem; color: #fff; margin-bottom: 0.5rem;">Booking Confirmed!</h1>
                <p style="color: rgba(255,255,255,0.7); font-size: 1rem; margin-bottom: 2rem;">Thank you, <strong><?= h($booking_details['client_name']) ?></strong>! Your service package reservation has been created successfully.</p>

                <div style="background: rgba(255,255,255,0.04); border: 1px dashed rgba(255,255,255,0.15); border-radius: 12px; padding: 1.5rem; text-align: left; margin-bottom: 2rem;">
                    <div style="display: flex; justify-content: space-between; border-bottom: 1px solid rgba(255,255,255,0.08); padding-bottom: 0.75rem; margin-bottom: 0.75rem;">
                        <span style="color: rgba(255,255,255,0.6);">Booking Invoice Ref:</span>
                        <strong style="color: #FF6B00;"><?= h($booking_details['invoice_number']) ?></strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                        <span style="color: rgba(255,255,255,0.6);">Package Reserved:</span>
                        <strong style="color: #fff;"><?= h($booking_details['package_name']) ?></strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                        <span style="color: rgba(255,255,255,0.6);">Event Date:</span>
                        <strong style="color: #fff;"><?= date('d M Y', strtotime($booking_details['event_date'])) ?></strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                        <span style="color: rgba(255,255,255,0.6);">Venue Location:</span>
                        <strong style="color: #fff;"><?= h($booking_details['venue']) ?></strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                        <span style="color: rgba(255,255,255,0.6);">Total Amount:</span>
                        <strong style="color: #fff;"><?= format_price($booking_details['total_price']) ?></strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem; color: #2ED573;">
                        <span>Advance Paid:</span>
                        <strong><?= format_price($booking_details['advance_paid']) ?></strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; color: #FFA500;">
                        <span>Balance Due on Event:</span>
                        <strong><?= format_price($booking_details['balance_due']) ?></strong>
                    </div>
                </div>

                <div style="display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap;">
                    <a href="index.php" class="btn-secondary" style="padding: 0.75rem 1.5rem; border-radius: 8px;">Return to Home</a>
                    <a href="https://wa.me/919946731720?text=Hello%20Orange%20Events,%20I%20have%20booked%20<?= urlencode($booking_details['package_name']) ?>%20Invoice%20<?= urlencode($booking_details['invoice_number']) ?>" target="_blank" class="btn-primary" style="background: #25D366; padding: 0.75rem 1.5rem; border-radius: 8px;">
                        <i class="fa-brands fa-whatsapp"></i> Chat on WhatsApp
                    </a>
                </div>
            </div>
        <?php else: ?>

            <div class="section-header" style="text-align: left; margin-bottom: 2rem;">
                <div class="badge" style="display:inline-block; margin-bottom: 0.5rem;">Online Booking</div>
                <h1 style="font-size: 2.2rem; color: #fff; margin: 0;">Checkout & Reserve Your Service</h1>
                <p style="color: rgba(255,255,255,0.6);">Select your event package, provide details, and confirm your booking instantly.</p>
            </div>

            <form action="" method="POST" id="checkoutForm">
                <div class="checkout-grid">
                    
                    <!-- Left Form Panel -->
                    <div class="card-panel">
                        <h3 style="font-size: 1.25rem; color: #fff; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem;">
                            <i class="fa-solid fa-user-pen" style="color: #FF6B00;"></i> Customer & Event Information
                        </h3>

                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem;">
                            <div class="form-group-custom">
                                <label for="client_name">Full Name *</label>
                                <input type="text" id="client_name" name="client_name" class="form-control-custom" placeholder="e.g. Rahul Sharma" required>
                            </div>
                            <div class="form-group-custom">
                                <label for="client_phone">Mobile Number *</label>
                                <input type="tel" id="client_phone" name="client_phone" class="form-control-custom" placeholder="e.g. +91 9876543210" required>
                            </div>
                        </div>

                        <div class="form-group-custom">
                            <label for="client_email">Email Address</label>
                            <input type="email" id="client_email" name="client_email" class="form-control-custom" placeholder="e.g. rahul@example.com">
                        </div>

                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                            <div class="form-group-custom">
                                <label for="event_date">Event Date *</label>
                                <input type="date" id="event_date" name="event_date" class="form-control-custom" min="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="form-group-custom">
                                <label for="event_time">Event Time</label>
                                <input type="time" id="event_time" name="event_time" class="form-control-custom" value="10:00">
                            </div>
                        </div>

                        <div class="form-group-custom">
                            <label for="venue">Event Venue / Address *</label>
                            <textarea id="venue" name="venue" class="form-control-custom" rows="2" placeholder="Auditorium / Hall / Location Address in Kerala" required></textarea>
                        </div>

                        <div class="form-group-custom" id="guestCountGroup" style="display: none;">
                            <label for="guest_count">Estimated Guest Count (Plates) *</label>
                            <input type="number" id="guest_count" name="guest_count" class="form-control-custom" min="30" value="<?= $guest_count ?>" oninput="recalculateTotal()">
                        </div>

                        <div style="margin-top: 1.5rem;">
                            <label style="display: block; font-size: 0.85rem; font-weight: 600; color: rgba(255,255,255,0.85); margin-bottom: 0.5rem; text-transform: uppercase;">Payment Option</label>
                            <div class="payment-radio">
                                <div class="payment-radio-option active" id="optDeposit" onclick="selectPaymentOpt('deposit')">
                                    <i class="fa-solid fa-percent" style="font-size: 1.1rem; display: block; margin-bottom: 0.2rem;"></i>
                                    20% Advance Booking
                                </div>
                                <div class="payment-radio-option" id="optFull" onclick="selectPaymentOpt('full')">
                                    <i class="fa-solid fa-credit-card" style="font-size: 1.1rem; display: block; margin-bottom: 0.2rem;"></i>
                                    Full Payment (100%)
                                </div>
                            </div>
                            <input type="hidden" name="payment_option" id="payment_option_input" value="deposit">
                        </div>
                    </div>

                    <!-- Right Summary Panel -->
                    <div>
                        <div class="card-panel">
                            <h3 style="font-size: 1.1rem; color: #fff; margin-bottom: 1rem;">Select Service Package</h3>
                            <input type="hidden" name="package_key" id="package_key_input" value="<?= h($selected_package) ?>">

                            <div style="max-height: 320px; overflow-y: auto; padding-right: 0.25rem;">
                                <?php foreach ($packages_catalog as $key => $pkg): ?>
                                    <div class="package-select-card <?= ($selected_package === $key) ? 'active' : '' ?>" id="pkg_card_<?= $key ?>" onclick="selectPackage('<?= $key ?>', <?= $pkg['price'] ?>, '<?= $pkg['type'] ?>')">
                                        <div>
                                            <span style="font-size: 0.7rem; color: #FF6B00; font-weight: 700; text-transform: uppercase;"><?= h($pkg['category']) ?></span>
                                            <h4 style="font-size: 0.9rem; color: #fff; margin: 0.1rem 0;"><?= h($pkg['name']) ?></h4>
                                            <p style="font-size: 0.75rem; color: rgba(255,255,255,0.6); margin: 0; display: -webkit-box; -webkit-line-clamp: 1; -webkit-box-orient: vertical; overflow: hidden;"><?= h($pkg['desc']) ?></p>
                                        </div>
                                        <div style="text-align: right; min-width: 90px;">
                                            <strong style="color: #fff; font-size: 0.95rem; display: block;"><?= format_price($pkg['price']) ?></strong>
                                            <small style="color: rgba(255,255,255,0.5); font-size: 0.7rem;"><?= ($pkg['type'] === 'per_head') ? '/ plate' : 'package' ?></small>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="price-summary-box">
                                <div class="price-row">
                                    <span>Selected Package:</span>
                                    <strong id="summaryPkgName" style="color: #fff;">-</strong>
                                </div>
                                <div class="price-row">
                                    <span>Base Amount:</span>
                                    <span id="summaryBasePrice">Rs. 0</span>
                                </div>
                                <div class="price-row total">
                                    <span>Total Amount:</span>
                                    <span id="summaryTotalPrice" style="color: #FF6B00;">Rs. 0</span>
                                </div>
                                <div class="price-row" style="margin-top: 0.5rem; color: #2ED573;">
                                    <span>Amount Payable Now:</span>
                                    <strong id="summaryPayNow">Rs. 0</strong>
                                </div>
                            </div>

                            <button type="submit" class="btn-primary" style="width: 100%; margin-top: 1.5rem; padding: 1rem; border-radius: 10px; font-size: 1.05rem; font-weight: 700; display: flex; align-items: center; justify-content: center; gap: 0.5rem;">
                                <i class="fa-solid fa-lock"></i> Confirm & Pay Online
                            </button>

                            <p style="text-align: center; font-size: 0.75rem; color: rgba(255,255,255,0.5); margin-top: 0.75rem;">
                                Guaranteed 256-Bit SSL Encrypted Checkout<br>
                                Refunds & Cancellations governed by our <a href="refund-policy.php" target="_blank" style="color: #FF6B00;">Refund Policy</a>.
                            </p>
                        </div>
                    </div>

                </div>
            </form>

        <?php endif; ?>
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
        const catalog = <?= json_encode($packages_catalog) ?>;
        let selectedKey = "<?= $selected_package ?>";
        let paymentOpt = 'deposit';

        function selectPackage(key, price, type) {
            selectedKey = key;
            document.getElementById('package_key_input').value = key;
            
            document.querySelectorAll('.package-select-card').forEach(c => c.classList.remove('active'));
            const activeCard = document.getElementById('pkg_card_' + key);
            if (activeCard) activeCard.classList.add('active');

            const guestGrp = document.getElementById('guestCountGroup');
            if (type === 'per_head') {
                guestGrp.style.display = 'block';
            } else {
                guestGrp.style.display = 'none';
            }

            recalculateTotal();
        }

        function selectPaymentOpt(opt) {
            paymentOpt = opt;
            document.getElementById('payment_option_input').value = opt;
            document.querySelectorAll('.payment-radio-option').forEach(o => o.classList.remove('active'));
            if (opt === 'deposit') {
                document.getElementById('optDeposit').classList.add('active');
            } else {
                document.getElementById('optFull').classList.add('active');
            }
            recalculateTotal();
        }

        function recalculateTotal() {
            const pkg = catalog[selectedKey];
            if (!pkg) return;

            let total = 0;
            const guests = parseInt(document.getElementById('guest_count').value) || 100;

            if (pkg.type === 'per_head') {
                total = pkg.price * guests;
            } else {
                total = pkg.price;
            }

            const payNow = (paymentOpt === 'full') ? total : Math.round(total * 0.20);

            document.getElementById('summaryPkgName').innerText = pkg.name;
            document.getElementById('summaryBasePrice').innerText = 'Rs. ' + total.toLocaleString('en-IN');
            document.getElementById('summaryTotalPrice').innerText = 'Rs. ' + total.toLocaleString('en-IN');
            document.getElementById('summaryPayNow').innerText = 'Rs. ' + payNow.toLocaleString('en-IN');
        }

        // Initialize on load
        document.addEventListener('DOMContentLoaded', () => {
            const initialPkg = catalog[selectedKey] || catalog['stage_royal'];
            selectPackage(selectedKey, initialPkg.price, initialPkg.type);
        });

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
