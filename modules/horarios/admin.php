<?php
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth_check.php';
require_role('admin');

date_default_timezone_set('America/Bogota');
$meses = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
$dias_semana_nombres = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];

$dia_bd_map = [
    1 => 'lunes',
    2 => 'martes',
    3 => 'miercoles',
    4 => 'jueves',
    5 => 'viernes',
    6 => 'sabado',
    7 => 'domingo',
];

$mes_actual  = date('n');
$anio_actual = date('Y');
$hoy         = date('j');

$mensaje_feedback = "";
$tipo_feedback    = "success";

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
                SELECT g.nombre AS nombre_grupo, c.nombre AS nombre_curso
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
                SELECT g.nombre AS nombre_grupo, c.nombre AS nombre_curso
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

        $stmt = $pdo->prepare("
            SELECT DISTINCT u.nombre AS nombre_estudiante,
                   g2.nombre AS nombre_grupo_conflicto,
                   c2.nombre AS nombre_curso_conflicto
            FROM matriculas m
            JOIN usuarios u    ON m.estudiante_id = u.id
            JOIN matriculas m2 ON m2.estudiante_id = m.estudiante_id AND m2.grupo_id != ?
            JOIN grupos g2     ON m2.grupo_id = g2.id
            JOIN cursos c2     ON g2.curso_id = c2.id
            JOIN horarios h2   ON h2.grupo_id = g2.id
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
            $tipo_feedback    = "error";
            $mensaje_feedback = "No se guardó el horario. Conflictos detectados:<br>• " . implode('<br>• ', $conflictos);
        }
    }

    /* ── Editar horario existente ── */
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
                SELECT g.nombre AS nombre_grupo, c.nombre AS nombre_curso
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
                SELECT g.nombre AS nombre_grupo, c.nombre AS nombre_curso
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
            $tipo_feedback    = "error";
            $mensaje_feedback = "No se actualizó el horario. Conflictos detectados:<br>• " . implode('<br>• ', $conflictos);
        }
    }
}

/* ══════════════════════════════════════════════════════════
   Consultas de datos
   ══════════════════════════════════════════════════════════ */
$mapa_eventos = [];
try {
    $clases_semana = $pdo->query("SELECT COUNT(*) FROM horarios")->fetchColumn();

    $dia_hoy_key = $dia_bd_map[(int)date('N')];

    $stmt = $pdo->prepare("
        SELECT c.nombre AS nombre_curso, h.hora_inicio
        FROM horarios h
        JOIN grupos g ON h.grupo_id = g.id
        JOIN cursos c ON g.curso_id = c.id
        WHERE h.dia_semana = ?
        ORDER BY h.hora_inicio ASC
        LIMIT 1
    ");
    $stmt->execute([$dia_hoy_key]);
    $proxima_clase = $stmt->fetch();

    $stmt = $pdo->query("
        SELECT h.id, h.grupo_id, h.dia_semana, h.hora_inicio, h.hora_fin,
               c.nombre AS nombre_curso, h.aula, g.nombre AS nombre_grupo
        FROM horarios h
        JOIN grupos g ON h.grupo_id = g.id
        JOIN cursos c ON g.curso_id = c.id
        WHERE g.estado = 'activo'
        ORDER BY h.hora_inicio ASC
    ");
    $eventos_db = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $mapa_eventos = [];
    foreach ($eventos_db as $ev) {
        $mapa_eventos[strtolower($ev['dia_semana'])][] = $ev;
    }
} catch (PDOException $e) {
    error_log($e->getMessage());
}

$eventos_json   = json_encode($mapa_eventos, JSON_UNESCAPED_UNICODE);
$primer_dia_mes = date('N', strtotime("$anio_actual-$mes_actual-01"));
$dias_en_mes    = date('t', strtotime("$anio_actual-$mes_actual-01"));

$hoy_dia_key = $dia_bd_map[(int)date('N')];
$hoy_label   = $dias_semana_nombres[date('N') - 1] . ', ' . $hoy . ' de ' . $meses[$mes_actual];
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendario Académico – Amimbré</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="../../assets/css/colores.css">
    <link rel="stylesheet" href="../../assets/css/style-horarios.css">
    <script>
        (function() {
            const t = localStorage.getItem('amimbre-theme');
            document.documentElement.setAttribute('data-theme', t === 'light' ? 'light' : 'dark');
        })();
    </script>
</head>

<body>
    <?php include_once '../../includes/header.php'; ?>

    <main class="main-content">

        <!-- ── Encabezado ── -->
        <div class="dashboard-header">
            <div class="dashboard-title">
                <h1>Calendario Académico</h1>
                <p>Visualización de clases y gestión de aulas</p>
            </div>
            <div class="dashboard-date">
                <span class="material-symbols-rounded">calendar_month</span>
                <?php echo $dias_semana_nombres[date('N') - 1] . ', ' . date('d') . ' de ' . $meses[$mes_actual]; ?>
            </div>
        </div>

        <!-- ── Feedback ── -->
        <?php if ($mensaje_feedback): ?>
            <div class="feedback-banner feedback-<?php echo $tipo_feedback; ?>">
                <i class="fas <?php echo $tipo_feedback === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?>"></i>
                <span><?php echo $mensaje_feedback; ?></span>
            </div>
        <?php endif; ?>

        <!-- ── Stats ── -->
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
                <div class="stat-change">
                    Hoy: <?php echo $proxima_clase ? date('H:i', strtotime($proxima_clase['hora_inicio'])) : '--:--'; ?>
                </div>
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

        <!-- ── Contenido principal ── -->
        <div class="content-grid">

            <!-- Calendario -->
            <div class="calendar-card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                    <h3>Horario Mensual</h3>
                    <!-- FIX: botón correctamente cerrado, sin contenido anidado -->
                    <button onclick="abrirModal()" class="btn-submit-modal" style="width:auto; padding:8px 16px; margin:0;">
                        <i class="fas fa-plus"></i> Nuevo Horario
                    </button>
                </div>

                <!-- Cuadrícula del calendario -->
                <div class="calendar-grid">
                    <!-- Nombres de días -->
                    <?php foreach ($dias_semana_nombres as $d): ?>
                        <div class="day-name"><?php echo mb_substr($d, 0, 3); ?></div>
                    <?php endforeach; ?>

                    <!-- Celdas vacías al inicio -->
                    <?php for ($i = 1; $i < $primer_dia_mes; $i++): ?>
                        <div class="day empty"></div>
                    <?php endfor; ?>

                    <!-- Días del mes -->
                    <?php for ($dia = 1; $dia <= $dias_en_mes; $dia++):
                        $ts              = strtotime("$anio_actual-$mes_actual-$dia");
                        $dow             = (int)date('N', $ts);
                        $dia_key         = $dia_bd_map[$dow];
                        $nombre_dia_full = $dias_semana_nombres[$dow - 1];
                        $clase_hoy       = ($dia == $hoy) ? 'today' : '';
                        $eventos_del_dia = $mapa_eventos[$dia_key] ?? [];
                        $total_eventos   = count($eventos_del_dia);
                        $dia_label       = "$dia de {$meses[$mes_actual]}";
                        $onclick         = $total_eventos > 0
                            ? "abrirDetalleDia('$dia_key','$nombre_dia_full $dia_label')"
                            : "abrirModal('$dia_key')";
                    ?>
                        <div class="day <?php echo $clase_hoy; ?>"
                            onclick="<?php echo $onclick; ?>"
                            title="<?php echo "$nombre_dia_full $dia_label"; ?>">
                            <span class="day-number"><?php echo $dia; ?></span>

                            <?php if ($total_eventos >= 1):
                                $ev = $eventos_del_dia[0]; ?>
                                <div class="event-tag-single"
                                    title="<?php echo htmlspecialchars($ev['nombre_grupo'] . ' · ' . $ev['nombre_curso']); ?>">
                                    <b><?php echo date('H:i', strtotime($ev['hora_inicio'])); ?></b>
                                    <?php echo htmlspecialchars($ev['nombre_curso']); ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($total_eventos > 1): ?>
                                <span class="event-more-badge">+<?php echo $total_eventos - 1; ?> más</span>
                            <?php endif; ?>
                        </div>
                    <?php endfor; ?>
                </div><!-- /calendar-grid -->
            </div><!-- /calendar-card -->

            <!-- Ayuda -->
            <div class="help-card">
                <h3>Ayuda</h3>
                <div class="help-items">
                    <div class="help-item">
                        <i class="fas fa-mouse-pointer"></i>
                        <span>Haz clic sobre cualquier día con clases para ver y editar los horarios de ese día.</span>
                    </div>
                    <div class="help-item">
                        <i class="fas fa-plus-circle"></i>
                        <span>Haz clic sobre un día vacío o usa "Nuevo Horario" para agregar una clase.</span>
                    </div>
                    <div class="help-item">
                        <i class="fas fa-info-circle"></i>
                        <span>Los horarios son cíclicos: se repiten cada semana en el día configurado.</span>
                    </div>
                    <div class="help-legend" onclick="irHoy()" title="Ver horarios de hoy">
                        <div class="legend-box"></div>
                        <span>Día actual — click para ver hoy</span>
                    </div>
                </div>
            </div><!-- /help-card -->

        </div><!-- /content-grid -->

    </main>

    <!-- ══════════════════════════════════════════════════════════
         Modal: Nuevo Horario
         ══════════════════════════════════════════════════════════ -->
    <div id="modalHorario" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h2>Nuevo Horario</h2>
                </div>
                <button class="modal-close" onclick="cerrarModal()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="guardar_horario">
                <div class="form-group">
                    <label>Grupo Activo</label>
                    <select name="grupo_id" required class="input-form">
                        <?php
                        $grupos = $pdo->query("SELECT id, nombre FROM grupos WHERE estado = 'activo' ORDER BY nombre")->fetchAll();
                        foreach ($grupos as $g):
                        ?>
                            <option value="<?php echo $g['id']; ?>"><?php echo htmlspecialchars($g['nombre']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row-2">
                    <div class="form-group">
                        <label>Día de la Semana</label>
                        <select name="dia_semana" id="nuevo_dia" class="input-form">
                            <option value="lunes">Lunes</option>
                            <option value="martes">Martes</option>
                            <option value="miercoles">Miércoles</option>
                            <option value="jueves">Jueves</option>
                            <option value="viernes">Viernes</option>
                            <option value="sabado">Sábado</option>
                            <option value="domingo">Domingo</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Aula / Salón</label>
                        <input type="text" name="aula" placeholder="Ej: Aula 1" class="input-form">
                    </div>
                </div>
                <div class="form-row-2">
                    <div class="form-group">
                        <label>Hora Inicio</label>
                        <input type="time" name="hora_inicio" required class="input-form">
                    </div>
                    <div class="form-group">
                        <label>Hora Fin</label>
                        <input type="time" name="hora_fin" required class="input-form">
                    </div>
                </div>
                <button type="submit" class="btn-submit-modal">
                    <i class="fas fa-save"></i> Guardar
                </button>
            </form>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════
         Modal: Detalle del día
         ══════════════════════════════════════════════════════════ -->
    <div id="modalDetalleDia" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h2 id="detalle-titulo">Horarios del día</h2>
                    <p id="detalle-subtitulo"></p>
                </div>
                <button class="modal-close" onclick="cerrarDetalleDia()">&times;</button>
            </div>
            <ul class="modal-day-list" id="detalle-lista"></ul>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════
         Modal: Editar Horario
         ══════════════════════════════════════════════════════════ -->
    <div id="modalEditarHorario" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h2>Editar Horario</h2>
                    <p id="edit-subtitulo"></p>
                </div>
                <button class="modal-close" onclick="cerrarModalEdit()">&times;</button>
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
                        <option value="domingo">Domingo</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Aula / Salón</label>
                    <input type="text" name="aula" id="edit_aula" class="input-form">
                </div>
                <div class="form-row-2">
                    <div class="form-group">
                        <label>Hora Inicio</label>
                        <input type="time" name="hora_inicio" id="edit_inicio" required class="input-form">
                    </div>
                    <div class="form-group">
                        <label>Hora Fin</label>
                        <input type="time" name="hora_fin" id="edit_fin" required class="input-form">
                    </div>
                </div>
                <button type="submit" class="btn-submit-modal">
                    <i class="fas fa-save"></i> Actualizar Horario
                </button>
            </form>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════
         JavaScript
         ══════════════════════════════════════════════════════════ -->
    <script>
        const todosLosEventos = <?php echo $eventos_json; ?>;
        const HOY_KEY = '<?php echo $hoy_dia_key; ?>';
        const HOY_LABEL = '<?php echo $hoy_label; ?>';

        /* ── Helpers ── */
        function fmtHora(h) {
            return h ? h.substring(0, 5) : '--:--';
        }

        /* ── Modal Nuevo Horario ── */
        function abrirModal(diaPreseleccionado) {
            if (diaPreseleccionado) document.getElementById('nuevo_dia').value = diaPreseleccionado;
            document.getElementById('modalHorario').style.display = 'block';
        }

        function cerrarModal() {
            document.getElementById('modalHorario').style.display = 'none';
        }

        /* ── Modal Detalle del día ── */
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
                                <i class="fas fa-door-open" style="font-size:11px;"></i> ${ev.aula || '—'}
                            </span>
                        </div>
                        <button class="btn-edit-item"
                                onclick="abrirModalEdit(${JSON.stringify(ev).replace(/"/g,'&quot;')})">
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

        /* ── Ir al día de hoy desde la leyenda de Ayuda ── */
        function irHoy() {
            const eventos = todosLosEventos[HOY_KEY] || [];
            if (eventos.length > 0) {
                abrirDetalleDia(HOY_KEY, HOY_LABEL);
            } else {
                abrirModal(HOY_KEY);
            }
        }

        /* ── Modal Editar Horario ── */
        function abrirModalEdit(datos) {
            if (typeof datos === 'string') datos = JSON.parse(datos.replace(/&quot;/g, '"'));

            document.getElementById('edit_horario_id').value = datos.id;
            document.getElementById('edit_dia').value = datos.dia_semana.toLowerCase();
            document.getElementById('edit_aula').value = datos.aula || '';
            document.getElementById('edit_inicio').value = fmtHora(datos.hora_inicio);
            document.getElementById('edit_fin').value = fmtHora(datos.hora_fin);
            document.getElementById('edit-subtitulo').textContent = `${datos.nombre_curso} · ${datos.nombre_grupo}`;

            cerrarDetalleDia();
            document.getElementById('modalEditarHorario').style.display = 'block';
        }

        function cerrarModalEdit() {
            document.getElementById('modalEditarHorario').style.display = 'none';
        }

        /* ── Cerrar modales al hacer clic fuera ── */
        window.addEventListener('click', e => {
            ['modalHorario', 'modalDetalleDia', 'modalEditarHorario'].forEach(id => {
                const m = document.getElementById(id);
                if (e.target === m) m.style.display = 'none';
            });
        });

        /* ── Sincronizar margin-left con el sidebar colapsable ── */
        (function() {
            const main = document.querySelector('.main-content');
            const EXPANDED = '270px';
            const COLLAPSED = '80px';

            function syncMargin() {
                const collapsed =
                    document.body.classList.contains('sidebar-collapsed') ||
                    document.querySelector('.sidebar')?.classList.contains('collapsed');
                main.style.marginLeft = collapsed ? COLLAPSED : EXPANDED;
            }

            const observer = new MutationObserver(syncMargin);
            observer.observe(document.body, {
                attributes: true,
                attributeFilter: ['class']
            });
            const sidebar = document.querySelector('.sidebar');
            if (sidebar) observer.observe(sidebar, {
                attributes: true,
                attributeFilter: ['class']
            });

            syncMargin();
        })();
    </script>
</body>

</html>