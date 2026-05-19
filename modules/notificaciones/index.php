<?php

require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth_check.php';
require_once '../../includes/notificaciones_helper.php';

// ─── Acciones AJAX ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    header('Content-Type: application/json');
    $accion   = $_POST['accion'];
    $response = ['success' => false, 'message' => 'Acción no válida'];

    try {
        switch ($accion) {

            case 'marcar_leida':
                $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
                if ($id > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE notificaciones
                        SET leida = 1, fecha_lectura = NOW()
                        WHERE id = ? AND usuario_id = ?
                    ");
                    $stmt->execute([$id, $_SESSION['user_id']]);
                    $response = $stmt->rowCount() > 0
                        ? ['success' => true,  'message' => 'Notificación marcada como leída']
                        : ['success' => false, 'message' => 'Notificación no encontrada'];
                }
                break;

            case 'marcar_todas_leidas':
                $stmt = $pdo->prepare("
                    UPDATE notificaciones
                    SET leida = 1, fecha_lectura = NOW()
                    WHERE usuario_id = ? AND leida = 0
                ");
                $stmt->execute([$_SESSION['user_id']]);
                $response = [
                    'success' => true,
                    'message' => "Se marcaron {$stmt->rowCount()} notificaciones como leídas",
                    'count'   => $stmt->rowCount(),
                ];
                break;

            case 'eliminar':
                $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
                if ($id > 0) {
                    $stmt = $pdo->prepare("
                        DELETE FROM notificaciones
                        WHERE id = ? AND usuario_id = ?
                    ");
                    $stmt->execute([$id, $_SESSION['user_id']]);
                    $response = $stmt->rowCount() > 0
                        ? ['success' => true,  'message' => 'Notificación eliminada']
                        : ['success' => false, 'message' => 'Notificación no encontrada'];
                }
                break;

            case 'contar_sin_leer':
                $response = [
                    'success'  => true,
                    'sin_leer' => NotificacionesHelper::contarSinLeer($pdo, $_SESSION['user_id']),
                ];
                break;
        }
    } catch (PDOException $e) {
        error_log("Error en acción de notificaciones: " . $e->getMessage());
        $response = ['success' => false, 'message' => 'Error del sistema'];
    }

    echo json_encode($response);
    exit;
}

// ─── Datos del usuario ───────────────────────────────────────────────────────
try {
    $stmt = $pdo->prepare("
        SELECT id, nombre, email, rol, estado, foto_perfil
        FROM usuarios WHERE id = ? AND estado = 'activo'
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
    die("Error del sistema.");
}

// ─── Handler: Crear notificación (modal) ─────────────────────────────────────
$modal_crear_error = null;
$modal_crear_open  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'crear_notificacion' && $user['rol'] === 'admin') {
    $destinatarios = $_POST['destinatarios'] ?? [];
    $enviar_todos  = !empty($_POST['enviar_todos']);
    $enviar_rol    = trim($_POST['enviar_rol'] ?? '');
    $tipo          = $_POST['tipo']      ?? '';
    $titulo        = trim($_POST['titulo']   ?? '');
    $mensaje       = trim($_POST['mensaje']  ?? '');
    $enlace        = trim($_POST['enlace']   ?? '');
    $prioridad     = $_POST['prioridad'] ?? 'normal';
    $enviar_rol = trim($_POST['enviar_rol'] ?? '');
    
    if (empty($tipo) || empty($titulo) || empty($mensaje)
        || (!$enviar_todos && $enviar_rol === '' && empty($destinatarios))) {
        $modal_crear_error = "Completa todos los campos obligatorios.";
        $modal_crear_open  = true;
    } else {
        try {
            $pdo->beginTransaction();
            if ($enviar_todos) {
                $st = $pdo->query("SELECT id FROM usuarios WHERE estado='activo'");
                $destinatarios = $st->fetchAll(PDO::FETCH_COLUMN);
            } elseif ($enviar_rol !== '') {
                $st = $pdo->prepare("SELECT id FROM usuarios WHERE estado='activo' AND rol = ?");
                $st->execute([$enviar_rol]);
                $destinatarios = $st->fetchAll(PDO::FETCH_COLUMN);
            }
            $st = $pdo->prepare("
                INSERT INTO notificaciones (usuario_id, tipo, titulo, mensaje, enlace, prioridad, emisor, fecha_creacion)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            foreach ($destinatarios as $uid) {
                $st->execute([(int)$uid, $tipo, $titulo, $mensaje, $enlace ?: null, $prioridad, $user['nombre']]);
            }
            $pdo->commit();
            header("Location: index.php?msg=enviadas&total=" . count($destinatarios));
            exit;
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log($e->getMessage());
            $modal_crear_error = "Error al enviar las notificaciones. Inténtalo de nuevo.";
            $modal_crear_open  = true;
        }
    }
}

// ─── Filtros ─────────────────────────────────────────────────────────────────
$filtro_tipo   = isset($_GET['tipo'])   ? $_GET['tipo']   : 'todas';
$filtro_estado = isset($_GET['estado']) ? $_GET['estado'] : 'todas';

// ─── Consulta ────────────────────────────────────────────────────────────────
$sql = "
    SELECT id, tipo, titulo, mensaje, enlace,
           leida, prioridad, emisor, fecha_creacion, fecha_lectura
    FROM notificaciones
    WHERE usuario_id = :usuario_id
";
$params = ['usuario_id' => $_SESSION['user_id']];

if ($filtro_tipo !== 'todas') {
    $sql .= " AND tipo = :tipo";
    $params['tipo'] = $filtro_tipo;
}
if ($filtro_estado === 'leidas')    $sql .= " AND leida = 1";
elseif ($filtro_estado === 'no_leidas') $sql .= " AND leida = 0";

$sql .= " ORDER BY leida ASC, fecha_creacion DESC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $notificaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error al obtener notificaciones: " . $e->getMessage());
    $notificaciones = [];
}

// ─── Estadísticas ────────────────────────────────────────────────────────────
$stats = NotificacionesHelper::obtenerEstadisticas($pdo, $_SESSION['user_id']);
$total_notificaciones = $stats['total'];
$sin_leer             = $stats['sin_leer'];
$preinscripciones     = $stats['preinscripciones'];
$eventos              = $stats['eventos'];

// ─── Lista de usuarios para el modal crear (solo admin) ──────────────────────
$usuarios_lista = [];
if ($user['rol'] === 'admin') {
    try {
        $stmt = $pdo->query("SELECT id, nombre, rol FROM usuarios WHERE estado='activo' ORDER BY nombre ASC");
        $usuarios_lista = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log($e->getMessage());
    }
}

// ─── Helpers ─────────────────────────────────────────────────────────────────
function tiempo_transcurrido(string $fecha): string {
    $diff = (new DateTime())->diff(new DateTime($fecha));
    if ($diff->days > 0) return "Hace {$diff->days} día" . ($diff->days > 1 ? "s" : "");
    if ($diff->h   > 0) return "Hace {$diff->h} hora"   . ($diff->h   > 1 ? "s" : "");
    if ($diff->i   > 0) return "Hace {$diff->i} minuto"  . ($diff->i   > 1 ? "s" : "");
    return "Hace unos segundos";
}

function icono_tipo(string $tipo): array {
    return [
        'sistema'        => ['icono' => 'notifications', 'color' => 'orange'],
        'preinscripcion' => ['icono' => 'person_add',    'color' => 'yellow'],
        'evento'         => ['icono' => 'event',         'color' => 'orange'],
        'general'        => ['icono' => 'mail',          'color' => 'pink'],
    ][$tipo] ?? ['icono' => 'notifications', 'color' => 'blue'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notificaciones - Amimbré</title>
    <link rel="shortcut icon" href="../../assets/img/3.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0" />
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="../../assets/css/colores.css">
    <link rel="stylesheet" href="../../assets/css/style-notificaciones.css">
    <script>
        (function() {
            const theme = localStorage.getItem('amimbre-theme');
            if (theme === 'light') document.documentElement.setAttribute('data-theme', 'light');
        })();
    </script>
</head>
<body>

<?php
if (file_exists('../../includes/header.php')) require_once '../../includes/header.php';
?>

<main class="main-content">

    <!-- ─── Header ──────────────────────────────────────────────────────────── -->
    <div class="notifications-header">
        <div class="header-content">
            <div class="header-left">
                <button class="back-button" onclick="window.history.back()">
                    <span class="material-symbols-rounded">arrow_back</span>
                </button>
                <div class="header-title">
                    <h1>Notificaciones</h1>
                    <p>
                        <?php if ($sin_leer > 0): ?>
                            Tienes <strong><?php echo $sin_leer; ?></strong> notificaciones sin leer
                        <?php else: ?>
                            Estás al día con todas tus notificaciones
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            <div class="header-actions">
                <?php if ($sin_leer > 0): ?>
                <button class="btn-secondary" id="marcarTodasLeidas">
                    <span class="material-symbols-rounded">done_all</span>
                    Marcar todas como leídas
                </button>
                <?php endif; ?>
                <?php if ($user['rol'] === 'admin'): ?>
                <button class="btn-primary" onclick="openModal('createModal')">
                    <span class="material-symbols-rounded">add</span>
                    Nueva notificación
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (isset($_GET['msg']) && $_GET['msg'] === 'enviadas'): ?>
    <div class="flash-noti-success" id="flashEnviadas">
        <span class="material-symbols-rounded">check_circle</span>
        Notificación enviada correctamente a <strong><?= (int)($_GET['total'] ?? 0) ?></strong> usuario(s).
    </div>
    <?php endif; ?>

    <!-- ─── Stats ───────────────────────────────────────────────────────────── -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon total">
                <span class="material-symbols-rounded">notifications</span>
            </div>
            <div class="stat-info">
                <div class="stat-value"><?php echo $total_notificaciones; ?></div>
                <div class="stat-label">Total</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon unread">
                <span class="material-symbols-rounded">mark_email_unread</span>
            </div>
            <div class="stat-info">
                <div class="stat-value"><?php echo $sin_leer; ?></div>
                <div class="stat-label">Sin leer</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon preinscription">
                <span class="material-symbols-rounded">person_add</span>
            </div>
            <div class="stat-info">
                <div class="stat-value"><?php echo $preinscripciones; ?></div>
                <div class="stat-label">Preinscripciones</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon event">
                <span class="material-symbols-rounded">event</span>
            </div>
            <div class="stat-info">
                <div class="stat-value"><?php echo $eventos; ?></div>
                <div class="stat-label">Eventos</div>
            </div>
        </div>
    </div>

    <!-- ─── Buscador + Filtros en chip-bar ──────────────────────────────────── -->
    <div class="filters-toolbar">
        <div class="search-box">
            <span class="material-symbols-rounded">search</span>
            <input type="text" id="searchNotifications" placeholder="Buscar notificaciones…">
        </div>

        <div class="chip-bar">
            <!-- Tipo -->
            <div class="chip-group">
                <span class="chip-group-label">Tipo:</span>
                <?php
                $tipos = [
                    'todas'          => ['label' => 'Todas',           'icon' => 'select_all'],
                    'sistema'        => ['label' => 'Sistema',         'icon' => 'notifications'],
                    'preinscripcion' => ['label' => 'Preinscripciones','icon' => 'person_add'],
                    'evento'         => ['label' => 'Eventos',         'icon' => 'event'],
                    'general'        => ['label' => 'General',         'icon' => 'mail'],
                ];
                foreach ($tipos as $val => $data):
                    $active = $filtro_tipo === $val ? 'active' : '';
                ?>
                <a href="?tipo=<?php echo $val; ?>&estado=<?php echo $filtro_estado; ?>"
                   class="chip <?php echo $active; ?>">
                    <span class="material-symbols-rounded"><?php echo $data['icon']; ?></span>
                    <?php echo $data['label']; ?>
                </a>
                <?php endforeach; ?>
            </div>

            <div class="chip-divider"></div>

            <!-- Estado -->
            <div class="chip-group">
                <span class="chip-group-label">Estado:</span>
                <?php
                $estados = [
                    'todas'     => ['label' => 'Todas',    'icon' => 'inbox'],
                    'no_leidas' => ['label' => 'Sin leer', 'icon' => 'mark_email_unread'],
                    'leidas'    => ['label' => 'Leídas',   'icon' => 'done_all'],
                ];
                foreach ($estados as $val => $data):
                    $active = $filtro_estado === $val ? 'active' : '';
                ?>
                <a href="?tipo=<?php echo $filtro_tipo; ?>&estado=<?php echo $val; ?>"
                   class="chip <?php echo $active; ?>">
                    <span class="material-symbols-rounded"><?php echo $data['icon']; ?></span>
                    <?php echo $data['label']; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- ─── Lista de notificaciones ─────────────────────────────────────────── -->
    <div class="notifications-container" id="notificationsContainer">
        <?php if (count($notificaciones) > 0): ?>
            <?php foreach ($notificaciones as $notif):
                $icono_data  = icono_tipo($notif['tipo']);
                $leida_class = $notif['leida'] ? 'read' : 'unread';
            ?>
            <div class="notification-card <?php echo $leida_class; ?>"
                 data-id="<?php echo $notif['id']; ?>"
                 data-titulo="<?php echo htmlspecialchars(strtolower($notif['titulo'])); ?>"
                 data-mensaje="<?php echo htmlspecialchars(strtolower($notif['mensaje'])); ?>"
                 data-full-titulo="<?php echo htmlspecialchars($notif['titulo']); ?>"
                 data-full-mensaje="<?php echo htmlspecialchars($notif['mensaje']); ?>"
                 data-tipo="<?php echo htmlspecialchars($notif['tipo']); ?>"
                 data-emisor="<?php echo htmlspecialchars($notif['emisor']); ?>"
                 data-fecha="<?php echo htmlspecialchars($notif['fecha_creacion']); ?>"
                 data-prioridad="<?php echo htmlspecialchars($notif['prioridad']); ?>"
                 data-enlace="<?php echo htmlspecialchars($notif['enlace'] ?? ''); ?>"
                 data-leida="<?php echo $notif['leida']; ?>">

                <div class="notification-indicator"></div>

                <div class="notification-icon <?php echo $icono_data['color']; ?>">
                    <span class="material-symbols-rounded"><?php echo $icono_data['icono']; ?></span>
                </div>

                <div class="notification-content">
                    <div class="notification-header">
                        <h3 class="notification-title">
                            <?php echo htmlspecialchars($notif['titulo']); ?>
                            <?php if (!$notif['leida']): ?>
                                <span class="dot-unread" title="Sin leer"></span>
                            <?php endif; ?>
                        </h3>
                        <div class="notification-meta">
                            <span class="notification-time">
                                <?php echo tiempo_transcurrido($notif['fecha_creacion']); ?>
                            </span>
                        </div>
                    </div>
                    <p class="notification-message"><?php echo htmlspecialchars($notif['mensaje']); ?></p>
                    <div class="notification-footer">
                        <span class="notification-sender">De: <?php echo htmlspecialchars($notif['emisor']); ?></span>
                        <?php if ($notif['prioridad'] === 'alta'): ?>
                            <span class="badge-priority high">Alta prioridad</span>
                        <?php endif; ?>
                        <?php if ($notif['leida'] && $notif['fecha_lectura']): ?>
                            <span class="badge-read">
                                <span class="material-symbols-rounded" style="font-size:14px">done_all</span>
                                Leída
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="notification-actions">
                    <?php if (!$notif['leida']): ?>
                    <button class="action-btn"
                            onclick="marcarLeida(<?php echo $notif['id']; ?>)"
                            title="Marcar como leída">
                        <span class="material-symbols-rounded">check</span>
                    </button>
                    <?php endif; ?>

                    <button class="action-btn"
                            onclick="verDetalles(this.closest('.notification-card'))"
                            title="Ver detalles">
                        <span class="material-symbols-rounded">open_in_new</span>
                    </button>

                    <button class="action-btn delete"
                            onclick="eliminarNotificacion(<?php echo $notif['id']; ?>)"
                            title="Eliminar">
                        <span class="material-symbols-rounded">delete</span>
                    </button>
                </div>
            </div>
            <?php endforeach; ?>

        <?php else: ?>
            <div class="empty-state">
                <div class="empty-icon">
                    <span class="material-symbols-rounded">notifications_off</span>
                </div>
                <h3>No hay notificaciones</h3>
                <p>No tienes notificaciones
                    <?php echo $filtro_estado === 'no_leidas' ? 'sin leer'
                             : ($filtro_estado === 'leidas'   ? 'leídas' : ''); ?>
                    en este momento
                </p>
            </div>
        <?php endif; ?>
    </div>
</main>


<!-- ═══════════════════════════════════════════════════════════════════════════
     MODAL — Crear Notificación
═══════════════════════════════════════════════════════════════════════════ -->
<?php if ($user['rol'] === 'admin'): ?>
<div class="modal-overlay" id="createModal">
    <div class="modal-box create-modal-box">
        <button class="modal-close-btn" onclick="closeModal('createModal')">
            <span class="material-symbols-rounded">close</span>
        </button>

        <div style="margin-bottom:20px;">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
                <div style="width:40px;height:40px;border-radius:12px;background:rgba(249,115,22,.15);color:var(--primary-orange,#f97316);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <span class="material-symbols-rounded" style="font-size:20px;">send</span>
                </div>
                <div>
                    <h3 class="modal-title" style="text-align:left;margin-bottom:0;">Nueva Notificación</h3>
                    <p style="font-size:0.82rem;color:var(--text-secondary);margin:0;">Enviar a usuarios del sistema</p>
                </div>
            </div>
        </div>

        <form method="POST" id="formCrearNotificacion" novalidate>
            <input type="hidden" name="action" value="crear_notificacion">

            <div class="modal-form-group">
                <label>Tipo <span class="req-star">*</span></label>
                <select name="tipo" id="crear-tipo" class="modal-form-control">
                    <option value="">Seleccionar tipo...</option>
                    <option value="sistema">Sistema</option>
                    <option value="preinscripcion">Preinscripción</option>
                    <option value="evento">Evento</option>
                    <option value="general">General</option>
                </select>
            </div>

            <div class="modal-form-group">
                <label>Título <span class="req-star">*</span></label>
                <input type="text" name="titulo" id="crear-titulo" class="modal-form-control"
                       placeholder="Ej: Nueva actualización del sistema">
            </div>

            <div class="modal-form-group">
                <label>Mensaje <span class="req-star">*</span></label>
                <textarea name="mensaje" id="crear-mensaje" class="modal-form-control"
                          placeholder="Escribe el contenido de la notificación..."></textarea>
            </div>

            <div class="modal-form-group">
                <label>Enlace <span style="font-size:0.78rem;color:var(--text-secondary);">(opcional)</span></label>
                <input type="text" name="enlace" class="modal-form-control"
                       placeholder="/modules/...">
            </div>

            <div class="modal-form-group">
                <label>Prioridad</label>
                <select name="prioridad" class="modal-form-control">
                    <option value="normal">Normal</option>
                    <option value="alta">Alta</option>
                    <option value="baja">Baja</option>
                </select>
            </div>

            <div class="modal-form-group">
                <label>Destinatarios <span class="req-star">*</span></label>

                <!-- Selector de modo -->
                <div class="dest-mode-grid">
                    <label class="dest-mode-card active" data-mode="todos">
                        <input type="radio" name="modo_destino" value="todos" checked>
                        <span class="material-symbols-rounded">groups</span>
                        <span>Todos</span>
                    </label>
                    <label class="dest-mode-card" data-mode="admins">
                        <input type="radio" name="modo_destino" value="admins">
                        <span class="material-symbols-rounded">admin_panel_settings</span>
                        <span>Admins</span>
                    </label>
                    <label class="dest-mode-card" data-mode="profesores">
                        <input type="radio" name="modo_destino" value="profesores">
                        <span class="material-symbols-rounded">school</span>
                        <span>Profesores</span>
                    </label>
                    <label class="dest-mode-card" data-mode="estudiantes">
                        <input type="radio" name="modo_destino" value="estudiantes">
                        <span class="material-symbols-rounded">person</span>
                        <span>Estudiantes</span>
                    </label>
                    <label class="dest-mode-card" data-mode="personalizado">
                        <input type="radio" name="modo_destino" value="personalizado">
                        <span class="material-symbols-rounded">tune</span>
                        <span>Personalizado</span>
                    </label>
                </div>

                <!-- Lista personalizada (solo visible en modo personalizado) -->
                <div id="listaDestModal" class="modal-check-list" style="display:none; margin-top:10px;">
                    <?php foreach ($usuarios_lista as $u): ?>
                    <label class="modal-check-item"
                        data-rol="<?= htmlspecialchars($u['rol']) ?>">
                        <input type="checkbox" name="destinatarios[]" value="<?= $u['id'] ?>">
                        <span><?= htmlspecialchars($u['nombre']) ?></span>
                        <span class="modal-user-rol"><?= ucfirst($u['rol']) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>

                <!-- Campo oculto para modos que no son personalizados -->
                <input type="hidden" name="enviar_todos"      id="hid_todos"      value="">
                <input type="hidden" name="enviar_rol"         id="hid_rol"         value="">
            </div>

            <div id="modal-alert-crear-noti" class="modal-alert-noti"></div>

            <div class="modal-actions" style="margin-top:20px;">
                <button type="button" class="modal-btn cancel" onclick="closeModal('createModal')">Cancelar</button>
                <button type="submit" class="modal-btn confirm" id="btnEnviarModal">
                    <span class="material-symbols-rounded">send</span>
                    Enviar notificación
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════════════════════
     MODAL — Confirmación "Marcar todas como leídas"
═══════════════════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="confirmModal">
    <div class="modal-box">
        <div class="modal-icon confirm-icon">
            <span class="material-symbols-rounded">done_all</span>
        </div>
        <h3 class="modal-title">¿Marcar todas como leídas?</h3>
        <p class="modal-desc">Se marcarán todas tus notificaciones sin leer como leídas. Esta acción no se puede deshacer.</p>
        <div class="modal-actions">
            <button class="modal-btn cancel" id="confirmCancel">Cancelar</button>
            <button class="modal-btn confirm" id="confirmOk">
                <span class="material-symbols-rounded">done_all</span>
                Sí, marcar todas
            </button>
        </div>
    </div>
</div>


<!-- ═══════════════════════════════════════════════════════════════════════════
     MODAL — Detalle de notificación
═══════════════════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="detailModal">
    <div class="modal-box detail-modal-box">
        <button class="modal-close-btn" id="detailClose">
            <span class="material-symbols-rounded">close</span>
        </button>

        <div class="detail-header">
            <div class="detail-icon-wrap" id="detailIconWrap">
                <span class="material-symbols-rounded" id="detailIcon">notifications</span>
            </div>
            <div class="detail-meta">
                <span class="detail-tipo-badge" id="detailTipo">—</span>
                <span class="detail-prioridad" id="detailPrioridad"></span>
            </div>
        </div>

        <h2 class="detail-title" id="detailTitulo">—</h2>
        <p class="detail-message" id="detailMensaje">—</p>

        <div class="detail-info-grid">
            <div class="detail-info-item">
                <span class="material-symbols-rounded">person</span>
                <div>
                    <span class="detail-info-label">Remitente</span>
                    <span class="detail-info-value" id="detailEmisor">—</span>
                </div>
            </div>
            <div class="detail-info-item">
                <span class="material-symbols-rounded">schedule</span>
                <div>
                    <span class="detail-info-label">Recibida</span>
                    <span class="detail-info-value" id="detailFecha">—</span>
                </div>
            </div>
        </div>

        <div class="detail-actions" id="detailActions"></div>
    </div>
</div>


<!-- ═══════════════════════════════════════════════════════════════════════════
     TOAST de confirmación
═══════════════════════════════════════════════════════════════════════════ -->
<div class="toast" id="toastMsg">
    <span class="material-symbols-rounded toast-icon" id="toastIcon">check_circle</span>
    <span id="toastText">Acción realizada</span>
</div>


<!-- ─── JavaScript ───────────────────────────────────────────────────────── -->
<script>
/* ── Utilidades AJAX ──────────────────────────────────────────────────────── */
function enviarAccion(accion, datos = {}) {
    const fd = new FormData();
    fd.append('accion', accion);
    Object.entries(datos).forEach(([k, v]) => fd.append(k, v));
    return fetch('index.php', { method: 'POST', body: fd }).then(r => r.json());
}

/* ── Toast ────────────────────────────────────────────────────────────────── */
function showToast(msg, type = 'success') {
    const t = document.getElementById('toastMsg');
    const i = document.getElementById('toastIcon');
    document.getElementById('toastText').textContent = msg;
    i.textContent = type === 'success' ? 'check_circle' : 'error';
    t.className = `toast ${type}`;
    void t.offsetWidth;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 3000);
}

/* ── Modal helpers ────────────────────────────────────────────────────────── */
function openModal(id) {
    document.getElementById(id).classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeModal(id) {
    document.getElementById(id).classList.remove('open');
    document.body.style.overflow = '';
}

// Cerrar al hacer clic en el overlay
document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', function(e) {
        if (e.target === this) closeModal(this.id);
    });
});
// Esc
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        closeModal('confirmModal');
        closeModal('detailModal');
        closeModal('createModal');
    }
});

/* ── Confirmar "marcar todas" ─────────────────────────────────────────────── */
const btnTodas = document.getElementById('marcarTodasLeidas');
if (btnTodas) {
    btnTodas.addEventListener('click', () => openModal('confirmModal'));
}
document.getElementById('confirmCancel').addEventListener('click', () => closeModal('confirmModal'));
document.getElementById('confirmOk').addEventListener('click', function() {
    this.disabled = true;
    this.innerHTML = '<span class="material-symbols-rounded">hourglass_empty</span> Procesando…';
    enviarAccion('marcar_todas_leidas')
        .then(data => {
            closeModal('confirmModal');
            if (data.success) {
                showToast(`${data.count} notificaciones marcadas como leídas`);
                setTimeout(() => location.reload(), 1200);
            } else {
                showToast('Ocurrió un error', 'error');
            }
        })
        .catch(() => {
            closeModal('confirmModal');
            showToast('Error de conexión', 'error');
        });
});

/* ── Marcar una como leída ────────────────────────────────────────────────── */
function marcarLeida(id) {
    enviarAccion('marcar_leida', { id })
        .then(data => {
            if (data.success) {
                showToast('Notificación marcada como leída');
                setTimeout(() => location.reload(), 900);
            } else {
                showToast('No se encontró la notificación', 'error');
            }
        })
        .catch(() => showToast('Error de conexión', 'error'));
}

/* ── Eliminar ─────────────────────────────────────────────────────────────── */
function eliminarNotificacion(id) {
    const modal = document.getElementById('confirmModal');
    modal.querySelector('.confirm-icon').innerHTML = '<span class="material-symbols-rounded">delete</span>';
    modal.querySelector('.confirm-icon').style.background = 'var(--subtle-red)';
    modal.querySelector('.confirm-icon').style.color = 'var(--primary-red)';
    modal.querySelector('.modal-title').textContent = '¿Eliminar notificación?';
    modal.querySelector('.modal-desc').textContent = 'Esta notificación se eliminará permanentemente.';
    const btnOk = document.getElementById('confirmOk');
    btnOk.style.background = 'var(--subtle-red)';
    btnOk.style.color = 'var(--primary-red)';
    btnOk.innerHTML = '<span class="material-symbols-rounded">delete</span> Eliminar';

    const nuevoBtn = btnOk.cloneNode(true);
    btnOk.parentNode.replaceChild(nuevoBtn, btnOk);
    nuevoBtn.addEventListener('click', function() {
        this.disabled = true;
        enviarAccion('eliminar', { id })
            .then(data => {
                closeModal('confirmModal');
                if (data.success) {
                    showToast('Notificación eliminada');
                    setTimeout(() => location.reload(), 900);
                } else {
                    showToast('No se encontró la notificación', 'error');
                }
            })
            .catch(() => {
                closeModal('confirmModal');
                showToast('Error de conexión', 'error');
            });
    });

    openModal('confirmModal');
}

/* ── Ver detalles ─────────────────────────────────────────────────────────── */
const iconoMap = {
    sistema:        { icono: 'notifications', bg: 'rgba(249,115,22,.15)', color: 'var(--primary-orange,#f97316)' },
    preinscripcion: { icono: 'person_add',    bg: 'rgba(234,179,8,.15)',  color: '#eab308' },
    evento:         { icono: 'event',         bg: 'rgba(249,115,22,.15)', color: 'var(--primary-orange,#f97316)' },
    general:        { icono: 'mail',          bg: 'rgba(236,72,153,.15)', color: '#ec4899' },
};

function verDetalles(card) {
    const d = card.dataset;
    const tipoData = iconoMap[d.tipo] || iconoMap['general'];

    const iconWrap = document.getElementById('detailIconWrap');
    iconWrap.style.background = tipoData.bg;
    iconWrap.style.color      = tipoData.color;
    document.getElementById('detailIcon').textContent = tipoData.icono;

    const tipoBadge = document.getElementById('detailTipo');
    tipoBadge.textContent   = d.tipo.charAt(0).toUpperCase() + d.tipo.slice(1);
    tipoBadge.style.background = tipoData.bg;
    tipoBadge.style.color      = tipoData.color;

    const prioEl = document.getElementById('detailPrioridad');
    if (d.prioridad === 'alta') {
        prioEl.textContent  = '⚠ Alta prioridad';
        prioEl.className    = 'detail-prioridad alta';
    } else {
        prioEl.textContent  = 'Prioridad ' + d.prioridad;
        prioEl.className    = 'detail-prioridad';
    }

    document.getElementById('detailTitulo').textContent  = d.fullTitulo;
    document.getElementById('detailMensaje').textContent = d.fullMensaje;
    document.getElementById('detailEmisor').textContent  = d.emisor;

    const fecha = new Date(d.fecha);
    document.getElementById('detailFecha').textContent =
        fecha.toLocaleDateString('es-CO', { day:'2-digit', month:'long', year:'numeric' }) + ' · ' +
        fecha.toLocaleTimeString('es-CO', { hour:'2-digit', minute:'2-digit' });

    const actDiv = document.getElementById('detailActions');
    actDiv.innerHTML = '';
    if (d.enlace) {
        const a = document.createElement('a');
        a.href      = d.enlace;
        a.className = 'btn-link';
        a.innerHTML = '<span class="material-symbols-rounded">open_in_new</span> Ir al enlace';
        actDiv.appendChild(a);
    }
    if (d.leida === '0') {
        const btn = document.createElement('button');
        btn.className = 'btn-mark';
        btn.innerHTML = '<span class="material-symbols-rounded">check</span> Marcar como leída';
        btn.onclick = () => {
            closeModal('detailModal');
            marcarLeida(parseInt(d.id));
        };
        actDiv.appendChild(btn);
    }

    openModal('detailModal');
}

document.getElementById('detailClose').addEventListener('click', () => closeModal('detailModal'));

/* ── Modal Crear Notificación ─────────────────────────────────────────────── */

function mostrarAlertaNoti(msg) {
    const div = document.getElementById('modal-alert-crear-noti');
    if (!div) return;
    div.innerHTML = `<span class="material-symbols-rounded">error</span>${msg}`;
    div.style.display = 'flex';
    div.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

document.querySelectorAll('.dest-mode-card').forEach(card => {
    card.addEventListener('click', function () {
        // Marcar card activa
        document.querySelectorAll('.dest-mode-card').forEach(c => c.classList.remove('active'));
        this.classList.add('active');

        const modo = this.dataset.mode;
        const lista = document.getElementById('listaDestModal');
        const hidTodos = document.getElementById('hid_todos');
        const hidRol   = document.getElementById('hid_rol');

        // Limpiar campos ocultos y lista personalizada
        hidTodos.value = '';
        hidRol.value   = '';
        lista.style.display = 'none';
        lista.querySelectorAll('input[type="checkbox"]').forEach(i => i.checked = false);

        if (modo === 'todos') {
            hidTodos.value = '1';
        } else if (modo === 'personalizado') {
            lista.style.display = 'block';
        } else {
            // admins → admin | profesores → profesor | estudiantes → estudiante
            const rolMap = { admins: 'admin', profesores: 'profesor', estudiantes: 'estudiante' };
            hidRol.value = rolMap[modo];
        }
    });
});

// Inicializar: modo "todos" activo por defecto
document.getElementById('hid_todos').value = '1';

const formCrear = document.getElementById('formCrearNotificacion');
if (formCrear) {
    formCrear.addEventListener('submit', function(e) {
        const tipo    = document.getElementById('crear-tipo').value;
        const titulo  = document.getElementById('crear-titulo').value.trim();
        const mensaje = document.getElementById('crear-mensaje').value.trim();
        const hidTodos = document.getElementById('hid_todos').value;
        const hidRol   = document.getElementById('hid_rol').value;
        const alguno   = hidTodos === '1'
                    || hidRol !== ''
                    || document.querySelectorAll('#listaDestModal input:checked').length > 0;

        if (!tipo) {
            e.preventDefault();
            mostrarAlertaNoti('Selecciona un tipo de notificación.');
            return;
        }
        if (!titulo) {
            e.preventDefault();
            mostrarAlertaNoti('El título es obligatorio.');
            return;
        }
        if (!mensaje) {
            e.preventDefault();
            mostrarAlertaNoti('El mensaje es obligatorio.');
            return;
        }
        if (!alguno) {
            e.preventDefault();
            mostrarAlertaNoti('Selecciona al menos un destinatario o marca "Enviar a todos".');
            return;
        }

        const btn = document.getElementById('btnEnviarModal');
        btn.disabled = true;
        btn.innerHTML = '<span class="material-symbols-rounded">hourglass_empty</span> Enviando...';
    });
}

// Auto-abrir modal si hubo error en POST
<?php if ($modal_crear_open): ?>
openModal('createModal');
<?php if ($modal_crear_error): ?>
(function() {
    const div = document.getElementById('modal-alert-crear-noti');
    if (div) {
        div.innerHTML = '<span class="material-symbols-rounded">error</span><?= htmlspecialchars($modal_crear_error) ?>';
        div.style.display = 'flex';
    }
})();
<?php endif; ?>
<?php endif; ?>

// Auto-ocultar flash de éxito
const flashEnv = document.getElementById('flashEnviadas');
if (flashEnv) {
    setTimeout(() => {
        flashEnv.style.transition = 'opacity 0.5s ease';
        flashEnv.style.opacity = '0';
        setTimeout(() => flashEnv.remove(), 500);
    }, 4000);
}

/* ── Búsqueda en tiempo real ──────────────────────────────────────────────── */
document.getElementById('searchNotifications').addEventListener('input', function() {
    const term = this.value.toLowerCase().trim();
    document.querySelectorAll('.notification-card').forEach(card => {
        const match = !term
            || card.dataset.titulo.includes(term)
            || card.dataset.mensaje.includes(term);
        card.style.display = match ? 'flex' : 'none';
    });
});
</script>
</body>
</html>