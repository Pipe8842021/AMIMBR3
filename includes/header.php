<?php
// Verificar que haya sesión iniciada
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../auth/login.php');
    exit;
}

// Obtener datos del usuario
$usuario_nombre = $_SESSION['user_nombre'] ?? 'Usuario';
$usuario_email = $_SESSION['user_email'] ?? '';
$usuario_rol = $_SESSION['user_rol'] ?? 'estudiante';
$esAdmin = ($usuario_rol === 'admin');
$esProfesor = ($usuario_rol === 'profesor');
$esEstudiante = ($usuario_rol === 'estudiante');

// Rol en español
$rol_nombre = [
    'admin' => 'Administrador',
    'profesor' => 'Profesor',
    'estudiante' => 'Estudiante'
][$usuario_rol] ?? 'Usuario';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="../../assets/css/colores.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: "Poppins", sans-serif;
        }

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            z-index: 10;
            width: 270px;
            height: 100vh;
            background: var(--dark-bg);
            transition: all 0.4s ease;
            box-shadow: var(--shadow-md);
            border-right: 1px solid var(--border-color);
        }

        .sidebar.collapsed {
            width: 85px;
        }

        .sidebar .sidebar-header {
            display: flex;
            position: relative;
            padding: 25px 20px;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid var(--border-color);
        }

        .sidebar-header .header-logo {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
        }

        .sidebar-header .header-logo img {
            width: 45px;
            height: 45px;
            object-fit: cover;
            border-radius: 50%;
            border: 2px solid var(--primary-blue);
            box-shadow: var(--shadow-sm);
            flex-shrink: 0;
        }

        .header-user-info {
            display: flex;
            flex-direction: column;
            overflow: hidden;
            transition: opacity 0.3s ease;
        }

        .sidebar.collapsed .header-user-info {
            opacity: 0;
            pointer-events: none;
        }

        .header-user-name {
            color: var(--text-primary);
            font-size: 0.95rem;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .header-user-role {
            color: var(--text-secondary);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .header-user-role.admin {
            color: var(--primary-blue);
        }

        .header-user-role.profesor {
            color: var(--primary-green);
        }

        .header-user-role.estudiante {
            color: var(--primary-yellow);
        }

        .sidebar-header .sidebar-toggler {
            position: absolute;
            right: 20px;
            height: 35px;
            width: 35px;
            color: var(--text-primary);
            border: none;
            cursor: pointer;
            display: flex;
            background: var(--primary-blue);
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            transition: 0.4s ease;
        }

        .sidebar-header .sidebar-toggler:hover {
            background: var(--primary-green);
        }

        .sidebar.collapsed .sidebar-header .sidebar-toggler {
            transform: translate(-4px, 65px);
        }

        .sidebar-header .sidebar-toggler span {
            font-size: 1.75rem;
            transition: 0.4s ease;
        }

        .sidebar.collapsed .sidebar-header .sidebar-toggler span {
            transform: rotate(180deg);
        }

        .sidebar-nav .nav-list {
            list-style: none;
            display: flex;
            gap: 4px;
            padding: 0 15px;
            flex-direction: column;
            transform: translateY(15px);
            transition: 0.4s ease;
        }

        .sidebar .sidebar-nav .primary-nav {
            overflow-y: auto;
            scrollbar-width: thin;
            padding-bottom: 20px;
            height: calc(100vh - 227px);
            scrollbar-color: transparent transparent;
        }

        .sidebar .sidebar-nav .primary-nav:hover {
            scrollbar-color: var(--primary-blue) transparent;
        }

        .sidebar.collapsed .sidebar-nav .primary-nav {
            overflow: unset;
            transform: translateY(65px);
        }

        .sidebar-nav .nav-item .nav-link {
            color: var(--text-primary);
            display: flex;
            gap: 12px;
            white-space: nowrap;
            border-radius: 8px;
            padding: 11px 15px;
            align-items: center;
            text-decoration: none;
            border: 1px solid transparent;
            transition: 0.4s ease;
        }

        .sidebar-nav .nav-item:is(:hover)>.nav-link {
            color: var(--primary-blue);
            background: var(--hover-bg);
            border: 1px solid var(--border-color);
        }

        .sidebar-nav .nav-item .nav-link.active {
            color: var(--primary-blue);
            background: var(--hover-bg);
            border: 1px solid var(--primary-blue);
        }

        .sidebar .nav-link .nav-label {
            transition: opacity 0.3s ease;
        }

        .sidebar.collapsed .nav-link .nav-label {
            opacity: 0;
            pointer-events: none;
        }

        .sidebar-nav .secondary-nav {
            position: absolute;
            bottom: 35px;
            width: 100%;
            background: var(--dark-bg);
            border-top: 1px solid var(--border-color);
            padding-top: 15px;
        }

        .sidebar-menu-button {
            display: none;
        }

        @media (max-width: 768px) {
            .sidebar-menu-button {
                position: fixed;
                left: 20px;
                top: 20px;
                height: 40px;
                width: 42px;
                display: flex;
                color: var(--text-primary);
                background: var(--dark-bg);
                z-index: 15;
                border: 1px solid var(--border-color);
                border-radius: 8px;
                align-items: center;
                justify-content: center;
                cursor: pointer;
            }

            .sidebar.collapsed {
                width: 270px;
                left: -270px;
            }

            .sidebar.collapsed .sidebar-header .sidebar-toggler {
                transform: none;
            }

            .sidebar.collapsed .sidebar-nav .primary-nav {
                transform: translateY(15px);
            }
        }
    </style>
</head>
<body>
    <button class="sidebar-menu-button" onclick="document.querySelector('.sidebar').classList.toggle('collapsed')">
        <span class="material-symbols-rounded">menu</span>
    </button>

    <div class="sidebar">
        <!-- Sidebar Header -->
        <div class="sidebar-header">
            <div class="header-logo">
                <img src="../../assets/img/3.png" alt="Amimbré">
                <div class="header-user-info">
                    <span class="header-user-name" title="<?php echo htmlspecialchars($usuario_nombre); ?>">
                        <?php echo htmlspecialchars($usuario_nombre); ?>
                    </span>
                    <span class="header-user-role <?php echo $usuario_rol; ?>">
                        <?php echo htmlspecialchars($rol_nombre); ?>
                    </span>
                </div>
            </div>
            <button class="sidebar-toggler" onclick="document.querySelector('.sidebar').classList.toggle('collapsed')">
                <span class="material-symbols-rounded">chevron_left</span>
            </button>
        </div>

        <nav class="sidebar-nav">
            <!-- Primary Nav -->
            <ul class="nav-list primary-nav">
                <li class="nav-item">
                    <a href="../dashboard/index.php" class="nav-link">
                        <span class="material-symbols-rounded">dashboard</span>
                        <span class="nav-label">Dashboard</span>
                    </a>
                </li>

                <?php if($esAdmin): ?>
                <!-- Menú para Administradores -->
                <li class="nav-item">
                    <a href="../prematriculas/index.php" class="nav-link">
                        <span class="material-symbols-rounded">description</span>
                        <span class="nav-label">Prematrículas</span>
                    </a>
                </li>
                
                <li class="nav-item">
                    <a href="../usuarios/index.php" class="nav-link">
                        <span class="material-symbols-rounded">group</span>
                        <span class="nav-label">Usuarios</span>
                    </a>
                </li>
                
                <li class="nav-item">
                    <a href="../cursos/index.php" class="nav-link">
                        <span class="material-symbols-rounded">menu_book</span>
                        <span class="nav-label">Cursos</span>
                    </a>
                </li>
                
                <li class="nav-item">
                    <a href="../matriculas/index.php" class="nav-link">
                        <span class="material-symbols-rounded">school</span>
                        <span class="nav-label">Matrículas</span>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="../notas/index.php" class="nav-link">
                        <span class="material-symbols-rounded">grade</span>
                        <span class="nav-label">Notas</span>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="../bitacoras/index.php" class="nav-link">
                        <span class="material-symbols-rounded">book</span>
                        <span class="nav-label">Bitácoras</span>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="../documentos/index.php" class="nav-link">
                        <span class="material-symbols-rounded">folder</span>
                        <span class="nav-label">Documentos</span>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="../reportes/index.php" class="nav-link">
                        <span class="material-symbols-rounded">assessment</span>
                        <span class="nav-label">Reportes</span>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="../notificaciones/index.php" class="nav-link">
                        <span class="material-symbols-rounded">notifications</span>
                        <span class="nav-label">Notificaciones</span>
                    </a>
                </li>

                <?php elseif($esProfesor): ?>
                <!-- Menú para Profesores -->
                <li class="nav-item">
                    <a href="../cursos/index.php" class="nav-link">
                        <span class="material-symbols-rounded">menu_book</span>
                        <span class="nav-label">Mis Cursos</span>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="../notas/index.php" class="nav-link">
                        <span class="material-symbols-rounded">grade</span>
                        <span class="nav-label">Notas</span>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="../bitacoras/index.php" class="nav-link">
                        <span class="material-symbols-rounded">book</span>
                        <span class="nav-label">Bitácoras</span>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="../documentos/index.php" class="nav-link">
                        <span class="material-symbols-rounded">folder</span>
                        <span class="nav-label">Documentos</span>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="../reportes/index.php" class="nav-link">
                        <span class="material-symbols-rounded">assessment</span>
                        <span class="nav-label">Reportes</span>
                    </a>
                </li>

                <?php else: ?>
                <!-- Menú para Estudiantes -->
                <li class="nav-item">
                    <a href="../cursos/index.php" class="nav-link">
                        <span class="material-symbols-rounded">menu_book</span>
                        <span class="nav-label">Mis Cursos</span>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="../notas/mis_notas.php" class="nav-link">
                        <span class="material-symbols-rounded">grade</span>
                        <span class="nav-label">Mis Notas</span>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="../bitacoras/index.php" class="nav-link">
                        <span class="material-symbols-rounded">book</span>
                        <span class="nav-label">Bitácoras</span>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="../documentos/recibidos.php" class="nav-link">
                        <span class="material-symbols-rounded">folder</span>
                        <span class="nav-label">Documentos</span>
                    </a>
                </li>
                <?php endif; ?>

                <li class="nav-item">
                    <a href="../configuracion/perfil.php" class="nav-link">
                        <span class="material-symbols-rounded">settings</span>
                        <span class="nav-label">Configuración</span>
                    </a>
                </li>
            </ul>
            
            <!-- Secondary Nav -->
            <ul class="nav-list secondary-nav">
                <li class="nav-item">
                    <a href="../ayuda/index.php" class="nav-link">
                        <span class="material-symbols-rounded">help</span>
                        <span class="nav-label">Ayuda</span>
                    </a>
                </li>
                
                <li class="nav-item">
                    <a href="../../auth/logout.php" class="nav-link" 
                        onclick="return confirm('¿Estás seguro de que deseas cerrar sesión?')">
                        <span class="material-symbols-rounded">logout</span>
                        <span class="nav-label">Cerrar sesión</span>
                    </a>
                </li>
            </ul>
        </nav>
    </div>
</body>
</html>