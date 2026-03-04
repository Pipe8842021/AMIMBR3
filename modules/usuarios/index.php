<?php
/**
 * Gestión de Usuarios - Vista Principal
 * Módulo para listar, filtrar y gestionar usuarios del sistema
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

// Procesar filtros
$filtro_rol = $_GET['rol'] ?? '';
$filtro_estado = $_GET['estado'] ?? '';
$filtro_curso = $_GET['curso'] ?? '';
$busqueda = $_GET['busqueda'] ?? '';

// Obtener estadísticas de usuarios
try {
    // Total de usuarios
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM usuarios");
    $total_usuarios = $stmt->fetch()['total'] ?? 0;
    
    // Usuarios activos e inactivos
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM usuarios WHERE estado = 'activo'");
    $usuarios_activos = $stmt->fetch()['total'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM usuarios WHERE estado = 'inactivo'");
    $usuarios_inactivos = $stmt->fetch()['total'] ?? 0;
    
    // Por rol
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM usuarios WHERE rol = 'admin' AND estado = 'activo'");
    $total_admin = $stmt->fetch()['total'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM usuarios WHERE rol = 'profesor' AND estado = 'activo'");
    $total_profesores = $stmt->fetch()['total'] ?? 0;
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM usuarios WHERE rol = 'estudiante' AND estado = 'activo'");
    $total_estudiantes = $stmt->fetch()['total'] ?? 0;
    
} catch (PDOException $e) {
    error_log("Error obteniendo estadísticas: " . $e->getMessage());
    $total_usuarios = $usuarios_activos = $usuarios_inactivos = 0;
    $total_admin = $total_profesores = $total_estudiantes = 0;
}

// Construir consulta de usuarios con filtros
try {
    $sql = "SELECT u.* FROM usuarios u WHERE 1=1";
    $params = [];
    
    if ($filtro_rol) {
        $sql .= " AND u.rol = ?";
        $params[] = $filtro_rol;
    }
    
    if ($filtro_estado) {
        $sql .= " AND u.estado = ?";
        $params[] = $filtro_estado;
    }
    
    if ($busqueda) {
        $sql .= " AND (u.nombre LIKE ? OR u.email LIKE ? OR u.documento LIKE ?)";
        $params[] = "%$busqueda%";
        $params[] = "%$busqueda%";
        $params[] = "%$busqueda%";
    }
    
    // Si hay filtro de curso, hacer JOIN con matrículas
    if ($filtro_curso) {
        $sql = "SELECT DISTINCT u.* FROM usuarios u 
                INNER JOIN matriculas m ON u.id = m.estudiante_id 
                INNER JOIN grupos g ON m.grupo_id = g.id 
                WHERE g.curso_id = ?";
        $params = [$filtro_curso];
        
        if ($filtro_rol) {
            $sql .= " AND u.rol = ?";
            $params[] = $filtro_rol;
        }
        if ($filtro_estado) {
            $sql .= " AND u.estado = ?";
            $params[] = $filtro_estado;
        }
        if ($busqueda) {
            $sql .= " AND (u.nombre LIKE ? OR u.email LIKE ?)";
            $params[] = "%$busqueda%";
            $params[] = "%$busqueda%";
        }
    }
    
    $sql .= " ORDER BY u.fecha_registro DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener cursos para el filtro
    $stmt = $pdo->query("SELECT id, nombre FROM cursos WHERE estado = 'activo' ORDER BY nombre");
    $cursos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error obteniendo usuarios: " . $e->getMessage());
    $usuarios = [];
    $cursos = [];
}

// Función para formatear fecha
function formatear_fecha($fecha) {
    if (!$fecha) return 'N/A';
    $fecha_obj = new DateTime($fecha);
    return $fecha_obj->format('d/m/Y');
}

// Función para obtener clase de badge de rol
function badge_rol($rol) {
    $clases = [
        'admin' => 'badge-admin',
        'profesor' => 'badge-profesor',
        'estudiante' => 'badge-estudiante'
    ];
    return $clases[$rol] ?? 'badge-default';
}

// Función para obtener texto de rol
function texto_rol($rol) {
    $textos = [
        'admin' => 'Administrador',
        'profesor' => 'Profesor',
        'estudiante' => 'Estudiante'
    ];
    return $textos[$rol] ?? $rol;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Usuarios - Amimbré</title>
    <link rel="shortcut icon" href="../../assets/img/3.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0" />
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="../../assets/css/colores.css">
    <link rel="stylesheet" href="../../assets/css/usuarios.css">
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
        <div class="page-header">
            <div class="header-left">
                <button class="back-button" onclick="window.location.href='../dashboard/'">
                    <span class="material-symbols-rounded">arrow_back</span>
                </button>
                <div class="header-title">
                    <h1>Gestión de Usuarios</h1>
                    <p>Administra todos los usuarios del sistema</p>
                </div>
            </div>
            <button class="btn-primary" onclick="window.location.href='crear.php'">
                <span class="material-symbols-rounded">person_add</span>
                Nuevo Usuario
            </button>
        </div>

        <?php if ($flash): ?>
        <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>">
            <span class="material-symbols-rounded">
                <?php echo $flash['type'] === 'success' ? 'check_circle' : 'info'; ?>
            </span>
            <?php echo htmlspecialchars($flash['message']); ?>
        </div>
        <?php endif; ?>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon total">
                    <span class="material-symbols-rounded">group</span>
                </div>
                <div class="stat-info">
                    <span class="stat-label">Total Usuarios</span>
                    <span class="stat-value"><?php echo $total_usuarios; ?></span>
                    <span class="stat-detail"><?php echo $usuarios_activos; ?> activos, <?php echo $usuarios_inactivos; ?> inactivos</span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon admin">
                    <span class="material-symbols-rounded">admin_panel_settings</span>
                </div>
                <div class="stat-info">
                    <span class="stat-label">Administradores</span>
                    <span class="stat-value"><?php echo $total_admin; ?></span>
                    <span class="stat-detail">Acceso completo</span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon profesor">
                    <span class="material-symbols-rounded">school</span>
                </div>
                <div class="stat-info">
                    <span class="stat-label">Profesores</span>
                    <span class="stat-value"><?php echo $total_profesores; ?></span>
                    <span class="stat-detail">Docentes activos</span>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon estudiante">
                    <span class="material-symbols-rounded">person</span>
                </div>
                <div class="stat-info">
                    <span class="stat-label">Estudiantes</span>
                    <span class="stat-value"><?php echo $total_estudiantes; ?></span>
                    <span class="stat-detail">Matriculados</span>
                </div>
            </div>
        </div>

        <!-- Filters Section -->
        <div class="filters-card">
            <div class="filters-header">
                <span class="material-symbols-rounded">filter_list</span>
                <h3>Filtros</h3>
            </div>
            
            <form method="GET" class="filters-form">
                <div class="filter-group">
                    <span class="material-symbols-rounded">search</span>
                    <input 
                        type="text" 
                        name="busqueda" 
                        placeholder="Buscar por nombre o email..."
                        value="<?php echo htmlspecialchars($busqueda); ?>"
                    >
                </div>

                <div class="filter-group">
                    <select name="rol" id="filtro-rol">
                        <option value="">Administradores</option>
                        <option value="admin" <?php echo $filtro_rol === 'admin' ? 'selected' : ''; ?>>Administradores</option>
                        <option value="profesor" <?php echo $filtro_rol === 'profesor' ? 'selected' : ''; ?>>Profesores</option>
                        <option value="estudiante" <?php echo $filtro_rol === 'estudiante' ? 'selected' : ''; ?>>Estudiantes</option>
                    </select>
                    <span class="material-symbols-rounded">expand_more</span>
                </div>

                <div class="filter-group">
                    <select name="estado" id="filtro-estado">
                        <option value="">Todos los estados</option>
                        <option value="activo" <?php echo $filtro_estado === 'activo' ? 'selected' : ''; ?>>Activos</option>
                        <option value="inactivo" <?php echo $filtro_estado === 'inactivo' ? 'selected' : ''; ?>>Inactivos</option>
                    </select>
                    <span class="material-symbols-rounded">expand_more</span>
                </div>

                <div class="filter-group">
                    <select name="curso" id="filtro-curso">
                        <option value="">Todos los cursos</option>
                        <?php foreach ($cursos as $curso): ?>
                            <option value="<?php echo $curso['id']; ?>" <?php echo $filtro_curso == $curso['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($curso['nombre']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="material-symbols-rounded">expand_more</span>
                </div>

                <button type="submit" class="btn-filter">
                    <span class="material-symbols-rounded">search</span>
                    Buscar
                </button>

                <?php if ($filtro_rol || $filtro_estado || $filtro_curso || $busqueda): ?>
                <a href="index.php" class="btn-clear">
                    <span class="material-symbols-rounded">close</span>
                    Limpiar
                </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Users List -->
        <div class="users-card">
            <div class="users-header">
                <h3>Lista de Usuarios (<?php echo count($usuarios); ?>)</h3>
                <p>Todos los usuarios registrados en el sistema</p>
            </div>

            <?php if (count($usuarios) > 0): ?>
            <div class="table-container">
                <table class="users-table">
                    <thead>
                        <tr>
                            <th>Usuario</th>
                            <th>Contacto</th>
                            <th>Rol</th>
                            <th>Curso</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($usuarios as $usuario): ?>
                        <tr>
                            <td>
                                <div class="user-info">
                                    <div class="user-avatar">
                                        <?php if ($usuario['foto_perfil']): ?>
                                            <img src="<?php echo htmlspecialchars($usuario['foto_perfil']); ?>" alt="Avatar">
                                        <?php else: ?>
                                            <span class="avatar-initials">
                                                <?php echo strtoupper(substr($usuario['nombre'], 0, 2)); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="user-details">
                                        <span class="user-name"><?php echo htmlspecialchars($usuario['nombre']); ?></span>
                                        <span class="user-id">ID: <?php echo $usuario['documento']; ?></span>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="contact-info">
                                    <div class="contact-item">
                                        <span class="material-symbols-rounded">mail</span>
                                        <?php echo htmlspecialchars($usuario['email']); ?>
                                    </div>
                                    <?php if ($usuario['telefono']): ?>
                                    <div class="contact-item">
                                        <span class="material-symbols-rounded">phone</span>
                                        <?php echo htmlspecialchars($usuario['telefono']); ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <span class="badge <?php echo badge_rol($usuario['rol']); ?>">
                                    <span class="material-symbols-rounded">
                                        <?php 
                                        echo $usuario['rol'] === 'admin' ? 'admin_panel_settings' : 
                                             ($usuario['rol'] === 'profesor' ? 'school' : 'person'); 
                                        ?>
                                    </span>
                                    <?php echo texto_rol($usuario['rol']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="curso-badge">—</span>
                            </td>
                            <td>
                                <span class="badge badge-<?php echo $usuario['estado']; ?>">
                                    <?php echo ucfirst($usuario['estado']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="actions-menu">
                                    <button class="action-btn" onclick="toggleMenu(this)">
                                        <span class="material-symbols-rounded">more_vert</span>
                                    </button>
                                    <div class="dropdown-menu">
                                        <a href="editar.php?id=<?php echo $usuario['id']; ?>" class="dropdown-item">
                                            <span class="material-symbols-rounded">edit</span>
                                            Editar
                                        </a>
                                        <?php if ($usuario['estado'] === 'activo'): ?>
                                        <a href="eliminar.php?id=<?php echo $usuario['id']; ?>&action=desactivar" 
                                           class="dropdown-item danger" 
                                           onclick="return confirm('¿Desactivar este usuario? No podrá acceder al sistema hasta que sea activado nuevamente.')">
                                            <span class="material-symbols-rounded">block</span>
                                            Desactivar
                                        </a>
                                        <?php else: ?>
                                        <a href="eliminar.php?id=<?php echo $usuario['id']; ?>&action=activar" 
                                           class="dropdown-item">
                                            <span class="material-symbols-rounded">check_circle</span>
                                            Activar
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <span class="material-symbols-rounded">person_off</span>
                <h3>No se encontraron usuarios</h3>
                <p>Intenta ajustar los filtros o crear un nuevo usuario</p>
            </div>
            <?php endif; ?>
        </div>
    </main>

    <script>
        // Toggle dropdown menu
        function toggleMenu(button) {
            const menu = button.nextElementSibling;
            const allMenus = document.querySelectorAll('.dropdown-menu');
            
            allMenus.forEach(m => {
                if (m !== menu) m.classList.remove('show');
            });
            
            menu.classList.toggle('show');
        }

        // Close menus when clicking outside
        document.addEventListener('click', (e) => {
            if (!e.target.closest('.actions-menu')) {
                document.querySelectorAll('.dropdown-menu').forEach(m => {
                    m.classList.remove('show');
                });
            }
        });

        // Auto-submit on select change
        document.querySelectorAll('.filters-form select').forEach(select => {
            select.addEventListener('change', () => {
                select.closest('form').submit();
            });
        });
    </script>
</body>
</html>