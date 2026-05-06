<?php
/**
 * Dashboard Estudiante – Amimbré
 */

require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth_check.php';

require_role('estudiante');

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

// ─── Estadísticas del estudiante ─────────────────────────────────────────────
try {
    $eid = $user['id'];

    // Matrículas activas
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM matriculas
        WHERE estudiante_id = ? AND estado = 'activa'
    ");
    $stmt->execute([$eid]);
    $cursos_activos = (int)$stmt->fetchColumn();

    // Notificaciones no leídas
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM notificaciones
        WHERE usuario_id = ? AND leida = 0
    ");
    $stmt->execute([$eid]);
    $notif_no_leidas = (int)$stmt->fetchColumn();

    // Asistencia propia desde bitacoras_asistencias
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN ba.estado = 'presente' THEN 1 ELSE 0 END) AS presentes
        FROM bitacoras_asistencias ba
        WHERE ba.estudiante_id = ?
    ");
    $stmt->execute([$eid]);
    $row_asi = $stmt->fetch(PDO::FETCH_ASSOC);
    $asistencia_pct = ($row_asi['total'] > 0)
        ? round(($row_asi['presentes'] / $row_asi['total']) * 100)
        : 0;

    // ─── Mis grupos / cursos activos con detalle ──────────────────────────
    $stmt = $pdo->prepare("
        SELECT
            m.id AS matricula_id,
            g.id AS grupo_id,
            g.nombre AS grupo,
            g.horario,
            g.aula,
            c.nombre AS curso,
            c.nivel,
            u.nombre AS profesor,
            m.fecha_matricula,
            g.fecha_inicio,
            g.fecha_fin
        FROM matriculas m
        INNER JOIN grupos g  ON m.grupo_id   = g.id
        INNER JOIN cursos c  ON g.curso_id   = c.id
        INNER JOIN usuarios u ON g.profesor_id = u.id
        WHERE m.estudiante_id = ? AND m.estado = 'activa'
        ORDER BY m.fecha_matricula DESC
    ");
    $stmt->execute([$eid]);
    $mis_cursos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ─── Calificaciones finales del estudiante (desde certificados) ──────
    $stmt = $pdo->prepare("
        SELECT
            cc.calificacion_final,
            cc.nivel_aprobado,
            cc.fecha_aprobacion,
            cc.estado,
            c.nombre AS curso,
            g.nombre AS grupo
        FROM calificaciones_certificados cc
        INNER JOIN cursos c ON cc.curso_id = c.id
        INNER JOIN grupos g ON cc.grupo_id = g.id
        WHERE cc.estudiante_id = ?
        ORDER BY cc.fecha_aprobacion DESC
        LIMIT 5
    ");
    $stmt->execute([$eid]);
    $calificaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Promedio general desde calificaciones_certificados
    $stmt = $pdo->prepare("
        SELECT ROUND(AVG(calificacion_final), 1)
        FROM calificaciones_certificados
        WHERE estudiante_id = ?
    ");
    $stmt->execute([$eid]);
    $promedio_general = $stmt->fetchColumn() ?: null;

    // ─── Certificados obtenidos ───────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT
            cc.codigo_certificado,
            cc.nivel_aprobado,
            cc.calificacion_final,
            cc.fecha_aprobacion,
            c.nombre AS curso
        FROM calificaciones_certificados cc
        INNER JOIN cursos c ON cc.curso_id = c.id
        WHERE cc.estudiante_id = ? AND cc.estado = 'aprobado'
        ORDER BY cc.fecha_aprobacion DESC
        LIMIT 3
    ");
    $stmt->execute([$eid]);
    $certificados = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ─── Próximas clases (horarios de sus grupos) ─────────────────────────
    $stmt = $pdo->prepare("
        SELECT
            h.dia_semana,
            h.hora_inicio,
            h.hora_fin,
            h.aula,
            g.nombre AS grupo,
            c.nombre AS curso,
            u.nombre AS profesor
        FROM horarios h
        INNER JOIN grupos g    ON h.grupo_id    = g.id
        INNER JOIN cursos c    ON g.curso_id    = c.id
        INNER JOIN usuarios u  ON g.profesor_id = u.id
        INNER JOIN matriculas m ON m.grupo_id   = g.id
        WHERE m.estudiante_id = ? AND m.estado = 'activa' AND g.estado = 'activo'
        ORDER BY FIELD(h.dia_semana,'lunes','martes','miercoles','jueves','viernes','sabado','domingo'),
                 h.hora_inicio
        LIMIT 5
    ");
    $stmt->execute([$eid]);
    $proximas_clases = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ─── Notificaciones recientes ─────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT titulo, mensaje, tipo, prioridad, leida, fecha_creacion
        FROM notificaciones
        WHERE usuario_id = ?
        ORDER BY fecha_creacion DESC
        LIMIT 5
    ");
    $stmt->execute([$eid]);
    $notificaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ─── Mi asistencia por grupo ──────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT
            c.nombre AS curso,
            g.nombre AS grupo,
            COUNT(*) AS total,
            SUM(CASE WHEN ba.estado = 'presente' THEN 1 ELSE 0 END) AS presentes,
            ROUND(SUM(CASE WHEN ba.estado = 'presente' THEN 1 ELSE 0 END) / COUNT(*) * 100) AS pct
        FROM bitacoras_asistencias ba
        INNER JOIN bitacoras b  ON ba.bitacora_id = b.id
        INNER JOIN grupos g     ON b.grupo_id     = g.id
        INNER JOIN cursos c     ON g.curso_id     = c.id
        WHERE ba.estudiante_id = ?
        GROUP BY g.id, c.nombre, g.nombre
        HAVING total > 0
    ");
    $stmt->execute([$eid]);
    $asistencia_por_grupo = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Error estadísticas estudiante: " . $e->getMessage());
    $cursos_activos = $notif_no_leidas = $asistencia_pct = 0;
    $promedio_general = null;
    $mis_cursos = $calificaciones = $certificados = $proximas_clases = $notificaciones = $asistencia_por_grupo = [];
}

// ─── Helpers ────────────────────────────────────────────────────────────────
$dias_es = [
    'lunes'     => 'Lun', 'martes'    => 'Mar', 'miercoles' => 'Mié',
    'jueves'    => 'Jue', 'viernes'   => 'Vie', 'sabado'    => 'Sáb', 'domingo' => 'Dom',
];

$nivel_badge = [
    'basico'     => 'badge-info',
    'básico'     => 'badge-info',
    'intermedio' => 'badge-warning',
    'avanzado'   => 'badge-danger',
];

$estado_cert_label = [
    'aprobado'   => ['txt' => 'Aprobado',    'cls' => 'badge-success'],
    'reprobado'  => ['txt' => 'Reprobado',   'cls' => 'badge-danger'],
    'en_proceso' => ['txt' => 'En proceso',  'cls' => 'badge-warning'],
];

$notif_icono = [
    'sistema'       => ['icono' => 'settings',      'clase' => 'info'],
    'preinscripcion'=> ['icono' => 'person_add',     'clase' => 'success'],
    'evento'        => ['icono' => 'event',          'clase' => 'warning'],
    'general'       => ['icono' => 'notifications',  'clase' => 'info'],
];

function nota_clase($nota) {
    if ($nota === null) return '';
    if ($nota >= 4.5) return 'positive';
    if ($nota >= 3.0) return '';
    return 'negative';
}

function tiempo_transcurrido_e($fecha) {
    $d = (new DateTime())->diff(new DateTime($fecha));
    if ($d->days > 0) return "Hace {$d->days} día" . ($d->days > 1 ? 's' : '');
    if ($d->h    > 0) return "Hace {$d->h} hora"   . ($d->h    > 1 ? 's' : '');
    if ($d->i    > 0) return "Hace {$d->i} min";
    return "Hace unos segundos";
}

// Fecha en español
date_default_timezone_set('America/Bogota');
$dias_semana = ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'];
$meses       = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$fecha_hoy   = $dias_semana[date('w')] . ', ' . date('d') . ' de ' . $meses[date('n')] . ' de ' . date('Y');

$iniciales = implode('', array_map(fn($p) => strtoupper($p[0]), array_slice(explode(' ', $user['nombre']), 0, 2)));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mi menú – Amimbré</title>
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
            <h1>Menú principal</h1>
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
                <span class="stat-title">Mis Cursos</span>
                <div class="stat-icon courses">
                    <span class="material-symbols-rounded">menu_book</span>
                </div>
            </div>
            <div class="stat-value"><?php echo $cursos_activos; ?></div>
            <div class="stat-change">
                <span class="material-symbols-rounded">check_circle</span>
                Matrículas activas
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-title">Mi Asistencia</span>
                <div class="stat-icon grades">
                    <span class="material-symbols-rounded">fact_check</span>
                </div>
            </div>
            <div class="stat-value"><?php echo $asistencia_pct; ?>%</div>
            <div class="stat-change <?php echo $asistencia_pct >= 75 ? 'positive' : 'negative'; ?>">
                <span class="material-symbols-rounded">
                    <?php echo $asistencia_pct >= 75 ? 'trending_up' : 'trending_down'; ?>
                </span>
                <?php echo $asistencia_pct >= 75 ? 'Buen rendimiento' : 'Requiere atención'; ?>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-title">Promedio General</span>
                <div class="stat-icon enrollment">
                    <span class="material-symbols-rounded">grade</span>
                </div>
            </div>
            <div class="stat-value">
                <?php echo $promedio_general !== null ? number_format($promedio_general, 1) : '—'; ?>
            </div>
            <div class="stat-change <?php echo $promedio_general !== null ? nota_clase($promedio_general) : ''; ?>">
                <span class="material-symbols-rounded">
                    <?php echo ($promedio_general >= 3.0 || $promedio_general === null) ? 'check_circle' : 'warning'; ?>
                </span>
                <?php echo $promedio_general !== null ? 'Sobre ' . count($calificaciones) . ' curso' . (count($calificaciones) > 1 ? 's' : '') : 'Sin calificaciones aún'; ?>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-title">Notificaciones</span>
                <div class="stat-icon <?php echo $notif_no_leidas > 0 ? 'income' : 'students'; ?>">
                    <span class="material-symbols-rounded">notifications</span>
                </div>
            </div>
            <div class="stat-value"><?php echo $notif_no_leidas; ?></div>
            <div class="stat-change <?php echo $notif_no_leidas > 0 ? 'pending' : 'positive'; ?>">
                <span class="material-symbols-rounded">
                    <?php echo $notif_no_leidas > 0 ? 'mark_email_unread' : 'done_all'; ?>
                </span>
                <?php echo $notif_no_leidas > 0 ? 'Sin leer' : 'Todo al día'; ?>
            </div>
        </div>

    </div><!-- /stats-grid -->

    <!-- ── Fila 1: Mis cursos + Perfil/Acciones ──────────────────────────── -->
    <div class="content-grid">

        <!-- Mis cursos -->
        <div class="card">
            <div class="section-header">
                <div>
                    <h3 class="section-title">Mis Cursos</h3>
                    <p class="section-subtitle">Matrículas activas</p>
                </div>
                <a href="../cursos/index.php" class="btn-link">
                    Ver todas <span class="material-symbols-rounded">arrow_forward</span>
                </a>
            </div>

            <?php if (count($mis_cursos) > 0): ?>
            <div class="groups-cards">
                <?php foreach ($mis_cursos as $mc):
                    $niv_cls = $nivel_badge[strtolower($mc['nivel'])] ?? 'badge-info';
                ?>
                <div class="group-card-item">
                    <div class="group-card-header">
                        <div>
                            <span class="group-card-name"><?php echo htmlspecialchars($mc['curso']); ?></span>
                            <span class="group-card-course">Grupo: <?php echo htmlspecialchars($mc['grupo']); ?></span>
                        </div>
                        <span class="badge <?php echo $niv_cls; ?>"><?php echo ucfirst($mc['nivel']); ?></span>
                    </div>
                    <div class="group-card-meta">
                        <span>
                            <span class="material-symbols-rounded">person_play</span>
                            <?php echo htmlspecialchars($mc['profesor']); ?>
                        </span>
                        <span>
                            <span class="material-symbols-rounded">schedule</span>
                            <?php echo htmlspecialchars($mc['horario']); ?>
                        </span>
                        <?php if ($mc['aula']): ?>
                        <span>
                            <span class="material-symbols-rounded">meeting_room</span>
                            <?php echo htmlspecialchars($mc['aula']); ?>
                        </span>
                        <?php endif; ?>
                        <span>
                            <span class="material-symbols-rounded">event</span>
                            Desde <?php echo date('d/m/Y', strtotime($mc['fecha_inicio'])); ?>
                        </span>
                    </div>
                    <div class="group-card-actions">
                        <a href="../horarios/index.php?grupo=<?php echo $mc['grupo_id']; ?>" class="group-btn">
                            <span class="material-symbols-rounded">schedule</span> Horario
                        </a>
                        <a href="../documentos/institucionales/index.php?search=&tipo=bitacora&categoria=todas" class="group-btn">
                            <span class="material-symbols-rounded">folder_open</span> Documentos
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <span class="material-symbols-rounded">menu_book</span>
                <p>No tienes matrículas activas en este momento</p>
            </div>
            <?php endif; ?>
        </div>

        <!-- Columna derecha: Perfil + Acciones rápidas -->
        <div style="display: flex; flex-direction: column; gap: 20px;">

            <!-- Perfil del estudiante -->
            <div class="card profile-card">
                <?php
                $foto_path = !empty($user['foto_perfil'])
                    ? "../../assets/img/avatars/" . $user['foto_perfil']
                    : '';
                $foto_existe = $foto_path && file_exists($foto_path);
                ?>
                <?php if ($foto_existe): ?>
                    <img src="<?= htmlspecialchars($foto_path) ?>"
                        alt="Foto de perfil"
                        class="profile-avatar"
                        onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                    <div class="profile-avatar-placeholder" style="display:none;"><?= $iniciales ?></div>
                <?php else: ?>
                    <div class="profile-avatar-placeholder"><?= $iniciales ?></div>
                <?php endif; ?>

                <div class="profile-name"><?php echo htmlspecialchars($user['nombre']); ?></div>
                <span class="profile-role">Estudiante</span>

                <div class="profile-stats">
                    <div class="profile-stat">
                        <div class="profile-stat-value"><?php echo $cursos_activos; ?></div>
                        <div class="profile-stat-label">Cursos</div>
                    </div>
                    <div class="profile-stat">
                        <div class="profile-stat-value"><?php echo $asistencia_pct; ?>%</div>
                        <div class="profile-stat-label">Asistencia</div>
                    </div>
                    <div class="profile-stat">
                        <div class="profile-stat-value"><?php echo count($certificados); ?></div>
                        <div class="profile-stat-label">Certificados</div>
                    </div>
                </div>
            </div>
        </div>
    </div><!-- /content-grid -->

    <!-- ── Fila 2: Horario + Notificaciones ─────────────────────────────── -->
    <div class="content-grid content-grid--halves">

        <!-- Próximas clases -->
        <div class="card">
            <div class="section-header">
                <div>
                    <h3 class="section-title">Mis Clases</h3>
                    <p class="section-subtitle">Horario semanal</p>
                </div>
                <a href="../horarios/index.php" class="btn-link">
                    Ver todo <span class="material-symbols-rounded">arrow_forward</span>
                </a>
            </div>

            <?php if (count($proximas_clases) > 0): ?>
            <div class="events-list">
                <?php foreach ($proximas_clases as $clase): ?>
                <div class="event-item">
                    <div class="event-date-box">
                        <div class="day"><?php echo $dias_es[$clase['dia_semana']] ?? strtoupper(substr($clase['dia_semana'], 0, 3)); ?></div>
                        <div class="month"><?php echo date('H:i', strtotime($clase['hora_inicio'])); ?></div>
                    </div>
                    <div class="event-content">
                        <div class="event-title"><?php echo htmlspecialchars($clase['curso']); ?></div>
                        <div class="event-meta">
                            <span class="material-symbols-rounded">person_play</span>
                            <?php echo htmlspecialchars($clase['profesor']); ?>
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

        <!-- Notificaciones -->
        <div class="card">
            <div class="section-header">
                <div>
                    <h3 class="section-title">Notificaciones</h3>
                    <p class="section-subtitle">
                        <?php echo $notif_no_leidas > 0 ? "$notif_no_leidas sin leer" : "Todas leídas"; ?>
                    </p>
                </div>
                <a href="../notificaciones/index.php" class="btn-link">
                    Ver todas <span class="material-symbols-rounded">arrow_forward</span>
                </a>
            </div>

            <?php if (count($notificaciones) > 0): ?>
            <div class="activity-list">
                <?php foreach ($notificaciones as $n):
                    $ni = $notif_icono[$n['tipo']] ?? ['icono' => 'notifications', 'clase' => 'info'];
                    $prioridad_cls = $n['prioridad'] === 'alta' ? 'danger' : ($n['prioridad'] === 'normal' ? 'info' : 'success');
                ?>
                <div class="activity-item <?php echo !$n['leida'] ? 'notif-unread' : ''; ?>">
                    <div class="activity-icon <?php echo $ni['clase']; ?>">
                        <span class="material-symbols-rounded"><?php echo $ni['icono']; ?></span>
                    </div>
                    <div class="activity-content">
                        <div class="activity-title"><?php echo htmlspecialchars($n['titulo']); ?></div>
                        <div class="activity-time"><?php echo tiempo_transcurrido_e($n['fecha_creacion']); ?></div>
                    </div>
                    <?php if (!$n['leida']): ?>
                    <span class="activity-badge <?php echo $prioridad_cls; ?>">Nueva</span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <span class="material-symbols-rounded">notifications_off</span>
                <p>Sin notificaciones recientes</p>
            </div>
            <?php endif; ?>
        </div>

    </div><!-- /content-grid halves -->

    <!-- ── Fila 3: Calificaciones + Asistencia por grupo ────────────────── -->
    <div class="content-grid content-grid--halves">

        <!-- Calificaciones finales -->
        <div class="card">
            <div class="section-header">
                <div>
                    <h3 class="section-title">Mis Calificaciones</h3>
                    <p class="section-subtitle">Notas finales por curso</p>
                </div>
            </div>

            <?php if (count($calificaciones) > 0): ?>
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Curso</th>
                            <th>Grupo</th>
                            <th>Nivel</th>
                            <th>Nota Final</th>
                            <th>Estado</th>
                            <th>Fecha</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($calificaciones as $cal):
                            $est_b = $estado_cert_label[$cal['estado']] ?? ['txt' => ucfirst($cal['estado']), 'cls' => 'badge-info'];
                            $niv_b = $nivel_badge[strtolower($cal['nivel_aprobado'])] ?? 'badge-info';
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($cal['curso']); ?></td>
                            <td><?php echo htmlspecialchars($cal['grupo']); ?></td>
                            <td><span class="badge <?php echo $niv_b; ?>"><?php echo ucfirst($cal['nivel_aprobado']); ?></span></td>
                            <td>
                                <strong class="stat-change <?php echo nota_clase($cal['calificacion_final']); ?>" style="font-size:0.95rem;">
                                    <?php echo number_format($cal['calificacion_final'], 1); ?>
                                </strong>
                            </td>
                            <td><span class="badge <?php echo $est_b['cls']; ?>"><?php echo $est_b['txt']; ?></span></td>
                            <td><?php echo date('d/m/Y', strtotime($cal['fecha_aprobacion'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <span class="material-symbols-rounded">grade</span>
                <p>Sin calificaciones finales registradas aún</p>
            </div>
            <?php endif; ?>
        </div>

        <!-- Asistencia por grupo -->
        <div class="card">
            <div class="section-header">
                <div>
                    <h3 class="section-title">Mi Asistencia</h3>
                    <p class="section-subtitle">Por grupo · Desde bitácoras</p>
                </div>
            </div>

            <?php if (count($asistencia_por_grupo) > 0): ?>
            <div class="progress-list">
                <?php foreach ($asistencia_por_grupo as $ag):
                    $pct = min(100, (int)$ag['pct']);
                    $fill = $pct >= 75 ? '' : ($pct >= 50 ? 'medium' : 'low');
                ?>
                <div class="progress-item">
                    <div class="progress-header">
                        <span class="progress-name"><?php echo htmlspecialchars($ag['curso']); ?></span>
                        <span class="progress-pct"><?php echo $ag['presentes']; ?>/<?php echo $ag['total']; ?> clases</span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-bar-fill <?php echo $fill; ?>" style="width:<?php echo $pct; ?>%"></div>
                    </div>
                    <div style="font-size:0.78rem; color:var(--text-secondary); margin-top:3px;">
                        <?php echo $pct; ?>% de asistencia
                        <?php if ($pct < 75): ?>
                        · <span style="color:var(--primary-orange);">Requiere atención</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <span class="material-symbols-rounded">fact_check</span>
                <p>Sin registros de asistencia aún</p>
            </div>
            <?php endif; ?>
        </div>

    </div><!-- /content-grid halves -->

    <!-- ── Fila 4: Certificados obtenidos ───────────────────────────────── -->
    <?php if (count($certificados) > 0): ?>
    <div class="card" style="margin-bottom: 24px;">
        <div class="section-header">
            <div>
                <h3 class="section-title">Mis Certificados</h3>
                <p class="section-subtitle">Niveles aprobados</p>
            </div>
            <a href="../documentos/institucionales/index.php" class="btn-link">
                Ver todos <span class="material-symbols-rounded">arrow_forward</span>
            </a>
        </div>
        <div class="certificados-grid">
            <?php foreach ($certificados as $cert):
                $niv_cls = $nivel_badge[strtolower($cert['nivel_aprobado'])] ?? 'badge-info';
            ?>
            <div class="cert-card">
                <div class="cert-icon">
                    <span class="material-symbols-rounded">workspace_premium</span>
                </div>
                <div class="cert-info">
                    <span class="cert-curso"><?php echo htmlspecialchars($cert['curso']); ?></span>
                    <span class="badge <?php echo $niv_cls; ?>" style="align-self:flex-start;">
                        <?php echo ucfirst($cert['nivel_aprobado']); ?>
                    </span>
                    <span class="cert-meta">
                        Nota: <strong><?php echo number_format($cert['calificacion_final'], 1); ?></strong>
                        &nbsp;·&nbsp;
                        <?php echo date('d/m/Y', strtotime($cert['fecha_aprobacion'])); ?>
                    </span>
                    <span class="cert-codigo"><?php echo htmlspecialchars($cert['codigo_certificado']); ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</main>

</body>
</html>