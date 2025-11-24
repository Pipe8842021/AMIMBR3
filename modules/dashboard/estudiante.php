<?php
/**
 * Dashboard Estudiante
 * Vista del panel de estudiante
 */

if (!isset($user)) {
    die('Acceso no autorizado');
}

$flash = get_flash_message();

// Obtener estadísticas del estudiante
try {
    // Cursos matriculados
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM matriculas m 
        WHERE m.estudiante_id = ? AND m.estado = 'activa'
    ");
    $stmt->execute([$user['id']]);
    $cursos_matriculados = $stmt->fetchColumn();
    
    // Promedio general (simulado)
    $promedio_general = 4.2;
    
    // Bitácoras recibidas
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT b.id)
        FROM bitacoras b
        INNER JOIN grupos g ON b.grupo_id = g.id
        INNER JOIN matriculas m ON g.id = m.grupo_id
        WHERE m.estudiante_id = ?
    ");
    $stmt->execute([$user['id']]);
    $bitacoras_recibidas = $stmt->fetchColumn();
    
    // Documentos pendientes
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM documentos d
        WHERE d.destinatario_id = ? AND d.estado = 'enviado'
    ");
    $stmt->execute([$user['id']]);
    $documentos_pendientes = $stmt->fetchColumn();
    
} catch (PDOException $e) {
    error_log("Error obteniendo estadísticas estudiante: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Estudiante - Amimbré</title>
    <link rel="shortcut icon" href="../../assets/img/3.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/colores.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--dark-bg);
            color: var(--text-primary);
            display: flex;
            min-height: 100vh;
        }

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
            background: var(--gradient-primary-yellow);
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
            color: var(--primary-yellow);
            border-left: 3px solid var(--primary-yellow);
        }

        .menu-item i {
            width: 20px;
        }

        .main-content {
            margin-left: 260px;
            flex: 1;
            padding: 2rem;
        }

        .header {
            background: var(--card-bg);
            padding: 1.5rem;
            border-radius: 12px;
            border: 1px solid var(--border-color);
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .welcome {
            font-size: 1.5rem;
            font-weight: 600;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .logout-btn {
            padding: 0.5rem 1rem;
            background: var(--gradient-primary-orange);
            border: none;
            border-radius: 8px;
            color: white;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: transform 0.2s;
        }

        .logout-btn:hover {
            transform: translateY(-2px);
        }

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

        .stat-icon.yellow {
            background: rgba(233, 233, 62, 0.1);
            color: var(--primary-yellow);
        }

        .stat-icon.green {
            background: rgba(78, 195, 54, 0.1);
            color: var(--primary-green);
        }

        .stat-icon.blue {
            background: rgba(20, 121, 176, 0.1);
            color: var(--primary-blue);
        }

        .stat-icon.orange {
            background: rgba(255, 109, 0, 0.1);
            color: var(--primary-orange);
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

        .quick-actions {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .quick-actions h3 {
            margin-bottom: 1rem;
        }

        .action-buttons {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .action-btn {
            padding: 1rem;
            background: var(--hover-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            text-decoration: none;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            transition: all 0.3s;
        }

        .action-btn:hover {
            border-color: var(--primary-yellow);
            transform: translateY(-2px);
        }

        .action-btn i {
            font-size: 1.5rem;
            color: var(--primary-yellow);
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="logo-section">
            <img src="../../assets/img/3.png" alt="Amimbré">
            <h2>Amimbré</h2>
        </div>
        
        <nav class="menu">
            <a href="index.php" class="menu-item active">
                <i class="fas fa-home"></i>
                <span>Dashboard</span>
            </a>
            <a href="../cursos/index.php" class="menu-item">
                <i class="fas fa-book"></i>
                <span>Mis Cursos</span>
            </a>
            <a href="../notas/mis_notas.php" class="menu-item">
                <i class="fas fa-clipboard-list"></i>
                <span>Mis Notas</span>
            </a>
            <a href="../bitacoras/index.php" class="menu-item">
                <i class="fas fa-book-open"></i>
                <span>Bitácoras</span>
            </a>
            <a href="../documentos/recibidos.php" class="menu-item">
                <i class="fas fa-folder-open"></i>
                <span>Documentos</span>
            </a>
            <a href="../notificaciones/index.php" class="menu-item">
                <i class="fas fa-bell"></i>
                <span>Notificaciones</span>
            </a>
            <a href="../configuracion/perfil.php" class="menu-item">
                <i class="fas fa-user-cog"></i>
                <span>Mi Perfil</span>
            </a>
            <a href="../ayuda/index.php" class="menu-item">
                <i class="fas fa-question-circle"></i>
                <span>Ayuda</span>
            </a>
        </nav>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <div class="header">
            <div>
                <h1 class="welcome">¡Bienvenido, <?php echo htmlspecialchars($user['nombre']); ?>!</h1>
                <p style="color: var(--text-secondary);">Panel de Estudiante</p>
            </div>
            <div class="user-info">
                <a href="../../auth/logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i>
                    Cerrar Sesión
                </a>
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
                <div class="stat-icon yellow">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $cursos_matriculados ?? 0; ?></h3>
                    <p>Cursos Matriculados</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon green">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo number_format($promedio_general, 1); ?></h3>
                    <p>Promedio General</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon blue">
                    <i class="fas fa-book-open"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $bitacoras_recibidas ?? 0; ?></h3>
                    <p>Bitácoras Recibidas</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon orange">
                    <i class="fas fa-folder"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $documentos_pendientes ?? 0; ?></h3>
                    <p>Documentos Pendientes</p>
                </div>
            </div>
        </div>

        <!-- Acciones Rápidas -->
        <div class="quick-actions">
            <h3>Acciones Rápidas</h3>
            <div class="action-buttons">
                <a href="../notas/mis_notas.php" class="action-btn">
                    <i class="fas fa-clipboard-list"></i>
                    <span>Ver Mis Notas</span>
                </a>
                <a href="../cursos/index.php" class="action-btn">
                    <i class="fas fa-book"></i>
                    <span>Mis Cursos</span>
                </a>
                <a href="../documentos/recibidos.php" class="action-btn">
                    <i class="fas fa-folder-open"></i>
                    <span>Ver Documentos</span>
                </a>
                <a href="../configuracion/perfil.php" class="action-btn">
                    <i class="fas fa-user-cog"></i>
                    <span>Editar Perfil</span>
                </a>
            </div>
        </div>
    </main>
</body>
</html>