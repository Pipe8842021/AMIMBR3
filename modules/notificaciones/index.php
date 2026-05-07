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
                <button class="btn-primary" onclick="location.href='crear.php'">
                    <span class="material-symbols-rounded">add</span>
                    Nueva notificación
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

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

                    <!-- Botón ver detalles -->
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


<!-- ─── Estilos adicionales ──────────────────────────────────────────────── -->
<style>
/* ── Chip bar ────────────────────────────────────────────────────────────── */
.filters-toolbar {
    display: flex;
    flex-direction: column;
    gap: 14px;
    margin: 0 0 20px;
}
.chip-bar {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: 14px;
    padding: 12px 16px;
}
.chip-group {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}
.chip-group-label {
    font-size: 0.72rem;
    font-weight: 600;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: .05em;
    white-space: nowrap;
    margin-right: 2px;
}
.chip-divider {
    width: 1px;
    height: 28px;
    background: var(--border-color);
    margin: 0 4px;
    flex-shrink: 0;
}
.chip {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 12px;
    border-radius: 20px;
    border: 1px solid var(--border-color);
    background: transparent;
    color: var(--text-secondary);
    font-size: 0.78rem;
    font-weight: 500;
    text-decoration: none;
    transition: all 0.22s ease;
    white-space: nowrap;
    font-family: "Poppins", sans-serif;
}
.chip .material-symbols-rounded { font-size: 15px; }
.chip:hover {
    border-color: var(--primary-blue);
    color: var(--primary-blue);
    background: var(--subtle-blue, rgba(59,130,246,.08));
    transform: translateY(-1px);
}
.chip.active {
    background: var(--primary-blue);
    border-color: var(--primary-blue);
    color: #fff;
    box-shadow: 0 3px 10px rgba(59,130,246,.35);
}

/* ── Dot unread + badge leída ────────────────────────────────────────────── */
.dot-unread {
    display: inline-block;
    width: 8px; height: 8px;
    border-radius: 50%;
    background: var(--primary-orange, #f97316);
    margin-left: 6px;
    vertical-align: middle;
    animation: pulse-badge 2s infinite;
}
.badge-read {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    padding: 3px 8px;
    border-radius: 6px;
    font-size: 0.75rem;
    background: rgba(34,197,94,.15);
    color: #22c55e;
}
@keyframes pulse-badge {
    0%, 100% { transform: scale(1);   box-shadow: 0 0 0 0 rgba(249,115,22,.4); }
    50%       { transform: scale(1.1); box-shadow: 0 0 0 5px rgba(249,115,22,0); }
}

/* ── Modal overlay ───────────────────────────────────────────────────────── */
.modal-overlay {
    position: fixed; inset: 0;
    background: rgba(0,0,0,.55);
    backdrop-filter: blur(4px);
    z-index: 2000;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    opacity: 0;
    pointer-events: none;
    transition: opacity .25s ease;
}
.modal-overlay.open {
    opacity: 1;
    pointer-events: auto;
}
.modal-box {
    background: var(--dark-bg);
    border: 1px solid var(--border-color);
    border-radius: 20px;
    padding: 32px 28px 28px;
    width: 100%;
    max-width: 420px;
    box-shadow: 0 20px 60px rgba(0,0,0,.4);
    transform: scale(.94) translateY(12px);
    transition: transform .28s cubic-bezier(0.34,1.56,0.64,1);
    position: relative;
}
.modal-overlay.open .modal-box {
    transform: scale(1) translateY(0);
}

/* ── Modal confirmación ──────────────────────────────────────────────────── */
.modal-icon {
    width: 56px; height: 56px;
    border-radius: 16px;
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 18px;
    font-size: 1.6rem;
}
.confirm-icon {
    background: rgba(59,130,246,.15);
    color: var(--primary-blue);
}
.modal-title {
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--text-primary);
    text-align: center;
    margin-bottom: 8px;
}
.modal-desc {
    font-size: 0.85rem;
    color: var(--text-secondary);
    text-align: center;
    line-height: 1.6;
    margin-bottom: 24px;
}
.modal-actions {
    display: flex;
    gap: 10px;
    justify-content: center;
}
.modal-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 10px 22px;
    border-radius: 10px;
    border: 1px solid var(--border-color);
    font-family: "Poppins", sans-serif;
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
}
.modal-btn.cancel {
    background: transparent;
    color: var(--text-secondary);
}
.modal-btn.cancel:hover {
    background: var(--hover-bg);
    color: var(--text-primary);
}
.modal-btn.confirm {
    background: var(--primary-blue);
    color: #fff;
    border-color: var(--primary-blue);
    box-shadow: 0 4px 14px rgba(59,130,246,.35);
}
.modal-btn.confirm:hover {
    filter: brightness(1.1);
    transform: translateY(-1px);
}
.modal-btn .material-symbols-rounded { font-size: 16px; }

/* ── Modal detalle ───────────────────────────────────────────────────────── */
.detail-modal-box {
    max-width: 500px;
    padding: 28px 28px 24px;
}
.modal-close-btn {
    position: absolute;
    top: 16px; right: 16px;
    width: 32px; height: 32px;
    border-radius: 8px;
    border: 1px solid var(--border-color);
    background: transparent;
    color: var(--text-secondary);
    display: flex; align-items: center; justify-content: center;
    cursor: pointer;
    transition: all 0.2s ease;
}
.modal-close-btn:hover {
    background: var(--hover-bg);
    color: var(--text-primary);
}
.modal-close-btn .material-symbols-rounded { font-size: 18px; }

.detail-header {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-bottom: 18px;
}
.detail-icon-wrap {
    width: 52px; height: 52px;
    border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.4rem;
    flex-shrink: 0;
    background: rgba(59,130,246,.15);
    color: var(--primary-blue);
}
.detail-meta {
    display: flex;
    flex-direction: column;
    gap: 5px;
}
.detail-tipo-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .04em;
    background: rgba(59,130,246,.15);
    color: var(--primary-blue);
}
.detail-prioridad {
    font-size: 0.75rem;
    color: var(--text-secondary);
    font-weight: 500;
}
.detail-prioridad.alta { color: var(--primary-orange, #f97316); }

.detail-title {
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--text-primary);
    margin-bottom: 10px;
    line-height: 1.4;
}
.detail-message {
    font-size: 0.875rem;
    color: var(--text-secondary);
    line-height: 1.7;
    margin-bottom: 20px;
}
.detail-info-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
    margin-bottom: 20px;
}
.detail-info-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: 10px;
}
.detail-info-item > .material-symbols-rounded {
    font-size: 1.1rem;
    color: var(--primary-blue);
    flex-shrink: 0;
}
.detail-info-label {
    display: block;
    font-size: 0.68rem;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: .04em;
    font-weight: 600;
}
.detail-info-value {
    display: block;
    font-size: 0.8rem;
    color: var(--text-primary);
    font-weight: 500;
    margin-top: 1px;
}
.detail-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}
.detail-actions a,
.detail-actions button {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 9px 18px;
    border-radius: 10px;
    font-family: "Poppins", sans-serif;
    font-size: 0.82rem;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.2s ease;
    border: 1px solid var(--border-color);
}
.detail-actions .btn-link {
    background: var(--primary-blue);
    color: #fff;
    border-color: var(--primary-blue);
    box-shadow: 0 3px 10px rgba(59,130,246,.3);
}
.detail-actions .btn-link:hover { filter: brightness(1.1); transform: translateY(-1px); }
.detail-actions .btn-mark {
    background: transparent;
    color: var(--text-primary);
}
.detail-actions .btn-mark:hover { background: var(--hover-bg); }
.detail-actions .material-symbols-rounded { font-size: 16px; }

/* ── Toast ───────────────────────────────────────────────────────────────── */
.toast {
    position: fixed;
    bottom: 28px;
    left: 50%;
    transform: translateX(-50%) translateY(80px);
    background: var(--dark-bg);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 12px 20px;
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--text-primary);
    box-shadow: 0 8px 28px rgba(0,0,0,.3);
    z-index: 3000;
    transition: transform .35s cubic-bezier(0.34,1.56,0.64,1), opacity .3s ease;
    opacity: 0;
    white-space: nowrap;
}
.toast.show {
    transform: translateX(-50%) translateY(0);
    opacity: 1;
}
.toast-icon { font-size: 18px; }
.toast.success .toast-icon { color: #22c55e; }
.toast.error   .toast-icon { color: #ef4444; }
</style>


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
    // Reutilizamos el modal de confirmación con texto distinto
    const modal = document.getElementById('confirmModal');
    modal.querySelector('.confirm-icon').innerHTML = '<span class="material-symbols-rounded">delete</span>';
    modal.querySelector('.confirm-icon').style.background = 'rgba(239,68,68,.15)';
    modal.querySelector('.confirm-icon').style.color = '#ef4444';
    modal.querySelector('.modal-title').textContent = '¿Eliminar notificación?';
    modal.querySelector('.modal-desc').textContent = 'Esta notificación se eliminará permanentemente.';
    const btnOk = document.getElementById('confirmOk');
    btnOk.style.background = '#ef4444';
    btnOk.style.borderColor = '#ef4444';
    btnOk.innerHTML = '<span class="material-symbols-rounded">delete</span> Eliminar';

    // Reemplazar listener
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

    // Icono
    const iconWrap = document.getElementById('detailIconWrap');
    iconWrap.style.background = tipoData.bg;
    iconWrap.style.color      = tipoData.color;
    document.getElementById('detailIcon').textContent = tipoData.icono;

    // Tipo badge
    const tipoBadge = document.getElementById('detailTipo');
    tipoBadge.textContent   = d.tipo.charAt(0).toUpperCase() + d.tipo.slice(1);
    tipoBadge.style.background = tipoData.bg;
    tipoBadge.style.color      = tipoData.color;

    // Prioridad
    const prioEl = document.getElementById('detailPrioridad');
    if (d.prioridad === 'alta') {
        prioEl.textContent  = '⚠ Alta prioridad';
        prioEl.className    = 'detail-prioridad alta';
    } else {
        prioEl.textContent  = 'Prioridad ' + d.prioridad;
        prioEl.className    = 'detail-prioridad';
    }

    // Contenido
    document.getElementById('detailTitulo').textContent  = d.fullTitulo;
    document.getElementById('detailMensaje').textContent = d.fullMensaje;
    document.getElementById('detailEmisor').textContent  = d.emisor;

    // Fecha legible
    const fecha = new Date(d.fecha);
    document.getElementById('detailFecha').textContent =
        fecha.toLocaleDateString('es-CO', { day:'2-digit', month:'long', year:'numeric' }) + ' · ' +
        fecha.toLocaleTimeString('es-CO', { hour:'2-digit', minute:'2-digit' });

    // Acciones
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

// Botón cerrar del modal de detalle
document.getElementById('detailClose').addEventListener('click', () => closeModal('detailModal'));

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