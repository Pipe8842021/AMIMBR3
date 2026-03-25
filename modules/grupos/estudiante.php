<?php
/**
 * Módulo Grupos – Vista Estudiante
 * Muestra los grupos en los que está matriculado con datos reales
 */
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth_check.php';
require_role('estudiante');

$uid = (int)$_SESSION['user_id'];

try {
    // Grupos matriculados con datos completos
    $stmt = $pdo->prepare("
        SELECT
            g.*,
            c.nombre      AS curso_nombre,
            c.nivel       AS curso_nivel,
            c.descripcion AS curso_desc,
            u.nombre      AS profesor_nombre,
            u.email       AS profesor_email,
            m.id          AS matricula_id,
            m.fecha_matricula
        FROM matriculas m
        JOIN grupos   g ON m.grupo_id    = g.id
        JOIN cursos   c ON g.curso_id    = c.id
        LEFT JOIN usuarios u ON g.profesor_id = u.id
        WHERE m.estudiante_id = ? AND m.estado = 'activa'
        ORDER BY
            FIELD(g.estado, 'activo', 'planificado', 'finalizado', 'cancelado'),
            m.fecha_matricula DESC
    ");
    $stmt->execute([$uid]);
    $mis_grupos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $ids_grupos = array_column($mis_grupos, 'id');

    $horarios_grupos = [];
    $asist_grupos    = [];
    $bitacoras_grupo = [];

    if (!empty($ids_grupos)) {
        $ph = implode(',', array_fill(0, count($ids_grupos), '?'));

        // Horarios de cada grupo
        $stmt = $pdo->prepare("
            SELECT grupo_id, dia_semana, hora_inicio, hora_fin, aula
            FROM horarios
            WHERE grupo_id IN ($ph)
            ORDER BY FIELD(dia_semana,'lunes','martes','miercoles','jueves','viernes','sabado','domingo'),
                     hora_inicio
        ");
        $stmt->execute($ids_grupos);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $h) {
            $horarios_grupos[$h['grupo_id']][] = $h;
        }

        // Asistencia del estudiante por grupo
        $stmt = $pdo->prepare("
            SELECT
                b.grupo_id,
                COUNT(ba.id) AS total,
                SUM(CASE WHEN ba.estado = 'presente'    THEN 1 ELSE 0 END) AS presentes,
                SUM(CASE WHEN ba.estado = 'ausente'     THEN 1 ELSE 0 END) AS ausentes,
                SUM(CASE WHEN ba.estado = 'justificado' THEN 1 ELSE 0 END) AS justificados,
                SUM(CASE WHEN ba.estado = 'tardanza'    THEN 1 ELSE 0 END) AS tardanzas
            FROM bitacoras_asistencias ba
            JOIN bitacoras b ON ba.bitacora_id = b.id
            WHERE ba.estudiante_id = ? AND b.grupo_id IN ($ph)
            GROUP BY b.grupo_id
        ");
        $stmt->execute(array_merge([$uid], $ids_grupos));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $asist_grupos[$r['grupo_id']] = $r;
        }

        // Total de clases (bitácoras) por grupo
        $stmt = $pdo->prepare("
            SELECT grupo_id, COUNT(*) AS total
            FROM bitacoras
            WHERE grupo_id IN ($ph) AND estado = 'activo'
            GROUP BY grupo_id
        ");
        $stmt->execute($ids_grupos);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $bitacoras_grupo[$r['grupo_id']] = $r['total'];
        }
    }

    // Totales del estudiante
    $total_grupos = count($mis_grupos);
    $stmt = $pdo->prepare("
        SELECT
            COUNT(ba.id) AS total,
            SUM(CASE WHEN ba.estado = 'presente' THEN 1 ELSE 0 END) AS presentes
        FROM bitacoras_asistencias ba
        WHERE ba.estudiante_id = ?
    ");
    $stmt->execute([$uid]);
    $asist_total = $stmt->fetch(PDO::FETCH_ASSOC);
    $pct_asist_global = $asist_total['total'] > 0
        ? round(($asist_total['presentes'] / $asist_total['total']) * 100) : null;

} catch (PDOException $e) {
    error_log($e->getMessage());
    $mis_grupos = [];
    $horarios_grupos = $asist_grupos = $bitacoras_grupo = [];
    $total_grupos = 0;
    $pct_asist_global = null;
}

// ─── Helpers ────────────────────────────────────────────────────────────────
$estado_cfg = [
    'planificado' => ['cls' => 'badge-info',    'txt' => 'Planificado'],
    'activo'      => ['cls' => 'badge-success',  'txt' => 'Activo'],
    'finalizado'  => ['cls' => 'badge-warning',  'txt' => 'Finalizado'],
    'cancelado'   => ['cls' => 'badge-danger',   'txt' => 'Cancelado'],
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
            <h1>Mi Información Académica</h1>
            <p>Consulta tus cursos y horarios matriculados</p>
        </div>
        <div style="display:flex; align-items:center; gap:8px; color:var(--text-secondary); font-size:0.875rem; background:var(--dark-bg); border:1px solid var(--border-color); padding:8px 16px; border-radius:10px;">
            <span class="material-symbols-rounded">calendar_today</span>
            <?php echo $fecha_hoy; ?>
        </div>
    </div>

    <!-- ── Chips de resumen ──────────────────────────────────────────────── -->
    <div class="modulo-stats">
        <div class="modulo-stat-chip total">
            <span class="material-symbols-rounded">menu_book</span>
            <div>
                <span class="chip-value"><?php echo $total_grupos; ?></span>
                <span class="chip-label">Grupos activos</span>
            </div>
        </div>
        <div class="modulo-stat-chip activo">
            <span class="material-symbols-rounded">fact_check</span>
            <div>
                <span class="chip-value">
                    <?php echo $pct_asist_global !== null ? $pct_asist_global . '%' : '—'; ?>
                </span>
                <span class="chip-label">Asistencia</span>
            </div>
        </div>
        <div class="modulo-stat-chip planificado">
            <span class="material-symbols-rounded">edit_note</span>
            <div>
                <span class="chip-value"><?php echo array_sum($bitacoras_grupo); ?></span>
                <span class="chip-label">Clases totales</span>
            </div>
        </div>
    </div>

    <!-- ── Grid de grupos ────────────────────────────────────────────────── -->
    <?php if (empty($mis_grupos)): ?>
    <div class="card">
        <div class="empty-state">
            <span class="material-symbols-rounded">menu_book</span>
            <p>No tienes grupos matriculados en este momento.</p>
        </div>
    </div>

    <?php else: ?>
    <div class="grupos-profesor-grid">
        <?php foreach ($mis_grupos as $g):
            $est      = $estado_cfg[$g['estado']] ?? $estado_cfg['planificado'];
            $niv      = $nivel_cfg[strtolower($g['curso_nivel'])] ?? 'badge-info';
            $pct_cupo = $g['cupo_maximo'] > 0 ? round(($g['cupo_actual'] / $g['cupo_maximo']) * 100) : 0;
            $bar_cls  = $pct_cupo >= 90 ? 'bar-danger' : ($pct_cupo >= 70 ? 'bar-warning' : 'bar-success');
            $asist    = $asist_grupos[$g['id']] ?? ['total'=>0,'presentes'=>0,'ausentes'=>0,'justificados'=>0,'tardanzas'=>0];
            $pct_e    = $asist['total'] > 0 ? round(($asist['presentes'] / $asist['total']) * 100) : null;
            $horarios = $horarios_grupos[$g['id']] ?? [];
            $n_clases = $bitacoras_grupo[$g['id']] ?? 0;
        ?>
        <div class="grupo-prof-card grupo-est-card">

            <!-- Cabecera -->
            <div class="grupo-prof-header">
                <div class="grupo-prof-badges">
                    <span class="badge <?php echo $niv; ?>"><?php echo ucfirst($g['curso_nivel']); ?></span>
                    <span class="badge <?php echo $est['cls']; ?>"><?php echo $est['txt']; ?></span>
                </div>
                <div class="grupo-prof-icon">
                    <span class="material-symbols-rounded">menu_book</span>
                </div>
            </div>

            <!-- Nombre y curso -->
            <div class="grupo-prof-nombre"><?php echo htmlspecialchars($g['curso_nombre']); ?></div>
            <div class="grupo-prof-curso">Grupo: <?php echo htmlspecialchars($g['nombre']); ?></div>

            <!-- Profesor -->
            <div class="grupo-est-profesor">
                <span class="material-symbols-rounded">person_play</span>
                <?php echo $g['profesor_nombre']
                    ? htmlspecialchars($g['profesor_nombre'])
                    : '<span style="color:var(--text-secondary);">Sin asignar</span>'; ?>
            </div>

            <!-- Horarios -->
            <?php if (!empty($horarios)): ?>
            <div class="grupo-est-horarios">
                <?php foreach ($horarios as $h): ?>
                <div class="grupo-est-horario-item">
                    <span class="hor-dia"><?php echo $dias_es[$h['dia_semana']] ?? strtoupper(substr($h['dia_semana'],0,3)); ?></span>
                    <span class="hor-hora">
                        <?php echo date('H:i', strtotime($h['hora_inicio'])); ?> –
                        <?php echo date('H:i', strtotime($h['hora_fin'])); ?>
                    </span>
                    <?php if ($h['aula']): ?>
                    <span class="hor-aula">
                        <span class="material-symbols-rounded">meeting_room</span>
                        <?php echo htmlspecialchars($h['aula']); ?>
                    </span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php elseif ($g['horario']): ?>
            <div class="grupo-prof-meta">
                <span>
                    <span class="material-symbols-rounded">schedule</span>
                    <?php echo htmlspecialchars($g['horario']); ?>
                </span>
            </div>
            <?php endif; ?>

            <!-- Mini stats: asistencia del estudiante en este grupo -->
            <div class="grupo-prof-stats">
                <div class="gps-item">
                    <?php if ($pct_e !== null): ?>
                    <span class="gps-value <?php echo $pct_e >= 75 ? 'positive' : 'negative'; ?>">
                        <?php echo $pct_e; ?>%
                    </span>
                    <div class="group-bar" style="width:100%; margin-top:4px;">
                        <div class="group-bar-fill <?php echo $pct_e >= 75 ? 'bar-success' : ($pct_e >= 50 ? 'bar-warning' : 'bar-danger'); ?>"
                             style="width:<?php echo $pct_e; ?>%"></div>
                    </div>
                    <?php else: ?>
                    <span class="gps-value" style="color:var(--text-secondary);">—</span>
                    <?php endif; ?>
                    <span class="gps-label">Mi asistencia</span>
                </div>

                <div class="gps-item">
                    <?php if ($asist['total'] > 0): ?>
                    <span class="gps-value"><?php echo $asist['presentes']; ?></span>
                    <span class="gps-label" style="color:var(--primary-green);">Presentes</span>
                    <?php else: ?>
                    <span class="gps-value" style="color:var(--text-secondary);">—</span>
                    <span class="gps-label">Presentes</span>
                    <?php endif; ?>
                </div>

                <div class="gps-item">
                    <span class="gps-value"><?php echo $n_clases; ?></span>
                    <span class="gps-label">Clases</span>
                </div>
            </div>

            <!-- Detalle de asistencia si hay registros -->
            <?php if ($asist['total'] > 0): ?>
            <div class="grupo-est-asist-detalle">
                <span title="Presentes"    class="asist-chip-mini presente">
                    <span class="material-symbols-rounded">check_circle</span> <?php echo $asist['presentes']; ?>
                </span>
                <span title="Ausentes"     class="asist-chip-mini ausente">
                    <span class="material-symbols-rounded">cancel</span> <?php echo $asist['ausentes']; ?>
                </span>
                <?php if ($asist['justificados'] > 0): ?>
                <span title="Justificados" class="asist-chip-mini justificado">
                    <span class="material-symbols-rounded">description</span> <?php echo $asist['justificados']; ?>
                </span>
                <?php endif; ?>
                <?php if ($asist['tardanzas'] > 0): ?>
                <span title="Tardanzas"    class="asist-chip-mini tardanza">
                    <span class="material-symbols-rounded">schedule</span> <?php echo $asist['tardanzas']; ?>
                </span>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Fecha de matrícula y cupos -->
            <div class="grupo-prof-meta" style="margin-top:-4px;">
                <span>
                    <span class="material-symbols-rounded">event</span>
                    Matriculado el <?php echo date('d/m/Y', strtotime($g['fecha_matricula'])); ?>
                </span>
                <span>
                    <span class="material-symbols-rounded">people</span>
                    <?php echo $g['cupo_actual']; ?>/<?php echo $g['cupo_maximo']; ?> estudiantes
                </span>
            </div>

            <!-- Acción -->
            <div class="grupo-prof-acciones">
                <a href="../horarios/index.php?grupo=<?php echo $g['id']; ?>" class="gpa-btn primary">
                    <span class="material-symbols-rounded">calendar_month</span>
                    Ver horario completo
                </a>
            </div>

        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</main>
</body>
</html>