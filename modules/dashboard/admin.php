<?php
/**
 * Dashboard Administrador
 * Vista completa del panel de administración
 */

// No requiere auth_check porque ya se validó en index.php
if (!isset($user)) {
    die('Acceso no autorizado');
}

$flash = get_flash_message();

// Obtener estadísticas básicas
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE estado = 'activo'");
    $total_usuarios = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM preinscripciones WHERE estado = 'pendiente'");
    $preinscripciones_pendientes = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM cursos WHERE estado = 'activo'");
    $total_cursos = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM matriculas WHERE estado = 'activa'");
    $matriculas_activas = $stmt->fetchColumn();
    
} catch (PDOException $e) {
    error_log("Error obteniendo estadísticas: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Administrador - Amimbré</title>
    <link rel="shortcut icon" href="../../assets/img/3.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/colores.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--hover-bg);
            color: var(--text-primary);
            min-height: 100vh;
        }

        /* Sidebar */
        .sidebar {
            width: 260px;
            background: var(--card-bg);
            border-right: 1px solid var(--border-color);
            padding: 2rem 0;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
        }

        .logo-section {
            padding: 0 1.5rem 2rem;
            border-bottom: 1px solid var(--border-color);
            text-align: center;
        }

        .logo-section img {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            margin-bottom: 0.5rem;
        }

        .logo-section h2 {
            font-size: 1.3rem;
            background: var(--gradient-primary-blue);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .menu {
            padding: 1.5rem 0;
        }

        .menu-item {
            padding: 0.75rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            color: var(--text-secondary);
            text-decoration: none;
            transition: all 0.3s;
        }

        .menu-item:hover, .menu-item.active {
            background: var(--hover-bg);
            color: var(--primary-blue);
            border-left: 3px solid var(--primary-blue);
        }

        .menu-item i {
            width: 20px;
        }

        /* Main Content */
        .main-content {
            margin-left: 260px;
            flex: 1;
            padding: 2rem;
        }

        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border-color);
        }

        .dashboard-title h1 {
            color: var(--text-primary);
            font-size: 2rem;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .dashboard-title p {
            color: var(--text-secondary);
            font-size: 0.95rem;
        }

        .dashboard-date {
            color: var(--text-secondary);
            font-size: 0.9rem;
            font-weight: 500;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .stat-icon.blue {
            background: rgba(20, 121, 176, 0.1);
            color: var(--primary-blue);
        }

        .stat-icon.green {
            background: rgba(78, 195, 54, 0.1);
            color: var(--primary-green);
        }

        .stat-icon.orange {
            background: rgba(255, 109, 0, 0.1);
            color: var(--primary-orange);
        }

        .stat-icon.yellow {
            background: rgba(233, 233, 62, 0.1);
            color: var(--primary-yellow);
        }

        .stat-info h3 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .stat-info p {
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        .alert {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .alert-success {
            background: rgba(78, 195, 54, 0.1);
            border: 1px solid var(--primary-green);
            color: var(--primary-green);
        }

        .alert-error {
            background: rgba(255, 109, 0, 0.1);
            border: 1px solid var(--primary-orange);
            color: var(--primary-orange);
        }
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 80px 20px 20px;
            }

            .sidebar.collapsed ~ .main-content {
                margin-left: 0;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .dashboard-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
        }
    </style>
</head>
<body>
    <!-- Include Header/Sidebar -->
    <?php require_once '../../includes/header.php'; ?>

    <!-- Main Dashboard Content -->
    <main class="main-content">
        <!-- Header -->
        <div class="dashboard-header">
            <div class="dashboard-title">
                <h1>Dashboard</h1>
                <p>Bienvenido, <?php echo htmlspecialchars($user['nombre']); ?></p>
            </div>
            <div class="dashboard-date">
                <?php
                setlocale(LC_TIME, 'es_CO.UTF-8', 'es_CO', 'Spanish_Colombia');
                date_default_timezone_set('America/Bogota');
                echo strftime("%A, %d de %B de %Y");
                ?>
            </div>
        </div>

        <?php if ($flash): ?>
        <div class="alert alert-<?php echo $flash['type']; ?>">
            <i class="fas fa-info-circle"></i>
            <?php echo htmlspecialchars($flash['message']); ?>
        </div>
        <?php endif; ?>

        <!-- Estadísticas -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon blue">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $total_usuarios ?? 0; ?></h3>
                    <p>Usuarios Activos</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon green">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $matriculas_activas ?? 0; ?></h3>
                    <p>Matrículas Activas</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon orange">
                    <i class="fas fa-file-alt"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $preinscripciones_pendientes ?? 0; ?></h3>
                    <p>Preinscripciones Pendientes</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon yellow">
                    <i class="fas fa-book"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $total_cursos ?? 0; ?></h3>
                    <p>Cursos Activos</p>
                </div>
            </div>
        </div>
    </main>
</body>
</html>