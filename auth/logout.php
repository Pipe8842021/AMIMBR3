<?php
require_once '../config/session.php';
require_once '../config/database.php';

if (is_logged_in()) {
    try {
        log_activity($pdo, $_SESSION['user_id'], 'logout', 'Usuario cerró sesión');
    } catch (Exception $e) {
        error_log("Error logging logout: " . $e->getMessage());
    }
}

logout('../public/index.php');