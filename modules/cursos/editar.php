<?php
/**
 * Editar Curso
 * Sistema Amimbré
 */

require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth_check.php';
require_role('admin');

// Obtener ID del curso
$curso_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($curso_id <= 0) {
    header("Location: index.php?error=curso_no_encontrado");
    exit;
}

// Obtener datos del curso
try {
    $stmt = $pdo->prepare("SELECT * FROM cursos WHERE id = ?");
    $stmt->execute([$curso_id]);
    $curso = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$curso) {
        header("Location: index.php?error=curso_no_encontrado");
        exit;
    }
} catch (PDOException $e) {
    error_log("Error al obtener curso: " . $e->getMessage());
    header("Location: index.php?error=error_sistema");
    exit;
}

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim($_POST['nombre']);
    $descripcion = trim($_POST['descripcion']);
    $duracion_meses = (int)$_POST['duracion_meses'];
    $nivel = $_POST['nivel'];
    $cupo_maximo = (int)$_POST['cupo_maximo'];
    $precio_mensual = (float)$_POST['precio_mensual'];
    $requisitos = trim($_POST['requisitos']);
    $estado = $_POST['estado'];
    
    $errores = [];
    
    // Validaciones
    if (empty($nombre)) {
        $errores[] = "El nombre del curso es obligatorio";
    }
    
    if ($duracion_meses < 1 || $duracion_meses > 48) {
        $errores[] = "La duración debe estar entre 1 y 48 meses";
    }
    
    if ($cupo_maximo < 1 || $cupo_maximo > 50) {
        $errores[] = "El cupo máximo debe estar entre 1 y 50";
    }
    
    if ($precio_mensual < 0) {
        $errores[] = "El precio debe ser mayor o igual a 0";
    }
    
    // Procesar imagen desde el cropper (base64)
    $imagen = $curso['imagen']; // mantener imagen actual por defecto

    $imagen_cropped = $_POST['imagen_cropped'] ?? '';
    $imagen_ext     = strtolower(trim($_POST['imagen_ext'] ?? 'jpg'));

    if (!empty($imagen_cropped) && strpos($imagen_cropped, 'data:image/') === 0) {
        $allowed_ext = ['jpg', 'jpeg', 'png', 'webp'];
        if (!in_array($imagen_ext, $allowed_ext)) $imagen_ext = 'jpg';

        $base64_data = preg_replace('/^data:image\/\w+;base64,/', '', $imagen_cropped);
        $img_data    = base64_decode($base64_data);

        if (strlen($img_data) > 5 * 1024 * 1024) {
            $errores[] = "La imagen no puede superar 5MB";
        } else {
            $new_filename = uniqid('curso_') . '.' . $imagen_ext;
            $upload_dir   = __DIR__ . '/../../assets/img/cursos/';

            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

            if (file_put_contents($upload_dir . $new_filename, $img_data) !== false) {
                // Eliminar imagen anterior si existe
                if ($curso['imagen'] && file_exists($upload_dir . $curso['imagen'])) {
                    unlink($upload_dir . $curso['imagen']);
                }
                $imagen = $new_filename;
            } else {
                $errores[] = "Error al guardar la imagen. Verifique permisos del directorio.";
            }
        }
    }
    
    // Si no hay errores, actualizar
    if (empty($errores)) {
        try {
            $sql = "UPDATE cursos SET 
                nombre = ?, descripcion = ?, duracion_meses = ?, nivel = ?, 
                cupo_maximo = ?, precio_mensual = ?, estado = ?, requisitos = ?, imagen = ?
                WHERE id = ?";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $nombre, $descripcion, $duracion_meses, $nivel,
                $cupo_maximo, $precio_mensual, $estado, $requisitos, $imagen,
                $curso_id
            ]);
            
            header("Location: index.php?success=curso_actualizado");
            exit;
            
        } catch (PDOException $e) {
            error_log("Error al actualizar curso: " . $e->getMessage());
            $errores[] = "Error al actualizar el curso. Intente nuevamente.";
        }
    }
    
    // Si hay errores, actualizar datos del curso para el formulario
    $curso = array_merge($curso, $_POST);
    $curso['imagen'] = $imagen;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Curso - Amimbré</title>
    <link rel="shortcut icon" href="../../assets/img/3.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0" />
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="../../assets/css/colores.css">
    <link rel="stylesheet" href="../../assets/css/style-cursos-form.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.css">
    <script>
        (function() {
            const theme = localStorage.getItem('amimbre-theme');
            if (theme === 'light') {
                document.documentElement.setAttribute('data-theme', 'light');
            }
        })();
    </script>
    <style>
        .cropper-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.85);
            backdrop-filter: blur(6px);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }
        .cropper-modal-overlay.active { display: flex; }
        .cropper-modal-box {
            background: var(--dark-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            width: min(880px, 94vw);
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 25px 60px rgba(0,0,0,0.6);
        }
        .cropper-modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 24px;
            border-bottom: 1px solid var(--border-color);
            flex-shrink: 0;
        }
        .cropper-modal-header h3 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0;
        }
        .cropper-modal-header h3 span { color: var(--primary-blue); }
        .cropper-close-btn {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            color: var(--text-secondary);
            border-radius: 8px;
            padding: 6px 10px;
            cursor: pointer;
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            transition: all 0.2s;
        }
        .cropper-close-btn:hover { background: #ef4444; color: white; border-color: #ef4444; }
        .cropper-canvas-wrap {
            flex: 1;
            overflow: hidden;
            background: #000;
            min-height: 0;
            height: 430px;
        }
        .cropper-canvas-wrap img { display: block; max-width: 100%; }
        .cropper-modal-footer {
            padding: 16px 24px;
            border-top: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-shrink: 0;
            flex-wrap: wrap;
        }
        .cropper-hint {
            color: var(--text-secondary);
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .cropper-actions { display: flex; gap: 10px; }
        .btn-crop-cancel {
            padding: 10px 22px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-primary);
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-crop-cancel:hover { background: var(--hover-bg); }
        .btn-crop-confirm {
            padding: 10px 22px;
            background: var(--gradient-primary-blue);
            border: none;
            border-radius: 8px;
            color: white;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 7px;
            transition: all 0.2s;
            box-shadow: var(--shadow-md);
        }
        .btn-crop-confirm:hover { transform: translateY(-1px); box-shadow: var(--shadow-lg); }
        .image-preview-cropped {
            margin-top: 15px;
            border-radius: 10px;
            overflow: hidden;
            border: 1px solid var(--border-color);
            aspect-ratio: 2 / 1;
            background: var(--card-bg);
            display: none;
        }
        .image-preview-cropped.visible { display: block; }
        .image-preview-cropped img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .file-upload-label { transition: border-color 0.2s, background 0.2s; }
        .file-upload-label.has-file { border-color: var(--primary-blue); color: var(--primary-blue); }
        .current-image-wrap {
            margin-bottom: 14px;
        }
        .current-image-wrap p {
            color: var(--text-secondary);
            font-size: 0.82rem;
            margin-bottom: 8px;
        }
        .current-image-wrap img {
            width: 100%;
            aspect-ratio: 2 / 1;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            display: block;
        }
    </style>
</head>
<body>
    <?php require_once '../../includes/header.php'; ?>

    <main class="main-content">
        <div class="page-header">
            <div class="header-nav">
                <a href="index.php" class="btn-back">
                    <span class="material-symbols-rounded">arrow_back</span>
                    Volver
                </a>
            </div>
            <h1>Editar Curso</h1>
            <p>Modifica la información del curso</p>
        </div>

        <?php if (!empty($errores)): ?>
        <div class="alert alert-error">
            <span class="material-symbols-rounded">error</span>
            <div>
                <strong>Se encontraron errores:</strong>
                <ul>
                    <?php foreach ($errores as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" class="form-curso">
            <div class="form-grid">
                <!-- Columna Izquierda -->
                <div class="form-column">
                    <div class="form-section">
                        <h3 class="section-title">Información Básica</h3>
                        
                        <div class="form-group">
                            <label for="nombre">
                                Nombre del Curso <span class="required">*</span>
                            </label>
                            <input 
                                type="text" 
                                id="nombre" 
                                name="nombre" 
                                required
                                value="<?php echo htmlspecialchars($curso['nombre']); ?>"
                                placeholder="Ej: Piano Clásico"
                            >
                        </div>

                        <div class="form-group">
                            <label for="descripcion">Descripción</label>
                            <textarea 
                                id="descripcion" 
                                name="descripcion" 
                                rows="4"
                                placeholder="Describe el curso, metodología, objetivos..."
                            ><?php echo htmlspecialchars($curso['descripcion']); ?></textarea>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="nivel">
                                    Nivel <span class="required">*</span>
                                </label>
                                <select id="nivel" name="nivel" required>
                                    <option value="basico" <?php echo $curso['nivel'] === 'basico' ? 'selected' : ''; ?>>Básico</option>
                                    <option value="intermedio" <?php echo $curso['nivel'] === 'intermedio' ? 'selected' : ''; ?>>Intermedio</option>
                                    <option value="avanzado" <?php echo $curso['nivel'] === 'avanzado' ? 'selected' : ''; ?>>Avanzado</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="estado">
                                    Estado <span class="required">*</span>
                                </label>
                                <select id="estado" name="estado" required>
                                    <option value="activo" <?php echo $curso['estado'] === 'activo' ? 'selected' : ''; ?>>Activo</option>
                                    <option value="inactivo" <?php echo $curso['estado'] === 'inactivo' ? 'selected' : ''; ?>>Inactivo</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="requisitos">Requisitos</label>
                            <textarea 
                                id="requisitos" 
                                name="requisitos" 
                                rows="3"
                                placeholder="Conocimientos previos, edad mínima, materiales necesarios..."
                            ><?php echo htmlspecialchars($curso['requisitos']); ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- Columna Derecha -->
                <div class="form-column">
                    <div class="form-section">
                        <h3 class="section-title">Detalles del Curso</h3>
                        
                        <div class="form-group">
                            <label for="duracion_meses">
                                Duración (meses) <span class="required">*</span>
                            </label>
                            <input 
                                type="number" 
                                id="duracion_meses" 
                                name="duracion_meses" 
                                min="1" 
                                max="48" 
                                required
                                value="<?php echo htmlspecialchars($curso['duracion_meses']); ?>"
                            >
                            <small>Entre 1 y 48 meses</small>
                        </div>

                        <div class="form-group">
                            <label for="cupo_maximo">
                                Cupo Máximo por Grupo <span class="required">*</span>
                            </label>
                            <input 
                                type="number" 
                                id="cupo_maximo" 
                                name="cupo_maximo" 
                                min="1" 
                                max="50" 
                                required
                                value="<?php echo htmlspecialchars($curso['cupo_maximo']); ?>"
                            >
                            <small>Entre 1 y 50 estudiantes</small>
                        </div>

                        <div class="form-group">
                            <label for="precio_mensual">
                                Precio Mensual <span class="required">*</span>
                            </label>
                            <div class="input-with-prefix">
                                <span class="prefix">$</span>
                                <input 
                                    type="number" 
                                    id="precio_mensual" 
                                    name="precio_mensual" 
                                    min="0" 
                                    step="1000" 
                                    required
                                    value="<?php echo htmlspecialchars($curso['precio_mensual']); ?>"
                                    placeholder="150000"
                                >
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="imagen_raw">Imagen del Curso</label>
                            
                            <?php if ($curso['imagen']): ?>
                            <div class="current-image-wrap">
                                <p>Imagen actual:</p>
                                <img 
                                    src="../../assets/img/cursos/<?php echo htmlspecialchars($curso['imagen']); ?>" 
                                    alt="Imagen actual"
                                    id="current-image-display"
                                >
                            </div>
                            <?php endif; ?>

                            <div class="file-upload">
                                <input 
                                    type="file" 
                                    id="imagen_raw"
                                    name="_imagen_raw_unused"
                                    accept="image/jpeg,image/png,image/webp"
                                    style="position:absolute;opacity:0;width:0;height:0;"
                                >
                                <input type="hidden" name="imagen_cropped" id="imagen_cropped">
                                <input type="hidden" name="imagen_ext" id="imagen_ext">

                                <label for="imagen_raw" class="file-upload-label" id="upload-label">
                                    <span class="material-symbols-rounded">crop</span>
                                    <span id="upload-label-text">
                                        <?php echo $curso['imagen'] ? 'Cambiar y recortar imagen' : 'Seleccionar y recortar imagen'; ?>
                                    </span>
                                </label>
                                <small>JPG, PNG o WEBP · Máx. 5MB · Se recortará en proporción 2:1</small>
                            </div>

                            <div class="image-preview-cropped" id="preview-cropped">
                                <img id="preview-cropped-img" src="" alt="Nueva imagen recortada">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <a href="index.php" class="btn-secundario">Cancelar</a>
                <button type="submit" class="btn-primario">
                    <span class="material-symbols-rounded">save</span>
                    Guardar Cambios
                </button>
            </div>
        </form>
    </main>

    <!-- ── Modal Cropper ──────────────────────────────────────── -->
    <div class="cropper-modal-overlay" id="cropperOverlay">
        <div class="cropper-modal-box">
            <div class="cropper-modal-header">
                <h3>
                    <span class="material-symbols-rounded">crop</span>
                    Recortar imagen del curso
                </h3>
                <button class="cropper-close-btn" id="btnCropClose" type="button">
                    <span class="material-symbols-rounded">close</span>
                </button>
            </div>
            <div class="cropper-canvas-wrap">
                <img id="cropImage" src="" alt="Imagen a recortar">
            </div>
            <div class="cropper-modal-footer">
                <span class="cropper-hint">
                    <span class="material-symbols-rounded" style="font-size:1rem;color:var(--primary-blue)">info</span>
                    Ajusta el recuadro · Proporción fija 2:1
                </span>
                <div class="cropper-actions">
                    <button type="button" class="btn-crop-cancel" id="btnCropCancel">Cancelar</button>
                    <button type="button" class="btn-crop-confirm" id="btnCropConfirm">
                        <span class="material-symbols-rounded">check</span>
                        Aplicar recorte
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.js"></script>
    <script>
    (function () {
        const ASPECT = 2 / 1;

        const inputRaw     = document.getElementById('imagen_raw');
        const inputCropped = document.getElementById('imagen_cropped');
        const inputExt     = document.getElementById('imagen_ext');
        const uploadLabel  = document.getElementById('upload-label');
        const uploadText   = document.getElementById('upload-label-text');
        const previewBox   = document.getElementById('preview-cropped');
        const previewImg   = document.getElementById('preview-cropped-img');
        const currentImg   = document.getElementById('current-image-display');

        const overlay  = document.getElementById('cropperOverlay');
        const cropImg  = document.getElementById('cropImage');
        let cropper    = null;
        let currentExt = 'jpg';

        inputRaw.addEventListener('change', function (e) {
            const file = e.target.files[0];
            if (!file) return;

            if (file.size > 5 * 1024 * 1024) {
                alert('La imagen no puede superar 5MB');
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
                    aspectRatio: ASPECT,
                    viewMode: 2,
                    dragMode: 'move',
                    autoCropArea: 1,
                    restore: false,
                    guides: true,
                    center: true,
                    highlight: true,
                    cropBoxMovable: true,
                    cropBoxResizable: true,
                    toggleDragModeOnDblclick: false,
                    background: true,
                });
            };
            this.value = '';
        });

        document.getElementById('btnCropConfirm').addEventListener('click', function () {
            if (!cropper) return;

            const canvas = cropper.getCroppedCanvas({ width: 1200, height: 600 });
            const mime   = currentExt === 'png' ? 'image/png' : 'image/jpeg';
            const b64    = canvas.toDataURL(mime, 0.92);

            inputCropped.value = b64;
            inputExt.value     = currentExt;

            previewImg.src = b64;
            previewBox.classList.add('visible');

            // Ocultar imagen actual si existe
            if (currentImg) currentImg.closest('.current-image-wrap').style.display = 'none';

            uploadLabel.classList.add('has-file');
            uploadText.textContent = 'Imagen lista — clic para cambiar';

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