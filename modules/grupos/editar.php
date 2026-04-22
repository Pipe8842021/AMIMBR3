<?php

require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth_check.php';
require_role('admin');

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if (!$id) {
    header("Location: admin.php");
    exit;
}

$error = null;

// Guardar cambios
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'editar') {
    $nombre       = trim($_POST['nombre']       ?? '');
    $curso_id     = (int)($_POST['curso_id']    ?? 0);
    $profesor_id  = (int)($_POST['profesor_id'] ?? 0);
    $cupo_maximo  = (int)($_POST['cupo_maximo'] ?? 20);
    $horario      = trim($_POST['horario']      ?? '');
    $aula         = trim($_POST['aula']         ?? '');
    $fecha_inicio = $_POST['fecha_inicio']      ?? '';
    $fecha_fin    = $_POST['fecha_fin']         ?: null;
    $estado       = $_POST['estado']            ?? 'planificado';

    if (!$nombre || !$curso_id || !$fecha_inicio) {
        $error = "Completa los campos obligatorios: nombre, curso y fecha de inicio.";
    } else {
        try {
            $pdo->prepare("
                UPDATE grupos SET
                    nombre=?, curso_id=?, profesor_id=?, cupo_maximo=?,
                    horario=?, aula=?, fecha_inicio=?, fecha_fin=?, estado=?
                WHERE id=?
            ")->execute([
                $nombre,
                $curso_id,
                $profesor_id ?: null,
                $cupo_maximo,
                $horario,
                $aula ?: null,
                $fecha_inicio,
                $fecha_fin,
                $estado,
                $id
            ]);
            header("Location: ver.php?id=$id&msg=editado");
            exit;
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $error = "Error al actualizar el grupo.";
        }
    }
}

// Cargar datos del grupo
try {
    $stmt = $pdo->prepare("
        SELECT g.*, c.nombre AS curso_nombre, u.nombre AS profesor_nombre
        FROM grupos g
        JOIN cursos c ON g.curso_id = c.id
        LEFT JOIN usuarios u ON g.profesor_id = u.id
        WHERE g.id = ?
    ");
    $stmt->execute([$id]);
    $grupo = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$grupo) {
        header("Location: admin.php");
        exit;
    }

    $cursos    = $pdo->query("SELECT id, nombre, nivel FROM cursos WHERE estado='activo' ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
    $profesores = $pdo->query("SELECT id, nombre FROM usuarios WHERE rol='profesor' AND estado='activo' ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log($e->getMessage());
    header("Location: admin.php");
    exit;
}

$estado_cfg = [
    'planificado' => 'Planificado',
    'activo'      => 'Activo',
    'finalizado'  => 'Finalizado',
    'cancelado'   => 'Cancelado',
];
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Grupo – Amimbré</title>
    <link rel="shortcut icon" href="../../assets/img/3.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,1,0">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="../../assets/css/colores.css">
    <link rel="stylesheet" href="../../assets/css/style-grupos.css">
    <script>
        (function() {
            const t = localStorage.getItem('amimbre-theme');
            if (t === 'light') document.documentElement.setAttribute('data-theme', 'light');
        })();
    </script>
</head>

<body>
    <?php require_once '../../includes/header.php'; ?>
    <main class="main-content">

        <div class="dashboard-header">
            <div class="dashboard-title">
                <h1>Editar Grupo</h1>
                <p><?php echo htmlspecialchars($grupo['nombre']); ?></p>
            </div>
            <a href="ver.php?id=<?php echo $id; ?>" class="btn-action back">
                <span class="material-symbols-rounded">arrow_back</span> Volver al grupo
            </a>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <span class="material-symbols-rounded">error</span><?php echo $error; ?>
            </div>
        <?php endif; ?>

        <div style="max-width: 700px; margin: 0 auto;">
            <div class="card">
                <div class="form-card-header editing">
                    <span class="material-symbols-rounded">edit</span>
                    <div>
                        <h3>Modificar datos del grupo</h3>
                        <p>ID #<?php echo $id; ?> · Los campos con <span class="req">*</span> son obligatorios</p>
                    </div>
                </div>

                <form method="POST" class="modulo-form">
                    <input type="hidden" name="action" value="editar">
                    <input type="hidden" name="id" value="<?php echo $id; ?>">

                    <div class="form-row">
                        <div class="input-group">
                            <label>Nombre del grupo <span class="req">*</span></label>
                            <input type="text" name="nombre" required
                                value="<?php echo htmlspecialchars($grupo['nombre']); ?>">
                        </div>
                    </div>

                    <div class="form-row form-row--2">
                        <div class="input-group">
                            <label>Curso <span class="req">*</span></label>
                            <select name="curso_id" required>
                                <option value="">Seleccionar...</option>
                                <?php foreach ($cursos as $c): ?>
                                    <option value="<?php echo $c['id']; ?>"
                                        <?php echo $c['id'] == $grupo['curso_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($c['nombre']); ?> (<?php echo ucfirst($c['nivel']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="input-group">
                            <label>Profesor asignado</label>
                            <select name="profesor_id">
                                <option value="">Sin asignar</option>
                                <?php foreach ($profesores as $p): ?>
                                    <option value="<?php echo $p['id']; ?>"
                                        <?php echo $p['id'] == $grupo['profesor_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($p['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-row form-row--2">
                        <div class="input-group">
                            <label>Horario</label>
                            <input type="text" name="horario"
                                value="<?php echo htmlspecialchars($grupo['horario']); ?>">
                        </div>
                        <div class="input-group">
                            <label>Aula</label>
                            <input type="text" name="aula"
                                value="<?php echo htmlspecialchars($grupo['aula'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="form-row form-row--3">
                        <div class="input-group">
                            <label>Cupo máximo</label>
                            <input type="number" name="cupo_maximo" min="1"
                                value="<?php echo $grupo['cupo_maximo']; ?>">
                        </div>
                        <div class="input-group">
                            <label>Fecha de inicio <span class="req">*</span></label>
                            <input type="date" name="fecha_inicio" required
                                value="<?php echo $grupo['fecha_inicio']; ?>">
                        </div>
                        <div class="input-group">
                            <label>Fecha de fin</label>
                            <input type="date" name="fecha_fin"
                                value="<?php echo $grupo['fecha_fin'] ?? ''; ?>">
                        </div>
                    </div>

                    <div class="form-row" style="max-width:200px;">
                        <div class="input-group">
                            <label>Estado</label>
                            <select name="estado">
                                <?php foreach ($estado_cfg as $val => $lbl): ?>
                                    <option value="<?php echo $val; ?>"
                                        <?php echo $val === $grupo['estado'] ? 'selected' : ''; ?>>
                                        <?php echo $lbl; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-actions">
                        <a href="ver.php?id=<?php echo $id; ?>" class="btn-cancel">Cancelar</a>
                        <button type="submit" class="btn-submit">
                            <span class="material-symbols-rounded">save</span> Guardar cambios
                        </button>
                    </div>
                </form>
            </div>
        </div>

    </main>
</body>

</html>