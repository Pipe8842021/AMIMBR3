<?php
/**
 * Crear Nuevo Curso
 * Sistema Amimbré
 */

require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth_check.php';
require_role('admin');

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
    
    // Procesar imagen
    $imagen = null;
    if (isset($_FILES['imagen']) && $_FILES['imagen']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        $filename = $_FILES['imagen']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if (in_array($ext, $allowed)) {
            // Verificar tamaño máximo (5MB)
            if ($_FILES['imagen']['size'] > 5 * 1024 * 1024) {
                $errores[] = "La imagen no puede superar 5MB";
            } else {
                $new_filename = uniqid('curso_') . '.' . $ext;
                $upload_dir = __DIR__ . '/../../assets/img/cursos/';
                
                // Crear directorio si no existe
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $upload_path = $upload_dir . $new_filename;
                
                if (move_uploaded_file($_FILES['imagen']['tmp_name'], $upload_path)) {
                    // Guardar SOLO el nombre del archivo en BD
                    // index.php construirá la ruta completa con $rutaImagenes
                    $imagen = $new_filename;
                } else {
                    $errores[] = "Error al subir la imagen. Verifique permisos del directorio.";
                }
            }
        } else {
            $errores[] = "Formato de imagen no permitido. Use JPG, PNG o WEBP";
        }
    }
    
    // Si no hay errores, insertar
    if (empty($errores)) {
        try {
            $sql = "INSERT INTO cursos (
                nombre, descripcion, duracion_meses, nivel, 
                cupo_maximo, precio_mensual, estado, requisitos, imagen
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $nombre, $descripcion, $duracion_meses, $nivel,
                $cupo_maximo, $precio_mensual, $estado, $requisitos, $imagen
            ]);
            
            header("Location: index.php?success=curso_creado");
            exit;
            
        } catch (PDOException $e) {
            error_log("Error al crear curso: " . $e->getMessage());
            $errores[] = "Error al guardar el curso. Intente nuevamente.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crear Nuevo Curso - Amimbré</title>
    <link rel="shortcut icon" href="../../assets/img/3.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0" />
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="../../assets/css/colores.css">
    <link rel="stylesheet" href="../../assets/css/style-cursos-form.css">
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
            <h1>Crear Nuevo Curso</h1>
            <p>Completa la información del curso</p>
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
                                value="<?php echo htmlspecialchars($_POST['nombre'] ?? ''); ?>"
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
                            ><?php echo htmlspecialchars($_POST['descripcion'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="nivel">
                                    Nivel <span class="required">*</span>
                                </label>
                                <select id="nivel" name="nivel" required>
                                    <option value="basico" <?php echo ($_POST['nivel'] ?? '') === 'basico' ? 'selected' : ''; ?>>Básico</option>
                                    <option value="intermedio" <?php echo ($_POST['nivel'] ?? '') === 'intermedio' ? 'selected' : ''; ?>>Intermedio</option>
                                    <option value="avanzado" <?php echo ($_POST['nivel'] ?? '') === 'avanzado' ? 'selected' : ''; ?>>Avanzado</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="estado">
                                    Estado <span class="required">*</span>
                                </label>
                                <select id="estado" name="estado" required>
                                    <option value="activo" <?php echo ($_POST['estado'] ?? 'activo') === 'activo' ? 'selected' : ''; ?>>Activo</option>
                                    <option value="inactivo" <?php echo ($_POST['estado'] ?? '') === 'inactivo' ? 'selected' : ''; ?>>Inactivo</option>
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
                            ><?php echo htmlspecialchars($_POST['requisitos'] ?? ''); ?></textarea>
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
                                value="<?php echo htmlspecialchars($_POST['duracion_meses'] ?? '12'); ?>"
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
                                value="<?php echo htmlspecialchars($_POST['cupo_maximo'] ?? '15'); ?>"
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
                                    value="<?php echo htmlspecialchars($_POST['precio_mensual'] ?? ''); ?>"
                                    placeholder="150000"
                                >
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="imagen">Imagen del Curso</label>
                            <div class="file-upload">
                                <input 
                                    type="file" 
                                    id="imagen" 
                                    name="imagen" 
                                    accept="image/jpeg,image/png,image/webp"
                                    onchange="previewImage(event)"
                                >
                                <label for="imagen" class="file-upload-label">
                                    <span class="material-symbols-rounded">upload</span>
                                    <span>Seleccionar imagen</span>
                                </label>
                                <small>JPG, PNG o WEBP. Máx. 5MB</small>
                            </div>
                            <div id="preview" class="image-preview"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <a href="index.php" class="btn-secundario">Cancelar</a>
                <button type="submit" class="btn-primario">
                    <span class="material-symbols-rounded">save</span>
                    Crear Curso
                </button>
            </div>
        </form>
    </main>

    <script>
        function previewImage(event) {
            const file = event.target.files[0];
            const preview = document.getElementById('preview');
            
            if (file) {
                // Validar tamaño antes de mostrar preview (5MB)
                if (file.size > 5 * 1024 * 1024) {
                    alert('La imagen no puede superar 5MB');
                    event.target.value = '';
                    preview.innerHTML = '';
                    return;
                }

                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.innerHTML = `<img src="${e.target.result}" alt="Preview">`;
                }
                reader.readAsDataURL(file);
            } else {
                preview.innerHTML = '';
            }
        }
    </script>
</body>
</html>