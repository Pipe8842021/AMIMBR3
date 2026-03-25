<?php
/**
 * Dashboard Administrador - Amimbré
 * Vista completa del panel de administración
 */

require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth_check.php';

require_role('admin');

// Obtener datos del usuario actual
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
        header("Location: ../../auth/login.php?error=usuario_no_encontrado");
        exit;
    }
} catch (PDOException $e) {
    error_log("Error al obtener datos de usuario: " . $e->getMessage());
    die("Error del sistema. Por favor, intenta más tarde.");
}

// Mensaje flash
$flash = null;
if (function_exists('get_flash_message')) {
    $flash = get_flash_message();
}

// ─── Estadísticas principales ───────────────────────────────────────────────
try {
    // Estudiantes activos
    $stmt = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE rol='estudiante' AND estado='activo'");
    $estudiantes_activos = (int)$stmt->fetchColumn();

    // Estudiantes mes anterior (para calcular cambio)
    $stmt = $pdo->query("
        SELECT COUNT(*) FROM usuarios 
        WHERE rol='estudiante' AND estado='activo'
          AND fecha_registro < DATE_SUB(NOW(), INTERVAL 1 MONTH)
    ");
    $estudiantes_mes_pasado = (int)$stmt->fetchColumn();
    $cambio_estudiantes = $estudiantes_mes_pasado > 0
        ? round((($estudiantes_activos - $estudiantes_mes_pasado) / $estudiantes_mes_pasado) * 100)
        : 0;

    // Cursos y grupos activos
    $stmt = $pdo->query("SELECT COUNT(*) FROM cursos WHERE estado='activo'");
    $cursos_activos = (int)$stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) FROM grupos WHERE estado='activo'");
    $grupos_activos = (int)$stmt->fetchColumn();

    // Profesores activos
    $stmt = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE rol='profesor' AND estado='activo'");
    $profesores_activos = (int)$stmt->fetchColumn();

    // Prematrículas pendientes
    $stmt = $pdo->query("SELECT COUNT(*) FROM preinscripciones WHERE estado='pendiente'");
    $prematriculas_pendientes = (int)$stmt->fetchColumn();

    // Ingresos del mes actual (pagos confirmados)
    $stmt = $pdo->query("
        SELECT COALESCE(SUM(monto), 0) FROM pagos 
        WHERE estado='pagado' AND MONTH(fecha_pago)=MONTH(NOW()) AND YEAR(fecha_pago)=YEAR(NOW())
    ");
    $ingresos_mes = (float)$stmt->fetchColumn();

    // Pagos vencidos
    $stmt = $pdo->query("SELECT COUNT(*) FROM pagos WHERE estado='vencido'");
    $pagos_vencidos = (int)$stmt->fetchColumn();

    // Matrículas activas
    $stmt = $pdo->query("SELECT COUNT(*) FROM matriculas WHERE estado='activa'");
    $matriculas_activas = (int)$stmt->fetchColumn();

    // ─── Actividad reciente (logs_actividad + logs_acceso combinados) ────────
    $stmt = $pdo->query("
        SELECT 
            la.fecha AS fecha,
            u.nombre AS usuario_nombre,
            u.rol,
            la.accion,
            la.detalles
        FROM logs_actividad la
        LEFT JOIN usuarios u ON la.usuario_id = u.id
        ORDER BY la.fecha DESC
        LIMIT 6
    ");
    $actividades = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ─── Últimas prematrículas ───────────────────────────────────────────────
    $stmt = $pdo->query("
        SELECT nombres_apellidos, programa, taller, estado, fecha_preinscripcion
        FROM preinscripciones
        ORDER BY fecha_preinscripcion DESC
        LIMIT 5
    ");
    $ultimas_prematriculas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ─── Grupos con mayor ocupación ─────────────────────────────────────────
    $stmt = $pdo->query("
        SELECT 
            g.nombre AS grupo,
            c.nombre AS curso,
            u.nombre AS profesor,
            g.cupo_actual,
            g.cupo_maximo,
            ROUND((g.cupo_actual / g.cupo_maximo) * 100) AS ocupacion_pct
        FROM grupos g
        INNER JOIN cursos c ON g.curso_id = c.id
        INNER JOIN usuarios u ON g.profesor_id = u.id
        WHERE g.estado = 'activo'
        ORDER BY ocupacion_pct DESC
        LIMIT 4
    ");
    $grupos_ocupacion = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ─── Nuevos estudiantes por mes (últimos 6 meses) ────────────────────────
    $stmt = $pdo->query("
        SELECT 
            DATE_FORMAT(fecha_registro, '%b') AS mes_label,
            COUNT(*) AS total
        FROM usuarios
        WHERE rol = 'estudiante'
          AND fecha_registro >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY YEAR(fecha_registro), MONTH(fecha_registro)
        ORDER BY fecha_registro ASC
    ");
    $nuevos_por_mes = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Error obteniendo estadísticas: " . $e->getMessage());
    $estudiantes_activos = $cambio_estudiantes = $cursos_activos = $grupos_activos = 0;
    $profesores_activos = $prematriculas_pendientes = $pagos_vencidos = $matriculas_activas = 0;
    $ingresos_mes = 0;
    $actividades = $ultimas_prematriculas = $grupos_ocupacion = $nuevos_por_mes = [];
}

// ─── Helpers ────────────────────────────────────────────────────────────────
function tiempo_transcurrido($fecha) {
    $ahora  = new DateTime();
    $tiempo = new DateTime($fecha);
    $d      = $ahora->diff($tiempo);
    if ($d->days > 0)  return "Hace {$d->days} día"  . ($d->days  > 1 ? 's' : '');
    if ($d->h    > 0)  return "Hace {$d->h} hora"    . ($d->h     > 1 ? 's' : '');
    if ($d->i    > 0)  return "Hace {$d->i} minuto"  . ($d->i     > 1 ? 's' : '');
    return "Hace unos segundos";
}

function accion_label($accion) {
    $mapa = [
        'preinscripcion_creada'  => ['label' => 'Prematrícula',  'icono' => 'person_add',       'clase' => 'success'],
        'login'                  => ['label' => 'Acceso',         'icono' => 'login',             'clase' => 'info'],
        'usuario_creado'         => ['label' => 'Usuario nuevo',  'icono' => 'group_add',         'clase' => 'warning'],
        'matricula_creada'       => ['label' => 'Matrícula',      'icono' => 'school',            'clase' => 'success'],
        'pago_registrado'        => ['label' => 'Pago',           'icono' => 'payments',          'clase' => 'info'],
        'preinscripcion_aprobada'=> ['label' => 'Aprobada',       'icono' => 'check_circle',      'clase' => 'success'],
        'preinscripcion_rechazada'=>['label' => 'Rechazada',      'icono' => 'cancel',            'clase' => 'danger'],
    ];
    return $mapa[$accion] ?? ['label' => 'Actividad', 'icono' => 'notifications', 'clase' => 'info'];
}

function estado_badge($estado) {
    $mapa = [
        'pendiente'   => ['txt' => 'Pendiente',   'cls' => 'badge-pending'],
        'matriculado' => ['txt' => 'Matriculado',  'cls' => 'badge-success'],
        'rechazado'   => ['txt' => 'Rechazado',    'cls' => 'badge-danger'],
        'aprobado'    => ['txt' => 'Aprobado',     'cls' => 'badge-success'],
    ];
    return $mapa[$estado] ?? ['txt' => ucfirst($estado), 'cls' => 'badge-info'];
}

// Fecha en español
date_default_timezone_set('America/Bogota');
$dias   = ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'];
$meses  = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$fecha_hoy = $dias[date('w')] . ', ' . date('d') . ' de ' . $meses[date('n')] . ' de ' . date('Y');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Administrador – Amimbré</title>
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

<?php 
if (file_exists('../../includes/header.php')) {
    require_once '../../includes/header.php';
}
?>

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

    <!-- ── Alertas rápidas ───────────────────────────────────────────────── -->
    <?php if ($prematriculas_pendientes > 0 || $pagos_vencidos > 0): ?>
    <div class="alert-strip">
        <?php if ($prematriculas_pendientes > 0): ?>
        <a href="../inscripciones/prematriculas/index.php" class="alert-chip warning">
            <span class="material-symbols-rounded">pending_actions</span>
            <?php echo $prematriculas_pendientes; ?> prematrículas pendientes de revisión
            <span class="material-symbols-rounded">arrow_forward</span>
        </a>
        <?php endif; ?>
        <?php if ($pagos_vencidos > 0): ?>
        <a href="../inscripciones/matriculas/index.php?buscar=&estado=&pago=vencido" class="alert-chip danger">
            <span class="material-symbols-rounded">payments</span>
            <?php echo $pagos_vencidos; ?> pagos vencidos
            <span class="material-symbols-rounded">arrow_forward</span>
        </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ── Tarjetas de estadísticas ─────────────────────────────────────── -->
    <div class="stats-grid">

        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-title">Estudiantes Activos</span>
                <div class="stat-icon students">
                    <span class="material-symbols-rounded">school</span>
                </div>
            </div>
            <div class="stat-value"><?php echo number_format($estudiantes_activos); ?></div>
            <div class="stat-change <?php echo $cambio_estudiantes >= 0 ? 'positive' : 'negative'; ?>">
                <span class="material-symbols-rounded">
                    <?php echo $cambio_estudiantes >= 0 ? 'trending_up' : 'trending_down'; ?>
                </span>
                <?php echo abs($cambio_estudiantes); ?>% vs mes anterior
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-title">Cursos Activos</span>
                <div class="stat-icon courses">
                    <span class="material-symbols-rounded">menu_book</span>
                </div>
            </div>
            <div class="stat-value"><?php echo number_format($cursos_activos); ?></div>
            <div class="stat-change">
                <span class="material-symbols-rounded">groups</span>
                <?php echo $grupos_activos; ?> grupos en curso
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-title">Matrículas Activas</span>
                <div class="stat-icon enrollment">
                    <span class="material-symbols-rounded">assignment_ind</span>
                </div>
            </div>
            <div class="stat-value"><?php echo number_format($matriculas_activas); ?></div>
            <div class="stat-change <?php echo $prematriculas_pendientes > 0 ? 'pending' : ''; ?>">
                <span class="material-symbols-rounded">pending_actions</span>
                <?php echo $prematriculas_pendientes; ?> prematrículas pendientes
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <span class="stat-title">Pagos Vencidos</span>
                <div class="stat-icon income">
                    <span class="material-symbols-rounded">payments</span>
                </div>
            </div>
            <div class="stat-value"><?php echo number_format($pagos_vencidos); ?></div>
            <div class="stat-change <?php echo $pagos_vencidos > 0 ? 'negative' : 'positive'; ?>">
                <span class="material-symbols-rounded">
                    <?php echo $pagos_vencidos > 0 ? 'warning' : 'check_circle'; ?>
                </span>
                <?php echo $pagos_vencidos > 0 ? "Requieren atención" : "Sin pagos vencidos"; ?>
            </div>
        </div>

    </div><!-- /stats-grid -->

    <!-- ── Fila principal: Actividad + Acciones rápidas ─────────────────── -->
    <div class="content-grid">

        <!-- Actividad reciente -->
        <div class="card activity-container">
            <div class="section-header">
                <div>
                    <h3 class="section-title">Actividad Reciente</h3>
                    <p class="section-subtitle">Últimas acciones registradas en el sistema</p>
                </div>
                <a href="../reportes/index.php" class="btn-link">
                    Ver todo <span class="material-symbols-rounded">arrow_forward</span>
                </a>
            </div>
            <div class="activity-list">
                <?php if (count($actividades) > 0): ?>
                    <?php foreach ($actividades as $act):
                        $info = accion_label($act['accion']);
                        $nombre = $act['usuario_nombre'] ?? 'Sistema';
                    ?>
                    <div class="activity-item">
                        <div class="activity-icon <?php echo $info['clase']; ?>">
                            <span class="material-symbols-rounded"><?php echo $info['icono']; ?></span>
                        </div>
                        <div class="activity-content">
                            <div class="activity-title">
                                <strong><?php echo htmlspecialchars($nombre); ?></strong>
                                — <?php echo htmlspecialchars($act['detalles'] ?? $act['accion']); ?>
                            </div>
                            <div class="activity-time">
                                <?php echo tiempo_transcurrido($act['fecha']); ?>
                            </div>
                        </div>
                        <span class="activity-badge <?php echo $info['clase']; ?>">
                            <?php echo $info['label']; ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <span class="material-symbols-rounded">inbox</span>
                        <p>Sin actividad reciente registrada</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Acciones rápidas -->
        <div class="card quick-actions-container">
            <div class="section-header">
                <div>
                    <h3 class="section-title">Acciones Rápidas</h3>
                    <p class="section-subtitle">Tareas frecuentes</p>
                </div>
            </div>
            <div class="quick-actions-grid">

                <a href="../inscripciones/prematriculas/index.php" class="quick-action">
                    <div class="quick-action-icon enrollment">
                        <span class="material-symbols-rounded">person_add</span>
                    </div>
                    <div class="quick-action-content">
                        <div class="quick-action-title">Prematrículas</div>
                        <div class="quick-action-desc"><?php echo $prematriculas_pendientes; ?> pendientes</div>
                    </div>
                    <span class="material-symbols-rounded arrow">arrow_forward</span>
                </a>

                <a href="../usuarios/index.php" class="quick-action">
                    <div class="quick-action-icon teachers">
                        <span class="material-symbols-rounded">manage_accounts</span>
                    </div>
                    <div class="quick-action-content">
                        <div class="quick-action-title">Gestionar Usuarios</div>
                        <div class="quick-action-desc"><?php echo $profesores_activos; ?> prof · <?php echo $estudiantes_activos; ?> est</div>
                    </div>
                    <span class="material-symbols-rounded arrow">arrow_forward</span>
                </a>

                <a href="../cursos/index.php" class="quick-action">
                    <div class="quick-action-icon courses">
                        <span class="material-symbols-rounded">menu_book</span>
                    </div>
                    <div class="quick-action-content">
                        <div class="quick-action-title">Gestionar Cursos</div>
                        <div class="quick-action-desc"><?php echo $cursos_activos; ?> activos · <?php echo $grupos_activos; ?> grupos</div>
                    </div>
                    <span class="material-symbols-rounded arrow">arrow_forward</span>
                </a>

                <a href="../grupos/index.php" class="quick-action">
                    <div class="quick-action-icon students">
                        <span class="material-symbols-rounded">groups</span>
                    </div>
                    <div class="quick-action-content">
                        <div class="quick-action-title">Grupos</div>
                        <div class="quick-action-desc">Ver cupos y horarios</div>
                    </div>
                    <span class="material-symbols-rounded arrow">arrow_forward</span>
                </a>

                <a href="../reportes/index.php" class="quick-action">
                    <div class="quick-action-icon reports">
                        <span class="material-symbols-rounded">assessment</span>
                    </div>
                    <div class="quick-action-content">
                        <div class="quick-action-title">Reportes</div>
                        <div class="quick-action-desc">Análisis y estadísticas</div>
                    </div>
                    <span class="material-symbols-rounded arrow">arrow_forward</span>
                </a>

            </div>
        </div>

    </div><!-- /content-grid -->

    <!-- ── Fila secundaria: Prematrículas recientes + Ocupación grupos ───── -->
    <div class="content-grid content-grid--secondary">

        <!-- Últimas prematrículas -->
        <div class="card">
            <div class="section-header">
                <div>
                    <h3 class="section-title">Últimas Prematrículas</h3>
                    <p class="section-subtitle">Solicitudes más recientes</p>
                </div>
                <a href="../inscripciones/prematriculas/index.php" class="btn-link">
                    Ver todas <span class="material-symbols-rounded">arrow_forward</span>
                </a>
            </div>
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Solicitante</th>
                            <th>Programa / Taller</th>
                            <th>Estado</th>
                            <th>Fecha</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($ultimas_prematriculas) > 0): ?>
                            <?php foreach ($ultimas_prematriculas as $p):
                                $badge = estado_badge($p['estado']);
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($p['nombres_apellidos']); ?></td>
                                <td><?php echo htmlspecialchars($p['programa'] ?: $p['taller'] ?: '—'); ?></td>
                                <td><span class="badge <?php echo $badge['cls']; ?>"><?php echo $badge['txt']; ?></span></td>
                                <td><?php echo date('d/m/Y', strtotime($p['fecha_preinscripcion'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="4" class="empty-row">Sin prematrículas registradas</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Ocupación de grupos -->
        <div class="card">
            <div class="section-header">
                <div>
                    <h3 class="section-title">Ocupación de Grupos</h3>
                    <p class="section-subtitle">Grupos activos por capacidad</p>
                </div>
                <a href="../grupos/index.php" class="btn-link">
                    Ver todos <span class="material-symbols-rounded">arrow_forward</span>
                </a>
            </div>
            <div class="groups-list">
                <?php if (count($grupos_ocupacion) > 0): ?>
                    <?php foreach ($grupos_ocupacion as $g): 
                        $pct = min(100, (int)$g['ocupacion_pct']);
                        $color = $pct >= 90 ? 'bar-danger' : ($pct >= 70 ? 'bar-warning' : 'bar-success');
                    ?>
                    <div class="group-item">
                        <div class="group-info">
                            <span class="group-name"><?php echo htmlspecialchars($g['grupo']); ?></span>
                            <span class="group-meta"><?php echo htmlspecialchars($g['curso']); ?> · <?php echo htmlspecialchars($g['profesor']); ?></span>
                        </div>
                        <div class="group-bar-wrap">
                            <div class="group-bar">
                                <div class="group-bar-fill <?php echo $color; ?>" style="width:<?php echo $pct; ?>%"></div>
                            </div>
                            <span class="group-pct"><?php echo $g['cupo_actual']; ?>/<?php echo $g['cupo_maximo']; ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <span class="material-symbols-rounded">groups</span>
                        <p>Sin grupos activos</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- /content-grid secondary -->

    <!-- ── Mini resumen de personal ─────────────────────────────────────── -->
    <div class="content-grid content-grid--thirds">

        <div class="card mini-stat">
            <div class="mini-stat-icon students">
                <span class="material-symbols-rounded">school</span>
            </div>
            <div class="mini-stat-info">
                <span class="mini-stat-value"><?php echo $estudiantes_activos; ?></span>
                <span class="mini-stat-label">Estudiantes</span>
            </div>
        </div>

        <div class="card mini-stat">
            <div class="mini-stat-icon teachers">
                <span class="material-symbols-rounded">person_play</span>
            </div>
            <div class="mini-stat-info">
                <span class="mini-stat-value"><?php echo $profesores_activos; ?></span>
                <span class="mini-stat-label">Profesores</span>
            </div>
        </div>

        <div class="card mini-stat">
            <div class="mini-stat-icon courses">
                <span class="material-symbols-rounded">menu_book</span>
            </div>
            <div class="mini-stat-info">
                <span class="mini-stat-value"><?php echo $cursos_activos; ?></span>
                <span class="mini-stat-label">Cursos</span>
            </div>
        </div>

        <div class="card mini-stat">
            <div class="mini-stat-icon enrollment">
                <span class="material-symbols-rounded">assignment_ind</span>
            </div>
            <div class="mini-stat-info">
                <span class="mini-stat-value"><?php echo $matriculas_activas; ?></span>
                <span class="mini-stat-label">Matrículas</span>
            </div>
        </div>

        <div class="card mini-stat">
            <div class="mini-stat-icon <?php echo $pagos_vencidos > 0 ? 'pending' : 'income'; ?>">
                <span class="material-symbols-rounded">payments</span>
            </div>
            <div class="mini-stat-info">
                <span class="mini-stat-value"><?php echo $pagos_vencidos; ?></span>
                <span class="mini-stat-label">Pagos vencidos</span>
            </div>
        </div>

        <div class="card mini-stat">
            <div class="mini-stat-icon <?php echo $prematriculas_pendientes > 0 ? 'pending' : 'students'; ?>">
                <span class="material-symbols-rounded">pending_actions</span>
            </div>
            <div class="mini-stat-info">
                <span class="mini-stat-value"><?php echo $prematriculas_pendientes; ?></span>
                <span class="mini-stat-label">Pendientes</span>
            </div>
        </div>

    </div>

</main>

</body>
</html>