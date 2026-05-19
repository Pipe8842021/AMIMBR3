<?php
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth_check.php';

try {
    $stmt = $pdo->prepare("
        SELECT id, nombre, email, rol, estado, foto_perfil 
        FROM usuarios 
        WHERE id = ?
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        session_destroy();
        header("Location: ../../auth/login.php");
        exit;
    }
    
    if ($user['estado'] !== 'activo') {
        session_destroy();
        header("Location: ../../auth/login.php?error=cuenta_inactiva");
        exit;
    }
    
} catch (PDOException $e) {
    error_log("Error al obtener datos de usuario: " . $e->getMessage());
    die("Error del sistema. Por favor, intenta más tarde.");
}

switch ($user['rol']) {
    case 'admin':
        if (basename($_SERVER['PHP_SELF']) === 'admin.php') {
            break;
        } else {
            require_once __DIR__ . '/admin.php';
            exit;
        }

    case 'profesor':
        if (basename($_SERVER['PHP_SELF']) === 'profesor.php') {
            break;
        } else {
            require_once __DIR__ . '/profesor.php';
            exit;
        }

    case 'estudiante':
        if (basename($_SERVER['PHP_SELF']) === 'estudiante.php') {
            break;
        } else {
            require_once __DIR__ . '/estudiante.php';
            exit;
        }

    default:
        error_log("Rol desconocido: " . $user['rol']);
        session_destroy();
        header("Location: ../../auth/login.php?error=rol_invalido");
        exit;
}
?>