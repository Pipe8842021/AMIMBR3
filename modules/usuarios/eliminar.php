<?php
/**
 * Eliminar/Desactivar Usuario
 * Script para gestionar la eliminación o cambio de estado de usuarios
 */

// Incluir configuración de sesión y base de datos
require_once '../../config/session.php';
require_once '../../config/database.php';

// Verificar autenticación
require_once '../../includes/auth_check.php';

// Verificar que sea administrador
require_role('admin');

// Obtener parámetros
$usuario_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$action = isset($_GET['action']) ? $_GET['action'] : '';

// Validar parámetros
if (!$usuario_id || !in_array($action, ['activar', 'desactivar', 'eliminar'])) {
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

// Prevenir auto-eliminación
if ($usuario_id === $_SESSION['user_id']) {
    if (function_exists('set_flash_message')) {
        set_flash_message('No puedes modificar tu propio usuario de esta manera', 'error');
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
        // Desactivar usuario
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
        
    } elseif ($action === 'eliminar') {
        // Verificar si el usuario tiene datos relacionados
        $tiene_matriculas = false;
        $tiene_bitacoras = false;
        $tiene_documentos = false;
        
        // Verificar matrículas (como estudiante)
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM matriculas WHERE estudiante_id = ?");
        $stmt->execute([$usuario_id]);
        $tiene_matriculas = $stmt->fetch()['total'] > 0;
        
        // Verificar bitácoras (como profesor)
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM bitacoras_clase WHERE profesor_id = ?");
        $stmt->execute([$usuario_id]);
        $tiene_bitacoras = $stmt->fetch()['total'] > 0;
        
        // Verificar documentos creados
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM documentos_administrativos WHERE creado_por = ?");
        $stmt->execute([$usuario_id]);
        $tiene_documentos = $stmt->fetch()['total'] > 0;
        
        if ($tiene_matriculas || $tiene_bitacoras || $tiene_documentos) {
            // No se puede eliminar, solo desactivar
            $pdo->rollBack();
            if (function_exists('set_flash_message')) {
                set_flash_message(
                    'Este usuario tiene datos relacionados y no puede ser eliminado. Se recomienda desactivarlo en su lugar.', 
                    'error'
                );
            }
            header("Location: index.php");
            exit;
        }
        
        // Si no tiene datos relacionados, proceder con la eliminación
        // Eliminar de logs de acceso
        $stmt = $pdo->prepare("DELETE FROM logs_acceso WHERE usuario_id = ?");
        $stmt->execute([$usuario_id]);
        
        // Actualizar logs de actividad (SET NULL por la FK)
        $stmt = $pdo->prepare("UPDATE logs_actividad SET usuario_id = NULL WHERE usuario_id = ?");
        $stmt->execute([$usuario_id]);
        
        // Eliminar el usuario
        $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = ?");
        $stmt->execute([$usuario_id]);
        
        // Log de eliminación
        $stmt = $pdo->prepare("
            INSERT INTO logs_actividad (usuario_id, accion, descripcion, fecha)
            VALUES (?, 'eliminar_usuario', ?, NOW())
        ");
        $stmt->execute([
            $_SESSION['user_id'],
            "Usuario '{$usuario['nombre']}' (ID: {$usuario_id}) eliminado permanentemente"
        ]);
        
        $mensaje = 'Usuario eliminado permanentemente';
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