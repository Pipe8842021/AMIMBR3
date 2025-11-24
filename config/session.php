<?php
/**
 * Configuración y Gestión de Sesiones - Amimbré
 * Este archivo debe incluirse ANTES de cualquier session_start()
 */

// Configurar parámetros de sesión solo si no hay sesión activa
if (session_status() === PHP_SESSION_NONE) {
    // Configuración de seguridad de sesiones
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_secure', 0); // Cambiar a 1 en producción con HTTPS
    ini_set('session.cookie_samesite', 'Lax');
    
    // Configuración de tiempo de vida de la sesión
    ini_set('session.gc_maxlifetime', 3600); // 1 hora
    ini_set('session.cookie_lifetime', 0); // Hasta cerrar navegador
    
    // Nombre de la sesión personalizado
    session_name('AMIMBRE_SESSION');
    
    // Iniciar sesión
    session_start();
    
    // Regenerar ID de sesión periódicamente para mayor seguridad
    if (!isset($_SESSION['created'])) {
        $_SESSION['created'] = time();
    } else if (time() - $_SESSION['created'] > 1800) { // 30 minutos
        session_regenerate_id(true);
        $_SESSION['created'] = time();
    }
}

/**
 * Función para verificar si el usuario está autenticado
 */
function is_logged_in() {
    return isset($_SESSION['user_id']);
}

/**
 * Función para verificar el rol del usuario
 */
function has_role($role) {
    return isset($_SESSION['user_rol']) && $_SESSION['user_rol'] === $role;
}

/**
 * Función para verificar múltiples roles
 */
function has_any_role($roles) {
    if (!isset($_SESSION['user_rol'])) {
        return false;
    }
    return in_array($_SESSION['user_rol'], $roles);
}

/**
 * Función para requerir autenticación
 */
function require_login($redirect = '../auth/login.php') {
    if (!is_logged_in()) {
        header("Location: $redirect");
        exit;
    }
}

/**
 * Función para requerir un rol específico
 */
function require_role($role, $redirect = '../public/index.html') {
    require_login();
    if (!has_role($role)) {
        header("Location: $redirect");
        exit;
    }
}

/**
 * Función para cerrar sesión
 */
function logout($redirect = '../public/index.html') {
    // Limpiar todas las variables de sesión
    $_SESSION = array();
    
    // Eliminar cookie de sesión
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 3600, '/');
    }
    
    // Eliminar cookie de "recordarme"
    if (isset($_COOKIE['remember_token'])) {
        setcookie('remember_token', '', time() - 3600, '/');
    }
    
    // Destruir sesión
    session_destroy();
    
    // Redirigir
    header("Location: $redirect");
    exit;
}

/**
 * Función para obtener datos del usuario en sesión
 */
function get_session_user() {
    if (!is_logged_in()) {
        return null;
    }
    
    return [
        'id' => $_SESSION['user_id'] ?? null,
        'nombre' => $_SESSION['user_nombre'] ?? null,
        'email' => $_SESSION['user_email'] ?? null,
        'rol' => $_SESSION['user_rol'] ?? null
    ];
}

/**
 * Función para establecer mensaje flash
 */
function set_flash_message($message, $type = 'info') {
    $_SESSION['flash_message'] = [
        'message' => $message,
        'type' => $type
    ];
}

/**
 * Función para obtener y limpiar mensaje flash
 */
function get_flash_message() {
    if (isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        unset($_SESSION['flash_message']);
        return $message;
    }
    return null;
}