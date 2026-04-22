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
            <div class="dashboard-title">
                <h1>Mis Grupos</h1>
                <p>Gestión académica de tus secciones asignadas</p>
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
                                <a href="asistencia.php?grupo=<?php echo $g['id']; ?>" class="gpa-btn secondary">
                                    <span class="material-symbols-rounded">fact_check</span> Asistencia
                                </a>
                                <a href="../documentos/institucionales/bitacoras/crear.php?grupo=<?php echo $g['id']; ?>" class="gpa-btn secondary">
                                    <span class="material-symbols-rounded">edit_note</span> Bitácora
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>

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
            from {
                transform: translateY(20px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
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

        window.onclick = e => {
            if (e.target.classList.contains('modal-overlay')) cerrarModal();
        };
    </script>
</body>

</html>