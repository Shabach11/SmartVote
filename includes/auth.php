<?php

// includes/auth.php
require_once __DIR__ . '/../config.php';

function login_user($user) {
    $_SESSION['user_id']   = $user['id'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['email']     = $user['email'];
    $_SESSION['role']      = $user['role'];
    
    // Regenerate session ID for security
    session_regenerate_id(true);
    try {
        global $pdo;
        $stmt = $pdo->prepare('INSERT INTO login_activity_logs (user_id, ip_address) VALUES (?, ?)');
        $stmt->execute([$user['id'], client_ip()]);
    } catch (Exception $e) {
        // Login must remain available if activity logging is unavailable.
    }
}

function logout() {
    session_destroy();
    redirect('/login.php');
}
?>
