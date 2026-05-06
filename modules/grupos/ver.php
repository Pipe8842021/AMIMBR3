<?php
/**
 * Grupos – Ver detalle del grupo
 * Accesible por admin y profesor (solo su grupo)
 */
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth_check.php';
require_any_role(['admin','profesor']);

$id  = (int)($_GET['id'] ?? 0);
$rol = $_SESSION['user_rol'];
$uid = (int)$_SESSION['user_id'];

if (!$id) { header("Location: index.php"); exit; }

// Mensajes flash GET
$msg_map = [
    'creado'  => ['type' => 'success', 'text' => 'Grupo creado correctamente.'],
    'editado' => ['type' => 'success', 'text' => 'Grupo actualizado correctamente.'],
];
$flash_msg = $msg_map[$_GET['msg'] ?? ''] ?? null;

try {
    $stmt = $pdo->prepare("
        SELECT g.*, c.nombre AS curso_nombre, c.nivel AS curso_nivel,
               c.descripcion AS curso_desc, u.nombre AS profesor_nombre,
               u.email AS profesor_email
        FROM grupos g
        JOIN cursos c ON g.curso_id = c.id
        LEFT JOIN usuarios u ON g.profesor_id = u.id
        WHERE g.id = ?
    ");
    $stmt->execute([$id]);
    $grupo = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$grupo) { header("Location: index.php"); exit; }

    // El profesor solo puede ver sus grupos
    if ($rol === 'profesor' && $grupo['profesor_id'] != $uid) {
        header("Location: index.php"); exit;
    }

    // Estudiantes matriculados
    $stmt = $pdo->prepare("
        SELECT u.id, u.nombre, u.email, u.telefono, u.foto_perfil,
               m.id AS matricula_id, m.fecha_matricula, m.estado AS estado_matricula
        FROM matriculas m
        JOIN usuarios u ON m.estudiante_id = u.id
        WHERE m.grupo_id = ? AND m.estado = 'activa'
        ORDER BY u.nombre
    ");
    $stmt->execute([$id]);
    $estudiantes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Horarios del grupo
    $stmt = $pdo->prepare("
        SELECT * FROM horarios WHERE grupo_id = ?
        ORDER BY FIELD(dia_semana,'lunes','martes','miercoles','jueves','viernes','sabado','domingo')
    ");
    $stmt->execute([$id]);
    $horarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Últimas bitácoras
    $stmt = $pdo->prepare("
        SELECT b.id, b.titulo, b.fecha_clase, b.hora_inicio, b.hora_fin,
               b.temas_tratados, b.estado,
               (SELECT COUNT(*) FROM bitacoras_asistencias ba WHERE ba.bitacora_id = b.id) AS total_asist,
               (SELECT COUNT(*) FROM bitacoras_asistencias ba WHERE ba.bitacora_id = b.id AND ba.estado='presente') AS presentes
        FROM bitacoras b
        WHERE b.grupo_id = ? AND b.estado = 'activo'
        ORDER BY b.fecha_clase DESC
        LIMIT 8
    ");
    $stmt->execute([$id]);
    $bitacoras = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Estadísticas de asistencia del grupo
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN ba.estado='presente'    THEN 1 ELSE 0 END) AS presentes,
            SUM(CASE WHEN ba.estado='ausente'     THEN 1 ELSE 0 END) AS ausentes,
            SUM(CASE WHEN ba.estado='justificado' THEN 1 ELSE 0 END) AS justificados,
            SUM(CASE WHEN ba.estado='tardanza'    THEN 1 ELSE 0 END) AS tardanzas
        FROM bitacoras_asistencias ba
        JOIN bitacoras b ON ba.bitacora_id = b.id
        WHERE b.grupo_id = ?
    ");
    $stmt->execute([$id]);
    $stats_asist = $stmt->fetch(PDO::FETCH_ASSOC);
    $pct_asist = $stats_asist['total'] > 0
        ? round(($stats_asist['presentes'] / $stats_asist['total']) * 100) : 0;


    $stmt = $pdo->prepare("
        SELECT id, titulo, fecha_clase
        FROM bitacoras
        WHERE grupo_id = ? AND estado = 'activo'
        ORDER BY fecha_clase DESC
        LIMIT 10
    ");
    $stmt->execute([$id]);
    $bitacoras_asist = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Para cada bitácora, cargar sus registros de asistencia de una sola vez
    $registros_asist = [];
    if (count($bitacoras_asist) > 0) {
        $ids_bitacoras = array_column($bitacoras_asist, 'id');
        $placeholders  = implode(',', array_fill(0, count($ids_bitacoras), '?'));
        $stmt = $pdo->prepare("
            SELECT bitacora_id, estudiante_id, estado, observacion
            FROM bitacoras_asistencias
            WHERE bitacora_id IN ($placeholders)
        ");
        $stmt->execute($ids_bitacoras);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $registros_asist[$r['bitacora_id']][$r['estudiante_id']] = $r;
        }
    }

    // Totales por estudiante (para la columna de resumen)
    $stmt = $pdo->prepare("
        SELECT
            ba.estudiante_id,
            COUNT(*) AS total,
            SUM(CASE WHEN ba.estado='presente'    THEN 1 ELSE 0 END) AS presentes,
            SUM(CASE WHEN ba.estado='ausente'     THEN 1 ELSE 0 END) AS ausentes,
            SUM(CASE WHEN ba.estado='justificado' THEN 1 ELSE 0 END) AS justificados,
            SUM(CASE WHEN ba.estado='tardanza'    THEN 1 ELSE 0 END) AS tardanzas
        FROM bitacoras_asistencias ba
        JOIN bitacoras b ON ba.bitacora_id = b.id
        WHERE b.grupo_id = ?
        GROUP BY ba.estudiante_id
    ");
    $stmt->execute([$id]);
    $totales_est = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $totales_est[$t['estudiante_id']] = $t;
    }

} catch (PDOException $e) {
    error_log($e->getMessage());
    header("Location: index.php"); exit;
}

$estado_cfg = [
    'planificado' => ['cls' => 'badge-info',    'txt' => 'Planificado'],
    'activo'      => ['cls' => 'badge-success',  'txt' => 'Activo'],
    'finalizado'  => ['cls' => 'badge-warning',  'txt' => 'Finalizado'],
    'cancelado'   => ['cls' => 'badge-danger',   'txt' => 'Cancelado'],
];
$nivel_cfg = ['basico' => 'badge-info', 'intermedio' => 'badge-warning', 'avanzado' => 'badge-danger'];
$dias_es   = ['lunes'=>'Lunes','martes'=>'Martes','miercoles'=>'Miércoles','jueves'=>'Jueves','viernes'=>'Viernes','sabado'=>'Sábado','domingo'=>'Domingo'];
$meses_es  = ['','Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];

$est_g = $estado_cfg[$grupo['estado']] ?? $estado_cfg['planificado'];
$niv_g = $nivel_cfg[strtolower($grupo['curso_nivel'])] ?? 'badge-info';
$pct_cupo = $grupo['cupo_maximo'] > 0 ? round(($grupo['cupo_actual'] / $grupo['cupo_maximo']) * 100) : 0;
$bar_cls  = $pct_cupo >= 90 ? 'bar-danger' : ($pct_cupo >= 70 ? 'bar-warning' : 'bar-success');

function iniciales($nombre) {
    return implode('', array_map(fn($p) => strtoupper($p[0]), array_slice(explode(' ', $nombre), 0, 2)));
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($grupo['nombre']); ?> – Amimbré</title>
    <link rel="shortcut icon" href="../../assets/img/3.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,1,0">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="../../assets/css/colores.css">
    <link rel="stylesheet" href="../../assets/css/style-grupos.css">
    <script>(function(){ const t=localStorage.getItem('amimbre-theme'); if(t==='light') document.documentElement.setAttribute('data-theme','light'); })();</script>
</head>
<body>
<?php require_once '../../includes/header.php'; ?>
<main class="main-content">

    <?php if ($flash_msg): ?>
    <div class="alert alert-<?php echo $flash_msg['type']; ?>">
        <span class="material-symbols-rounded">check_circle</span>
        <?php echo $flash_msg['text']; ?>
    </div>
    <?php endif; ?>

    <!-- ── Encabezado del detalle ────────────────────────────────────────── -->
    <div class="detalle-header">
        <div class="detalle-titulo">
            <div style="display:flex; align-items:center; gap:10px; margin-bottom:8px;">
                <a href="<?php echo $rol==='admin' ? 'admin.php' : 'profesor.php'; ?>" class="btn-action back" style="padding:6px 10px;">
                    <span class="material-symbols-rounded">arrow_back</span>
                </a>
                <h2><?php echo htmlspecialchars($grupo['nombre']); ?></h2>
                <span class="badge <?php echo $est_g['cls']; ?>"><?php echo $est_g['txt']; ?></span>
                <span class="badge <?php echo $niv_g; ?>"><?php echo ucfirst($grupo['curso_nivel']); ?></span>
            </div>
            <div class="meta-row">
                <span><span class="material-symbols-rounded">menu_book</span><?php echo htmlspecialchars($grupo['curso_nombre']); ?></span>
                <?php if ($grupo['profesor_nombre']): ?>
                <span><span class="material-symbols-rounded">person_play</span><?php echo htmlspecialchars($grupo['profesor_nombre']); ?></span>
                <?php endif; ?>
                <?php if ($grupo['horario']): ?>
                <span><span class="material-symbols-rounded">schedule</span><?php echo htmlspecialchars($grupo['horario']); ?></span>
                <?php endif; ?>
                <?php if ($grupo['aula']): ?>
                <span><span class="material-symbols-rounded">meeting_room</span><?php echo htmlspecialchars($grupo['aula']); ?></span>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($rol === 'admin'): ?>
        <div class="detalle-actions">
            <a href="asistencia.php?grupo=<?php echo $id; ?>" class="btn-action">
                <span class="material-symbols-rounded">fact_check</span> Registrar asistencia
            </a>
            <a href="editar.php?id=<?php echo $id; ?>" class="btn-action edit">
                <span class="material-symbols-rounded">edit</span> Editar
            </a>
            <a href="eliminar.php?id=<?php echo $id; ?>" class="btn-action danger">
                <span class="material-symbols-rounded">delete</span>
            </a>
        </div>
        <?php else: ?>
        <div class="detalle-actions">
            <a href="asistencia.php?grupo=<?php echo $id; ?>" class="btn-action">
                <span class="material-symbols-rounded">fact_check</span> Registrar asistencia
            </a>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Fila de info rápida ───────────────────────────────────────────── -->
    <div class="detalle-grid" style="margin-bottom:20px;">

        <div class="info-card">
            <div class="info-card-title">
                <span class="material-symbols-rounded">info</span> Información del grupo
            </div>
            <div class="info-row">
                <span class="label">Fecha inicio</span>
                <span class="value"><?php echo date('d/m/Y', strtotime($grupo['fecha_inicio'])); ?></span>
            </div>
            <div class="info-row">
                <span class="label">Fecha fin</span>
                <span class="value"><?php echo $grupo['fecha_fin'] ? date('d/m/Y', strtotime($grupo['fecha_fin'])) : '—'; ?></span>
            </div>
            <div class="info-row">
                <span class="label">Horario</span>
                <span class="value"><?php echo htmlspecialchars($grupo['horario'] ?: '—'); ?></span>
            </div>
            <div class="info-row">
                <span class="label">Aula</span>
                <span class="value"><?php echo htmlspecialchars($grupo['aula'] ?: '—'); ?></span>
            </div>
            <div class="info-row">
                <span class="label">Bitácoras registradas</span>
                <span class="value"><?php echo count($bitacoras); ?></span>
            </div>
        </div>

        <div class="info-card">
            <div class="info-card-title">
                <span class="material-symbols-rounded">people</span> Ocupación y asistencia
            </div>
            <div class="ocupacion-wrap" style="margin-bottom:14px;">
                <div class="ocupacion-numero"><?php echo $grupo['cupo_actual']; ?><span style="font-size:1rem; font-weight:400; color:var(--text-secondary);">/<?php echo $grupo['cupo_maximo']; ?></span></div>
                <div class="ocupacion-label">estudiantes matriculados</div>
                <div class="group-bar" style="margin-top:8px;">
                    <div class="group-bar-fill <?php echo $bar_cls; ?>" style="width:<?php echo $pct_cupo; ?>%"></div>
                </div>
            </div>
            <div class="info-row">
                <span class="label">% Asistencia general</span>
                <span class="value <?php echo $pct_asist >= 75 ? 'positive' : 'negative'; ?>"
                      style="color:<?php echo $pct_asist >= 75 ? 'var(--primary-green)' : 'var(--primary-orange)'; ?>; font-weight:700;">
                    <?php echo $pct_asist; ?>%
                </span>
            </div>
            <div class="info-row">
                <span class="label">Registros totales</span>
                <span class="value"><?php echo $stats_asist['total']; ?></span>
            </div>
            <div class="info-row">
                <span class="label">Presentes / Ausentes</span>
                <span class="value"><?php echo $stats_asist['presentes']; ?> / <?php echo $stats_asist['ausentes']; ?></span>
            </div>
        </div>

    </div>

    <!-- ── Horarios configurados ─────────────────────────────────────────── -->
    <?php if (count($horarios) > 0): ?>
    <div class="card" style="margin-bottom:20px;">
        <div class="section-header">
            <div>
                <h3 class="section-title">Horario Semanal</h3>
                <p class="section-subtitle">Días y horas de clase</p>
            </div>
        </div>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <?php foreach ($horarios as $h): ?>
            <div class="event-item" style="flex:1; min-width:200px;">
                <div class="event-date-box">
                    <div class="day"><?php echo strtoupper(substr($dias_es[$h['dia_semana']] ?? $h['dia_semana'], 0, 3)); ?></div>
                    <div class="month"><?php echo date('H:i', strtotime($h['hora_inicio'])); ?></div>
                </div>
                <div class="event-content">
                    <div class="event-title"><?php echo ucfirst($dias_es[$h['dia_semana']] ?? $h['dia_semana']); ?></div>
                    <div class="event-meta">
                        <span class="material-symbols-rounded">schedule</span>
                        <?php echo date('H:i', strtotime($h['hora_inicio'])); ?> – <?php echo date('H:i', strtotime($h['hora_fin'])); ?>
                        <?php if ($h['aula']): ?>
                        &nbsp;·&nbsp;<span class="material-symbols-rounded">meeting_room</span><?php echo htmlspecialchars($h['aula']); ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Fila: Estudiantes + Bitácoras ─────────────────────────────────── -->
    <div class="detalle-grid">

        <!-- Estudiantes -->
        <div class="card">
            <div class="section-header">
                <div>
                    <h3 class="section-title">Estudiantes Matriculados</h3>
                    <p class="section-subtitle"><?php echo count($estudiantes); ?> estudiante<?php echo count($estudiantes) != 1 ? 's' : ''; ?> activo<?php echo count($estudiantes) != 1 ? 's' : ''; ?></p>
                </div>
            </div>

            <?php if (count($estudiantes) > 0): ?>
            <div style="display:flex; flex-direction:column; gap:10px;">
                <?php foreach ($estudiantes as $est): ?>
                <div class="estudiante-row">
                    <div class="est-avatar"><?php echo iniciales($est['nombre']); ?></div>
                    <div class="est-info">
                        <div class="est-nombre"><?php echo htmlspecialchars($est['nombre']); ?></div>
                        <div class="est-meta"><?php echo htmlspecialchars($est['email']); ?></div>
                    </div>
                    <span class="badge badge-success" style="font-size:0.7rem;">Activo</span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <span class="material-symbols-rounded">person_off</span>
                <p>Sin estudiantes matriculados aún</p>
            </div>
            <?php endif; ?>
        </div>

        <!-- Bitácoras -->
        <div class="card">
            <div class="section-header">
                <div>
                    <h3 class="section-title">Bitácoras de Clase</h3>
                    <p class="section-subtitle">Clases registradas</p>
                </div>
                <?php if ($rol === 'profesor' || $rol === 'admin'): ?>
                <a href="../bitacoras/crear.php?grupo=<?php echo $id; ?>" class="btn-link">
                    + Nueva <span class="material-symbols-rounded">arrow_forward</span>
                </a>
                <?php endif; ?>
            </div>

            <?php if (count($bitacoras) > 0): ?>
            <div style="display:flex; flex-direction:column; gap:10px;">
                <?php foreach ($bitacoras as $b):
                    $dia  = date('d', strtotime($b['fecha_clase']));
                    $mes  = $meses_es[(int)date('n', strtotime($b['fecha_clase']))];
                    $pct_b = $b['total_asist'] > 0 ? round(($b['presentes'] / $b['total_asist']) * 100) : null;
                ?>
                <div class="bitacora-item">
                    <div class="bitacora-fecha">
                        <div class="day"><?php echo $dia; ?></div>
                        <div class="month"><?php echo $mes; ?></div>
                    </div>
                    <div class="bitacora-content">
                        <div class="bitacora-titulo"><?php echo htmlspecialchars($b['titulo']); ?></div>
                        <div class="bitacora-meta">
                            <span><?php echo date('H:i', strtotime($b['hora_inicio'])); ?> – <?php echo date('H:i', strtotime($b['hora_fin'])); ?></span>
                            <?php if ($pct_b !== null): ?>
                            <span>Asistencia: <?php echo $pct_b; ?>%</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <span class="material-symbols-rounded">menu_book</span>
                <p>Sin bitácoras registradas aún</p>
            </div>
            <?php endif; ?>
        </div>

    </div>

    <!-- ── Tabla de asistencia por estudiante ───────────────────────────── -->
    <div class="card" style="margin-bottom: 24px;">
        <div class="section-header">
            <div>
                <h3 class="section-title">Registro de Asistencia</h3>
                <p class="section-subtitle">
                    <?php if (count($bitacoras_asist) > 0): ?>
                        Últimas <?php echo count($bitacoras_asist); ?> clases · <?php echo count($estudiantes); ?> estudiante<?php echo count($estudiantes) != 1 ? 's' : ''; ?>
                    <?php else: ?>
                        Sin clases registradas aún
                    <?php endif; ?>
                </p>
            </div>
            <?php if ($rol === 'admin' || $rol === 'profesor'): ?>
            <a href="asistencia.php?grupo=<?php echo $id; ?>" class="btn-submit" style="font-size:0.82rem; padding:8px 16px;">
                <span class="material-symbols-rounded">fact_check</span> Registrar clase
            </a>
            <?php endif; ?>
        </div>

        <?php if (count($bitacoras_asist) > 0 && count($estudiantes) > 0): ?>

        <?php
        // Config visual por estado
        $asist_cfg = [
            'presente'    => ['icono' => 'check_circle', 'cls' => 'presente',    'title' => 'Presente'],
            'ausente'     => ['icono' => 'cancel',       'cls' => 'ausente',     'title' => 'Ausente'],
            'justificado' => ['icono' => 'description',  'cls' => 'justificado', 'title' => 'Justificado'],
            'tardanza'    => ['icono' => 'schedule',     'cls' => 'tardanza',    'title' => 'Tardanza'],
        ];
        $meses_abr = ['','Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
        ?>

        <div class="asist-tabla-wrap">
            <table class="asist-tabla">
                <thead>
                    <tr>
                        <th class="asist-th-nombre">Estudiante</th>
                        <?php foreach ($bitacoras_asist as $bit): ?>
                        <th class="asist-th-fecha" title="<?php echo htmlspecialchars($bit['titulo']); ?>">
                            <div class="asist-fecha-col">
                                <span class="asist-fecha-dia"><?php echo date('d', strtotime($bit['fecha_clase'])); ?></span>
                                <span class="asist-fecha-mes"><?php echo $meses_abr[(int)date('n', strtotime($bit['fecha_clase']))]; ?></span>
                            </div>
                        </th>
                        <?php endforeach; ?>
                        <th class="asist-th-resumen">Resumen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($estudiantes as $est):
                        $tot = $totales_est[$est['id']] ?? ['total'=>0,'presentes'=>0,'ausentes'=>0,'justificados'=>0,'tardanzas'=>0];
                        $pct_e = $tot['total'] > 0 ? round(($tot['presentes'] / $tot['total']) * 100) : null;
                        $bar_e = $pct_e === null ? '' : ($pct_e >= 75 ? 'bar-success' : ($pct_e >= 50 ? 'bar-warning' : 'bar-danger'));
                    ?>
                    <tr>
                        <!-- Nombre del estudiante -->
                        <td class="asist-td-nombre">
                            <div style="display:flex; align-items:center; gap:9px;">
                                <div class="est-avatar" style="width:30px;height:30px;font-size:0.72rem;flex-shrink:0;">
                                    <?php echo iniciales($est['nombre']); ?>
                                </div>
                                <span><?php echo htmlspecialchars($est['nombre']); ?></span>
                            </div>
                        </td>

                        <!-- Celda por cada bitácora -->
                        <?php foreach ($bitacoras_asist as $bit):
                            $reg = $registros_asist[$bit['id']][$est['id']] ?? null;
                            $cfg = $reg ? ($asist_cfg[$reg['estado']] ?? null) : null;
                        ?>
                        <td class="asist-td-celda" title="<?php echo $reg ? $asist_cfg[$reg['estado']]['title'] . ($reg['observacion'] ? ': ' . htmlspecialchars($reg['observacion']) : '') : 'Sin registro'; ?>">
                            <?php if ($cfg): ?>
                                <span class="asist-icono <?php echo $cfg['cls']; ?>">
                                    <span class="material-symbols-rounded"><?php echo $cfg['icono']; ?></span>
                                </span>
                            <?php else: ?>
                                <span class="asist-icono sin-registro">—</span>
                            <?php endif; ?>
                        </td>
                        <?php endforeach; ?>

                        <!-- Resumen del estudiante -->
                        <td class="asist-td-resumen">
                            <?php if ($tot['total'] > 0): ?>
                            <div class="asist-resumen-cell">
                                <span class="asist-pct <?php echo $bar_e; ?>"><?php echo $pct_e; ?>%</span>
                                <div class="group-bar" style="width:60px;">
                                    <div class="group-bar-fill <?php echo $bar_e; ?>" style="width:<?php echo $pct_e; ?>%"></div>
                                </div>
                                <span class="asist-detalle">
                                    <span title="Presentes"  style="color:var(--primary-green);"><?php echo $tot['presentes']; ?>P</span>
                                    <span title="Ausentes"   style="color:var(--primary-orange);"><?php echo $tot['ausentes']; ?>A</span>
                                    <?php if ($tot['justificados'] > 0): ?>
                                    <span title="Justificados" style="color:var(--primary-yellow);"><?php echo $tot['justificados']; ?>J</span>
                                    <?php endif; ?>
                                    <?php if ($tot['tardanzas'] > 0): ?>
                                    <span title="Tardanzas" style="color:var(--primary-blue);"><?php echo $tot['tardanzas']; ?>T</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <?php else: ?>
                            <span style="color:var(--text-secondary); font-size:0.78rem;">Sin registros</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>

                <!-- Fila de totales por clase -->
                <tfoot>
                    <tr>
                        <td class="asist-td-nombre" style="font-weight:600; font-size:0.8rem; color:var(--text-secondary);">
                            % Asistencia
                        </td>
                        <?php foreach ($bitacoras_asist as $bit):
                            $regs_bit  = $registros_asist[$bit['id']] ?? [];
                            $total_bit = count($regs_bit);
                            $pres_bit  = count(array_filter($regs_bit, fn($r) => $r['estado'] === 'presente'));
                            $pct_bit   = $total_bit > 0 ? round(($pres_bit / $total_bit) * 100) : null;
                            $cls_bit   = $pct_bit === null ? '' : ($pct_bit >= 75 ? 'bar-success' : ($pct_bit >= 50 ? 'bar-warning' : 'bar-danger'));
                        ?>
                        <td class="asist-td-celda" style="text-align:center;">
                            <?php if ($pct_bit !== null): ?>
                            <span class="asist-pct <?php echo $cls_bit; ?>" style="font-size:0.72rem;">
                                <?php echo $pct_bit; ?>%
                            </span>
                            <?php else: ?>
                            <span style="color:var(--text-secondary); font-size:0.72rem;">—</span>
                            <?php endif; ?>
                        </td>
                        <?php endforeach; ?>
                        <td class="asist-td-resumen">
                            <span class="asist-pct <?php echo $pct_asist >= 75 ? 'bar-success' : ($pct_asist >= 50 ? 'bar-warning' : 'bar-danger'); ?>" style="font-size:0.82rem; font-weight:700;">
                                <?php echo $pct_asist; ?>% general
                            </span>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- Leyenda -->
        <div class="asist-leyenda">
            <?php foreach ($asist_cfg as $estado => $cfg): ?>
            <span class="asist-leyenda-item">
                <span class="asist-icono <?php echo $cfg['cls']; ?>" style="width:22px;height:22px;">
                    <span class="material-symbols-rounded" style="font-size:14px;"><?php echo $cfg['icono']; ?></span>
                </span>
                <?php echo $cfg['title']; ?>
            </span>
            <?php endforeach; ?>
            <span class="asist-leyenda-item">
                <span class="asist-icono sin-registro" style="width:22px;height:22px; font-size:0.8rem;">—</span>
                Sin registro
            </span>
        </div>

        <?php elseif (count($estudiantes) === 0): ?>
        <div class="empty-state">
            <span class="material-symbols-rounded">person_off</span>
            <p>Sin estudiantes matriculados en este grupo</p>
        </div>
        <?php else: ?>
        <div class="empty-state">
            <span class="material-symbols-rounded">fact_check</span>
            <p>Aún no se han registrado clases con asistencia</p>
            <?php if ($rol === 'admin' || $rol === 'profesor'): ?>
            <a href="asistencia.php?grupo=<?php echo $id; ?>" class="btn-submit" style="margin-top:12px; font-size:0.82rem; padding:8px 16px;">
                <span class="material-symbols-rounded">add_circle</span> Registrar primera clase
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div>

</main>

<script>
// Auto-ocultar flash
setTimeout(() => {
    document.querySelectorAll('.alert').forEach(a => {
        a.style.transition = 'opacity 0.5s ease';
        a.style.opacity = '0';
        setTimeout(() => a.remove(), 500);
    });
}, 4000);
</script>

</body>
</html>