<?php
/**
 * Eliminar Bitácora
 */

require_once '../../../../config/session.php';
require_once '../../../../config/database.php';
require_once '../../../../includes/auth_check.php';

// Solo admin puede eliminar
require_role('admin');

$bitacora_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($bitacora_id === 0) {
    header("Location: ../index.php?error=bitacora_no_encontrada");
    exit;
}

try {
    // Obtener bitácora
    $stmt = $pdo->prepare("SELECT * FROM bitacoras WHERE id = ?");
    $stmt->execute([$bitacora_id]);
    $bitacora = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$bitacora) {
        header("Location: ../index.php?error=bitacora_no_encontrada");
        exit;
    }
    
    // Marcar como archivada (soft delete)
    $stmt = $pdo->prepare("UPDATE bitacoras SET estado = 'archivado' WHERE id = ?");
    $stmt->execute([$bitacora_id]);
    
    header("Location: ../index.php?success=bitacora_eliminada");
    exit;
    
} catch (PDOException $e) {
    error_log("Error al eliminar bitácora: " . $e->getMessage());
    header("Location: ../index.php?error=error_eliminacion");
    exit;
}
?>