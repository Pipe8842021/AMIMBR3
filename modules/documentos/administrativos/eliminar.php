<?php
/**
 * Eliminar Documento Administrativo
 */

require_once '../../../config/session.php';
require_once '../../../config/database.php';
require_once '../../../includes/auth_check.php';

// Solo administradores pueden eliminar
require_role('admin');

$doc_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($doc_id === 0) {
    header("Location: index.php?error=documento_no_encontrado");
    exit;
}

try {
    // Obtener documento
    $stmt = $pdo->prepare("SELECT * FROM documentos_administrativos WHERE id = ?");
    $stmt->execute([$doc_id]);
    $documento = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$documento) {
        header("Location: index.php?error=documento_no_encontrado");
        exit;
    }
    
    // Marcar como eliminado (soft delete)
    $stmt = $pdo->prepare("UPDATE documentos_administrativos SET estado = 'eliminado' WHERE id = ?");
    $stmt->execute([$doc_id]);
    
    // Opcional: eliminar archivo físico
    // if (file_exists($documento['ruta_archivo'])) {
    //     unlink($documento['ruta_archivo']);
    // }
    
    header("Location: index.php?success=documento_eliminado");
    exit;
    
} catch (PDOException $e) {
    error_log("Error al eliminar documento: " . $e->getMessage());
    header("Location: index.php?error=error_eliminacion");
    exit;
}
?>