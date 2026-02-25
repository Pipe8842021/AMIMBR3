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

$cursos = $pdo->query("SELECT id, nombre FROM cursos WHERE estado = 'activo'")->fetchAll();
$profesores = $pdo->query("SELECT id, nombre FROM usuarios WHERE rol = 'profesor'")->fetchAll();
$grupos = $pdo->query("SELECT g.*, c.nombre as curso_nombre, u.nombre as profesor_nombre FROM grupos g JOIN cursos c ON g.curso_id = c.id LEFT JOIN usuarios u ON g.profesor_id = u.id ORDER BY g.id DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Admin - Gestión de Grupos</title>
    <link rel="stylesheet" href="../../assets/css/colores.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0" />
    <link rel="stylesheet" href="../../assets/css/style-gruposAdmin.css">
</head>

<body>
    <?php include '../../includes/header.php'; ?>
    <main class="main-content">
        <div class="dashboard-header">
            <h1>Administración de Grupos</h1>
            <p style="color: var(--text-secondary)">Creación y control de secciones académicas</p>
        </div>

        <div class="admin-grid">
            <div class="card-base">
                <h3 style="margin-bottom:20px">Nuevo Grupo</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="crear">
                    <div class="input-group"><label>Nombre</label><input type="text" name="nombre" required></div>
                    <div class="input-group">
                        <label>Curso</label>
                        <select name="curso_id"><?php foreach ($cursos as $c): ?><option value="<?= $c['id'] ?>"><?= $c['nombre'] ?></option><?php endforeach; ?></select>
                    </div>
                    <div class="input-group">
                        <label>Profesor</label>
                        <select name="profesor_id"><?php foreach ($profesores as $p): ?><option value="<?= $p['id'] ?>"><?= $p['nombre'] ?></option><?php endforeach; ?></select>
                    </div>
                    <div class="input-group"><label>Horario</label><input type="text" name="horario" placeholder="Ej: Lunes 10am"></div>
                    <div class="input-group"><label>Cupo Max</label><input type="number" name="cupo_maximo" value="20"></div>
                    <div class="input-group"><label>Inicio</label><input type="date" name="fecha_inicio" required></div>
                    <button type="submit" class="btn-submit">Registrar Grupo</button>
                </form>
            </div>

            <div class="card-base">
                <h3 style="margin-bottom:20px">Listado de Grupos</h3>
                <?php foreach ($grupos as $g): ?>
                    <div class="activity-item">
                        <div class="activity-icon"><span class="material-symbols-rounded">layers</span></div>
                        <div class="activity-content">
                            <div style="font-weight:600"><?= htmlspecialchars($g['nombre']) ?></div>
                            <div style="font-size:0.85rem; color:var(--text-secondary)"><?= htmlspecialchars($g['curso_nombre']) ?> | Prof: <?= htmlspecialchars($g['profesor_nombre'] ?? 'N/A') ?></div>
                        </div>
                        <div style="margin-left:auto; color:var(--primary-blue); font-weight:600; font-size:0.8rem;"><?= $g['cupo_actual'] ?>/<?= $g['cupo_maximo'] ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </main>
</body>

</html>