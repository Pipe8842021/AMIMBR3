<?php
/**
 * Gestión de Usuarios – Crear (solo admin y profesor)
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

// ── Procesar formulario ───────────────────────────────────────────────────
$errors = [];
$form   = [
    'nombre'           => '',
    'email'            => '',
    'documento'        => '',
    'telefono'         => '',
    'direccion'        => '',
    'fecha_nacimiento' => '',
    'rol'              => 'profesor',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    foreach (['nombre', 'email', 'documento', 'telefono', 'direccion', 'fecha_nacimiento', 'rol'] as $f) {
        $form[$f] = trim($_POST[$f] ?? '');
    }
    $pass  = $_POST['password']         ?? '';
    $pass2 = $_POST['password_confirm'] ?? '';

    if (empty($form['nombre']))    $errors[] = 'El nombre es obligatorio.';
    if (empty($form['email']))     $errors[] = 'El correo es obligatorio.';
    elseif (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'El correo no tiene un formato válido.';
    if (empty($form['documento'])) $errors[] = 'El documento de identidad es obligatorio.';
    if (empty($pass))              $errors[] = 'La contraseña es obligatoria.';
    elseif (strlen($pass) < 6)    $errors[] = 'La contraseña debe tener al menos 6 caracteres.';
    elseif ($pass !== $pass2)      $errors[] = 'Las contraseñas no coinciden.';

    if (!in_array($form['rol'], ['admin', 'profesor'])) {
        $errors[] = 'Rol no válido. Solo se puede asignar Administrador o Profesor.';
    }

    if (empty($errors)) {
        try {
            $ck = $pdo->prepare("SELECT id FROM usuarios WHERE email = ? OR documento = ?");
            $ck->execute([$form['email'], $form['documento']]);
            if ($ck->rowCount() > 0) $errors[] = 'Ya existe un usuario con ese correo o documento.';
        } catch (PDOException $e) {
            $errors[] = 'Error al verificar los datos. Intenta de nuevo.';
        }
    }

    if (empty($errors)) {
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
            $errors[] = 'No se pudo crear el usuario. Intenta nuevamente.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crear Usuario – Amimbré</title>
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
                <h1>Crear Usuario</h1>
                <p>Registra un nuevo administrador o profesor en el sistema</p>
            </div>
        </div>
    </div>

    <div class="form-card">
        <div class="form-card-header">
            <span class="material-symbols-rounded">person_add</span>
            <h2>Información del nuevo usuario</h2>
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

                <!-- Selector de rol -->
                <div class="field" style="margin-bottom:20px">
                    <label>Rol del usuario <span class="required">*</span></label>
                    <div class="role-cards">
                        <label class="role-card <?= $form['rol'] === 'admin' ? 'selected-admin' : '' ?>" id="card-admin">
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
                        <label class="role-card <?= $form['rol'] === 'profesor' ? 'selected-profesor' : '' ?>" id="card-profesor">
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
                        <label for="nombre">Nombre completo <span class="required">*</span></label>
                        <input type="text" id="nombre" name="nombre"
                               value="<?= htmlspecialchars($form['nombre']) ?>"
                               placeholder="Ej: Juan Pérez García" required>
                    </div>
                    <div class="field">
                        <label for="email">Correo electrónico <span class="required">*</span></label>
                        <input type="email" id="email" name="email"
                               value="<?= htmlspecialchars($form['email']) ?>"
                               placeholder="correo@ejemplo.com" required>
                    </div>
                    <div class="field">
                        <label for="documento">N° de documento <span class="required">*</span></label>
                        <input type="text" id="documento" name="documento"
                               value="<?= htmlspecialchars($form['documento']) ?>"
                               placeholder="Cédula o documento" required>
                    </div>
                    <div class="field">
                        <label for="telefono">Teléfono</label>
                        <input type="tel" id="telefono" name="telefono"
                               value="<?= htmlspecialchars($form['telefono']) ?>"
                               placeholder="Número de contacto">
                    </div>
                    <div class="field">
                        <label for="fecha_nacimiento">Fecha de nacimiento</label>
                        <input type="date" id="fecha_nacimiento" name="fecha_nacimiento"
                               value="<?= htmlspecialchars($form['fecha_nacimiento']) ?>">
                    </div>
                    <div class="field col-full">
                        <label for="direccion">Dirección</label>
                        <textarea id="direccion" name="direccion"
                                  placeholder="Dirección de residencia (opcional)"><?= htmlspecialchars($form['direccion']) ?></textarea>
                    </div>
                </div>

                <hr class="divider">
                <p class="section-label">Contraseña</p>

                <div class="form-grid">
                    <div class="field">
                        <label for="password">Contraseña <span class="required">*</span></label>
                        <div class="input-wrap">
                            <input type="password" id="password" name="password"
                                   placeholder="Mínimo 6 caracteres" required>
                            <button type="button" class="toggle-pass" onclick="togglePass('password', this)">
                                <span class="material-symbols-rounded">visibility</span>
                            </button>
                        </div>
                    </div>
                    <div class="field">
                        <label for="password_confirm">Confirmar contraseña <span class="required">*</span></label>
                        <div class="input-wrap">
                            <input type="password" id="password_confirm" name="password_confirm"
                                   placeholder="Repite la contraseña" required>
                            <button type="button" class="toggle-pass" onclick="togglePass('password_confirm', this)">
                                <span class="material-symbols-rounded">visibility</span>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <a href="index.php" class="btn-cancel">Cancelar</a>
                    <button type="submit" class="btn-submit">
                        <span class="material-symbols-rounded">person_add</span> Crear usuario
                    </button>
                </div>

            </form>
        </div>
    </div>

</main>

<script>
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