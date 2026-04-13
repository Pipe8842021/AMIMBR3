<?php
/**
 * Gestión de Usuarios – Editar
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

// ── Cargar usuario a editar ───────────────────────────────────────────────
$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    header("Location: index.php");
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = ?");
    $stmt->execute([$id]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $target = null;
}

if (!$target) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Usuario no encontrado.'];
    header("Location: index.php");
    exit;
}

// ── Procesar formulario ───────────────────────────────────────────────────
$errors = [];
$form   = [
    'nombre'           => $target['nombre'],
    'email'            => $target['email'],
    'documento'        => $target['documento'],
    'telefono'         => $target['telefono']          ?? '',
    'direccion'        => $target['direccion']          ?? '',
    'fecha_nacimiento' => $target['fecha_nacimiento']   ?? '',
    'rol'              => $target['rol'],
    'estado'           => $target['estado'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    foreach (['nombre', 'email', 'documento', 'telefono', 'direccion', 'fecha_nacimiento', 'rol', 'estado'] as $f) {
        $form[$f] = trim($_POST[$f] ?? '');
    }
    $newpass  = $_POST['new_password']         ?? '';
    $newpass2 = $_POST['new_password_confirm'] ?? '';

    if (empty($form['nombre']))    $errors[] = 'El nombre es obligatorio.';
    if (empty($form['email']))     $errors[] = 'El correo es obligatorio.';
    elseif (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'El correo no tiene un formato válido.';
    if (empty($form['documento'])) $errors[] = 'El documento es obligatorio.';
    if (!in_array($form['rol'],    ['admin', 'profesor', 'estudiante'])) $errors[] = 'Rol no válido.';
    if (!in_array($form['estado'], ['activo', 'inactivo', 'suspendido'])) $errors[] = 'Estado no válido.';

    if ($id === (int)$_SESSION['user_id'] && $form['rol'] !== 'admin') {
        $errors[] = 'No puedes cambiar tu propio rol de administrador.';
    }
    if ($id === (int)$_SESSION['user_id'] && $form['estado'] !== 'activo') {
        $errors[] = 'No puedes desactivar tu propia cuenta.';
    }

    if (!empty($newpass)) {
        if (strlen($newpass) < 6)       $errors[] = 'La nueva contraseña debe tener al menos 6 caracteres.';
        elseif ($newpass !== $newpass2) $errors[] = 'Las contraseñas no coinciden.';
    }

    if (empty($errors)) {
        try {
            $ck = $pdo->prepare("SELECT id FROM usuarios WHERE (email = ? OR documento = ?) AND id != ?");
            $ck->execute([$form['email'], $form['documento'], $id]);
            if ($ck->rowCount() > 0) $errors[] = 'Ya existe otro usuario con ese correo o documento.';
        } catch (PDOException $e) {
            $errors[] = 'Error al verificar los datos.';
        }
    }

    if (empty($errors)) {
        try {
            if (!empty($newpass)) {
                $pdo->prepare("
                    UPDATE usuarios
                    SET nombre=?, email=?, password=?, documento=?, telefono=?,
                        direccion=?, fecha_nacimiento=?, rol=?, estado=?
                    WHERE id=?
                ")->execute([
                    $form['nombre'], $form['email'],
                    password_hash($newpass, PASSWORD_BCRYPT),
                    $form['documento'],
                    $form['telefono']         ?: null,
                    $form['direccion']        ?: null,
                    $form['fecha_nacimiento'] ?: null,
                    $form['rol'], $form['estado'], $id,
                ]);
            } else {
                $pdo->prepare("
                    UPDATE usuarios
                    SET nombre=?, email=?, documento=?, telefono=?,
                        direccion=?, fecha_nacimiento=?, rol=?, estado=?
                    WHERE id=?
                ")->execute([
                    $form['nombre'], $form['email'], $form['documento'],
                    $form['telefono']         ?: null,
                    $form['direccion']        ?: null,
                    $form['fecha_nacimiento'] ?: null,
                    $form['rol'], $form['estado'], $id,
                ]);
            }

            $_SESSION['flash'] = [
                'type'    => 'success',
                'message' => 'Usuario "' . $form['nombre'] . '" actualizado correctamente.',
            ];
            header("Location: index.php");
            exit;

        } catch (PDOException $e) {
            error_log($e->getMessage());
            $errors[] = 'No se pudo actualizar el usuario. Intenta nuevamente.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Usuario – Amimbré</title>
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

    <!-- Page header -->
    <div class="page-header">
        <div class="header-left">
            <a href="index.php" class="back-button">
                <span class="material-symbols-rounded">arrow_back</span>
            </a>
            <div class="header-title">
                <h1>Editar Usuario</h1>
                <p>Modifica los datos del usuario seleccionado</p>
            </div>
        </div>
        <div class="user-chip">
            <div class="chip-avatar <?= htmlspecialchars($target['rol']) ?>">
                <?= strtoupper(mb_substr($target['nombre'], 0, 2)) ?>
            </div>
            <div class="chip-info">
                <strong><?= htmlspecialchars($target['nombre']) ?></strong>
                <span><?= ucfirst($target['rol']) ?></span>
            </div>
        </div>
    </div>

    <div class="edit-layout">

        <!-- Formulario principal -->
        <div class="form-card" style="max-width:100%">
            <div class="form-card-header">
                <span class="material-symbols-rounded">manage_accounts</span>
                <h2>Datos del usuario</h2>
            </div>
            <div class="form-body">

                <?php if (!empty($errors)): ?>
                <div class="alert-form-errors">
                    <p>Por favor corrige los siguientes errores:</p>
                    <ul>
                        <?php foreach ($errors as $e): ?>
                            <li><?= htmlspecialchars($e) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <form method="POST">

                    <p class="section-label">Información personal</p>
                    <div class="form-grid">
                        <div class="field col-full">
                            <label for="nombre">Nombre completo <span class="required">*</span></label>
                            <input type="text" id="nombre" name="nombre"
                                   value="<?= htmlspecialchars($form['nombre']) ?>" required>
                        </div>
                        <div class="field">
                            <label for="email">Correo electrónico <span class="required">*</span></label>
                            <input type="email" id="email" name="email"
                                   value="<?= htmlspecialchars($form['email']) ?>" required>
                        </div>
                        <div class="field">
                            <label for="documento">N° de documento <span class="required">*</span></label>
                            <input type="text" id="documento" name="documento"
                                   value="<?= htmlspecialchars($form['documento']) ?>" required>
                        </div>
                        <div class="field">
                            <label for="telefono">Teléfono</label>
                            <input type="tel" id="telefono" name="telefono"
                                   value="<?= htmlspecialchars($form['telefono']) ?>">
                        </div>
                        <div class="field">
                            <label for="fecha_nacimiento">Fecha de nacimiento</label>
                            <input type="date" id="fecha_nacimiento" name="fecha_nacimiento"
                                   value="<?= htmlspecialchars($form['fecha_nacimiento']) ?>">
                        </div>
                        <div class="field col-full">
                            <label for="direccion">Dirección</label>
                            <textarea id="direccion" name="direccion"><?= htmlspecialchars($form['direccion']) ?></textarea>
                        </div>
                    </div>

                    <hr class="divider">
                    <p class="section-label">Rol y estado</p>
                    <div class="form-grid">
                        <div class="field">
                            <label for="rol">Rol <span class="required">*</span></label>
                            <select id="rol" name="rol"
                                    <?= $id === (int)$_SESSION['user_id'] ? 'disabled' : '' ?>>
                                <option value="admin"      <?= $form['rol'] === 'admin'      ? 'selected' : '' ?>>Administrador</option>
                                <option value="profesor"   <?= $form['rol'] === 'profesor'   ? 'selected' : '' ?>>Profesor</option>
                                <option value="estudiante" <?= $form['rol'] === 'estudiante' ? 'selected' : '' ?>>Estudiante</option>
                            </select>
                            <?php if ($id === (int)$_SESSION['user_id']): ?>
                                <input type="hidden" name="rol" value="<?= htmlspecialchars($form['rol']) ?>">
                            <?php endif; ?>
                        </div>
                        <div class="field">
                            <label for="estado">Estado <span class="required">*</span></label>
                            <select id="estado" name="estado"
                                    <?= $id === (int)$_SESSION['user_id'] ? 'disabled' : '' ?>>
                                <option value="activo"     <?= $form['estado'] === 'activo'     ? 'selected' : '' ?>>Activo</option>
                                <option value="inactivo"   <?= $form['estado'] === 'inactivo'   ? 'selected' : '' ?>>Inactivo</option>
                                <option value="suspendido" <?= $form['estado'] === 'suspendido' ? 'selected' : '' ?>>Suspendido</option>
                            </select>
                            <?php if ($id === (int)$_SESSION['user_id']): ?>
                                <input type="hidden" name="estado" value="<?= htmlspecialchars($form['estado']) ?>">
                            <?php endif; ?>
                        </div>
                    </div>

                    <hr class="divider">
                    <p class="section-label">
                        Cambiar contraseña
                        <span style="font-weight:400;text-transform:none;letter-spacing:0;font-size:.8rem;color:var(--text-secondary)">
                            — dejar vacío para mantener la actual
                        </span>
                    </p>
                    <div class="form-grid">
                        <div class="field">
                            <label for="new_password">Nueva contraseña</label>
                            <div class="input-wrap">
                                <input type="password" id="new_password" name="new_password"
                                       placeholder="Dejar vacío para no cambiar">
                                <button type="button" class="toggle-pass" onclick="togglePass('new_password', this)">
                                    <span class="material-symbols-rounded">visibility</span>
                                </button>
                            </div>
                        </div>
                        <div class="field">
                            <label for="new_password_confirm">Confirmar contraseña</label>
                            <div class="input-wrap">
                                <input type="password" id="new_password_confirm" name="new_password_confirm"
                                       placeholder="Repite la nueva contraseña">
                                <button type="button" class="toggle-pass" onclick="togglePass('new_password_confirm', this)">
                                    <span class="material-symbols-rounded">visibility</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <a href="index.php" class="btn-cancel">Cancelar</a>
                        <button type="submit" class="btn-submit">
                            <span class="material-symbols-rounded">save</span> Guardar cambios
                        </button>
                    </div>

                </form>
            </div>
        </div>

        <!-- Sidebar derecho -->
        <div>
            <!-- Estado actual -->
            <div class="side-card">
                <h3>Estado actual</h3>
                <div class="status-badge <?= htmlspecialchars($target['estado']) ?>">
                    <div class="status-dot"></div>
                    <?= ucfirst($target['estado']) ?>
                </div>

                <?php if ($id !== (int)$_SESSION['user_id']): ?>
                <div style="margin-top:14px">
                    <?php if ($target['estado'] === 'activo'): ?>
                    <button class="btn-danger" style="width:100%;font-size:.85rem;padding:11px"
                            onclick="document.getElementById('modalDesactivar').classList.add('show')">
                        <span class="material-symbols-rounded">person_off</span> Desactivar usuario
                    </button>
                    <?php else: ?>
                    <a href="desactivar.php?id=<?= $id ?>&action=activar"
                       class="btn-submit" style="width:100%;box-sizing:border-box;padding:11px;font-size:.85rem;background:var(--gradient-primary-green)"
                       onclick="return confirm('¿Activar a <?= htmlspecialchars(addslashes($target['nombre'])) ?>?')">
                        <span class="material-symbols-rounded">person_check</span> Activar usuario
                    </a>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <p style="font-size:.75rem;color:var(--text-secondary);margin-top:10px;
                           background:var(--subtle-orange);border:1px solid var(--primary-orange);
                           border-radius:8px;padding:8px 12px">
                    No puedes modificar el estado de tu propia cuenta.
                </p>
                <?php endif; ?>
            </div>

            <!-- Info adicional -->
            <div class="side-card">
                <h3>Información adicional</h3>
                <div class="info-row">
                    <span class="label">ID sistema</span>
                    <span class="value">#<?= $target['id'] ?></span>
                </div>
                <div class="info-row">
                    <span class="label">Registro</span>
                    <span class="value"><?= date('d/m/Y', strtotime($target['fecha_registro'])) ?></span>
                </div>
                <div class="info-row">
                    <span class="label">Última sesión</span>
                    <span class="value">
                        <?= $target['ultima_conexion']
                            ? date('d/m/Y H:i', strtotime($target['ultima_conexion']))
                            : 'Nunca' ?>
                    </span>
                </div>
            </div>
        </div>

    </div><!-- /edit-layout -->

</main>

<!-- Modal desactivar -->
<div class="modal-overlay" id="modalDesactivar">
    <div class="modal">
        <div class="modal-icon">
            <span class="material-symbols-rounded">person_off</span>
        </div>
        <p class="modal-title">¿Desactivar usuario?</p>
        <p class="modal-body">
            Estás a punto de desactivar a <strong><?= htmlspecialchars($target['nombre']) ?></strong>.<br>
            No podrá iniciar sesión hasta que sea reactivado.
        </p>
        <div class="modal-actions">
            <button class="btn-cancel" onclick="document.getElementById('modalDesactivar').classList.remove('show')">
                Cancelar
            </button>
            <a href="desactivar.php?id=<?= $id ?>&action=desactivar" class="btn-danger">
                <span class="material-symbols-rounded">person_off</span> Sí, desactivar
            </a>
        </div>
    </div>
</div>

<script>
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

document.getElementById('modalDesactivar').addEventListener('click', function(e) {
    if (e.target === this) this.classList.remove('show');
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') document.getElementById('modalDesactivar').classList.remove('show');
});
</script>

</body>
</html>