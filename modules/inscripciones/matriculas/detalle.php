<?php
/**
 * Detalle de Matrícula
 * Ver información completa, gestionar pagos y asignar grupo
 */

require_once '../../../config/session.php';
require_once '../../../config/database.php';
require_once '../../../includes/auth_check.php';
require_role('admin');

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header("Location: index.php"); exit; }

try {
    $stmt = $pdo->prepare("SELECT id, nombre, email, rol FROM usuarios WHERE id = ? AND estado = 'activo'");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) { session_destroy(); header("Location: ../../../auth/login.php"); exit; }
} catch (PDOException $e) { die("Error del sistema."); }

try {
    // Obtener matrícula completa
    $stmt = $pdo->prepare("
        SELECT
            m.*,
            u.id as estudiante_id, u.nombre as estudiante_nombre, u.email as estudiante_email,
            u.documento as estudiante_documento, u.telefono as estudiante_telefono,
            u.direccion as estudiante_direccion, u.fecha_nacimiento as estudiante_nacimiento,
            u.fecha_registro as estudiante_registro,
            g.id as grupo_id, g.nombre as grupo_nombre, g.horario as grupo_horario,
            g.aula as grupo_aula, g.cupo_actual, g.cupo_maximo,
            g.fecha_inicio as grupo_fecha_inicio, g.fecha_fin as grupo_fecha_fin,
            c.id as curso_id, c.nombre as curso_nombre, c.nivel as curso_nivel,
            c.duracion_meses, c.precio_mensual, c.descripcion as curso_descripcion,
            prof.nombre as profesor_nombre, prof.email as profesor_email
        FROM matriculas m
        INNER JOIN usuarios u ON m.estudiante_id = u.id
        LEFT JOIN grupos g ON m.grupo_id = g.id
        LEFT JOIN cursos c ON g.curso_id = c.id
        LEFT JOIN usuarios prof ON g.profesor_id = prof.id
        WHERE m.id = ?
    ");
    $stmt->execute([$id]);
    $matricula = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$matricula) { header("Location: index.php?error=no_encontrada"); exit; }

    // Pagos de esta matrícula
    $stmt = $pdo->prepare("
        SELECT p.*, u.nombre as registrado_por_nombre
        FROM pagos p
        LEFT JOIN usuarios u ON p.registrado_por = u.id
        WHERE p.matricula_id = ?
        ORDER BY p.fecha_vencimiento DESC
    ");
    $stmt->execute([$id]);
    $pagos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Grupos disponibles del curso (con cupo)
    $grupos_disponibles = [];
    if ($matricula['curso_id']) {
        $stmt = $pdo->prepare("
            SELECT g.id, g.nombre, g.horario, g.aula, g.cupo_actual, g.cupo_maximo,
                   u.nombre as profesor_nombre
            FROM grupos g
            LEFT JOIN usuarios u ON g.profesor_id = u.id
            WHERE g.curso_id = ? AND g.estado IN ('activo','planificado')
            AND g.cupo_actual < g.cupo_maximo
            AND g.id != ?
        ");
        $stmt->execute([$matricula['curso_id'], $matricula['grupo_id'] ?? 0]);
        $grupos_disponibles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Todos los cursos activos (para cambiar curso si no tiene grupo)
    $stmt = $pdo->query("SELECT id, nombre, nivel FROM cursos WHERE estado = 'activo' ORDER BY nombre");
    $todos_cursos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Resumen de pagos
    $resumen_pagos = ['total' => 0, 'pagados' => 0, 'pendientes' => 0, 'vencidos' => 0, 'monto_total' => 0, 'monto_pagado' => 0];
    foreach ($pagos as $p) {
        $resumen_pagos['total']++;
        $resumen_pagos['monto_total'] += $p['monto'];
        if ($p['estado'] === 'pagado')   { $resumen_pagos['pagados']++;   $resumen_pagos['monto_pagado'] += $p['monto']; }
        if ($p['estado'] === 'pendiente') $resumen_pagos['pendientes']++;
        if ($p['estado'] === 'vencido')   $resumen_pagos['vencidos']++;
    }

    // Flash message
    $flash_msg = $_GET['msg'] ?? '';
    $flash_type = $_GET['type'] ?? '';

} catch (PDOException $e) {
    error_log($e->getMessage());
    die("Error al cargar la matrícula.");
}

function badge_pago($estado) {
    $map = [
        'pagado'   => ['label' => 'Pagado',   'class' => 'badge-success'],
        'pendiente'=> ['label' => 'Pendiente','class' => 'badge-warning'],
        'vencido'  => ['label' => 'Vencido',  'class' => 'badge-danger'],
        'anulado'  => ['label' => 'Anulado',  'class' => 'badge-secondary'],
    ];
    return $map[$estado] ?? ['label' => ucfirst($estado), 'class' => 'badge-secondary'];
}

function badge_estado($estado) {
    $map = [
        'activa'     => ['label' => 'Activa',     'class' => 'badge-success'],
        'suspendida' => ['label' => 'Suspendida', 'class' => 'badge-warning'],
        'graduado'   => ['label' => 'Graduado',   'class' => 'badge-primary'],
        'retirado'   => ['label' => 'Retirado',   'class' => 'badge-danger'],
    ];

    return $map[$estado] ?? [
        'label' => ucfirst($estado),
        'class' => 'badge-secondary'
    ];
}

function nivel_label($nivel) {
    return ['basico'=>'Básico','intermedio'=>'Intermedio','avanzado'=>'Avanzado'][$nivel] ?? ucfirst($nivel);
}
function fmt_money($n) { return '$' . number_format($n, 0, ',', '.'); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detalle Matrícula - <?php echo htmlspecialchars($matricula['estudiante_nombre']); ?></title>
    <link rel="shortcut icon" href="../../../assets/img/3.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0" />
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="../../../assets/css/colores.css">
    <link rel="stylesheet" href="../../../assets/css/style-matriculas.css">
</head>
<body>
    <?php require_once '../../../includes/header.php'; ?>

    <main class="main-content">
        <!-- Header de página -->
        <div class="dashboard-header">
            <div class="dashboard-title">
                <a href="index.php" class="back-link">
                    <span class="material-symbols-rounded">arrow_back</span>
                    Matrículas
                </a>
                <h1><?php echo htmlspecialchars($matricula['estudiante_nombre']); ?></h1>
                <p>Detalle completo de la matrícula #<?php echo $id; ?></p>
            </div>
        </div>

        <?php if ($flash_msg): ?>
        <div class="alert alert-<?php echo $flash_type === 'success' ? 'success' : 'danger'; ?>">
            <span class="material-symbols-rounded"><?php echo $flash_type === 'success' ? 'check_circle' : 'error'; ?></span>
            <?php echo htmlspecialchars($flash_msg); ?>
        </div>
        <?php endif; ?>

        <div class="detalle-grid">
            <!-- Columna izquierda: Info estudiante + Grupo -->
            <div class="detalle-col-left">
                <!-- Tarjeta: Info del estudiante -->
                <div class="card">
                    <div class="card-header">
                        <span class="material-symbols-rounded card-header-icon">person</span>
                        <h3>Información del Estudiante</h3>
                    </div>
                    <div class="card-body">
                        <div class="student-profile">
                            <div class="student-avatar-lg">
                                <span class="material-symbols-rounded">person</span>
                            </div>
                            <div class="student-profile-info">
                                <h2><?php echo htmlspecialchars($matricula['estudiante_nombre']); ?></h2>
                                <p><?php echo htmlspecialchars($matricula['estudiante_email']); ?></p>
                            </div>
                        </div>
                        <div class="info-grid">
                            <div class="info-item">
                                <span class="info-label">Documento</span>
                                <span class="info-value"><?php echo htmlspecialchars($matricula['estudiante_documento']); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Teléfono</span>
                                <span class="info-value"><?php echo htmlspecialchars($matricula['estudiante_telefono'] ?? '—'); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Fecha nacimiento</span>
                                <span class="info-value">
                                    <?php echo $matricula['estudiante_nacimiento'] ? date('d/m/Y', strtotime($matricula['estudiante_nacimiento'])) : '—'; ?>
                                </span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Registrado</span>
                                <span class="info-value"><?php echo date('d/m/Y', strtotime($matricula['estudiante_registro'])); ?></span>
                            </div>
                            <?php if ($matricula['estudiante_direccion']): ?>
                            <div class="info-item full-width">
                                <span class="info-label">Dirección</span>
                                <span class="info-value"><?php echo htmlspecialchars($matricula['estudiante_direccion']); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Tarjeta: Curso y Grupo -->
                <div class="card">
                    <div class="card-header">
                        <span class="material-symbols-rounded card-header-icon">music_note</span>
                        <h3>Curso y Grupo</h3>
                    </div>
                    <div class="card-body">
                        <?php if ($matricula['curso_nombre']): ?>
                            <div class="course-info-block">
                                <div class="course-badge-wrap">
                                    <span class="course-badge"><?php echo htmlspecialchars($matricula['curso_nombre']); ?></span>
                                    <span class="nivel-badge nivel-<?php echo $matricula['curso_nivel']; ?>"><?php echo nivel_label($matricula['curso_nivel']); ?></span>
                                </div>
                                <?php if ($matricula['curso_descripcion']): ?>
                                    <p class="course-desc"><?php echo htmlspecialchars($matricula['curso_descripcion']); ?></p>
                                <?php endif; ?>
                            </div>
                            <?php if ($matricula['grupo_nombre']): ?>
                                <div class="group-info-block">
                                    <div class="info-grid">
                                        <div class="info-item">
                                            <span class="info-label">Grupo</span>
                                            <span class="info-value"><?php echo htmlspecialchars($matricula['grupo_nombre']); ?></span>
                                        </div>
                                        <div class="info-item">
                                            <span class="info-label">Horario</span>
                                            <span class="info-value"><?php echo htmlspecialchars($matricula['grupo_horario'] ?? '—'); ?></span>
                                        </div>
                                        <div class="info-item">
                                            <span class="info-label">Aula</span>
                                            <span class="info-value"><?php echo htmlspecialchars($matricula['grupo_aula'] ?? '—'); ?></span>
                                        </div>
                                        <div class="info-item">
                                            <span class="info-label">Cupos</span>
                                            <span class="info-value"><?php echo $matricula['cupo_actual']; ?>/<?php echo $matricula['cupo_maximo']; ?></span>
                                        </div>
                                        <div class="info-item">
                                            <span class="info-label">Profesor</span>
                                            <span class="info-value"><?php echo htmlspecialchars($matricula['profesor_nombre'] ?? '—'); ?></span>
                                        </div>
                                        <div class="info-item">
                                            <span class="info-label">Fecha inicio</span>
                                            <span class="info-value">
                                                <?php echo $matricula['grupo_fecha_inicio'] ? date('d/m/Y', strtotime($matricula['grupo_fecha_inicio'])) : '—'; ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <!-- Cambiar grupo -->
                                <?php if (!empty($grupos_disponibles)): ?>
                                <div class="action-section">
                                    <button class="btn-outline" onclick="toggleSection('cambiarGrupo')">
                                        <span class="material-symbols-rounded">swap_horiz</span>
                                        Cambiar de grupo
                                    </button>
                                    <div id="cambiarGrupo" class="collapsible-section" style="display:none;">
                                        <form method="POST" action="acciones.php" class="form-inline-section">
                                            <input type="hidden" name="accion" value="cambiar_grupo">
                                            <input type="hidden" name="matricula_id" value="<?php echo $id; ?>">
                                            <div class="form-group">
                                                <label class="form-label">Seleccionar nuevo grupo</label>
                                                <select name="grupo_id" class="form-control" required>
                                                    <option value="">— Selecciona un grupo —</option>
                                                    <?php foreach($grupos_disponibles as $g): ?>
                                                        <option value="<?php echo $g['id']; ?>">
                                                            <?php echo htmlspecialchars($g['nombre']); ?> — <?php echo htmlspecialchars($g['horario']); ?>
                                                            (<?php echo $g['cupo_actual']; ?>/<?php echo $g['cupo_maximo']; ?> cupos)
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <button type="submit" class="btn-primary">
                                                <span class="material-symbols-rounded">save</span>
                                                Guardar cambio
                                            </button>
                                        </form>
                                    </div>
                                </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="no-group-notice">
                                    <span class="material-symbols-rounded">warning</span>
                                    <p>Este estudiante no tiene grupo asignado aún</p>
                                </div>
                                <!-- Asignar grupo -->
                                <?php if (!empty($grupos_disponibles)): ?>
                                <form method="POST" action="acciones.php" class="form-inline-section mt-16">
                                    <input type="hidden" name="accion" value="asignar_grupo">
                                    <input type="hidden" name="matricula_id" value="<?php echo $id; ?>">
                                    <div class="form-group">
                                        <label class="form-label">Asignar a un grupo</label>
                                        <select name="grupo_id" class="form-control" required>
                                            <option value="">— Selecciona un grupo —</option>
                                            <?php foreach($grupos_disponibles as $g): ?>
                                                <option value="<?php echo $g['id']; ?>">
                                                    <?php echo htmlspecialchars($g['nombre']); ?> — <?php echo htmlspecialchars($g['horario']); ?>
                                                    (<?php echo $g['cupo_actual']; ?>/<?php echo $g['cupo_maximo']; ?> cupos)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <button type="submit" class="btn-primary">
                                        <span class="material-symbols-rounded">group_add</span>
                                        Asignar grupo
                                    </button>
                                </form>
                                <?php else: ?>
                                <p class="text-muted mt-16">No hay grupos disponibles con cupo en este curso.</p>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="no-group-notice">
                                <span class="material-symbols-rounded">school</span>
                                <p>Sin curso ni grupo asignado</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Tarjeta: Estado matrícula -->
                <div class="card">
                    <div class="card-header">
                        <span class="material-symbols-rounded card-header-icon">edit_note</span>
                        <h3>Estado de la Matrícula</h3>
                    </div>
                    <div class="card-body">
                        <div class="info-grid">
                            <div class="info-item">
                                <span class="info-label">Fecha matrícula</span>
                                <span class="info-value"><?php echo date('d/m/Y', strtotime($matricula['fecha_matricula'])); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Estado actual</span>
                                <?php $b = badge_estado($matricula['estado'] ?? 'activa'); ?>
                                <span class="badge <?php echo $b['class']; ?>"><?php echo $b['label']; ?></span>
                            </div>
                            <?php if ($matricula['observaciones']): ?>
                            <div class="info-item full-width">
                                <span class="info-label">Observaciones</span>
                                <span class="info-value"><?php echo htmlspecialchars($matricula['observaciones']); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="action-section">
                            <button class="btn-outline" onclick="toggleSection('cambiarEstado')">
                                <span class="material-symbols-rounded">swap_vert</span>
                                Cambiar estado
                            </button>
                            <div id="cambiarEstado" class="collapsible-section" style="display:none;">
                                <form method="POST" action="acciones.php" class="form-inline-section">
                                    <input type="hidden" name="accion" value="cambiar_estado">
                                    <input type="hidden" name="matricula_id" value="<?php echo $id; ?>">
                                    <div class="form-group">
                                        <label class="form-label">Nuevo estado</label>
                                        <select name="nuevo_estado" class="form-control" required>
                                            <option value="activa"     <?php echo $matricula['estado'] === 'activa'     ? 'selected':'' ?>>Activa</option>
                                            <option value="suspendida" <?php echo $matricula['estado'] === 'suspendida' ? 'selected':'' ?>>Suspendida</option>
                                            <option value="graduado"   <?php echo $matricula['estado'] === 'graduado'   ? 'selected':'' ?>>Graduado</option>
                                            <option value="retirado"   <?php echo $matricula['estado'] === 'retirado'   ? 'selected':'' ?>>Retirado</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Observaciones (opcional)</label>
                                        <textarea name="observaciones" class="form-control" rows="2" placeholder="Motivo del cambio..."><?php echo htmlspecialchars($matricula['observaciones'] ?? ''); ?></textarea>
                                    </div>
                                    <button type="submit" class="btn-primary">
                                        <span class="material-symbols-rounded">save</span>
                                        Guardar cambio
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Columna derecha: Pagos -->
            <div class="detalle-col-right">
                <!-- Resumen de pagos -->
                <div class="card">
                    <div class="card-header">
                        <span class="material-symbols-rounded card-header-icon">payments</span>
                        <h3>Registro de Pagos</h3>
                        <button class="btn-primary btn-sm ml-auto" onclick="toggleSection('nuevoPago')">
                            <span class="material-symbols-rounded">add</span>
                            Registrar pago
                        </button>
                    </div>
                    <div class="card-body">
                        <!-- Resumen -->
                        <div class="pagos-resumen">
                            <div class="resumen-item resumen-total">
                                <span class="resumen-value"><?php echo fmt_money($resumen_pagos['monto_total']); ?></span>
                                <span class="resumen-label">Total facturado</span>
                            </div>
                            <div class="resumen-item resumen-pagado">
                                <span class="resumen-value"><?php echo fmt_money($resumen_pagos['monto_pagado']); ?></span>
                                <span class="resumen-label">Pagado</span>
                            </div>
                            <div class="resumen-item resumen-deuda">
                                <span class="resumen-value"><?php echo fmt_money($resumen_pagos['monto_total'] - $resumen_pagos['monto_pagado']); ?></span>
                                <span class="resumen-label">Pendiente</span>
                            </div>
                        </div>

                        <!-- Formulario nuevo pago (colapsable) -->
                        <div id="nuevoPago" class="collapsible-section" style="display:none;">
                            <div class="form-section-title">Registrar nuevo pago</div>
                            <form method="POST" action="acciones.php" class="form-pago">
                                <input type="hidden" name="accion" value="registrar_pago">
                                <input type="hidden" name="matricula_id" value="<?php echo $id; ?>">
                                <input type="hidden" name="estudiante_id" value="<?php echo $matricula['estudiante_id']; ?>">
                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label">Concepto *</label>
                                        <input type="text" name="concepto" class="form-control" placeholder="Ej: Mensualidad Marzo 2026" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Monto *</label>
                                        <div class="input-prefix-wrap">
                                            <span class="input-prefix">$</span>
                                            <input type="number" name="monto" class="form-control with-prefix"
                                                   placeholder="<?php echo $matricula['precio_mensual'] ?? '0'; ?>"
                                                   value="<?php echo $matricula['precio_mensual'] ?? ''; ?>"
                                                   min="0" step="1000" required>
                                        </div>
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label">Método de pago *</label>
                                        <select name="metodo_pago" class="form-control" required>
                                            <option value="">— Selecciona —</option>
                                            <option value="efectivo">Efectivo</option>
                                            <option value="transferencia">Transferencia</option>
                                            <option value="tarjeta">Tarjeta</option>
                                            <option value="pse">PSE</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Estado *</label>
                                        <select name="estado" class="form-control" required>
                                            <option value="pagado">Pagado</option>
                                            <option value="pendiente">Pendiente</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="form-label">Fecha vencimiento *</label>
                                        <input type="date" name="fecha_vencimiento" class="form-control"
                                               value="<?php echo date('Y-m-t'); ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Fecha de pago</label>
                                        <input type="date" name="fecha_pago" class="form-control"
                                               value="<?php echo date('Y-m-d'); ?>">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Comprobante / Observaciones</label>
                                    <input type="text" name="observaciones" class="form-control" placeholder="Número de recibo, referencia, etc.">
                                </div>
                                <div class="form-actions">
                                    <button type="button" class="btn-secondary" onclick="toggleSection('nuevoPago')">Cancelar</button>
                                    <button type="submit" class="btn-primary">
                                        <span class="material-symbols-rounded">save</span>
                                        Guardar pago
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
                                <?php foreach($pagos as $p): ?>
                                    <?php $bp = badge_pago($p['estado']); ?>
                                    <div class="pago-item">
                                        <div class="pago-item-left">
                                            <div class="pago-concepto"><?php echo htmlspecialchars($p['concepto']); ?></div>
                                            <div class="pago-meta">
                                                <span><?php echo ucfirst($p['metodo_pago']); ?></span>
                                                <span>Vence: <?php echo date('d/m/Y', strtotime($p['fecha_vencimiento'])); ?></span>
                                                <?php if ($p['fecha_pago']): ?>
                                                    <span>Pagado: <?php echo date('d/m/Y', strtotime($p['fecha_pago'])); ?></span>
                                                <?php endif; ?>
                                                <?php if ($p['registrado_por_nombre']): ?>
                                                    <span>Por: <?php echo htmlspecialchars($p['registrado_por_nombre']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($p['observaciones']): ?>
                                                <div class="pago-obs"><?php echo htmlspecialchars($p['observaciones']); ?></div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="pago-item-right">
                                            <div class="pago-monto"><?php echo fmt_money($p['monto']); ?></div>
                                            <span class="badge <?php echo $bp['class']; ?>"><?php echo $bp['label']; ?></span>
                                            <?php if ($p['estado'] === 'pendiente'): ?>
                                                <form method="POST" action="acciones.php" style="margin-top:6px;">
                                                    <input type="hidden" name="accion" value="marcar_pagado">
                                                    <input type="hidden" name="pago_id" value="<?php echo $p['id']; ?>">
                                                    <input type="hidden" name="matricula_id" value="<?php echo $id; ?>">
                                                    <button type="submit" class="btn-xs btn-success">
                                                        <span class="material-symbols-rounded">check</span>
                                                        Marcar pagado
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
        function toggleSection(id) {
            const el = document.getElementById(id);
            if (!el) return;
            el.style.display = el.style.display === 'none' ? 'block' : 'none';
        }
    </script>
</body>
</html>