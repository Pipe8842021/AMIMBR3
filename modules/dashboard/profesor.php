<?php
/**
 * Dashboard Profesor – Amimbré
 */

require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth_check.php';

require_role('profesor');

// ─── Datos del usuario ───────────────────────────────────────────────────────
try {
    $stmt = $pdo->prepare("
        SELECT id, nombre, email, rol, estado, foto_perfil
        FROM usuarios
        WHERE id = ? AND estado = 'activo'
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        session_destroy();
        header("Location: ../../auth/login.php");
        exit;
    }
} catch (PDOException $e) {
    error_log("Error usuario: " . $e->getMessage());
    die("Error del sistema. Por favor, intenta más tarde.");
}

$flash = function_exists('get_flash_message') ? get_flash_message() : null;

// ─── Estadísticas del profesor ───────────────────────────────────────────────
try {
    $pid = $user['id'];

    // Grupos activos del profesor
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM grupos
        WHERE profesor_id = ? AND estado = 'activo'
    ");
    $stmt->execute([$pid]);
    $grupos_activos = (int)$stmt->fetchColumn();

    // Total de estudiantes en sus grupos
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT m.estudiante_id)
        FROM matriculas m
        INNER JOIN grupos g ON m.grupo_id = g.id
        WHERE g.profesor_id = ? AND m.estado = 'activa'
    ");
    $stmt->execute([$pid]);
    $total_estudiantes = (int)$stmt->fetchColumn();

    // Bitácoras registradas por este profesor
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM bitacoras
        WHERE profesor_id = ? AND estado = 'activo'
    ");
    $stmt->execute([$pid]);
    $total_bitacoras = (int)$stmt->fetchColumn();

    // Promedio de asistencia desde bitacoras_asistencias → bitacoras → grupos
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN ba.estado = 'presente' THEN 1 ELSE 0 END) AS presentes
        FROM bitacoras_asistencias ba
        INNER JOIN bitacoras b ON ba.bitacora_id = b.id
        INNER JOIN grupos g ON b.grupo_id = g.id
        WHERE g.profesor_id = ?
    ");
    $stmt->execute([$pid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $promedio_asistencia = ($row['total'] > 0)
        ? round(($row['presentes'] / $row['total']) * 100)
        : 0;

    // ─── Grupos del profesor con info completa ───────────────────────────────
    $stmt = $pdo->prepare("
        SELECT
            g.id,
            g.nombre AS grupo,
            c.nombre AS curso,
            c.nivel,
            g.horario,
            g.aula,
            g.cupo_actual,
            g.cupo_maximo,
            g.fecha_inicio,
            g.fecha_fin,
            g.estado
        FROM grupos g
        INNER JOIN cursos c ON g.curso_id = c.id
        WHERE g.profesor_id = ? AND g.estado = 'activo'
        ORDER BY g.fecha_inicio DESC
    ");
    $stmt->execute([$pid]);
    $mis_grupos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ─── Próximas clases (horarios de la semana actual) ──────────────────────
    $stmt = $pdo->prepare("
        SELECT
            h.dia_semana,
            h.hora_inicio,
            h.hora_fin,
            h.aula,
            g.nombre AS grupo,
            c.nombre AS curso
        FROM horarios h
        INNER JOIN grupos g ON h.grupo_id = g.id
        INNER JOIN cursos c ON g.curso_id = c.id
        WHERE g.profesor_id = ? AND g.estado = 'activo'
        ORDER BY FIELD(h.dia_semana,'lunes','martes','miercoles','jueves','viernes','sabado','domingo'),
                 h.hora_inicio
        LIMIT 5
    ");
    $stmt->execute([$pid]);
    $proximas_clases = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ─── Últimas bitácoras registradas ───────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT
            b.titulo,
            b.fecha_clase,
            b.temas_tratados,
            g.nombre AS grupo,
            c.nombre AS curso
        FROM bitacoras b
        INNER JOIN grupos g ON b.grupo_id = g.id
        INNER JOIN cursos c ON b.curso_id = c.id
        WHERE b.profesor_id = ?
        ORDER BY b.fecha_clase DESC
        LIMIT 4
    ");
    $stmt->execute([$pid]);
    $ultimas_bitacoras = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ─── Top 5 estudiantes por asistencia desde bitacoras_asistencias ───────
    $stmt = $pdo->prepare("
        SELECT
            u.nombre,
            COUNT(*) AS total_clases,
            SUM(CASE WHEN ba.estado = 'presente' THEN 1 ELSE 0 END) AS presentes,
            ROUND(SUM(CASE WHEN ba.estado = 'presente' THEN 1 ELSE 0 END) / COUNT(*) * 100) AS pct
        FROM bitacoras_asistencias ba
        INNER JOIN bitacoras b ON ba.bitacora_id = b.id
        INNER JOIN grupos g ON b.grupo_id = g.id
        INNER JOIN usuarios u ON ba.estudiante_id = u.id
        WHERE g.profesor_id = ?
        GROUP BY ba.estudiante_id, u.nombre
        HAVING total_clases > 0
        ORDER BY pct DESC
        LIMIT 5
    ");
    $stmt->execute([$pid]);
    $top_estudiantes = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Error estadísticas profesor: " . $e->getMessage());
    $grupos_activos = $total_estudiantes = $total_bitacoras = $promedio_asistencia = 0;
    $mis_grupos = $proximas_clases = $ultimas_bitacoras = $top_estudiantes = [];
}

// ─── Helpers ────────────────────────────────────────────────────────────────
function tiempo_transcurrido_p($fecha) {
    $ahora  = new DateTime();
    $tiempo = new DateTime($fecha);
    $d      = $ahora->diff($tiempo);
    if ($d->days > 0) return "Hace {$d->days} día" . ($d->days > 1 ? 's' : '');
    if ($d->h    > 0) return "Hace {$d->h} hora"   . ($d->h    > 1 ? 's' : '');
    if ($d->i    > 0) return "Hace {$d->i} min";
    return "Hace unos segundos";
}

$dias_es = [
    'lunes'     => 'Lunes',
    'martes'    => 'Martes',
    'miercoles' => 'Miércoles',
    'jueves'    => 'Jueves',
    'viernes'   => 'Viernes',
    'sabado'    => 'Sábado',
    'domingo'   => 'Domingo',
];

$nivel_badge = [
    'basico'       => 'badge-info',
    'básico'       => 'badge-info',
    'intermedio'   => 'badge-warning',
    'avanzado'     => 'badge-danger',
];

// Fecha en español
date_default_timezone_set('America/Bogota');
$dias_semana = ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'];
$meses       = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$fecha_hoy   = $dias_semana[date('w')] . ', ' . date('d') . ' de ' . $meses[date('n')] . ' de ' . date('Y');

// Iniciales del profesor para avatar
$iniciales = implode('', array_map(fn($p) => strtoupper($p[0]), array_slice(explode(' ', $user['nombre']), 0, 2)));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Profesor – Amimbré</title>
    <link rel="shortcut icon" href="../../assets/img/3.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,1,0">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="../../assets/css/colores.css">
    <link rel="stylesheet" href="../../assets/css/style-dashboard.css">
    <script>
        (function(){
            const t = localStorage.getItem('amimbre-theme');
            if (t === 'light') document.documentElement.setAttribute('data-theme','light');
        })();
    </script>
</head>
<body>

<?php require_once '../../includes/header.php'; ?>

<main class="main-content">

    <!-- ── Encabezado ────────────────────────────────────────────────────── -->
    <div class="dashboard-header">
        <div class="dashboard-title">
            <h1>Dashboard</h1>
            <p>Bienvenido, <strong><?php echo htmlspecialchars($user['nombre']); ?></strong></p>
        </div>
        <div class="dashboard-date">
            <span class="material-symbols-rounded">calendar_today</span>
            <?php echo $fecha_hoy; ?>
        </div>
    </div>

    <?php if ($flash): ?>
    <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>">
        <span class="material-symbols-rounded">info</span>
        <?php echo htmlspecialchars($flash['message']); ?>
    </div>
    <?php endif; ?>

    <!-- ── Tarjetas de estadísticas ─────────────────────────────────────── -->
    <div class="stats-grid">

        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-title">Mis Grupos</span>
                <div class="stat-icon courses">
                    <span class="material-symbols-rounded">groups</span>
                </div>
            </div>
            <div class="stat-value"><?php echo $grupos_activos; ?></div>
            <div class="stat-change">
                <span class="material-symbols-rounded">circle</span>
                Grupos activos
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-title">Mis Estudiantes</span>
                <div class="stat-icon students">
                    <span class="material-symbols-rounded">school</span>
                </div>
            </div>
            <div class="stat-value"><?php echo $total_estudiantes; ?></div>
            <div class="stat-change">
                <span class="material-symbols-rounded">people</span>
                Total en mis grupos
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-title">Bitácoras</span>
                <div class="stat-icon schedule">
                    <span class="material-symbols-rounded">menu_book</span>
                </div>
            </div>
            <div class="stat-value"><?php echo $total_bitacoras; ?></div>
            <div class="stat-change">
                <span class="material-symbols-rounded">edit_note</span>
                Clases registradas
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-title">Asistencia</span>
                <div class="stat-icon grades">
                    <span class="material-symbols-rounded">fact_check</span>
                </div>
            </div>
            <div class="stat-value"><?php echo $promedio_asistencia; ?>%</div>
            <div class="stat-change <?php echo $promedio_asistencia >= 75 ? 'positive' : 'negative'; ?>">
                <span class="material-symbols-rounded">
                    <?php echo $promedio_asistencia >= 75 ? 'trending_up' : 'trending_down'; ?>
                </span>
                Según bitácoras registradas
            </div>
        </div>

    </div><!-- /stats-grid -->

    <!-- ── Fila 1: Mis grupos + Perfil / Acciones rápidas ───────────────── -->
    <div class="content-grid">

        <!-- Mis grupos -->
        <div class="card">
            <div class="section-header">
                <div>
                    <h3 class="section-title">Mis Grupos</h3>
                    <p class="section-subtitle">Grupos activos asignados</p>
                </div>
                <a href="../grupos/index.php" class="btn-link">
                    Ver todos <span class="material-symbols-rounded">arrow_forward</span>
                </a>
            </div>

            <?php if (count($mis_grupos) > 0): ?>
            <div class="groups-cards">
                <?php foreach ($mis_grupos as $g):
                    $pct      = $g['cupo_maximo'] > 0 ? round(($g['cupo_actual'] / $g['cupo_maximo']) * 100) : 0;
                    $bar_cls  = $pct >= 90 ? 'bar-danger' : ($pct >= 70 ? 'bar-warning' : 'bar-success');
                    $niv_cls  = $nivel_badge[strtolower($g['nivel'])] ?? 'badge-info';
                ?>
                <div class="group-card-item">
                    <div class="group-card-header">
                        <div>
                            <span class="group-card-name"><?php echo htmlspecialchars($g['grupo']); ?></span>
                            <span class="group-card-course"><?php echo htmlspecialchars($g['curso']); ?></span>
                        </div>
                        <span class="badge <?php echo $niv_cls; ?>"><?php echo ucfirst($g['nivel']); ?></span>
                    </div>
                    <div class="group-card-meta">
                        <span><span class="material-symbols-rounded">schedule</span><?php echo htmlspecialchars($g['horario']); ?></span>
                        <?php if ($g['aula']): ?>
                        <span><span class="material-symbols-rounded">meeting_room</span><?php echo htmlspecialchars($g['aula']); ?></span>
                        <?php endif; ?>
                        <span><span class="material-symbols-rounded">event</span><?php echo date('d/m/Y', strtotime($g['fecha_inicio'])); ?></span>
                    </div>
                    <div class="group-bar-wrap" style="margin-top: 10px;">
                        <div class="group-bar">
                            <div class="group-bar-fill <?php echo $bar_cls; ?>" style="width:<?php echo $pct; ?>%"></div>
                        </div>
                        <span class="group-pct"><?php echo $g['cupo_actual']; ?>/<?php echo $g['cupo_maximo']; ?></span>
                    </div>
                    <div class="group-card-actions">
                        <a href="../bitacoras/crear.php?grupo=<?php echo $g['id']; ?>" class="group-btn">
                            <span class="material-symbols-rounded">edit_note</span> Bitácora
                        </a>
                        <a href="../grupos/ver.php?id=<?php echo $g['id']; ?>" class="group-btn">
                            <span class="material-symbols-rounded">visibility</span> Ver grupo
                        </a>
                        <a href="../bitacoras/crear.php?grupo=<?php echo $g['id']; ?>" class="group-btn">
                            <span class="material-symbols-rounded">fact_check</span> Asistencia
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <span class="material-symbols-rounded">groups</span>
                <p>No tienes grupos activos asignados</p>
            </div>
            <?php endif; ?>
        </div>

        <!-- Columna derecha: Perfil + Acciones rápidas -->
        <div style="display: flex; flex-direction: column; gap: 20px;">

            <!-- Perfil del profesor -->
            <div class="card profile-card">
                <?php if (!empty($user['foto_perfil']) && file_exists("../../assets/uploads/" . $user['foto_perfil'])): ?>
                    <img src="../../assets/uploads/<?php echo htmlspecialchars($user['foto_perfil']); ?>"
                         alt="Foto de perfil" class="profile-avatar">
                <?php else: ?>
                    <div class="profile-avatar-placeholder"><?php echo $iniciales; ?></div>
                <?php endif; ?>

                <div class="profile-name"><?php echo htmlspecialchars($user['nombre']); ?></div>
                <span class="profile-role">Profesor</span>

                <div class="profile-stats">
                    <div class="profile-stat">
                        <div class="profile-stat-value"><?php echo $grupos_activos; ?></div>
                        <div class="profile-stat-label">Grupos</div>
                    </div>
                    <div class="profile-stat">
                        <div class="profile-stat-value"><?php echo $total_estudiantes; ?></div>
                        <div class="profile-stat-label">Estudiantes</div>
                    </div>
                    <div class="profile-stat">
                        <div class="profile-stat-value"><?php echo $promedio_asistencia; ?>%</div>
                        <div class="profile-stat-label">Asistencia</div>
                    </div>
                </div>
            </div>

            <!-- Acciones rápidas -->
            <div class="card">
                <div class="section-header">
                    <div>
                        <h3 class="section-title">Acciones Rápidas</h3>
                        <p class="section-subtitle">Tareas frecuentes</p>
                    </div>
                </div>
                <div class="quick-actions-grid">

                    <a href="../documentos/institucionales/bitacoras/crear.php" class="quick-action">
                        <div class="quick-action-icon schedule">
                            <span class="material-symbols-rounded">edit_note</span>
                        </div>
                        <div class="quick-action-content">
                            <div class="quick-action-title">Nueva Bitácora</div>
                            <div class="quick-action-desc">Registrar clase</div>
                        </div>
                        <span class="material-symbols-rounded arrow">arrow_forward</span>
                    </a>

                    <a href="../grupos/index.php" class="quick-action">
                        <div class="quick-action-icon attendance">
                            <span class="material-symbols-rounded">fact_check</span>
                        </div>
                        <div class="quick-action-content">
                            <div class="quick-action-title">Ver Asistencias</div>
                            <div class="quick-action-desc">Desde bitácoras</div>
                        </div>
                        <span class="material-symbols-rounded arrow">arrow_forward</span>
                    </a>

                    <a href="../grupos/index.php" class="quick-action">
                        <div class="quick-action-icon courses">
                            <span class="material-symbols-rounded">groups</span>
                        </div>
                        <div class="quick-action-content">
                            <div class="quick-action-title">Mis Grupos</div>
                            <div class="quick-action-desc"><?php echo $grupos_activos; ?> activos</div>
                        </div>
                        <span class="material-symbols-rounded arrow">arrow_forward</span>
                    </a>

                </div>
            </div>

        </div>

    </div><!-- /content-grid -->

    <!-- ── Fila 2: Próximas clases + Top estudiantes ─────────────────────── -->
    <div class="content-grid content-grid--halves">

        <!-- Próximas clases (horarios) -->
        <div class="card">
            <div class="section-header">
                <div>
                    <h3 class="section-title">Horario de Clases</h3>
                    <p class="section-subtitle">Sesiones programadas por semana</p>
                </div>
                <a href="../horarios/index.php" class="btn-link">
                    Ver todos <span class="material-symbols-rounded">arrow_forward</span>
                </a>
            </div>

            <?php if (count($proximas_clases) > 0): ?>
            <div class="events-list">
                <?php foreach ($proximas_clases as $clase): ?>
                <div class="event-item">
                    <div class="event-date-box">
                        <div class="day"><?php echo strtoupper(substr($dias_es[$clase['dia_semana']] ?? $clase['dia_semana'], 0, 3)); ?></div>
                        <div class="month"><?php echo date('H:i', strtotime($clase['hora_inicio'])); ?></div>
                    </div>
                    <div class="event-content">
                        <div class="event-title"><?php echo htmlspecialchars($clase['curso']); ?></div>
                        <div class="event-meta">
                            <span class="material-symbols-rounded">groups</span>
                            <?php echo htmlspecialchars($clase['grupo']); ?>
                            &nbsp;·&nbsp;
                            <span class="material-symbols-rounded">schedule</span>
                            <?php echo date('H:i', strtotime($clase['hora_inicio'])); ?> – <?php echo date('H:i', strtotime($clase['hora_fin'])); ?>
                            <?php if ($clase['aula']): ?>
                            &nbsp;·&nbsp;
                            <span class="material-symbols-rounded">meeting_room</span>
                            <?php echo htmlspecialchars($clase['aula']); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <span class="material-symbols-rounded">event_busy</span>
                <p>No hay horarios registrados aún</p>
            </div>
            <?php endif; ?>
        </div>

        <!-- Top estudiantes por asistencia -->
        <div class="card">
            <div class="section-header">
                <div>
                    <h3 class="section-title">Top Estudiantes</h3>
                    <p class="section-subtitle">Mayor asistencia en tus grupos</p>
                </div>
            </div>

            <?php if (count($top_estudiantes) > 0): ?>
            <div class="top-students-list">
                <?php foreach ($top_estudiantes as $i => $est):
                    $rank = $i + 1;
                    $rank_cls = $rank <= 3 ? "rank-$rank" : 'rank-default';
                    $bar_color = $est['pct'] >= 80 ? 'bar-success' : ($est['pct'] >= 60 ? 'bar-warning' : 'bar-danger');
                ?>
                <div class="top-student-item">
                    <div class="top-student-rank <?php echo $rank_cls; ?>"><?php echo $rank; ?></div>
                    <div class="top-student-info">
                        <span class="top-student-name"><?php echo htmlspecialchars($est['nombre']); ?></span>
                        <div class="group-bar-wrap" style="margin-top: 5px;">
                            <div class="group-bar">
                                <div class="group-bar-fill <?php echo $bar_color; ?>" style="width:<?php echo $est['pct']; ?>%"></div>
                            </div>
                            <span class="group-pct"><?php echo $est['pct']; ?>%</span>
                        </div>
                    </div>
                    <span class="top-student-classes"><?php echo $est['presentes']; ?>/<?php echo $est['total_clases']; ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <span class="material-symbols-rounded">leaderboard</span>
                <p>Sin registros de asistencia aún</p>
            </div>
            <?php endif; ?>
        </div>

    </div><!-- /content-grid halves -->

    <!-- ── Fila 3: Últimas bitácoras ─────────────────────────────────────── -->
    <div class="content-grid content-grid--halves">

        <div class="card" style="grid-column: 1 / -1;">
            <div class="section-header">
                <div>
                    <h3 class="section-title">Últimas Bitácoras</h3>
                    <p class="section-subtitle">Clases registradas recientemente</p>
                </div>
                <a href="../documentos/institucionales/index.php?search=&tipo=bitacora&categoria=todas" class="btn-link">
                    Ver todas <span class="material-symbols-rounded">arrow_forward</span>
                </a>
            </div>

            <?php if (count($ultimas_bitacoras) > 0): ?>
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Título</th>
                            <th>Curso</th>
                            <th>Grupo</th>
                            <th>Temas tratados</th>
                            <th>Fecha</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ultimas_bitacoras as $b): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($b['titulo']); ?></strong></td>
                            <td><?php echo htmlspecialchars($b['curso']); ?></td>
                            <td><?php echo htmlspecialchars($b['grupo']); ?></td>
                            <td style="max-width: 260px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                <?php echo htmlspecialchars($b['temas_tratados']); ?>
                            </td>
                            <td><?php echo date('d/m/Y', strtotime($b['fecha_clase'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <span class="material-symbols-rounded">menu_book</span>
                <p>No hay bitácoras registradas aún</p>
            </div>
            <?php endif; ?>
        </div>

    </div>

</main>

</body>
</html>