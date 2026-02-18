<?php

/**
 * Dashboard Router
 * Redirige al dashboard específico según el rol del usuario
 */

require_once '../../includes/auth_check.php';

$user = get_session_user();
$rol = $user['rol'] ?? null;

// Redirigir según el rol
switch ($rol) {
    case 'admin':
        require_once '../modules/horarios/admin.php';
        break;

    case 'profesor':
        require_once 'horarios/profesor.php';
        break;

    case 'estudiante':
        require_once 'horarios/estudiante.php';
        break;

    default:
        // Si no tiene rol válido, cerrar sesión
        set_flash_message('Rol de usuario no válido', 'error');
        header('Location: ../../auth/logout.php');
        exit;
}
