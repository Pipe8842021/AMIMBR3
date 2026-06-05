<?php
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_secure', 0); // Cambiar a 1 en producción con HTTPS
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.gc_maxlifetime', 3600);
    ini_set('session.cookie_lifetime', 0);
    session_name('AMIMBRE_SESSION');
    session_start();

    // Regenerar ID de sesión periódicamente para prevenir session fixation
    if (!isset($_SESSION['created'])) {
        $_SESSION['created'] = time();
    } else if (time() - $_SESSION['created'] > 1800) {
        session_regenerate_id(true);
        $_SESSION['created'] = time();
    }
}

function is_logged_in() {
    return isset($_SESSION['user_id']);
}

function has_role($role) {
    return isset($_SESSION['user_rol']) && $_SESSION['user_rol'] === $role;
}

function has_any_role($roles) {
    if (!isset($_SESSION['user_rol'])) {
        return false;
    }
    return in_array($_SESSION['user_rol'], $roles);
}

function require_login($redirect = '../auth/login.php') {
    if (!is_logged_in()) {
        header("Location: $redirect");
        exit;
    }
}

function require_role($role, $redirect = '../public/index.html') {
    require_login();
    if (!has_role($role)) {
        header("Location: $redirect");
        exit;
    }
}

function logout($redirect = '../public/index.html') {
    $_SESSION = array();

    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 3600, '/');
    }

    if (isset($_COOKIE['remember_token'])) {
        setcookie('remember_token', '', time() - 3600, '/');
    }

    session_destroy();
    header("Location: $redirect");
    exit;
}

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

function set_flash_message($message, $type = 'info') {
    $_SESSION['flash_message'] = [
        'message' => $message,
        'type' => $type
    ];
}

function get_flash_message() {
    if (isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        unset($_SESSION['flash_message']);
        return $message;
    }
    return null;
}