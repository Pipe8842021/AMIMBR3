<?php
/**
 * Dashboard Router
 * Redirige a cada usuario según su rol
 */

// Incluir configuración de sesión y base de datos
require_once '../../config/session.php';
require_once '../../config/database.php';

// Verificar autenticación
require_once '../../includes/auth_check.php';

// Obtener datos del usuario actual
try {
    $stmt = $pdo->prepare("
        SELECT id, nombre, email, rol, estado, foto_perfil 
        FROM usuarios 
        WHERE id = ?
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        // Usuario no encontrado, cerrar sesión
        session_destroy();
        header("Location: ../../auth/login.php");
        exit;
    }
    
    // Verificar que la cuenta esté activa
    if ($user['estado'] !== 'activo') {
        session_destroy();
        header("Location: ../../auth/login.php?error=cuenta_inactiva");
        exit;
    }
    
} catch (PDOException $e) {
    error_log("Error al obtener datos de usuario: " . $e->getMessage());
    die("Error del sistema. Por favor, intenta más tarde.");
}

// Redirigir según el rol del usuario
switch ($user['rol']) {
    case 'admin':
        // Si ya estamos en admin.php, incluirlo, sino redirigir
        if (basename($_SERVER['PHP_SELF']) === 'admin.php') {
            // Ya estamos en admin.php, no hacer nada
            break;
        } else {
            require_once __DIR__ . '/admin.php';
            exit;
        }
        
    case 'profesor':
        // Si ya estamos en profesor.php, incluirlo, sino redirigir
        if (basename($_SERVER['PHP_SELF']) === 'profesor.php') {
            // Ya estamos en profesor.php, no hacer nada
            break;
        } else {
            require_once __DIR__ . '/profesor.php';
            exit;
        }
        
    case 'estudiante':
        // Si ya estamos en estudiante.php, incluirlo, sino redirigir
        if (basename($_SERVER['PHP_SELF']) === 'estudiante.php') {
            // Ya estamos en estudiante.php, no hacer nada
            break;
        } else {
            require_once __DIR__ . '/estudiante.php';
            exit;
        }
        
    default:
        // Rol no reconocido
        error_log("Rol desconocido: " . $user['rol']);
        session_destroy();
        header("Location: ../../auth/login.php?error=rol_invalido");
        exit;
}
?>