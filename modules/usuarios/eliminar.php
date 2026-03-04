<?php
/**
 * Gestión de Estado de Usuario
 * Script para activar o desactivar usuarios (no hay eliminación permanente)
 */

// Incluir configuración de sesión y base de datos
require_once '../../config/session.php';
require_once '../../config/database.php';

// Verificar autenticación
require_once '../../includes/auth_check.php';

// helper de notificaciones
require_once '../../includes/notificaciones_helper.php';

// Verificar que sea administrador
require_role('admin');

// Obtener parámetros
$usuario_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$action = isset($_GET['action']) ? $_GET['action'] : '';

// Validar parámetros - ahora solo aceptamos 'activar' y 'desactivar'
if (!$usuario_id || !in_array($action, ['activar', 'desactivar'])) {
    if (function_exists('set_flash_message')) {
        set_flash_message('Parámetros inválidos', 'error');
    }
    header("Location: index.php");
    exit;
}

// Verificar que el usuario existe
try {
    $stmt = $pdo->prepare("SELECT nombre, rol, estado FROM usuarios WHERE id = ?");
    $stmt->execute([$usuario_id]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$usuario) {
        if (function_exists('set_flash_message')) {
            set_flash_message('Usuario no encontrado', 'error');
        }
        header("Location: index.php");
        exit;
    }
} catch (PDOException $e) {
    error_log("Error verificando usuario: " . $e->getMessage());
    die("Error del sistema.");
}

// Prevenir auto-modificación del propio usuario
if ($usuario_id === $_SESSION['user_id']) {
    if (function_exists('set_flash_message')) {
        set_flash_message('No puedes modificar el estado de tu propio usuario', 'error');
    }
    header("Location: index.php");
    exit;
}

// Procesar la acción
try {
    $pdo->beginTransaction();
    
    if ($action === 'activar') {
        // Activar usuario
        $stmt = $pdo->prepare("UPDATE usuarios SET estado = 'activo' WHERE id = ?");
        $stmt->execute([$usuario_id]);
        
        // Log
        $stmt = $pdo->prepare("
            INSERT INTO logs_actividad (usuario_id, accion, descripcion, fecha)
            VALUES (?, 'activar_usuario', ?, NOW())
        ");
        $stmt->execute([
            $_SESSION['user_id'],
            "Usuario '{$usuario['nombre']}' (ID: {$usuario_id}) activado"
        ]);
        
        $mensaje = 'Usuario activado exitosamente';
        $tipo = 'success';
        
    } elseif ($action === 'desactivar') {
        // Desactivar usuario (esto incluye lo que antes era "eliminar")
        $stmt = $pdo->prepare("UPDATE usuarios SET estado = 'inactivo' WHERE id = ?");
        $stmt->execute([$usuario_id]);
        
        // Log
        $stmt = $pdo->prepare("
            INSERT INTO logs_actividad (usuario_id, accion, descripcion, fecha)
            VALUES (?, 'desactivar_usuario', ?, NOW())
        ");
        $stmt->execute([
            $_SESSION['user_id'],
            "Usuario '{$usuario['nombre']}' (ID: {$usuario_id}) desactivado"
        ]);
        
        $mensaje = 'Usuario desactivado exitosamente';
        $tipo = 'success';
    }
    
    $pdo->commit();
    
    if (function_exists('set_flash_message')) {
        set_flash_message($mensaje, $tipo);
    }
    
} catch (PDOException $e) {
    $pdo->rollBack();
    error_log("Error procesando acción: " . $e->getMessage());
    if (function_exists('set_flash_message')) {
        set_flash_message('Error al procesar la acción. Por favor, intenta de nuevo.', 'error');
    }
}

header("Location: index.php");
exit;
?>