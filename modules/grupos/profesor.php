<?php
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth_check.php';
require_role('profesor');

$uid = (int)$_SESSION['user_id'];
$success = $_GET['success'] ?? null;
$error   = $_GET['error'] ?? null;

try {
    // Filtros GET (Basado en la lógica de admin)
    $filtro_buscar = trim($_GET['buscar'] ?? '');

    $where = ["g.profesor_id = ?"];
    $params = [$uid];

    if ($filtro_buscar) {
        $where[] = "(g.nombre LIKE ? OR c.nombre LIKE ?)";
        $params[] = "%$filtro_buscar%";
        $params[] = "%$filtro_buscar%";
    }

    $sql_where = "WHERE " . implode(" AND ", $where);

    // Grupos del profesor con datos completos
    $stmt = $pdo->prepare("
        SELECT
            g.*,
            c.nombre  AS curso_nombre,
            c.nivel   AS curso_nivel
        FROM grupos g
        JOIN cursos c ON g.curso_id = c.id
        $sql_where
        ORDER BY
            FIELD(g.estado, 'activo', 'planificado', 'finalizado', 'cancelado'),
            g.fecha_inicio DESC
    ");
    $stmt->execute($params);
    $mis_grupos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $ids_grupos = array_column($mis_grupos, 'id');
    $stats_grupos = [];
    $horarios_modal = [];

    if (!empty($ids_grupos)) {
        $ph = implode(',', array_fill(0, count($ids_grupos), '?'));

        // Obtener Horarios Detallados (Nueva lógica de base de datos)
        $stmtH = $pdo->prepare("SELECT * FROM horarios WHERE grupo_id IN ($ph) ORDER BY dia_semana, hora_inicio");
        $stmtH->execute($ids_grupos);
        while ($h = $stmtH->fetch(PDO::FETCH_ASSOC)) {
            $horarios_modal[$h['grupo_id']][] = $h;
        }

        // Asistencia por grupo
        $stmt = $pdo->prepare("
            SELECT
                b.grupo_id,
                COUNT(ba.id) AS total_registros,
                SUM(CASE WHEN ba.estado = 'presente' THEN 1 ELSE 0 END) AS presentes
            FROM bitacoras_asistencias ba
            JOIN bitacoras b ON ba.bitacora_id = b.id
            WHERE b.grupo_id IN ($ph)
            GROUP BY b.grupo_id
        ");
        $stmt->execute($ids_grupos);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $stats_grupos[$r['grupo_id']] = $r;
        }
    }

    // Totales generales para los chips
    $total_grupos    = count($mis_grupos);
    $grupos_activos  = count(array_filter($mis_grupos, fn($g) => $g['estado'] === 'activo'));

    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT m.estudiante_id) AS total
        FROM matriculas m
        JOIN grupos g ON m.grupo_id = g.id
        WHERE g.profesor_id = ? AND m.estado = 'activa'
    ");
    $stmt->execute([$uid]);
    $total_estudiantes = (int)$stmt->fetchColumn();
} catch (PDOException $e) {
    error_log($e->getMessage());
    $mis_grupos = [];
    $horarios_modal = [];
    $total_grupos = $grupos_activos = $total_estudiantes = 0;
}

// Configuración de UI
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
$dias_esp = ['mon' => 'Lunes', 'tue' => 'Martes', 'wed' => 'Miércoles', 'thu' => 'Jueves', 'fri' => 'Viernes', 'sat' => 'Sábado', 'sun' => 'Domingo'];

date_default_timezone_set('America/Bogota');
$dias_semana = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
$meses       = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
$fecha_hoy   = $dias_semana[date('w')] . ', ' . date('d') . ' de ' . $meses[date('n')] . ' de ' . date('Y');
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis Grupos – Amimbré</title>
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
            <div class="header-left">
                <button class="btn-back" onclick="window.history.back()">
                    <span class="material-symbols-rounded">arrow_back</span>
                </button>
                <div class="dashboard-title">
                    <h1>Mis Grupos</h1>
                    <p>Gestión académica de tus secciones asignadas</p>
                </div>
            </div>
            <div class="date-display">
                <span class="material-symbols-rounded">calendar_today</span>
                <?php echo $fecha_hoy; ?>
            </div>
        </div>

        <div class="modulo-stats">
            <div class="modulo-stat-chip total">
                <span class="material-symbols-rounded">layers</span>
                <div>
                    <span class="chip-value"><?php echo $total_grupos; ?></span>
                    <span class="chip-label">Total grupos</span>
                </div>
            </div>
            <div class="modulo-stat-chip activo">
                <span class="material-symbols-rounded">play_circle</span>
                <div>
                    <span class="chip-value"><?php echo $grupos_activos; ?></span>
                    <span class="chip-label">Activos</span>
                </div>
            </div>
            <div class="modulo-stat-chip planificado">
                <span class="material-symbols-rounded">group</span>
                <div>
                    <span class="chip-value"><?php echo $total_estudiantes; ?></span>
                    <span class="chip-label">Estudiantes</span>
                </div>
            </div>
        </div>

        <div class="card">
            <form method="GET" class="filtros-bar">
                <div class="filtros-buscar">
                    <span class="material-symbols-rounded">search</span>
                    <input type="text" name="buscar" value="<?php echo htmlspecialchars($filtro_buscar); ?>" placeholder="Buscar por grupo o curso...">
                </div>
                <button type="submit" class="btn-filtrar">
                    <span class="material-symbols-rounded">filter_alt</span>
                </button>
            </form>

            <?php if (empty($mis_grupos)): ?>
                <div class="empty-state">
                    <span class="material-symbols-rounded">groups</span>
                    <p>No se encontraron grupos asignados.</p>
                </div>
            <?php else: ?>
                <div class="grupos-profesor-grid">
                    <?php foreach ($mis_grupos as $g):
                        $est = $estado_cfg[$g['estado']] ?? $estado_cfg['planificado'];
                        $niv = $nivel_cfg[strtolower($g['curso_nivel'])] ?? 'badge-info';
                        $pct_cupo = $g['cupo_maximo'] > 0 ? round(($g['cupo_actual'] / $g['cupo_maximo']) * 100) : 0;
                        $bar_cls = $pct_cupo >= 90 ? 'bar-danger' : ($pct_cupo >= 70 ? 'bar-warning' : 'bar-success');

                        $stats = $stats_grupos[$g['id']] ?? ['total_registros' => 0, 'presentes' => 0];
                        $pct_asist = $stats['total_registros'] > 0 ? round(($stats['presentes'] / $stats['total_registros']) * 100) : null;
                    ?>
                        <div class="grupo-prof-card">
                            <div class="grupo-prof-header">
                                <div class="grupo-prof-badges">
                                    <span class="badge <?php echo $niv; ?>"><?php echo ucfirst($g['curso_nivel']); ?></span>
                                    <span class="badge <?php echo $est['cls']; ?>"><?php echo $est['txt']; ?></span>
                                </div>
                                <div class="grupo-prof-icon">
                                    <span class="material-symbols-rounded">school</span>
                                </div>
                            </div>

                            <div class="grupo-prof-nombre"><?php echo htmlspecialchars($g['nombre']); ?></div>
                            <div class="grupo-prof-curso"><?php echo htmlspecialchars($g['curso_nombre']); ?></div>

                            <div class="grupo-prof-meta">
                                <span>
                                    <span class="material-symbols-rounded">meeting_room</span>
                                    Aula: <?php echo $g['aula'] ? htmlspecialchars($g['aula']) : 'N/A'; ?>
                                </span>
                                <a href="#" class="link-horarios" onclick="event.preventDefault(); abrirModalHorarios(<?php echo $g['id']; ?>, '<?php echo htmlspecialchars($g['nombre']); ?>')">
                                    <span class="material-symbols-rounded">calendar_month</span>
                                    Ver horarios detallados
                                </a>
                            </div>

                            <div class="grupo-prof-stats">
                                <div class="gps-item">
                                    <span class="gps-value"><?php echo $g['cupo_actual']; ?>/<?php echo $g['cupo_maximo']; ?></span>
                                    <span class="gps-label">Cupos</span>
                                    <div class="group-bar">
                                        <div class="group-bar-fill <?php echo $bar_cls; ?>" style="width:<?php echo $pct_cupo; ?>%"></div>
                                    </div>
                                </div>
                                <div class="gps-item">
                                    <span class="gps-value <?php echo $pct_asist >= 75 ? 'positive' : ($pct_asist === null ? '' : 'negative'); ?>">
                                        <?php echo $pct_asist !== null ? $pct_asist . '%' : '—'; ?>
                                    </span>
                                    <span class="gps-label">Asistencia</span>
                                </div>
                            </div>

                            <div class="grupo-prof-acciones">
                                <a href="ver.php?id=<?php echo $g['id']; ?>" class="gpa-btn primary">
                                    <span class="material-symbols-rounded">visibility</span> Ver Grupo
                                </a>
                                <button type="button" class="gpa-btn secondary"
                                    onclick="abrirModalAsistencia(<?php echo $g['id']; ?>, '<?php echo htmlspecialchars(addslashes($g['nombre'])); ?>')">
                                    <span class="material-symbols-rounded">fact_check</span> Asistencia
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Modal Horarios -->
    <div id="modalHorarios" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h3 id="modalTitulo">Horarios del Grupo</h3>
                    <p id="modalSubtitulo">Sesiones programadas</p>
                </div>
                <button onclick="cerrarModal()" class="btn-close-modal">
                    <span class="material-symbols-rounded">close</span>
                </button>
            </div>
            <div class="modal-body" id="modalBody"></div>
            <div class="modal-footer">
                <button onclick="cerrarModal()" class="btn-secondary-modal">Cerrar</button>
            </div>
        </div>
    </div>

    <!-- Modal Registrar Asistencia -->
    <div id="modalAsistencia" class="modal-overlay">
        <div class="modal-content modal-asistencia-content">
            <div class="modal-header">
                <div>
                    <h3 id="asist_titulo">Registrar Asistencia</h3>
                    <p id="asist_subtitulo" style="color:var(--text-secondary);font-size:0.83rem;margin-top:3px"></p>
                </div>
                <button onclick="cerrarModalAsistencia()" class="btn-close-modal">
                    <span class="material-symbols-rounded">close</span>
                </button>
            </div>

            <div class="modal-body asist-modal-body" id="asist_body">
                <!-- Loading -->
                <div id="asist_loading" style="text-align:center;padding:36px 20px;color:var(--text-secondary)">
                    <span class="material-symbols-rounded" style="font-size:2.2rem;color:var(--primary-blue);display:block;margin-bottom:8px">progress_activity</span>
                    <span style="font-size:0.9rem">Cargando estudiantes...</span>
                </div>

                <!-- Formulario -->
                <form id="formAsistencia" method="POST" action="asistencia.php" style="display:none">
                    <input type="hidden" name="grupo_id" id="asist_grupo_id">
                    <input type="hidden" name="action" value="guardar">

                    <div class="asist-form-grid">
                        <div class="asist-field asist-field-full">
                            <label>Título</label>
                            <input type="text" name="titulo" id="asist_campo_titulo" class="asist-input" required>
                        </div>
                        <div class="asist-field">
                            <label>Fecha de Clase</label>
                            <input type="date" name="fecha_clase" id="asist_campo_fecha" class="asist-input" required>
                        </div>
                        <div class="asist-field">
                            <label>Hora Inicio</label>
                            <input type="time" name="hora_inicio" id="asist_campo_inicio" class="asist-input" required>
                        </div>
                        <div class="asist-field">
                            <label>Hora Fin</label>
                            <input type="time" name="hora_fin" id="asist_campo_fin" class="asist-input" required>
                        </div>
                        <div class="asist-field asist-field-full">
                            <label>Temas Tratados</label>
                            <textarea name="temas" id="asist_campo_temas" class="asist-input asist-textarea"
                                      required placeholder="Describe brevemente los temas de la clase..."></textarea>
                        </div>
                    </div>

                    <div class="asist-section-title">
                        <span class="material-symbols-rounded">group</span>
                        Asistencia de Estudiantes
                        <span id="asist_legend" style="margin-left:auto;font-size:0.7rem;font-weight:400;display:flex;gap:8px;align-items:center">
                            <span style="color:var(--primary-green);font-weight:600">P</span> Presente &nbsp;
                            <span style="color:#ef4444;font-weight:600">A</span> Ausente &nbsp;
                            <span style="color:var(--primary-orange);font-weight:600">T</span> Tardanza
                        </span>
                    </div>
                    <div id="asist_estudiantes_lista"></div>
                </form>
            </div>

            <div class="modal-footer asist-footer" id="asist_footer" style="display:none">
                <button type="button" onclick="cerrarModalAsistencia()" class="btn-secondary-modal"
                        style="width:auto;margin-top:0;padding:10px 20px">Cancelar</button>
                <button type="button" onclick="submitAsistencia()" class="gpa-btn primary"
                        style="flex:1;justify-content:center;margin:0">
                    <span class="material-symbols-rounded">save</span> Guardar Asistencia
                </button>
            </div>
        </div>
    </div>

    <style>
        /* Estilos específicos para el profesor y modal */
        .date-display {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text-secondary);
            font-size: 0.875rem;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            padding: 8px 16px;
            border-radius: 12px;
        }

        .link-horarios {
            color: var(--primary-green);
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 4px;
            margin-top: 5px;
        }

        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 3000;
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
            max-width: 420px;
            border-radius: 20px;
            padding: 25px;
            animation: slideUp 0.3s ease;
        }

        .modal-body ul {
            list-style: none;
            padding: 0;
        }

        .modal-body li {
            padding: 12px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .btn-secondary-modal {
            padding: 10px 20px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            background: none;
            color: var(--text-main);
            cursor: pointer;
            width: 100%;
            margin-top: 15px;
        }

        @keyframes slideUp {
            from { transform: translateY(20px); opacity: 0; }
            to   { transform: translateY(0);    opacity: 1; }
        }

        /* ── Modal Asistencia ──────────────────────── */
        .modal-asistencia-content { max-width: 560px; width: 95%; }

        .asist-modal-body {
            overflow-y: auto;
            max-height: 62vh;
            padding: 0 2px;
        }

        .asist-footer {
            display: flex !important;
            gap: 10px;
            align-items: center;
            padding-top: 14px;
            border-top: 1px solid var(--border-color);
            margin-top: 4px;
        }

        .asist-form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 11px;
            margin-bottom: 18px;
        }
        .asist-field-full { grid-column: 1 / -1; }

        .asist-field label {
            display: block;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: .04em;
            margin-bottom: 5px;
        }

        .asist-input {
            width: 100%;
            padding: 9px 11px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background: var(--hover-bg);
            color: var(--text-primary);
            font-size: 0.88rem;
            font-family: 'Poppins', sans-serif;
            outline: none;
            transition: border-color .2s;
        }
        .asist-input:focus { border-color: var(--primary-blue); }
        .asist-textarea    { resize: vertical; min-height: 68px; }

        .asist-section-title {
            display: flex;
            align-items: center;
            gap: 7px;
            font-size: 0.78rem;
            font-weight: 700;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: .05em;
            margin-bottom: 10px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--border-color);
        }

        .asist-estudiante {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 9px 12px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            margin-bottom: 7px;
            background: var(--hover-bg);
            gap: 10px;
        }

        .asist-est-nombre {
            font-size: 0.87rem;
            font-weight: 500;
            color: var(--text-primary);
            flex: 1;
            min-width: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .asist-est-btns { display: flex; gap: 5px; flex-shrink: 0; }

        .asist-btn {
            width: 34px;
            height: 28px;
            border-radius: 7px;
            border: 1.5px solid var(--border-color);
            background: none;
            color: var(--text-secondary);
            font-size: 0.7rem;
            font-weight: 700;
            cursor: pointer;
            font-family: 'Poppins', sans-serif;
            transition: all .15s;
        }
        .asist-btn.presente.active { background: var(--subtle-green);        color: var(--primary-green);  border-color: var(--primary-green); }
        .asist-btn.ausente.active  { background: rgba(239,68,68,.12);        color: #ef4444;               border-color: #ef4444; }
        .asist-btn.tardanza.active { background: var(--subtle-orange);       color: var(--primary-orange); border-color: var(--primary-orange); }

        @media (max-width: 500px) {
            .asist-form-grid { grid-template-columns: 1fr; }
            .asist-field-full { grid-column: 1; }
        }
    </style>

    <script>
        const horariosData = <?php echo json_encode($horarios_modal); ?>;
        const diasEsp = <?php echo json_encode($dias_esp); ?>;

        function abrirModalHorarios(grupoId, nombreGrupo) {
            const modal = document.getElementById('modalHorarios');
            const body = document.getElementById('modalBody');
            const titulo = document.getElementById('modalTitulo');

            titulo.innerText = nombreGrupo;
            let html = "";

            if (horariosData[grupoId]) {
                html = "<ul>";
                horariosData[grupoId].forEach(h => {
                    html += `<li>
                        <span class="material-symbols-rounded" style="color:var(--primary-green)">event_available</span>
                        <div>
                            <div style="font-weight:600">${diasEsp[h.dia_semana] || h.dia_semana}</div>
                            <div style="font-size:0.85rem; color:var(--text-secondary)">
                                ${h.hora_inicio.substring(0,5)} - ${h.hora_fin.substring(0,5)}
                                ${h.aula ? '· Aula: ' + h.aula : ''}
                            </div>
                        </div>
                    </li>`;
                });
                html += "</ul>";
            } else {
                html = "<p style='text-align:center; padding:20px;'>No hay horarios asignados.</p>";
            }

            body.innerHTML = html;
            modal.classList.add('active');
        }

        function cerrarModal() {
            document.getElementById('modalHorarios').classList.remove('active');
        }

        /* ── Modal Asistencia ───────────────────────────────── */
        async function abrirModalAsistencia(grupoId, nombreGrupo) {
            // Reset estado
            document.getElementById('asist_titulo').textContent     = 'Registrar Asistencia';
            document.getElementById('asist_subtitulo').textContent  = nombreGrupo;
            document.getElementById('asist_grupo_id').value         = grupoId;
            document.getElementById('asist_loading').style.display  = 'block';
            document.getElementById('formAsistencia').style.display = 'none';
            document.getElementById('asist_footer').style.display   = 'none';
            document.getElementById('asist_estudiantes_lista').innerHTML = '';

            // Valores por defecto
            const hoy   = new Date();
            const meses = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio',
                           'Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
            const yyyy  = hoy.getFullYear(), mm = String(hoy.getMonth()+1).padStart(2,'0'), dd = String(hoy.getDate()).padStart(2,'0');
            document.getElementById('asist_campo_fecha').value  = `${yyyy}-${mm}-${dd}`;
            document.getElementById('asist_campo_titulo').value = `Clase del ${hoy.getDate()} de ${meses[hoy.getMonth()]} ${yyyy}`;
            document.getElementById('asist_campo_inicio').value = '';
            document.getElementById('asist_campo_fin').value    = '';
            document.getElementById('asist_campo_temas').value  = '';

            document.getElementById('modalAsistencia').classList.add('active');

            try {
                const res  = await fetch(`/modules/documentos/institucionales/bitacoras/get_estudiantes.php?grupo_id=${grupoId}`);
                const data = await res.json();

                if (data.error || !data.estudiantes) {
                    document.getElementById('asist_loading').innerHTML =
                        '<p style="color:#ef4444;text-align:center;padding:20px">Error al cargar estudiantes.</p>';
                    return;
                }

                const lista = document.getElementById('asist_estudiantes_lista');
                if (data.estudiantes.length === 0) {
                    lista.innerHTML = '<p style="text-align:center;padding:20px;color:var(--text-secondary)">No hay estudiantes matriculados activos.</p>';
                } else {
                    lista.innerHTML = data.estudiantes.map(est => `
                        <div class="asist-estudiante">
                            <span class="asist-est-nombre">${est.nombre}</span>
                            <div class="asist-est-btns" data-est="${est.id}">
                                <button type="button" class="asist-btn presente active" data-estado="presente">P</button>
                                <button type="button" class="asist-btn ausente"  data-estado="ausente">A</button>
                                <button type="button" class="asist-btn tardanza" data-estado="tardanza">T</button>
                                <input type="hidden" name="asistencia[${est.id}]" class="asist-hidden-val" value="presente">
                            </div>
                        </div>`).join('');

                    // Event delegation por fila
                    lista.querySelectorAll('.asist-est-btns').forEach(row => {
                        row.querySelectorAll('.asist-btn').forEach(btn => {
                            btn.addEventListener('click', () => {
                                row.querySelectorAll('.asist-btn').forEach(b => b.classList.remove('active'));
                                btn.classList.add('active');
                                row.querySelector('.asist-hidden-val').value = btn.dataset.estado;
                            });
                        });
                    });
                }

                document.getElementById('asist_loading').style.display  = 'none';
                document.getElementById('formAsistencia').style.display = 'block';
                document.getElementById('asist_footer').style.display   = 'flex';

            } catch(err) {
                document.getElementById('asist_loading').innerHTML =
                    '<p style="color:#ef4444;text-align:center;padding:20px">Error de conexión.</p>';
            }
        }

        function cerrarModalAsistencia() {
            document.getElementById('modalAsistencia').classList.remove('active');
        }

        function submitAsistencia() {
            const form = document.getElementById('formAsistencia');
            if (!form.checkValidity()) { form.reportValidity(); return; }
            form.submit();
        }

        window.onclick = e => {
            if (e.target.classList.contains('modal-overlay')) {
                cerrarModal();
                cerrarModalAsistencia();
            }
        };
    </script>
</body>

</html>