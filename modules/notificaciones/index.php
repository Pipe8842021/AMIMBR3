<?php
/**
 * Módulo de Notificaciones
 * Vista principal del sistema de notificaciones
 * Versión simplificada - Sin APIs externas
 */

// Incluir configuración de sesión y base de datos
require_once '../../config/session.php';
require_once '../../config/database.php';

// Verificar autenticación
require_once '../../includes/auth_check.php';

// Procesar acciones AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    header('Content-Type: application/json');
    
    $accion = $_POST['accion'];
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
                    
                    if ($stmt->rowCount() > 0) {
                        $response = ['success' => true, 'message' => 'Notificación marcada como leída'];
                    } else {
                        $response = ['success' => false, 'message' => 'Notificación no encontrada'];
                    }
                }
                break;
                
            case 'marcar_todas_leidas':
                $stmt = $pdo->prepare("
                    UPDATE notificaciones 
                    SET leida = 1, fecha_lectura = NOW() 
                    WHERE usuario_id = ? AND leida = 0
                ");
                $stmt->execute([$_SESSION['user_id']]);
                $affected = $stmt->rowCount();
                
                $response = [
                    'success' => true, 
                    'message' => "Se marcaron $affected notificaciones como leídas",
                    'count' => $affected
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
                    
                    if ($stmt->rowCount() > 0) {
                        $response = ['success' => true, 'message' => 'Notificación eliminada'];
                    } else {
                        $response = ['success' => false, 'message' => 'Notificación no encontrada'];
                    }
                }
                break;
        }
    } catch (PDOException $e) {
        error_log("Error en acción de notificaciones: " . $e->getMessage());
        $response = ['success' => false, 'message' => 'Error del sistema'];
    }
    
    echo json_encode($response);
    exit;
}

// Obtener datos del usuario actual
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

// Obtener filtro de tipo si existe
$filtro_tipo = isset($_GET['tipo']) ? $_GET['tipo'] : 'todas';
$filtro_estado = isset($_GET['estado']) ? $_GET['estado'] : 'todas';

// Construir consulta SQL base
$sql = "
    SELECT 
        n.id,
        n.tipo,
        n.titulo,
        n.mensaje,
        n.enlace,
        n.leida,
        n.prioridad,
        n.emisor,
        n.fecha_creacion,
        n.fecha_lectura
    FROM notificaciones n
    WHERE n.usuario_id = :usuario_id
";

// Aplicar filtros
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

$sql .= " ORDER BY n.fecha_creacion DESC";

// Ejecutar consulta
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $notificaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error al obtener notificaciones: " . $e->getMessage());
    $notificaciones = [];
}

// Obtener estadísticas
try {
    // Total de notificaciones
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM notificaciones WHERE usuario_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $total_notificaciones = $stmt->fetch()['total'] ?? 0;
    
    // Sin leer
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM notificaciones WHERE usuario_id = ? AND leida = 0");
    $stmt->execute([$_SESSION['user_id']]);
    $sin_leer = $stmt->fetch()['total'] ?? 0;
    
    // Preinscripciones
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM notificaciones WHERE usuario_id = ? AND tipo = 'preinscripcion' AND leida = 0");
    $stmt->execute([$_SESSION['user_id']]);
    $preinscripciones = $stmt->fetch()['total'] ?? 0;
    
    // Eventos
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM notificaciones WHERE usuario_id = ? AND tipo = 'evento' AND leida = 0");
    $stmt->execute([$_SESSION['user_id']]);
    $eventos = $stmt->fetch()['total'] ?? 0;
} catch (PDOException $e) {
    error_log("Error al obtener estadísticas: " . $e->getMessage());
    $total_notificaciones = 0;
    $sin_leer = 0;
    $preinscripciones = 0;
    $eventos = 0;
}

// Función para formatear tiempo transcurrido
function tiempo_transcurrido($fecha) {
    $ahora = new DateTime();
    $tiempo = new DateTime($fecha);
    $diferencia = $ahora->diff($tiempo);
    
    if ($diferencia->days > 0) {
        return "Hace " . $diferencia->days . " día" . ($diferencia->days > 1 ? "s" : "");
    } elseif ($diferencia->h > 0) {
        return "Hace " . $diferencia->h . " hora" . ($diferencia->h > 1 ? "s" : "");
    } elseif ($diferencia->i > 0) {
        return "Hace " . $diferencia->i . " minuto" . ($diferencia->i > 1 ? "s" : "");
    } else {
        return "Hace unos segundos";
    }
}

// Función para obtener icono según tipo
function icono_tipo($tipo) {
    $iconos = [
        'sistema' => ['icono' => 'notifications', 'color' => 'orange'],
        'preinscripcion' => ['icono' => 'person_add', 'color' => 'yellow'],
        'evento' => ['icono' => 'event', 'color' => 'orange'],
        'general' => ['icono' => 'mail', 'color' => 'pink']
    ];
    return $iconos[$tipo] ?? ['icono' => 'notifications', 'color' => 'blue'];
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
</head>
<body>
    <!-- Include Header/Sidebar -->
    <?php 
    if (file_exists('../../includes/header.php')) {
        require_once '../../includes/header.php'; 
    }
    ?>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Header -->
        <div class="notifications-header">
            <div class="header-content">
                <div class="header-left">
                    <button class="back-button" onclick="window.history.back()">
                        <span class="material-symbols-rounded">arrow_back</span>
                    </button>
                    <div class="header-title">
                        <h1>Notificaciones</h1>
                        <p>Tienes <?php echo $sin_leer; ?> notificaciones sin leer</p>
                    </div>
                </div>
                <div class="header-actions">
                    <button class="btn-secondary" id="marcarTodasLeidas">
                        <span class="material-symbols-rounded">done_all</span>
                        Marcar todas como leídas
                    </button>
                    <?php if ($user['rol'] === 'admin'): ?>
                    <button class="btn-primary" onclick="location.href='crear.php'">
                        <span class="material-symbols-rounded">add</span>
                        Nueva notificación
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Stats Cards -->
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

        <!-- Filters and Search -->
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

        <!-- Filter Panel (hidden by default) -->
        <div class="filter-panel" id="filterPanel" style="display: none;">
            <div class="filter-section">
                <label>Tipo de notificación:</label>
                <div class="filter-options">
                    <a href="?tipo=todas&estado=<?php echo $filtro_estado; ?>" class="filter-option <?php echo $filtro_tipo === 'todas' ? 'active' : ''; ?>">
                        Todas
                    </a>
                    <a href="?tipo=sistema&estado=<?php echo $filtro_estado; ?>" class="filter-option <?php echo $filtro_tipo === 'sistema' ? 'active' : ''; ?>">
                        Sistema
                    </a>
                    <a href="?tipo=preinscripcion&estado=<?php echo $filtro_estado; ?>" class="filter-option <?php echo $filtro_tipo === 'preinscripcion' ? 'active' : ''; ?>">
                        Preinscripciones
                    </a>
                    <a href="?tipo=evento&estado=<?php echo $filtro_estado; ?>" class="filter-option <?php echo $filtro_tipo === 'evento' ? 'active' : ''; ?>">
                        Eventos
                    </a>
                    <a href="?tipo=general&estado=<?php echo $filtro_estado; ?>" class="filter-option <?php echo $filtro_tipo === 'general' ? 'active' : ''; ?>">
                        General
                    </a>
                </div>
            </div>
            <div class="filter-section">
                <label>Estado:</label>
                <div class="filter-options">
                    <a href="?tipo=<?php echo $filtro_tipo; ?>&estado=todas" class="filter-option <?php echo $filtro_estado === 'todas' ? 'active' : ''; ?>">
                        Todas
                    </a>
                    <a href="?tipo=<?php echo $filtro_tipo; ?>&estado=no_leidas" class="filter-option <?php echo $filtro_estado === 'no_leidas' ? 'active' : ''; ?>">
                        No leídas
                    </a>
                    <a href="?tipo=<?php echo $filtro_tipo; ?>&estado=leidas" class="filter-option <?php echo $filtro_estado === 'leidas' ? 'active' : ''; ?>">
                        Leídas
                    </a>
                </div>
            </div>
        </div>

        <!-- Notifications List -->
        <div class="notifications-container">
            <?php if (count($notificaciones) > 0): ?>
                <?php foreach($notificaciones as $notif): ?>
                    <?php 
                    $icono_data = icono_tipo($notif['tipo']);
                    $leida_class = $notif['leida'] ? 'read' : 'unread';
                    ?>
                    <div class="notification-card <?php echo $leida_class; ?>" data-id="<?php echo $notif['id']; ?>">
                        <div class="notification-indicator"></div>
                        <div class="notification-icon <?php echo $icono_data['color']; ?>">
                            <span class="material-symbols-rounded"><?php echo $icono_data['icono']; ?></span>
                        </div>
                        <div class="notification-content">
                            <div class="notification-header">
                                <h3 class="notification-title"><?php echo htmlspecialchars($notif['titulo']); ?></h3>
                                <div class="notification-meta">
                                    <span class="notification-time"><?php echo tiempo_transcurrido($notif['fecha_creacion']); ?></span>
                                </div>
                            </div>
                            <p class="notification-message"><?php echo htmlspecialchars($notif['mensaje']); ?></p>
                            <div class="notification-footer">
                                <span class="notification-sender">De: <?php echo htmlspecialchars($notif['emisor']); ?></span>
                                <?php if ($notif['prioridad'] === 'alta'): ?>
                                    <span class="badge-priority high">Alta prioridad</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="notification-actions">
                            <?php if (!$notif['leida']): ?>
                                <button class="action-btn" onclick="marcarLeida(<?php echo $notif['id']; ?>)" title="Marcar como leída">
                                    <span class="material-symbols-rounded">check</span>
                                </button>
                            <?php endif; ?>
                            <?php if ($notif['enlace']): ?>
                                <a href="<?php echo htmlspecialchars($notif['enlace']); ?>" class="action-btn" title="Ver detalles">
                                    <span class="material-symbols-rounded">arrow_forward</span>
                                </a>
                            <?php endif; ?>
                            <button class="action-btn delete" onclick="eliminarNotificacion(<?php echo $notif['id']; ?>)" title="Eliminar">
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
                    <p>No tienes notificaciones en este momento</p>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <script>
        // Toggle filter panel
        document.getElementById('btnFiltros').addEventListener('click', function() {
            const panel = document.getElementById('filterPanel');
            panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
        });

        // Función para enviar peticiones AJAX
        function enviarAccion(accion, datos = {}) {
            const formData = new FormData();
            formData.append('accion', accion);
            
            for (let key in datos) {
                formData.append(key, datos[key]);
            }
            
            return fetch('index.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json());
        }

        // Marcar notificación como leída
        function marcarLeida(id) {
            enviarAccion('marcar_leida', { id: id })
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert('Error al marcar como leída');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error de conexión');
                });
        }

        // Marcar todas como leídas
        document.getElementById('marcarTodasLeidas').addEventListener('click', function() {
            if (confirm('¿Marcar todas las notificaciones como leídas?')) {
                enviarAccion('marcar_todas_leidas')
                    .then(data => {
                        if (data.success) {
                            location.reload();
                        } else {
                            alert('Error al marcar todas como leídas');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('Error de conexión');
                    });
            }
        });

        // Eliminar notificación
        function eliminarNotificacion(id) {
            if (confirm('¿Estás seguro de eliminar esta notificación?')) {
                enviarAccion('eliminar', { id: id })
                    .then(data => {
                        if (data.success) {
                            location.reload();
                        } else {
                            alert('Error al eliminar la notificación');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('Error de conexión');
                    });
            }
        }

        // Búsqueda en tiempo real
        document.getElementById('searchNotifications').addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const notifications = document.querySelectorAll('.notification-card');
            
            notifications.forEach(notif => {
                const title = notif.querySelector('.notification-title').textContent.toLowerCase();
                const message = notif.querySelector('.notification-message').textContent.toLowerCase();
                
                if (title.includes(searchTerm) || message.includes(searchTerm)) {
                    notif.style.display = 'flex';
                } else {
                    notif.style.display = 'none';
                }
            });
        });
    </script>
</body>
</html>