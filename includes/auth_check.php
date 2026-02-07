<?php
/**
 * Verificación de Autenticación
 * Este archivo verifica que el usuario esté autenticado
 * Debe incluirse en todas las páginas protegidas
 */

// Iniciar sesión si no está iniciada
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verificar si el usuario está autenticado
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_rol'])) {
    // No hay sesión activa, redirigir al login
    
    // Guardar la URL actual para redirigir después del login
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
    
    // Determinar la ruta al login según la ubicación actual
    $login_path = '../../auth/login.php';
    
    // Si estamos en una subcarpeta diferente, ajustar la ruta
    $current_dir = dirname($_SERVER['PHP_SELF']);
    if (strpos($current_dir, '/modules/') !== false) {
        $login_path = '../../auth/login.php';
    } elseif (strpos($current_dir, '/auth/') !== false) {
        $login_path = './login.php';
    }
    
    header("Location: $login_path");
    exit;
}

// NOTA: La función is_logged_in() ya está definida en config/session.php
// No la redeclaramos aquí para evitar errores

// Función para verificar si el usuario tiene un rol específico
if (!function_exists('has_role')) {
    function has_role($rol) {
        return isset($_SESSION['user_rol']) && $_SESSION['user_rol'] === $rol;
    }
}

// Función para verificar si el usuario tiene uno de varios roles
if (!function_exists('has_any_role')) {
    function has_any_role($roles) {
        return isset($_SESSION['user_rol']) && in_array($_SESSION['user_rol'], $roles);
    }
}

// Función para requerir un rol específico (redirige si no lo tiene)
if (!function_exists('require_role')) {
    function require_role($rol) {
        if (!has_role($rol)) {
            header("Location: ../../auth/login.php?error=acceso_denegado");
            exit;
        }
    }
}

// Función para requerir uno de varios roles
if (!function_exists('require_any_role')) {
    function require_any_role($roles) {
        if (!has_any_role($roles)) {
            header("Location: ../../auth/login.php?error=acceso_denegado");
            exit;
        }
    }
}

// Función para obtener mensajes flash (si no existe en session.php)
if (!function_exists('get_flash_message')) {
    function get_flash_message() {
        if (isset($_SESSION['flash_message'])) {
            $message = $_SESSION['flash_message'];
            unset($_SESSION['flash_message']);
            return $message;
        }
        return null;
    }
}

// Función para establecer mensajes flash (si no existe en session.php)
if (!function_exists('set_flash_message')) {
    function set_flash_message($message, $type = 'info') {
        $_SESSION['flash_message'] = [
            'message' => $message,
            'type' => $type
        ];
    }
}
?>