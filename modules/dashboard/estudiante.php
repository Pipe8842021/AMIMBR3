<?php
/**
 * Dashboard Estudiante
 * Vista para estudiantes
 */

// Incluir configuración de sesión y base de datos
require_once '../../config/session.php';
require_once '../../config/database.php';

// Verificar autenticación
require_once '../../includes/auth_check.php';

// Verificar que sea estudiante
require_role('estudiante');

// Obtener datos del usuario actual
try {
    $stmt = $pdo->prepare("
        SELECT id, nombre, email, rol, estado, foto_perfil 
        FROM usuarios 
        WHERE id = ?
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        session_destroy();
        header("Location: ../../auth/login.php");
        exit;
    }
} catch (PDOException $e) {
    error_log("Error al obtener datos de usuario: " . $e->getMessage());
    die("Error del sistema. Por favor, intenta más tarde.");
}

// Obtener mensaje flash si existe
$flash = get_flash_message();

// Obtener estadísticas del estudiante
try {
    // Cursos matriculados
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total 
        FROM matriculas 
        WHERE estudiante_id = ? AND estado = 'activa'
    ");
    $stmt->execute([$user['id']]);
    $cursos_activos = $stmt->fetch()['total'];
    
    // Asistencia promedio (simulado)
    $asistencia_promedio = 90;
    
    // Próximas clases (simulado)
    $proximas_clases = 3;
    
    // Estado de pagos (simulado)
    $pagos_pendientes = 0;
    
} catch (PDOException $e) {
    error_log("Error obteniendo estadísticas: " . $e->getMessage());
    $cursos_activos = 0;
    $asistencia_promedio = 0;
    $proximas_clases = 0;
    $pagos_pendientes = 0;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Estudiante - Amimbré</title>
    <link rel="shortcut icon" href="../../assets/img/3.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0" />
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="../../assets/css/colores.css">
    <link rel="stylesheet" href="../../assets/css/style-dashboardAdmin.css">
</head>
<body>
    <!-- Include Header/Sidebar -->
    <?php require_once '../../includes/header.php'; ?>

    <!-- Main Dashboard Content -->
    <main class="main-content">
        <!-- Header -->
        <div class="dashboard-header">
            <div class="dashboard-title">
                <h1>Mi Dashboard</h1>
                <p>Bienvenido, <?php echo htmlspecialchars($user['nombre']); ?></p>
            </div>
            <div class="dashboard-date">
                <span class="material-symbols-rounded" style="vertical-align: middle; margin-right: 5px;">calendar_today</span>
                <?php
                date_default_timezone_set('America/Bogota');
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
        <div class="alert alert-<?php echo $flash['type']; ?>">
            <i class="fas fa-info-circle"></i>
            <?php echo htmlspecialchars($flash['message']); ?>
        </div>
        <?php endif; ?>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-title">Mis Cursos</span>
                    <div class="stat-icon courses">
                        <span class="material-symbols-rounded">menu_book</span>
                    </div>
                </div>
                <div class="stat-value"><?php echo number_format($cursos_activos); ?></div>
                <div class="stat-change">
                    <span>Cursos activos</span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-title">Asistencia</span>
                    <div class="stat-icon students">
                        <span class="material-symbols-rounded">fact_check</span>
                    </div>
                </div>
                <div class="stat-value"><?php echo $asistencia_promedio; ?>%</div>
                <div class="stat-change positive">
                    <span class="material-symbols-rounded">arrow_upward</span>
                    Buen rendimiento
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-title">Próximas Clases</span>
                    <div class="stat-icon teachers">
                        <span class="material-symbols-rounded">event</span>
                    </div>
                </div>
                <div class="stat-value"><?php echo $proximas_clases; ?></div>
                <div class="stat-change">
                    <span>Esta semana</span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-title">Pagos</span>
                    <div class="stat-icon documents">
                        <span class="material-symbols-rounded">payments</span>
                    </div>
                </div>
                <div class="stat-value"><?php echo $pagos_pendientes; ?></div>
                <div class="stat-change <?php echo $pagos_pendientes > 0 ? 'negative' : 'positive'; ?>">
                    <?php if ($pagos_pendientes > 0): ?>
                        <span class="material-symbols-rounded">warning</span>
                        Pendientes
                    <?php else: ?>
                        <span class="material-symbols-rounded">check_circle</span>
                        Al día
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Content Grid -->
        <div class="content-grid">
            <!-- Acciones Rápidas -->
            <div class="quick-actions-container">
                <div class="section-header">
                    <h3 class="section-title">Acciones Rápidas</h3>
                    <p class="section-subtitle">Acceso directo</p>
                </div>
                <div class="quick-actions-grid">
                    <a href="#" class="quick-action">
                        <div class="quick-action-icon courses">
                            <span class="material-symbols-rounded">menu_book</span>
                        </div>
                        <div class="quick-action-content">
                            <div class="quick-action-title">Mis Cursos</div>
                            <div class="quick-action-desc"><?php echo $cursos_activos; ?> activos</div>
                        </div>
                        <span class="material-symbols-rounded" style="color: var(--text-secondary);">arrow_forward</span>
                    </a>

                    <a href="#" class="quick-action">
                        <div class="quick-action-icon grades">
                            <span class="material-symbols-rounded">grade</span>
                        </div>
                        <div class="quick-action-content">
                            <div class="quick-action-title">Calificaciones</div>
                            <div class="quick-action-desc">Ver mis notas</div>
                        </div>
                        <span class="material-symbols-rounded" style="color: var(--text-secondary);">arrow_forward</span>
                    </a>

                    <a href="#" class="quick-action">
                        <div class="quick-action-icon enrollment">
                            <span class="material-symbols-rounded">schedule</span>
                        </div>
                        <div class="quick-action-content">
                            <div class="quick-action-title">Horarios</div>
                            <div class="quick-action-desc">Ver mis horarios</div>
                        </div>
                        <span class="material-symbols-rounded" style="color: var(--text-secondary);">arrow_forward</span>
                    </a>

                    <a href="#" class="quick-action">
                        <div class="quick-action-icon documents">
                            <span class="material-symbols-rounded">payments</span>
                        </div>
                        <div class="quick-action-content">
                            <div class="quick-action-title">Pagos</div>
                            <div class="quick-action-desc">Estado financiero</div>
                        </div>
                        <span class="material-symbols-rounded" style="color: var(--text-secondary);">arrow_forward</span>
                    </a>
                </div>
            </div>

            <!-- Información adicional -->
            <div class="activity-container">
                <div class="section-header">
                    <h3 class="section-title">Información</h3>
                    <p class="section-subtitle">Avisos importantes</p>
                </div>
                <div class="activity-list">
                    <div class="activity-item">
                        <div class="activity-icon success">
                            <span class="material-symbols-rounded">celebration</span>
                        </div>
                        <div class="activity-content">
                            <div class="activity-title">Bienvenido a Amimbré</div>
                            <div class="activity-time">Estamos felices de tenerte aquí</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</body>
</html>