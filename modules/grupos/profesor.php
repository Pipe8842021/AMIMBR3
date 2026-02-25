<?php
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth_check.php';

$mis_grupos = [];
try {
    $stmt = $pdo->prepare("SELECT g.*, c.nombre as curso_nombre FROM grupos g JOIN cursos c ON g.curso_id = c.id WHERE g.profesor_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $mis_grupos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log($e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Profesor - Mis Grupos</title>
    <link rel="stylesheet" href="../../assets/css/colores.css">
    <link rel="stylesheet" href="../../assets/css/style-gruposProfesor.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0" />

</head>

<body>
    <?php include '../../includes/header.php'; ?>
    <main class="main-content">
        <div class="dashboard-header">
            <h1>Mis Grupos Asignados</h1>
            <p style="color: var(--text-secondary)">Vista general de tus clases activas</p>
        </div>

        <div class="stats-grid">
            <?php if (!empty($mis_grupos)): foreach ($mis_grupos as $g): ?>
                    <div class="stat-card">
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <span class="course-tag"><?= htmlspecialchars($g['curso_nombre']) ?></span>
                            <div class="stat-icon blue"><span class="material-symbols-rounded">groups</span></div>
                        </div>
                        <span class="stat-value"><?= htmlspecialchars($g['nombre']) ?></span>
                        <div style="margin-top:20px; display:flex; gap:15px; font-size:0.85rem; color:var(--text-secondary);">
                            <div style="display:flex; align-items:center; gap:5px;"><span class="material-symbols-rounded" style="font-size:18px">schedule</span> <?= htmlspecialchars($g['horario']) ?></div>
                            <div style="display:flex; align-items:center; gap:5px;"><span class="material-symbols-rounded" style="font-size:18px">person</span> <?= $g['cupo_actual'] ?>/<?= $g['cupo_maximo'] ?></div>
                        </div>
                    </div>
                <?php endforeach;
            else: ?>
                <p>No tienes grupos asignados.</p>
            <?php endif; ?>
        </div>
    </main>
</body>

</html>