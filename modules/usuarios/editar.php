<?php
/**
 * Editar Usuario - Formulario de Edición
 * Permite modificar la información de usuarios existentes
 */

// Incluir configuración de sesión y base de datos
require_once '../../config/session.php';
require_once '../../config/database.php';

// Verificar autenticación
require_once '../../includes/auth_check.php';

// helper de notificaciones
require_once '../../includes/notificaciones_helper.php';

// Verificar que sea administrador
require_role('admin');

// Obtener ID del usuario a editar
$usuario_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$usuario_id) {
    header("Location: index.php");
    exit;
}

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

// Obtener datos del usuario a editar
try {
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = ?");
    $stmt->execute([$usuario_id]);
    $usuario_editar = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$usuario_editar) {
        header("Location: index.php");
        exit;
    }
} catch (PDOException $e) {
    error_log("Error obteniendo usuario: " . $e->getMessage());
    die("Error del sistema.");
}

// Variables para el formulario
$errors = [];
$form_data = $usuario_editar;

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validar y sanitizar datos
    $form_data = [
        'nombre' => trim($_POST['nombre'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'telefono' => trim($_POST['telefono'] ?? ''),
        'documento' => trim($_POST['documento'] ?? ''),
        'rol' => $_POST['rol'] ?? '',
        'estado' => $_POST['estado'] ?? 'activo',
        'password' => $_POST['password'] ?? '',
        'password_confirm' => $_POST['password_confirm'] ?? ''
    ];
    
    // Validaciones
    if (empty($form_data['nombre'])) {
        $errors['nombre'] = 'El nombre es obligatorio';
    } elseif (strlen($form_data['nombre']) < 3) {
        $errors['nombre'] = 'El nombre debe tener al menos 3 caracteres';
    }
    
    if (empty($form_data['email'])) {
        $errors['email'] = 'El email es obligatorio';
    } elseif (!filter_var($form_data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'El email no es válido';
    } else {
        // Verificar email único (excluyendo el usuario actual)
        try {
            $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ? AND id != ?");
            $stmt->execute([$form_data['email'], $usuario_id]);
            if ($stmt->fetch()) {
                $errors['email'] = 'Este email ya está registrado';
            }
        } catch (PDOException $e) {
            error_log("Error verificando email: " . $e->getMessage());
        }
    }
    
    if (empty($form_data['documento'])) {
        $errors['documento'] = 'El documento es obligatorio';
    } else {
        // Verificar documento único (excluyendo el usuario actual)
        try {
            $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE documento = ? AND id != ?");
            $stmt->execute([$form_data['documento'], $usuario_id]);
            if ($stmt->fetch()) {
                $errors['documento'] = 'Este documento ya está registrado';
            }
        } catch (PDOException $e) {
            error_log("Error verificando documento: " . $e->getMessage());
        }
    }
    
    if (!empty($form_data['telefono']) && !preg_match('/^[0-9+\-\s()]+$/', $form_data['telefono'])) {
        $errors['telefono'] = 'El teléfono no es válido';
    }
    
    if (empty($form_data['rol']) || !in_array($form_data['rol'], ['admin', 'profesor', 'estudiante'])) {
        $errors['rol'] = 'Debe seleccionar un rol válido';
    }
    
    if (!in_array($form_data['estado'], ['activo', 'inactivo'])) {
        $errors['estado'] = 'Estado no válido';
    }
    
    // Validar contraseña solo si se ingresó una nueva
    if (!empty($form_data['password'])) {
        if (strlen($form_data['password']) < 6) {
            $errors['password'] = 'La contraseña debe tener al menos 6 caracteres';
        } elseif ($form_data['password'] !== $form_data['password_confirm']) {
            $errors['password_confirm'] = 'Las contraseñas no coinciden';
        }
    }
    
    // Si no hay errores, actualizar usuario
    if (empty($errors)) {
        try {
            // Construir consulta según si se cambió la contraseña
            if (!empty($form_data['password'])) {
                $sql = "
                    UPDATE usuarios 
                    SET nombre = ?, email = ?, telefono = ?, documento = ?, 
                        rol = ?, estado = ?, password = ?
                    WHERE id = ?
                ";
                $params = [
                    $form_data['nombre'],
                    $form_data['email'],
                    $form_data['telefono'],
                    $form_data['documento'],
                    $form_data['rol'],
                    $form_data['estado'],
                    password_hash($form_data['password'], PASSWORD_DEFAULT),
                    $usuario_id
                ];
            } else {
                $sql = "
                    UPDATE usuarios 
                    SET nombre = ?, email = ?, telefono = ?, documento = ?, 
                        rol = ?, estado = ?
                    WHERE id = ?
                ";
                $params = [
                    $form_data['nombre'],
                    $form_data['email'],
                    $form_data['telefono'],
                    $form_data['documento'],
                    $form_data['rol'],
                    $form_data['estado'],
                    $usuario_id
                ];
            }
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            // Registrar en log de actividad
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO logs_actividad (usuario_id, accion, descripcion, fecha)
                    VALUES (?, 'editar_usuario', ?, NOW())
                ");
                $stmt->execute([
                    $_SESSION['user_id'],
                    "Usuario '{$form_data['nombre']}' (ID: {$usuario_id}) actualizado"
                ]);
            } catch (PDOException $e) {
                error_log("Error registrando log: " . $e->getMessage());
            }

            // ── NUEVO: Notificación automática 
            NotificacionesHelper::usuarioEditado(
                $pdo,
                $form_data['nombre'],
                $user['nombre']
            );
            
            // Redireccionar con mensaje de éxito
            if (function_exists('set_flash_message')) {
                set_flash_message('Usuario actualizado exitosamente', 'success');
            }
            header("Location: index.php");
            exit;
            
        } catch (PDOException $e) {
            error_log("Error actualizando usuario: " . $e->getMessage());
            $errors['general'] = 'Error al actualizar el usuario. Por favor, intenta de nuevo.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Usuario - Amimbré</title>
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
                <button class="back-button" onclick="window.location.href='index.php'">
                    <span class="material-symbols-rounded">arrow_back</span>
                </button>
                <div class="header-title">
                    <h1>Editar Usuario</h1>
                    <p>Modifica la información del usuario</p>
                </div>
            </div>
        </div>

        <?php if (isset($errors['general'])): ?>
        <div class="alert alert-error">
            <span class="material-symbols-rounded">error</span>
            <?php echo htmlspecialchars($errors['general']); ?>
        </div>
        <?php endif; ?>

        <!-- Form Card -->
        <div class="form-card">
            <form method="POST" autocomplete="off">
                <div style="display: flex; align-items: center; gap: 16px; margin-bottom: 32px; padding-bottom: 20px; border-bottom: 1px solid var(--border-color);">
                    <div class="user-avatar" style="width: 60px; height: 60px;">
                        <?php if ($usuario_editar['foto_perfil']): ?>
                            <img src="<?php echo htmlspecialchars($usuario_editar['foto_perfil']); ?>" alt="Avatar">
                        <?php else: ?>
                            <span class="avatar-initials" style="font-size: 1.2rem;">
                                <?php echo strtoupper(substr($usuario_editar['nombre'], 0, 2)); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <h3 style="color: var(--text-primary); font-size: 1.3rem; margin-bottom: 4px;">
                            <?php echo htmlspecialchars($usuario_editar['nombre']); ?>
                        </h3>
                        <p style="color: var(--text-secondary); font-size: 0.9rem;">
                            ID: <?php echo $usuario_id; ?> • 
                            Registrado: <?php echo date('d/m/Y', strtotime($usuario_editar['fecha_registro'])); ?>
                        </p>
                    </div>
                </div>

                <h3 style="color: var(--text-primary); margin-bottom: 24px; font-size: 1.2rem;">
                    <span class="material-symbols-rounded" style="vertical-align: middle; margin-right: 8px;">edit</span>
                    Información del Usuario
                </h3>

                <div class="form-row">
                    <div class="form-group">
                        <label for="nombre">
                            Nombre Completo
                            <span class="required">*</span>
                        </label>
                        <input 
                            type="text" 
                            id="nombre" 
                            name="nombre" 
                            class="form-input <?php echo isset($errors['nombre']) ? 'error' : ''; ?>"
                            value="<?php echo htmlspecialchars($form_data['nombre'] ?? ''); ?>"
                            required
                        >
                        <?php if (isset($errors['nombre'])): ?>
                            <span class="form-error"><?php echo $errors['nombre']; ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="documento">
                            Documento de Identidad
                            <span class="required">*</span>
                        </label>
                        <input 
                            type="text" 
                            id="documento" 
                            name="documento" 
                            class="form-input <?php echo isset($errors['documento']) ? 'error' : ''; ?>"
                            value="<?php echo htmlspecialchars($form_data['documento'] ?? ''); ?>"
                            required
                        >
                        <?php if (isset($errors['documento'])): ?>
                            <span class="form-error"><?php echo $errors['documento']; ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="email">
                            Email
                            <span class="required">*</span>
                        </label>
                        <input 
                            type="email" 
                            id="email" 
                            name="email" 
                            class="form-input <?php echo isset($errors['email']) ? 'error' : ''; ?>"
                            value="<?php echo htmlspecialchars($form_data['email'] ?? ''); ?>"
                            required
                        >
                        <?php if (isset($errors['email'])): ?>
                            <span class="form-error"><?php echo $errors['email']; ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="telefono">Teléfono</label>
                        <input 
                            type="tel" 
                            id="telefono" 
                            name="telefono" 
                            class="form-input <?php echo isset($errors['telefono']) ? 'error' : ''; ?>"
                            value="<?php echo htmlspecialchars($form_data['telefono'] ?? ''); ?>"
                        >
                        <?php if (isset($errors['telefono'])): ?>
                            <span class="form-error"><?php echo $errors['telefono']; ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="rol">
                            Rol del Usuario
                            <span class="required">*</span>
                        </label>
                        <select 
                            id="rol" 
                            name="rol" 
                            class="form-select <?php echo isset($errors['rol']) ? 'error' : ''; ?>"
                            required
                        >
                            <option value="admin" <?php echo ($form_data['rol'] ?? '') === 'admin' ? 'selected' : ''; ?>>
                                Administrador
                            </option>
                            <option value="profesor" <?php echo ($form_data['rol'] ?? '') === 'profesor' ? 'selected' : ''; ?>>
                                Profesor
                            </option>
                            <option value="estudiante" <?php echo ($form_data['rol'] ?? '') === 'estudiante' ? 'selected' : ''; ?>>
                                Estudiante
                            </option>
                        </select>
                        <?php if (isset($errors['rol'])): ?>
                            <span class="form-error"><?php echo $errors['rol']; ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="estado">
                            Estado
                            <span class="required">*</span>
                        </label>
                        <select 
                            id="estado" 
                            name="estado" 
                            class="form-select <?php echo isset($errors['estado']) ? 'error' : ''; ?>"
                            required
                        >
                            <option value="activo" <?php echo ($form_data['estado'] ?? '') === 'activo' ? 'selected' : ''; ?>>
                                Activo
                            </option>
                            <option value="inactivo" <?php echo ($form_data['estado'] ?? '') === 'inactivo' ? 'selected' : ''; ?>>
                                Inactivo
                            </option>
                        </select>
                        <?php if (isset($errors['estado'])): ?>
                            <span class="form-error"><?php echo $errors['estado']; ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <h3 style="color: var(--text-primary); margin: 32px 0 24px; font-size: 1.2rem; padding-top: 24px; border-top: 1px solid var(--border-color);">
                    <span class="material-symbols-rounded" style="vertical-align: middle; margin-right: 8px;">lock</span>
                    Cambiar Contraseña (Opcional)
                </h3>
                <p style="color: var(--text-secondary); font-size: 0.9rem; margin-bottom: 20px;">
                    Deja estos campos en blanco si no deseas cambiar la contraseña
                </p>

                <div class="form-row">
                    <div class="form-group">
                        <label for="password">Nueva Contraseña</label>
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            class="form-input <?php echo isset($errors['password']) ? 'error' : ''; ?>"
                            autocomplete="new-password"
                        >
                        <?php if (isset($errors['password'])): ?>
                            <span class="form-error"><?php echo $errors['password']; ?></span>
                        <?php else: ?>
                            <span class="form-help">Mínimo 6 caracteres</span>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="password_confirm">Confirmar Nueva Contraseña</label>
                        <input 
                            type="password" 
                            id="password_confirm" 
                            name="password_confirm" 
                            class="form-input <?php echo isset($errors['password_confirm']) ? 'error' : ''; ?>"
                            autocomplete="new-password"
                        >
                        <?php if (isset($errors['password_confirm'])): ?>
                            <span class="form-error"><?php echo $errors['password_confirm']; ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn-submit">
                        <span class="material-symbols-rounded">save</span>
                        Guardar Cambios
                    </button>
                    <a href="index.php" class="btn-cancel">
                        <span class="material-symbols-rounded">close</span>
                        Cancelar
                    </a>
                </div>
            </form>
        </div>
    </main>

    <script>
        // Password match validation
        const passwordInput = document.getElementById('password');
        const confirmInput = document.getElementById('password_confirm');

        confirmInput.addEventListener('input', function() {
            if (this.value && passwordInput.value && passwordInput.value !== this.value) {
                this.style.borderColor = '#f44';
            } else {
                this.style.borderColor = '';
            }
        });

        // Clear confirm if password is cleared
        passwordInput.addEventListener('input', function() {
            if (!this.value) {
                confirmInput.value = '';
            }
        });
    </script>
</body>
</html>