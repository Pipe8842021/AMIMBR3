<?php
/**
 * Logout - Cerrar Sesión
 */

require_once '../config/session.php';
require_once '../config/database.php';

// Registrar logout en logs si el usuario está autenticado
if (is_logged_in()) {
    try {
        log_activity($pdo, $_SESSION['user_id'], 'logout', 'Usuario cerró sesión');
    } catch (Exception $e) {
        error_log("Error logging logout: " . $e->getMessage());
    }
}

// Cerrar sesión y redirigir
logout('../public/index.php');