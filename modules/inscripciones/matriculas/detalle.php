<?php
/**
 * Detalle de Matrículas por Estudiante — con pestañas
 * Parámetro: ?estudiante=ID  (obligatorio)
 * Opcional:  &tab=MATRICULA_ID  para abrir una pestaña específica
 */
require_once '../../../config/session.php';
require_once '../../../config/database.php';
require_once '../../../includes/auth_check.php';
require_role('admin');

function get_foto_url(?string $foto): string {
    if (empty($foto)) return '';
    return '../../../assets/img/avatars/' . htmlspecialchars($foto);
}

$estudiante_id = (int)($_GET['estudiante'] ?? 0);
$tab_activa    = (int)($_GET['tab'] ?? 0);

if (!$estudiante_id) { header("Location: index.php"); exit; }

try {
    // Datos del estudiante
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = ? AND rol = 'estudiante'");
    $stmt->execute([$estudiante_id]);
    $estudiante = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$estudiante) { header("Location: index.php"); exit; }

    // Todas las matrículas del estudiante
    $stmt = $pdo->prepare("
        SELECT m.*,
               g.id as grupo_id, g.nombre as grupo_nombre, g.horario as grupo_horario,
               g.aula as grupo_aula, g.cupo_actual, g.cupo_maximo,
               g.fecha_inicio as grupo_fecha_inicio, g.fecha_fin as grupo_fecha_fin,
               c.id as curso_id, c.nombre as curso_nombre, c.nivel as curso_nivel,
               c.precio_mensual, c.descripcion as curso_descripcion,
               prof.nombre as profesor_nombre, prof.email as profesor_email
        FROM matriculas m
        LEFT JOIN grupos g ON m.grupo_id = g.id
        LEFT JOIN cursos c ON g.curso_id = c.id
        LEFT JOIN usuarios prof ON g.profesor_id = prof.id
        WHERE m.estudiante_id = ?
        ORDER BY
            FIELD(m.estado, 'activa', 'suspendida', 'graduado', 'retirado'),
            m.fecha_matricula DESC
    ");
    $stmt->execute([$estudiante_id]);
    $matriculas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($matriculas)) { header("Location: index.php"); exit; }

    if (!$tab_activa) {
        foreach ($matriculas as $m) {
            if ($m['estado'] === 'activa') { $tab_activa = $m['id']; break; }
        }
        if (!$tab_activa) $tab_activa = $matriculas[0]['id'];
    }

    $pagos_por_mat          = [];
    $grupos_disponibles_mat = [];
    $cursos_grupos_mat      = [];

    foreach ($matriculas as $m) {
        $stmt = $pdo->prepare("
            SELECT p.*, u.nombre as registrado_por_nombre
            FROM pagos p LEFT JOIN usuarios u ON p.registrado_por = u.id
            WHERE p.matricula_id = ?
            ORDER BY p.fecha_vencimiento DESC
        ");
        $stmt->execute([$m['id']]);
        $pagos_por_mat[$m['id']] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("
            SELECT g.id, g.nombre, g.horario, g.aula, g.cupo_actual, g.cupo_maximo,
                   g.fecha_inicio, c.id as curso_id, c.nombre as curso_nombre, c.nivel as curso_nivel,
                   c.precio_mensual, u.nombre as profesor_nombre,
                   GROUP_CONCAT(
                       CONCAT(h.dia_semana,'|',h.hora_inicio,'|',h.hora_fin,'|',COALESCE(h.aula,''))
                       ORDER BY FIELD(h.dia_semana,'lunes','martes','miercoles','jueves','viernes','sabado','domingo')
                       SEPARATOR ';;'
                   ) as horarios_raw
            FROM grupos g
            JOIN cursos c ON g.curso_id = c.id
            LEFT JOIN usuarios u ON g.profesor_id = u.id
            LEFT JOIN horarios h ON h.grupo_id = g.id
            WHERE g.estado IN ('activo','planificado')
              AND g.cupo_actual < g.cupo_maximo
              AND g.id != ?
            GROUP BY g.id, g.nombre, g.horario, g.aula, g.cupo_actual, g.cupo_maximo,
                     g.fecha_inicio, c.id, c.nombre, c.nivel, c.precio_mensual, u.nombre
            ORDER BY c.nombre, g.nombre
        ");
        $stmt->execute([$m['grupo_id'] ?? 0]);
        $gds = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $cursos_grupos_mat[$m['id']] = [];
        foreach ($gds as &$gd) {
            $gd['horarios_lista'] = [];
            if ($gd['horarios_raw']) {
                foreach (explode(';;', $gd['horarios_raw']) as $h) {
                    [$dia,$hi,$hf,$aula_h] = explode('|', $h);
                    $gd['horarios_lista'][] = ['dia'=>$dia,'inicio'=>substr($hi,0,5),'fin'=>substr($hf,0,5),'aula'=>$aula_h];
                }
            }
            $cursos_grupos_mat[$m['id']][$gd['curso_nombre']] = $gd['curso_id'];
        }
        unset($gd);
        $grupos_disponibles_mat[$m['id']] = $gds;
    }

    $resumen_pagos = [];
    foreach ($matriculas as $m) {
        $rp = ['monto_total'=>0,'monto_pagado'=>0,'monto_pendiente'=>0,'pendientes'=>0,'vencidos'=>0];
        foreach ($pagos_por_mat[$m['id']] as $p) {
            $rp['monto_total'] += $p['monto'];
            if ($p['estado']==='pagado')    $rp['monto_pagado']    += $p['monto'];
            if ($p['estado']==='pendiente') { $rp['pendientes']++;   $rp['monto_pendiente'] += $p['monto']; }
            if ($p['estado']==='vencido')   { $rp['vencidos']++;     $rp['monto_pendiente'] += $p['monto']; }
        }
        $resumen_pagos[$m['id']] = $rp;
    }

    // ── Datos para el modal de nueva matrícula ──────────────────
    $estudiantes_modal = $pdo->query("
        SELECT id, nombre, email, documento
        FROM usuarios
        WHERE rol = 'estudiante' AND estado = 'activo'
        ORDER BY nombre
    ")->fetchAll(PDO::FETCH_ASSOC);

    $grupos_raw_modal = $pdo->query("
        SELECT g.id, g.nombre, g.horario, g.cupo_actual, g.cupo_maximo,
               c.nombre as curso_nombre, c.precio_mensual, c.duracion_meses,
               u.nombre as profesor_nombre
        FROM grupos g
        INNER JOIN cursos c ON g.curso_id = c.id
        LEFT JOIN usuarios u ON g.profesor_id = u.id
        WHERE g.estado IN ('activo','planificado')
          AND g.cupo_actual < g.cupo_maximo
        ORDER BY c.nombre, g.nombre
    ")->fetchAll(PDO::FETCH_ASSOC);

    $grupos_por_curso_modal = [];
    foreach ($grupos_raw_modal as $g) $grupos_por_curso_modal[$g['curso_nombre']][] = $g;

    $flash_msg  = $_GET['msg']  ?? '';
    $flash_type = $_GET['type'] ?? '';
    $open_modal = isset($_GET['open_modal']) && $_GET['open_modal'] === '1';

} catch (PDOException $e) {
    error_log($e->getMessage());
    die("Error al cargar los datos.");
}

// Helpers
function badge_pago($e)   { return ['pagado'=>['label'=>'Pagado','class'=>'badge-success'],'pendiente'=>['label'=>'Pendiente','class'=>'badge-warning'],'vencido'=>['label'=>'Vencido','class'=>'badge-danger'],'anulado'=>['label'=>'Anulado','class'=>'badge-secondary']][$e] ?? ['label'=>ucfirst($e),'class'=>'badge-secondary']; }
function badge_estado($e) { return ['activa'=>['label'=>'Activa','class'=>'badge-success'],'suspendida'=>['label'=>'Suspendida','class'=>'badge-warning'],'graduado'=>['label'=>'Graduado','class'=>'badge-info'],'retirado'=>['label'=>'Retirado','class'=>'badge-danger']][$e] ?? ['label'=>ucfirst($e),'class'=>'badge-secondary']; }
function fmt_money($n)    { return '$' . number_format($n, 0, ',', '.'); }
function nivel_label($n)  { return ['basico'=>'Básico','intermedio'=>'Intermedio','avanzado'=>'Avanzado'][$n] ?? ucfirst($n ?? ''); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($estudiante['nombre']) ?> — Matrículas</title>
    <link rel="shortcut icon" href="../../../assets/img/3.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,1,0"/>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="../../../assets/css/colores.css">
    <link rel="stylesheet" href="../../../assets/css/style-matriculas.css">
    <script>(function(){ const t=localStorage.getItem('amimbre-theme'); if(t==='light') document.documentElement.setAttribute('data-theme','light'); })();</script>
</head>
<body>
<?php require_once '../../../includes/header.php'; ?>
<main class="main-content">

    <!-- Encabezado -->
    <div class="dashboard-header">
        <div class="dashboard-title">
            <a href="index.php" class="back-link">
                <span class="material-symbols-rounded">arrow_back</span> Matrículas
            </a>
            <h1><?= htmlspecialchars($estudiante['nombre']) ?></h1>
            <p><?= htmlspecialchars($estudiante['email']) ?>
               · Doc. <?= htmlspecialchars($estudiante['documento'] ?? '—') ?>
               · <?= count($matriculas) ?> matrícula<?= count($matriculas) != 1 ? 's' : '' ?>
            </p>
        </div>
        <!-- Botón abre modal en lugar de ir a nueva.php -->
        <button type="button" class="btn-primary" onclick="abrirModalNuevaMatricula()">
            <span class="material-symbols-rounded">add</span> Nueva matrícula
        </button>
    </div>

    <!-- Flash -->
    <?php if ($flash_msg): ?>
    <div class="alert alert-<?= $flash_type==='success'?'success':'danger' ?>" id="flashAlert">
        <span class="material-symbols-rounded"><?= $flash_type==='success'?'check_circle':'error' ?></span>
        <?= htmlspecialchars($flash_msg) ?>
    </div>
    <?php endif; ?>

    <!-- Perfil del estudiante (compacto) -->
    <div class="estudiante-perfil-card">
        <div class="ep-avatar">
            <?php $foto_url = get_foto_url($estudiante['foto_perfil'] ?? ''); ?>
            <?php if ($foto_url): ?>
                <img src="<?= $foto_url ?>"
                    alt=""
                    onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                <span style="display:none;">
                    <?= mb_strtoupper(mb_substr($estudiante['nombre'], 0, 1)) ?>
                </span>
            <?php else: ?>
                <?= mb_strtoupper(mb_substr($estudiante['nombre'], 0, 1)) ?>
            <?php endif; ?>
        </div>
        <div class="ep-info">
            <div class="ep-nombre"><?= htmlspecialchars($estudiante['nombre']) ?></div>
            <div class="ep-meta">
                <span><span class="material-symbols-rounded">email</span><?= htmlspecialchars($estudiante['email']) ?></span>
                <?php if ($estudiante['telefono']): ?>
                <span><span class="material-symbols-rounded">phone</span><?= htmlspecialchars($estudiante['telefono']) ?></span>
                <?php endif; ?>
                <?php if ($estudiante['documento']): ?>
                <span><span class="material-symbols-rounded">badge</span><?= htmlspecialchars($estudiante['documento']) ?></span>
                <?php endif; ?>
                <?php if ($estudiante['fecha_nacimiento']): ?>
                <span><span class="material-symbols-rounded">cake</span><?= date('d/m/Y', strtotime($estudiante['fecha_nacimiento'])) ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Pestañas de matrículas -->
    <div class="tabs-wrap">
        <div class="tabs-nav" id="tabsNav">
            <?php foreach ($matriculas as $i => $m):
                $b   = badge_estado($m['estado']);
                $rp  = $resumen_pagos[$m['id']];
                $activa = $m['id'] === $tab_activa;
            ?>
            <button class="tab-btn <?= $activa ? 'tab-btn--active' : '' ?>"
                    data-tab="tab-<?= $m['id'] ?>"
                    onclick="cambiarTab('tab-<?= $m['id'] ?>', this)">
                <div class="tab-btn-inner">
                    <span class="tab-numero">#<?= $i + 1 ?></span>
                    <div class="tab-info">
                        <span class="tab-curso"><?= htmlspecialchars($m['curso_nombre'] ?? 'Sin curso') ?></span>
                        <div class="tab-badges">
                            <span class="badge <?= $b['class'] ?>" style="font-size:0.65rem; padding:2px 7px;"><?= $b['label'] ?></span>
                            <?php if ($rp['pendientes'] + $rp['vencidos'] > 0): ?>
                            <span class="badge badge-warning-soft" style="font-size:0.65rem; padding:2px 7px;">
                                <span class="material-symbols-rounded" style="font-size:11px;">receipt_long</span>
                                <?= $rp['pendientes'] + $rp['vencidos'] ?> pend.
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </button>
            <?php endforeach; ?>
        </div>

        <!-- Contenido de cada pestaña -->
        <?php foreach ($matriculas as $m):
            $activa             = $m['id'] === $tab_activa;
            $pagos              = $pagos_por_mat[$m['id']];
            $rp                 = $resumen_pagos[$m['id']];
            $grupos_disponibles = $grupos_disponibles_mat[$m['id']];
            $cursos_grupos      = $cursos_grupos_mat[$m['id']];
            $dias_abbr          = ['lunes'=>'Lun','martes'=>'Mar','miercoles'=>'Mié','jueves'=>'Jue','viernes'=>'Vie','sabado'=>'Sáb','domingo'=>'Dom'];
        ?>
        <div class="tab-panel <?= $activa ? 'tab-panel--active' : '' ?>" id="tab-<?= $m['id'] ?>">

            <div class="detalle-grid">

                <!-- ── Col izquierda ──────────────────────────── -->
                <div class="detalle-col-left">

                    <!-- Curso y Grupo -->
                    <div class="card">
                        <div class="card-header">
                            <span class="material-symbols-rounded card-header-icon">music_note</span>
                            <h3>Curso y Grupo</h3>
                            <?php if (!empty($grupos_disponibles)): ?>
                            <button class="btn-primary btn-sm ml-auto"
                                    onclick="abrirModalGrupo('<?= $m['id'] ?>')">
                                <span class="material-symbols-rounded">
                                    <?= $m['grupo_nombre'] ? 'swap_horiz' : 'group_add' ?>
                                </span>
                                <?= $m['grupo_nombre'] ? 'Cambiar grupo' : 'Asignar grupo' ?>
                            </button>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <?php if ($m['curso_nombre']): ?>
                            <div class="course-info-block">
                                <div class="course-badge-wrap">
                                    <span class="course-badge"><?= htmlspecialchars($m['curso_nombre']) ?></span>
                                    <span class="nivel-badge nivel-<?= $m['curso_nivel'] ?>">
                                        <?= nivel_label($m['curso_nivel']) ?>
                                    </span>
                                </div>
                                <?php if ($m['curso_descripcion']): ?>
                                <p class="course-desc"><?= htmlspecialchars($m['curso_descripcion']) ?></p>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>

                            <?php if ($m['grupo_nombre']): ?>
                            <div class="grupo-actual">
                                <div class="grupo-actual-header">
                                    <div class="grupo-actual-icono">
                                        <span class="material-symbols-rounded">groups</span>
                                    </div>
                                    <div>
                                        <div class="grupo-actual-nombre"><?= htmlspecialchars($m['grupo_nombre']) ?></div>
                                        <?php if ($m['profesor_nombre']): ?>
                                        <div class="grupo-actual-prof">
                                            <span class="material-symbols-rounded">person_play</span>
                                            <?= htmlspecialchars($m['profesor_nombre']) ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="grupo-actual-meta">
                                    <?php if ($m['grupo_horario']): ?>
                                    <span class="gam-chip">
                                        <span class="material-symbols-rounded">schedule</span>
                                        <?= htmlspecialchars($m['grupo_horario']) ?>
                                    </span>
                                    <?php endif; ?>
                                    <?php if ($m['grupo_aula']): ?>
                                    <span class="gam-chip">
                                        <span class="material-symbols-rounded">meeting_room</span>
                                        <?= htmlspecialchars($m['grupo_aula']) ?>
                                    </span>
                                    <?php endif; ?>
                                    <span class="gam-chip">
                                        <span class="material-symbols-rounded">people</span>
                                        <?= $m['cupo_actual'] ?>/<?= $m['cupo_maximo'] ?> cupos
                                    </span>
                                    <?php if ($m['grupo_fecha_inicio']): ?>
                                    <span class="gam-chip">
                                        <span class="material-symbols-rounded">event</span>
                                        Desde <?= date('d/m/Y', strtotime($m['grupo_fecha_inicio'])) ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php else: ?>
                            <div class="no-group-notice" style="margin-top:10px;">
                                <span class="material-symbols-rounded">warning</span>
                                <p>Sin grupo asignado aún</p>
                            </div>
                            <?php if (empty($grupos_disponibles)): ?>
                            <p class="text-muted" style="margin-top:8px; font-size:0.8rem;">No hay grupos con cupo disponibles.</p>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Estado de la matrícula -->
                    <div class="card">
                        <div class="card-header">
                            <span class="material-symbols-rounded card-header-icon">edit_note</span>
                            <h3>Estado de la Matrícula</h3>
                        </div>
                        <div class="card-body">
                            <div class="info-grid">
                                <div class="info-item">
                                    <span class="info-label">Matrícula</span>
                                    <span class="info-value">#<?= $m['id'] ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Estado actual</span>
                                    <?php $b = badge_estado($m['estado'] ?? 'activa'); ?>
                                    <span class="badge <?= $b['class'] ?>"><?= $b['label'] ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Fecha de matrícula</span>
                                    <span class="info-value"><?= date('d/m/Y', strtotime($m['fecha_matricula'])) ?></span>
                                </div>
                                <?php if ($m['fecha_inicio']): ?>
                                <div class="info-item">
                                    <span class="info-label">Fecha de inicio</span>
                                    <span class="info-value"><?= date('d/m/Y', strtotime($m['fecha_inicio'])) ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if ($m['fecha_retiro']): ?>
                                <div class="info-item">
                                    <span class="info-label">Fecha de retiro</span>
                                    <span class="info-value"><?= date('d/m/Y', strtotime($m['fecha_retiro'])) ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if ($m['preinscripcion_id']): ?>
                                <div class="info-item">
                                    <span class="info-label">Preinscripción</span>
                                    <span class="info-value">#<?= $m['preinscripcion_id'] ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if ($m['observaciones']): ?>
                                <div class="info-item full-width">
                                    <span class="info-label">Observaciones</span>
                                    <span class="info-value"><?= htmlspecialchars($m['observaciones']) ?></span>
                                </div>
                                <?php endif; ?>
                            </div>

                            <div class="action-section">
                                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                                    <button class="btn-outline" onclick="toggleSection('estado-<?= $m['id'] ?>')">
                                        <span class="material-symbols-rounded">swap_vert</span> Cambiar estado
                                    </button>
                                    <button class="btn-outline btn-outline--danger"
                                            onclick="abrirModalEliminar(<?= $m['id'] ?>, <?= $estudiante_id ?>,
                                                    '<?= htmlspecialchars(addslashes($m['curso_nombre'] ?? 'Sin curso')) ?>')">
                                        <span class="material-symbols-rounded">delete</span> Eliminar matrícula
                                    </button>
                                </div>

                                <div id="estado-<?= $m['id'] ?>" class="collapsible-section" style="display:none;">
                                    <form method="POST" action="acciones.php" class="form-inline-section">
                                        <input type="hidden" name="accion" value="cambiar_estado">
                                        <input type="hidden" name="matricula_id" value="<?= $m['id'] ?>">
                                        <input type="hidden" name="redir_estudiante" value="<?= $estudiante_id ?>">
                                        <div class="form-group">
                                            <label class="form-label">Nuevo estado</label>
                                            <select name="nuevo_estado" class="form-control" required>
                                                <option value="activa"     <?= ($m['estado']??'')==='activa'    ?'selected':'' ?>>Activa</option>
                                                <option value="suspendida" <?= ($m['estado']??'')==='suspendida'?'selected':'' ?>>Suspendida</option>
                                                <option value="graduado"   <?= ($m['estado']??'')==='graduado'  ?'selected':'' ?>>Graduado</option>
                                                <option value="retirado"   <?= ($m['estado']??'')==='retirado'  ?'selected':'' ?>>Retirado</option>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Observaciones</label>
                                            <textarea name="observaciones" class="form-control" rows="2"
                                                    placeholder="Motivo del cambio..."><?= htmlspecialchars($m['observaciones'] ?? '') ?></textarea>
                                        </div>
                                        <div class="form-actions">
                                            <button type="button" class="btn-secondary"
                                                    onclick="toggleSection('estado-<?= $m['id'] ?>')">Cancelar</button>
                                            <button type="submit" class="btn-primary">
                                                <span class="material-symbols-rounded">save</span> Guardar
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                </div><!-- /col-left -->

                <!-- ── Col derecha: Pagos ─────────────────────── -->
                <div class="detalle-col-right">
                    <div class="card">
                        <div class="card-header">
                            <span class="material-symbols-rounded card-header-icon">payments</span>
                            <h3>Registro de Pagos</h3>
                            <div class="ml-auto" style="display:flex; gap:8px;">
                                <?php if ($m['grupo_id'] && $m['precio_mensual']): ?>
                                <form method="POST" action="acciones.php" style="margin:0;">
                                    <input type="hidden" name="accion" value="regenerar_pagos">
                                    <input type="hidden" name="matricula_id" value="<?= $m['id'] ?>">
                                    <input type="hidden" name="redir_estudiante" value="<?= $estudiante_id ?>">
                                    <button type="submit" class="btn-outline"
                                            title="Elimina pagos pendientes y regenera las cuotas desde la fecha de matrícula"
                                            onclick="return confirm('¿Regenerar las cuotas pendientes? Se eliminarán los pagos pendientes actuales y se crearán de nuevo.')">
                                        <span class="material-symbols-rounded">refresh</span>
                                        Regenerar cuotas
                                    </button>
                                </form>
                                <?php endif; ?>
                                <button class="btn-primary btn-sm"
                                        onclick="toggleSection('pago-<?= $m['id'] ?>')">
                                    <span class="material-symbols-rounded">add</span> Registrar pago
                                </button>
                            </div>
                        </div>
                        <div class="card-body">

                            <!-- Resumen -->
                            <div class="pagos-resumen">
                                <div class="resumen-item resumen-total">
                                    <span class="resumen-value"><?= fmt_money($rp['monto_total']) ?></span>
                                    <span class="resumen-label">Total facturado</span>
                                </div>
                                <div class="resumen-item resumen-pagado">
                                    <span class="resumen-value"><?= fmt_money($rp['monto_pagado']) ?></span>
                                    <span class="resumen-label">Pagado</span>
                                </div>
                                <div class="resumen-item resumen-deuda">
                                    <span class="resumen-value"><?= fmt_money($rp['monto_pendiente']) ?></span>
                                    <span class="resumen-label">Pendiente</span>
                                </div>
                            </div>

                            <!-- Form nuevo pago -->
                            <div id="pago-<?= $m['id'] ?>" class="collapsible-section" style="display:none; margin-bottom:16px;">
                                <div class="form-section-title">Registrar nuevo pago</div>
                                <form method="POST" action="acciones.php" class="form-pago">
                                    <input type="hidden" name="accion" value="registrar_pago">
                                    <input type="hidden" name="matricula_id" value="<?= $m['id'] ?>">
                                    <input type="hidden" name="estudiante_id" value="<?= $estudiante_id ?>">
                                    <input type="hidden" name="redir_estudiante" value="<?= $estudiante_id ?>">
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label class="form-label">Concepto <span style="color:var(--primary-orange)">*</span></label>
                                            <input type="text" name="concepto" class="form-control"
                                                   placeholder="Ej: Mensualidad Marzo 2026" required>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Monto <span style="color:var(--primary-orange)">*</span></label>
                                            <div class="input-prefix-wrap">
                                                <span class="input-prefix">$</span>
                                                <input type="number" name="monto" class="form-control with-prefix"
                                                       value="<?= $m['precio_mensual'] ?? '' ?>"
                                                       placeholder="0" min="0" step="1000" required>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label class="form-label">Método <span style="color:var(--primary-orange)">*</span></label>
                                            <select name="metodo_pago" class="form-control" required>
                                                <option value="">— Selecciona —</option>
                                                <option value="efectivo">Efectivo</option>
                                                <option value="transferencia">Transferencia</option>
                                                <option value="tarjeta">Tarjeta</option>
                                                <option value="pse">PSE</option>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Estado <span style="color:var(--primary-orange)">*</span></label>
                                            <select name="estado" class="form-control" required>
                                                <option value="pagado">Pagado</option>
                                                <option value="pendiente">Pendiente</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label class="form-label">Fecha vencimiento</label>
                                            <input type="date" name="fecha_vencimiento" class="form-control"
                                                   value="<?= date('Y-m-t') ?>">
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Fecha de pago</label>
                                            <input type="date" name="fecha_pago" class="form-control"
                                                   value="<?= date('Y-m-d') ?>">
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Comprobante / Observaciones</label>
                                        <input type="text" name="observaciones" class="form-control"
                                               placeholder="Número de recibo, referencia…">
                                    </div>
                                    <div class="form-actions">
                                        <button type="button" class="btn-secondary"
                                                onclick="toggleSection('pago-<?= $m['id'] ?>')">Cancelar</button>
                                        <button type="submit" class="btn-primary">
                                            <span class="material-symbols-rounded">save</span> Guardar pago
                                        </button>
                                    </div>
                                </form>
                            </div>

                            <!-- Lista de pagos -->
                            <?php if (empty($pagos)): ?>
                            <div class="empty-pagos">
                                <span class="material-symbols-rounded">receipt_long</span>
                                <p>No hay pagos registrados aún</p>
                            </div>
                            <?php else: ?>
                            <div class="pagos-list">
                                <?php foreach ($pagos as $p):
                                    $bp = badge_pago($p['estado']); ?>
                                <div class="pago-item">
                                    <div class="pago-item-left">
                                        <div class="pago-concepto"><?= htmlspecialchars($p['concepto']) ?></div>
                                        <div class="pago-meta">
                                            <span><?= ucfirst($p['metodo_pago']) ?></span>
                                            <span>Vence: <?= date('d/m/Y', strtotime($p['fecha_vencimiento'])) ?></span>
                                            <?php if ($p['fecha_pago']): ?>
                                            <span>Pagado: <?= date('d/m/Y', strtotime($p['fecha_pago'])) ?></span>
                                            <?php endif; ?>
                                            <?php if ($p['registrado_por_nombre']): ?>
                                            <span>Por: <?= htmlspecialchars($p['registrado_por_nombre']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($p['observaciones']): ?>
                                        <div class="pago-obs"><?= htmlspecialchars($p['observaciones']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="pago-item-right">
                                        <div class="pago-monto"><?= fmt_money($p['monto']) ?></div>
                                        <span class="badge <?= $bp['class'] ?>"><?= $bp['label'] ?></span>

                                        <?php if (in_array($p['estado'], ['pendiente', 'vencido'])): ?>
                                        <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                            <form method="POST" action="acciones.php">
                                                <input type="hidden" name="accion" value="marcar_pagado">
                                                <input type="hidden" name="pago_id" value="<?= $p['id'] ?>">
                                                <input type="hidden" name="matricula_id" value="<?= $m['id'] ?>">
                                                <input type="hidden" name="redir_estudiante" value="<?= $estudiante_id ?>">
                                                <button type="submit" class="btn-xs btn-success">
                                                    <span class="material-symbols-rounded">check</span> Marcar pagado
                                                </button>
                                            </form>
                                            <button class="btn-xs btn-edit-pago"
                                                    onclick="abrirEditarPago(<?= htmlspecialchars(json_encode($p)) ?>, <?= $m['id'] ?>, <?= $estudiante_id ?>)">
                                                <span class="material-symbols-rounded">edit</span> Editar
                                            </button>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>

                        </div>
                    </div>
                </div><!-- /col-right -->

            </div><!-- /detalle-grid -->
        </div><!-- /tab-panel -->

        <?php endforeach; ?>
    </div><!-- /tabs-wrap -->

</main>

<!-- ══ MODALES de asignación de grupo (uno por matrícula) ═══ -->
<?php foreach ($matriculas as $m):
    $grupos_disponibles = $grupos_disponibles_mat[$m['id']];
    $cursos_grupos      = $cursos_grupos_mat[$m['id']];
    if (empty($grupos_disponibles)) continue;
    $dias_abbr = ['lunes'=>'Lun','martes'=>'Mar','miercoles'=>'Mié','jueves'=>'Jue','viernes'=>'Vie','sabado'=>'Sáb','domingo'=>'Dom'];
?>
<div class="modal-overlay" id="modalGrupo-<?= $m['id'] ?>">
    <div class="modal modal-grupo">
        <div class="modal-grupo-header">
            <div>
                <h2 class="modal-grupo-titulo">
                    <span class="material-symbols-rounded"><?= $m['grupo_nombre'] ? 'swap_horiz' : 'group_add' ?></span>
                    <?= $m['grupo_nombre'] ? 'Cambiar grupo' : 'Asignar grupo' ?>
                </h2>
                <p class="modal-grupo-sub">
                    <?= htmlspecialchars($m['curso_nombre'] ?? 'Matrícula') ?> · #<?= $m['id'] ?>
                </p>
            </div>
            <button class="modal-close" onclick="cerrarModalGrupo('<?= $m['id'] ?>')">
                <span class="material-symbols-rounded">close</span>
            </button>
        </div>
        <div class="mg-filtros">
            <div class="mg-buscar">
                <span class="material-symbols-rounded">search</span>
                <input type="text" id="mgBuscar-<?= $m['id'] ?>"
                       placeholder="Buscar grupo o profesor…"
                       oninput="filtrarGrupos('<?= $m['id'] ?>')">
            </div>
            <select id="mgCurso-<?= $m['id'] ?>" class="mg-select"
                    onchange="filtrarGrupos('<?= $m['id'] ?>')">
                <option value="">Todos los cursos</option>
                <?php foreach (array_keys($cursos_grupos) as $cn): ?>
                <option value="<?= htmlspecialchars($cn) ?>"><?= htmlspecialchars($cn) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mg-body">
            <form method="POST" action="acciones.php" id="formGrupo-<?= $m['id'] ?>">
                <input type="hidden" name="accion" value="<?= $m['grupo_nombre'] ? 'cambiar_grupo' : 'asignar_grupo' ?>">
                <input type="hidden" name="matricula_id" value="<?= $m['id'] ?>">
                <input type="hidden" name="redir_estudiante" value="<?= $estudiante_id ?>">
                <input type="hidden" name="grupo_id" id="grupoId-<?= $m['id'] ?>" value="">

                <div class="mg-grid" id="mgGrid-<?= $m['id'] ?>">
                    <?php foreach ($grupos_disponibles as $g):
                        $pct = $g['cupo_maximo'] > 0 ? round(($g['cupo_actual'] / $g['cupo_maximo']) * 100) : 0;
                        $bar = $pct >= 90 ? 'bar-danger' : ($pct >= 70 ? 'bar-warning' : 'bar-success');
                    ?>
                    <div class="mg-card"
                         data-id="<?= $g['id'] ?>"
                         data-curso="<?= htmlspecialchars($g['curso_nombre']) ?>"
                         data-texto="<?= strtolower($g['nombre'].' '.($g['profesor_nombre']??'').' '.$g['curso_nombre']) ?>"
                         onclick="seleccionarGrupo(this, '<?= $m['id'] ?>')">
                        <div class="mg-card-top">
                            <div class="mg-card-icono"><span class="material-symbols-rounded">groups</span></div>
                            <div class="mg-card-info">
                                <div class="mg-card-nombre"><?= htmlspecialchars($g['nombre']) ?></div>
                                <div class="mg-card-curso">
                                    <span class="mg-nivel mg-nivel-<?= $g['curso_nivel'] ?>"><?= nivel_label($g['curso_nivel']) ?></span>
                                    <?= htmlspecialchars($g['curso_nombre']) ?>
                                </div>
                            </div>
                            <div class="mg-card-check"><span class="material-symbols-rounded">check_circle</span></div>
                        </div>
                        <?php if ($g['profesor_nombre']): ?>
                        <div class="mg-card-prof">
                            <span class="material-symbols-rounded">person_play</span>
                            <?= htmlspecialchars($g['profesor_nombre']) ?>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($g['horarios_lista'])): ?>
                        <div class="mg-horarios">
                            <?php foreach ($g['horarios_lista'] as $h): ?>
                            <div class="mg-horario-chip">
                                <span class="mg-dia"><?= $dias_abbr[$h['dia']] ?? ucfirst($h['dia']) ?></span>
                                <span class="mg-hora"><?= $h['inicio'] ?> – <?= $h['fin'] ?></span>
                                <?php if ($h['aula']): ?><span class="mg-aula"><?= htmlspecialchars($h['aula']) ?></span><?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php elseif ($g['horario']): ?>
                        <div class="mg-horarios">
                            <div class="mg-horario-chip">
                                <span class="material-symbols-rounded" style="font-size:13px;">schedule</span>
                                <span class="mg-hora"><?= htmlspecialchars($g['horario']) ?></span>
                            </div>
                        </div>
                        <?php endif; ?>
                        <div class="mg-cupos">
                            <div class="mg-cupos-texto">
                                <span class="material-symbols-rounded">people</span>
                                <span><?= $g['cupo_actual'] ?>/<?= $g['cupo_maximo'] ?></span>
                                <span class="mg-cupos-libre"><?= $g['cupo_maximo'] - $g['cupo_actual'] ?> disponibles</span>
                            </div>
                            <div class="mg-bar"><div class="mg-bar-fill <?= $bar ?>" style="width:<?= $pct ?>%"></div></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="mg-empty" id="mgEmpty-<?= $m['id'] ?>" style="display:none;">
                    <span class="material-symbols-rounded">search_off</span>
                    <p>No hay grupos que coincidan</p>
                </div>
            </form>
        </div>
        <div class="mg-footer">
            <div class="mg-seleccion-info" id="mgInfo-<?= $m['id'] ?>">
                <span class="material-symbols-rounded">info</span>
                Selecciona un grupo para continuar
            </div>
            <div class="mg-footer-btns">
                <button class="btn-secondary" onclick="cerrarModalGrupo('<?= $m['id'] ?>')">Cancelar</button>
                <button class="btn-primary" id="btnGrupo-<?= $m['id'] ?>" disabled
                        onclick="confirmarGrupo('<?= $m['id'] ?>')">
                    <span class="material-symbols-rounded">check</span> Confirmar
                </button>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

<!-- ══ Modal: Eliminar Matrícula ════════════════════════════ -->
<div class="modal-overlay" id="modalEliminarMatricula">
    <div class="modal" style="max-width:460px; text-align:left; padding:0;">
        <div style="display:flex; align-items:center; gap:10px; padding:20px 24px;
                    border-bottom:1px solid var(--border-color); background:var(--hover-bg);
                    border-radius:18px 18px 0 0;">
            <div class="modal-icon modal-icon-danger" style="width:40px;height:40px;border-radius:10px;flex-shrink:0;">
                <span class="material-symbols-rounded">delete_forever</span>
            </div>
            <div>
                <div class="modal-title" style="margin:0; font-size:1rem;">Eliminar matrícula</div>
                <div style="font-size:0.8rem; color:var(--text-secondary); margin-top:2px;"
                     id="eliminarModalSubtitulo"></div>
            </div>
            <button class="modal-close" style="margin-left:auto;" onclick="cerrarModalEliminar()">
                <span class="material-symbols-rounded">close</span>
            </button>
        </div>
        <div style="padding:20px 24px;">
            <div class="alert alert-danger" style="margin-bottom:16px;">
                <span class="material-symbols-rounded">warning</span>
                <span>Esta acción es <strong>irreversible</strong>. Se eliminarán la matrícula y todos sus datos asociados (asistencias, calificaciones). Los pagos quedarán anulados en el historial.</span>
            </div>
            <div class="form-group">
                <label class="form-label">
                    Para confirmar, escribe <strong style="color:var(--primary-orange)">eliminar</strong> en el campo:
                </label>
                <input type="text" id="eliminarConfirmInput" class="form-control"
                       placeholder="eliminar"
                       oninput="validarConfirmEliminar(this.value)">
            </div>
        </div>
        <form method="POST" action="acciones.php" id="formEliminarMatricula">
            <input type="hidden" name="accion" value="eliminar_matricula">
            <input type="hidden" name="matricula_id" id="eliminarMatriculaId">
            <input type="hidden" name="redir_estudiante" id="eliminarEstudianteId">
            <input type="hidden" name="confirmacion" id="eliminarConfirmHidden">
            <div style="display:flex; gap:10px; justify-content:flex-end; flex-wrap:wrap;
                        padding:16px 24px; border-top:1px solid var(--border-color);
                        background:var(--hover-bg); border-radius:0 0 18px 18px;">
                <button type="button" class="btn-secondary" onclick="cerrarModalEliminar()">Cancelar</button>
                <button type="submit" class="btn-danger-solid" id="btnConfirmarEliminar" disabled>
                    <span class="material-symbols-rounded">delete_forever</span> Sí, eliminar
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ══ Modal: Editar Pago ═══════════════════════════════════ -->
<div class="modal-overlay" id="modalEditarPago">
    <div class="modal modal--sm">
        <div class="modal-header modal-header--primary">
            <h2 class="modal-title">
                <span class="material-symbols-rounded">edit</span>
                Editar Pago
            </h2>
            <button class="modal-close" onclick="cerrarEditarPago()">
                <span class="material-symbols-rounded">close</span>
            </button>
        </div>
        <form method="POST" action="acciones.php" id="formEditarPago">
            <input type="hidden" name="accion" value="editar_pago">
            <input type="hidden" name="pago_id" id="editPagoId">
            <input type="hidden" name="matricula_id" id="editMatriculaId">
            <input type="hidden" name="redir_estudiante" id="editEstudianteId">
            <div class="modal-body" style="display:flex; flex-direction:column; gap:14px;">
                <div class="form-group">
                    <label class="form-label">Concepto</label>
                    <input type="text" name="concepto" id="editConcepto" class="form-control" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Monto <span style="color:var(--primary-orange)">*</span></label>
                        <div class="input-prefix-wrap">
                            <span class="input-prefix">$</span>
                            <input type="number" name="monto" id="editMonto" class="form-control with-prefix"
                                   min="0" step="1000" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Método de pago <span style="color:var(--primary-orange)">*</span></label>
                        <select name="metodo_pago" id="editMetodo" class="form-control" required>
                            <option value="efectivo">Efectivo</option>
                            <option value="transferencia">Transferencia</option>
                            <option value="tarjeta">Tarjeta</option>
                            <option value="pse">PSE</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Fecha de vencimiento</label>
                        <input type="date" name="fecha_vencimiento" id="editFechaVence" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Estado</label>
                        <select name="estado" id="editEstado" class="form-control">
                            <option value="pendiente">Pendiente</option>
                            <option value="vencido">Vencido</option>
                            <option value="pagado">Pagado</option>
                            <option value="anulado">Anulado</option>
                        </select>
                    </div>
                </div>
                <div class="form-group" id="editFechaPagoWrap">
                    <label class="form-label">Fecha de pago</label>
                    <input type="date" name="fecha_pago" id="editFechaPago" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label">Comprobante / Observaciones</label>
                    <input type="text" name="observaciones" id="editObs" class="form-control"
                           placeholder="N° de recibo, referencia, etc.">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="cerrarEditarPago()">Cancelar</button>
                <button type="submit" class="btn-primary">
                    <span class="material-symbols-rounded">save</span> Guardar cambios
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ══ Modal: Nueva Matrícula ═══════════════════════════════ -->
<div class="modal-overlay" id="modalNuevaMatricula">
    <div class="modal modal-nueva-matricula">

        <div class="modal-nueva-header">
            <div style="display:flex; align-items:center; gap:12px;">
                <div class="modal-icon modal-icon-blue" style="margin:0; width:40px; height:40px; border-radius:10px; flex-shrink:0;">
                    <span class="material-symbols-rounded">person_add</span>
                </div>
                <div>
                    <h3 class="modal-title" style="margin:0; text-align:left; font-size:1.05rem;">Nueva Matrícula</h3>
                    <p style="font-size:0.8rem; color:var(--text-secondary); margin:0;">
                        Para: <strong><?= htmlspecialchars($estudiante['nombre']) ?></strong>
                    </p>
                </div>
            </div>
            <button class="modal-close-btn" onclick="cerrarModalNuevaMatricula()" type="button">
                <span class="material-symbols-rounded">close</span>
            </button>
        </div>

        <div class="modal-nueva-body">

            <div class="nueva-mat-info">
                <span class="material-symbols-rounded">auto_awesome</span>
                <div>
                    <strong>Generación automática de pagos</strong>
                    <p>Al seleccionar un grupo se generará la cuota del mes actual, con vencimiento el mismo día de hoy.</p>
                </div>
            </div>

            <form method="POST" action="nueva.php" class="form-nueva-matricula" id="formNuevaModal">

                <!-- El estudiante viene pre-seleccionado desde el contexto del detalle -->
                <input type="hidden" name="estudiante_id" value="<?= $estudiante_id ?>">

                <!-- Info del estudiante pre-seleccionado -->
                <div class="form-group">
                    <label class="form-label">Estudiante</label>
                    <div style="display:flex; align-items:center; gap:10px; padding:10px 12px;
                                border-radius:9px; background:var(--hover-bg);
                                border:1.5px solid var(--border-color);">
                        <div class="ep-avatar" style="width:32px; height:32px; font-size:0.9rem; flex-shrink:0;">
                            <?= mb_strtoupper(mb_substr($estudiante['nombre'], 0, 1)) ?>
                        </div>
                        <div>
                            <div style="font-size:0.88rem; font-weight:600; color:var(--text-primary);">
                                <?= htmlspecialchars($estudiante['nombre']) ?>
                            </div>
                            <div style="font-size:0.78rem; color:var(--text-secondary);">
                                <?= htmlspecialchars($estudiante['email']) ?>
                                <?php if ($estudiante['documento']): ?>
                                · <?= htmlspecialchars($estudiante['documento']) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <span class="badge badge-success-soft" style="margin-left:auto;">
                            <span class="material-symbols-rounded" style="font-size:12px;">check_circle</span>
                            Seleccionado
                        </span>
                    </div>
                    <span class="form-hint">El estudiante se asigna automáticamente desde este perfil</span>
                </div>

                <div class="form-group">
                    <label class="form-label">
                        Grupo
                        <span class="form-hint" style="display:inline; text-transform:none; font-size:0.72rem;">(opcional)</span>
                    </label>
                    <select name="grupo_id" id="selectGrupoModalDetalle" class="form-control"
                            style="width:100%; min-width:0;"
                            onchange="mostrarInfoGrupoModalDetalle(this)">
                        <option value="">— Sin grupo asignado —</option>
                        <?php foreach ($grupos_por_curso_modal as $curso_nombre => $gs): ?>
                        <optgroup label="<?= htmlspecialchars($curso_nombre) ?>">
                            <?php foreach ($gs as $g): ?>
                            <option value="<?= $g['id'] ?>"
                                    data-precio="<?= $g['precio_mensual'] ?>"
                                    data-meses="<?= $g['duracion_meses'] ?>"
                                    data-curso="<?= htmlspecialchars($g['curso_nombre']) ?>">
                                <?= htmlspecialchars($g['nombre']) ?>
                                · <?= htmlspecialchars($g['horario'] ?? '—') ?>
                                (<?= $g['cupo_actual'] ?>/<?= $g['cupo_maximo'] ?> cupos)
                                <?= $g['profesor_nombre'] ? '· '.$g['profesor_nombre'] : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endforeach; ?>
                    </select>
                    <div id="infoGrupoModalDetalle" class="grupo-preview" style="display:none;"></div>
                </div>

                <div class="form-group">
                    <label class="form-label">Fecha de inicio</label>
                    <input type="date" name="fecha_inicio" class="form-control"
                           style="width:100%; min-width:0;"
                           value="<?= date('Y-m-d') ?>">
                </div>

                <div class="form-group">
                    <label class="form-label">Observaciones</label>
                    <textarea name="observaciones" class="form-control" rows="2"
                              style="width:100%; min-width:0;"
                              placeholder="Información adicional sobre la matrícula…"></textarea>
                </div>

            </form>
        </div>

        <div class="modal-nueva-footer">
            <button type="button" class="btn-secondary" onclick="cerrarModalNuevaMatricula()">Cancelar</button>
            <button type="submit" form="formNuevaModal" class="btn-primary">
                <span class="material-symbols-rounded">save</span> Crear matrícula
            </button>
        </div>

    </div>
</div>

<script>
// ── Pestañas ─────────────────────────────────────────────────
function cambiarTab(tabId, btn) {
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('tab-panel--active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('tab-btn--active'));
    document.getElementById(tabId)?.classList.add('tab-panel--active');
    btn.classList.add('tab-btn--active');
}

// ── Colapsables ──────────────────────────────────────────────
function toggleSection(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = el.style.display !== 'none' ? 'none' : 'block';
}

// ── Modal editar pago ────────────────────────────────────────
function abrirEditarPago(pago, matriculaId, estudianteId) {
    document.getElementById('editPagoId').value        = pago.id;
    document.getElementById('editMatriculaId').value   = matriculaId;
    document.getElementById('editEstudianteId').value  = estudianteId;
    document.getElementById('editConcepto').value      = pago.concepto;
    document.getElementById('editMonto').value         = pago.monto;
    document.getElementById('editMetodo').value        = pago.metodo_pago;
    document.getElementById('editFechaVence').value    = pago.fecha_vencimiento;
    document.getElementById('editEstado').value        = pago.estado;
    document.getElementById('editFechaPago').value     = pago.fecha_pago || '';
    document.getElementById('editObs').value           = pago.observaciones || '';
    toggleFechaPago(pago.estado);
    document.getElementById('modalEditarPago').classList.add('active');
    document.body.style.overflow = 'hidden';
}
function cerrarEditarPago() {
    document.getElementById('modalEditarPago').classList.remove('active');
    document.body.style.overflow = '';
}
function toggleFechaPago(estado) {
    const wrap = document.getElementById('editFechaPagoWrap');
    wrap.style.display = estado === 'pagado' ? 'flex' : 'none';
    if (estado === 'pagado' && !document.getElementById('editFechaPago').value) {
        document.getElementById('editFechaPago').value = new Date().toISOString().split('T')[0];
    }
}
document.getElementById('editEstado')?.addEventListener('change', function() {
    toggleFechaPago(this.value);
});
document.getElementById('modalEditarPago')?.addEventListener('click', e => {
    if (e.target === e.currentTarget) cerrarEditarPago();
});

// ── Modal grupo ──────────────────────────────────────────────
function abrirModalGrupo(matId) {
    document.getElementById('modalGrupo-' + matId)?.classList.add('active');
    document.body.style.overflow = 'hidden';
}
function cerrarModalGrupo(matId) {
    const modal = document.getElementById('modalGrupo-' + matId);
    if (!modal) return;
    modal.classList.remove('active');
    document.body.style.overflow = '';
    modal.querySelectorAll('.mg-card.selected').forEach(c => c.classList.remove('selected'));
    document.getElementById('grupoId-' + matId).value = '';
    document.getElementById('btnGrupo-' + matId).disabled = true;
    document.getElementById('mgInfo-' + matId).innerHTML =
        '<span class="material-symbols-rounded">info</span> Selecciona un grupo para continuar';
    const b = document.getElementById('mgBuscar-' + matId); if (b) b.value = '';
    const s = document.getElementById('mgCurso-' + matId);  if (s) s.value = '';
    filtrarGrupos(matId);
}
document.querySelectorAll('[id^="modalGrupo-"]').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) cerrarModalGrupo(m.id.replace('modalGrupo-','')); });
});

function seleccionarGrupo(card, matId) {
    document.querySelectorAll('#mgGrid-' + matId + ' .mg-card.selected').forEach(c => c.classList.remove('selected'));
    card.classList.add('selected');
    document.getElementById('grupoId-' + matId).value = card.dataset.id;
    document.getElementById('btnGrupo-' + matId).disabled = false;
    document.getElementById('mgInfo-' + matId).innerHTML =
        '<span class="material-symbols-rounded" style="color:var(--primary-green)">check_circle</span> ' +
        'Seleccionado: <strong>' + card.querySelector('.mg-card-nombre').textContent + '</strong>';
}
function confirmarGrupo(matId) {
    if (!document.getElementById('grupoId-' + matId).value) return;
    document.getElementById('formGrupo-' + matId).submit();
}
function filtrarGrupos(matId) {
    const q     = (document.getElementById('mgBuscar-' + matId)?.value || '').toLowerCase();
    const curso = (document.getElementById('mgCurso-' + matId)?.value  || '').toLowerCase();
    let vis = 0;
    document.querySelectorAll('#mgGrid-' + matId + ' .mg-card').forEach(c => {
        const ok = (!q || c.dataset.texto.includes(q)) && (!curso || c.dataset.curso.toLowerCase() === curso);
        c.style.display = ok ? '' : 'none';
        if (ok) vis++;
    });
    document.getElementById('mgEmpty-' + matId).style.display = vis === 0 ? 'flex' : 'none';
}

// ── Modal eliminar matrícula ─────────────────────────────────
function abrirModalEliminar(matriculaId, estudianteId, cursoNombre) {
    document.getElementById('eliminarMatriculaId').value  = matriculaId;
    document.getElementById('eliminarEstudianteId').value = estudianteId;
    document.getElementById('eliminarConfirmInput').value = '';
    document.getElementById('eliminarConfirmHidden').value = '';
    document.getElementById('btnConfirmarEliminar').disabled = true;
    document.getElementById('eliminarModalSubtitulo').textContent =
        'Matrícula #' + matriculaId + ' — ' + cursoNombre;
    document.getElementById('modalEliminarMatricula').classList.add('active');
    document.body.style.overflow = 'hidden';
    setTimeout(() => document.getElementById('eliminarConfirmInput').focus(), 200);
}
function cerrarModalEliminar() {
    document.getElementById('modalEliminarMatricula').classList.remove('active');
    document.body.style.overflow = '';
}
function validarConfirmEliminar(val) {
    const ok = val.trim().toLowerCase() === 'eliminar';
    document.getElementById('btnConfirmarEliminar').disabled = !ok;
    document.getElementById('eliminarConfirmHidden').value    = val.trim();
}
document.getElementById('modalEliminarMatricula')?.addEventListener('click', e => {
    if (e.target === e.currentTarget) cerrarModalEliminar();
});

// ── Modal nueva matrícula ────────────────────────────────────
function abrirModalNuevaMatricula() {
    document.getElementById('modalNuevaMatricula').classList.add('active');
    document.body.style.overflow = 'hidden';
}
function cerrarModalNuevaMatricula() {
    document.getElementById('modalNuevaMatricula').classList.remove('active');
    document.body.style.overflow = '';
}
document.getElementById('modalNuevaMatricula')?.addEventListener('click', e => {
    if (e.target === e.currentTarget) cerrarModalNuevaMatricula();
});

// Preview pago en el modal de detalle
function mostrarInfoGrupoModalDetalle(sel) {
    const opt    = sel.options[sel.selectedIndex];
    const info   = document.getElementById('infoGrupoModalDetalle');
    const precio = parseFloat(opt.dataset.precio || 0);

    if (!sel.value || precio <= 0) { info.style.display = 'none'; return; }

    const fmt      = n => '$' + Math.round(n).toLocaleString('es-CO');
    const hoy      = new Date();
    const mesesNom = ['Enero','Febrero','Marzo','Abril','Mayo','Junio',
                      'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];

    info.style.display = 'flex';
    info.innerHTML = `
        <span class="material-symbols-rounded">auto_awesome</span>
        <div>
            <strong>Se generará 1 pago al matricular</strong>
            <p>Mensualidad <strong>${mesesNom[hoy.getMonth()]} ${hoy.getFullYear()}</strong> · ${fmt(precio)} · Vence el día <strong>${hoy.getDate()}</strong>.</p>
        </div>
    `;
}

// Cerrar todos los modales con Escape
document.addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    if (document.getElementById('modalNuevaMatricula').classList.contains('active'))   cerrarModalNuevaMatricula();
    if (document.getElementById('modalEditarPago').classList.contains('active'))       cerrarEditarPago();
    if (document.getElementById('modalEliminarMatricula').classList.contains('active')) cerrarModalEliminar();
});

// Reabrir modal si nueva.php redirige con ?open_modal=1
<?php if ($open_modal): ?>
document.addEventListener('DOMContentLoaded', () => abrirModalNuevaMatricula());
<?php endif; ?>

// Auto-ocultar flash
setTimeout(() => {
    const a = document.getElementById('flashAlert');
    if (a) { a.style.transition='opacity .5s'; a.style.opacity='0'; setTimeout(()=>a.remove(),500); }
}, 5000);
</script>
</body>
</html>