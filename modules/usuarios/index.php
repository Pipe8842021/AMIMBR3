<?php
/**
 * Gestión de Usuarios – Vista Principal
 */

require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth_check.php';

require_role('admin');

// ── Usuario actual ────────────────────────────────────────────────────────
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

// ── Flash ─────────────────────────────────────────────────────────────────
$flash = null;
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

// ── Filtros ───────────────────────────────────────────────────────────────
$filtro_rol    = $_GET['rol']      ?? '';
$filtro_estado = $_GET['estado']   ?? '';
$busqueda      = $_GET['busqueda'] ?? '';

// ── Stats ─────────────────────────────────────────────────────────────────
try {
    $total_usuarios = (int) $pdo->query("SELECT COUNT(*) FROM usuarios")->fetchColumn();
} catch (PDOException $e) {
    $total_usuarios = 0;
}

// ── Consulta con filtros ──────────────────────────────────────────────────
try {
    $sql    = "SELECT * FROM usuarios WHERE 1=1";
    $params = [];

    if ($filtro_rol) {
        $sql     .= " AND rol = ?";
        $params[] = $filtro_rol;
    }
    if ($filtro_estado) {
        $sql     .= " AND estado = ?";
        $params[] = $filtro_estado;
    }
    if ($busqueda) {
        $sql     .= " AND (nombre LIKE ? OR email LIKE ? OR documento LIKE ?)";
        $s        = "%$busqueda%";
        array_push($params, $s, $s, $s);
    }

    $sql .= " ORDER BY fecha_registro DESC";
    $stmt  = $pdo->prepare($sql);
    $stmt->execute($params);
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log($e->getMessage());
    $usuarios = [];
}

// ── Helpers ───────────────────────────────────────────────────────────────
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

    <!-- Flash -->
    <?php if ($flash): ?>
    <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>">
        <span class="material-symbols-rounded"><?= $flash['type'] === 'success' ? 'check_circle' : 'error' ?></span>
        <?= htmlspecialchars($flash['message']) ?>
    </div>
    <?php endif; ?>

    <!-- Page header -->
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
        <a href="crear.php" class="btn-primary">
            <span class="material-symbols-rounded">person_add</span> Nuevo Usuario
        </a>
    </div>

    <!-- Stat card -->
    <div class="stat-card">
        <div class="stat-icon total">
            <span class="material-symbols-rounded">group</span>
        </div>
        <div class="stat-info">
            <span class="stat-label">Total Usuarios</span>
            <span class="stat-value"><?= $total_usuarios ?></span>
        </div>
    </div>

    <!-- Filtros -->
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

    <!-- Tabla -->
    <div class="users-card">
        <div class="users-card-header">
            <h3>Lista de usuarios</h3>
            <span class="result-count"><?= count($usuarios) ?> resultado<?= count($usuarios) !== 1 ? 's' : '' ?></span>
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
                                    <a href="editar.php?id=<?= $u['id'] ?>" class="dropdown-item">
                                        <span class="material-symbols-rounded">edit</span> Editar
                                    </a>
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

</main>

<!-- Modal desactivar -->
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

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') cerrarModal();
});
</script>

</body>
</html>