<?php
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth_check.php';

require_role('admin');

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
    error_log($e->getMessage());
    die("Error del sistema. Por favor, intenta más tarde.");
}

$modal_errors   = [];
$modal_open     = false;
$form = [
    'nombre'           => '',
    'email'            => '',
    'documento'        => '',
    'telefono'         => '',
    'direccion'        => '',
    'fecha_nacimiento' => '',
    'rol'              => 'profesor',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action']) && $_POST['_action'] === 'crear_usuario') {

    $modal_open = true;

    foreach (['nombre', 'email', 'documento', 'telefono', 'direccion', 'fecha_nacimiento', 'rol'] as $f) {
        $form[$f] = trim($_POST[$f] ?? '');
    }
    $pass  = $_POST['password']         ?? '';
    $pass2 = $_POST['password_confirm'] ?? '';

    if (empty($form['nombre']))    $modal_errors[] = 'El nombre es obligatorio.';
    if (empty($form['email']))     $modal_errors[] = 'El correo es obligatorio.';
    elseif (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) $modal_errors[] = 'El correo no tiene un formato válido.';
    if (empty($form['documento'])) $modal_errors[] = 'El documento de identidad es obligatorio.';
    if (empty($pass))              $modal_errors[] = 'La contraseña es obligatoria.';
    elseif (strlen($pass) < 6)    $modal_errors[] = 'La contraseña debe tener al menos 6 caracteres.';
    elseif ($pass !== $pass2)      $modal_errors[] = 'Las contraseñas no coinciden.';

    if (!in_array($form['rol'], ['admin', 'profesor'])) {
        $modal_errors[] = 'Rol no válido. Solo se puede asignar Administrador o Profesor.';
    }

    if (empty($modal_errors)) {
        try {
            $ck = $pdo->prepare("SELECT id FROM usuarios WHERE email = ? OR documento = ?");
            $ck->execute([$form['email'], $form['documento']]);
            if ($ck->rowCount() > 0) $modal_errors[] = 'Ya existe un usuario con ese correo o documento.';
        } catch (PDOException $e) {
            $modal_errors[] = 'Error al verificar los datos. Intenta de nuevo.';
        }
    }

    if (empty($modal_errors)) {
        try {
            $pdo->prepare("
                INSERT INTO usuarios (nombre, email, password, documento, telefono, direccion, fecha_nacimiento, rol, estado)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'activo')
            ")->execute([
                $form['nombre'],
                $form['email'],
                password_hash($pass, PASSWORD_BCRYPT),
                $form['documento'],
                $form['telefono']         ?: null,
                $form['direccion']        ?: null,
                $form['fecha_nacimiento'] ?: null,
                $form['rol'],
            ]);

            $_SESSION['flash'] = [
                'type'    => 'success',
                'message' => 'Usuario "' . $form['nombre'] . '" creado exitosamente.',
            ];
            header("Location: index.php");
            exit;
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $modal_errors[] = 'No se pudo crear el usuario. Intenta nuevamente.';
        }
    }
}

$edit_errors  = [];
$edit_open    = false;
$edit_form    = [];
$edit_target  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action']) && $_POST['_action'] === 'editar_usuario') {

    $edit_id = (int)($_POST['edit_id'] ?? 0);
    $edit_open = true;

    try {
        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = ?");
        $stmt->execute([$edit_id]);
        $edit_target = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $edit_target = null;
    }

    foreach (['nombre', 'email', 'documento', 'telefono', 'direccion', 'fecha_nacimiento', 'rol', 'estado'] as $f) {
        $edit_form[$f] = trim($_POST[$f] ?? '');
    }
    $newpass  = $_POST['e_new_password']         ?? '';
    $newpass2 = $_POST['e_new_password_confirm'] ?? '';

    if (empty($edit_form['nombre']))    $edit_errors[] = 'El nombre es obligatorio.';
    if (empty($edit_form['email']))     $edit_errors[] = 'El correo es obligatorio.';
    elseif (!filter_var($edit_form['email'], FILTER_VALIDATE_EMAIL)) $edit_errors[] = 'El correo no tiene un formato válido.';
    if (empty($edit_form['documento'])) $edit_errors[] = 'El documento es obligatorio.';
    if (!in_array($edit_form['rol'],    ['admin', 'profesor', 'estudiante'])) $edit_errors[] = 'Rol no válido.';
    if (!in_array($edit_form['estado'], ['activo', 'inactivo', 'suspendido'])) $edit_errors[] = 'Estado no válido.';

    if ($edit_id === (int)$_SESSION['user_id'] && $edit_form['rol'] !== 'admin') {
        $edit_errors[] = 'No puedes cambiar tu propio rol de administrador.';
    }
    if ($edit_id === (int)$_SESSION['user_id'] && $edit_form['estado'] !== 'activo') {
        $edit_errors[] = 'No puedes desactivar tu propia cuenta.';
    }

    if (!empty($newpass)) {
        if (strlen($newpass) < 6)       $edit_errors[] = 'La nueva contraseña debe tener al menos 6 caracteres.';
        elseif ($newpass !== $newpass2) $edit_errors[] = 'Las contraseñas no coinciden.';
    }

    if (empty($edit_errors)) {
        try {
            $ck = $pdo->prepare("SELECT id FROM usuarios WHERE (email = ? OR documento = ?) AND id != ?");
            $ck->execute([$edit_form['email'], $edit_form['documento'], $edit_id]);
            if ($ck->rowCount() > 0) $edit_errors[] = 'Ya existe otro usuario con ese correo o documento.';
        } catch (PDOException $e) {
            $edit_errors[] = 'Error al verificar los datos.';
        }
    }

    if (empty($edit_errors)) {
        try {
            if (!empty($newpass)) {
                $pdo->prepare("
                    UPDATE usuarios
                    SET nombre=?, email=?, password=?, documento=?, telefono=?,
                        direccion=?, fecha_nacimiento=?, rol=?, estado=?
                    WHERE id=?
                ")->execute([
                    $edit_form['nombre'], $edit_form['email'],
                    password_hash($newpass, PASSWORD_BCRYPT),
                    $edit_form['documento'],
                    $edit_form['telefono']         ?: null,
                    $edit_form['direccion']        ?: null,
                    $edit_form['fecha_nacimiento'] ?: null,
                    $edit_form['rol'], $edit_form['estado'], $edit_id,
                ]);
            } else {
                $pdo->prepare("
                    UPDATE usuarios
                    SET nombre=?, email=?, documento=?, telefono=?,
                        direccion=?, fecha_nacimiento=?, rol=?, estado=?
                    WHERE id=?
                ")->execute([
                    $edit_form['nombre'], $edit_form['email'], $edit_form['documento'],
                    $edit_form['telefono']         ?: null,
                    $edit_form['direccion']        ?: null,
                    $edit_form['fecha_nacimiento'] ?: null,
                    $edit_form['rol'], $edit_form['estado'], $edit_id,
                ]);
            }

            $_SESSION['flash'] = [
                'type'    => 'success',
                'message' => 'Usuario "' . $edit_form['nombre'] . '" actualizado correctamente.',
            ];
            header("Location: index.php");
            exit;

        } catch (PDOException $e) {
            error_log($e->getMessage());
            $edit_errors[] = 'No se pudo actualizar el usuario. Intenta nuevamente.';
        }
    }
}

$flash = null;
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

$filtro_rol    = $_GET['rol']      ?? '';
$filtro_estado = $_GET['estado']   ?? '';
$busqueda      = $_GET['busqueda'] ?? '';
$pagina        = max(1, (int)($_GET['pagina'] ?? 1));
$por_pagina    = 15;

try {
    $total_usuarios = (int) $pdo->query("SELECT COUNT(*) FROM usuarios")->fetchColumn();
} catch (PDOException $e) {
    $total_usuarios = 0;
}

try {
    $sql_where = "WHERE 1=1";
    $params    = [];

    if ($filtro_rol)    { $sql_where .= " AND rol = ?";    $params[] = $filtro_rol; }
    if ($filtro_estado) { $sql_where .= " AND estado = ?"; $params[] = $filtro_estado; }
    if ($busqueda) {
        $sql_where .= " AND (nombre LIKE ? OR email LIKE ? OR documento LIKE ?)";
        $s = "%$busqueda%";
        array_push($params, $s, $s, $s);
    }

    $stmtC = $pdo->prepare("SELECT COUNT(*) FROM usuarios $sql_where");
    $stmtC->execute($params);
    $total_filtrados = (int)$stmtC->fetchColumn();
    $total_paginas   = max(1, (int)ceil($total_filtrados / $por_pagina));
    $pagina          = min($pagina, $total_paginas);
    $offset          = ($pagina - 1) * $por_pagina;

    $stmt = $pdo->prepare("SELECT * FROM usuarios $sql_where ORDER BY fecha_registro DESC LIMIT $por_pagina OFFSET $offset");
    $stmt->execute($params);
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log($e->getMessage());
    $usuarios        = [];
    $total_filtrados = 0;
    $total_paginas   = 1;
    $offset          = 0;
}

function badge_rol($r) {
    return ['admin' => 'badge-admin', 'profesor' => 'badge-profesor', 'estudiante' => 'badge-estudiante'][$r] ?? '';
}
function texto_rol($r) {
    return ['admin' => 'Administrador', 'profesor' => 'Profesor', 'estudiante' => 'Estudiante'][$r] ?? ucfirst($r);
}
function badge_estado($e) {
    return ['activo' => 'badge-activo', 'inactivo' => 'badge-inactivo', 'suspendido' => 'badge-suspendido'][$e] ?? '';
}
function get_foto_url(?string $foto): string {
    if (empty($foto)) return '';
    return '../../assets/img/avatars/' . htmlspecialchars($foto);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Usuarios – Amimbré</title>
    <link rel="shortcut icon" href="../../assets/img/3.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,1,0">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="../../assets/css/colores.css">
    <link rel="stylesheet" href="../../assets/css/style-usuarios.css">
    <script>
        (function () {
            const t = localStorage.getItem('amimbre-theme');
            if (t === 'light') document.documentElement.setAttribute('data-theme', 'light');
        })();
    </script>
</head>
<body>

<?php if (file_exists('../../includes/header.php')) require_once '../../includes/header.php'; ?>

<main class="main-content">

    <?php if ($flash): ?>
    <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>" id="alertFlash">
        <span class="material-symbols-rounded"><?= $flash['type'] === 'success' ? 'check_circle' : 'error' ?></span>
        <?= htmlspecialchars($flash['message']) ?>
    </div>
    <?php endif; ?>

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
        <button class="btn-primary" onclick="abrirModalCrear()">
            <span class="material-symbols-rounded">person_add</span> Nuevo Usuario
        </button>
    </div>

    <div class="stat-card">
        <div class="stat-icon total">
            <span class="material-symbols-rounded">group</span>
        </div>
        <div class="stat-info">
            <span class="stat-label">Total Usuarios</span>
            <span class="stat-value"><?= $total_usuarios ?></span>
        </div>
    </div>

    <div class="filters-card">
        <div class="filters-header">
            <span class="material-symbols-rounded">filter_list</span>
            Filtros
        </div>
        <form method="GET" class="filters-form">
            <div class="filter-group">
                <span class="material-symbols-rounded">search</span>
                <input type="text" name="busqueda"
                       placeholder="Buscar por nombre, email o documento..."
                       value="<?= htmlspecialchars($busqueda) ?>">
            </div>
            <div class="filter-group">
                <span class="material-symbols-rounded">manage_accounts</span>
                <select name="rol">
                    <option value="">Todos los roles</option>
                    <option value="admin"      <?= $filtro_rol === 'admin'      ? 'selected' : '' ?>>Administrador</option>
                    <option value="profesor"   <?= $filtro_rol === 'profesor'   ? 'selected' : '' ?>>Profesor</option>
                    <option value="estudiante" <?= $filtro_rol === 'estudiante' ? 'selected' : '' ?>>Estudiante</option>
                </select>
            </div>
            <div class="filter-group">
                <span class="material-symbols-rounded">toggle_on</span>
                <select name="estado">
                    <option value="">Todos los estados</option>
                    <option value="activo"     <?= $filtro_estado === 'activo'     ? 'selected' : '' ?>>Activo</option>
                    <option value="inactivo"   <?= $filtro_estado === 'inactivo'   ? 'selected' : '' ?>>Inactivo</option>
                    <option value="suspendido" <?= $filtro_estado === 'suspendido' ? 'selected' : '' ?>>Suspendido</option>
                </select>
            </div>
            <button type="submit" class="btn-filter">
                <span class="material-symbols-rounded">search</span> Buscar
            </button>
            <?php if ($busqueda || $filtro_rol || $filtro_estado): ?>
            <a href="index.php" class="btn-clear">
                <span class="material-symbols-rounded">close</span> Limpiar
            </a>
            <?php endif; ?>
        </form>
    </div>

    <div class="users-card">
        <div class="users-card-header">
            <h3>Lista de usuarios</h3>
            <span class="result-count"><?= $total_filtrados ?> resultado<?= $total_filtrados !== 1 ? 's' : '' ?></span>
        </div>

        <?php if (count($usuarios) > 0): ?>
        <div class="table-container">
            <table class="users-table">
                <thead>
                    <tr>
                        <th>Usuario</th>
                        <th>Contacto</th>
                        <th>Rol</th>
                        <th>Estado</th>
                        <th>Registro</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($usuarios as $u): ?>
                    <tr>
                        <td>
                            <div class="user-info">
                                <div class="user-avatar avatar-<?= htmlspecialchars($u['rol']) ?>">
                                    <?php $foto_url = get_foto_url($u['foto_perfil']); ?>
                                    <?php if ($foto_url): ?>
                                        <img src="<?= $foto_url ?>"
                                            alt=""
                                            onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                        <div class="avatar-initials" style="display:none;">
                                            <?= strtoupper(mb_substr($u['nombre'], 0, 2)) ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="avatar-initials">
                                            <?= strtoupper(mb_substr($u['nombre'], 0, 2)) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="user-details">
                                    <span class="user-name"><?= htmlspecialchars($u['nombre']) ?></span>
                                    <span class="user-id">ID: <?= htmlspecialchars($u['documento']) ?></span>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="contact-info">
                                <div class="contact-item">
                                    <span class="material-symbols-rounded">mail</span>
                                    <?= htmlspecialchars($u['email']) ?>
                                </div>
                                <?php if (!empty($u['telefono'])): ?>
                                <div class="contact-item">
                                    <span class="material-symbols-rounded">phone</span>
                                    <?= htmlspecialchars($u['telefono']) ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <span class="badge <?= badge_rol($u['rol']) ?>">
                                <?= texto_rol($u['rol']) ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge <?= badge_estado($u['estado']) ?>">
                                <?= ucfirst($u['estado']) ?>
                            </span>
                        </td>
                        <td style="color:var(--text-secondary);font-size:.8rem">
                            <?= date('d/m/Y', strtotime($u['fecha_registro'])) ?>
                        </td>
                        <td>
                            <div class="actions-menu">
                                <button class="action-btn" onclick="toggleMenu(this, event)">
                                    <span class="material-symbols-rounded">more_vert</span>
                                </button>
                                <div class="dropdown-menu">
                                    <button class="dropdown-item"
                                            onclick="abrirModalEditar(
                                                <?= $u['id'] ?>,
                                                <?= (int)($_SESSION['user_id']) ?>,
                                                <?= htmlspecialchars(json_encode([
                                                    'nombre'           => $u['nombre'],
                                                    'email'            => $u['email'],
                                                    'documento'        => $u['documento'],
                                                    'telefono'         => $u['telefono'] ?? '',
                                                    'direccion'        => $u['direccion'] ?? '',
                                                    'fecha_nacimiento' => $u['fecha_nacimiento'] ?? '',
                                                    'rol'              => $u['rol'],
                                                    'estado'           => $u['estado'],
                                                    'fecha_registro'   => $u['fecha_registro'],
                                                    'ultima_conexion'  => $u['ultima_conexion'] ?? null,
                                                    'foto_perfil'      => $u['foto_perfil'] ?? '',
                                                ]), ENT_QUOTES) ?>
                                            )">
                                        <span class="material-symbols-rounded">edit</span> Editar
                                    </button>
                                    <?php if ($u['id'] != $_SESSION['user_id']): ?>
                                        <?php if ($u['estado'] === 'activo'): ?>
                                        <button class="dropdown-item danger"
                                            onclick="abrirModal(<?= $u['id'] ?>, '<?= htmlspecialchars(addslashes($u['nombre'])) ?>')">
                                            <span class="material-symbols-rounded">person_off</span> Desactivar
                                        </button>
                                        <?php else: ?>
                                        <a href="desactivar.php?id=<?= $u['id'] ?>&action=activar"
                                           class="dropdown-item"
                                           onclick="return confirm('¿Activar a <?= htmlspecialchars(addslashes($u['nombre'])) ?>?')">
                                            <span class="material-symbols-rounded">person_check</span> Activar
                                        </a>
                                        <?php endif; ?>
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
            <span class="material-symbols-rounded">manage_search</span>
            <h3>Sin resultados</h3>
            <p>No se encontraron usuarios con los filtros aplicados.</p>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($total_paginas > 1): ?>
    <div class="pagination">
        <span class="pag-info">
            Mostrando <?= min($offset + 1, $total_filtrados) ?>–<?= min($offset + $por_pagina, $total_filtrados) ?> de <?= $total_filtrados ?>
        </span>
        <div class="pag-controls">
            <?php if ($pagina > 1): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['pagina' => $pagina - 1])) ?>" class="pag-btn">
                <span class="material-symbols-rounded">chevron_left</span>
            </a>
            <?php endif; ?>
            <?php for ($i = max(1, $pagina - 2); $i <= min($total_paginas, $pagina + 2); $i++): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['pagina' => $i])) ?>"
               class="pag-btn <?= $i === $pagina ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
            <?php if ($pagina < $total_paginas): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['pagina' => $pagina + 1])) ?>" class="pag-btn">
                <span class="material-symbols-rounded">chevron_right</span>
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

</main>

<div class="modal-overlay" id="modalDesactivar">
    <div class="modal">
        <div class="modal-icon">
            <span class="material-symbols-rounded">person_off</span>
        </div>
        <p class="modal-title">¿Desactivar usuario?</p>
        <p class="modal-body">
            Estás a punto de desactivar a <strong id="modalNombre"></strong>.<br>
            No podrá iniciar sesión hasta que sea reactivado.
        </p>
        <div class="modal-actions">
            <button class="btn-cancel" onclick="cerrarModal()">Cancelar</button>
            <a href="#" id="modalConfirmBtn" class="btn-danger">
                <span class="material-symbols-rounded">person_off</span> Sí, desactivar
            </a>
        </div>
    </div>
</div>

<div class="modal-overlay modal-crear-overlay" id="modalCrearUsuario">
    <div class="modal modal-crear">

        <div class="modal-crear-header">
            <div class="modal-crear-title-group">
                <div class="modal-crear-icon-wrap">
                    <span class="material-symbols-rounded">person_add</span>
                </div>
                <div>
                    <h2 class="modal-crear-title">Crear Usuario</h2>
                    <p class="modal-crear-subtitle">Registra un nuevo administrador o profesor</p>
                </div>
            </div>
            <button class="modal-crear-close" onclick="cerrarModalCrear()" aria-label="Cerrar">
                <span class="material-symbols-rounded">close</span>
            </button>
        </div>

        <div class="modal-crear-body">

            <?php if (!empty($modal_errors)): ?>
            <div class="alert alert-error" style="margin:0 28px 0;padding-top:20px;">
                <span class="material-symbols-rounded">error</span>
                <div>
                    <?php if (count($modal_errors) === 1): ?>
                        <?= htmlspecialchars($modal_errors[0]) ?>
                    <?php else: ?>
                        <strong>Corrige los siguientes errores:</strong>
                        <ul style="margin:6px 0 0;padding-left:16px;">
                            <?php foreach ($modal_errors as $e): ?>
                                <li style="margin-top:4px;"><?= htmlspecialchars($e) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <form method="POST" id="formCrearUsuario" novalidate>
                <input type="hidden" name="_action" value="crear_usuario">

                <div class="alert alert-error" id="alertValidarCrear" style="display:none">
                    <span class="material-symbols-rounded">error</span>
                    <span id="msgValidarCrear"></span>
                </div>

                <div class="field" style="margin-bottom:20px">
                    <label>Rol del usuario <span class="required">*</span></label>
                    <div class="role-cards">
                        <label class="role-card <?= $form['rol'] === 'admin' ? 'selected-admin' : '' ?>" id="modal-card-admin">
                            <input type="radio" name="rol" value="admin"
                                   <?= $form['rol'] === 'admin' ? 'checked' : '' ?>
                                   onchange="selectRole(this)">
                            <div class="role-icon admin">
                                <span class="material-symbols-rounded">admin_panel_settings</span>
                            </div>
                            <div class="role-info">
                                <strong>Administrador</strong>
                                <span>Acceso total al sistema</span>
                            </div>
                        </label>
                        <label class="role-card <?= $form['rol'] === 'profesor' ? 'selected-profesor' : '' ?>" id="modal-card-profesor">
                            <input type="radio" name="rol" value="profesor"
                                   <?= $form['rol'] === 'profesor' ? 'checked' : '' ?>
                                   onchange="selectRole(this)">
                            <div class="role-icon profesor">
                                <span class="material-symbols-rounded">school</span>
                            </div>
                            <div class="role-info">
                                <strong>Profesor</strong>
                                <span>Gestión académica y grupos</span>
                            </div>
                        </label>
                    </div>
                </div>

                <hr class="divider">
                <p class="section-label">Información personal</p>

                <div class="form-grid">
                    <div class="field col-full">
                        <label for="m_nombre">Nombre completo <span class="required">*</span></label>
                        <input type="text" id="m_nombre" name="nombre"
                               value="<?= htmlspecialchars($form['nombre']) ?>"
                               placeholder="Ej: Juan Pérez García" required>
                    </div>
                    <div class="field">
                        <label for="m_email">Correo electrónico <span class="required">*</span></label>
                        <input type="email" id="m_email" name="email"
                               value="<?= htmlspecialchars($form['email']) ?>"
                               placeholder="correo@ejemplo.com" required>
                    </div>
                    <div class="field">
                        <label for="m_documento">N° de documento <span class="required">*</span></label>
                        <input type="text" id="m_documento" name="documento"
                               value="<?= htmlspecialchars($form['documento']) ?>"
                               placeholder="Cédula o documento" required>
                    </div>
                    <div class="field">
                        <label for="m_telefono">Teléfono</label>
                        <input type="tel" id="m_telefono" name="telefono"
                               value="<?= htmlspecialchars($form['telefono']) ?>"
                               placeholder="Número de contacto">
                    </div>
                    <div class="field">
                        <label for="m_fecha_nacimiento">Fecha de nacimiento</label>
                        <input type="date" id="m_fecha_nacimiento" name="fecha_nacimiento"
                               value="<?= htmlspecialchars($form['fecha_nacimiento']) ?>">
                    </div>
                    <div class="field col-full">
                        <label for="m_direccion">Dirección</label>
                        <textarea id="m_direccion" name="direccion"
                                  placeholder="Dirección de residencia (opcional)"><?= htmlspecialchars($form['direccion']) ?></textarea>
                    </div>
                </div>

                <hr class="divider">
                <p class="section-label">Contraseña</p>

                <div class="form-grid">
                    <div class="field">
                        <label for="m_password">Contraseña <span class="required">*</span></label>
                        <div class="input-wrap">
                            <input type="password" id="m_password" name="password"
                                   placeholder="Mínimo 6 caracteres" required>
                            <button type="button" class="toggle-pass" onclick="togglePass('m_password', this)">
                                <span class="material-symbols-rounded">visibility</span>
                            </button>
                        </div>
                    </div>
                    <div class="field">
                        <label for="m_password_confirm">Confirmar contraseña <span class="required">*</span></label>
                        <div class="input-wrap">
                            <input type="password" id="m_password_confirm" name="password_confirm"
                                   placeholder="Repite la contraseña" required>
                            <button type="button" class="toggle-pass" onclick="togglePass('m_password_confirm', this)">
                                <span class="material-symbols-rounded">visibility</span>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn-cancel" onclick="cerrarModalCrear()">Cancelar</button>
                    <button type="submit" class="btn-submit">
                        <span class="material-symbols-rounded">person_add</span> Crear usuario
                    </button>
                </div>

            </form>
        </div>
    </div>
</div>

<div class="modal-overlay modal-crear-overlay" id="modalEditarUsuario">
    <div class="modal modal-crear">

        <div class="modal-crear-header">
            <div class="modal-crear-title-group">
                <div class="modal-crear-icon-wrap" style="background:var(--subtle-orange);color:var(--primary-orange)">
                    <span class="material-symbols-rounded">manage_accounts</span>
                </div>
                <div>
                    <h2 class="modal-crear-title">Editar Usuario</h2>
                    <p class="modal-crear-subtitle" id="e_subtitle">Modifica los datos del usuario</p>
                </div>
            </div>
            <button class="modal-crear-close" onclick="cerrarModalEditar()" aria-label="Cerrar">
                <span class="material-symbols-rounded">close</span>
            </button>
        </div>

        <div class="modal-editar-info-card" id="e_info_bar">
            <div class="modal-editar-avatar-wrap" id="e_avatar_wrap">
                <div class="avatar-initials">??</div>
            </div>
            <div class="modal-editar-info-content">
                <span class="modal-editar-nombre" id="e_chip_nombre">—</span>
                <div class="modal-editar-badges">
                    <span class="badge" id="e_badge_rol">—</span>
                    <span class="badge" id="e_badge_estado">—</span>
                </div>
                <div class="modal-editar-meta">
                    <span class="modal-editar-meta-item">
                        <span class="material-symbols-rounded">calendar_today</span>
                        Registro: <strong id="e_fecha_registro">—</strong>
                    </span>
                    <span class="modal-editar-meta-item">
                        <span class="material-symbols-rounded">login</span>
                        Última sesión: <strong id="e_ultima_conexion">—</strong>
                    </span>
                </div>
            </div>
        </div>

        <div class="modal-crear-body">

            <?php if (!empty($edit_errors)): ?>
            <div class="alert alert-error" style="margin:0 28px 0;padding-top:20px;">
                <span class="material-symbols-rounded">error</span>
                <div>
                    <?php if (count($edit_errors) === 1): ?>
                        <?= htmlspecialchars($edit_errors[0]) ?>
                    <?php else: ?>
                        <strong>Corrige los siguientes errores:</strong>
                        <ul style="margin:6px 0 0;padding-left:16px;">
                            <?php foreach ($edit_errors as $err): ?>
                                <li style="margin-top:4px;"><?= htmlspecialchars($err) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <form method="POST" id="formEditarUsuario" novalidate>
                <input type="hidden" name="_action" value="editar_usuario">
                <input type="hidden" name="edit_id"  id="e_edit_id" value="">

                <div class="form-section-card">
                <p class="section-label">Información personal</p>
                <div class="form-grid">
                    <div class="field col-full">
                        <label for="e_nombre">Nombre completo <span class="required">*</span></label>
                        <input type="text" id="e_nombre" name="nombre"
                               value="<?= htmlspecialchars($edit_form['nombre'] ?? '') ?>"
                               placeholder="Ej: Juan Pérez García" required>
                    </div>
                    <div class="field">
                        <label for="e_email">Correo electrónico <span class="required">*</span></label>
                        <input type="email" id="e_email" name="email"
                               value="<?= htmlspecialchars($edit_form['email'] ?? '') ?>"
                               placeholder="correo@ejemplo.com" required>
                    </div>
                    <div class="field">
                        <label for="e_documento">N° de documento <span class="required">*</span></label>
                        <input type="text" id="e_documento" name="documento"
                               value="<?= htmlspecialchars($edit_form['documento'] ?? '') ?>"
                               placeholder="Cédula o documento" required>
                    </div>
                    <div class="field">
                        <label for="e_telefono">Teléfono</label>
                        <input type="tel" id="e_telefono" name="telefono"
                               value="<?= htmlspecialchars($edit_form['telefono'] ?? '') ?>"
                               placeholder="Número de contacto">
                    </div>
                    <div class="field">
                        <label for="e_fecha_nacimiento">Fecha de nacimiento</label>
                        <input type="date" id="e_fecha_nacimiento" name="fecha_nacimiento"
                               value="<?= htmlspecialchars($edit_form['fecha_nacimiento'] ?? '') ?>">
                    </div>
                    <div class="field col-full">
                        <label for="e_direccion">Dirección</label>
                        <textarea id="e_direccion" name="direccion"
                                  placeholder="Dirección de residencia (opcional)"><?= htmlspecialchars($edit_form['direccion'] ?? '') ?></textarea>
                    </div>
                </div>
                </div>

                <div class="form-section-card">
                <p class="section-label">Rol y estado</p>
                <div class="form-grid">
                    <div class="field">
                        <label for="e_rol">Rol <span class="required">*</span></label>
                        <select id="e_rol" name="rol">
                            <option value="admin"      <?= ($edit_form['rol'] ?? '') === 'admin'      ? 'selected' : '' ?>>Administrador</option>
                            <option value="profesor"   <?= ($edit_form['rol'] ?? '') === 'profesor'   ? 'selected' : '' ?>>Profesor</option>
                            <option value="estudiante" <?= ($edit_form['rol'] ?? '') === 'estudiante' ? 'selected' : '' ?>>Estudiante</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="e_estado">Estado <span class="required">*</span></label>
                        <select id="e_estado" name="estado">
                            <option value="activo"     <?= ($edit_form['estado'] ?? '') === 'activo'     ? 'selected' : '' ?>>Activo</option>
                            <option value="inactivo"   <?= ($edit_form['estado'] ?? '') === 'inactivo'   ? 'selected' : '' ?>>Inactivo</option>
                            <option value="suspendido" <?= ($edit_form['estado'] ?? '') === 'suspendido' ? 'selected' : '' ?>>Suspendido</option>
                        </select>
                    </div>
                </div>
                <p class="info-notice" id="e_self_notice" style="display:none">
                    <span class="material-symbols-rounded">info</span>
                    No puedes cambiar el rol ni el estado de tu propia cuenta.
                </p>
                </div>

                <div class="form-section-card">
                <p class="section-label">
                    Cambiar contraseña
                    <span style="font-weight:400;text-transform:none;letter-spacing:0;font-size:.8rem;color:var(--text-secondary)">
                        — dejar vacío para mantener la actual
                    </span>
                </p>
                <div class="form-grid">
                    <div class="field">
                        <label for="e_new_password">Nueva contraseña</label>
                        <div class="input-wrap">
                            <input type="password" id="e_new_password" name="e_new_password"
                                   placeholder="Dejar vacío para no cambiar">
                            <button type="button" class="toggle-pass" onclick="togglePass('e_new_password', this)">
                                <span class="material-symbols-rounded">visibility</span>
                            </button>
                        </div>
                    </div>
                    <div class="field">
                        <label for="e_new_password_confirm">Confirmar contraseña</label>
                        <div class="input-wrap">
                            <input type="password" id="e_new_password_confirm" name="e_new_password_confirm"
                                   placeholder="Repite la nueva contraseña">
                            <button type="button" class="toggle-pass" onclick="togglePass('e_new_password_confirm', this)">
                                <span class="material-symbols-rounded">visibility</span>
                            </button>
                        </div>
                    </div>
                </div>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn-cancel" onclick="cerrarModalEditar()">Cancelar</button>
                    <button type="submit" class="btn-submit">
                        <span class="material-symbols-rounded">save</span> Guardar cambios
                    </button>
                </div>

            </form>
        </div>
    </div>
</div>

<script>
function toggleMenu(btn, e) {
    e.stopPropagation();
    document.querySelectorAll('.dropdown-menu').forEach(function(m) {
        if (m !== btn.nextElementSibling) m.classList.remove('show');
    });
    btn.nextElementSibling.classList.toggle('show');
}

window.addEventListener('click', function() {
    document.querySelectorAll('.dropdown-menu').forEach(function(m) {
        m.classList.remove('show');
    });
});

function abrirModal(id, nombre) {
    document.querySelectorAll('.dropdown-menu').forEach(function(m) { m.classList.remove('show'); });
    document.getElementById('modalNombre').textContent = nombre;
    document.getElementById('modalConfirmBtn').href = 'desactivar.php?id=' + id + '&action=desactivar';
    document.getElementById('modalDesactivar').classList.add('show');
}

function cerrarModal() {
    document.getElementById('modalDesactivar').classList.remove('show');
}

document.getElementById('modalDesactivar').addEventListener('click', function(e) {
    if (e.target === this) cerrarModal();
});

function abrirModalCrear() {
    const overlay = document.getElementById('modalCrearUsuario');
    const body    = overlay.querySelector('.modal-crear-body');
    overlay.scrollTop = 0;
    if (body) body.scrollTop = 0;
    overlay.classList.add('show');
    document.body.style.overflow = 'hidden';
    setTimeout(function() {
        document.getElementById('m_nombre').focus();
    }, 300);
}

function cerrarModalCrear() {
    const overlay = document.getElementById('modalCrearUsuario');
    overlay.classList.remove('show');
    document.body.style.overflow = '';
}

document.getElementById('modalCrearUsuario').addEventListener('click', function(e) {
    if (e.target === this) cerrarModalCrear();
});

<?php if ($modal_open): ?>
document.addEventListener('DOMContentLoaded', function() {
    abrirModalCrear();
    // Scroll al error dentro del modal
    const err = document.querySelector('#modalCrearUsuario .alert.alert-error');
    if (err) setTimeout(function() { err.scrollIntoView({ behavior: 'smooth', block: 'center' }); }, 350);
});
<?php endif; ?>

function abrirModalEditar(id, sessionUserId, data) {
    document.querySelectorAll('.dropdown-menu').forEach(function(m) { m.classList.remove('show'); });

    var isSelf = (id === sessionUserId);

    document.getElementById('e_edit_id').value          = id;
    document.getElementById('e_nombre').value           = data.nombre           || '';
    document.getElementById('e_email').value            = data.email            || '';
    document.getElementById('e_documento').value        = data.documento        || '';
    document.getElementById('e_telefono').value         = data.telefono         || '';
    document.getElementById('e_fecha_nacimiento').value = data.fecha_nacimiento || '';
    document.getElementById('e_direccion').value        = data.direccion        || '';
    document.getElementById('e_new_password').value         = '';
    document.getElementById('e_new_password_confirm').value = '';

    var rolSel    = document.getElementById('e_rol');
    var estadoSel = document.getElementById('e_estado');
    rolSel.value    = data.rol    || 'profesor';
    estadoSel.value = data.estado || 'activo';

    rolSel.disabled    = isSelf;
    estadoSel.disabled = isSelf;
    document.getElementById('e_self_notice').style.display = isSelf ? 'flex' : 'none';

    var initials   = (data.nombre || '??').substring(0, 2).toUpperCase();
    var avatarWrap = document.getElementById('e_avatar_wrap');
    avatarWrap.className = 'modal-editar-avatar-wrap avatar-' + (data.rol || '');
    if (data.foto_perfil) {
        avatarWrap.innerHTML =
            '<img src="../../assets/img/avatars/' + data.foto_perfil + '" alt=""' +
            ' onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'flex\';">' +
            '<div class="avatar-initials" style="display:none;">' + initials + '</div>';
    } else {
        avatarWrap.innerHTML = '<div class="avatar-initials">' + initials + '</div>';
    }

    document.getElementById('e_chip_nombre').textContent = data.nombre || '—';
    document.getElementById('e_subtitle').textContent    = 'Modifica los datos de ' + (data.nombre || 'este usuario');

    var badgeMap          = { admin: 'Administrador', profesor: 'Profesor', estudiante: 'Estudiante' };
    var estadoMap         = { activo: 'Activo', inactivo: 'Inactivo', suspendido: 'Suspendido' };
    var badgeRolClsMap    = { admin: 'badge-admin', profesor: 'badge-profesor', estudiante: 'badge-estudiante' };
    var badgeEstadoClsMap = { activo: 'badge-activo', inactivo: 'badge-inactivo', suspendido: 'badge-suspendido' };

    var badgeRolEl = document.getElementById('e_badge_rol');
    badgeRolEl.className   = 'badge ' + (badgeRolClsMap[data.rol] || '');
    badgeRolEl.textContent = badgeMap[data.rol] || data.rol || '—';

    var badgeEstadoEl = document.getElementById('e_badge_estado');
    badgeEstadoEl.className   = 'badge ' + (badgeEstadoClsMap[data.estado] || '');
    badgeEstadoEl.textContent = estadoMap[data.estado] || data.estado || '—';

    document.getElementById('e_fecha_registro').textContent =
        data.fecha_registro ? formatDate(data.fecha_registro) : '—';
    document.getElementById('e_ultima_conexion').textContent =
        data.ultima_conexion ? formatDateTime(data.ultima_conexion) : 'Nunca';

    var overlay = document.getElementById('modalEditarUsuario');
    var body    = overlay.querySelector('.modal-crear-body');
    overlay.scrollTop = 0;
    if (body) body.scrollTop = 0;
    overlay.classList.add('show');
    document.body.style.overflow = 'hidden';

    setTimeout(function() { document.getElementById('e_nombre').focus(); }, 300);
}

function cerrarModalEditar() {
    document.getElementById('modalEditarUsuario').classList.remove('show');
    document.body.style.overflow = '';
}

document.getElementById('modalEditarUsuario').addEventListener('click', function(e) {
    if (e.target === this) cerrarModalEditar();
});

function formatDate(str) {
    var d = new Date(str);
    if (isNaN(d)) return str;
    return d.toLocaleDateString('es-CO', { day: '2-digit', month: '2-digit', year: 'numeric' });
}
function formatDateTime(str) {
    var d = new Date(str);
    if (isNaN(d)) return str;
    return d.toLocaleDateString('es-CO', { day: '2-digit', month: '2-digit', year: 'numeric' }) + ' '
         + d.toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit' });
}

<?php if ($edit_open && $edit_target): ?>
document.addEventListener('DOMContentLoaded', function() {
    abrirModalEditar(
        <?= (int)$edit_target['id'] ?>,
        <?= (int)$_SESSION['user_id'] ?>,
        <?= json_encode([
            'nombre'           => $edit_form['nombre']           ?? $edit_target['nombre'],
            'email'            => $edit_form['email']            ?? $edit_target['email'],
            'documento'        => $edit_form['documento']        ?? $edit_target['documento'],
            'telefono'         => $edit_form['telefono']         ?? ($edit_target['telefono'] ?? ''),
            'direccion'        => $edit_form['direccion']        ?? ($edit_target['direccion'] ?? ''),
            'fecha_nacimiento' => $edit_form['fecha_nacimiento'] ?? ($edit_target['fecha_nacimiento'] ?? ''),
            'rol'              => $edit_form['rol']              ?? $edit_target['rol'],
            'estado'           => $edit_form['estado']           ?? $edit_target['estado'],
            'fecha_registro'   => $edit_target['fecha_registro'],
            'ultima_conexion'  => $edit_target['ultima_conexion'] ?? null,
            'foto_perfil'      => $edit_target['foto_perfil'] ?? '',
        ]) ?>
    );
    var err = document.querySelector('#modalEditarUsuario .alert.alert-error');
    if (err) setTimeout(function() { err.scrollIntoView({ behavior: 'smooth', block: 'center' }); }, 350);
});
<?php endif; ?>

document.getElementById('formCrearUsuario').addEventListener('submit', function(e) {
    const alertEl = document.getElementById('alertValidarCrear');
    const msgEl   = document.getElementById('msgValidarCrear');
    alertEl.style.display = 'none';
    this.querySelectorAll('.input-error').forEach(el => el.classList.remove('input-error'));

    const required = this.querySelectorAll('[required]');
    let firstEmpty = null;
    required.forEach(function(el) {
        if (!el.value.trim()) { el.classList.add('input-error'); if (!firstEmpty) firstEmpty = el; }
    });
    if (firstEmpty) {
        const label = firstEmpty.closest('.field')?.querySelector('label')?.textContent?.replace('*','').trim() ?? 'campo requerido';
        msgEl.textContent = 'El campo "' + label + '" es obligatorio.';
        alertEl.style.display = 'flex';
        firstEmpty.scrollIntoView({ behavior: 'smooth', block: 'center' });
        e.preventDefault(); return;
    }
    const pass  = document.getElementById('m_password').value;
    const pass2 = document.getElementById('m_password_confirm').value;
    if (pass.length < 6) {
        msgEl.textContent = 'La contraseña debe tener al menos 6 caracteres.';
        alertEl.style.display = 'flex';
        document.getElementById('m_password').classList.add('input-error');
        e.preventDefault(); return;
    }
    if (pass !== pass2) {
        msgEl.textContent = 'Las contraseñas no coinciden.';
        alertEl.style.display = 'flex';
        document.getElementById('m_password_confirm').classList.add('input-error');
        e.preventDefault();
    }
});
document.querySelectorAll('#formCrearUsuario [required]').forEach(function(el) {
    el.addEventListener('input', function() {
        if (this.value.trim()) {
            this.classList.remove('input-error');
            if (!document.querySelectorAll('#formCrearUsuario .input-error').length)
                document.getElementById('alertValidarCrear').style.display = 'none';
        }
    });
});

setTimeout(function() {
    var a = document.getElementById('alertFlash');
    if (a) { a.style.transition = 'opacity .5s'; a.style.opacity = '0'; setTimeout(function() { a.remove(); }, 500); }
}, 5000);

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        cerrarModal();
        cerrarModalCrear();
    }
});

function selectRole(radio) {
    document.querySelectorAll('.role-card').forEach(function(c) {
        c.classList.remove('selected-admin', 'selected-profesor');
    });
    radio.closest('.role-card').classList.add('selected-' + radio.value);
}

function togglePass(id, btn) {
    var inp  = document.getElementById(id);
    var icon = btn.querySelector('span');
    if (inp.type === 'password') {
        inp.type = 'text';
        icon.textContent = 'visibility_off';
    } else {
        inp.type = 'password';
        icon.textContent = 'visibility';
    }
}
</script>

</body>
</html>