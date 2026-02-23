<?php
/**
 * Obtener Estudiantes de un Grupo (AJAX)
 */

require_once '../../../../config/session.php';
require_once '../../../../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'No autenticado']);
    exit;
}

$grupo_id = isset($_GET['grupo_id']) ? (int)$_GET['grupo_id'] : 0;

if ($grupo_id === 0) {
    echo json_encode(['error' => 'ID de grupo inválido']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT u.id, u.nombre 
        FROM matriculas m
        INNER JOIN usuarios u ON m.estudiante_id = u.id
        WHERE m.grupo_id = ? AND m.estado = 'activa' AND u.estado = 'activo'
        ORDER BY u.nombre
    ");
    $stmt->execute([$grupo_id]);
    $estudiantes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['estudiantes' => $estudiantes]);
    
} catch (PDOException $e) {
    error_log("Error: " . $e->getMessage());
    echo json_encode(['error' => 'Error al obtener estudiantes']);
}
?>