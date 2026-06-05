<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_rol'])) {
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
    $login_path = '/auth/login.php';
    $current_dir = dirname($_SERVER['PHP_SELF']);
    if (strpos($current_dir, '/modules/') !== false) {
        $login_path = '/auth/login.php';
    } elseif (strpos($current_dir, '/auth/') !== false) {
        $login_path = '/auth/login.php';
    }
    header("Location: $login_path");
    exit;
}

if (!function_exists('has_role')) {
    function has_role($rol) {
        return isset($_SESSION['user_rol']) && $_SESSION['user_rol'] === $rol;
    }
}

if (!function_exists('has_any_role')) {
    function has_any_role($roles) {
        return isset($_SESSION['user_rol']) && in_array($_SESSION['user_rol'], $roles);
    }
}

if (!function_exists('require_role')) {
    function require_role($rol) {
        if (!has_role($rol)) {
            header("Location: ../../auth/login.php?error=acceso_denegado");
            exit;
        }
    }
}

if (!function_exists('require_any_role')) {
    function require_any_role($roles) {
        if (!has_any_role($roles)) {
            header("Location: ../../auth/login.php?error=acceso_denegado");
            exit;
        }
    }
}

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

if (!function_exists('set_flash_message')) {
    function set_flash_message($message, $type = 'info') {
        $_SESSION['flash_message'] = [
            'message' => $message,
            'type' => $type
        ];
    }
}
?>