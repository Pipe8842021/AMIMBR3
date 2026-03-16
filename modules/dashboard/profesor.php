<?php
/**
 * Dashboard Profesor
 * Vista para profesores
 */

// Incluir configuración de sesión y base de datos
require_once '../../config/session.php';
require_once '../../config/database.php';

// Verificar autenticación
require_once '../../includes/auth_check.php';

// Verificar que sea profesor
require_role('profesor');

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

// Obtener estadísticas del profesor
try {
    // Grupos asignados al profesor
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total 
        FROM grupos 
        WHERE profesor_id = ? AND estado = 'activo'
    ");
    $stmt->execute([$user['id']]);
    $grupos_activos = $stmt->fetch()['total'];
    
    // Total de estudiantes en sus grupos
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT m.estudiante_id) as total
        FROM matriculas m
        INNER JOIN grupos g ON m.grupo_id = g.id
        WHERE g.profesor_id = ? AND m.estado = 'activa'
    ");
    $stmt->execute([$user['id']]);
    $total_estudiantes = $stmt->fetch()['total'];
    
    // Próximas clases (simulado por ahora)
    $proximas_clases = [];
    
} catch (PDOException $e) {
    error_log("Error obteniendo estadísticas: " . $e->getMessage());
    $grupos_activos = 0;
    $total_estudiantes = 0;
    $proximas_clases = [];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Profesor - Amimbré</title>
    <link rel="shortcut icon" href="../../assets/img/3.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0" />
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="../../assets/css/colores.css">
    <link rel="stylesheet" href="../../assets/css/style-dashboardAdmin.css">
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
    <!-- Include Header/Sidebar -->
    <?php require_once '../../includes/header.php'; ?>

    <!-- Main Dashboard Content -->
    <main class="main-content">
        <!-- Header -->
        <div class="dashboard-header">
            <div class="dashboard-title">
                <h1>Dashboard Profesor</h1>
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
                    <span class="stat-title">Mis Grupos</span>
                    <div class="stat-icon courses">
                        <span class="material-symbols-rounded">groups</span>
                    </div>
                </div>
                <div class="stat-value"><?php echo number_format($grupos_activos); ?></div>
                <div class="stat-change">
                    <span>Grupos activos</span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-title">Mis Estudiantes</span>
                    <div class="stat-icon students">
                        <span class="material-symbols-rounded">school</span>
                    </div>
                </div>
                <div class="stat-value"><?php echo number_format($total_estudiantes); ?></div>
                <div class="stat-change">
                    <span>Total en mis grupos</span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-title">Próximas Clases</span>
                    <div class="stat-icon teachers">
                        <span class="material-symbols-rounded">event</span>
                    </div>
                </div>
                <div class="stat-value"><?php echo count($proximas_clases); ?></div>
                <div class="stat-change">
                    <span>Esta semana</span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-title">Asistencia</span>
                    <div class="stat-icon documents">
                        <span class="material-symbols-rounded">fact_check</span>
                    </div>
                </div>
                <div class="stat-value">85%</div>
                <div class="stat-change positive">
                    <span class="material-symbols-rounded">arrow_upward</span>
                    Promedio general
                </div>
            </div>
        </div>

        <!-- Content Grid -->
        <div class="content-grid">
            <!-- Acciones Rápidas -->
            <div class="quick-actions-container">
                <div class="section-header">
                    <h3 class="section-title">Acciones Rápidas</h3>
                    <p class="section-subtitle">Tareas frecuentes</p>
                </div>
                <div class="quick-actions-grid">
                    <a href="#" class="quick-action">
                        <div class="quick-action-icon enrollment">
                            <span class="material-symbols-rounded">fact_check</span>
                        </div>
                        <div class="quick-action-content">
                            <div class="quick-action-title">Tomar Asistencia</div>
                            <div class="quick-action-desc">Registrar asistencia</div>
                        </div>
                        <span class="material-symbols-rounded" style="color: var(--text-secondary);">arrow_forward</span>
                    </a>

                    <a href="#" class="quick-action">
                        <div class="quick-action-icon grades">
                            <span class="material-symbols-rounded">grade</span>
                        </div>
                        <div class="quick-action-content">
                            <div class="quick-action-title">Calificaciones</div>
                            <div class="quick-action-desc">Registrar notas</div>
                        </div>
                        <span class="material-symbols-rounded" style="color: var(--text-secondary);">arrow_forward</span>
                    </a>

                    <a href="#" class="quick-action">
                        <div class="quick-action-icon students">
                            <span class="material-symbols-rounded">groups</span>
                        </div>
                        <div class="quick-action-content">
                            <div class="quick-action-title">Mis Grupos</div>
                            <div class="quick-action-desc"><?php echo $grupos_activos; ?> activos</div>
                        </div>
                        <span class="material-symbols-rounded" style="color: var(--text-secondary);">arrow_forward</span>
                    </a>

                    <a href="#" class="quick-action">
                        <div class="quick-action-icon reports">
                            <span class="material-symbols-rounded">assessment</span>
                        </div>
                        <div class="quick-action-content">
                            <div class="quick-action-title">Reportes</div>
                            <div class="quick-action-desc">Ver estadísticas</div>
                        </div>
                        <span class="material-symbols-rounded" style="color: var(--text-secondary);">arrow_forward</span>
                    </a>
                </div>
            </div>

            <!-- Información adicional -->
            <div class="activity-container">
                <div class="section-header">
                    <h3 class="section-title">Información</h3>
                    <p class="section-subtitle">Recursos y ayuda</p>
                </div>
                <div class="activity-list">
                    <div class="activity-item">
                        <div class="activity-icon info">
                            <span class="material-symbols-rounded">info</span>
                        </div>
                        <div class="activity-content">
                            <div class="activity-title">Panel de Profesor</div>
                            <div class="activity-time">Aquí podrás gestionar tus grupos y estudiantes</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</body>
</html>