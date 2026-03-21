<?php
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth_check.php';

if ($_SESSION['user_rol'] !== 'admin') {
    header('Location: ../../index.php');
    exit;
}

// Lógica de Inserción
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $nombre = trim($_POST['nombre']);
    $curso_id = $_POST['curso_id'];
    $profesor_id = $_POST['profesor_id'];
    $cupo_maximo = $_POST['cupo_maximo'];
    $horario = $_POST['horario'];
    $fecha_inicio = $_POST['fecha_inicio'];

    try {
        $stmt = $pdo->prepare("INSERT INTO grupos (nombre, curso_id, profesor_id, cupo_maximo, horario, fecha_inicio, estado) VALUES (?, ?, ?, ?, ?, ?, 'activo')");
        $stmt->execute([$nombre, $curso_id, $profesor_id, $cupo_maximo, $horario, $fecha_inicio]);
    } catch (PDOException $e) {
        error_log($e->getMessage());
    }
}

// Consultas
$cursos = $pdo->query("SELECT id, nombre FROM cursos WHERE estado = 'activo'")->fetchAll();
$profesores = $pdo->query("SELECT id, nombre FROM usuarios WHERE rol = 'profesor'")->fetchAll();
$grupos = $pdo->query("SELECT g.*, c.nombre as curso_nombre, u.nombre as profesor_nombre FROM grupos g JOIN cursos c ON g.curso_id = c.id LEFT JOIN usuarios u ON g.profesor_id = u.id ORDER BY g.id DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Gestión de Grupos</title>
    <link rel="stylesheet" href="../../assets/css/colores.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0" />
    <link rel="stylesheet" href="../../assets/css/style-gruposAdmin.css">
</head>

<body>
    <?php include '../../includes/header.php'; ?>

    <main class="main-content">
        <div class="dashboard-header">
            <div>
                <h1>Administración de Grupos</h1>
                <p style="color: var(--text-secondary)">Creación y control de secciones académicas</p>
            </div>
        </div>

        <div class="admin-grid">
            <div class="card-base">
                <h3 style="margin-bottom:20px; display: flex; align-items: center; gap: 10px;">
                    <span class="material-symbols-rounded">add_circle</span> Nuevo Grupo
                </h3>
                <form method="POST">
                    <input type="hidden" name="action" value="crear">

                    <div class="input-group">
                        <label>Nombre del Grupo</label>
                        <input type="text" name="nombre" placeholder="Ej: Grupo A - Mañana" required>
                    </div>

                    <div class="input-group">
                        <label>Curso</label>
                        <select name="curso_id" required>
                            <option value="">Seleccionar curso...</option>
                            <?php foreach ($cursos as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="input-group">
                        <label>Profesor Asignado</label>
                        <select name="profesor_id">
                            <option value="">Sin asignar</option>
                            <?php foreach ($profesores as $p): ?>
                                <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="input-group">
                        <label>Horario</label>
                        <input type="text" name="horario" placeholder="Ej: Lunes y Miércoles 10:00 AM">
                    </div>

                    <div class="input-group">
                        <label>Cupo Máximo</label>
                        <input type="number" name="cupo_maximo" value="20" min="1">
                    </div>

                    <div class="input-group">
                        <label>Fecha de Inicio</label>
                        <input type="date" name="fecha_inicio" required>
                    </div>

                    <button type="submit" class="btn-submit">Registrar Grupo</button>
                </form>
            </div>

            <div class="card-base">
                <h3 style="margin-bottom:20px">Listado de Grupos Activos</h3>

                <?php if (empty($grupos)): ?>
                    <p style="text-align: center; color: var(--text-secondary); padding: 20px;">No hay grupos registrados.</p>
                <?php endif; ?>

                <?php foreach ($grupos as $g): ?>
                    <div class="activity-item">
                        <div class="activity-icon">
                            <span class="material-symbols-rounded">layers</span>
                        </div>
                        <div class="activity-content">
                            <div style="font-weight:600; color: var(--text-primary);">
                                <?= htmlspecialchars($g['nombre']) ?>
                            </div>
                            <div style="font-size:0.85rem; color:var(--text-secondary)">
                                <strong><?= htmlspecialchars($g['curso_nombre']) ?></strong> |
                                Prof: <?= htmlspecialchars($g['profesor_nombre'] ?? 'No asignado') ?>
                            </div>
                            <div style="font-size:0.75rem; color:var(--text-secondary); margin-top: 4px;">
                                <span class="material-symbols-rounded" style="font-size: 12px; vertical-align: middle;">schedule</span>
                                <?= htmlspecialchars($g['horario']) ?>
                            </div>
                        </div>
                        <div style="margin-left:auto; text-align: right;">
                            <div style="color:var(--primary-blue); font-weight:700; font-size:0.9rem;">
                                <?= $g['cupo_actual'] ?? 0 ?>/<?= $g['cupo_maximo'] ?>
                            </div>
                            <div style="font-size: 0.7rem; color: var(--text-secondary);">Cupos</div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </main>

    <script>
        // Opcional: Script para manejar errores o confirmaciones de envío
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    </script>
</body>

</html>
