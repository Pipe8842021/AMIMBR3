<?php
/**
 * Eliminar Bitácora
 */

require_once '../../../../config/session.php';
require_once '../../../../config/database.php';
require_once '../../../../includes/auth_check.php';

// Solo admin puede eliminar
require_role('admin');

$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

$bitacora_id = isset($_POST['bitacora_id']) ? (int)$_POST['bitacora_id']
             : (isset($_GET['id'])          ? (int)$_GET['id'] : 0);

if ($bitacora_id === 0) {
    if ($is_ajax) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'error' => 'ID inválido']); exit; }
    header("Location: ../index.php?error=bitacora_no_encontrada");
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id FROM bitacoras WHERE id = ?");
    $stmt->execute([$bitacora_id]);
    if (!$stmt->fetch()) {
        if ($is_ajax) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'error' => 'Bitácora no encontrada']); exit; }
        header("Location: ../index.php?error=bitacora_no_encontrada");
        exit;
    }

    $stmt = $pdo->prepare("UPDATE bitacoras SET estado = 'archivado' WHERE id = ?");
    $stmt->execute([$bitacora_id]);

    if ($is_ajax) { header('Content-Type: application/json'); echo json_encode(['success' => true]); exit; }
    header("Location: ../index.php?success=bitacora_eliminada");
    exit;

} catch (PDOException $e) {
    error_log("Error al eliminar bitácora: " . $e->getMessage());
    if ($is_ajax) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'error' => 'Error del sistema']); exit; }
    header("Location: ../index.php?error=error_eliminacion");
    exit;
}
