<?php
/**
 * Grupos – Crear nuevo grupo
 */
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth_check.php';
require_role('admin');

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
            $stmt = $pdo->prepare("
                INSERT INTO grupos
                    (nombre, curso_id, profesor_id, cupo_maximo, horario, aula, fecha_inicio, fecha_fin, estado)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $nombre, $curso_id,
                $profesor_id ?: null,
                $cupo_maximo, $horario,
                $aula ?: null,
                $fecha_inicio, $fecha_fin, $estado
            ]);
            $nuevo_id = $pdo->lastInsertId();
            header("Location: ver.php?id=$nuevo_id&msg=creado");
            exit;
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $error = "Error al crear el grupo. Intenta de nuevo.";
        }
    }
}

$cursos    = $pdo->query("SELECT id, nombre, nivel FROM cursos WHERE estado='activo' ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
$profesores= $pdo->query("SELECT id, nombre FROM usuarios WHERE rol='profesor' AND estado='activo' ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

date_default_timezone_set('America/Bogota');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crear Grupo – Amimbré</title>
    <link rel="shortcut icon" href="../../assets/img/3.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,1,0">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="../../assets/css/colores.css">
    <link rel="stylesheet" href="../../assets/css/style-grupos.css">
    <script>(function(){ const t=localStorage.getItem('amimbre-theme'); if(t==='light') document.documentElement.setAttribute('data-theme','light'); })();</script>
</head>
<body>
<?php require_once '../../includes/header.php'; ?>
<main class="main-content">

    <div class="dashboard-header">
        <div class="dashboard-title">
            <h1>Nuevo Grupo</h1>
            <p>Registrar una nueva sección académica</p>
        </div>
        <a href="admin.php" class="btn-action back">
            <span class="material-symbols-rounded">arrow_back</span> Volver al listado
        </a>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger">
        <span class="material-symbols-rounded">error</span><?php echo $error; ?>
    </div>
    <?php endif; ?>

    <div style="max-width: 700px;">
        <div class="card">
            <div class="form-card-header">
                <span class="material-symbols-rounded">add_circle</span>
                <div>
                    <h3>Datos del Grupo</h3>
                    <p>Los campos con <span class="req">*</span> son obligatorios</p>
                </div>
            </div>

            <form method="POST" class="modulo-form">

                <div class="form-row">
                    <div class="input-group">
                        <label>Nombre del grupo <span class="req">*</span></label>
                        <input type="text" name="nombre" required
                               placeholder="Ej: Guitarra I – Mañana"
                               value="<?php echo htmlspecialchars($_POST['nombre'] ?? ''); ?>">
                    </div>
                </div>

                <div class="form-row form-row--2">
                    <div class="input-group">
                        <label>Curso <span class="req">*</span></label>
                        <select name="curso_id" required>
                            <option value="">Seleccionar curso...</option>
                            <?php foreach ($cursos as $c): ?>
                            <option value="<?php echo $c['id']; ?>"
                                <?php echo (($_POST['curso_id'] ?? '') == $c['id']) ? 'selected' : ''; ?>>
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
                                <?php echo (($_POST['profesor_id'] ?? '') == $p['id']) ? 'selected' : ''; ?>>
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
                               placeholder="Ej: Martes 3:00 PM"
                               value="<?php echo htmlspecialchars($_POST['horario'] ?? ''); ?>">
                    </div>
                    <div class="input-group">
                        <label>Aula</label>
                        <input type="text" name="aula"
                               placeholder="Ej: Aula 3 – Cuerdas"
                               value="<?php echo htmlspecialchars($_POST['aula'] ?? ''); ?>">
                    </div>
                </div>

                <div class="form-row form-row--3">
                    <div class="input-group">
                        <label>Cupo máximo</label>
                        <input type="number" name="cupo_maximo" min="1"
                               value="<?php echo htmlspecialchars($_POST['cupo_maximo'] ?? '20'); ?>">
                    </div>
                    <div class="input-group">
                        <label>Fecha de inicio <span class="req">*</span></label>
                        <input type="date" name="fecha_inicio" required
                               value="<?php echo htmlspecialchars($_POST['fecha_inicio'] ?? ''); ?>">
                    </div>
                    <div class="input-group">
                        <label>Fecha de fin</label>
                        <input type="date" name="fecha_fin"
                               value="<?php echo htmlspecialchars($_POST['fecha_fin'] ?? ''); ?>">
                    </div>
                </div>

                <div class="form-row" style="max-width:200px;">
                    <div class="input-group">
                        <label>Estado inicial</label>
                        <select name="estado">
                            <option value="planificado">Planificado</option>
                            <option value="activo">Activo</option>
                        </select>
                    </div>
                </div>

                <div class="form-actions">
                    <a href="admin.php" class="btn-cancel">Cancelar</a>
                    <button type="submit" class="btn-submit">
                        <span class="material-symbols-rounded">add_circle</span> Crear grupo
                    </button>
                </div>

            </form>
        </div>
    </div>

</main>
</body>
</html>