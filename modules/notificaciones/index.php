<?php

require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth_check.php';
require_once '../../includes/notificaciones_helper.php'; // <-- NUEVO

// ─ Acciones AJAX 
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

            // devuelve el conteo sin leer para refrescar el badge 
            case 'contar_sin_leer':
                $response = [
                    'success'   => true,
                    'sin_leer'  => NotificacionesHelper::contarSinLeer($pdo, $_SESSION['user_id']),
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

// ─── Datos del usuario actual 
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

// ─ Filtros 
$filtro_tipo   = isset($_GET['tipo'])   ? $_GET['tipo']   : 'todas';
$filtro_estado = isset($_GET['estado']) ? $_GET['estado'] : 'todas';

// ── Construir consulta con filtros y ROL 
$sql = "
    SELECT
        n.id, n.tipo, n.titulo, n.mensaje, n.enlace,
        n.leida, n.prioridad, n.emisor,
        n.fecha_creacion, n.fecha_lectura
    FROM notificaciones n
    WHERE n.usuario_id = :usuario_id
";

$params = ['usuario_id' => $_SESSION['user_id']];

if ($filtro_tipo !== 'todas') {
    $sql .= " AND n.tipo = :tipo";
    $params['tipo'] = $filtro_tipo;
}

if ($filtro_estado === 'leidas') {
    $sql .= " AND n.leida = 1";
} elseif ($filtro_estado === 'no_leidas') {
    $sql .= " AND n.leida = 0";
}

$sql .= " ORDER BY n.leida ASC, n.fecha_creacion DESC";
//  las no leídas aparecen primero

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $notificaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error al obtener notificaciones: " . $e->getMessage());
    $notificaciones = [];
}

// ─── Estadísticas (1 sola consulta usando el helper) 
$stats = NotificacionesHelper::obtenerEstadisticas($pdo, $_SESSION['user_id']);
$total_notificaciones = $stats['total'];
$sin_leer             = $stats['sin_leer'];
$preinscripciones     = $stats['preinscripciones'];
$eventos              = $stats['eventos'];

//  Helpers de presentación 
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
            if (theme === 'light') {
                document.documentElement.setAttribute('data-theme', 'light');
            }
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

    <!--  Header  -->
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

    <!-- ── Stats  -->
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
                <div class="stat-label">Preinscripción</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon event">
                <span class="material-symbols-rounded">event</span>
            </div>
            <div class="stat-info">
                <div class="stat-value"><?php echo $eventos; ?></div>
                <div class="stat-label">Evento</div>
            </div>
        </div>
    </div>

    <!-- Buscador y filtros  -->
    <div class="filters-container">
        <div class="search-box">
            <span class="material-symbols-rounded">search</span>
            <input type="text" id="searchNotifications" placeholder="Buscar notificaciones...">
        </div>
        <div class="filter-buttons">
            <button class="filter-btn" id="btnFiltros">
                <span class="material-symbols-rounded">tune</span>
                Filtros
                <span class="material-symbols-rounded">arrow_drop_down</span>
            </button>
        </div>
    </div>

    <!-- ── Panel de filtros  -->
    <div class="filter-panel" id="filterPanel" style="display:none;">
        <div class="filter-section">
            <label>Tipo de notificación:</label>
            <div class="filter-options">
                <?php
                $tipos = ['todas' => 'Todas', 'sistema' => 'Sistema',
                          'preinscripcion' => 'Preinscripciones',
                          'evento' => 'Eventos', 'general' => 'General'];
                foreach ($tipos as $val => $label):
                ?>
                <a href="?tipo=<?php echo $val; ?>&estado=<?php echo $filtro_estado; ?>"
                   class="filter-option <?php echo $filtro_tipo === $val ? 'active' : ''; ?>">
                    <?php echo $label; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="filter-section">
            <label>Estado:</label>
            <div class="filter-options">
                <?php
                $estados = ['todas' => 'Todas', 'no_leidas' => 'No leídas', 'leidas' => 'Leídas'];
                foreach ($estados as $val => $label):
                ?>
                <a href="?tipo=<?php echo $filtro_tipo; ?>&estado=<?php echo $val; ?>"
                   class="filter-option <?php echo $filtro_estado === $val ? 'active' : ''; ?>">
                    <?php echo $label; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- ── Lista de notificaciones -->
    <div class="notifications-container" id="notificationsContainer">
        <?php if (count($notificaciones) > 0): ?>
            <?php foreach ($notificaciones as $notif):
                $icono_data  = icono_tipo($notif['tipo']);
                $leida_class = $notif['leida'] ? 'read' : 'unread';
            ?>
            <div class="notification-card <?php echo $leida_class; ?>"
                 data-id="<?php echo $notif['id']; ?>"
                 data-titulo="<?php echo htmlspecialchars(strtolower($notif['titulo'])); ?>"
                 data-mensaje="<?php echo htmlspecialchars(strtolower($notif['mensaje'])); ?>">

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
                    <?php if ($notif['enlace']): ?>
                    <a href="<?php echo htmlspecialchars($notif['enlace']); ?>"
                       class="action-btn" title="Ver detalles">
                        <span class="material-symbols-rounded">arrow_forward</span>
                    </a>
                    <?php endif; ?>
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
                             : ($filtro_estado === 'leidas'   ? 'leídas'
                             : ''); ?> en este momento
                </p>
            </div>
        <?php endif; ?>
    </div>
</main>

<!-- ── CSS adicional (dot sin leer + badge leída) -->
<style>
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
/* Animación reutilizada del badge del sidebar */
@keyframes pulse-badge {
    0%, 100% { transform: scale(1);   box-shadow: 0 0 0 0 rgba(249,115,22,.4); }
    50%       { transform: scale(1.1); box-shadow: 0 0 0 5px rgba(249,115,22,0);  }
}
</style>

<script>
// ── Toggle filtros 
document.getElementById('btnFiltros').addEventListener('click', function () {
    const p = document.getElementById('filterPanel');
    p.style.display = p.style.display === 'none' ? 'block' : 'none';
});

// ── Función AJAX genérica 
function enviarAccion(accion, datos = {}) {
    const fd = new FormData();
    fd.append('accion', accion);
    Object.entries(datos).forEach(([k, v]) => fd.append(k, v));
    return fetch('index.php', { method: 'POST', body: fd })
        .then(r => r.json());
}

// ── Marcar una notificación como leída
function marcarLeida(id) {
    enviarAccion('marcar_leida', { id })
        .then(data => { if (data.success) location.reload(); })
        .catch(() => alert('Error de conexión'));
}

// ── Marcar todas como leídas 
const btnTodas = document.getElementById('marcarTodasLeidas');
if (btnTodas) {
    btnTodas.addEventListener('click', function () {
        if (!confirm('¿Marcar todas las notificaciones como leídas?')) return;
        enviarAccion('marcar_todas_leidas')
            .then(data => { if (data.success) location.reload(); })
            .catch(() => alert('Error de conexión'));
    });
}

// ── Eliminar notificación 
function eliminarNotificacion(id) {
    if (!confirm('¿Eliminar esta notificación?')) return;
    enviarAccion('eliminar', { id })
        .then(data => { if (data.success) location.reload(); })
        .catch(() => alert('Error de conexión'));
}

// ── Búsqueda en tiempo real (filtra por data-titulo / data-mensaje) 
document.getElementById('searchNotifications').addEventListener('input', function () {
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