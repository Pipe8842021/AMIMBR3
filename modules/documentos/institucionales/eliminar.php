<?php
/**
 * Eliminar Documento Institucional
 */

require_once '../../../config/session.php';
require_once '../../../config/database.php';
require_once '../../../includes/auth_check.php';

// Solo admin puede eliminar
require_role('admin');

// Obtener tipo y ID
$tipo = $_GET['tipo'] ?? '';
$doc_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (empty($tipo) || $doc_id === 0) {
    header("Location: index.php?error=documento_no_encontrado");
    exit;
}

try {
    if ($tipo === 'certificado') {
        // CERTIFICADO - Marcar como archivado (no como reprobado)
        $stmt = $pdo->prepare("UPDATE calificaciones_certificados SET estado = 'en_proceso' WHERE id = ?");
        $stmt->execute([$doc_id]);
        
        header("Location: index.php?success=certificado_eliminado");
        exit;
        
    } elseif ($tipo === 'comunicado') {
        // COMUNICADO - Soft delete
        $stmt = $pdo->prepare("UPDATE documentos_comunicados SET estado = 'archivado' WHERE id = ?");
        $stmt->execute([$doc_id]);
        
        header("Location: index.php?success=comunicado_eliminado");
        exit;
        
    } elseif ($tipo === 'acta') {
        // ACTA - Soft delete
        $stmt = $pdo->prepare("UPDATE documentos_actas SET estado = 'archivado' WHERE id = ?");
        $stmt->execute([$doc_id]);
        
        header("Location: index.php?success=acta_eliminada");
        exit;
    }
    
    header("Location: index.php?error=tipo_invalido");
    exit;
    
} catch (PDOException $e) {
    error_log("Error al eliminar: " . $e->getMessage());
    header("Location: index.php?error=error_eliminacion");
    exit;
}
?>