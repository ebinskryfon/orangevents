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
        }
        
        .login-card {
            width: 100%;
            max-width: 420px;
            background: rgba(24, 34, 53, 0.6);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: var(--border-radius-lg);
            padding: 3rem 2.5rem;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.4);
            text-align: center;
        }
        
        .login-logo {
            font-size: 2.8rem;
            font-weight: 800;
            color: var(--accent-color);
            margin-bottom: 0.5rem;
            font-family: 'Outfit', sans-serif;
        }
        
        .login-logo span {
            color: var(--text-primary);
        }
        
        .login-subtitle {
            color: var(--text-secondary);
            font-size: 0.95rem;
            margin-bottom: 2.5rem;
        }
        
        .alert-error {
            background-color: rgba(255, 71, 87, 0.15);
            color: var(--danger);
            border: 1px solid rgba(255, 71, 87, 0.3);
            border-radius: var(--border-radius-sm);
            padding: 0.75rem 1rem;
            font-size: 0.85rem;
            margin-bottom: 1.5rem;
            text-align: left;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .login-footer {
            margin-top: 2rem;
            font-size: 0.8rem;
            color: var(--text-muted);
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="login-logo-container" style="margin-bottom: 1.25rem;">
            <img src="assets/images/logo.png" alt="Orange Events Logo" class="login-logo-img" style="height: 65px; width: auto; display: block; margin: 0 auto 0.5rem auto;">
            <div class="login-logo-title" style="font-size: 2rem; font-weight: 800; font-family: 'Outfit', sans-serif; letter-spacing: 0.05em; line-height: 1.1;"><span style="color: #f07c1b;">ORANGE</span> <span style="color: #ffffff;">EVENTS</span></div>
        </div>
        <p class="login-subtitle">Management Control Center Login</p>
        
        <?php if (!empty($error)): ?>
            <div class="alert-error">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>
        
        <form action="" method="POST">
            <div class="form-group" style="text-align: left;">
                <label for="username" class="form-label">Username</label>
                <div style="position: relative;">
                    <i class="fa-solid fa-user" style="position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: var(--text-muted);"></i>
                    <input type="text" id="username" name="username" class="form-control" placeholder="admin" required style="padding-left: 2.8rem;" autocomplete="username">
                </div>
            </div>
            
            <div class="form-group" style="text-align: left; margin-bottom: 2rem;">
                <label for="password" class="form-label">Password</label>
                <div style="position: relative;">
                    <i class="fa-solid fa-lock" style="position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: var(--text-muted);"></i>
                    <input type="password" id="password" name="password" class="form-control" placeholder="••••••••" required style="padding-left: 2.8rem;" autocomplete="current-password">
                </div>
            </div>
            
            <button type="submit" class="btn btn-primary" style="width: 100%; height: 50px;">
                <span>Sign In</span>
                <i class="fa-solid fa-arrow-right-to-bracket"></i>
            </button>
        </form>
        
        <div class="login-footer">
            &copy; 2026 Orange Events. All rights reserved.<br>
            <span style="font-size: 0.75rem; opacity: 0.6;">Default demo login: <strong>admin</strong> / <strong>admin123</strong></span>
        </div>
    </div>
</body>
</html>
