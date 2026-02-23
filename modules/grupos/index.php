<?php
require_once '../../config/session.php';
require_once '../../includes/auth_check.php';

$rol = $_SESSION['user_rol'] ?? null;

switch ($rol) {
    case 'admin':
        header('Location: admin.php');
        break;
    case 'profesor':
        header('Location: profesor.php');
        break;
    case 'estudiante':
        header('Location: estudiante.php');
        break;
    default:
        header('Location: ../../auth/logout.php');
}
exit;
