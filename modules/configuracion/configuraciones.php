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
            <h1>
                <span class="material-symbols-rounded">settings</span>
                Configuración
            </h1>
            <p>Ajustes de cuenta, apariencia y control de acceso</p>
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
    </script>
</body>
</html>