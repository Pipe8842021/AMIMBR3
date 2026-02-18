<?php
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth_check.php';

// El módulo es accesible para cualquier rol logueado, pero la pestaña de permisos es filtrada
$user_id = $_SESSION['user_id'];
$user_rol = $_SESSION['user_rol']; // Asumiendo que guardas el rol en la sesión
$mensaje_feedback = "";
$tipo_feedback = "";

// --- 1. PROCESAR ACTUALIZACIÓN DE PERFIL ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $nombre = trim($_POST['nombre']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    try {
        if (!empty($password)) {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE usuarios SET nombre = ?, email = ?, password = ? WHERE id = ?");
            $stmt->execute([$nombre, $email, $hashed_password, $user_id]);
        } else {
            $stmt = $pdo->prepare("UPDATE usuarios SET nombre = ?, email = ? WHERE id = ?");
            $stmt->execute([$nombre, $email, $user_id]);
        }
        $mensaje_feedback = "Perfil actualizado correctamente.";
        $tipo_feedback = "success";
        $_SESSION['user_nombre'] = $nombre;
    } catch (PDOException $e) {
        $mensaje_feedback = "Error: " . $e->getMessage();
        $tipo_feedback = "error";
    }
}

// --- 2. PROCESAR CAMBIO DE ROL (SOLO ADMIN) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_permission' && $user_rol === 'admin') {
    try {
        $stmt = $pdo->prepare("UPDATE usuarios SET rol = ? WHERE id = ?");
        $stmt->execute([$_POST['nuevo_rol'], $_POST['target_user_id']]);
        $mensaje_feedback = "Permiso actualizado exitosamente.";
        $tipo_feedback = "success";
    } catch (PDOException $e) {
        $mensaje_feedback = "Error al cambiar permiso.";
        $tipo_feedback = "error";
    }
}

// --- DATOS INICIALES ---
$stmt = $pdo->prepare("SELECT nombre, email, rol, usuario FROM usuarios WHERE id = ?");
$stmt->execute([$user_id]);
$usuario_actual = $stmt->fetch();

// Lista de usuarios para la pestaña de permisos (solo si es admin)
$lista_usuarios = [];
if ($user_rol === 'admin') {
    $lista_usuarios = $pdo->query("SELECT id, nombre, usuario, rol FROM usuarios WHERE id != $user_id ORDER BY rol ASC")->fetchAll();
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Configuraciones y Permisos - Amimbré</title>
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

        body {
            background: var(--hover-bg);
            color: var(--text-primary);
            min-height: 100vh;
            transition: background 0.3s;
        }

        .main-content {
            margin-left: 270px;
            padding: 30px;
        }

        .config-card {
            background: var(--dark-bg);
            border-radius: 16px;
            border: 1px solid var(--border-color);
            overflow: hidden;
            box-shadow: var(--shadow-md);
        }

        .tabs-header {
            display: flex;
            background: rgba(0, 0, 0, 0.2);
            border-bottom: 1px solid var(--border-color);
            overflow-x: auto;
        }

        .tab-link {
            padding: 15px 25px;
            border: none;
            background: none;
            color: var(--text-secondary);
            cursor: pointer;
            font-weight: 500;
            white-space: nowrap;
        }

        .tab-link.active {
            color: var(--primary-blue);
            border-bottom: 3px solid var(--primary-blue);
            background: var(--hover-bg);
        }

        .tab-content {
            display: none;
            padding: 30px;
        }

        .tab-content.active {
            display: block;
            animation: slideUp 0.3s ease;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }

        .form-group label {
            display: block;
            color: var(--text-secondary);
            margin-bottom: 8px;
            font-size: 0.85rem;
        }

        .input-field {
            width: 100%;
            padding: 12px;
            background: var(--hover-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: white;
        }

        /* Estilos para la tabla de permisos */
        .permisos-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        .permisos-table th {
            text-align: left;
            padding: 12px;
            color: var(--text-secondary);
            font-size: 0.85rem;
            border-bottom: 1px solid var(--border-color);
        }

        .permisos-table td {
            padding: 12px;
            border-bottom: 1px solid var(--border-color);
        }

        .role-badge {
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.75rem;
            text-transform: uppercase;
            font-weight: 600;
        }

        .role-admin {
            background: rgba(58, 110, 242, 0.2);
            color: var(--primary-blue);
        }

        .role-profesor {
            background: rgba(255, 152, 0, 0.2);
            color: var(--primary-orange);
        }

        .role-estudiante {
            background: rgba(76, 175, 80, 0.2);
            color: var(--primary-green);
        }

        .btn-action {
            padding: 8px 15px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            transition: 0.2s;
        }

        .btn-primary {
            background: var(--primary-blue);
            color: white;
        }

        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
            }
        }
    </style>
</head>

<body class="<?php echo isset($_COOKIE['theme']) && $_COOKIE['theme'] === 'dark' ? 'dark-theme' : ''; ?>">

    <?php include_once '../../includes/header.php'; ?>

    <main class="main-content">
        <div style="margin-bottom: 30px;">
            <h1><i class="fas fa-cog"></i> Configuración del Sistema</h1>
            <p>Ajustes de cuenta, apariencia y control de acceso</p>
        </div>

        <?php if ($mensaje_feedback): ?>
            <div style="padding:15px; margin-bottom:20px; border-radius:8px; border:1px solid; <?php echo $tipo_feedback === 'success' ? 'background:var(--subtle-green); color:var(--primary-green); border-color:var(--primary-green);' : 'background:rgba(255,0,0,0.1); color:#ff5252; border-color:#ff5252;'; ?>">
                <?php echo $mensaje_feedback; ?>
            </div>
        <?php endif; ?>

        <div class="config-card">
            <div class="tabs-header">
                <button class="tab-link active" onclick="openTab(event, 'profile')"><i class="fas fa-user"></i> Mi Perfil</button>
                <button class="tab-link" onclick="openTab(event, 'appearance')"><i class="fas fa-desktop"></i> Interfaz</button>
                <?php if ($user_rol === 'admin'): ?>
                    <button class="tab-link" onclick="openTab(event, 'permissions')"><i class="fas fa-shield-alt"></i> Gestión de Permisos</button>
                <?php endif; ?>
            </div>

            <div id="profile" class="tab-content active">
                <form method="POST" class="form-grid">
                    <input type="hidden" name="action" value="update_profile">
                    <div class="form-group">
                        <label>Nombre Completo</label>
                        <input type="text" name="nombre" class="input-field" value="<?php echo htmlspecialchars($usuario_actual['nombre']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Correo Electrónico</label>
                        <input type="email" name="email" class="input-field" value="<?php echo htmlspecialchars($usuario_actual['email']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Nueva Contraseña (Opcional)</label>
                        <input type="password" name="password" class="input-field" placeholder="Dejar en blanco para mantener actual">
                    </div>
                    <div style="grid-column: 1 / -1;">
                        <button type="submit" class="btn-action btn-primary">Guardar Cambios de Perfil</button>
                    </div>
                </form>
            </div>

            <div id="appearance" class="tab-content">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Tema del Sistema</label>
                        <button onclick="toggleDarkMode()" class="btn-action" style="background: var(--hover-bg); color: white; border: 1px solid var(--border-color); width: 100%;">
                            <i class="fas fa-moon"></i> Alternar Modo Oscuro
                        </button>
                    </div>
                    <div class="form-group">
                        <label>Tamaño de Fuente</label>
                        <select onchange="changeFontSize(this.value)" class="input-field">
                            <option value="14px">Pequeño</option>
                            <option value="16px" selected>Normal</option>
                            <option value="18px">Grande</option>
                        </select>
                    </div>
                </div>
            </div>

            <?php if ($user_rol === 'admin'): ?>
                <div id="permissions" class="tab-content">
                    <h3>Control de Roles</h3>
                    <p style="color:var(--text-secondary); font-size: 0.9rem; margin-bottom: 20px;">Modifica el nivel de acceso de otros usuarios registrados.</p>
                    <div style="overflow-x: auto;">
                        <table class="permisos-table">
                            <thead>
                                <tr>
                                    <th>Usuario</th>
                                    <th>Nombre</th>
                                    <th>Rol Actual</th>
                                    <th>Nuevo Rol</th>
                                    <th>Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($lista_usuarios as $u): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($u['usuario']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($u['nombre']); ?></td>
                                        <td><span class="role-badge role-<?php echo $u['rol']; ?>"><?php echo $u['rol']; ?></span></td>
                                        <form method="POST">
                                            <input type="hidden" name="action" value="change_permission">
                                            <input type="hidden" name="target_user_id" value="<?php echo $u['id']; ?>">
                                            <td>
                                                <select name="nuevo_rol" class="input-field" style="padding: 5px; font-size: 0.8rem;">
                                                    <option value="estudiante" <?php echo $u['rol'] == 'estudiante' ? 'selected' : ''; ?>>Estudiante</option>
                                                    <option value="profesor" <?php echo $u['rol'] == 'profesor' ? 'selected' : ''; ?>>Profesor</option>
                                                    <option value="admin" <?php echo $u['rol'] == 'admin' ? 'selected' : ''; ?>>Administrador</option>
                                                </select>
                                            </td>
                                            <td><button type="submit" class="btn-action btn-primary" style="padding: 5px 10px; font-size: 0.8rem;">Actualizar</button></td>
                                        </form>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <script>
        function openTab(evt, tabName) {
            let i, tabcontent, tablinks;
            tabcontent = document.getElementsByClassName("tab-content");
            for (i = 0; i < tabcontent.length; i++) tabcontent[i].classList.remove("active");
            tablinks = document.getElementsByClassName("tab-link");
            for (i = 0; i < tablinks.length; i++) tablinks[i].classList.remove("active");
            document.getElementById(tabName).classList.add("active");
            evt.currentTarget.classList.add("active");
        }

        function toggleDarkMode() {
            document.body.classList.toggle('dark-theme');
            const isDark = document.body.classList.contains('dark-theme');
            // Guardar preferencia en cookie para que sea persistente al recargar
            document.cookie = "theme=" + (isDark ? "dark" : "light") + ";path=/";
        }

        function changeFontSize(size) {
            document.documentElement.style.fontSize = size;
        }
    </script>
</body>

</html>