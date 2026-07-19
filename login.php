<?php
require_once __DIR__ . '/includes/auth.php';

if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: admin/index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        if (authenticate_user($username, $password)) {
            header('Location: admin/index.php');
            exit;
        } else {
            $error = 'Invalid username or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orange Events - Administrator Login</title>
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
    <!-- FontAwesome CDNs -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Style -->
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
            overflow-x: hidden;
            display: block;
            padding: 0;
        }

        .login-container {
            display: flex;
            min-height: 100vh;
            width: 100%;
        }

        /* Image Side styling */
        .login-image-side {
            position: relative;
            flex: 1.2;
            background-image: url('assets/images/collage/stage.jpg');
            background-size: cover;
            background-position: center;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 4rem;
        }

        /* Dark overlay on the image side to ensure text is legible */
        .login-image-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(10, 14, 23, 0.92) 0%, rgba(24, 34, 53, 0.75) 100%);
            z-index: 1;
        }

        .login-image-content {
            position: relative;
            z-index: 2;
            max-width: 550px;
            color: #ffffff;
        }

        .brand-logo-large {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .brand-logo-large img {
            height: 70px;
            width: auto;
        }

        .brand-logo-large h2 {
            font-family: 'Outfit', sans-serif;
            font-size: 2.8rem;
            font-weight: 800;
            letter-spacing: 0.05em;
            color: #f07c1b;
            line-height: 1.1;
        }

        .brand-logo-large h2 span {
            color: #ffffff;
        }

        .brand-tagline {
            font-size: 1.25rem;
            line-height: 1.6;
            margin-bottom: 3rem;
            color: var(--text-secondary);
            font-weight: 300;
        }

        .brand-features {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .feature-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            font-size: 1.05rem;
            color: var(--text-primary);
        }

        .feature-item i {
            font-size: 1.3rem;
            color: var(--accent-color);
            background: rgba(255, 107, 53, 0.15);
            width: 42px;
            height: 42px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }

        /* Form Side styling */
        .login-form-side {
            flex: 1;
            background-color: var(--bg-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 3rem 2rem;
            position: relative;
            border-left: 1px solid var(--border-color);
        }

        .login-form-wrapper {
            width: 100%;
            max-width: 400px;
        }

        .login-mobile-logo {
            display: none;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            margin-bottom: 2rem;
        }

        .login-mobile-logo img {
            height: 50px;
            width: auto;
        }

        .login-mobile-logo h2 {
            font-family: 'Outfit', sans-serif;
            font-size: 1.8rem;
            font-weight: 800;
            letter-spacing: 0.05em;
            color: #f07c1b;
            line-height: 1.1;
        }

        .login-mobile-logo h2 span {
            color: #ffffff;
        }

        .login-title {
            font-size: 2.2rem;
            font-weight: 800;
            font-family: 'Outfit', sans-serif;
            margin-bottom: 0.5rem;
            color: var(--text-primary);
        }

        .login-subtitle {
            color: var(--text-secondary);
            font-size: 0.95rem;
            margin-bottom: 2.5rem;
        }

        .login-form {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .input-group {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 1.2rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 1.1rem;
            transition: color 0.3s ease;
            pointer-events: none;
        }

        .form-control {
            width: 100%;
            height: 54px;
            padding: 0 1rem 0 3.2rem;
            background-color: var(--bg-control);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius-sm);
            color: var(--text-primary);
            font-family: inherit;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            background-color: rgba(255, 255, 255, 0.05);
            border-color: var(--accent-color);
            box-shadow: 0 0 0 3px rgba(255, 107, 53, 0.15);
            outline: none;
        }

        .form-control:focus + .input-icon {
            color: var(--accent-color);
        }

        .login-btn {
            width: 100%;
            height: 54px;
            margin-top: 1.25rem;
            font-size: 1.05rem;
        }

        .alert-error {
            background-color: rgba(255, 71, 87, 0.15);
            color: var(--danger);
            border: 1px solid rgba(255, 71, 87, 0.3);
            border-radius: var(--border-radius-sm);
            padding: 0.9rem 1.2rem;
            font-size: 0.9rem;
            margin-bottom: 1.5rem;
            text-align: left;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .login-footer {
            margin-top: 3rem;
            font-size: 0.85rem;
            color: var(--text-muted);
            text-align: center;
            line-height: 1.5;
        }

        .demo-credentials {
            font-size: 0.75rem;
            opacity: 0.75;
            display: inline-block;
            margin-top: 0.5rem;
        }

        /* Responsive styling */
        @media (max-width: 1024px) {
            .login-image-side {
                padding: 2.5rem;
            }
            .brand-logo-large h2 {
                font-size: 2.2rem;
            }
            .brand-tagline {
                font-size: 1.1rem;
                margin-bottom: 2rem;
            }
        }

        @media (max-width: 768px) {
            .login-image-side {
                display: none;
            }
            
            .login-form-side {
                flex: 1;
                border-left: none;
                padding: 3rem 1.5rem;
            }

            .login-mobile-logo {
                display: flex;
            }

            .login-title, .login-subtitle {
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <!-- Image Side (Left) -->
        <div class="login-image-side">
            <div class="login-image-overlay"></div>
            <div class="login-image-content">
                <div class="brand-logo-large">
                    <img src="assets/images/logo.png" alt="Orange Events Logo">
                    <h2>ORANGE <span>EVENTS</span></h2>
                </div>
                <p class="brand-tagline">We turn your celebration dreams into elegant & extraordinary realities.</p>
                <div class="brand-features">
                    <div class="feature-item">
                        <i class="fa-solid fa-wand-magic-sparkles"></i>
                        <span>Bespoke Stage Designs</span>
                    </div>
                    <div class="feature-item">
                        <i class="fa-solid fa-champagne-glasses"></i>
                        <span>Exquisite Event Decor</span>
                    </div>
                    <div class="feature-item">
                        <i class="fa-solid fa-heart"></i>
                        <span>Memorable Experiences</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Form Side (Right) -->
        <div class="login-form-side">
            <div class="login-form-wrapper">
                <div class="login-mobile-logo">
                    <img src="assets/images/logo.png" alt="Orange Events Logo">
                    <h2>ORANGE <span>EVENTS</span></h2>
                </div>
                
                <h1 class="login-title">Welcome Back</h1>
                <p class="login-subtitle">Management Control Center Login</p>
                
                <?php if (!empty($error)): ?>
                    <div class="alert-error">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <span><?= htmlspecialchars($error) ?></span>
                    </div>
                <?php endif; ?>
                
                <form action="" method="POST" class="login-form">
                    <div class="form-group" style="text-align: left;">
                        <label for="username" class="form-label">Username</label>
                        <div class="input-group">
                            <input type="text" id="username" name="username" class="form-control" placeholder="admin" required autocomplete="username">
                            <i class="fa-solid fa-user input-icon"></i>
                        </div>
                    </div>
                    
                    <div class="form-group" style="text-align: left; margin-bottom: 2rem;">
                        <label for="password" class="form-label">Password</label>
                        <div class="input-group">
                            <input type="password" id="password" name="password" class="form-control" placeholder="••••••••" required autocomplete="current-password">
                            <i class="fa-solid fa-lock input-icon"></i>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary login-btn">
                        <span>Sign In</span>
                        <i class="fa-solid fa-arrow-right-to-bracket"></i>
                    </button>
                </form>
                
                <div class="login-footer">
                    &copy; 2026 Orange Events. All rights reserved.<br>
                    <span class="demo-credentials">Default demo login: <strong>admin</strong> / <strong>admin123</strong></span>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
