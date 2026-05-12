<?php
/**
 * Endpoint para eliminar un documento administrativo.
 * Acepta GET (redirect legacy) y POST vía AJAX.
 */

require_once '../../../config/session.php';
require_once '../../../config/database.php';
require_once '../../../includes/auth_check.php';

require_role('admin');

$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$doc_id = (int)($_POST['doc_id'] ?? 0);

if ($doc_id === 0) {
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Documento no válido']);
        exit;
    }
    header('Location: index.php?error=documento_no_encontrado');
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id FROM documentos_administrativos WHERE id = ? AND estado = 'activo'");
    $stmt->execute([$doc_id]);

    if (!$stmt->fetch()) {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Documento no encontrado']);
            exit;
        }
        header('Location: index.php?error=documento_no_encontrado');
        exit;
    }

    // Soft delete
    $stmt = $pdo->prepare("UPDATE documentos_administrativos SET estado = 'eliminado' WHERE id = ?");
    $stmt->execute([$doc_id]);

    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }
    header('Location: index.php?success=documento_eliminado');
    exit;

} catch (PDOException $e) {
    error_log('Error al eliminar documento: ' . $e->getMessage());
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Error al eliminar el documento']);
        exit;
    }
    header('Location: index.php?error=error_eliminacion');
    exit;
}
