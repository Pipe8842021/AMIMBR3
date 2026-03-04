<?php

/**
 * Gestión de Usuarios - Vista Principal
 * Módulo para listar, filtrar y gestionar usuarios del sistema
 */

require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth_check.php';

require_role('admin');

// 1. Obtener datos del usuario actual
try {
    $stmt = $pdo->prepare("SELECT id, nombre, email, rol, estado, foto_perfil FROM usuarios WHERE id = ? AND estado = 'activo'");
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

$flash = function_exists('get_flash_message') ? get_flash_message() : null;

// 2. Procesar filtros
$filtro_rol = $_GET['rol'] ?? '';
$filtro_estado = $_GET['estado'] ?? '';
$filtro_curso = $_GET['curso'] ?? '';
$busqueda = $_GET['busqueda'] ?? '';

// 3. Obtener estadísticas (Optimizado en una sola consulta si fuera necesario, pero mantenemos tu estructura)
try {
    $total_usuarios = $pdo->query("SELECT COUNT(*) FROM usuarios")->fetchColumn();
    $usuarios_activos = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE estado = 'activo'")->fetchColumn();
    $usuarios_inactivos = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE estado = 'inactivo'")->fetchColumn();
    $total_admin = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE rol = 'admin' AND estado = 'activo'")->fetchColumn();
    $total_profesores = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE rol = 'profesor' AND estado = 'activo'")->fetchColumn();
    $total_estudiantes = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE rol = 'estudiante' AND estado = 'activo'")->fetchColumn();
} catch (PDOException $e) {
    error_log("Error stats: " . $e->getMessage());
}

// 4. Construir consulta con filtros (Mejorado con JOIN)
try {
    $sql = "SELECT DISTINCT u.* FROM usuarios u";
    $where = ["1=1"];
    $params = [];

    if ($filtro_curso) {
        $sql .= " INNER JOIN matriculas m ON u.id = m.estudiante_id 
                  INNER JOIN grupos g ON m.grupo_id = g.id";
        $where[] = "g.curso_id = ?";
        $params[] = $filtro_curso;
    }

    if ($filtro_rol) {
        $where[] = "u.rol = ?";
        $params[] = $filtro_rol;
    }

    if ($filtro_estado) {
        $where[] = "u.estado = ?";
        $params[] = $filtro_estado;
    }

    if ($busqueda) {
        $where[] = "(u.nombre LIKE ? OR u.email LIKE ? OR u.documento LIKE ?)";
        $search_val = "%$busqueda%";
        $params[] = $search_val;
        $params[] = $search_val;
        $params[] = $search_val;
    }

    $sql .= " WHERE " . implode(" AND ", $where) . " ORDER BY u.fecha_registro DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $cursos = $pdo->query("SELECT id, nombre FROM cursos WHERE estado = 'activo' ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error query: " . $e->getMessage());
    $usuarios = [];
}

// Funciones auxiliares
function badge_rol($rol)
{
    $clases = ['admin' => 'badge-admin', 'profesor' => 'badge-profesor', 'estudiante' => 'badge-estudiante'];
    return $clases[$rol] ?? 'badge-default';
}

function texto_rol($rol)
{
    $textos = ['admin' => 'Administrador', 'profesor' => 'Profesor', 'estudiante' => 'Estudiante'];
    return $textos[$rol] ?? ucfirst($rol);
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Gestión de Usuarios - Amimbré</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0" />
    <link rel="stylesheet" href="../../assets/css/colores.css">
    <link rel="stylesheet" href="../../assets/css/usuarios.css">
</head>

<body>
    <?php if (file_exists('../../includes/header.php')) require_once '../../includes/header.php'; ?>

    <main class="main-content">
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
                <span class="material-symbols-rounded">person_add</span> Nuevo Usuario
            </button>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon total"><span class="material-symbols-rounded">group</span></div>
                <div class="stat-info">
                    <span class="stat-label">Total Usuarios</span>
                    <span class="stat-value"><?php echo $total_usuarios; ?></span>
                </div>
            </div>
        </div>

        <div class="filters-card">
            <form method="GET" class="filters-form">
                <div class="filter-group">
                    <span class="material-symbols-rounded">search</span>
                    <input type="text" name="busqueda" placeholder="Buscar..." value="<?php echo htmlspecialchars($busqueda); ?>">
                </div>

                <div class="filter-group">
                    <select name="rol">
                        <option value="">Todos los roles</option>
                        <option value="admin" <?php echo $filtro_rol === 'admin' ? 'selected' : ''; ?>>Administrador</option>
                        <option value="profesor" <?php echo $filtro_rol === 'profesor' ? 'selected' : ''; ?>>Profesor</option>
                        <option value="estudiante" <?php echo $filtro_rol === 'estudiante' ? 'selected' : ''; ?>>Estudiante</option>
                    </select>
                </div>

                <div class="filter-group">
                    <select name="curso">
                        <option value="">Todos los cursos</option>
                        <?php foreach ($cursos as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo $filtro_curso == $c['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c['nombre']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="btn-filter">Buscar</button>
            </form>
        </div>

        <div class="users-card">
            <?php if (count($usuarios) > 0): ?>
                <div class="table-container">
                    <table class="users-table">
                        <thead>
                            <tr>
                                <th>Usuario</th>
                                <th>Contacto</th>
                                <th>Rol</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($usuarios as $u): ?>
                                <tr>
                                    <td>
                                        <div class="user-info">
                                            <div class="user-avatar">
                                                <?php if ($u['foto_perfil']): ?>
                                                    <img src="<?php echo htmlspecialchars($u['foto_perfil']); ?>">
                                                <?php else: ?>
                                                    <span class="avatar-initials"><?php echo strtoupper(substr($u['nombre'], 0, 2)); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="user-details">
                                                <span class="user-name"><?php echo htmlspecialchars($u['nombre']); ?></span>
                                                <span class="user-id">ID: <?php echo htmlspecialchars($u['documento']); ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="contact-info">
                                            <div><span class="material-symbols-rounded">mail</span> <?php echo htmlspecialchars($u['email']); ?></div>
                                            <?php if ($u['telefono']): ?>
                                                <div><span class="material-symbols-rounded">phone</span> <?php echo htmlspecialchars($u['telefono']); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo badge_rol($u['rol']); ?>">
                                            <?php echo texto_rol($u['rol']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?php echo $u['estado']; ?>">
                                            <?php echo ucfirst($u['estado']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="actions-menu">
                                            <button class="action-btn" onclick="toggleMenu(this)">
                                                <span class="material-symbols-rounded">more_vert</span>
                                            </button>
                                            <div class="dropdown-menu">
                                                <a href="editar.php?id=<?php echo $u['id']; ?>" class="dropdown-item">Editar</a>
                                                <a href="eliminar.php?id=<?php echo $u['id']; ?>&action=<?php echo $u['estado'] === 'activo' ? 'desactivar' : 'activar'; ?>"
                                                    class="dropdown-item <?php echo $u['estado'] === 'activo' ? 'danger' : ''; ?>">
                                                    <?php echo $u['estado'] === 'activo' ? 'Desactivar' : 'Activar'; ?>
                                                </a>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">No se encontraron usuarios.</div>
            <?php endif; ?>
        </div>
    </main>

    <script>
        function toggleMenu(btn) {
            document.querySelectorAll('.dropdown-menu').forEach(m => m.classList.remove('show'));
            btn.nextElementSibling.classList.toggle('show');
            event.stopPropagation();
        }
        window.onclick = () => document.querySelectorAll('.dropdown-menu').forEach(m => m.classList.remove('show'));
    </script>
</body>

</html>