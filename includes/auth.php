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
        header('Location: ../index.php');
        exit;
    }
}

/**
 * Verify administrative credentials.
 * 
 * @param string $username
 * @param string $password
 * @return bool
 */
function authenticate_user($username, $password) {
    require_once __DIR__ . '/../config/database.php';
    $db = get_db_connection();
    
    $stmt = $db->prepare("SELECT * FROM users WHERE username = :username LIMIT 1");
    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_id'] = $user['id'];
        $_SESSION['admin_username'] = $user['username'];
        $_SESSION['admin_role'] = $user['role'];
        return true;
    }
    
    return false;
}
