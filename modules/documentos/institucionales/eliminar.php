<?php
/**
 * Eliminar Documento Institucional
 * Acepta POST (AJAX modal) y GET (legacy redirect).
 */

require_once '../../../config/session.php';
require_once '../../../config/database.php';
require_once '../../../includes/auth_check.php';

require_role('admin');

$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tipo   = $_POST['tipo']   ?? '';
    $doc_id = isset($_POST['doc_id']) ? (int)$_POST['doc_id'] : 0;
} else {
    $tipo   = $_GET['tipo']    ?? '';
    $doc_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
}

if (empty($tipo) || $doc_id === 0) {
    if ($is_ajax) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'error' => 'Documento no válido']); exit; }
    header("Location: index.php?error=documento_no_encontrado"); exit;
}

try {
    if ($tipo === 'certificado') {
        $stmt = $pdo->prepare("UPDATE calificaciones_certificados SET estado = 'en_proceso' WHERE id = ?");
        $stmt->execute([$doc_id]);
        if ($is_ajax) { header('Content-Type: application/json'); echo json_encode(['success' => true]); exit; }
        header("Location: index.php?success=certificado_eliminado"); exit;

    } elseif ($tipo === 'comunicado') {
        $stmt = $pdo->prepare("UPDATE documentos_comunicados SET estado = 'archivado' WHERE id = ?");
        $stmt->execute([$doc_id]);
        if ($is_ajax) { header('Content-Type: application/json'); echo json_encode(['success' => true]); exit; }
        header("Location: index.php?success=comunicado_eliminado"); exit;

    } elseif ($tipo === 'acta') {
        $stmt = $pdo->prepare("UPDATE documentos_actas SET estado = 'archivado' WHERE id = ?");
        $stmt->execute([$doc_id]);
        if ($is_ajax) { header('Content-Type: application/json'); echo json_encode(['success' => true]); exit; }
        header("Location: index.php?success=acta_eliminada"); exit;
    }

    if ($is_ajax) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'error' => 'Tipo inválido']); exit; }
    header("Location: index.php?error=tipo_invalido"); exit;

} catch (PDOException $e) {
    error_log("Error al eliminar documento institucional: " . $e->getMessage());
    if ($is_ajax) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'error' => 'Error al eliminar el documento']); exit; }
    header("Location: index.php?error=error_eliminacion"); exit;
}
