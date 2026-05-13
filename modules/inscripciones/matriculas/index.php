<?php
/**
 * Módulo de Matrículas — Listado agrupado por estudiante
 */
require_once '../../../config/session.php';
require_once '../../../config/database.php';
require_once '../../../includes/auth_check.php';

require_role('admin');

function get_foto_url(?string $foto): string {
    if (empty($foto)) return '';
    return '../../../assets/img/avatars/' . htmlspecialchars($foto);
}

$buscar        = trim($_GET['buscar']  ?? '');
$filtro_estado = $_GET['estado']       ?? '';
$filtro_pago   = $_GET['pago']         ?? '';
$pagina        = max(1, (int)($_GET['pagina'] ?? 1));
$por_pagina    = 12;
$offset        = ($pagina - 1) * $por_pagina;

$where_parts = $filtro_estado === '' ? ["m.estado != 'retirado'"] : [];
$params      = [];
if ($buscar !== '') {
    $where_parts[] = "(u.nombre LIKE ? OR u.email LIKE ? OR c.nombre LIKE ? OR u.documento LIKE ?)";
    $like = "%$buscar%";
    $params = array_merge($params, [$like, $like, $like, $like]);
}
if ($filtro_estado !== '') { $where_parts[] = "m.estado = ?"; $params[] = $filtro_estado; }
$where_sql = 'WHERE ' . implode(' AND ', $where_parts);

$having_sql = match($filtro_pago) {
    'al_dia'    => 'HAVING SUM(p_pend.id IS NOT NULL) = 0',
    'pendiente' => 'HAVING SUM(p_pend.id IS NOT NULL AND p_pend.estado = \'pendiente\') > 0
                    AND SUM(p_pend.id IS NOT NULL AND p_pend.estado = \'vencido\') = 0',
    'vencido'   => 'HAVING SUM(p_pend.id IS NOT NULL AND p_pend.estado = \'vencido\') > 0',
    default     => '',
};

try {
    // Stats generales
    $total_activas    = (int)$pdo->query("SELECT COUNT(*) FROM matriculas WHERE estado='activa'")->fetchColumn();
    $total_al_dia     = (int)$pdo->query("SELECT COUNT(*) FROM (SELECT m.id FROM matriculas m LEFT JOIN pagos p ON p.matricula_id=m.id AND p.estado IN('pendiente','vencido') WHERE m.estado='activa' GROUP BY m.id HAVING COUNT(p.id)=0) s")->fetchColumn();
    $total_pendientes = (int)$pdo->query("SELECT COUNT(DISTINCT m.id) FROM matriculas m INNER JOIN pagos p ON p.matricula_id=m.id AND p.estado IN('pendiente','vencido') WHERE m.estado='activa'")->fetchColumn();
    $total_vencidos   = (int)$pdo->query("SELECT COUNT(DISTINCT m.id) FROM matriculas m INNER JOIN pagos p ON p.matricula_id=m.id AND p.estado='vencido' WHERE m.estado='activa'")->fetchColumn();
    $total_sin_grupo  = (int)$pdo->query("SELECT COUNT(*) FROM matriculas WHERE estado='activa' AND grupo_id IS NULL")->fetchColumn();

    // Contar estudiantes únicos con las condiciones
    $sql_count = "
        SELECT COUNT(*) FROM (
            SELECT u.id
            FROM matriculas m
            INNER JOIN usuarios u ON m.estudiante_id = u.id
            LEFT JOIN grupos g ON m.grupo_id = g.id
            LEFT JOIN cursos c ON g.curso_id = c.id
            LEFT JOIN pagos p_pend ON p_pend.matricula_id = m.id AND p_pend.estado IN('pendiente','vencido')
            $where_sql
            GROUP BY u.id
            $having_sql
        ) sub
    ";
    $stmtC = $pdo->prepare($sql_count);
    $stmtC->execute($params);
    $total_estudiantes = (int)$stmtC->fetchColumn();
    $total_paginas     = max(1, (int)ceil($total_estudiantes / $por_pagina));
    $pagina            = min($pagina, $total_paginas);

    // Listado de estudiantes con resumen
    $sql = "
        SELECT
            u.id as estudiante_id,
            u.nombre as estudiante_nombre,
            u.email as estudiante_email,
            u.documento as estudiante_documento,
            u.foto_perfil as estudiante_foto,
            COUNT(DISTINCT m.id)   AS total_matriculas,
            SUM(m.estado = 'activa')      AS matriculas_activas,
            SUM(m.estado = 'suspendida')  AS matriculas_suspendidas,
            SUM(m.grupo_id IS NULL)       AS sin_grupo,
            SUM(p_pend.id IS NOT NULL)    AS pagos_pendientes,
            SUM(p_pend.id IS NOT NULL AND p_pend.estado = 'vencido') AS pagos_vencidos,
            GROUP_CONCAT(
                DISTINCT c.nombre
                ORDER BY c.nombre
                SEPARATOR ', '
            ) AS cursos_lista,
            MAX(m.fecha_matricula) AS ultima_matricula
        FROM matriculas m
        INNER JOIN usuarios u ON m.estudiante_id = u.id
        LEFT JOIN grupos g ON m.grupo_id = g.id
        LEFT JOIN cursos c ON g.curso_id = c.id
        LEFT JOIN pagos p_pend ON p_pend.matricula_id = m.id AND p_pend.estado IN('pendiente','vencido')
        $where_sql
        GROUP BY u.id, u.nombre, u.email, u.documento
        $having_sql
        ORDER BY u.nombre ASC
        LIMIT $por_pagina OFFSET $offset
    ";
    $stmtL = $pdo->prepare($sql);
    $stmtL->execute($params);
    $estudiantes_lista = $stmtL->fetchAll(PDO::FETCH_ASSOC);

    // ── Datos para el modal de nueva matrícula ──────────────────
    $estudiantes = $pdo->query("
        SELECT id, nombre, email, documento
        FROM usuarios
        WHERE rol = 'estudiante' AND estado = 'activo'
        ORDER BY nombre
    ")->fetchAll(PDO::FETCH_ASSOC);

    $grupos_raw = $pdo->query("
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

    $grupos_por_curso = [];
    foreach ($grupos_raw as $g) $grupos_por_curso[$g['curso_nombre']][] = $g;

} catch (PDOException $e) {
    error_log($e->getMessage());
    $estudiantes_lista = [];
    $estudiantes       = [];
    $grupos_por_curso  = [];
    $total_activas = $total_al_dia = $total_pendientes = $total_vencidos = $total_sin_grupo = 0;
    $total_estudiantes = $total_paginas = 0;
}

// Flash desde acciones o desde nueva.php
$flash_msg  = $_GET['msg']  ?? '';
$flash_type = $_GET['type'] ?? '';
// Si viene del formulario del modal con error, reabrir el modal automáticamente
$open_modal = isset($_GET['open_modal']) && $_GET['open_modal'] === '1';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Matrículas — Amimbré</title>
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
        <div class="header-left">
            <button class="btn-back" onclick="window.history.back()">
                <span class="material-symbols-rounded">arrow_back</span>
            </button>
            <div class="dashboard-title">
                <h1>Matrículas</h1>
                <p>Gestiona los estudiantes matriculados, sus grupos y pagos</p>
            </div>
        </div>
        <div class="header-actions">
            <button type="button" class="btn-primary" onclick="abrirModalNuevaMatricula()">
                <span class="material-symbols-rounded">add</span> Nueva Matrícula
            </button>
        </div>
    </div>

    <!-- Flash -->
    <?php if ($flash_msg): ?>
    <div class="alert alert-<?= $flash_type === 'success' ? 'success' : 'danger' ?>" id="alertFlash">
        <span class="material-symbols-rounded"><?= $flash_type === 'success' ? 'check_circle' : 'error' ?></span>
        <?= htmlspecialchars($flash_msg) ?>
    </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stats-grid">
        <div class="stat-card stat-blue">
            <div class="stat-icon-wrap"><span class="material-symbols-rounded">people</span></div>
            <div class="stat-info"><span class="stat-value"><?= $total_activas ?></span><span class="stat-label">Matrículas activas</span></div>
        </div>
        <div class="stat-card stat-green">
            <div class="stat-icon-wrap"><span class="material-symbols-rounded">check_circle</span></div>
            <div class="stat-info"><span class="stat-value"><?= $total_al_dia ?></span><span class="stat-label">Al día en pagos</span></div>
        </div>
        <div class="stat-card stat-yellow">
            <div class="stat-icon-wrap"><span class="material-symbols-rounded">warning</span></div>
            <div class="stat-info"><span class="stat-value"><?= $total_pendientes ?></span><span class="stat-label">Con pagos pendientes</span></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon-wrap" style="background:var(--hover-bg);color:var(--text-secondary);">
                <span class="material-symbols-rounded">group_off</span>
            </div>
            <div class="stat-info"><span class="stat-value"><?= $total_sin_grupo ?></span><span class="stat-label">Sin grupo asignado</span></div>
        </div>
    </div>

    <!-- Filtros -->
    <div class="filters-bar">
        <form method="GET" class="filters-form" id="filtrosForm">
            <div class="search-input-wrap">
                <span class="material-symbols-rounded search-icon">search</span>
                <input type="text" name="buscar" class="search-input"
                       placeholder="Buscar por nombre, email, documento o curso…"
                       value="<?= htmlspecialchars($buscar) ?>" autocomplete="off">
                <?php if ($buscar): ?>
                <button type="button" class="clear-search"
                        onclick="document.querySelector('[name=buscar]').value='';document.getElementById('filtrosForm').submit()">
                    <span class="material-symbols-rounded">close</span>
                </button>
                <?php endif; ?>
            </div>
            <div class="filters-right">
                <select name="estado" class="filter-select" onchange="this.form.submit()">
                    <option value="">Todos los estados</option>
                    <option value="activa"     <?= $filtro_estado==='activa'     ?'selected':'' ?>>Activa</option>
                    <option value="suspendida" <?= $filtro_estado==='suspendida' ?'selected':'' ?>>Suspendida</option>
                    <option value="graduado"   <?= $filtro_estado==='graduado'   ?'selected':'' ?>>Graduado</option>
                    <option value="retirado"   <?= $filtro_estado==='retirado'   ?'selected':'' ?>>Retirado</option>
                </select>
                <select name="pago" class="filter-select" onchange="this.form.submit()">
                    <option value="">Todos los pagos</option>
                    <option value="al_dia"    <?= $filtro_pago==='al_dia'    ?'selected':'' ?>>Al día</option>
                    <option value="pendiente" <?= $filtro_pago==='pendiente' ?'selected':'' ?>>Con pendientes</option>
                    <option value="vencido"   <?= $filtro_pago==='vencido'   ?'selected':'' ?>>Con vencidos</option>
                </select>
                <button type="submit" class="btn-search">
                    <span class="material-symbols-rounded">filter_list</span> Filtrar
                </button>
                <?php if ($buscar || $filtro_estado || $filtro_pago): ?>
                <a href="index.php" class="btn-secondary" style="padding:8px 14px;font-size:0.82rem;">
                    <span class="material-symbols-rounded">close</span> Limpiar
                </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Contador de resultados -->
    <?php if (!empty($estudiantes_lista)): ?>
    <div class="resultados-info">
        <?= $total_estudiantes ?> estudiante<?= $total_estudiantes != 1 ? 's' : '' ?> encontrado<?= $total_estudiantes != 1 ? 's' : '' ?>
        <?= $buscar ? '· búsqueda: "<strong>' . htmlspecialchars($buscar) . '</strong>"' : '' ?>
    </div>
    <?php endif; ?>

    <!-- Listado agrupado por estudiante -->
    <div class="matriculas-container">
    <?php if (empty($estudiantes_lista)): ?>
        <div class="empty-state">
            <span class="material-symbols-rounded empty-icon">search_off</span>
            <h3>No se encontraron matrículas</h3>
            <p>Intenta ajustar los filtros o crea una nueva matrícula</p>
            <?php if ($buscar || $filtro_estado || $filtro_pago): ?>
            <a href="index.php" class="btn-secondary">Limpiar filtros</a>
            <?php else: ?>
            <button type="button" class="btn-primary" onclick="abrirModalNuevaMatricula()">
                <span class="material-symbols-rounded">add</span> Nueva matrícula
            </button>
            <?php endif; ?>
        </div>
    <?php else: ?>
    <?php foreach ($estudiantes_lista as $e): ?>
        <div class="matricula-card">

            <!-- Avatar con inicial -->
            <div class="matricula-avatar">
                <?php $foto_url = get_foto_url($e['estudiante_foto'] ?? ''); ?>
                <?php if ($foto_url): ?>
                    <img src="<?= $foto_url ?>"
                        alt=""
                        onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                    <span style="display:none;">
                        <?= mb_strtoupper(mb_substr($e['estudiante_nombre'], 0, 1)) ?>
                    </span>
                <?php else: ?>
                    <?= mb_strtoupper(mb_substr($e['estudiante_nombre'], 0, 1)) ?>
                <?php endif; ?>
            </div>

            <div class="matricula-info">
                <div class="matricula-top">
                    <div class="matricula-nombre-wrap">
                        <h3 class="matricula-nombre"><?= htmlspecialchars($e['estudiante_nombre']) ?></h3>

                        <?php if ($e['total_matriculas'] > 1): ?>
                        <span class="badge badge-info">
                            <span class="material-symbols-rounded" style="font-size:12px;">layers</span>
                            <?= $e['total_matriculas'] ?> matrículas
                        </span>
                        <?php endif; ?>

                        <?php if (($e['pagos_vencidos'] ?? 0) > 0): ?>
                        <span class="badge badge-danger-soft">
                            <span class="material-symbols-rounded" style="font-size:12px;">running_with_errors</span>
                            <?= $e['pagos_vencidos'] ?> vencido<?= $e['pagos_vencidos'] > 1 ? 's' : '' ?>
                        </span>
                        <?php elseif ($e['pagos_pendientes'] > 0): ?>
                        <span class="badge badge-warning-soft">
                            <span class="material-symbols-rounded" style="font-size:12px;">receipt_long</span>
                            <?= $e['pagos_pendientes'] ?> pendiente<?= $e['pagos_pendientes'] > 1 ? 's' : '' ?>
                        </span>
                        <?php else: ?>
                        <span class="badge badge-success-soft">
                            <span class="material-symbols-rounded" style="font-size:12px;">check_circle</span>
                            Al día
                        </span>
                        <?php endif; ?>

                        <?php if ($e['sin_grupo'] > 0): ?>
                        <span class="badge badge-secondary">
                            <span class="material-symbols-rounded" style="font-size:12px;">group_off</span>
                            <?= $e['sin_grupo'] > 1 ? $e['sin_grupo'] . ' sin grupo' : 'Sin grupo' ?>
                        </span>
                        <?php endif; ?>
                    </div>
                    <p class="matricula-email"><?= htmlspecialchars($e['estudiante_email']) ?></p>
                </div>

                <div class="matricula-meta">
                    <?php if ($e['cursos_lista']): ?>
                    <span class="meta-item">
                        <span class="material-symbols-rounded">music_note</span>
                        <?= htmlspecialchars($e['cursos_lista']) ?>
                    </span>
                    <?php endif; ?>
                    <span class="meta-item">
                        <span class="material-symbols-rounded">badge</span>
                        <?= htmlspecialchars($e['estudiante_documento'] ?? '—') ?>
                    </span>
                    <span class="meta-item">
                        <span class="material-symbols-rounded">calendar_today</span>
                        Última matrícula: <?= date('d/m/Y', strtotime($e['ultima_matricula'])) ?>
                    </span>
                </div>
            </div>

            <div class="matricula-actions">
                <a href="detalle.php?estudiante=<?= $e['estudiante_id'] ?>" class="btn-action btn-view">
                    <span class="material-symbols-rounded">visibility</span>
                    Ver detalle
                </a>
                <?php if ($e['total_matriculas'] == 1): ?>
                <button class="btn-action btn-danger"
                        onclick="confirmarCancelacion(<?= $e['estudiante_id'] ?>, '<?= addslashes(htmlspecialchars($e['estudiante_nombre'])) ?>')">
                    <span class="material-symbols-rounded">cancel</span>
                    Cancelar
                </button>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
    <?php endif; ?>
    </div>

    <!-- Paginación -->
    <?php if ($total_paginas > 1): ?>
    <div class="pagination">
        <span class="pag-info">
            Mostrando <?= min($offset+1,$total_estudiantes) ?>–<?= min($offset+$por_pagina,$total_estudiantes) ?> de <?= $total_estudiantes ?>
        </span>
        <div class="pag-controls">
            <?php if ($pagina > 1): ?>
            <a href="?<?= http_build_query(array_merge($_GET,['pagina'=>$pagina-1])) ?>" class="pag-btn">
                <span class="material-symbols-rounded">chevron_left</span>
            </a>
            <?php endif; ?>
            <?php for ($i=max(1,$pagina-2);$i<=min($total_paginas,$pagina+2);$i++): ?>
            <a href="?<?= http_build_query(array_merge($_GET,['pagina'=>$i])) ?>"
               class="pag-btn <?= $i===$pagina?'active':'' ?>"><?= $i ?></a>
            <?php endfor; ?>
            <?php if ($pagina < $total_paginas): ?>
            <a href="?<?= http_build_query(array_merge($_GET,['pagina'=>$pagina+1])) ?>" class="pag-btn">
                <span class="material-symbols-rounded">chevron_right</span>
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

</main>

<!-- ══════════════════════════════════════════════════════════════
     Modal: Cancelar matrícula (solo para estudiantes con 1 matrícula)
     ══════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modalCancelacion">
    <div class="modal">
        <div class="modal-icon modal-icon-danger"><span class="material-symbols-rounded">cancel</span></div>
        <h3 class="modal-title">Cancelar Matrícula</h3>
        <p class="modal-desc">¿Cancelar la matrícula de <strong id="nombreEstudiante"></strong>? El estado cambiará a <em>retirado</em>.</p>
        <div class="modal-actions">
            <button class="btn-secondary" onclick="cerrarModal()">Volver</button>
            <a href="" id="linkCancelar" class="btn-danger-solid">Sí, cancelar</a>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     Modal: Nueva Matrícula
     ══════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modalNuevaMatricula">
    <div class="modal modal-nueva-matricula">

        <!-- Header -->
        <div class="modal-nueva-header">
            <div style="display:flex; align-items:center; gap:12px;">
                <div class="modal-icon modal-icon-blue" style="margin:0; width:40px; height:40px; border-radius:10px; flex-shrink:0;">
                    <span class="material-symbols-rounded">person_add</span>
                </div>
                <div>
                    <h3 class="modal-title" style="margin:0; text-align:left; font-size:1.05rem;">Nueva Matrícula</h3>
                    <p style="font-size:0.8rem; color:var(--text-secondary); margin:0;">Registra y se generará el pago del mes automáticamente</p>
                </div>
            </div>
            <button class="modal-close-btn" onclick="cerrarModalNuevaMatricula()" type="button">
                <span class="material-symbols-rounded">close</span>
            </button>
        </div>

        <!-- Body con scroll -->
        <div class="modal-nueva-body">

            <div class="nueva-mat-info">
                <span class="material-symbols-rounded">auto_awesome</span>
                <div>
                    <strong>Generación automática de pagos</strong>
                    <p>Al seleccionar un grupo se generará la cuota del mes actual, con vencimiento el mismo día de hoy.</p>
                </div>
            </div>

            <form method="POST" action="nueva.php" class="form-nueva-matricula" id="formNuevaModal" novalidate>

                <div class="alert alert-error" id="alertValidarMatricula" style="display:none">
                    <span class="material-symbols-rounded">error</span>
                    <span id="msgValidarMatricula"></span>
                </div>

                <!-- Buscador + select de estudiante -->
                <div class="form-group">
                    <label class="form-label">
                        Estudiante <span style="color:var(--primary-orange)">*</span>
                    </label>
                    <div class="search-input-wrap" style="margin-bottom:8px;">
                        <span class="material-symbols-rounded search-icon">search</span>
                        <input type="text" id="buscarEstudianteModal" class="search-input"
                                placeholder="Buscar por nombre, email o documento…"
                                autocomplete="off">
                    </div>
                    <select name="estudiante_id" id="selectEstudianteModal"
                        class="form-control" required
                        size="5" style="height:auto; max-height:180px; overflow-y:auto; width:100%; min-width:0;">
                        <option value="">— Busca y selecciona —</option>
                        <?php foreach ($estudiantes as $e): ?>
                        <option value="<?= $e['id'] ?>"
                                data-texto="<?= strtolower($e['nombre'].' '.$e['email'].' '.$e['documento']) ?>">
                            <?= htmlspecialchars($e['nombre']) ?> — <?= htmlspecialchars($e['email']) ?>
                            (<?= htmlspecialchars($e['documento']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="form-hint">Clic en el nombre para seleccionar</span>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">
                            Grupo
                            <span class="form-hint" style="display:inline; text-transform:none; font-size:0.72rem;">(opcional)</span>
                        </label>
                        <select name="grupo_id" id="selectGrupoModal" class="form-control"
                                onchange="mostrarInfoGrupoModal(this)">
                            <option value="">— Sin grupo asignado —</option>
                            <?php foreach ($grupos_por_curso as $curso_nombre => $gs): ?>
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
                        <div id="infoGrupoModal" class="grupo-preview" style="display:none;"></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Fecha de inicio</label>
                        <input type="date" name="fecha_inicio" class="form-control"
                               value="<?= date('Y-m-d') ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Observaciones</label>
                    <textarea name="observaciones" class="form-control" rows="2"
                              placeholder="Información adicional sobre la matrícula…"></textarea>
                </div>

            </form>
        </div>

        <!-- Footer con acciones -->
        <div class="modal-nueva-footer">
            <button type="button" class="btn-secondary" onclick="cerrarModalNuevaMatricula()">Cancelar</button>
            <button type="submit" form="formNuevaModal" class="btn-primary">
                <span class="material-symbols-rounded">save</span> Crear matrícula
            </button>
        </div>

    </div>
</div>

<script>
// ── Modal Cancelar ────────────────────────────────────────────
function confirmarCancelacion(estudianteId, nombre) {
    document.getElementById('nombreEstudiante').textContent = nombre;
    document.getElementById('linkCancelar').href = 'detalle.php?estudiante=' + estudianteId;
    document.getElementById('modalCancelacion').classList.add('active');
}
function cerrarModal() { document.getElementById('modalCancelacion').classList.remove('active'); }
document.getElementById('modalCancelacion').addEventListener('click', e => {
    if (e.target === e.currentTarget) cerrarModal();
});

// ── Modal Nueva Matrícula ─────────────────────────────────────
function abrirModalNuevaMatricula() {
    document.getElementById('modalNuevaMatricula').classList.add('active');
    document.body.style.overflow = 'hidden';
    setTimeout(() => document.getElementById('buscarEstudianteModal')?.focus(), 250);
}
function cerrarModalNuevaMatricula() {
    document.getElementById('modalNuevaMatricula').classList.remove('active');
    document.body.style.overflow = '';
}
document.getElementById('modalNuevaMatricula').addEventListener('click', e => {
    if (e.target === e.currentTarget) cerrarModalNuevaMatricula();
});

// Cerrar ambos modales con Escape
document.addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    if (document.getElementById('modalNuevaMatricula').classList.contains('active')) cerrarModalNuevaMatricula();
    if (document.getElementById('modalCancelacion').classList.contains('active')) cerrarModal();
});

// Filtro de búsqueda dentro del modal
document.getElementById('buscarEstudianteModal')?.addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#selectEstudianteModal option').forEach(opt => {
        if (!opt.value) return;
        opt.style.display = (opt.dataset.texto || '').includes(q) ? '' : 'none';
    });
});

// Preview de pago al seleccionar grupo
function mostrarInfoGrupoModal(sel) {
    const opt    = sel.options[sel.selectedIndex];
    const info   = document.getElementById('infoGrupoModal');
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

// Validación del formulario Nueva Matrícula
document.getElementById('formNuevaModal').addEventListener('submit', function(e) {
    const alertEl = document.getElementById('alertValidarMatricula');
    const msgEl   = document.getElementById('msgValidarMatricula');
    const sel     = document.getElementById('selectEstudianteModal');

    alertEl.style.display = 'none';
    sel.classList.remove('input-error');

    if (!sel.value) {
        e.preventDefault();
        msgEl.textContent = 'Debes seleccionar un estudiante antes de crear la matrícula.';
        alertEl.style.display = 'flex';
        sel.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
});

document.getElementById('selectEstudianteModal').addEventListener('change', function() {
    if (this.value) {
        this.classList.remove('input-error');
        document.getElementById('alertValidarMatricula').style.display = 'none';
    }
});

// Reabrir modal si viene de un error en nueva.php
<?php if ($open_modal): ?>
document.addEventListener('DOMContentLoaded', () => abrirModalNuevaMatricula());
<?php endif; ?>

// Búsqueda con debounce en el listado principal
let st;
document.querySelector('.search-input[name="buscar"]')?.addEventListener('input', function() {
    clearTimeout(st); st = setTimeout(() => document.getElementById('filtrosForm').submit(), 500);
});

// Auto-ocultar flash
setTimeout(() => {
    const a = document.getElementById('alertFlash');
    if (a) { a.style.transition = 'opacity .5s'; a.style.opacity = '0'; setTimeout(() => a.remove(), 500); }
}, 5000);
</script>
</body>
</html>