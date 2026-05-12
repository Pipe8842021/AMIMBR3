<?php

require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth_check.php';
require_role('admin');

$success = null;
$error   = null;
// En la sección de manejo de POST de admin.php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'crear_grupo') {
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

    $cursos = $pdo->query("SELECT id, nombre, nivel FROM cursos WHERE estado='activo' ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
    $profesores = $pdo->query("SELECT id, nombre FROM usuarios WHERE rol='profesor' AND estado='activo' ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
    date_default_timezone_set('America/Bogota');
    // ... Copia aquí la lógica de validación y el try-catch de crear.php ...
    // Asegúrate de que al final, en lugar de redirigir a ver.php, 
    // puedas asignar el mensaje a $success y dejar que la página se recargue.
    $success = "Grupo creado exitosamente.";
}
// Manejo de cambio de estado
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cambiar_estado') {
    $id_grupo     = (int)($_POST['id']           ?? 0);
    $nuevo_estado = $_POST['nuevo_estado']        ?? '';
    $estados_validos = ['planificado', 'activo', 'finalizado', 'cancelado'];

    if ($id_grupo && in_array($nuevo_estado, $estados_validos)) {
        try {
            $pdo->prepare("UPDATE grupos SET estado = ? WHERE id = ?")
                ->execute([$nuevo_estado, $id_grupo]);
            $success = "Estado del grupo actualizado correctamente.";
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $error = "Error al actualizar el estado.";
        }
    }
}

try {
    // Filtros GET
    $filtro_estado = $_GET['estado'] ?? '';
    $filtro_buscar = trim($_GET['buscar'] ?? '');

    $where  = [];
    $params = [];

    if ($filtro_estado) {
        $where[]  = "g.estado = ?";
        $params[] = $filtro_estado;
    }
    if ($filtro_buscar) {
        $where[]  = "(g.nombre LIKE ? OR c.nombre LIKE ? OR u.nombre LIKE ?)";
        $params[] = "%$filtro_buscar%";
        $params[] = "%$filtro_buscar%";
        $params[] = "%$filtro_buscar%";
    }

    $sql_where = $where ? "WHERE " . implode(" AND ", $where) : "";

    $stmt = $pdo->prepare("
        SELECT
            g.id, g.nombre, g.fecha_inicio, g.fecha_fin, g.estado, 
            g.cupo_actual, g.cupo_maximo, g.aula,
            c.nombre AS curso_nombre,
            c.nivel  AS curso_nivel,
            u.nombre AS profesor_nombre
        FROM grupos g
        JOIN cursos c        ON g.curso_id   = c.id
        LEFT JOIN usuarios u ON g.profesor_id = u.id
        $sql_where
        ORDER BY g.id DESC
    ");
    $stmt->execute($params);
    $grupos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // --- NUEVA LÓGICA: Obtener horarios para el MODAL ---
    $horarios_modal = [];
    if (!empty($grupos)) {
        $ids = array_column($grupos, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmtH = $pdo->prepare("SELECT * FROM horarios WHERE grupo_id IN ($placeholders) ORDER BY dia_semana, hora_inicio");
        $stmtH->execute($ids);
        while ($h = $stmtH->fetch(PDO::FETCH_ASSOC)) {
            $horarios_modal[$h['grupo_id']][] = $h;
        }
    }
    $dias_esp = ['mon' => 'Lunes', 'tue' => 'Martes', 'wed' => 'Miércoles', 'thu' => 'Jueves', 'fri' => 'Viernes', 'sat' => 'Sábado', 'sun' => 'Domingo'];
    // ----------------------------------------------------

    // Totales por estado para los chips
    $totales = [];
    foreach ($pdo->query("SELECT estado, COUNT(*) AS n FROM grupos GROUP BY estado")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $totales[$r['estado']] = $r['n'];
    }
    $total_general = array_sum($totales);
} catch (PDOException $e) {
    error_log($e->getMessage());
    $grupos = [];
    $totales = [];
    $total_general = 0;
}

$estado_cfg = [
    'planificado' => ['cls' => 'badge-info',    'txt' => 'Planificado', 'icon' => 'schedule'],
    'activo'      => ['cls' => 'badge-success',  'txt' => 'Activo',      'icon' => 'play_circle'],
    'finalizado'  => ['cls' => 'badge-warning',  'txt' => 'Finalizado',  'icon' => 'check_circle'],
    'cancelado'   => ['cls' => 'badge-danger',   'txt' => 'Cancelado',   'icon' => 'cancel'],
];
$nivel_cfg = [
    'basico'     => 'badge-info',
    'intermedio' => 'badge-warning',
    'avanzado'   => 'badge-danger',
];

date_default_timezone_set('America/Bogota');
$dias  = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
$meses = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
$fecha_hoy = $dias[date('w')] . ', ' . date('d') . ' de ' . $meses[date('n')] . ' de ' . date('Y');
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Grupos – Amimbré</title>
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
                <h1>Gestión de Grupos</h1>
                <p>Creación y control de secciones académicas</p>
            </div>
            <button type="button" class="btn-submit" onclick="abrirModalCrear()">
                <span class="material-symbols-rounded">add_circle</span> Nuevo grupo
            </button>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <span class="material-symbols-rounded">check_circle</span>
                <?php echo $success; ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger">
                <span class="material-symbols-rounded">error</span>
                <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <div class="modulo-stats">
            <div class="modulo-stat-chip total">
                <span class="material-symbols-rounded">layers</span>
                <div>
                    <span class="chip-value"><?php echo $total_general; ?></span>
                    <span class="chip-label">Total grupos</span>
                </div>
            </div>
            <?php foreach ($estado_cfg as $est => $cfg): ?>
                <div class="modulo-stat-chip <?php echo $est; ?>">
                    <span class="material-symbols-rounded"><?php echo $cfg['icon']; ?></span>
                    <div>
                        <span class="chip-value"><?php echo $totales[$est] ?? 0; ?></span>
                        <span class="chip-label"><?php echo $cfg['txt']; ?>s</span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="card">
            <div class="section-header">
                <div>
                    <h3 class="section-title">Grupos Registrados</h3>
                    <p class="section-subtitle"><?php echo count($grupos); ?> resultado<?php echo count($grupos) != 1 ? 's' : ''; ?></p>
                </div>
            </div>

            <form method="GET" class="filtros-bar">
                <div class="filtros-buscar">
                    <span class="material-symbols-rounded">search</span>
                    <input type="text" name="buscar"
                        value="<?php echo htmlspecialchars($filtro_buscar); ?>"
                        placeholder="Buscar por nombre, curso o profesor...">
                </div>
                <select name="estado" onchange="this.form.submit()">
                    <option value="">Todos los estados</option>
                    <?php foreach ($estado_cfg as $est => $cfg): ?>
                        <option value="<?php echo $est; ?>" <?php echo $filtro_estado === $est ? 'selected' : ''; ?>>
                            <?php echo $cfg['txt']; ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <?php if ($filtro_estado || $filtro_buscar): ?>
                    <a href="admin.php" class="btn-limpiar" title="Limpiar filtros">
                        <span class="material-symbols-rounded">close</span>
                    </a>
                <?php endif; ?>
            </form>

            <?php if (count($grupos) > 0): ?>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Grupo</th>
                                <th>Curso</th>
                                <th>Profesor</th>
                                <th>Horario / Aula</th>
                                <th>Cupos</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($grupos as $g):
                                $est = $estado_cfg[$g['estado']] ?? $estado_cfg['planificado'];
                                $niv = $nivel_cfg[strtolower($g['curso_nivel'])] ?? 'badge-info';
                                $pct = $g['cupo_maximo'] > 0 ? round(($g['cupo_actual'] / $g['cupo_maximo']) * 100) : 0;
                                $bar = $pct >= 90 ? 'bar-danger' : ($pct >= 70 ? 'bar-warning' : 'bar-success');
                            ?>
                                <tr>
                                    <td style="color:var(--text-secondary); font-size:0.8rem;">#<?php echo $g['id']; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($g['nombre']); ?></strong>
                                        <div style="font-size:0.75rem; color:var(--text-secondary); margin-top:2px;">
                                            Desde <?php echo date('d/m/Y', strtotime($g['fecha_inicio'])); ?>
                                            <?php if ($g['fecha_fin']): ?>
                                                · Hasta <?php echo date('d/m/Y', strtotime($g['fecha_fin'])); ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($g['curso_nombre']); ?>
                                        <div style="margin-top:3px;">
                                            <span class="badge <?php echo $niv; ?>"><?php echo ucfirst($g['curso_nivel']); ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <?php echo $g['profesor_nombre']
                                            ? htmlspecialchars($g['profesor_nombre'])
                                            : '<span style="color:var(--text-secondary);font-size:0.82rem;">Sin asignar</span>'; ?>
                                    </td>
                                    <td>
                                        <?php if ($g['aula']): ?>
                                            <div style="font-size:0.85rem;">
                                                <span class="material-symbols-rounded" style="font-size:16px; vertical-align:middle;">meeting_room</span>
                                                <?php echo htmlspecialchars($g['aula']); ?>
                                            </div>
                                        <?php else: ?>
                                            <span style="color:var(--text-secondary); font-size:0.8rem;">Sin aula asignada</span>
                                        <?php endif; ?>

                                        <div style="margin-top:4px;">
                                            <a href="../horarios/index.php?grupo_id=<?php echo $g['id']; ?>"
                                                style="font-size:0.7rem; color:var(--primary-green); text-decoration:none; font-weight:600;"
                                                onclick="event.preventDefault(); abrirModalHorarios(<?php echo $g['id']; ?>, '<?php echo htmlspecialchars($g['nombre']); ?>')">
                                                <span class="material-symbols-rounded" style="font-size:12px; vertical-align:middle;">calendar_month</span>
                                                Ver horarios detallados
                                            </a>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="cupo-cell">
                                            <span><?php echo $g['cupo_actual']; ?>/<?php echo $g['cupo_maximo']; ?></span>
                                            <div class="group-bar" style="width:80px;">
                                                <div class="group-bar-fill <?php echo $bar; ?>" style="width:<?php echo $pct; ?>%"></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $est['cls']; ?>"><?php echo $est['txt']; ?></span>
                                    </td>
                                    <td>
                                        <div class="table-actions">
                                            <a href="ver.php?id=<?php echo $g['id']; ?>" class="tbl-btn view" title="Ver detalle">
                                                <span class="material-symbols-rounded">visibility</span>
                                            </a>

                                            <button class="tbl-btn edit" onclick='abrirModalEditar(<?php echo json_encode($g); ?>)' class="btn-edit" title="Editar">
                                                <span class="material-symbols-rounded">edit</span>
                                            </button>
                                            <div class="dropdown-estado">
                                                <button type="button" class="tbl-btn estado" title="Cambiar estado">
                                                    <span class="material-symbols-rounded">swap_horiz</span>
                                                </button>
                                                <div class="dropdown-estado-menu">
                                                    <?php foreach ($estado_cfg as $est_k => $est_v):
                                                        if ($est_k === $g['estado']) continue; ?>
                                                        <form method="POST" style="margin:0;">
                                                            <input type="hidden" name="action" value="cambiar_estado">
                                                            <input type="hidden" name="id" value="<?php echo $g['id']; ?>">
                                                            <input type="hidden" name="nuevo_estado" value="<?php echo $est_k; ?>">
                                                            <button type="submit" class="estado-option">
                                                                <span class="material-symbols-rounded"><?php echo $est_v['icon']; ?></span>
                                                                <?php echo $est_v['txt']; ?>
                                                            </button>
                                                        </form>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                            <a href="eliminar.php?id=<?php echo $g['id']; ?>" class="tbl-btn delete" title="Eliminar grupo">
                                                <span class="material-symbols-rounded">delete</span>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <span class="material-symbols-rounded">layers</span>
                    <p><?php echo ($filtro_estado || $filtro_buscar) ? 'No hay grupos con esos filtros.' : 'Aún no hay grupos registrados.'; ?></p>
                </div>
            <?php endif; ?>
        </div>
    </main>
    <div id="modalHorarios" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h3 id="modalTitulo">Horarios del Grupo</h3>
                    <p id="modalSubtitulo" style="font-size:0.85rem; color:var(--text-secondary);">Sesiones programadas en el sistema</p>
                </div>
                <button onclick="cerrarModal()" class="btn-close-modal">
                    <span class="material-symbols-rounded">close</span>
                </button>
            </div>
            <div class="modal-body" id="modalBody"></div>
            <div class="modal-footer" style="margin-top:20px; display:flex; gap:10px; justify-content:flex-end;">
                <button onclick="cerrarModal()" class="btn-secondary" style="padding:8px 16px; border-radius:8px; border:1px solid var(--border-color); background:none; color:var(--text-main); cursor:pointer;">Cerrar</button>
                <a href="../horarios/index.php" id="linkGestionar" class="btn-primary" style="padding:8px 16px; border-radius:8px; background:var(--primary-green); color:white; text-decoration:none; font-size:0.9rem;">Gestionar en Calendario</a>
            </div>
        </div>
    </div>

    <style>
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            z-index: 2000;
            backdrop-filter: blur(4px);
            align-items: center;
            justify-content: center;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal-content {
            background: var(--card-bg);
            width: 90%;
            max-width: 450px;
            border-radius: 20px;
            padding: 24px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.3);
            animation: slideUp 0.3s ease;
        }

        @keyframes slideUp {
            from {
                transform: translateY(20px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        .modal-body ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .modal-body li {
            padding: 14px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .modal-body li:last-child {
            border-bottom: none;
        }

        .btn-close-modal {
            background: none;
            border: none;
            cursor: pointer;
            color: var(--text-secondary);
            transition: 0.2s;
        }

        .btn-close-modal:hover {
            color: var(--primary-green);
        }
    </style>
    <!-- Modal para Crear Nuevo Grupo -->
    <div id="modalCrearGrupo" class="modal-overlay">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header">
                <div>
                    <h3>Nuevo Grupo</h3>
                    <p style="font-size:0.85rem; color:var(--text-secondary);">Registrar sección académica</p>
                </div>
                <button onclick="cerrarModalCrear()" class="btn-close-modal">
                    <span class="material-symbols-rounded">close</span>
                </button>
            </div>
            <div class="modal-body">
                <form method="POST" id="formCrearGrupo">
                    <div class="form-row form-row--2">
                        <div class="input-group">
                            <label>Fecha de Inicio <span class="req">*</span></label>
                            <input type="date" name="fecha_inicio" required value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="input-group">
                            <label>Cupo Máximo</label>
                            <input type="number" name="cupo_maximo" value="20" min="1">
                        </div>
                    </div>
                    <input type="hidden" name="estado" value="planificado">

                    <input type="hidden" name="action" value="crear_grupo">

                    <div class="form-row">
                        <div class="input-group">
                            <label>Nombre del grupo <span class="req">*</span></label>
                            <input type="text" name="nombre" required placeholder="Ej: Piano Básico A">
                        </div>
                    </div>

                    <div class="form-row form-row--2">
                        <div class="input-group">
                            <label>Curso <span class="req">*</span></label>
                            <select name="curso_id" required>
                                <option value="">Seleccionar...</option>
                                <?php foreach ($cursos as $c): ?>
                                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="input-group">
                            <label>Profesor</label>
                            <select name="profesor_id">
                                <option value="">Sin asignar</option>
                                <?php foreach ($profesores as $p): ?>
                                    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-row form-row--2">
                        <div class="input-group">
                            <label>Día <span class="req">*</span></label>
                            <select name="dia_semana" required>
                                <option value="lunes">Lunes</option>
                                <option value="martes">Martes</option>
                                <option value="wed">Miércoles</option>
                                <option value="thu">Jueves</option>
                                <option value="fri">Viernes</option>
                                <option value="sat">Sábado</option>
                                <option value="sun">Domingo</option>
                                <!-- ... otros días ... -->
                            </select>
                        </div>
                        <div class="input-group">
                            <label>Aula</label>
                            <input type="text" name="aula" placeholder="Ej: Salón 1">
                        </div>
                    </div>

                    <div class="form-row form-row--2">
                        <div class="input-group">
                            <label>Hora Inicio <span class="req">*</span></label>
                            <input type="time" name="hora_inicio" required>
                        </div>
                        <div class="input-group">
                            <label>Hora Fin <span class="req">*</span></label>
                            <input type="time" name="hora_fin" required>
                        </div>
                    </div>

                    <div class="form-actions" style="margin-top:20px; display:flex; justify-content:flex-end; gap:10px;">
                        <button type="button" class="btn-cancel" onclick="cerrarModalCrear()" class="btn-cancel">Cancelar</button>
                        <button type="submit" class="btn-submit">Guardar Grupo</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <!-- Modal para Editar Grupo -->
    <div id="modalEditarGrupo" class="modal-overlay">
        <div class="modal-content" style="max-width: 700px;">
            <div class="modal-header">
                <div>
                    <h3>Editar Grupo</h3>
                    <p id="edit-grupo-id-label" style="font-size:0.85rem; color:var(--text-secondary);"></p>
                </div>
                <button onclick="cerrarModalEditar()" class="btn-close-modal">
                    <span class="material-symbols-rounded">close</span>
                </button>
            </div>
            <div class="modal-body">
                <form method="POST" id="formEditarGrupo">
                    <input type="hidden" name="action" value="editar">
                    <input type="hidden" name="id" id="edit-id">

                    <div class="form-row">
                        <div class="input-group">
                            <label>Nombre del grupo <span class="req">*</span></label>
                            <input type="text" name="nombre" id="edit-nombre" required>
                        </div>
                    </div>

                    <div class="form-row form-row--2">
                        <div class="input-group">
                            <label>Curso <span class="req">*</span></label>
                            <select name="curso_id" id="edit-curso_id" required>
                                <?php foreach ($cursos as $c): ?>
                                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="input-group">
                            <label>Profesor</label>
                            <select name="profesor_id" id="edit-profesor_id">
                                <option value="">Sin asignar</option>
                                <?php foreach ($profesores as $p): ?>
                                    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-row form-row--3">
                        <div class="input-group">
                            <label>Cupo Máximo</label>
                            <input type="number" name="cupo_maximo" id="edit-cupo" min="1">
                        </div>
                        <div class="input-group">
                            <label>Fecha Inicio <span class="req">*</span></label>
                            <input type="date" name="fecha_inicio" id="edit-fecha_inicio" required>
                        </div>
                        <div class="input-group">
                            <label>Fecha Fin</label>
                            <input type="date" name="fecha_fin" id="edit-fecha_fin">
                        </div>
                    </div>

                    <div class="form-row form-row--2">
                        <div class="input-group">
                            <label>Horario (Texto)</label>
                            <input type="text" name="horario" id="edit-horario" placeholder="Ej: Lun y Mar 08:00 - 10:00">
                        </div>
                        <div class="input-group">
                            <label>Aula</label>
                            <input type="text" name="aula" id="edit-aula">
                        </div>
                    </div>

                    <div class="form-row" style="max-width: 200px;">
                        <div class="input-group">
                            <label>Estado</label>
                            <select name="estado" id="edit-estado">
                                <option value="planificado">Planificado</option>
                                <option value="activo">Activo</option>
                                <option value="finalizado">Finalizado</option>
                                <option value="cancelado">Cancelado</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-actions" style="margin-top:20px; display:flex; justify-content:flex-end; gap:10px;">
                        <button type="button" onclick="cerrarModalEditar()" class="btn-cancel">Cancelar</button>
                        <button type="submit" class="btn-submit">Guardar Cambios</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Lógica de los Dropdowns de estado
        document.querySelectorAll('.dropdown-estado').forEach(dd => {
            const btn = dd.querySelector('.tbl-btn.estado');
            const menu = dd.querySelector('.dropdown-estado-menu');
            btn.addEventListener('click', e => {
                e.stopPropagation();
                document.querySelectorAll('.dropdown-estado-menu.open').forEach(m => {
                    if (m !== menu) m.classList.remove('open');
                });
                menu.classList.toggle('open');
            });
        });
        document.addEventListener('click', () => {
            document.querySelectorAll('.dropdown-estado-menu.open').forEach(m => m.classList.remove('open'));
        });

        // Lógica del Modal de Horarios
        const horariosData = <?php echo json_encode($horarios_modal); ?>;
        const diasEsp = <?php echo json_encode($dias_esp); ?>;

        function abrirModalHorarios(grupoId, nombreGrupo) {
            const modal = document.getElementById('modalHorarios');
            const body = document.getElementById('modalBody');
            const titulo = document.getElementById('modalTitulo');
            const link = document.getElementById('linkGestionar');

            titulo.innerText = nombreGrupo;
            link.href = "../horarios/index.php?grupo_id=" + grupoId;

            let html = "";
            if (horariosData[grupoId] && horariosData[grupoId].length > 0) {
                html = "<ul>";
                horariosData[grupoId].forEach(h => {
                    html += `
                    <li>
                        <span class="material-symbols-rounded" style="color:var(--primary-green); font-size:20px;">event_available</span>
                        <div>
                            <div style="font-weight:600; font-size:0.95rem;">${diasEsp[h.dia_semana] || h.dia_semana}</div>
                            <div style="font-size:0.85rem; color:var(--text-secondary)">
                                ${h.hora_inicio.substring(0,5)} - ${h.hora_fin.substring(0,5)} 
                                ${h.aula ? '· Aula: ' + h.aula : ''}
                            </div>
                        </div>
                    </li>`;
                });
                html += "</ul>";
            } else {
                html = `<div style="text-align:center; padding:20px; color:var(--text-secondary);">
                            <span class="material-symbols-rounded" style="font-size:40px; display:block; margin-bottom:10px; opacity:0.5;">calendar_today</span>
                            Este grupo no tiene horarios registrados.
                        </div>`;
            }

            body.innerHTML = html;
            modal.classList.add('active');
        }

        function cerrarModal() {
            document.getElementById('modalHorarios').classList.remove('active');
        }

        window.onclick = function(event) {
            const modal = document.getElementById('modalHorarios');
            if (event.target == modal) cerrarModal();
        }

        // Auto-ocultar alertas
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(a => {
                a.style.transition = 'opacity 0.5s ease';
                a.style.opacity = '0';
                setTimeout(() => a.remove(), 500);
            });
        }, 4000);

        function abrirModalCrear() {
            document.getElementById('modalCrearGrupo').classList.add('active');
        }

        function cerrarModalCrear() {
            document.getElementById('modalCrearGrupo').classList.remove('active');
        }

        function abrirModalEditar(datos) {
            // Rellenar campos ocultos e informativos
            document.getElementById('edit-id').value = datos.id;
            document.getElementById('edit-grupo-id-label').innerText = 'Editando ID #' + datos.id;

            // Rellenar inputs de texto y select
            document.getElementById('edit-nombre').value = datos.nombre;
            document.getElementById('edit-curso_id').value = datos.curso_id;
            document.getElementById('edit-profesor_id').value = datos.profesor_id || '';
            document.getElementById('edit-cupo').value = datos.cupo_maximo;
            document.getElementById('edit-horario').value = datos.horario || '';
            document.getElementById('edit-aula').value = datos.aula || '';
            document.getElementById('edit-fecha_inicio').value = datos.fecha_inicio;
            document.getElementById('edit-fecha_fin').value = datos.fecha_fin || '';
            document.getElementById('edit-estado').value = datos.estado;

            // Mostrar modal
            document.getElementById('modalEditarGrupo').classList.add('active');
        }

        function cerrarModalEditar() {
            document.getElementById('modalEditarGrupo').classList.remove('active');
        }
        // Intercepción de scroll para móviles en la tabla "Grupos"
        document.addEventListener('DOMContentLoaded', () => {
            const tableWrapper = document.querySelector('.table-wrapper');

            // Solo ejecutar en pantallas móviles (menores a 768px)
            if (window.innerWidth < 768 && tableWrapper) {
                setTimeout(() => {
                    // 1. Desplazar un poco a la derecha (60px)
                    tableWrapper.scrollTo({
                        left: 60,
                        behavior: 'smooth'
                    });

                    // 2. Regresar al inicio después de 600ms
                    setTimeout(() => {
                        tableWrapper.scrollTo({
                            left: 0,
                            behavior: 'smooth'
                        });
                    }, 600);
                }, 1000); // Se dispara 1 segundo después de cargar para que el usuario lo note
            }
        });
    </script>
</body>

</html>