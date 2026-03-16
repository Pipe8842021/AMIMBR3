<?php
/**
 * Módulo de Prematrículas / Preinscripciones
 * Gestión: listar, aprobar, rechazar, crear desde admin y ver detalles
 *
 * Tabla usuarios relevante:
 *   id, nombre, email, password, documento (UNI), telefono,
 *   direccion, fecha_nacimiento, rol, estado, foto_perfil,
 *   remember_token, fecha_registro, ultima_conexion
 */

require_once '../../../config/session.php';
require_once '../../../config/database.php';
require_once '../../../includes/auth_check.php';
require_once '../../../includes/notificaciones_helper.php';
require_role('admin');

// ═══════════════════════════════════════════════════════════
//  PROCESAMIENTO DE ACCIONES POST
// ═══════════════════════════════════════════════════════════
$mensaje      = null;
$tipo_mensaje = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    // ──────────────────────────────────────────────────────
    // APROBAR — crea usuario, contraseña = numero_documento
    // ──────────────────────────────────────────────────────
    if ($accion === 'aprobar') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $pdo->beginTransaction();

            // Obtener preinscripción
            $stmt = $pdo->prepare("SELECT * FROM preinscripciones WHERE id = ? AND estado = 'pendiente'");
            $stmt->execute([$id]);
            $pre = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$pre) throw new Exception("Preinscripción no encontrada o ya fue procesada.");

            // Verificar duplicados usando columnas reales de usuarios
            $chk = $pdo->prepare("SELECT id FROM usuarios WHERE email = ? OR documento = ?");
            $chk->execute([$pre['email'], $pre['numero_documento']]);
            if ($chk->fetch()) throw new Exception("Ya existe un usuario registrado con ese correo o documento.");

            // Contraseña por defecto = número de documento
            $pass_hash = password_hash($pre['numero_documento'], PASSWORD_BCRYPT);

            // Insertar usuario con columnas reales de la tabla
            $ins = $pdo->prepare("
                INSERT INTO usuarios
                    (nombre, email, password, documento, telefono,
                     direccion, fecha_nacimiento, rol, estado, fecha_registro)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'estudiante', 'activo', NOW())
            ");
            $ins->execute([
                $pre['nombres_apellidos'],
                $pre['email'],
                $pass_hash,
                $pre['numero_documento'],
                $pre['celular']           ?? null,
                $pre['direccion']         ?? null,
                $pre['fecha_nacimiento']  ?? null,
            ]);
            $nuevo_usuario_id = $pdo->lastInsertId();

            // ── Crear matrícula ───────────────────────────────
            // grupo_id = NULL (se asigna luego desde el módulo de matrículas)
            // fecha_matricula = hoy, fecha_inicio = fecha deseada de la preinscripción o hoy
            $fecha_inicio_mat = !empty($pre['fecha_inicio'])
                ? $pre['fecha_inicio']
                : date('Y-m-d');

            $pdo->prepare("
                INSERT INTO matriculas
                    (estudiante_id, grupo_id, fecha_matricula, fecha_inicio, estado, observaciones, preinscripcion_id)
                VALUES (?, NULL, CURDATE(), ?, 'activa', ?, ?)
            ")->execute([
                $nuevo_usuario_id,
                $fecha_inicio_mat,
                "Matrícula generada desde preinscripción #{$id} — Programa: " . ($pre['programa'] ?? '—'),
                $id,
            ]);

            // Marcar preinscripción como aprobada y vincular usuario
            $pdo->prepare("
                UPDATE preinscripciones
                SET estado             = 'aprobada',
                    fecha_aprobacion   = NOW(),
                    usuario_creado_id  = ?
                WHERE id = ?
            ")->execute([$nuevo_usuario_id, $id]);

           // ── Notificación automática ───
            // 1. Notifica a todos los admins sobre la nueva matrícula
            NotificacionesHelper::crearParaRoles(
                $pdo,
                ['admin'],
                'sistema',
                'Nueva matrícula creada',
                "Se aprobó la preinscripción de {$pre['nombres_apellidos']} y se creó su matrícula. Pendiente asignar grupo.",
                $user['nombre'] ?? 'Administrador',
                'alta',
                '/AMIMBR3/modules/inscripciones/matriculas/index.php'
            );

            // 2. Si el estudiante ya tiene usuario, notificarlo también
            NotificacionesHelper::estadoPreinscripcionCambiado(
                $pdo,
                $nuevo_usuario_id,
                'matriculado'
            );

            // Log de actividad
            $pdo->prepare("
                INSERT INTO logs_actividad (usuario_id, accion, detalles, ip_address)
                VALUES (?, 'preinscripcion_aprobada', ?, ?)
            ")->execute([
                $_SESSION['user_id'],
                "Aprobada preinscripción #{$id} — {$pre['nombres_apellidos']} — Matrícula creada",
                $_SERVER['REMOTE_ADDR'] ?? null
            ]);

            $pdo->commit();
            $mensaje      = "✅ Preinscripción aprobada. Matrícula creada — contraseña temporal: <strong>{$pre['numero_documento']}</strong>. Recuerda asignarle un grupo.";
            $tipo_mensaje = 'success';

        } catch (Exception $e) {
            $pdo->rollBack();
            $mensaje      = "❌ Error: " . htmlspecialchars($e->getMessage());
            $tipo_mensaje = 'error';
        }

    // ──────────────────────────────────────────────────────
    // RECHAZAR
    // ──────────────────────────────────────────────────────
    } elseif ($accion === 'rechazar') {
        $id     = (int)($_POST['id'] ?? 0);
        $motivo = htmlspecialchars(strip_tags(trim($_POST['motivo'] ?? '')));
        try {
            $pdo->prepare("
                UPDATE preinscripciones
                SET estado           = 'rechazada',
                    motivo_rechazo   = ?,
                    fecha_aprobacion = NOW()
                WHERE id = ? AND estado = 'pendiente'
            ")->execute([$motivo, $id]);

            $pdo->prepare("
                INSERT INTO logs_actividad (usuario_id, accion, detalles, ip_address)
                VALUES (?, 'preinscripcion_rechazada', ?, ?)
            ")->execute([
                $_SESSION['user_id'],
                "Rechazada preinscripción #{$id}. Motivo: {$motivo}",
                $_SERVER['REMOTE_ADDR'] ?? null
            ]);

            $mensaje      = "Preinscripción rechazada correctamente.";
            $tipo_mensaje = 'info';
        } catch (PDOException $e) {
            $mensaje      = "Error al rechazar: " . htmlspecialchars($e->getMessage());
            $tipo_mensaje = 'error';
        }

    // ──────────────────────────────────────────────────────
    // CREAR DESDE ADMIN — aprobación inmediata
    // ──────────────────────────────────────────────────────
    } elseif ($accion === 'crear_admin') {
        try {
            $pdo->beginTransaction();

            $nombres   = htmlspecialchars(strip_tags(trim($_POST['nombres_apellidos'] ?? '')));
            $tipo_doc  = $_POST['tipo_documento']   ?? 'CC';
            $num_doc   = trim($_POST['numero_documento'] ?? '');
            $email     = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
            $celular   = trim($_POST['celular']     ?? '');
            $programa  = trim($_POST['programa']    ?? '');
            $municipio = trim($_POST['municipio']   ?? '');
            $fecha_nac = $_POST['fecha_nacimiento'] ?? null;
            $direccion = trim($_POST['direccion']   ?? '');
            $nom_acud  = trim($_POST['nombre_acudiente']   ?? '');
            $tel_acud  = trim($_POST['telefono_acudiente'] ?? '');

            if (empty($nombres) || empty($num_doc) || empty($email) || empty($programa)) {
                throw new Exception("Faltan campos obligatorios (nombre, documento, email, programa).");
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("El correo electrónico no es válido.");
            }

            // Verificar duplicados con columnas reales
            $chk = $pdo->prepare("SELECT id FROM usuarios WHERE email = ? OR documento = ?");
            $chk->execute([$email, $num_doc]);
            if ($chk->fetch()) throw new Exception("Ya existe un usuario con ese correo o documento.");

            // Insertar preinscripción ya aprobada
            $insPI = $pdo->prepare("
                INSERT INTO preinscripciones
                    (nombres_apellidos, tipo_documento, numero_documento, email, celular,
                     programa, municipio, direccion, fecha_nacimiento,
                     nombre_acudiente, telefono_acudiente,
                     estado, ip_address)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'aprobada', ?)
            ");
            $insPI->execute([
                $nombres, $tipo_doc, $num_doc, $email, $celular ?: null,
                $programa, $municipio ?: null, $direccion ?: null,
                ($fecha_nac ?: null),
                $nom_acud ?: null, $tel_acud ?: null,
                $_SERVER['REMOTE_ADDR'] ?? null
            ]);
            $preId = $pdo->lastInsertId();

            // Crear usuario — contraseña = documento
            $pass_hash = password_hash($num_doc, PASSWORD_BCRYPT);
            $insU = $pdo->prepare("
                INSERT INTO usuarios
                    (nombre, email, password, documento, telefono,
                     direccion, fecha_nacimiento, rol, estado, fecha_registro)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'estudiante', 'activo', NOW())
            ");
            $insU->execute([
                $nombres, $email, $pass_hash, $num_doc,
                $celular   ?: null,
                $direccion ?: null,
                ($fecha_nac ?: null),
            ]);
            $nuevoId = $pdo->lastInsertId();

            // ── Crear matrícula ───────────────────────────────
            $fecha_inicio_mat = !empty($fecha_nac) ? date('Y-m-d') : date('Y-m-d');
            $pdo->prepare("
                INSERT INTO matriculas
                    (estudiante_id, grupo_id, fecha_matricula, fecha_inicio, estado, observaciones, preinscripcion_id)
                VALUES (?, NULL, CURDATE(), CURDATE(), 'activa', ?, ?)
            ")->execute([
                $nuevoId,
                "Matrícula creada por administrador — Programa: {$programa}",
                $preId,
            ]);

            // Vincular usuario a preinscripción
            $pdo->prepare("
                UPDATE preinscripciones
                SET usuario_creado_id = ?, fecha_aprobacion = NOW()
                WHERE id = ?
            ")->execute([$nuevoId, $preId]);

            // Log
            $pdo->prepare("
                INSERT INTO logs_actividad (usuario_id, accion, detalles, ip_address)
                VALUES (?, 'preinscripcion_creada_admin', ?, ?)
            ")->execute([
                $_SESSION['user_id'],
                "Admin creó preinscripción aprobada #{$preId} para {$nombres} — Matrícula creada",
                $_SERVER['REMOTE_ADDR'] ?? null
            ]);

            // ── Notificaciones automáticas ───────────────────────────
            // Notifica a todos los admins
            NotificacionesHelper::crearParaRoles(
                $pdo,
                ['admin'],
                'sistema',
                'Estudiante creado desde admin',
                "El admin creó directamente al estudiante {$nombres} con matrícula en {$programa}.",
                $user['nombre'] ?? 'Administrador',
                'normal',
                '/AMIMBR3/modules/inscripciones/matriculas/index.php'
            );
            // ────────────────────────────────────────────────────────

            $pdo->commit();
            $mensaje      = "Estudiante y matrícula creados. Contraseña temporal: <strong>{$num_doc}</strong>. Recuerda asignarle un grupo.";
            $tipo_mensaje = 'success';

        } catch (Exception $e) {
            $pdo->rollBack();
            $mensaje      = "❌ Error: " . htmlspecialchars($e->getMessage());
            $tipo_mensaje = 'error';
        }
    }
}

// ═══════════════════════════════════════════════════════════
//  FILTROS Y PAGINACIÓN
// ═══════════════════════════════════════════════════════════
$buscar  = trim($_GET['q']      ?? '');
$estado  = $_GET['estado']      ?? 'todos';
$pagina  = max(1, (int)($_GET['page'] ?? 1));
$por_pag = 10;
$offset  = ($pagina - 1) * $por_pag;

$where  = [];
$params = [];

if ($buscar !== '') {
    $where[]  = "(p.nombres_apellidos LIKE ? OR p.email LIKE ? OR p.numero_documento LIKE ? OR p.programa LIKE ?)";
    $like     = "%{$buscar}%";
    $params   = array_merge($params, [$like, $like, $like, $like]);
}
if ($estado !== 'todos') {
    $where[]  = "p.estado = ?";
    $params[] = $estado;
}

$sql_where = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Conteos por estado
try {
    $cntRow = $pdo->query("
        SELECT
            SUM(estado = 'pendiente')  AS pendientes,
            SUM(estado = 'aprobada')   AS aprobadas,
            SUM(estado = 'rechazada')  AS rechazadas,
            COUNT(*)                   AS total
        FROM preinscripciones
    ")->fetch(PDO::FETCH_ASSOC);
    $cnt = $cntRow ?: ['pendientes'=>0,'aprobadas'=>0,'rechazadas'=>0,'total'=>0];
} catch (PDOException $e) {
    $cnt = ['pendientes'=>0,'aprobadas'=>0,'rechazadas'=>0,'total'=>0];
}

// Total para paginación
try {
    $stmtC = $pdo->prepare("SELECT COUNT(*) FROM preinscripciones p {$sql_where}");
    $stmtC->execute($params);
    $total_registros = (int)$stmtC->fetchColumn();
    $total_paginas   = max(1, (int)ceil($total_registros / $por_pag));
} catch (PDOException $e) {
    $total_registros = 0;
    $total_paginas   = 1;
}

// Listado paginado
try {
    $stmtL = $pdo->prepare("
        SELECT p.*
        FROM preinscripciones p
        {$sql_where}
        ORDER BY
            CASE p.estado
                WHEN 'pendiente'  THEN 0
                WHEN 'aprobada'   THEN 1
                ELSE 2
            END,
            p.fecha_preinscripcion DESC
        LIMIT {$por_pag} OFFSET {$offset}
    ");
    $stmtL->execute($params);
    $preinscripciones = $stmtL->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $preinscripciones = [];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preinscripciones — Amimbré</title>
    <link rel="shortcut icon" href="../../../assets/img/3.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0"/>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="../../../assets/css/colores.css">
    <link rel="stylesheet" href="../../../assets/css/style-prematriculas.css">
    <script>
        (function() {
            const theme = localStorage.getItem('amimbre-theme');
            if (theme === 'light') {
                document.documentElement.setAttribute('data-theme', 'light');
            }
        })();
    </script>
</head>
<body>

<?php require_once '../../../includes/header.php'; ?>

<main class="main-content" id="mainContent">

    <!-- ══ Page Header ══════════════════════════════════════ -->
    <div class="page-header">
        <div>
            <h1 class="page-title">Preinscripciones</h1>
            <p class="page-subtitle">Gestiona las solicitudes de preinscripción recibidas</p>
        </div>
        <button class="btn-primary" onclick="abrirModal('modalCrear')">
            <span class="material-symbols-rounded">person_add</span>
            Nueva Preinscripción
        </button>
    </div>

    <!-- ══ Alerta de resultado ══════════════════════════════ -->
    <?php if ($mensaje): ?>
    <div class="alert alert-<?= $tipo_mensaje ?>" id="alertMsg">
        <?= $mensaje ?>
        <button class="alert-close" onclick="this.parentElement.remove()">
            <span class="material-symbols-rounded">close</span>
        </button>
    </div>
    <?php endif; ?>

    <!-- ══ Tarjetas de estado ════════════════════════════════ -->
    <div class="stats-row">
        <a class="stat-pill stat-pill--warning <?= $estado==='pendiente'?'stat-pill--active':'' ?>"
           href="?estado=pendiente">
            <span class="material-symbols-rounded">schedule</span>
            <div>
                <span class="pill-num"><?= (int)$cnt['pendientes'] ?></span>
                <span class="pill-lbl">Pendientes</span>
            </div>
        </a>
        <a class="stat-pill stat-pill--success <?= $estado==='aprobada'?'stat-pill--active':'' ?>"
           href="?estado=aprobada">
            <span class="material-symbols-rounded">check_circle</span>
            <div>
                <span class="pill-num"><?= (int)$cnt['aprobadas'] ?></span>
                <span class="pill-lbl">Aprobadas</span>
            </div>
        </a>
        <a class="stat-pill stat-pill--danger <?= $estado==='rechazada'?'stat-pill--active':'' ?>"
           href="?estado=rechazada">
            <span class="material-symbols-rounded">cancel</span>
            <div>
                <span class="pill-num"><?= (int)$cnt['rechazadas'] ?></span>
                <span class="pill-lbl">Rechazadas</span>
            </div>
        </a>
        <a class="stat-pill stat-pill--neutral <?= $estado==='todos'?'stat-pill--active':'' ?>"
           href="?estado=todos">
            <span class="material-symbols-rounded">list_alt</span>
            <div>
                <span class="pill-num"><?= (int)$cnt['total'] ?></span>
                <span class="pill-lbl">Total</span>
            </div>
        </a>
    </div>

    <!-- ══ Toolbar ══════════════════════════════════════════ -->
    <div class="toolbar">
        <form method="GET" class="search-box" id="searchForm">
            <span class="material-symbols-rounded">search</span>
            <input type="text" name="q"
                   value="<?= htmlspecialchars($buscar) ?>"
                   placeholder="Buscar por nombre, email, documento o programa…"
                   autocomplete="off"
                   oninput="clearTimeout(window._st);window._st=setTimeout(()=>this.form.submit(),400)">
            <input type="hidden" name="estado" value="<?= htmlspecialchars($estado) ?>">
        </form>
        <div class="filter-select">
            <span class="material-symbols-rounded">filter_list</span>
            <select onchange="location.href='?estado='+this.value+'&q=<?= urlencode($buscar) ?>'">
                <option value="todos"     <?= $estado==='todos'    ?'selected':'' ?>>Todos los estados</option>
                <option value="pendiente" <?= $estado==='pendiente'?'selected':'' ?>>Pendientes</option>
                <option value="aprobada"  <?= $estado==='aprobada' ?'selected':'' ?>>Aprobadas</option>
                <option value="rechazada" <?= $estado==='rechazada'?'selected':'' ?>>Rechazadas</option>
            </select>
        </div>
    </div>

    <!-- ══ Lista de preinscripciones ════════════════════════ -->
    <div class="inscripciones-lista">
        <?php if (empty($preinscripciones)): ?>
        <div class="empty-state">
            <span class="material-symbols-rounded">inbox</span>
            <p>No hay preinscripciones que coincidan con los filtros.</p>
        </div>
        <?php else: ?>
        <?php foreach ($preinscripciones as $p):
            $badge_cls = match($p['estado']) {
                'aprobada'  => 'badge--success',
                'rechazada' => 'badge--danger',
                default     => 'badge--warning'
            };
            $badge_lbl = match($p['estado']) {
                'aprobada'  => 'Aprobada',
                'rechazada' => 'Rechazada',
                default     => 'Pendiente'
            };
            $fecha = !empty($p['fecha_preinscripcion'])
                ? date('d/m/Y', strtotime($p['fecha_preinscripcion'])) : '—';
        ?>
        <div class="inscripcion-card" id="card-<?= $p['id'] ?>">

            <div class="card-avatar">
                <?= mb_strtoupper(mb_substr($p['nombres_apellidos'], 0, 1)) ?>
            </div>

            <div class="card-info">
                <div class="card-top">
                    <span class="card-nombre"><?= htmlspecialchars($p['nombres_apellidos']) ?></span>
                    <span class="badge <?= $badge_cls ?>"><?= $badge_lbl ?></span>
                </div>
                <span class="card-email"><?= htmlspecialchars($p['email']) ?></span>
                <div class="card-meta">
                    <span>
                        <span class="material-symbols-rounded">badge</span>
                        <?= htmlspecialchars($p['tipo_documento'] ?? '') ?>
                        <?= htmlspecialchars($p['numero_documento'] ?? '—') ?>
                    </span>
                    <span>
                        <span class="material-symbols-rounded">music_note</span>
                        <?= htmlspecialchars($p['programa'] ?? '—') ?>
                    </span>
                    <span>
                        <span class="material-symbols-rounded">calendar_today</span>
                        <?= $fecha ?>
                    </span>
                    <?php if (!empty($p['municipio'])): ?>
                    <span>
                        <span class="material-symbols-rounded">location_on</span>
                        <?= htmlspecialchars($p['municipio']) ?>
                    </span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card-actions">
                <button class="btn-icon btn-icon--view"
                        onclick="verDetalle(<?= $p['id'] ?>)"
                        title="Ver detalles">
                    <span class="material-symbols-rounded">visibility</span>
                    Ver
                </button>

                <?php if ($p['estado'] === 'pendiente'): ?>
                <button class="btn-icon btn-icon--approve"
                        onclick="confirmarAprobar(<?= $p['id'] ?>, '<?= addslashes(htmlspecialchars($p['nombres_apellidos'])) ?>')"
                        title="Aprobar">
                    <span class="material-symbols-rounded">check_circle</span>
                    Aprobar
                </button>
                <button class="btn-icon btn-icon--reject"
                        onclick="abrirModalRechazar(<?= $p['id'] ?>, '<?= addslashes(htmlspecialchars($p['nombres_apellidos'])) ?>')"
                        title="Rechazar">
                    <span class="material-symbols-rounded">cancel</span>
                    Rechazar
                </button>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- ══ Paginación ════════════════════════════════════════ -->
    <?php if ($total_paginas > 1): ?>
    <div class="pagination">
        <?php if ($pagina > 1): ?>
        <a href="?page=<?= $pagina-1 ?>&q=<?= urlencode($buscar) ?>&estado=<?= urlencode($estado) ?>"
           class="page-btn">
            <span class="material-symbols-rounded">chevron_left</span>
        </a>
        <?php endif; ?>

        <?php for ($i = max(1,$pagina-2); $i <= min($total_paginas,$pagina+2); $i++): ?>
        <a href="?page=<?= $i ?>&q=<?= urlencode($buscar) ?>&estado=<?= urlencode($estado) ?>"
           class="page-btn <?= $i===$pagina?'page-btn--active':'' ?>">
            <?= $i ?>
        </a>
        <?php endfor; ?>

        <?php if ($pagina < $total_paginas): ?>
        <a href="?page=<?= $pagina+1 ?>&q=<?= urlencode($buscar) ?>&estado=<?= urlencode($estado) ?>"
           class="page-btn">
            <span class="material-symbols-rounded">chevron_right</span>
        </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</main><!-- /main-content -->


<!-- ══════════════════════════════════════════════════════════
     MODAL: Ver Detalle completo
     ══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modalDetalle" aria-hidden="true">
    <div class="modal modal--lg">
        <div class="modal-header">
            <h2 class="modal-title">
                <span class="material-symbols-rounded">person</span>
                Detalle de Preinscripción
            </h2>
            <button class="modal-close" onclick="cerrarModal('modalDetalle')">
                <span class="material-symbols-rounded">close</span>
            </button>
        </div>
        <div class="modal-body" id="detalleContent">
            <p class="loading-txt">Cargando información…</p>
        </div>
        <div class="modal-footer" id="detalleFooter">
            <button class="btn-secondary" onclick="cerrarModal('modalDetalle')">Cerrar</button>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     MODAL: Confirmar Aprobar
     ══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modalAprobar" aria-hidden="true">
    <div class="modal modal--sm">
        <div class="modal-header modal-header--success">
            <h2 class="modal-title">
                <span class="material-symbols-rounded">check_circle</span>
                Confirmar Aprobación
            </h2>
            <button class="modal-close" onclick="cerrarModal('modalAprobar')">
                <span class="material-symbols-rounded">close</span>
            </button>
        </div>
        <div class="modal-body">
            <p id="aprobarTexto" style="font-size:.95rem;color:var(--text-primary);margin-bottom:14px;"></p>
            <div class="modal-info-box modal-info-box--success">
                <span class="material-symbols-rounded">key</span>
                Se creará el usuario con <strong>contraseña temporal = número de documento</strong>.
                El estudiante podrá cambiarla al iniciar sesión.
            </div>
        </div>
        <form method="POST">
            <input type="hidden" name="accion" value="aprobar">
            <input type="hidden" name="id"     id="aprobarId">
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="cerrarModal('modalAprobar')">Cancelar</button>
                <button type="submit" class="btn-success">
                    <span class="material-symbols-rounded">check_circle</span>
                    Sí, aprobar
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     MODAL: Rechazar
     ══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modalRechazar" aria-hidden="true">
    <div class="modal modal--sm">
        <div class="modal-header modal-header--danger">
            <h2 class="modal-title">
                <span class="material-symbols-rounded">cancel</span>
                Rechazar Preinscripción
            </h2>
            <button class="modal-close" onclick="cerrarModal('modalRechazar')">
                <span class="material-symbols-rounded">close</span>
            </button>
        </div>
        <div class="modal-body">
            <p id="rechazarNombre" class="rechazar-nombre"></p>
            <form method="POST" id="formRechazar">
                <input type="hidden" name="accion" value="rechazar">
                <input type="hidden" name="id"     id="rechazarId">
                <div class="form-group-modal">
                    <label for="motivoRechazo">Motivo del rechazo <span class="req">*</span></label>
                    <textarea id="motivoRechazo" name="motivo" rows="4"
                              placeholder="Explica el motivo del rechazo…" required></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="cerrarModal('modalRechazar')">Cancelar</button>
                    <button type="submit" class="btn-danger">
                        <span class="material-symbols-rounded">cancel</span>
                        Confirmar Rechazo
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     MODAL: Crear Preinscripción (Admin)
     ══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modalCrear" aria-hidden="true">
    <div class="modal modal--lg">
        <div class="modal-header modal-header--primary">
            <h2 class="modal-title">
                <span class="material-symbols-rounded">person_add</span>
                Nueva Preinscripción
                <span class="modal-badge">Aprobación automática</span>
            </h2>
            <button class="modal-close" onclick="cerrarModal('modalCrear')">
                <span class="material-symbols-rounded">close</span>
            </button>
        </div>
        <form method="POST" id="formCrear" onsubmit="return validarFormCrear()">
            <input type="hidden" name="accion" value="crear_admin">
            <div class="modal-body">
                <div class="modal-info-box">
                    <span class="material-symbols-rounded">info</span>
                    Al crear desde el panel, la preinscripción se aprueba de inmediato y se genera el
                    usuario con <strong>contraseña = número de documento</strong>.
                </div>
                <div class="modal-grid">
                    <div class="form-group-modal col-full">
                        <label>Nombres y apellidos <span class="req">*</span></label>
                        <input type="text" name="nombres_apellidos" required
                               placeholder="Nombre completo del estudiante">
                    </div>
                    <div class="form-group-modal">
                        <label>Tipo de documento <span class="req">*</span></label>
                        <select name="tipo_documento" required>
                            <option value="TI">Tarjeta de Identidad (TI)</option>
                            <option value="CC">Cédula de Ciudadanía (CC)</option>
                            <option value="CE">Cédula de Extranjería (CE)</option>
                            <option value="PA">Pasaporte (PA)</option>
                            <option value="RC">Registro Civil (RC)</option>
                        </select>
                    </div>
                    <div class="form-group-modal">
                        <label>Número de documento <span class="req">*</span></label>
                        <input type="text" name="numero_documento" required
                               placeholder="Sin puntos ni guiones" id="inputDoc">
                    </div>
                    <div class="form-group-modal">
                        <label>Correo electrónico <span class="req">*</span></label>
                        <input type="email" name="email" required placeholder="correo@ejemplo.com">
                    </div>
                    <div class="form-group-modal">
                        <label>Celular</label>
                        <input type="tel" name="celular" placeholder="3XX XXX XXXX">
                    </div>
                    <div class="form-group-modal">
                        <label>Programa <span class="req">*</span></label>
                        <select name="programa" required>
                            <option value="">— Selecciona —</option>
                            <option>Iniciación Musical Infantil</option>
                            <option>Guitarra</option>
                            <option>Piano</option>
                            <option>Instrumentos de Viento</option>
                            <option>Técnica Vocal y Canto</option>
                            <option>Teoría y Lenguaje Musical</option>
                            <option>Ensambles Musicales</option>
                            <option>Preparación Universitaria</option>
                        </select>
                    </div>
                    <div class="form-group-modal">
                        <label>Municipio</label>
                        <input type="text" name="municipio" placeholder="Ej: El Carmen de Viboral">
                    </div>
                    <div class="form-group-modal">
                        <label>Dirección</label>
                        <input type="text" name="direccion" placeholder="Dirección de residencia">
                    </div>
                    <div class="form-group-modal">
                        <label>Fecha de nacimiento</label>
                        <input type="date" name="fecha_nacimiento">
                    </div>
                    <div class="form-group-modal">
                        <label>Nombre del acudiente</label>
                        <input type="text" name="nombre_acudiente">
                    </div>
                    <div class="form-group-modal">
                        <label>Teléfono del acudiente</label>
                        <input type="tel" name="telefono_acudiente" placeholder="3XX XXX XXXX">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="cerrarModal('modalCrear')">Cancelar</button>
                <button type="submit" class="btn-primary">
                    <span class="material-symbols-rounded">check</span>
                    Crear y Aprobar
                </button>
            </div>
        </form>
    </div>
</div>


<!-- ══════════════════════════════════════════════════════════
     DATOS JSON para el modal de detalle (solo página actual)
     ══════════════════════════════════════════════════════════ -->
<script>
const DATA = <?= json_encode(
    array_column($preinscripciones, null, 'id'),
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG
) ?>;

// ── Helpers modales ───────────────────────────────────────
function abrirModal(id) {
    const m = document.getElementById(id);
    if (!m) return;
    m.classList.add('open');
    m.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
}
function cerrarModal(id) {
    const m = document.getElementById(id);
    if (!m) return;
    m.classList.remove('open');
    m.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
}

// Cerrar al hacer clic en el overlay
document.querySelectorAll('.modal-overlay').forEach(o =>
    o.addEventListener('click', e => { if (e.target === o) cerrarModal(o.id); })
);

// Cerrar con Escape
document.addEventListener('keydown', e => {
    if (e.key === 'Escape')
        document.querySelectorAll('.modal-overlay.open').forEach(o => cerrarModal(o.id));
});

// ── Confirmar aprobar ─────────────────────────────────────
function confirmarAprobar(id, nombre) {
    document.getElementById('aprobarId').value      = id;
    document.getElementById('aprobarTexto').innerHTML =
        `¿Aprobar la preinscripción de <strong>${nombre}</strong>?`;
    abrirModal('modalAprobar');
}

// ── Abrir modal rechazar ──────────────────────────────────
function abrirModalRechazar(id, nombre) {
    document.getElementById('rechazarId').value         = id;
    document.getElementById('rechazarNombre').innerHTML =
        `Rechazando solicitud de: <strong>${nombre}</strong>`;
    document.getElementById('motivoRechazo').value      = '';
    abrirModal('modalRechazar');
}

// ── Ver detalle ───────────────────────────────────────────
function verDetalle(id) {
    const p = DATA[id];
    if (!p) { alert('No se encontraron los datos.'); return; }

    const val  = v => (v !== null && v !== undefined && v !== '')
        ? v : '<span class="nd">—</span>';
    const fmt  = f => f ? new Date(f + 'T00:00:00').toLocaleDateString('es-CO') : '—';
    const st   = { aprobada:'badge--success', rechazada:'badge--danger', pendiente:'badge--warning' };
    const sl   = { aprobada:'Aprobada', rechazada:'Rechazada', pendiente:'Pendiente' };

    const html = `
    <div class="detalle-grid">

        <!-- Encabezado -->
        <div class="detalle-seccion col-full">
            <div class="detalle-avatar">${p.nombres_apellidos.charAt(0).toUpperCase()}</div>
            <div>
                <h3 class="detalle-nombre">${p.nombres_apellidos}</h3>
                <span class="badge ${st[p.estado]||'badge--warning'}" style="margin-bottom:4px;display:inline-flex;">
                    ${sl[p.estado]||p.estado}
                </span>
                <p class="detalle-sub">${p.email} &bull; ${p.celular||'—'}</p>
            </div>
        </div>

        <!-- Programa -->
        <div class="detalle-seccion">
            <h4 class="det-title"><span class="material-symbols-rounded">music_note</span>Programa</h4>
            <div class="det-row"><span>Programa:</span>${val(p.programa)}</div>
            <div class="det-row"><span>Taller:</span>${val(p.taller)}</div>
            <div class="det-row"><span>Día clase:</span>${val(p.dia_clase)}</div>
            <div class="det-row"><span>Hora:</span>${val(p.hora_clase)}</div>
            <div class="det-row"><span>Inicio:</span>${fmt(p.fecha_inicio)}</div>
        </div>

        <!-- Identificación -->
        <div class="detalle-seccion">
            <h4 class="det-title"><span class="material-symbols-rounded">badge</span>Identificación</h4>
            <div class="det-row"><span>Tipo doc.:</span>${val(p.tipo_documento)}</div>
            <div class="det-row"><span>Número:</span>${val(p.numero_documento)}</div>
            <div class="det-row"><span>Nacimiento:</span>${fmt(p.fecha_nacimiento)}</div>
            <div class="det-row"><span>Edad:</span>${val(p.edad)}</div>
            <div class="det-row"><span>Lugar nac.:</span>${val(p.lugar_nacimiento)}</div>
        </div>

        <!-- Ubicación -->
        <div class="detalle-seccion">
            <h4 class="det-title"><span class="material-symbols-rounded">location_on</span>Ubicación</h4>
            <div class="det-row"><span>Dirección:</span>${val(p.direccion)}</div>
            <div class="det-row"><span>Barrio:</span>${val(p.barrio)}</div>
            <div class="det-row"><span>Municipio:</span>${val(p.municipio)}</div>
            <div class="det-row"><span>Zona:</span>${val(p.zona)}</div>
            <div class="det-row"><span>Estrato:</span>${val(p.estrato)}</div>
        </div>

        <!-- Salud y Socioeconómico -->
        <div class="detalle-seccion">
            <h4 class="det-title"><span class="material-symbols-rounded">local_hospital</span>Salud y Socioecon.</h4>
            <div class="det-row"><span>EPS:</span>${val(p.eps)}</div>
            <div class="det-row"><span>SISBEN:</span>${val(p.nivel_sisben)}</div>
            <div class="det-row"><span>Ocupación:</span>${val(p.ocupacion)}</div>
            <div class="det-row"><span>Institución:</span>${val(p.institucion_educativa)}</div>
        </div>

        <!-- Acudiente -->
        <div class="detalle-seccion">
            <h4 class="det-title"><span class="material-symbols-rounded">family_restroom</span>Acudiente</h4>
            <div class="det-row"><span>Nombre:</span>${val(p.nombre_acudiente)}</div>
            <div class="det-row"><span>Parentesco:</span>${val(p.parentesco_acudiente)}</div>
            <div class="det-row"><span>Teléfono:</span>${val(p.telefono_acudiente)}</div>
            <div class="det-row"><span>Email:</span>${val(p.email_acudiente)}</div>
            <div class="det-row"><span>N° Recibo:</span>${val(p.numero_recibo)}</div>
        </div>

        <!-- Autorización imagen -->
        <div class="detalle-seccion">
            <h4 class="det-title"><span class="material-symbols-rounded">photo_camera</span>Autorización Imagen</h4>
            <div class="det-row">
                <span>Autoriza:</span>
                ${p.autoriza_imagen == 1
                    ? '<span class="text-success">Sí autoriza</span>'
                    : '<span class="text-danger">No autoriza</span>'}
            </div>
            <div class="det-row"><span>CC acudiente:</span>${val(p.firma_acudiente_cc)}</div>
            <div class="det-row"><span>TI estudiante:</span>${val(p.ti_estudiante)}</div>
        </div>

        ${p.observaciones ? `
        <div class="detalle-seccion col-full">
            <h4 class="det-title"><span class="material-symbols-rounded">notes</span>Observaciones</h4>
            <p class="det-observaciones">${p.observaciones}</p>
        </div>` : ''}

        ${p.motivo_rechazo ? `
        <div class="detalle-seccion col-full detalle-seccion--danger">
            <h4 class="det-title"><span class="material-symbols-rounded">report</span>Motivo de Rechazo</h4>
            <p class="det-observaciones">${p.motivo_rechazo}</p>
        </div>` : ''}

    </div>`;

    document.getElementById('detalleContent').innerHTML = html;

    // Footer dinámico según estado
    const footer = document.getElementById('detalleFooter');
    if (p.estado === 'pendiente') {
        const n = p.nombres_apellidos.replace(/'/g, "\\'");
        footer.innerHTML = `
            <button class="btn-secondary" onclick="cerrarModal('modalDetalle')">Cerrar</button>
            <button class="btn-danger"
                    onclick="cerrarModal('modalDetalle');abrirModalRechazar(${p.id},'${n}')">
                <span class="material-symbols-rounded">cancel</span> Rechazar
            </button>
            <button class="btn-success"
                    onclick="cerrarModal('modalDetalle');confirmarAprobar(${p.id},'${n}')">
                <span class="material-symbols-rounded">check_circle</span> Aprobar
            </button>`;
    } else {
        footer.innerHTML = `
            <button class="btn-secondary" onclick="cerrarModal('modalDetalle')">Cerrar</button>`;
    }

    abrirModal('modalDetalle');
}

// ── Validar form crear ─────────────────
// ───────────────────
function validarFormCrear() {
    const doc = document.getElementById('inputDoc').value.trim();
    if (doc.length < 5) { alert('El número de documento debe tener al menos 5 caracteres.'); return false; }
    return true;
}

// Auto-ocultar alerta tras 7s
setTimeout(() => {
    const a = document.getElementById('alertMsg');
    if (a) { a.style.transition='opacity .5s'; a.style.opacity='0'; setTimeout(()=>a.remove(),500); }
}, 7000);
</script>

</body>
</html>