<?php
// Base public index file for Orange Events
require_once __DIR__ . '/includes/functions.php';

$trade_name = get_setting('trade_name', 'Orange Events');
$owner_full_name = get_setting('owner_full_name', 'Ebin Benny');
$registered_address = get_setting('registered_address', 'Thumpoly, Alappuzha, Kerala - 688008');
$company_phone = get_setting('company_phone', '+91 99467 31720');
$company_email = get_setting('company_email', 'orangedecorations@gmail.com');

$settings = [
    'company_name' => $trade_name,
    'trade_name' => $trade_name,
    'owner_full_name' => $owner_full_name,
    'phone' => $company_phone,
    'email' => $company_email,
    'address' => $registered_address
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($settings['company_name']) ?> | Premium Event Management</title>
    <meta name="description" content="Orange Events – Kerala's premier event management company. Premium stage decors, grand catering & full coordination for weddings, corporate events and celebrations in Alappuzha.">

    <!-- Resource Hints: Tell browser to connect early -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">
    <link rel="dns-prefetch" href="https://images.unsplash.com">

    <!-- Google Fonts: non-blocking with font-display=swap -->
    <link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700;800&display=swap" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700;800&display=swap"></noscript>

    <!-- FontAwesome: load asynchronously to avoid render-blocking -->
    <link rel="preload" as="style" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></noscript>

    <!-- Public CSS (local, fastest) -->
    <link rel="stylesheet" href="assets/css/public.css">

    <!-- Preload critical above-the-fold image: logo -->
    <link rel="preload" as="image" href="assets/images/logo.png">
</head>

<body class="loading">

    <!-- Preloader Splash Screen -->
    <div id="preloader">
        <div class="preloader-content">
            <div class="merge-animation">
                <span class="letter letter-o">o</span>
                <span class="letter letter-e">e</span>
            </div>
            <div class="final-logo">
                <img src="assets/images/logo.png" alt="Orange Events Logo">
                <h2>ORANGE<span>EVENTS</span></h2>
            </div>
        </div>
    </div>

    <!-- Navigation -->
    <header>
        <div class="nav-container">
            <a href="#" class="logo">
                <img src="assets/images/logo.png" alt="Logo" style="height: 40px; margin-right: 5px;">
                ORANGE<span>EVENTS</span>
            </a>

            <button class="hamburger" id="mobileMenuBtn">
                <i class="fa-solid fa-bars"></i>
            </button>

            <nav class="nav-links" id="navLinks">
                <a href="#home" class="nav-link">Home</a>
                <a href="#about" class="nav-link">About Us</a>
                <a href="#services" class="nav-link">Services & Pricing</a>
                <a href="#gallery" class="nav-link">Gallery</a>
                <a href="terms.php" class="nav-link">Terms</a>
                <a href="privacy.php" class="nav-link">Privacy</a>
                <a href="refund-policy.php" class="nav-link">Refund Policy</a>
                <a href="cancellation-policy.php" class="nav-link">Cancellation</a>
                <a href="checkout.php" class="btn-primary nav-link" style="background: var(--primary, #FF6B00);">Book & Checkout</a>
            </nav>
        </div>
    </header>

    <!-- Hero Section -->
    <section id="home" class="hero">
        <!-- Video Background -->
        <!-- Video: poster shows instantly, video streams in background -->
        <video autoplay muted loop playsinline class="hero-video"
               poster="assets/images/hero-poster.jpg"
               preload="none">
            <source data-src="assets/videos/15496416_1920_1080_50fps.mp4" type="video/mp4">
        </video>
        <div class="hero-overlay"></div>

        <div class="orb orb-1"></div>
        <div class="orb orb-2"></div>
        <div class="orb orb-3"></div>

        <div class="hero-content">
            <div class="badge">Kerala's Premier Event Managers</div>
            <h1>Crafting <span class="text-gradient">Unforgettable</span><br>Experiences</h1>
            <p>From breathtaking stage decorations to mouth-watering premium catering, we handle every detail so you can
                enjoy your special day.</p>
            <div class="hero-btns">
                <a href="#contact" class="btn-primary pulse-btn">Book Your Event <i
                        class="fa-solid fa-calendar-check"></i></a>
                <a href="#gallery" class="btn-secondary">View Our Work</a>
            </div>
        </div>

        <a href="#about" class="scroll-down-arrow">
            <i class="fa-solid fa-chevron-down"></i>
        </a>
    </section>

    <!-- About Section -->
    <section id="about" class="section reveal">
        <div class="about-grid">
            <div class="about-content">
                <div class="badge" style="display: inline-block; margin-bottom: 1rem;">About Us & Our Heritage</div>
                <h3>Crafting Extraordinary Celebrations Across Kerala</h3>
                <p>Operated under trade name <strong><?= h($trade_name) ?></strong> (Proprietor: <strong><?= h($owner_full_name) ?></strong>), based in Thumpoly, Alappuzha, we are Kerala's trusted premier event management agency.</p>
                <p>We combine innovative stage decor design, master culinary catering, and seamless A-to-Z logistics coordination to bring your dream wedding, corporate gala, or family function to life with unmatched elegance.</p>

                <ul class="about-features">
                    <li><i class="fa-solid fa-circle-check" style="color: #FF6B00;"></i> <strong>500+ Grand Events</strong> Managed Successfully</li>
                    <li><i class="fa-solid fa-circle-check" style="color: #FF6B00;"></i> <strong>Registered Business:</strong> <?= h($registered_address) ?></li>
                    <li><i class="fa-solid fa-circle-check" style="color: #FF6B00;"></i> <strong>Top Culinary Chefs & In-House Artisans</strong></li>
                </ul>
            </div>
            <div class="about-image">
                <img src="https://images.unsplash.com/photo-1464366400600-7168b8af9bc3?q=75&w=800&auto=format&fit=crop"
                    alt="Wedding Event Setup"
                    width="800" height="533"
                    loading="lazy" decoding="async">
            </div>
        </div>
    </section>

    <!-- Services & Pricing Section -->
    <section id="services" class="section reveal">
        <div class="section-header">
            <h2>Our Core <span>Services & Transparent Pricing</span></h2>
            <p>Select your desired service package and check out online with instant booking confirmation.</p>
        </div>

        <div class="services-grid">
            <!-- Service 1: Stage Decors -->
            <div class="service-card" style="position: relative;">
                <div class="service-icon">
                    <i class="fa-solid fa-wand-magic-sparkles"></i>
                </div>
                <h3>Premium Stage Decors</h3>
                <p style="margin-bottom: 0.5rem;">Custom floral stage backdrops, 3D wedding thematic setups, ambient LED lighting & pathway arches.</p>
                <div style="background: rgba(255,107,0,0.1); border: 1px solid rgba(255,107,0,0.25); border-radius: 8px; padding: 0.5rem 0.75rem; margin-bottom: 1rem; text-align: center;">
                    <span style="font-size: 0.75rem; color: rgba(255,255,255,0.7); text-transform: uppercase;">Starting From</span>
                    <h4 style="font-size: 1.25rem; color: #FF6B00; margin: 0;">Rs. 15,000 <small style="font-size: 0.75rem; color: #fff;">/ package</small></h4>
                </div>
                <a href="checkout.php?package=stage_royal" class="btn-primary" style="display: block; text-align: center; font-size: 0.85rem; padding: 0.65rem 1rem; border-radius: 8px; text-decoration: none;">
                    Book & Checkout <i class="fa-solid fa-arrow-right"></i>
                </a>
            </div>

            <!-- Service 2: Grand Catering -->
            <div class="service-card" style="position: relative;">
                <div class="service-icon">
                    <i class="fa-solid fa-utensils"></i>
                </div>
                <h3>Grand Catering Feasts</h3>
                <p style="margin-bottom: 0.5rem;">Authentic Kerala Sadhyas on banana leaf, multi-cuisine buffets & live food counters crafted by master chefs.</p>
                <div style="background: rgba(46,213,115,0.1); border: 1px solid rgba(46,213,115,0.25); border-radius: 8px; padding: 0.5rem 0.75rem; margin-bottom: 1rem; text-align: center;">
                    <span style="font-size: 0.75rem; color: rgba(255,255,255,0.7); text-transform: uppercase;">Starting From</span>
                    <h4 style="font-size: 1.25rem; color: #2ED573; margin: 0;">Rs. 350 <small style="font-size: 0.75rem; color: #fff;">/ plate</small></h4>
                </div>
                <a href="checkout.php?package=catering_sadhya" class="btn-primary" style="display: block; text-align: center; font-size: 0.85rem; padding: 0.65rem 1rem; border-radius: 8px; text-decoration: none;">
                    Book & Checkout <i class="fa-solid fa-arrow-right"></i>
                </a>
            </div>

            <!-- Service 3: Event Management -->
            <div class="service-card" style="position: relative;">
                <div class="service-icon">
                    <i class="fa-solid fa-camera"></i>
                </div>
                <h3>Full Event Management</h3>
                <p style="margin-bottom: 0.5rem;">Complete A-to-Z coordination: stage decor, catering, photography, sound systems, transport & guest support.</p>
                <div style="background: rgba(255,165,0,0.1); border: 1px solid rgba(255,165,0,0.25); border-radius: 8px; padding: 0.5rem 0.75rem; margin-bottom: 1rem; text-align: center;">
                    <span style="font-size: 0.75rem; color: rgba(255,255,255,0.7); text-transform: uppercase;">Starting From</span>
                    <h4 style="font-size: 1.25rem; color: #FFA500; margin: 0;">Rs. 45,000 <small style="font-size: 0.75rem; color: #fff;">/ package</small></h4>
                </div>
                <a href="checkout.php?package=event_silver" class="btn-primary" style="display: block; text-align: center; font-size: 0.85rem; padding: 0.65rem 1rem; border-radius: 8px; text-decoration: none;">
                    Book & Checkout <i class="fa-solid fa-arrow-right"></i>
                </a>
            </div>
        </div>
    </section>

    <!-- Gallery Section -->
    <section id="gallery" class="section reveal">
        <div class="section-header">
            <h2>Our <span>Masterpieces</span></h2>
            <p>Take a glimpse into some of the beautiful moments and breathtaking setups we've created.</p>
        </div>

        <div class="gallery-grid">
            <!-- w=600 for gallery cards; q=70 for smaller files; lazy load all -->
            <div class="gallery-item">
                <img src="https://images.unsplash.com/photo-1519225421980-715cb0215aed?q=70&w=600&auto=format&fit=crop"
                    alt="Wedding Stage"
                    width="600" height="450"
                    loading="lazy" decoding="async">
                <div class="gallery-overlay">
                    <h4>Royal Stage Setup</h4>
                    <p>Wedding Reception</p>
                </div>
            </div>
            <div class="gallery-item">
                <img src="https://images.unsplash.com/photo-1555244162-803834f70033?q=70&w=600&auto=format&fit=crop"
                    alt="Premium Catering"
                    width="600" height="450"
                    loading="lazy" decoding="async">
                <div class="gallery-overlay">
                    <h4>Premium Buffet</h4>
                    <p>Grand Catering</p>
                </div>
            </div>
            <div class="gallery-item">
                <img src="https://images.unsplash.com/photo-1478146896981-b80fe463b330?q=70&w=600&auto=format&fit=crop"
                    alt="Lighting Setup"
                    width="600" height="450"
                    loading="lazy" decoding="async">
                <div class="gallery-overlay">
                    <h4>Ambient Lighting</h4>
                    <p>Evening Gala</p>
                </div>
            </div>
            <div class="gallery-item">
                <img src="https://images.unsplash.com/photo-1533142277637-88f5d0239cf9?q=70&w=600&auto=format&fit=crop"
                    alt="Table Arrangement"
                    width="600" height="450"
                    loading="lazy" decoding="async">
                <div class="gallery-overlay">
                    <h4>Elegant Dining</h4>
                    <p>Guest Seating</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Feedback / Testimonials -->
    <section id="feedback" class="section reveal">
        <div class="section-header">
            <h2>Client <span>Feedback</span></h2>
            <p>Don't just take our word for it. Here's what our happy clients have to say about their experience.</p>
        </div>

        <div class="testimonials-grid">
            <div class="testimonial-card">
                <i class="fa-solid fa-quote-right quote-icon"></i>
                <div class="stars">
                    <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i
                        class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i>
                </div>
                <p class="testimonial-text">"Orange Events completely transformed our wedding day. The floral stage
                    decoration was beyond anything we imagined, and the catering was praised by every single guest.
                    Highly recommended!"</p>
                <div class="client-info">
                    <div class="client-avatar">R</div>
                    <div class="client-details">
                        <h4>Rahul & Sneha</h4>
                        <p>Wedding Couple</p>
                    </div>
                </div>
            </div>

            <div class="testimonial-card">
                <i class="fa-solid fa-quote-right quote-icon"></i>
                <div class="stars">
                    <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i
                        class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i>
                </div>
                <p class="testimonial-text">"The level of professionalism is unmatched. We hired them for our corporate
                    anniversary gala. Everything from the lighting to the welcome drinks was handled with absolute
                    perfection."</p>
                <div class="client-info">
                    <div class="client-avatar">K</div>
                    <div class="client-details">
                        <h4>Kiran Kumar</h4>
                        <p>Corporate Director</p>
                    </div>
                </div>
            </div>

            <div class="testimonial-card">
                <i class="fa-solid fa-quote-right quote-icon"></i>
                <div class="stars">
                    <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i
                        class="fa-solid fa-star"></i><i class="fa-solid fa-star-half-stroke"></i>
                </div>
                <p class="testimonial-text">"If you want peace of mind during your family function, hire Orange Events.
                    They took care of the A-to-Z logistics. We just sat back and enjoyed our daughter's baptism party!"
                </p>
                <div class="client-info">
                    <div class="client-avatar">A</div>
                    <div class="client-details">
                        <h4>Anita George</h4>
                        <p>Proud Mother</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Call to Action -->
    <section id="contact" class="section reveal">
        <div class="cta-section">
            <div class="cta-content">
                <h2>Ready to plan your dream event?</h2>
                <p>Contact us today for a free consultation and customized quote based on your requirements and budget.
                    Let's create magic together.</p>
                <a href="https://wa.me/919946731720" target="_blank" class="btn-primary"
                    style="background: #25D366; box-shadow: 0 4px 15px rgba(37, 211, 102, 0.4);">
                    <i class="fa-brands fa-whatsapp"></i> Chat on WhatsApp
                </a>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer>
        <div class="footer-content">
            <div class="footer-brand">
                <div class="logo">ORANGE<span>EVENTS</span></div>
                <p>This website is operated by <?= h($trade_name) ?> (Proprietor: <?= h($owner_full_name) ?>). Making your special moments truly unforgettable with premium decorations and catering services across Kerala.</p>
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
                    <li><i class="fa-solid fa-location-dot"></i>
                        <span><?= h($registered_address) ?></span>
                    </li>
                    <li><i class="fa-solid fa-user-check"></i> <span>Proprietor: <?= h($owner_full_name) ?></span></li>
                    <li><i class="fa-solid fa-phone"></i> <span><?= h($company_phone) ?></span></li>
                    <li><i class="fa-solid fa-envelope"></i> <span><?= h($company_email) ?></span></li>
                </ul>
            </div>
        </div>

        <div class="footer-bottom">
            <p>&copy; <?= date('Y') ?> <?= h($trade_name) ?>. Operated by <?= h($owner_full_name) ?>. All rights reserved.</p>
            <div>
                <a href="login.php"
                    style="color: var(--text-muted); opacity: 0.5; font-size: 0.8rem; text-decoration: none; transition: opacity 0.3s;">
                    <i class="fa-solid fa-lock"></i> Admin Portal
                </a>
            </div>
        </div>
    </footer>

    <script>
        // --- Mobile Menu Toggle ---
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const navLinks = document.getElementById('navLinks');
        const navItems = document.querySelectorAll('.nav-link');

        mobileMenuBtn.addEventListener('click', () => {
            navLinks.classList.toggle('active');
            const icon = mobileMenuBtn.querySelector('i');
            if (navLinks.classList.contains('active')) {
                icon.classList.remove('fa-bars');
                icon.classList.add('fa-xmark');
            } else {
                icon.classList.remove('fa-xmark');
                icon.classList.add('fa-bars');
            }
        });

        // Close mobile menu when a link is clicked
        navItems.forEach(item => {
            item.addEventListener('click', () => {
                navLinks.classList.remove('active');
                mobileMenuBtn.querySelector('i').classList.remove('fa-xmark');
                mobileMenuBtn.querySelector('i').classList.add('fa-bars');
            });
        });

        // --- Smooth scrolling for anchor links ---
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    // Offset for fixed header
                    const headerOffset = 80;
                    const elementPosition = target.getBoundingClientRect().top;
                    const offsetPosition = elementPosition + window.pageYOffset - headerOffset;

                    window.scrollTo({
                        top: offsetPosition,
                        behavior: "smooth"
                    });
                }
            });
        });

        // --- Lazy-load the hero video after page load ---
        // This prevents the 8MB video from blocking the initial render
        window.addEventListener('load', () => {
            const heroVideo = document.querySelector('.hero-video');
            if (heroVideo) {
                const source = heroVideo.querySelector('source[data-src]');
                if (source) {
                    source.src = source.dataset.src;
                    heroVideo.load();
                }
            }
        });

        // --- Preloader Splash Screen ---
        // Dismiss as soon as the animation is done (1.8s), don't wait for video
        window.addEventListener('DOMContentLoaded', () => {
            setTimeout(() => {
                const preloader = document.getElementById('preloader');
                preloader.classList.add('fade-out');
                document.body.classList.remove('loading');
            }, 1800); // Animation takes ~1.6s; 200ms buffer
        });

        // --- Header scroll effect ---
        window.addEventListener('scroll', () => {
            const header = document.querySelector('header');
            if (window.scrollY > 50) {
                header.classList.add('scrolled');
            } else {
                header.classList.remove('scrolled');
            }
        });

        // --- Scroll Reveal Animations ---
        // Uses Intersection Observer to add 'active' class when elements scroll into view
        const revealElements = document.querySelectorAll('.reveal');

        const revealCallback = (entries, observer) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('active');
                    // Optional: Stop observing once revealed if you only want it to animate once
                    // observer.unobserve(entry.target);
                }
            });
        };

        const revealOptions = {
            threshold: 0.15, // Trigger when 15% of the element is visible
            rootMargin: "0px 0px -50px 0px"
        };

        const revealObserver = new IntersectionObserver(revealCallback, revealOptions);

        revealElements.forEach(el => {
            revealObserver.observe(el);
        });

        // Trigger check on load for elements already in viewport
        setTimeout(() => {
            revealElements.forEach(el => {
                const rect = el.getBoundingClientRect();
                if (rect.top < window.innerHeight) {
                    el.classList.add('active');
                }
            });
        }, 100);
    </script>
</body>

</html>