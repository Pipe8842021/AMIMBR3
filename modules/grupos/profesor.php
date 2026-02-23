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
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0" />
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: "Poppins", sans-serif;
        }

        body {
            background: var(--hover-bg);
            color: var(--text-primary);
        }

        .main-content {
            margin-left: 270px;
            padding: 30px;
        }

        .dashboard-header {
            margin-bottom: 30px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 20px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }

        .stat-card {
            background: var(--dark-bg);
            border-radius: 16px;
            padding: 24px;
            border: 1px solid var(--border-color);
            transition: 0.3s ease;
            box-shadow: var(--shadow-md);
        }

        .stat-card:hover {
            border-color: var(--primary-blue);
            transform: translateY(-4px);
            box-shadow: 0 10px 20px -5px rgba(20, 121, 176, 0.3);
        }

        .stat-icon.blue {
            background: var(--subtle-blue);
            color: var(--primary-blue);
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .stat-value {
            font-size: 1.4rem;
            font-weight: 700;
            margin-top: 15px;
            display: block;
        }

        .course-tag {
            color: var(--primary-blue);
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
        }
    </style>
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