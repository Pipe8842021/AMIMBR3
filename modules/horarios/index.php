<?php

require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth_check.php';

// Supongamos que tu función para obtener el usuario es similar a esta:
$rol = $_SESSION['user_rol'] ?? null;

// Redirigir según el rol
switch ($rol) {
    case 'admin':
        header('Location: admin.php');
        exit;

    case 'profesor':
        header('Location: profesor.php');
        exit;

    case 'estudiante':
        header('Location: estudiante.php');
        exit;

    default:
        header('Location: ../../auth/logout.php');
        exit;
}
