<?php
/**
 * Módulo Grupos – Vista Profesor
 * Muestra los grupos asignados al profesor con estadísticas reales
 */
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth_check.php';
require_role('profesor');

$uid = (int)$_SESSION['user_id'];

try {
    // Grupos del profesor con datos completos
    $stmt = $pdo->prepare("
        SELECT
            g.*,
            c.nombre  AS curso_nombre,
            c.nivel   AS curso_nivel
        FROM grupos g
        JOIN cursos c ON g.curso_id = c.id
        WHERE g.profesor_id = ?
        ORDER BY
            FIELD(g.estado, 'activo', 'planificado', 'finalizado', 'cancelado'),
            g.fecha_inicio DESC
    ");
    $stmt->execute([$uid]);
    $mis_grupos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Para cada grupo: total estudiantes, asistencia y próximo horario
    $ids_grupos = array_column($mis_grupos, 'id');

    $stats_grupos = [];
    $horarios_grupos = [];

    if (!empty($ids_grupos)) {
        $ph = implode(',', array_fill(0, count($ids_grupos), '?'));

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

        // Siguiente horario de cada grupo
        $stmt = $pdo->prepare("
            SELECT grupo_id, dia_semana, hora_inicio, hora_fin, aula
            FROM horarios
            WHERE grupo_id IN ($ph)
            ORDER BY FIELD(dia_semana,'lunes','martes','miercoles','jueves','viernes','sabado','domingo'),
                     hora_inicio
        ");
        $stmt->execute($ids_grupos);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $h) {
            // Solo guardamos el primero (próximo) de cada grupo
            if (!isset($horarios_grupos[$h['grupo_id']])) {
                $horarios_grupos[$h['grupo_id']] = $h;
            }
        }

        // Total bitácoras por grupo
        $stmt = $pdo->prepare("
            SELECT grupo_id, COUNT(*) AS total
            FROM bitacoras
            WHERE grupo_id IN ($ph) AND estado = 'activo'
            GROUP BY grupo_id
        ");
        $stmt->execute($ids_grupos);
        $bitacoras_grupo = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $bitacoras_grupo[$r['grupo_id']] = $r['total'];
        }
    }

    // Totales generales del profesor
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

    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM bitacoras WHERE profesor_id = ? AND estado = 'activo'
    ");
    $stmt->execute([$uid]);
    $total_bitacoras = (int)$stmt->fetchColumn();

} catch (PDOException $e) {
    error_log($e->getMessage());
    $mis_grupos = [];
    $stats_grupos = $horarios_grupos = $bitacoras_grupo = [];
    $total_grupos = $grupos_activos = $total_estudiantes = $total_bitacoras = 0;
}

// ─── Helpers ────────────────────────────────────────────────────────────────
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
$dias_es = [
    'lunes'     => 'Lun', 'martes'    => 'Mar', 'miercoles' => 'Mié',
    'jueves'    => 'Jue', 'viernes'   => 'Vie', 'sabado'    => 'Sáb', 'domingo' => 'Dom',
];

date_default_timezone_set('America/Bogota');
$dias_semana = ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'];
$meses       = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
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
            <h1>Mis Grupos</h1>
            <p>Vista general de tus secciones asignadas</p>
        </div>
        <div style="display:flex; align-items:center; gap:8px; color:var(--text-secondary); font-size:0.875rem; background:var(--dark-bg); border:1px solid var(--border-color); padding:8px 16px; border-radius:10px;">
            <span class="material-symbols-rounded">calendar_today</span>
            <?php echo $fecha_hoy; ?>
        </div>
    </div>

    <!-- ── Chips de resumen ──────────────────────────────────────────────── -->
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
            <span class="material-symbols-rounded">school</span>
            <div>
                <span class="chip-value"><?php echo $total_estudiantes; ?></span>
                <span class="chip-label">Estudiantes</span>
            </div>
        </div>
        <div class="modulo-stat-chip finalizado">
            <span class="material-symbols-rounded">edit_note</span>
            <div>
                <span class="chip-value"><?php echo $total_bitacoras; ?></span>
                <span class="chip-label">Bitácoras</span>
            </div>
        </div>
    </div>

    <!-- ── Listado de grupos ─────────────────────────────────────────────── -->
    <?php if (empty($mis_grupos)): ?>
    <div class="card">
        <div class="empty-state">
            <span class="material-symbols-rounded">groups</span>
            <p>No tienes grupos asignados en este momento.</p>
        </div>
    </div>

    <?php else: ?>
    <div class="grupos-profesor-grid">
        <?php foreach ($mis_grupos as $g):
            $est     = $estado_cfg[$g['estado']] ?? $estado_cfg['planificado'];
            $niv     = $nivel_cfg[strtolower($g['curso_nivel'])] ?? 'badge-info';
            $pct_cupo= $g['cupo_maximo'] > 0 ? round(($g['cupo_actual'] / $g['cupo_maximo']) * 100) : 0;
            $bar_cls = $pct_cupo >= 90 ? 'bar-danger' : ($pct_cupo >= 70 ? 'bar-warning' : 'bar-success');
            $stats   = $stats_grupos[$g['id']] ?? ['total_registros' => 0, 'presentes' => 0];
            $pct_asist = $stats['total_registros'] > 0
                ? round(($stats['presentes'] / $stats['total_registros']) * 100) : null;
            $horario = $horarios_grupos[$g['id']] ?? null;
            $n_bitacoras = $bitacoras_grupo[$g['id']] ?? 0;
        ?>
        <div class="grupo-prof-card">

            <!-- Cabecera de la tarjeta -->
            <div class="grupo-prof-header">
                <div class="grupo-prof-badges">
                    <span class="badge <?php echo $niv; ?>"><?php echo ucfirst($g['curso_nivel']); ?></span>
                    <span class="badge <?php echo $est['cls']; ?>"><?php echo $est['txt']; ?></span>
                </div>
                <div class="grupo-prof-icon">
                    <span class="material-symbols-rounded">groups</span>
                </div>
            </div>

            <!-- Nombre y curso -->
            <div class="grupo-prof-nombre"><?php echo htmlspecialchars($g['nombre']); ?></div>
            <div class="grupo-prof-curso"><?php echo htmlspecialchars($g['curso_nombre']); ?></div>

            <!-- Metadata -->
            <div class="grupo-prof-meta">
                <?php if ($g['horario']): ?>
                <span>
                    <span class="material-symbols-rounded">schedule</span>
                    <?php echo htmlspecialchars($g['horario']); ?>
                </span>
                <?php endif; ?>
                <?php if ($g['aula']): ?>
                <span>
                    <span class="material-symbols-rounded">meeting_room</span>
                    <?php echo htmlspecialchars($g['aula']); ?>
                </span>
                <?php endif; ?>
                <span>
                    <span class="material-symbols-rounded">event</span>
                    Desde <?php echo date('d/m/Y', strtotime($g['fecha_inicio'])); ?>
                </span>
            </div>

            <!-- Estadísticas en mini-grid -->
            <div class="grupo-prof-stats">
                <div class="gps-item">
                    <span class="gps-value"><?php echo $g['cupo_actual']; ?>/<?php echo $g['cupo_maximo']; ?></span>
                    <span class="gps-label">Cupos</span>
                    <div class="group-bar" style="margin-top:5px;">
                        <div class="group-bar-fill <?php echo $bar_cls; ?>" style="width:<?php echo $pct_cupo; ?>%"></div>
                    </div>
                </div>
                <div class="gps-item">
                    <?php if ($pct_asist !== null): ?>
                    <span class="gps-value <?php echo $pct_asist >= 75 ? 'positive' : 'negative'; ?>">
                        <?php echo $pct_asist; ?>%
                    </span>
                    <?php else: ?>
                    <span class="gps-value" style="color:var(--text-secondary);">—</span>
                    <?php endif; ?>
                    <span class="gps-label">Asistencia</span>
                </div>
                <div class="gps-item">
                    <span class="gps-value"><?php echo $n_bitacoras; ?></span>
                    <span class="gps-label">Bitácoras</span>
                </div>
            </div>

            <!-- Horario estructurado si existe -->
            <?php if ($horario): ?>
            <div class="grupo-prof-horario">
                <span class="material-symbols-rounded">calendar_today</span>
                <span>
                    <?php echo $dias_es[$horario['dia_semana']] ?? ucfirst($horario['dia_semana']); ?>
                    <?php echo date('H:i', strtotime($horario['hora_inicio'])); ?> –
                    <?php echo date('H:i', strtotime($horario['hora_fin'])); ?>
                    <?php if ($horario['aula']): ?>· <?php echo htmlspecialchars($horario['aula']); ?><?php endif; ?>
                </span>
            </div>
            <?php endif; ?>

            <!-- Acciones -->
            <div class="grupo-prof-acciones">
                <a href="ver.php?id=<?php echo $g['id']; ?>" class="gpa-btn primary">
                    <span class="material-symbols-rounded">visibility</span>
                    Ver grupo
                </a>
                <a href="asistencia.php?grupo=<?php echo $g['id']; ?>" class="gpa-btn secondary">
                    <span class="material-symbols-rounded">fact_check</span>
                    Asistencia
                </a>
                <a href="../documentos/institucionales/bitacoras/crear.php?grupo=<?php echo $g['id']; ?>" class="gpa-btn secondary">
                    <span class="material-symbols-rounded">edit_note</span>
                    Bitácora
                </a>
            </div>

        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</main>

</body>
</html>