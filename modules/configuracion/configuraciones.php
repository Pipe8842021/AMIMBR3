<?php
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth_check.php';

$user_id  = $_SESSION['user_id'];
$user_rol = $_SESSION['user_rol'];

$mensaje_feedback = "";
$tipo_feedback    = "";

// --- 1. ACTUALIZAR PERFIL (nombre, email, password, foto) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {
    $nombre = trim($_POST['nombre']);
    $email  = trim($_POST['email']);
    $password = $_POST['password'] ?? '';

    // Procesar foto de perfil recortada (base64 1:1)
    $foto_cropped = $_POST['foto_cropped'] ?? '';
    $foto_ext     = strtolower(trim($_POST['foto_ext'] ?? 'jpg'));
    $foto_campo   = null; // null = no cambiar

    if (!empty($foto_cropped) && strpos($foto_cropped, 'data:image/') === 0) {
        $allowed_ext = ['jpg', 'jpeg', 'png', 'webp'];
        if (!in_array($foto_ext, $allowed_ext)) $foto_ext = 'jpg';

        $base64_data = preg_replace('/^data:image\/\w+;base64,/', '', $foto_cropped);
        $img_data    = base64_decode($base64_data);

        if (strlen($img_data) > 3 * 1024 * 1024) {
            $mensaje_feedback = "La foto no puede superar 3MB.";
            $tipo_feedback    = "error";
        } else {
            $upload_dir = __DIR__ . '/../../assets/img/avatars/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

            // Eliminar foto anterior si existe
            $stmt_old = $pdo->prepare("SELECT foto_perfil FROM usuarios WHERE id = ?");
            $stmt_old->execute([$user_id]);
            $old_foto = $stmt_old->fetchColumn();
            if ($old_foto && file_exists($upload_dir . $old_foto)) {
                unlink($upload_dir . $old_foto);
            }

            $new_filename = 'avatar_' . $user_id . '_' . uniqid() . '.' . $foto_ext;
            if (file_put_contents($upload_dir . $new_filename, $img_data) !== false) {
                $foto_campo = $new_filename;
            } else {
                $mensaje_feedback = "Error al guardar la foto. Verifique permisos.";
                $tipo_feedback    = "error";
            }
        }
    }

    if (empty($mensaje_feedback)) {
        try {
            if (!empty($password)) {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                if ($foto_campo !== null) {
                    $stmt = $pdo->prepare("UPDATE usuarios SET nombre=?, email=?, password=?, foto_perfil=? WHERE id=?");
                    $stmt->execute([$nombre, $email, $hash, $foto_campo, $user_id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE usuarios SET nombre=?, email=?, password=? WHERE id=?");
                    $stmt->execute([$nombre, $email, $hash, $user_id]);
                }
            } else {
                if ($foto_campo !== null) {
                    $stmt = $pdo->prepare("UPDATE usuarios SET nombre=?, email=?, foto_perfil=? WHERE id=?");
                    $stmt->execute([$nombre, $email, $foto_campo, $user_id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE usuarios SET nombre=?, email=? WHERE id=?");
                    $stmt->execute([$nombre, $email, $user_id]);
                }
            }
            $_SESSION['user_nombre'] = $nombre;
            $mensaje_feedback = "Perfil actualizado correctamente.";
            $tipo_feedback    = "success";
        } catch (PDOException $e) {
            $mensaje_feedback = "Error al actualizar: " . $e->getMessage();
            $tipo_feedback    = "error";
        }
    }
}

// --- 2. CAMBIAR ROL (solo admin) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_permission' && $user_rol === 'admin') {
    try {
        $stmt = $pdo->prepare("UPDATE usuarios SET rol=? WHERE id=?");
        $stmt->execute([$_POST['nuevo_rol'], $_POST['target_user_id']]);
        $mensaje_feedback = "Rol actualizado correctamente.";
        $tipo_feedback    = "success";
    } catch (PDOException $e) {
        $mensaje_feedback = "Error al cambiar rol.";
        $tipo_feedback    = "error";
    }
}

// --- 3. GUARDAR INFO PÁGINA DE INICIO (solo admin) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_pagina_info' && $user_rol === 'admin') {
    $campos = ['mision', 'vision', 'valores', 'sobre_nosotros',
               'contacto_direccion', 'contacto_telefono', 'contacto_email', 'contacto_horario'];
    try {
        $stmt = $pdo->prepare("INSERT INTO configuracion_pagina (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)");
        foreach ($campos as $campo) {
            $stmt->execute([$campo, trim($_POST[$campo] ?? '')]);
        }
        $mensaje_feedback = "Información de la página actualizada correctamente.";
        $tipo_feedback    = "success";
    } catch (PDOException $e) {
        $mensaje_feedback = "Error al guardar: " . $e->getMessage();
        $tipo_feedback    = "error";
    }
}

// --- 4. SUBIR IMAGEN GALERÍA (solo admin) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_galeria' && $user_rol === 'admin') {
    if (isset($_FILES['imagen_galeria']) && $_FILES['imagen_galeria']['error'] === UPLOAD_ERR_OK) {
        $file    = $_FILES['imagen_galeria'];
        $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        if (!in_array($ext, $allowed)) {
            $mensaje_feedback = "Formato no permitido. Use JPG, PNG, WEBP o GIF.";
            $tipo_feedback    = "error";
        } elseif ($file['size'] > 5 * 1024 * 1024) {
            $mensaje_feedback = "La imagen no puede superar 5MB.";
            $tipo_feedback    = "error";
        } else {
            $upload_dir = __DIR__ . '/../../assets/uploads/galeria/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $filename = uniqid() . '_' . time() . '.' . $ext;
            if (move_uploaded_file($file['tmp_name'], $upload_dir . $filename)) {
                $max_orden = (int)$pdo->query("SELECT COALESCE(MAX(orden), 0) FROM galeria_pagina")->fetchColumn();
                $pdo->prepare("INSERT INTO galeria_pagina (nombre_archivo, descripcion, orden) VALUES (?, ?, ?)")
                    ->execute([$filename, trim($_POST['descripcion'] ?? ''), $max_orden + 1]);
                $mensaje_feedback = "Imagen subida correctamente.";
                $tipo_feedback    = "success";
            } else {
                $mensaje_feedback = "Error al guardar la imagen en el servidor.";
                $tipo_feedback    = "error";
            }
        }
    } else {
        $mensaje_feedback = "No se recibió ninguna imagen válida.";
        $tipo_feedback    = "error";
    }
}

// --- 5. ELIMINAR IMAGEN GALERÍA (solo admin) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_galeria' && $user_rol === 'admin') {
    $img_id = (int)($_POST['imagen_id'] ?? 0);
    if ($img_id > 0) {
        try {
            $stmt = $pdo->prepare("SELECT nombre_archivo FROM galeria_pagina WHERE id = ?");
            $stmt->execute([$img_id]);
            $img = $stmt->fetch();
            if ($img) {
                $path = __DIR__ . '/../../assets/uploads/galeria/' . $img['nombre_archivo'];
                if (file_exists($path)) unlink($path);
                $pdo->prepare("DELETE FROM galeria_pagina WHERE id = ?")->execute([$img_id]);
            }
            $mensaje_feedback = "Imagen eliminada correctamente.";
            $tipo_feedback    = "success";
        } catch (PDOException $e) {
            $mensaje_feedback = "Error al eliminar la imagen.";
            $tipo_feedback    = "error";
        }
    }
}

// Datos actuales del usuario
$stmt = $pdo->prepare("SELECT nombre, email, rol, telefono, foto_perfil FROM usuarios WHERE id=?");
$stmt->execute([$user_id]);
$usuario_actual = $stmt->fetch();

// Lista de usuarios para permisos (solo admin)
$lista_usuarios = [];
if ($user_rol === 'admin') {
    $stmt = $pdo->prepare("SELECT id, nombre, email, rol, foto_perfil FROM usuarios WHERE id != ? ORDER BY rol ASC, nombre ASC");
    $stmt->execute([$user_id]);
    $lista_usuarios = $stmt->fetchAll();
}

// Datos para Página de Inicio (solo admin)
$pagina_config = [];
$galeria_imgs  = [];
if ($user_rol === 'admin') {
    try {
        $rows = $pdo->query("SELECT clave, valor FROM configuracion_pagina")->fetchAll();
        foreach ($rows as $row) $pagina_config[$row['clave']] = $row['valor'];
    } catch (PDOException $e) {}
    try {
        $galeria_imgs = $pdo->query("SELECT * FROM galeria_pagina ORDER BY orden ASC, id ASC")->fetchAll();
    } catch (PDOException $e) {}
}

// Helper: inicial del nombre para avatar fallback
function get_inicial(string $nombre): string {
    return mb_strtoupper(mb_substr(trim($nombre), 0, 1));
}

// Helper: ruta de foto
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
    <title>Configuración - Amimbré</title>
    <link rel="shortcut icon" href="../../assets/img/3.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0"/>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="../../assets/css/colores.css">
    <link rel="stylesheet" href="../../assets/css/style-configuracion.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.css">
    <script>
        (function() {
            const theme = localStorage.getItem('amimbre-theme');
            if (theme === 'light') document.documentElement.setAttribute('data-theme', 'light');
        })();
    </script>
</head>
<body>
    <?php require_once '../../includes/header.php'; ?>

    <main class="main-content">

        <div class="page-header">
            <div class="header-left">
                <button class="btn-back" onclick="window.history.back()">
                    <span class="material-symbols-rounded">arrow_back</span>
                </button>
                <div>
                    <h1>
                        <span class="material-symbols-rounded">settings</span>
                        Configuración
                    </h1>
                    <p>Ajustes de cuenta, apariencia y control de acceso</p>
                </div>
            </div>
        </div>

        <?php if ($mensaje_feedback): ?>
        <div class="alert alert-<?php echo $tipo_feedback; ?>">
            <span class="material-symbols-rounded">
                <?php echo $tipo_feedback === 'success' ? 'check_circle' : 'error'; ?>
            </span>
            <?php echo htmlspecialchars($mensaje_feedback); ?>
        </div>
        <?php endif; ?>

        <div class="config-layout">

            <!-- ── Sidebar de tabs ─────────────────────────── -->
            <aside class="tabs-sidebar">
                <div class="tabs-sidebar-header">
                    <span>Secciones</span>
                </div>
                <nav class="tabs-sidebar-nav">
                    <button class="tab-link active" onclick="openTab(event,'tab-perfil')">
                        <span class="material-symbols-rounded">person</span>
                        Mi Perfil
                    </button>
                    <button class="tab-link" onclick="openTab(event,'tab-interfaz')">
                        <span class="material-symbols-rounded">palette</span>
                        Interfaz
                    </button>
                    <?php if ($user_rol === 'admin'): ?>
                    <button class="tab-link" onclick="openTab(event,'tab-permisos')">
                        <span class="material-symbols-rounded">admin_panel_settings</span>
                        Permisos
                    </button>
                    <button class="tab-link" onclick="openTab(event,'tab-pagina')">
                        <span class="material-symbols-rounded">web</span>
                        Página de Inicio
                    </button>
                    <?php endif; ?>
                </nav>
            </aside>

            <!-- ── Contenido ───────────────────────────────── -->
            <div class="tabs-content">

                <!-- ===== TAB: MI PERFIL ===== -->
                <div id="tab-perfil" class="tab-content active">
                    <form method="POST">
                        <input type="hidden" name="action" value="update_profile">
                        <input type="hidden" name="foto_cropped" id="foto_cropped">
                        <input type="hidden" name="foto_ext"     id="foto_ext">

                        <!-- Foto de perfil -->
                        <div class="config-section">
                            <div class="section-header">
                                <span class="material-symbols-rounded">photo_camera</span>
                                <div>
                                    <h3>Foto de Perfil</h3>
                                    <p>Se mostrará en el sistema y en el encabezado</p>
                                </div>
                            </div>
                            <div class="section-body">
                                <div class="profile-photo-area">
                                    <!-- Avatar actual -->
                                    <div class="avatar-wrap">
                                        <?php $foto_url = get_foto_url($usuario_actual['foto_perfil']); ?>
                                        <?php if ($foto_url): ?>
                                        <img src="<?php echo $foto_url; ?>"
                                             alt="Foto de perfil"
                                             class="avatar-circle"
                                             id="avatar-display">
                                        <?php else: ?>
                                        <div class="avatar-placeholder" id="avatar-placeholder">
                                            <?php echo get_inicial($usuario_actual['nombre']); ?>
                                        </div>
                                        <img src="" alt="" class="avatar-circle" id="avatar-display"
                                             style="display:none;">
                                        <?php endif; ?>

                                        <!-- Botón editar sobre el avatar -->
                                        <label for="foto_raw" class="avatar-edit-btn" title="Cambiar foto">
                                            <span class="material-symbols-rounded">edit</span>
                                        </label>
                                    </div>

                                    <!-- Info + botón -->
                                    <div class="photo-info">
                                        <h4><?php echo htmlspecialchars($usuario_actual['nombre']); ?></h4>
                                        <p>JPG, PNG o WEBP · Máx. 3MB<br>La foto se recortará en formato cuadrado</p>
                                        <label for="foto_raw" class="btn-cambiar-foto">
                                            <span class="material-symbols-rounded">upload</span>
                                            <?php echo $foto_url ? 'Cambiar foto' : 'Subir foto'; ?>
                                        </label>
                                    </div>
                                </div>

                                <!-- Input file oculto -->
                                <input type="file" id="foto_raw" accept="image/jpeg,image/png,image/webp"
                                       style="position:absolute;opacity:0;width:0;height:0;">

                                <!-- Preview de nueva foto -->
                                <div class="foto-preview" id="foto-preview">
                                    <img id="foto-preview-img" src="" alt="">
                                    <div class="foto-preview-info">
                                        <p>Nueva foto lista</p>
                                        <span>Se guardará al hacer clic en "Guardar cambios"</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Datos personales -->
                        <div class="config-section">
                            <div class="section-header">
                                <span class="material-symbols-rounded">badge</span>
                                <div>
                                    <h3>Datos Personales</h3>
                                    <p>Nombre y correo de acceso al sistema</p>
                                </div>
                            </div>
                            <div class="section-body">
                                <div class="form-grid-2">
                                    <div class="form-group">
                                        <label>Nombre Completo</label>
                                        <input type="text" name="nombre" required
                                               value="<?php echo htmlspecialchars($usuario_actual['nombre']); ?>"
                                               placeholder="Tu nombre completo">
                                    </div>
                                    <div class="form-group">
                                        <label>Correo Electrónico</label>
                                        <input type="email" name="email" required
                                               value="<?php echo htmlspecialchars($usuario_actual['email']); ?>"
                                               placeholder="correo@ejemplo.com">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Seguridad -->
                        <div class="config-section">
                            <div class="section-header">
                                <span class="material-symbols-rounded">lock</span>
                                <div>
                                    <h3>Seguridad</h3>
                                    <p>Deja en blanco para mantener la contraseña actual</p>
                                </div>
                            </div>
                            <div class="section-body">
                                <div class="form-grid-2">
                                    <div class="form-group">
                                        <label>Nueva Contraseña</label>
                                        <div class="input-password-wrap">
                                            <input type="password" name="password" id="password-input"
                                                   placeholder="Mínimo 8 caracteres">
                                            <button type="button" class="toggle-password"
                                                    onclick="togglePass('password-input', this)">
                                                <span class="material-symbols-rounded">visibility</span>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label>Confirmar Contraseña</label>
                                        <div class="input-password-wrap">
                                            <input type="password" id="password-confirm"
                                                   placeholder="Repite la contraseña">
                                            <button type="button" class="toggle-password"
                                                    onclick="togglePass('password-confirm', this)">
                                                <span class="material-symbols-rounded">visibility</span>
                                            </button>
                                        </div>
                                        <small id="pass-match-msg"></small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn-primario" id="btn-guardar">
                                <span class="material-symbols-rounded">save</span>
                                Guardar Cambios
                            </button>
                        </div>
                    </form>
                </div>

                <!-- ===== TAB: INTERFAZ ===== -->
                <div id="tab-interfaz" class="tab-content">
                    <div class="config-section">
                        <div class="section-header">
                            <span class="material-symbols-rounded">dark_mode</span>
                            <div>
                                <h3>Tema del Sistema</h3>
                                <p>Selecciona la apariencia de la interfaz</p>
                            </div>
                        </div>
                        <div class="section-body">
                            <div class="theme-options">
                                <div class="theme-option" id="theme-dark" onclick="setTheme('dark')">
                                    <div class="theme-preview theme-preview-dark">
                                        <div class="preview-bar"></div>
                                        <div class="preview-content">
                                            <div class="preview-line"></div>
                                            <div class="preview-line short"></div>
                                            <div class="preview-line"></div>
                                        </div>
                                    </div>
                                    <span>Modo Oscuro</span>
                                    <span class="material-symbols-rounded theme-check">check_circle</span>
                                </div>
                                <div class="theme-option" id="theme-light" onclick="setTheme('light')">
                                    <div class="theme-preview theme-preview-light">
                                        <div class="preview-bar"></div>
                                        <div class="preview-content">
                                            <div class="preview-line"></div>
                                            <div class="preview-line short"></div>
                                            <div class="preview-line"></div>
                                        </div>
                                    </div>
                                    <span>Modo Claro</span>
                                    <span class="material-symbols-rounded theme-check">check_circle</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="config-section">
                        <div class="section-header">
                            <span class="material-symbols-rounded">format_size</span>
                            <div>
                                <h3>Tamaño de Fuente</h3>
                                <p>Ajusta el tamaño del texto en el sistema</p>
                            </div>
                        </div>
                        <div class="section-body">
                            <div class="font-size-options">
                                <button class="font-size-btn" onclick="setFontSize('14px', this)"
                                        style="font-size:0.82rem;">Pequeño</button>
                                <button class="font-size-btn active" onclick="setFontSize('16px', this)"
                                        style="font-size:0.93rem;">Normal</button>
                                <button class="font-size-btn" onclick="setFontSize('18px', this)"
                                        style="font-size:1.05rem;">Grande</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ===== TAB: PERMISOS (solo admin) ===== -->
                <?php if ($user_rol === 'admin'): ?>
                <div id="tab-permisos" class="tab-content">
                    <div class="config-section">
                        <div class="section-header">
                            <span class="material-symbols-rounded">manage_accounts</span>
                            <div>
                                <h3>Gestión de Roles</h3>
                                <p>Modifica el nivel de acceso de los usuarios registrados</p>
                            </div>
                        </div>
                        <div class="section-body" style="padding: 0;">
                            <div style="overflow-x: auto;">
                                <table class="permisos-table">
                                    <thead>
                                        <tr>
                                            <th>Usuario</th>
                                            <th>Correo</th>
                                            <th>Rol Actual</th>
                                            <th>Nuevo Rol</th>
                                            <th>Acción</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($lista_usuarios as $u): ?>
                                        <tr>
                                            <td>
                                                <div class="user-cell">
                                                    <?php $u_foto = get_foto_url($u['foto_perfil']); ?>
                                                    <?php if ($u_foto): ?>
                                                    <img src="<?php echo $u_foto; ?>"
                                                         class="user-mini-avatar"
                                                         alt="">
                                                    <?php else: ?>
                                                    <div class="user-mini-avatar">
                                                        <?php echo get_inicial($u['nombre']); ?>
                                                    </div>
                                                    <?php endif; ?>
                                                    <div class="user-cell-info">
                                                        <strong><?php echo htmlspecialchars($u['nombre']); ?></strong>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($u['email']); ?></td>
                                            <td>
                                                <span class="role-badge role-<?php echo $u['rol']; ?>">
                                                    <?php echo ucfirst($u['rol']); ?>
                                                </span>
                                            </td>
                                            <form method="POST" style="display:contents;">
                                                <input type="hidden" name="action" value="change_permission">
                                                <input type="hidden" name="target_user_id" value="<?php echo $u['id']; ?>">
                                                <td>
                                                    <select name="nuevo_rol">
                                                        <option value="estudiante" <?php echo $u['rol']==='estudiante'?'selected':''; ?>>Estudiante</option>
                                                        <option value="profesor"   <?php echo $u['rol']==='profesor'  ?'selected':''; ?>>Profesor</option>
                                                        <option value="admin"      <?php echo $u['rol']==='admin'     ?'selected':''; ?>>Administrador</option>
                                                    </select>
                                                </td>
                                                <td>
                                                    <button type="submit" class="btn-update-role">
                                                        <span class="material-symbols-rounded">check</span>
                                                        Actualizar
                                                    </button>
                                                </td>
                                            </form>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- ===== TAB: PÁGINA DE INICIO (solo admin) ===== -->
                <?php if ($user_rol === 'admin'): ?>
                <div id="tab-pagina" class="tab-content">

                    <!-- Galería -->
                    <div class="config-section">
                        <div class="section-header">
                            <span class="material-symbols-rounded">photo_library</span>
                            <div>
                                <h3>Galería</h3>
                                <p>Imágenes que aparecen en la sección Galería del sitio público</p>
                            </div>
                        </div>
                        <div class="section-body">
                            <form method="POST" enctype="multipart/form-data" id="form-upload-galeria">
                                <input type="hidden" name="action" value="upload_galeria">

                                <!-- Zona de drop personalizada -->
                                <div class="galeria-dropzone" id="galeria-dropzone">
                                    <input type="file" name="imagen_galeria" id="galeria-file-input"
                                           accept="image/*" required class="galeria-file-hidden">
                                    <div class="dropzone-idle" id="dropzone-idle">
                                        <span class="material-symbols-rounded">cloud_upload</span>
                                        <p>Arrastra una imagen aquí o <label for="galeria-file-input" class="dropzone-browse">selecciona un archivo</label></p>
                                        <span>JPG, PNG, WEBP, GIF · Máx. 5 MB</span>
                                    </div>
                                    <div class="dropzone-preview" id="dropzone-preview">
                                        <img id="dropzone-thumb" src="" alt="">
                                        <div class="dropzone-preview-info">
                                            <p id="dropzone-filename">archivo.jpg</p>
                                            <span id="dropzone-filesize">0 KB</span>
                                            <button type="button" class="dropzone-clear" id="dropzone-clear">
                                                <span class="material-symbols-rounded">close</span>
                                                Quitar
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-group" style="margin-top:14px;">
                                    <label>Descripción (opcional)</label>
                                    <input type="text" name="descripcion" placeholder="Descripción de la imagen...">
                                </div>

                                <div class="galeria-upload-actions">
                                    <button type="submit" class="btn-primario" id="btn-subir-imagen" disabled>
                                        <span class="material-symbols-rounded">upload</span>
                                        Subir Imagen
                                    </button>
                                </div>
                            </form>

                            <?php if (!empty($galeria_imgs)): ?>
                            <div class="galeria-admin-grid">
                                <?php foreach ($galeria_imgs as $img): ?>
                                <div class="galeria-admin-item">
                                    <img src="../../assets/uploads/galeria/<?= htmlspecialchars($img['nombre_archivo']) ?>"
                                         alt="<?= htmlspecialchars($img['descripcion'] ?? '') ?>">
                                    <div class="galeria-item-overlay">
                                        <form method="POST"
                                              onsubmit="return confirm('¿Eliminar esta imagen? Esta acción no se puede deshacer.')">
                                            <input type="hidden" name="action" value="delete_galeria">
                                            <input type="hidden" name="imagen_id" value="<?= $img['id'] ?>">
                                            <button type="submit" class="btn-galeria-del" title="Eliminar imagen">
                                                <span class="material-symbols-rounded">delete</span>
                                            </button>
                                        </form>
                                    </div>
                                    <?php if ($img['descripcion']): ?>
                                    <p class="galeria-item-desc"><?= htmlspecialchars($img['descripcion']) ?></p>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <div class="galeria-empty-state">
                                <span class="material-symbols-rounded">photo_library</span>
                                <p>No hay imágenes administradas aún.</p>
                                <span>Se muestran las imágenes predeterminadas del sitio. Sube imágenes para reemplazarlas.</span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Misión, Visión, Valores y Descripción general -->
                    <form method="POST">
                        <input type="hidden" name="action" value="update_pagina_info">

                        <div class="config-section">
                            <div class="section-header">
                                <span class="material-symbols-rounded">school</span>
                                <div>
                                    <h3>Misión, Visión y Valores</h3>
                                    <p>Texto de la sección "Sobre Nosotros" del sitio público</p>
                                </div>
                            </div>
                            <div class="section-body">
                                <div class="form-group" style="margin-bottom:18px;">
                                    <label>Misión</label>
                                    <textarea name="mision" rows="3"
                                              placeholder="Escribe la misión de la institución..."><?= htmlspecialchars($pagina_config['mision'] ?? '') ?></textarea>
                                </div>
                                <div class="form-group" style="margin-bottom:18px;">
                                    <label>Visión</label>
                                    <textarea name="vision" rows="3"
                                              placeholder="Escribe la visión de la institución..."><?= htmlspecialchars($pagina_config['vision'] ?? '') ?></textarea>
                                </div>
                                <div class="form-group" style="margin-bottom:18px;">
                                    <label>Valores</label>
                                    <textarea name="valores" rows="3"
                                              placeholder="Escribe los valores de la institución..."><?= htmlspecialchars($pagina_config['valores'] ?? '') ?></textarea>
                                </div>
                                <div class="form-group">
                                    <label>Descripción general (párrafo "Sobre Amimbré")</label>
                                    <textarea name="sobre_nosotros" rows="6"
                                              placeholder="Descripción general (usa doble salto de línea para separar párrafos)..."><?= htmlspecialchars($pagina_config['sobre_nosotros'] ?? '') ?></textarea>
                                </div>
                            </div>
                        </div>

                        <div class="config-section">
                            <div class="section-header">
                                <span class="material-symbols-rounded">contact_phone</span>
                                <div>
                                    <h3>Información de Contacto</h3>
                                    <p>Datos visibles en la sección "Contacto" del sitio público</p>
                                </div>
                            </div>
                            <div class="section-body">
                                <div class="form-grid-2">
                                    <div class="form-group">
                                        <label>Dirección</label>
                                        <input type="text" name="contacto_direccion"
                                               value="<?= htmlspecialchars($pagina_config['contacto_direccion'] ?? '') ?>"
                                               placeholder="Dirección física de la institución">
                                    </div>
                                    <div class="form-group">
                                        <label>Teléfono</label>
                                        <input type="text" name="contacto_telefono"
                                               value="<?= htmlspecialchars($pagina_config['contacto_telefono'] ?? '') ?>"
                                               placeholder="Número de contacto">
                                    </div>
                                    <div class="form-group">
                                        <label>Correo Electrónico</label>
                                        <input type="email" name="contacto_email"
                                               value="<?= htmlspecialchars($pagina_config['contacto_email'] ?? '') ?>"
                                               placeholder="email@ejemplo.com">
                                    </div>
                                    <div class="form-group">
                                        <label>Horario de Atención</label>
                                        <input type="text" name="contacto_horario"
                                               value="<?= htmlspecialchars($pagina_config['contacto_horario'] ?? '') ?>"
                                               placeholder="Ej: Lunes - Sábado: 7:00 a 5:00">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn-primario">
                                <span class="material-symbols-rounded">save</span>
                                Guardar Cambios
                            </button>
                        </div>
                    </form>

                </div>
                <?php endif; ?>

            </div><!-- /tabs-content -->
        </div><!-- /config-layout -->
    </main>

    <!-- ── Modal Cropper foto de perfil ─────────────────────── -->
    <div class="cropper-modal-overlay" id="cropperOverlay">
        <div class="cropper-modal-box">
            <div class="cropper-modal-header">
                <h3>
                    <span class="material-symbols-rounded">person</span>
                    Recortar foto de perfil
                </h3>
                <button class="cropper-close-btn" id="btnCropClose" type="button">
                    <span class="material-symbols-rounded">close</span>
                </button>
            </div>
            <div class="cropper-canvas-wrap">
                <img id="cropImage" src="" alt="">
            </div>
            <div class="cropper-modal-footer">
                <span class="cropper-hint">
                    <span class="material-symbols-rounded">info</span>
                    Proporción 1:1 — se mostrará circular
                </span>
                <div class="cropper-actions">
                    <button type="button" class="btn-crop-cancel" id="btnCropCancel">Cancelar</button>
                    <button type="button" class="btn-crop-confirm" id="btnCropConfirm">
                        <span class="material-symbols-rounded">check</span>
                        Aplicar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.js"></script>
    <script>
    // ── TABS ──────────────────────────────────────────────────
    function openTab(evt, tabId) {
        document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.tab-link').forEach(l => l.classList.remove('active'));
        document.getElementById(tabId).classList.add('active');
        evt.currentTarget.classList.add('active');
    }

    // ── TEMA ──────────────────────────────────────────────────
    function setTheme(mode) {
        const html = document.documentElement;
        if (mode === 'light') {
            html.setAttribute('data-theme', 'light');
            localStorage.setItem('amimbre-theme', 'light');
        } else {
            html.removeAttribute('data-theme');
            localStorage.setItem('amimbre-theme', 'dark');
        }
        updateThemeUI();
    }

    function updateThemeUI() {
        const isLight = document.documentElement.getAttribute('data-theme') === 'light';
        document.getElementById('theme-dark').classList.toggle('active', !isLight);
        document.getElementById('theme-light').classList.toggle('active', isLight);
    }

    document.addEventListener('DOMContentLoaded', updateThemeUI);

    // ── FUENTE ────────────────────────────────────────────────
    function setFontSize(size, btn) {
        document.documentElement.style.fontSize = size;
        document.querySelectorAll('.font-size-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
    }

    // ── PASSWORD TOGGLE ───────────────────────────────────────
    function togglePass(inputId, btn) {
        const input = document.getElementById(inputId);
        const icon  = btn.querySelector('.material-symbols-rounded');
        if (input.type === 'password') {
            input.type = 'text';
            icon.textContent = 'visibility_off';
        } else {
            input.type = 'password';
            icon.textContent = 'visibility';
        }
    }

    // ── VALIDACIÓN CONTRASEÑA ─────────────────────────────────
    const passInput   = document.getElementById('password-input');
    const passConfirm = document.getElementById('password-confirm');
    const passMsg     = document.getElementById('pass-match-msg');

    function checkPasswords() {
        if (!passInput.value && !passConfirm.value) {
            passMsg.textContent = '';
            passMsg.style.color = '';
            return;
        }
        if (passInput.value === passConfirm.value) {
            passMsg.textContent = '✓ Las contraseñas coinciden';
            passMsg.style.color = 'var(--primary-green)';
        } else {
            passMsg.textContent = 'Las contraseñas no coinciden';
            passMsg.style.color = '#ef4444';
        }
    }

    if (passInput) passInput.addEventListener('input', checkPasswords);
    if (passConfirm) passConfirm.addEventListener('input', checkPasswords);

    // ── CROPPER FOTO DE PERFIL ────────────────────────────────
    (function () {
        const inputRaw     = document.getElementById('foto_raw');
        const inputCropped = document.getElementById('foto_cropped');
        const inputExt     = document.getElementById('foto_ext');
        const avatarDisplay    = document.getElementById('avatar-display');
        const avatarPlaceholder = document.getElementById('avatar-placeholder');
        const fotoPreview  = document.getElementById('foto-preview');
        const fotoPreviewImg = document.getElementById('foto-preview-img');

        const overlay  = document.getElementById('cropperOverlay');
        const cropImg  = document.getElementById('cropImage');
        let cropper    = null;
        let currentExt = 'jpg';

        inputRaw.addEventListener('change', function (e) {
            const file = e.target.files[0];
            if (!file) return;

            if (file.size > 3 * 1024 * 1024) {
                alert('La foto no puede superar 3MB');
                this.value = '';
                return;
            }

            currentExt = file.name.split('.').pop().toLowerCase();
            const url  = URL.createObjectURL(file);

            if (cropper) { cropper.destroy(); cropper = null; }
            cropImg.src = url;
            overlay.classList.add('active');

            cropImg.onload = function () {
                cropper = new Cropper(cropImg, {
                    aspectRatio: 1,       // 1:1 para foto de perfil
                    viewMode: 2,
                    dragMode: 'move',
                    autoCropArea: 0.85,
                    restore: false,
                    guides: true,
                    center: true,
                    cropBoxMovable: true,
                    cropBoxResizable: false,  // fijo en 1:1
                    toggleDragModeOnDblclick: false,
                    background: true,
                });
            };
            this.value = '';
        });

        document.getElementById('btnCropConfirm').addEventListener('click', function () {
            if (!cropper) return;

            const canvas = cropper.getCroppedCanvas({ width: 400, height: 400 });
            const mime   = currentExt === 'png' ? 'image/png' : 'image/jpeg';
            const b64    = canvas.toDataURL(mime, 0.9);

            inputCropped.value = b64;
            inputExt.value     = currentExt;

            // Actualizar avatar en pantalla
            if (avatarDisplay) {
                avatarDisplay.src = b64;
                avatarDisplay.style.display = 'block';
            }
            if (avatarPlaceholder) {
                avatarPlaceholder.style.display = 'none';
            }

            // Mostrar preview pequeño
            fotoPreviewImg.src = b64;
            fotoPreview.classList.add('visible');

            closeCropper();
        });

        document.getElementById('btnCropCancel').addEventListener('click', closeCropper);
        document.getElementById('btnCropClose').addEventListener('click', closeCropper);
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) closeCropper();
        });

        function closeCropper() {
            overlay.classList.remove('active');
            if (cropper) { cropper.destroy(); cropper = null; }
        }
    })();

    // ── GALERÍA DROPZONE ──────────────────────────────────────
    (function () {
        const fileInput   = document.getElementById('galeria-file-input');
        const dropzone    = document.getElementById('galeria-dropzone');
        const idleView    = document.getElementById('dropzone-idle');
        const previewView = document.getElementById('dropzone-preview');
        const thumb       = document.getElementById('dropzone-thumb');
        const fname       = document.getElementById('dropzone-filename');
        const fsize       = document.getElementById('dropzone-filesize');
        const clearBtn    = document.getElementById('dropzone-clear');
        const submitBtn   = document.getElementById('btn-subir-imagen');

        if (!fileInput) return;

        function formatSize(bytes) {
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
        }

        function showPreview(file) {
            if (!file || !file.type.startsWith('image/')) return;
            const url = URL.createObjectURL(file);
            thumb.src = url;
            fname.textContent = file.name;
            fsize.textContent = formatSize(file.size);
            idleView.style.display  = 'none';
            previewView.style.display = 'flex';
            submitBtn.disabled = false;
            dropzone.classList.add('has-file');
        }

        function clearFile() {
            fileInput.value = '';
            thumb.src = '';
            idleView.style.display  = 'flex';
            previewView.style.display = 'none';
            submitBtn.disabled = true;
            dropzone.classList.remove('has-file', 'drag-over');
        }

        fileInput.addEventListener('change', function () {
            if (this.files[0]) showPreview(this.files[0]);
        });

        clearBtn.addEventListener('click', clearFile);

        // Drag & drop
        ['dragenter', 'dragover'].forEach(evt => {
            dropzone.addEventListener(evt, function (e) {
                e.preventDefault();
                dropzone.classList.add('drag-over');
            });
        });

        ['dragleave', 'drop'].forEach(evt => {
            dropzone.addEventListener(evt, function (e) {
                e.preventDefault();
                dropzone.classList.remove('drag-over');
            });
        });

        dropzone.addEventListener('drop', function (e) {
            const file = e.dataTransfer.files[0];
            if (!file) return;
            const dt = new DataTransfer();
            dt.items.add(file);
            fileInput.files = dt.files;
            showPreview(file);
        });
    })();
    </script>
</body>
</html>