<?php
/**
 * Eliminar Curso
 * Sistema Amimbré
 */

require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth_check.php';
require_role('admin');

// Obtener ID del curso
$curso_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($curso_id <= 0) {
    header("Location: index.php?error=curso_no_encontrado");
    exit;
}

try {
    // Verificar que el curso existe
    $stmt = $pdo->prepare("SELECT * FROM cursos WHERE id = ?");
    $stmt->execute([$curso_id]);
    $curso = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$curso) {
        header("Location: index.php?error=curso_no_encontrado");
        exit;
    }
    
    // Verificar si hay grupos asociados
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM grupos WHERE curso_id = ?");
    $stmt->execute([$curso_id]);
    $grupos_count = $stmt->fetch()['total'];
    
    // Verificar si hay matrículas activas
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total 
        FROM matriculas m
        INNER JOIN grupos g ON m.grupo_id = g.id
        WHERE g.curso_id = ? AND m.estado = 'activa'
    ");
    $stmt->execute([$curso_id]);
    $matriculas_activas = $stmt->fetch()['total'];
    
    if ($matriculas_activas > 0) {
        header("Location: index.php?error=curso_con_matriculas_activas");
        exit;
    }
    
    // Iniciar transacción
    $pdo->beginTransaction();
    
    // Eliminar el curso (las relaciones se eliminan en cascada por las FK)
    $stmt = $pdo->prepare("DELETE FROM cursos WHERE id = ?");
    $stmt->execute([$curso_id]);
    
    // Eliminar imagen si existe
    if ($curso['imagen'] && file_exists("../../assets/img/cursos/" . $curso['imagen'])) {
        unlink("../../assets/img/cursos/" . $curso['imagen']);
    }
    
    // Confirmar transacción
    $pdo->commit();
    
    header("Location: index.php?success=curso_eliminado");
    exit;
    
} catch (PDOException $e) {
    // Revertir transacción en caso de error
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log("Error al eliminar curso: " . $e->getMessage());
    header("Location: index.php?error=error_al_eliminar");
    exit;
}
?>