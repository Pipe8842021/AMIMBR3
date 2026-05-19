<?php
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth_check.php';
require_role('admin');

$curso_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($curso_id <= 0) {
    header("Location: index.php?error=curso_no_encontrado");
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM cursos WHERE id = ?");
    $stmt->execute([$curso_id]);
    $curso = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$curso) {
        header("Location: index.php?error=curso_no_encontrado");
        exit;
    }
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM grupos WHERE curso_id = ?");
    $stmt->execute([$curso_id]);
    $grupos_count = $stmt->fetch()['total'];
    
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
    
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("DELETE FROM cursos WHERE id = ?");
    $stmt->execute([$curso_id]);

    if ($curso['imagen'] && file_exists("../../assets/img/cursos/" . $curso['imagen'])) {
        unlink("../../assets/img/cursos/" . $curso['imagen']);
    }

    $pdo->commit();
    
    header("Location: index.php?success=curso_eliminado");
    exit;
    
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log("Error al eliminar curso: " . $e->getMessage());
    header("Location: index.php?error=error_al_eliminar");
    exit;
}
?>