<?php
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth_check.php';

$mis_clases = [];
try {
    $query = "SELECT g.*, c.nombre as curso_nombre, u.nombre as profesor_nombre 
              FROM matriculas m
              JOIN grupos g ON m.grupo_id = g.id
              JOIN cursos c ON g.curso_id = c.id
              LEFT JOIN usuarios u ON g.profesor_id = u.id
              WHERE m.estudiante_id = ? AND m.estado = 'activa'";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$_SESSION['user_id']]);
    $mis_clases = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log($e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Estudiante - Mi Horario</title>
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

        .events-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 20px;
        }

        .event-card {
            background: var(--dark-bg);
            border-radius: 16px;
            padding: 25px;
            border: 1px solid var(--border-color);
            transition: 0.3s;
            position: relative;
            overflow: hidden;
        }

        .event-card:hover {
            border-color: var(--primary-blue);
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
        }

        .blue-badge {
            background: var(--primary-blue);
            color: white;
            padding: 5px 12px;
            border-radius: 8px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
            margin-bottom: 15px;
        }

        .prof-info {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid var(--border-color);
            color: var(--text-secondary);
            font-size: 0.9rem;
        }
    </style>
</head>

<body>
    <?php include '../../includes/header.php'; ?>
    <main class="main-content">
        <div class="dashboard-header">
            <h1>Mi Información Académica</h1>
            <p style="color: var(--text-secondary)">Consulta tus cursos y horarios matriculados</p>
        </div>

        <div class="events-grid">
            <?php if (!empty($mis_clases)): foreach ($mis_clases as $clase): ?>
                    <div class="event-card">
                        <div class="blue-badge">GRUPO: <?= htmlspecialchars($clase['nombre']) ?></div>
                        <h2 style="font-size:1.2rem; margin-bottom:5px;"><?= htmlspecialchars($clase['curso_nombre']) ?></h2>
                        <div style="display:flex; align-items:center; gap:8px; color:var(--primary-blue); font-size:0.9rem;">
                            <span class="material-symbols-rounded" style="font-size:18px">calendar_month</span>
                            <?= htmlspecialchars($clase['horario']) ?>
                        </div>
                        <div class="prof-info">
                            <span class="material-symbols-rounded">account_circle</span>
                            Prof. <?= htmlspecialchars($clase['profesor_nombre'] ?? 'Sin asignar') ?>
                        </div>
                    </div>
                <?php endforeach;
            else: ?>
                <div class="event-card" style="grid-column: 1/-1; text-align: center;">No tienes clases registradas.</div>
            <?php endif; ?>
        </div>
    </main>
</body>

</html>