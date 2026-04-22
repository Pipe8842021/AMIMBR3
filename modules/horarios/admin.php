<?php
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth_check.php';
require_role('admin');

date_default_timezone_set('America/Bogota');
$meses = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
$dias_semana_nombres = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];

$mes_actual = date('n');
$anio_actual = date('Y');
$hoy = date('j');

$mensaje_feedback = "";
$tipo_feedback = "success";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'guardar_horario') {
        $grupo_id = intval($_POST['grupo_id']);
        $dia      = $_POST['dia_semana'];
        $hora_ini = $_POST['hora_inicio'];
        $hora_fin = $_POST['hora_fin'];
        $aula     = trim($_POST['aula']);
        $conflictos = [];

        if (!empty($aula)) {
            $stmt = $pdo->prepare("
                SELECT g.nombre as nombre_grupo, c.nombre as nombre_curso
                FROM horarios h
                JOIN grupos g ON h.grupo_id = g.id
                JOIN cursos c ON g.curso_id = c.id
                WHERE h.aula = ? AND h.dia_semana = ?
                  AND h.hora_inicio < ? AND h.hora_fin > ?
            ");
            $stmt->execute([$aula, $dia, $hora_fin, $hora_ini]);
            $cf = $stmt->fetch();
            if ($cf) $conflictos[] = "El aula '{$aula}' ya está ocupada ese día por el grupo '{$cf['nombre_grupo']}' ({$cf['nombre_curso']}).";
        }


        $stmt = $pdo->prepare("SELECT profesor_id FROM grupos WHERE id = ?");
        $stmt->execute([$grupo_id]);
        $profesor_id = $stmt->fetchColumn();
        if ($profesor_id) {
            $stmt = $pdo->prepare("
                SELECT g.nombre as nombre_grupo, c.nombre as nombre_curso
                FROM horarios h
                JOIN grupos g ON h.grupo_id = g.id
                JOIN cursos c ON g.curso_id = c.id
                WHERE g.profesor_id = ? AND g.id != ? AND h.dia_semana = ?
                  AND h.hora_inicio < ? AND h.hora_fin > ?
            ");
            $stmt->execute([$profesor_id, $grupo_id, $dia, $hora_fin, $hora_ini]);
            $cf = $stmt->fetch();
            if ($cf) $conflictos[] = "El profesor ya tiene clase ese día con el grupo '{$cf['nombre_grupo']}' ({$cf['nombre_curso']}).";
        }
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
        $stmt = $pdo->prepare("
            SELECT DISTINCT u.nombre as nombre_estudiante, g2.nombre as nombre_grupo_conflicto, c2.nombre as nombre_curso_conflicto
            FROM matriculas m
            JOIN usuarios u ON m.estudiante_id = u.id
            JOIN matriculas m2 ON m2.estudiante_id = m.estudiante_id AND m2.grupo_id != ?
            JOIN grupos g2 ON m2.grupo_id = g2.id
            JOIN cursos c2 ON g2.curso_id = c2.id
            JOIN horarios h2 ON h2.grupo_id = g2.id
            WHERE m.grupo_id = ? AND m.estado = 'activa' AND m2.estado = 'activa'
              AND h2.dia_semana = ? AND h2.hora_inicio < ? AND h2.hora_fin > ?
            LIMIT 5
        ");
        $stmt->execute([$grupo_id, $grupo_id, $dia, $hora_fin, $hora_ini]);
        foreach ($stmt->fetchAll() as $ce) {
            $conflictos[] = "El estudiante '{$ce['nombre_estudiante']}' ya tiene clase en '{$ce['nombre_grupo_conflicto']}' a esa hora.";
        }

        if (empty($conflictos)) {
            $stmt = $pdo->prepare("INSERT INTO horarios (grupo_id, dia_semana, hora_inicio, hora_fin, aula) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$grupo_id, $dia, $hora_ini, $hora_fin, $aula]);
            $mensaje_feedback = "Horario guardado correctamente.";
        } else {
            $tipo_feedback = "error";
            $mensaje_feedback = "No se guardó el horario. Conflictos detectados:<br>• " . implode('<br>• ', $conflictos);
        }
    }

    if ($_POST['action'] === 'editar_horario') {
        $horario_id = intval($_POST['horario_id']);
        $dia        = $_POST['dia_semana'];
        $hora_ini   = $_POST['hora_inicio'];
        $hora_fin   = $_POST['hora_fin'];
        $aula       = trim($_POST['aula']);
        $conflictos = [];


        $stmt = $pdo->prepare("SELECT grupo_id FROM horarios WHERE id = ?");
        $stmt->execute([$horario_id]);
        $grupo_id = $stmt->fetchColumn();

        if (!empty($aula)) {
            $stmt = $pdo->prepare("
                SELECT g.nombre as nombre_grupo, c.nombre as nombre_curso
                FROM horarios h
                JOIN grupos g ON h.grupo_id = g.id
                JOIN cursos c ON g.curso_id = c.id
                WHERE h.aula = ? AND h.dia_semana = ? AND h.id != ?
                  AND h.hora_inicio < ? AND h.hora_fin > ?
            ");
            $stmt->execute([$aula, $dia, $horario_id, $hora_fin, $hora_ini]);
            $cf = $stmt->fetch();
            if ($cf) $conflictos[] = "El aula '{$aula}' ya está ocupada ese día por el grupo '{$cf['nombre_grupo']}' ({$cf['nombre_curso']}).";
        }

        $stmt = $pdo->prepare("SELECT profesor_id FROM grupos WHERE id = ?");
        $stmt->execute([$grupo_id]);
        $profesor_id = $stmt->fetchColumn();
        if ($profesor_id) {
            $stmt = $pdo->prepare("
                SELECT g.nombre as nombre_grupo, c.nombre as nombre_curso
                FROM horarios h
                JOIN grupos g ON h.grupo_id = g.id
                JOIN cursos c ON g.curso_id = c.id
                WHERE g.profesor_id = ? AND h.id != ? AND h.dia_semana = ?
                  AND h.hora_inicio < ? AND h.hora_fin > ?
            ");
            $stmt->execute([$profesor_id, $horario_id, $dia, $hora_fin, $hora_ini]);
            $cf = $stmt->fetch();
            if ($cf) $conflictos[] = "El profesor ya tiene clase ese día con el grupo '{$cf['nombre_grupo']}' ({$cf['nombre_curso']}).";
        }

        if (empty($conflictos)) {
            $stmt = $pdo->prepare("UPDATE horarios SET dia_semana = ?, hora_inicio = ?, hora_fin = ?, aula = ? WHERE id = ?");
            $stmt->execute([$dia, $hora_ini, $hora_fin, $aula, $horario_id]);
            $mensaje_feedback = "Horario actualizado correctamente.";
        } else {
            $tipo_feedback = "error";
            $mensaje_feedback = "No se actualizó el horario. Conflictos detectados:<br>• " . implode('<br>• ', $conflictos);
        }
    }
}


try {
    $clases_semana = $pdo->query("SELECT COUNT(*) FROM horarios")->fetchColumn();

    $dia_hoy_nombre = strtolower($dias_semana_nombres[date('N') - 1]);
    $stmt = $pdo->prepare("
        SELECT c.nombre as nombre_curso, h.hora_inicio
        FROM horarios h
        JOIN grupos g ON h.grupo_id = g.id
        JOIN cursos c ON g.curso_id = c.id
        WHERE h.dia_semana = ? ORDER BY h.hora_inicio ASC LIMIT 1
    ");
    $stmt->execute([$dia_hoy_nombre]);
    $proxima_clase = $stmt->fetch();

    $stmt = $pdo->query("
        SELECT h.id, h.grupo_id, h.dia_semana, h.hora_inicio, h.hora_fin,
               c.nombre as nombre_curso, h.aula, g.nombre as nombre_grupo
        FROM horarios h
        JOIN grupos g ON h.grupo_id = g.id
        JOIN cursos c ON g.curso_id = c.id
        WHERE g.estado = 'activo'
        ORDER BY h.hora_inicio ASC
    ");
    $eventos_db = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Agrupar eventos por día
    $mapa_eventos = [];
    foreach ($eventos_db as $ev) {
        $mapa_eventos[strtolower($ev['dia_semana'])][] = $ev;
    }
} catch (PDOException $e) {
    error_log($e->getMessage());
}

// Pasar todos los eventos al JS como JSON para el modal de detalle
$eventos_json = json_encode($mapa_eventos, JSON_UNESCAPED_UNICODE);

$primer_dia_mes = date('N', strtotime("$anio_actual-$mes_actual-01"));
$dias_en_mes    = date('t', strtotime("$anio_actual-$mes_actual-01"));
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Calendario Académico - Amimbré</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0" />
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="../../assets/css/colores.css">
    <link rel="stylesheet" href="../../assets/css/style-horariosAdmin.css">
    <script>
        (function() {
            const theme = localStorage.getItem('amimbre-theme');
            if (theme === 'light') document.documentElement.setAttribute('data-theme', 'light');
        })();
    </script>
    <style>
        .modal-day-list {
            list-style: none;
            padding: 0;
            margin: 0;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .modal-day-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 15px;
            background: var(--hover-bg);
            border-radius: 10px;
            border-left: 4px solid var(--primary-blue);
            gap: 12px;
        }

        .modal-day-item-info {
            display: flex;
            flex-direction: column;
            gap: 3px;
            flex: 1;
        }

        .modal-day-item-curso {
            font-weight: 600;
            font-size: 0.9rem;
            color: var(--text-primary);
        }

        .modal-day-item-meta {
            font-size: 0.8rem;
            color: var(--text-secondary);
        }

        .btn-edit-item {
            padding: 6px 14px;
            background: var(--primary-blue);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 0.8rem;
            cursor: pointer;
            white-space: nowrap;
            transition: background 0.2s;
        }

        .btn-edit-item:hover {
            background: var(--primary-green);
        }


        .day {
            cursor: pointer;
            transition: border-color 0.2s, transform 0.15s;
        }

        .day:not(.empty):hover {
            border-color: var(--primary-blue);
            transform: translateY(-2px);
        }


        .event-tag-single {
            font-size: 0.7rem;
            padding: 4px 8px;
            border-radius: 6px;
            margin-top: 5px;
            background: var(--subtle-blue);
            color: var(--primary-blue);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            border-left: 3px solid var(--primary-blue);
            pointer-events: none;
        }

        .event-more-badge {
            display: inline-block;
            margin-top: 4px;
            font-size: 0.68rem;
            color: var(--text-secondary);
            background: var(--border-color);
            border-radius: 20px;
            padding: 2px 8px;
            font-weight: 500;
            pointer-events: none;
        }


        .feedback-success {
            background: var(--subtle-green);
            color: var(--primary-green);
            border: 1px solid var(--primary-green);
        }

        .feedback-error {
            background: rgba(239, 68, 68, .12);
            color: #ef4444;
            border: 1px solid #ef4444;
        }
    </style>
</head>

<body>

    <?php include_once '../../includes/header.php'; ?>

    <main class="main-content">
        <div class="dashboard-header">
            <div class="dashboard-title">
                <h1>Calendario Académico</h1>
                <p>Visualización de clases y gestión de aulas</p>
            </div>
            <div class="dashboard-date">
                <span class="material-symbols-rounded" style="vertical-align:middle;">calendar_month</span>
                <?php echo "{$dias_semana_nombres[date('N') - 1]}, " . date('d') . " de {$meses[$mes_actual]}"; ?>
            </div>
        </div>

        <?php if ($mensaje_feedback): ?>
            <div style="padding:15px 20px; border-radius:10px; margin-bottom:20px;" class="feedback-<?php echo $tipo_feedback; ?>">
                <i class="fas <?php echo $tipo_feedback === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?>"></i>
                <?php echo $mensaje_feedback; ?>
            </div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-title">Clases Programadas</span>
                    <div class="stat-icon blue"><span class="material-symbols-rounded">menu_book</span></div>
                </div>
                <div class="stat-value"><?php echo $clases_semana; ?> Sesiones</div>
                <div class="stat-change">Total registradas</div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-title">Próxima Clase</span>
                    <div class="stat-icon orange"><span class="material-symbols-rounded">event_upcoming</span></div>
                </div>
                <div class="stat-value" style="font-size:1.1rem;">
                    <?php echo $proxima_clase ? htmlspecialchars($proxima_clase['nombre_curso']) : 'Sin clases hoy'; ?>
                </div>
                <div class="stat-change">Hoy: <?php echo $proxima_clase ? date('H:i', strtotime($proxima_clase['hora_inicio'])) : '--:--'; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-title">Mes Actual</span>
                    <div class="stat-icon green"><span class="material-symbols-rounded">calendar_today</span></div>
                </div>
                <div class="stat-value"><?php echo $meses[$mes_actual]; ?></div>
                <div class="stat-change"><?php echo $anio_actual; ?></div>
            </div>
        </div>

        <div class="content-grid">
            <div class="calendar-card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                    <h3>Horario Mensual</h3>
                    <button onclick="abrirModal()" style="padding:8px 15px; background:var(--primary-blue); color:white; border:none; border-radius:8px; cursor:pointer; font-weight:500;">
                        <i class="fas fa-plus"></i> Nuevo Horario
                    </button>
                </div>

                <div class="calendar-grid">
                    <?php foreach ($dias_semana_nombres as $d): ?>
                        <div class="day-name"><?php echo substr($d, 0, 3); ?></div>
                    <?php endforeach; ?>

                    <?php
                    for ($i = 1; $i < $primer_dia_mes; $i++) echo '<div class="day empty"></div>';

                    for ($dia = 1; $dia <= $dias_en_mes; $dia++):
                        $timestamp       = strtotime("$anio_actual-$mes_actual-$dia");
                        $dia_key         = strtolower($dias_semana_nombres[date('N', $timestamp) - 1]);
                        $nombre_dia_full = $dias_semana_nombres[date('N', $timestamp) - 1]; // ej. "Lunes"
                        $clase_hoy       = ($dia == $hoy) ? 'today' : '';
                        $eventos_del_dia = $mapa_eventos[$dia_key] ?? [];
                        $total_eventos   = count($eventos_del_dia);
                        // Pasar clave del día al onclick para recuperar desde JS
                        $dia_label = "$dia de {$meses[$mes_actual]}";
                    ?>
                        <div class="day <?php echo $clase_hoy; ?>"
                            onclick="<?php echo $total_eventos > 0 ? "abrirDetalleDia('$dia_key', '$nombre_dia_full $dia_label')" : "abrirModal()"; ?>">
                            <span class="day-number"><?php echo $dia; ?></span>

                            <?php if ($total_eventos >= 1): ?>
                                <?php $ev = $eventos_del_dia[0]; ?>
                                <div class="event-tag-single" title="<?php echo htmlspecialchars($ev['nombre_grupo']); ?>">
                                    <b><?php echo date('H:i', strtotime($ev['hora_inicio'])); ?></b>
                                    <?php echo htmlspecialchars($ev['nombre_curso']); ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($total_eventos > 1): ?>
                                <span class="event-more-badge">+<?php echo $total_eventos - 1; ?> más</span>
                            <?php endif; ?>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>

            <div class="calendar-card" style="height:fit-content;">
                <h3>Ayuda</h3>
                <div style="margin-top:15px; display:flex; flex-direction:column; gap:12px;">
                    <p style="font-size:0.9rem; color:var(--text-secondary);">
                        <i class="fas fa-mouse-pointer"></i> Haz clic sobre cualquier día con clases para ver y editar los horarios de ese día.
                    </p>
                    <p style="font-size:0.9rem; color:var(--text-secondary);">
                        <i class="fas fa-plus-circle"></i> Haz clic sobre un día vacío o usa "Nuevo Horario" para agregar una clase.
                    </p>
                    <p style="font-size:0.9rem; color:var(--text-secondary);">
                        <i class="fas fa-info-circle"></i> Los horarios son cíclicos: se repiten cada semana en el día configurado.
                    </p>
                    <div style="display:flex; align-items:center; gap:10px; font-size:0.8rem; color:var(--text-secondary);">
                        <div style="width:12px; height:12px; border:2px solid var(--primary-blue); border-radius:2px; flex-shrink:0;"></div>
                        Día actual
                    </div>
                </div>
            </div>
        </div>
    </main>

    <div id="modalHorario" class="modal">
        <div class="modal-content">
            <div style="display:flex; justify-content:space-between; margin-bottom:20px; align-items:center;">
                <h2 style="font-size:1.3rem; color:var(--primary-blue);">Nuevo Horario</h2>
                <span onclick="cerrarModal()" style="cursor:pointer; font-size:1.5rem; color:var(--text-secondary);">&times;</span>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="guardar_horario">
                <div class="form-group">
                    <label>Grupo Activo</label>
                    <select name="grupo_id" required class="input-form">
                        <?php
                        $grupos = $pdo->query("SELECT id, nombre FROM grupos WHERE estado = 'activo'")->fetchAll();
                        foreach ($grupos as $g) echo "<option value='{$g['id']}'>" . htmlspecialchars($g['nombre']) . "</option>";
                        ?>
                    </select>
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                    <div class="form-group">
                        <label>Día de la Semana</label>
                        <select name="dia_semana" id="nuevo_dia" class="input-form">
                            <option value="lunes">Lunes</option>
                            <option value="martes">Martes</option>
                            <option value="miercoles">Miércoles</option>
                            <option value="jueves">Jueves</option>
                            <option value="viernes">Viernes</option>
                            <option value="sabado">Sábado</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Aula / Salón</label>
                        <input type="text" name="aula" placeholder="Ej: Aula 1" class="input-form">
                    </div>
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                    <div class="form-group"><label>Hora Inicio</label><input type="time" name="hora_inicio" required class="input-form"></div>
                    <div class="form-group"><label>Hora Fin</label><input type="time" name="hora_fin" required class="input-form"></div>
                </div>
                <button type="submit" style="width:100%; padding:12px; background:var(--primary-blue); color:white; border:none; border-radius:8px; font-weight:600; cursor:pointer; margin-top:10px;">
                    <i class="fas fa-save"></i> Guardar
                </button>
            </form>
        </div>
    </div>


    <div id="modalDetalleDia" class="modal">
        <div class="modal-content">
            <div style="display:flex; justify-content:space-between; margin-bottom:20px; align-items:center;">
                <div>
                    <h2 style="font-size:1.3rem; color:var(--primary-blue);" id="detalle-titulo">Horarios del día</h2>
                    <p style="font-size:0.85rem; color:var(--text-secondary); margin-top:3px;" id="detalle-subtitulo"></p>
                </div>
                <span onclick="cerrarDetalleDia()" style="cursor:pointer; font-size:1.5rem; color:var(--text-secondary);">&times;</span>
            </div>
            <ul class="modal-day-list" id="detalle-lista"></ul>
        </div>
    </div>

    <div id="modalEditarHorario" class="modal">
        <div class="modal-content">
            <div style="display:flex; justify-content:space-between; margin-bottom:20px; align-items:center;">
                <div>
                    <h2 style="font-size:1.3rem; color:var(--primary-blue);">Editar Horario</h2>
                    <p style="font-size:0.85rem; color:var(--text-secondary); margin-top:3px;" id="edit-subtitulo"></p>
                </div>
                <span onclick="cerrarModalEdit()" style="cursor:pointer; font-size:1.5rem; color:var(--text-secondary);">&times;</span>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="editar_horario">
                <input type="hidden" name="horario_id" id="edit_horario_id">
                <div class="form-group">
                    <label>Día de la Semana</label>
                    <select name="dia_semana" id="edit_dia" class="input-form">
                        <option value="lunes">Lunes</option>
                        <option value="martes">Martes</option>
                        <option value="miercoles">Miércoles</option>
                        <option value="jueves">Jueves</option>
                        <option value="viernes">Viernes</option>
                        <option value="sabado">Sábado</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Aula / Salón</label>
                    <input type="text" name="aula" id="edit_aula" class="input-form">
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                    <div class="form-group"><label>Hora Inicio</label><input type="time" name="hora_inicio" id="edit_inicio" required class="input-form"></div>
                    <div class="form-group"><label>Hora Fin</label><input type="time" name="hora_fin" id="edit_fin" required class="input-form"></div>
                </div>
                <button type="submit" style="width:100%; padding:12px; background:var(--primary-blue); color:white; border:none; border-radius:8px; font-weight:600; cursor:pointer; margin-top:15px;">
                    <i class="fas fa-save"></i> Actualizar Horario
                </button>
            </form>
        </div>
    </div>

    <script>
        const todosLosEventos = <?php echo $eventos_json; ?>;


        function fmtHora(h) {
            return h ? h.substring(0, 5) : '--:--';
        }


        function abrirModal(diaPreseleccionado) {
            if (diaPreseleccionado) document.getElementById('nuevo_dia').value = diaPreseleccionado;
            document.getElementById('modalHorario').style.display = 'block';
        }

        function cerrarModal() {
            document.getElementById('modalHorario').style.display = 'none';
        }

        function abrirDetalleDia(diaKey, etiqueta) {
            const eventos = todosLosEventos[diaKey] || [];
            document.getElementById('detalle-titulo').textContent = 'Horarios del día';
            document.getElementById('detalle-subtitulo').textContent = etiqueta;

            const lista = document.getElementById('detalle-lista');
            lista.innerHTML = '';

            if (eventos.length === 0) {
                lista.innerHTML = '<li style="color:var(--text-secondary); font-size:0.9rem; text-align:center; padding:20px 0;">Sin horarios registrados para este día.</li>';
            } else {
                eventos.forEach(ev => {
                    const li = document.createElement('li');
                    li.className = 'modal-day-item';
                    li.innerHTML = `
                    <div class="modal-day-item-info">
                        <span class="modal-day-item-curso">${ev.nombre_curso}</span>
                        <span class="modal-day-item-meta">
                            <i class="fas fa-clock" style="font-size:11px;"></i> ${fmtHora(ev.hora_inicio)} – ${fmtHora(ev.hora_fin)}
                            &nbsp;|&nbsp;
                            <i class="fas fa-users" style="font-size:11px;"></i> ${ev.nombre_grupo}
                            &nbsp;|&nbsp;
                            <i class="fas fa-door-open" style="font-size:11px;"></i> Aula: ${ev.aula || '—'}
                        </span>
                    </div>
                    <button class="btn-edit-item" onclick="abrirModalEdit(${JSON.stringify(ev).replace(/"/g,'&quot;')})">
                        <i class="fas fa-pen"></i> Editar
                    </button>
                `;
                    lista.appendChild(li);
                });
            }

            document.getElementById('modalDetalleDia').style.display = 'block';
        }

        function cerrarDetalleDia() {
            document.getElementById('modalDetalleDia').style.display = 'none';
        }


        function abrirModalEdit(datos) {

            if (typeof datos === 'string') datos = JSON.parse(datos.replace(/&quot;/g, '"'));

            document.getElementById('edit_horario_id').value = datos.id;
            document.getElementById('edit_dia').value = datos.dia_semana.toLowerCase();
            document.getElementById('edit_aula').value = datos.aula || '';
            document.getElementById('edit_inicio').value = fmtHora(datos.hora_inicio);
            document.getElementById('edit_fin').value = fmtHora(datos.hora_fin);
            document.getElementById('edit-subtitulo').textContent =
                `${datos.nombre_curso} · ${datos.nombre_grupo}`;


            cerrarDetalleDia();
            document.getElementById('modalEditarHorario').style.display = 'block';
        }

        function cerrarModalEdit() {
            document.getElementById('modalEditarHorario').style.display = 'none';
        }


        window.onclick = function(e) {
            ['modalHorario', 'modalDetalleDia', 'modalEditarHorario'].forEach(id => {
                const m = document.getElementById(id);
                if (e.target === m) m.style.display = 'none';
            });
        };
    </script>
</body>

</html>