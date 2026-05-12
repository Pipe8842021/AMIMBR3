<?php
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth_check.php';
require_role('admin');

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Datos del Grupo
    $nombre       = trim($_POST['nombre']       ?? '');
    $curso_id     = (int)($_POST['curso_id']    ?? 0);
    $profesor_id  = (int)($_POST['profesor_id'] ?? 0);
    $cupo_maximo  = (int)($_POST['cupo_maximo'] ?? 20);
    $aula_input   = trim($_POST['aula']         ?? '');
    $fecha_inicio = $_POST['fecha_inicio']      ?? '';
    $fecha_fin    = $_POST['fecha_fin']         ?: null;
    $estado       = $_POST['estado']            ?? 'planificado';

    // Datos del Horario (Nuevos campos)
    $dia_semana   = $_POST['dia_semana']        ?? '';
    $hora_inicio  = $_POST['hora_inicio']       ?? '';
    $hora_fin     = $_POST['hora_fin']          ?? '';
    $horario_texto = ucfirst($dia_semana) . ' ' . $hora_inicio . ' - ' . $hora_fin;

    if (!$nombre || !$curso_id || !$fecha_inicio || !$dia_semana || !$hora_inicio || !$hora_fin) {
        $error = "Completa los campos obligatorios, incluyendo el horario detallado.";
    } else {
        try {
            $pdo->beginTransaction();

            // 1. Validar conflictos de Horario/Aula/Profesor antes de insertar
            $conflictos = [];

            // Conflicto de AULA
            if (!empty($aula_input)) {
                $stmt = $pdo->prepare("
                    SELECT g.nombre FROM horarios h 
                    JOIN grupos g ON h.grupo_id = g.id 
                    WHERE h.aula = ? AND h.dia_semana = ? 
                    AND h.hora_inicio < ? AND h.hora_fin > ?
                ");
                $stmt->execute([$aula_input, $dia_semana, $hora_fin, $hora_inicio]);
                if ($stmt->fetch()) $conflictos[] = "El aula ya está ocupada en ese horario.";
            }

            // Conflicto de PROFESOR
            if ($profesor_id) {
                $stmt = $pdo->prepare("
                    SELECT g.nombre FROM horarios h 
                    JOIN grupos g ON h.grupo_id = g.id 
                    WHERE g.profesor_id = ? AND h.dia_semana = ? 
                    AND h.hora_inicio < ? AND h.hora_fin > ?
                ");
                $stmt->execute([$profesor_id, $dia_semana, $hora_fin, $hora_inicio]);
                if ($stmt->fetch()) $conflictos[] = "El profesor ya tiene otra clase asignada en este horario.";
            }

            if (!empty($conflictos)) {
                throw new Exception(implode(" ", $conflictos));
            }

            // 2. Insertar Grupo
            $stmt = $pdo->prepare("
                INSERT INTO grupos (nombre, curso_id, profesor_id, cupo_maximo, aula, fecha_inicio, fecha_fin, estado, horario)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$nombre, $curso_id, $profesor_id ?: null, $cupo_maximo, $aula_input ?: null, $fecha_inicio, $fecha_fin, $estado, $horario_texto]);
            $nuevo_id = $pdo->lastInsertId();

            // 3. Insertar Horario vinculado
            $stmtHorario = $pdo->prepare("
                INSERT INTO horarios (grupo_id, dia_semana, hora_inicio, hora_fin, aula) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmtHorario->execute([$nuevo_id, $dia_semana, $hora_inicio, $hora_fin, $aula_input]);

            $pdo->commit();
            header("Location: ver.php?id=$nuevo_id&msg=creado");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log($e->getMessage());
            $error = "Error: " . $e->getMessage();
        }
    }
}

$cursos = $pdo->query("SELECT id, nombre, nivel FROM cursos WHERE estado='activo' ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
$profesores = $pdo->query("SELECT id, nombre FROM usuarios WHERE rol='profesor' AND estado='activo' ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
date_default_timezone_set('America/Bogota');
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crear Grupo – Amimbré</title>
    <link rel="stylesheet" href="../../assets/css/colores.css">
    <link rel="stylesheet" href="../../assets/css/style-grupos.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,1,0">
    <script>
        (function() {
            const t = localStorage.getItem('amimbre-theme');
            if (t === 'light') {
                document.documentElement.setAttribute('data-theme', 'light');
            } else {
                // Por defecto SIEMA suele usar dark o el sistema
                document.documentElement.setAttribute('data-theme', 'dark');
            }
        })();
    </script>
</head>

<body>
    <?php require_once '../../includes/header.php'; ?>
    <main class="main-content">
        <div class="dashboard-header">
            <div class="dashboard-title">
                <h1>Nuevo Grupo</h1>
                <p>Registrar sección académica y horario base</p>
            </div>
            <a href="admin.php" class="btn-action back">
                <span class="material-symbols-rounded">arrow_back</span> Volver
            </a>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <span class="material-symbols-rounded">error</span>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <div style="max-width: 800px; margin: 0 auto;">
            <div class="card">
                <form method="POST" class="modulo-form" id="formCrearGrupo" novalidate>

                    <div class="alert alert-error" id="alertValidarCrear" style="display:none">
                        <span class="material-symbols-rounded">error</span>
                        <span id="msgValidarCrear"></span>
                    </div>

                    <h3 style="margin-bottom: 15px; color: var(--primary-blue); border-bottom: 2px solid #eee; padding-bottom: 5px;">Información General</h3>

                    <div class="form-row">
                        <div class="input-group">
                            <label>Nombre del grupo <span class="req">*</span></label>
                            <input type="text" name="nombre" required placeholder="Ej: Piano Básico A" value="<?php echo htmlspecialchars($_POST['nombre'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="form-row form-row--2">
                        <div class="input-group">
                            <label>Curso <span class="req">*</span></label>
                            <select name="curso_id" required>
                                <option value="">Seleccionar curso...</option>
                                <?php foreach ($cursos as $c): ?>
                                    <option value="<?php echo $c['id']; ?>" <?php echo (($_POST['curso_id'] ?? '') == $c['id']) ? 'selected' : ''; ?>>
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
                                    <option value="<?php echo $p['id']; ?>" <?php echo (($_POST['profesor_id'] ?? '') == $p['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($p['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <h3 style="margin: 25px 0 15px 0; color: var(--primary-blue); border-bottom: 2px solid #eee; padding-bottom: 5px;">Configuración de Horario y Aula</h3>

                    <div class="form-row form-row--2">
                        <div class="input-group">
                            <label>Día de la Semana <span class="req">*</span></label>
                            <select name="dia_semana" required>
                                <option value="lunes">Lunes</option>
                                <option value="martes">Martes</option>
                                <option value="miercoles">Miércoles</option>
                                <option value="jueves">Jueves</option>
                                <option value="viernes">Viernes</option>
                                <option value="sabado">Sábado</option>
                                <option value="domingo">Domingo</option>
                            </select>
                        </div>
                        <div class="input-group">
                            <label>Aula / Salón</label>
                            <input type="text" name="aula" placeholder="Ej: Salón 1" value="<?php echo htmlspecialchars($_POST['aula'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="form-row form-row--2">
                        <div class="input-group">
                            <label>Hora Inicio <span class="req">*</span></label>
                            <input type="time" name="hora_inicio" required value="<?php echo $_POST['hora_inicio'] ?? ''; ?>">
                        </div>
                        <div class="input-group">
                            <label>Hora Fin <span class="req">*</span></label>
                            <input type="time" name="hora_fin" required value="<?php echo $_POST['hora_fin'] ?? ''; ?>">
                        </div>
                    </div>


                    <div class="form-row form-row--3">
                        <div class="input-group">
                            <label>Cupo máximo</label>
                            <input type="number" name="cupo_maximo" min="1" value="<?php echo htmlspecialchars($_POST['cupo_maximo'] ?? '20'); ?>">
                        </div>
                        <div class="input-group">
                            <label>Fecha de inicio <span class="req">*</span></label>
                            <input type="date" name="fecha_inicio" required value="<?php echo htmlspecialchars($_POST['fecha_inicio'] ?? ''); ?>">
                        </div>
                        <div class="input-group">
                            <label>Estado</label>
                            <select name="estado">
                                <option value="planificado">Planificado</option>
                                <option value="activo">Activo</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-actions">
                        <a href="admin.php" class="btn-cancel">Cancelar</a>
                        <button type="submit" class="btn-submit">
                            <span class="material-symbols-rounded">save</span> Crear Grupo y Horario
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </main>
<script>
document.getElementById('formCrearGrupo').addEventListener('submit', function(e) {
    const alertEl = document.getElementById('alertValidarCrear');
    const msgEl   = document.getElementById('msgValidarCrear');
    alertEl.style.display = 'none';
    this.querySelectorAll('.input-error').forEach(el => el.classList.remove('input-error'));

    const required = this.querySelectorAll('[required]');
    let firstEmpty = null;
    required.forEach(function(el) {
        const isEmpty = (el.tagName === 'SELECT' && !el.value) ||
                        (el.tagName !== 'SELECT' && !el.value.trim());
        if (isEmpty) { el.classList.add('input-error'); if (!firstEmpty) firstEmpty = el; }
    });

    if (firstEmpty) {
        const label = firstEmpty.closest('.input-group')?.querySelector('label')
                        ?.textContent?.replace('*','').trim() ?? 'campo requerido';
        msgEl.textContent = 'El campo "' + label + '" es obligatorio. Completa todos los campos marcados con *.';
        alertEl.style.display = 'flex';
        alertEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
        e.preventDefault();
    }
});

document.querySelectorAll('#formCrearGrupo [required]').forEach(function(el) {
    ['input', 'change'].forEach(function(ev) {
        el.addEventListener(ev, function() {
            if (this.value.trim() || (this.tagName === 'SELECT' && this.value)) {
                this.classList.remove('input-error');
                if (!document.querySelectorAll('#formCrearGrupo .input-error').length)
                    document.getElementById('alertValidarCrear').style.display = 'none';
            }
        });
    });
});
</script>
</body>

</html>