<?php
/**
 * Obtener Grupos de un Curso (AJAX)
 */

require_once '../../../config/session.php';
require_once '../../../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'No autenticado']);
    exit;
}

$curso_id = isset($_GET['curso_id']) ? (int)$_GET['curso_id'] : 0;

if ($curso_id === 0) {
    echo json_encode(['error' => 'ID de curso inválido']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT id, nombre 
        FROM grupos
        WHERE curso_id = ? AND estado = 'activo'
        ORDER BY nombre
    ");
    $stmt->execute([$curso_id]);
    $grupos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['grupos' => $grupos]);
    
} catch (PDOException $e) {
    error_log("Error: " . $e->getMessage());
    echo json_encode(['error' => 'Error al obtener grupos']);
}
?>