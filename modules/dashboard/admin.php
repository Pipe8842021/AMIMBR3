<?php
/**
 * Dashboard Administrador
 * Vista completa del panel de administración
 */

// Incluir configuración de sesión y base de datos
require_once '../../config/session.php';
require_once '../../config/database.php';

// Verificar autenticación
require_once '../../includes/auth_check.php';

// Verificar que sea administrador
require_role('admin');

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

// Obtener mensaje flash si existe
$flash = null;
if (function_exists('get_flash_message')) {
    $flash = get_flash_message();
}

// Obtener estadísticas reales
try {
    // 1. Estudiantes activos
    $stmt = $pdo->query("
        SELECT COUNT(*) as total 
        FROM usuarios 
        WHERE rol = 'estudiante' AND estado = 'activo'
    ");
    $estudiantes_activos = $stmt->fetch()['total'] ?? 0;
    
    // Estudiantes del mes pasado
    $stmt = $pdo->query("
        SELECT COUNT(*) as total 
        FROM usuarios 
        WHERE rol = 'estudiante' 
        AND estado = 'activo'
        AND fecha_registro < DATE_SUB(NOW(), INTERVAL 1 MONTH)
    ");
    $estudiantes_mes_pasado = $stmt->fetch()['total'] ?? 0;
    
    // Calcular porcentaje de cambio
    $cambio_estudiantes = $estudiantes_mes_pasado > 0 
        ? round((($estudiantes_activos - $estudiantes_mes_pasado) / $estudiantes_mes_pasado) * 100) 
        : 0;
    
    // 2. Cursos activos
    $stmt = $pdo->query("
        SELECT COUNT(*) as total 
        FROM cursos 
        WHERE estado = 'activo'
    ");
    $cursos_activos = $stmt->fetch()['total'] ?? 0;
    
    // Grupos activos
    $stmt = $pdo->query("
        SELECT COUNT(*) as total 
        FROM grupos 
        WHERE estado = 'activo'
    ");
    $grupos_activos = $stmt->fetch()['total'] ?? 0;
    
    // 4. Profesores activos
    $stmt = $pdo->query("
        SELECT COUNT(*) as total 
        FROM usuarios 
        WHERE rol = 'profesor' AND estado = 'activo'
    ");
    $profesores_activos = $stmt->fetch()['total'] ?? 0;
    
    // 5. Actividad reciente (últimas 4 acciones)
    $stmt = $pdo->query("
        SELECT 
            la.fecha_acceso,
            u.nombre as usuario_nombre,
            u.rol
        FROM logs_acceso la
        INNER JOIN usuarios u ON la.usuario_id = u.id
        ORDER BY la.fecha_acceso DESC
        LIMIT 4
    ");
    $actividades = $stmt->fetchAll();
    
    // 6. Prematrículas pendientes
    $stmt = $pdo->query("
        SELECT COUNT(*) as total 
        FROM preinscripciones 
        WHERE estado = 'pendiente'
    ");
    $prematriculas_pendientes = $stmt->fetch()['total'] ?? 0;
    
} catch (PDOException $e) {
    error_log("Error obteniendo estadísticas: " . $e->getMessage());
    // Valores por defecto en caso de error
    $estudiantes_activos = 0;
    $cambio_estudiantes = 0;
    $cursos_activos = 0;
    $grupos_activos = 0;
    $profesores_activos = 0;
    $actividades = [];
    $prematriculas_pendientes = 0;
}

// Función para tiempo transcurrido
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

// Función para icono según rol
function icono_rol($rol) {
    $iconos = [
        'admin' => ['icono' => 'admin_panel_settings', 'clase' => 'warning'],
        'profesor' => ['icono' => 'school', 'clase' => 'info'],
        'estudiante' => ['icono' => 'person', 'clase' => 'success']
    ];
    return $iconos[$rol] ?? ['icono' => 'person', 'clase' => 'info'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Administrador - Amimbré</title>
    <link rel="shortcut icon" href="../../assets/img/3.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0" />
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="../../assets/css/colores.css">
    <link rel="stylesheet" href="../../assets/css/style-dashboardAdmin.css">
</head>
<body>
    <!-- Include Header/Sidebar -->
    <?php 
    if (file_exists('../../includes/header.php')) {
        require_once '../../includes/header.php'; 
    } else {
        echo '<p style="padding: 20px; background: #fff3cd; color: #856404;">Advertencia: El archivo header.php no existe</p>';
    }
    ?>

    <!-- Main Dashboard Content -->
    <main class="main-content">
        <!-- Header -->
        <div class="dashboard-header">
            <div class="dashboard-title">
                <h1>Dashboard</h1>
                <p>Bienvenido, <?php echo htmlspecialchars($user['nombre']); ?> (Administrador)</p>
            </div>
            <div class="dashboard-date">
                <span class="material-symbols-rounded" style="vertical-align: middle; margin-right: 5px;">calendar_today</span>
                <?php
                date_default_timezone_set('America/Bogota');
                
                // Días y meses en español
                $dias = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
                $meses = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
                
                $dia_semana = $dias[date('w')];
                $dia = date('d');
                $mes = $meses[date('n')];
                $anio = date('Y');
                
                echo "$dia_semana, $dia de $mes de $anio";
                ?>
            </div>
        </div>

        <?php if ($flash): ?>
        <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>">
            <i class="fas fa-info-circle"></i>
            <?php echo htmlspecialchars($flash['message']); ?>
        </div>
        <?php endif; ?>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-title">Estudiantes Activos</span>
                    <div class="stat-icon students">
                        <span class="material-symbols-rounded">school</span>
                    </div>
                </div>
                <div class="stat-value"><?php echo number_format($estudiantes_activos); ?></div>
                <div class="stat-change <?php echo $cambio_estudiantes >= 0 ? 'positive' : 'negative'; ?>">
                    <span class="material-symbols-rounded">
                        <?php echo $cambio_estudiantes >= 0 ? 'arrow_upward' : 'arrow_downward'; ?>
                    </span>
                    <?php echo abs($cambio_estudiantes); ?>% desde el mes pasado
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-title">Cursos Activos</span>
                    <div class="stat-icon courses">
                        <span class="material-symbols-rounded">menu_book</span>
                    </div>
                </div>
                <div class="stat-value"><?php echo number_format($cursos_activos); ?></div>
                <div class="stat-change">
                    <span>En <?php echo $grupos_activos; ?> grupos</span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-title">Prematrículas</span>
                    <div class="stat-icon documents">
                        <span class="material-symbols-rounded">description</span>
                    </div>
                </div>
                <div class="stat-value"><?php echo number_format($prematriculas_pendientes); ?></div>
                <div class="stat-change">
                    <span>Pendientes de revisión</span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-title">Profesores</span>
                    <div class="stat-icon teachers">
                        <span class="material-symbols-rounded">groups</span>
                    </div>
                </div>
                <div class="stat-value"><?php echo number_format($profesores_activos); ?></div>
                <div class="stat-change">
                    <span>Personal docente activo</span>
                </div>
            </div>
        </div>

        <!-- Content Grid -->
        <div class="content-grid">
            <!-- Actividad Reciente -->
            <div class="activity-container">
                <div class="section-header">
                    <h3 class="section-title">Actividad Reciente</h3>
                    <p class="section-subtitle">Últimos accesos al sistema</p>
                </div>
                <div class="activity-list">
                    <?php if (count($actividades) > 0): ?>
                        <?php foreach($actividades as $actividad): ?>
                            <?php 
                            $icono = icono_rol($actividad['rol']);
                            $rol_texto = [
                                'admin' => 'Administrador',
                                'profesor' => 'Profesor',
                                'estudiante' => 'Estudiante'
                            ][$actividad['rol']] ?? 'Usuario';
                            ?>
                            <div class="activity-item">
                                <div class="activity-icon <?php echo $icono['clase']; ?>">
                                    <span class="material-symbols-rounded"><?php echo $icono['icono']; ?></span>
                                </div>
                                <div class="activity-content">
                                    <div class="activity-title">
                                        <?php echo htmlspecialchars($actividad['usuario_nombre']); ?> accedió al sistema
                                    </div>
                                    <div class="activity-time">
                                        <?php echo tiempo_transcurrido($actividad['fecha_acceso']); ?> • <?php echo $rol_texto; ?>
                                    </div>
                                </div>
                                <span class="activity-badge new">Acceso</span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="activity-item">
                            <div class="activity-icon info">
                                <span class="material-symbols-rounded">info</span>
                            </div>
                            <div class="activity-content">
                                <div class="activity-title">No hay actividad reciente</div>
                                <div class="activity-time">El sistema está iniciando</div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="quick-actions-container">
                <div class="section-header">
                    <h3 class="section-title">Acciones Rápidas</h3>
                    <p class="section-subtitle">Tareas frecuentes</p>
                </div>
                <div class="quick-actions-grid">
                    <a href="../prematriculas/index.php" class="quick-action">
                        <div class="quick-action-icon enrollment">
                            <span class="material-symbols-rounded">person_add</span>
                        </div>
                        <div class="quick-action-content">
                            <div class="quick-action-title">Prematrículas</div>
                            <div class="quick-action-desc"><?php echo $prematriculas_pendientes; ?> pendientes</div>
                        </div>
                        <span class="material-symbols-rounded" style="color: var(--text-secondary);">arrow_forward</span>
                    </a>

                    <a href="../usuarios/crear.php" class="quick-action">
                        <div class="quick-action-icon documents">
                            <span class="material-symbols-rounded">group_add</span>
                        </div>
                        <div class="quick-action-content">
                            <div class="quick-action-title">Nuevo Usuario</div>
                            <div class="quick-action-desc">Registrar usuario</div>
                        </div>
                        <span class="material-symbols-rounded" style="color: var(--text-secondary);">arrow_forward</span>
                    </a>

                    <a href="../cursos/index.php" class="quick-action">
                        <div class="quick-action-icon grades">
                            <span class="material-symbols-rounded">menu_book</span>
                        </div>
                        <div class="quick-action-content">
                            <div class="quick-action-title">Gestionar Cursos</div>
                            <div class="quick-action-desc"><?php echo $cursos_activos; ?> activos</div>
                        </div>
                        <span class="material-symbols-rounded" style="color: var(--text-secondary);">arrow_forward</span>
                    </a>

                    <a href="../reportes/index.php" class="quick-action">
                        <div class="quick-action-icon reports">
                            <span class="material-symbols-rounded">assessment</span>
                        </div>
                        <div class="quick-action-content">
                            <div class="quick-action-title">Ver Reportes</div>
                            <div class="quick-action-desc">Análisis y estadísticas</div>
                        </div>
                        <span class="material-symbols-rounded" style="color: var(--text-secondary);">arrow_forward</span>
                    </a>
                </div>
            </div>
        </div>
    </main>
</body>
</html>