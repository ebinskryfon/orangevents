<?php
/**
 * Authentication and Session Helper
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Check if the user is currently logged in.
 * If not, redirect them to the login screen.
 */
function check_admin_auth() {
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        header('Location: ../login.php');
        exit;
    }
}

/**
 * Verify administrative credentials and load permissions.
 * 
 * @param string $username
 * @param string $password
 * @return bool
 */
function authenticate_user($username, $password) {
    require_once __DIR__ . '/../config/database.php';
    $db = get_db_connection();
    
    $stmt = $db->prepare("
        SELECT u.*, r.role_name AS assigned_role
          FROM users u
     LEFT JOIN user_roles ur ON u.id = ur.user_id
     LEFT JOIN roles r ON ur.role_id = r.id
         WHERE u.username = :username 
         LIMIT 1
    ");
    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_id'] = $user['id'];
        $_SESSION['admin_username'] = $user['username'];
        
        $role = $user['assigned_role'] ?: $user['role'];
        $_SESSION['admin_role'] = $role;
        
        // Cache permissions
        $perms = [];
        if ($role === 'admin') {
            // Admins get all permissions
            $p_stmt = $db->query("SELECT permission_key FROM permissions");
            $perms = $p_stmt->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $p_stmt = $db->prepare("
                SELECT p.permission_key 
                  FROM user_roles ur
                  JOIN role_permissions rp ON ur.role_id = rp.role_id
                  JOIN permissions p ON rp.permission_id = p.id
                 WHERE ur.user_id = :user_id
            ");
            $p_stmt->execute(['user_id' => $user['id']]);
            $perms = $p_stmt->fetchAll(PDO::FETCH_COLUMN);
        }
        $_SESSION['admin_permissions'] = $perms;
        
        return true;
    }
    
    return false;
}

/**
 * Check if the currently logged in user has a specific permission.
 * 
 * @param string $permission_key
 * @return bool
 */
function has_permission($permission_key) {
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        return false;
    }
    // Admin role has all rights
    if (isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'admin') {
        return true;
    }
    return isset($_SESSION['admin_permissions']) && in_array($permission_key, $_SESSION['admin_permissions']);
}

/**
 * Enforce a specific permission to access a page.
 * 
 * @param string $permission_key
 */
function require_permission($permission_key) {
    check_admin_auth();
    if (!has_permission($permission_key)) {
        if (headers_sent()) {
            // Render Access Denied inside the current theme layout
            echo "
            <div style='display: flex; align-items: center; justify-content: center; min-height: 60vh;'>
                <div style='text-align: center; max-width: 450px; padding: 2.5rem; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--border-radius-lg); box-shadow: var(--box-shadow); margin: 2rem auto;'>
                    <i class='fa-solid fa-circle-exclamation' style='font-size: 3.5rem; color: var(--danger); margin-bottom: 1.5rem; display: block;'></i>
                    <h1 style='font-size: 1.6rem; font-weight: 800; margin-bottom: 0.5rem; color: var(--text-primary);'>Access Denied</h1>
                    <p style='color: var(--text-secondary); font-size: 0.95rem; line-height: 1.5; margin-bottom: 1.5rem;'>You do not have the required permission ('" . htmlspecialchars($permission_key) . "') to access this page.</p>
                    <a href='index.php' class='btn btn-primary' style='text-decoration: none; padding: 0.65rem 1.5rem; font-weight: 600; display: inline-block;'>Go to Dashboard</a>
                </div>
            </div>";
            require_once __DIR__ . '/../includes/footer.php';
            exit;
        } else {
            // Render stand-alone Access Denied page
            header('HTTP/1.1 403 Forbidden');
            echo "<!DOCTYPE html>
            <html>
            <head>
                <title>Access Denied</title>
                <link rel='stylesheet' href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css'>
                <link rel='stylesheet' href='../assets/css/style.css'>
            </head>
            <body style='background: var(--bg-primary); color: var(--text-primary); font-family: sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0;'>
                <div style='text-align: center; max-width: 450px; padding: 2rem; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--border-radius-lg); box-shadow: var(--box-shadow);'>
                    <i class='fa-solid fa-circle-exclamation' style='font-size: 3rem; color: var(--danger); margin-bottom: 1.5rem; display: block;'></i>
                    <h1 style='font-size: 1.5rem; font-weight: 800; margin-bottom: 0.5rem;'>Access Denied</h1>
                    <p style='color: var(--text-secondary); font-size: 0.9rem; line-height: 1.5; margin-bottom: 1.5rem;'>You do not have the required permission ('" . htmlspecialchars($permission_key) . "') to access this page.</p>
                    <a href='index.php' class='btn btn-primary' style='text-decoration: none; padding: 0.6rem 1.25rem; font-weight: 600; display: inline-block;'>Go to Dashboard</a>
                </div>
            </body>
            </html>";
            exit;
        }
    }
}
