<?php
/**
 * Editar Documento Administrativo
 */

require_once '../../../config/session.php';
require_once '../../../config/database.php';
require_once '../../../includes/auth_check.php';

// Solo administradores pueden editar
require_role('admin');

// Obtener datos del usuario actual
try {
    $stmt = $pdo->prepare("SELECT id, nombre, email, rol FROM usuarios WHERE id = ? AND estado = 'activo'");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        session_destroy();
        header("Location: ../../../auth/login.php");
        exit;
    }
} catch (PDOException $e) {
    die("Error del sistema");
}

// Obtener ID del documento
$doc_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($doc_id === 0) {
    header("Location: index.php?error=documento_no_encontrado");
    exit;
}

// Obtener documento
try {
    $stmt = $pdo->prepare("SELECT * FROM documentos_administrativos WHERE id = ? AND estado = 'activo'");
    $stmt->execute([$doc_id]);
    $documento = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$documento) {
        header("Location: index.php?error=documento_no_encontrado");
        exit;
    }
} catch (PDOException $e) {
    die("Error del sistema");
}

// Obtener lista de profesores
try {
    $stmt = $pdo->query("SELECT id, nombre FROM usuarios WHERE rol = 'profesor' AND estado = 'activo' ORDER BY nombre");
    $profesores = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $profesores = [];
}

$error = '';
$success = '';

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $titulo = trim($_POST['titulo'] ?? '');
    $categoria = $_POST['categoria'] ?? '';
    $descripcion = trim($_POST['descripcion'] ?? '');
    $visibilidad = $_POST['visibilidad'] ?? '';
    $profesor_especifico_id = $_POST['profesor_especifico_id'] ?? null;
    $reemplazar_archivo = isset($_FILES['archivo']) && $_FILES['archivo']['error'] !== UPLOAD_ERR_NO_FILE;
    
    // Validaciones
    if (empty($titulo)) {
        $error = 'El título es obligatorio';
    } elseif (empty($categoria)) {
        $error = 'La categoría es obligatoria';
    } elseif (empty($visibilidad)) {
        $error = 'La visibilidad es obligatoria';
    } elseif ($visibilidad === 'profesor_especifico' && empty($profesor_especifico_id)) {
        $error = 'Debes seleccionar un profesor';
    } else {
        // Preparar datos para actualización
        $datos_actualizacion = [
            'titulo' => $titulo,
            'categoria' => $categoria,
            'descripcion' => $descripcion,
            'visibilidad' => $visibilidad,
            'profesor_especifico_id' => $visibilidad === 'profesor_especifico' ? $profesor_especifico_id : null
        ];
        
        // Si se va a reemplazar el archivo
        if ($reemplazar_archivo) {
            $archivo = $_FILES['archivo'];
            
            if ($archivo['error'] !== UPLOAD_ERR_OK) {
                $error = 'Error al subir el archivo';
            } else {
                // Validar tamaño (máximo 10MB)
                $max_size = 10 * 1024 * 1024;
                if ($archivo['size'] > $max_size) {
                    $error = 'El archivo no puede superar 10MB';
                } else {
                    // Determinar tipo de archivo
                    $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
                    $tipo_archivo = 'otro';
                    
                    if (in_array($extension, ['pdf'])) {
                        $tipo_archivo = 'pdf';
                    } elseif (in_array($extension, ['xls', 'xlsx', 'xlsm', 'csv'])) {
                        $tipo_archivo = 'excel';
                    } elseif (in_array($extension, ['doc', 'docx'])) {
                        $tipo_archivo = 'word';
                    } elseif (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                        $tipo_archivo = 'imagen';
                    }
                    
                    // Crear directorio si no existe
                    $upload_dir = '../../../assets/uploads/documentos/administrativos/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }
                    
                    // Generar nombre único
                    $nombre_archivo = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $archivo['name']);
                    $ruta_archivo = $upload_dir . $nombre_archivo;
                    
                    // Mover archivo
                    if (move_uploaded_file($archivo['tmp_name'], $ruta_archivo)) {
                        // Eliminar archivo anterior
                        if (file_exists($documento['ruta_archivo'])) {
                            unlink($documento['ruta_archivo']);
                        }
                        
                        // Actualizar datos del archivo
                        $datos_actualizacion['tipo_archivo'] = $tipo_archivo;
                        $datos_actualizacion['nombre_archivo'] = $archivo['name'];
                        $datos_actualizacion['ruta_archivo'] = $ruta_archivo;
                        $datos_actualizacion['tamanio_archivo'] = $archivo['size'];
                    } else {
                        $error = 'Error al mover el archivo al servidor';
                    }
                }
            }
        }
        
        // Si no hay errores, actualizar en base de datos
        if (empty($error)) {
            try {
                // Construir consulta SQL dinámicamente
                $campos = [];
                $valores = [];
                
                foreach ($datos_actualizacion as $campo => $valor) {
                    $campos[] = "$campo = ?";
                    $valores[] = $valor;
                }
                
                $valores[] = $doc_id; // Para el WHERE
                
                $sql = "UPDATE documentos_administrativos SET " . implode(', ', $campos) . " WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($valores);
                
                header("Location: ver.php?id=$doc_id&success=documento_actualizado");
                exit;
                
            } catch (PDOException $e) {
                error_log("Error al actualizar documento: " . $e->getMessage());
                $error = 'Error al actualizar el documento en la base de datos';
                
                // Si se subió un archivo nuevo y falló la actualización, eliminarlo
                if ($reemplazar_archivo && isset($ruta_archivo) && file_exists($ruta_archivo)) {
                    unlink($ruta_archivo);
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Documento - Amimbré</title>
    <link rel="shortcut icon" href="../../../assets/img/3.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0" />
    <link rel="stylesheet" href="../../../assets/css/colores.css">
    <link rel="stylesheet" href="../../../assets/css/style-documentos-administrativos.css">
    <style>
        .form-container {
            max-width: 800px;
            margin: 0 auto;
            background: var(--dark-bg);
            border-radius: 16px;
            padding: 32px;
            border: 1px solid var(--border-color);
        }

        .form-group {
            margin-bottom: 24px;
        }

        .form-label {
            display: block;
            color: var(--text-primary);
            font-size: 0.9rem;
            font-weight: 500;
            margin-bottom: 8px;
        }

        .form-label.required::after {
            content: '*';
            color: #ef4444;
            margin-left: 4px;
        }

        .form-input,
        .form-select,
        .form-textarea {
            width: 100%;
            background: var(--hover-bg);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 12px 16px;
            color: var(--text-primary);
            font-size: 0.95rem;
            transition: all 0.3s ease;
        }

        .form-input:focus,
        .form-select:focus,
        .form-textarea:focus {
            outline: none;
            border-color: var(--primary-blue);
            background: var(--dark-bg);
        }

        .form-textarea {
            resize: vertical;
            min-height: 100px;
        }

        .file-upload {
            position: relative;
            background: var(--hover-bg);
            border: 2px dashed var(--border-color);
            border-radius: 12px;
            padding: 32px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .file-upload:hover {
            border-color: var(--primary-blue);
            background: var(--dark-bg);
        }

        .file-upload input[type="file"] {
            position: absolute;
            opacity: 0;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            cursor: pointer;
        }

        .file-upload-icon {
            font-size: 48px;
            color: var(--primary-blue);
            margin-bottom: 12px;
        }

        .file-upload-text {
            color: var(--text-primary);
            font-size: 0.95rem;
            margin-bottom: 4px;
        }

        .file-upload-hint {
            color: var(--text-secondary);
            font-size: 0.8rem;
        }

        .current-file-info {
            background: var(--hover-bg);
            padding: 16px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            margin-bottom: 16px;
        }

        .current-file-name {
            color: var(--text-primary);
            font-weight: 500;
            margin-bottom: 4px;
        }

        .current-file-meta {
            color: var(--text-secondary);
            font-size: 0.85rem;
        }

        .form-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 32px;
            padding-top: 24px;
            border-top: 1px solid var(--border-color);
        }

        .btn-cancel {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            background: transparent;
            color: var(--text-primary);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            font-size: 0.95rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-cancel:hover {
            background: var(--hover-bg);
            border-color: var(--text-secondary);
            transform: translateY(-2px);
        }

        .alert {
            padding: 16px;
            border-radius: 10px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #ef4444;
        }

        .alert-success {
            background: var(--subtle-green);
            border: 1px solid var(--primary-green);
            color: var(--primary-green);
        }

        #profesorField {
            display: none;
        }

        .form-note {
            background: var(--subtle-yellow);
            border: 1px solid var(--primary-yellow);
            color: var(--primary-yellow);
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 0.85rem;
            margin-bottom: 16px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }
    </style>
</head>
<body>
    <?php require_once '../../../includes/header.php'; ?>

    <main class="main-content">
        <div class="page-header">
            <div class="header-left">
                <button class="btn-back" onclick="window.location.href='ver.php?id=<?php echo $doc_id; ?>'">
                    <span class="material-symbols-rounded">arrow_back</span>
                </button>
                <div class="header-info">
                    <h1>Editar Documento</h1>
                    <p>Modifica la información del documento</p>
                </div>
            </div>
        </div>

        <div class="form-container">
            <?php if ($error): ?>
            <div class="alert alert-error">
                <span class="material-symbols-rounded">error</span>
                <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label class="form-label required">Título del Documento</label>
                    <input type="text" name="titulo" class="form-input" 
                           placeholder="Ej: Informe Financiero Q4 2024" 
                           value="<?php echo htmlspecialchars($documento['titulo']); ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label required">Categoría</label>
                    <select name="categoria" class="form-select" required>
                        <option value="">Seleccionar categoría</option>
                        <option value="informes" <?php echo $documento['categoria'] === 'informes' ? 'selected' : ''; ?>>Informe</option>
                        <option value="facturas" <?php echo $documento['categoria'] === 'facturas' ? 'selected' : ''; ?>>Factura</option>
                        <option value="contratos" <?php echo $documento['categoria'] === 'contratos' ? 'selected' : ''; ?>>Contrato</option>
                        <option value="nominas" <?php echo $documento['categoria'] === 'nominas' ? 'selected' : ''; ?>>Nómina</option>
                        <option value="presupuestos" <?php echo $documento['categoria'] === 'presupuestos' ? 'selected' : ''; ?>>Presupuesto</option>
                        <option value="legal" <?php echo $documento['categoria'] === 'legal' ? 'selected' : ''; ?>>Legal</option>
                        <option value="otro" <?php echo $documento['categoria'] === 'otro' ? 'selected' : ''; ?>>Otro</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Descripción</label>
                    <textarea name="descripcion" class="form-textarea" 
                              placeholder="Describe el contenido del documento..."><?php echo htmlspecialchars($documento['descripcion'] ?? ''); ?></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label required">Visibilidad</label>
                    <select name="visibilidad" id="visibilidadSelect" class="form-select" required>
                        <option value="">Seleccionar visibilidad</option>
                        <option value="solo_admin" <?php echo $documento['visibilidad'] === 'solo_admin' ? 'selected' : ''; ?>>Solo Administradores</option>
                        <option value="profesores" <?php echo $documento['visibilidad'] === 'profesores' ? 'selected' : ''; ?>>Todos los Profesores</option>
                        <option value="profesor_especifico" <?php echo $documento['visibilidad'] === 'profesor_especifico' ? 'selected' : ''; ?>>Profesor Específico</option>
                        <option value="todos_excepto_estudiantes" <?php echo $documento['visibilidad'] === 'todos_excepto_estudiantes' ? 'selected' : ''; ?>>Todos excepto Estudiantes</option>
                    </select>
                </div>

                <div class="form-group" id="profesorField">
                    <label class="form-label required">Seleccionar Profesor</label>
                    <select name="profesor_especifico_id" class="form-select">
                        <option value="">Seleccionar profesor</option>
                        <?php foreach ($profesores as $profesor): ?>
                        <option value="<?php echo $profesor['id']; ?>" 
                                <?php echo $documento['profesor_especifico_id'] == $profesor['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($profesor['nombre']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Reemplazar Archivo (Opcional)</label>
                    
                    <div class="current-file-info">
                        <div class="current-file-name">
                            <span class="material-symbols-rounded" style="vertical-align: middle; font-size: 18px;">insert_drive_file</span>
                            Archivo actual: <?php echo htmlspecialchars($documento['nombre_archivo']); ?>
                        </div>
                        <div class="current-file-meta">
                            Tipo: <?php echo strtoupper($documento['tipo_archivo']); ?> • 
                            Tamaño: <?php 
                                $bytes = $documento['tamanio_archivo'];
                                if ($bytes >= 1048576) {
                                    echo number_format($bytes / 1048576, 2) . ' MB';
                                } elseif ($bytes >= 1024) {
                                    echo number_format($bytes / 1024, 2) . ' KB';
                                } else {
                                    echo $bytes . ' B';
                                }
                            ?>
                        </div>
                    </div>

                    <div class="form-note">
                        <span class="material-symbols-rounded" style="font-size: 20px;">info</span>
                        <div>Si no seleccionas un archivo, se mantendrá el archivo actual. Si subes uno nuevo, el anterior será reemplazado.</div>
                    </div>

                    <div class="file-upload" id="fileUpload">
                        <input type="file" name="archivo" id="archivoInput" accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.jpg,.jpeg,.png">
                        <span class="material-symbols-rounded file-upload-icon">cloud_upload</span>
                        <div class="file-upload-text">Arrastra y suelta o haz clic para seleccionar</div>
                        <div class="file-upload-hint">PDF, Word, Excel o Imágenes (máx. 10MB)</div>
                    </div>
                    <div id="fileName" style="margin-top: 12px; color: var(--primary-green); display: none;"></div>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn-cancel" onclick="window.location.href='ver.php?id=<?php echo $doc_id; ?>'">
                        <span class="material-symbols-rounded">close</span>
                        Cancelar
                    </button>
                    <button type="submit" class="btn-primary">
                        <span class="material-symbols-rounded">save</span>
                        Guardar Cambios
                    </button>
                </div>
            </form>
        </div>
    </main>

    <script>
        // Mostrar/ocultar campo de profesor específico
        document.getElementById('visibilidadSelect').addEventListener('change', function() {
            const profesorField = document.getElementById('profesorField');
            if (this.value === 'profesor_especifico') {
                profesorField.style.display = 'block';
            } else {
                profesorField.style.display = 'none';
            }
        });

        // Trigger al cargar si ya está seleccionado
        if (document.getElementById('visibilidadSelect').value === 'profesor_especifico') {
            document.getElementById('profesorField').style.display = 'block';
        }

        // Mostrar nombre de archivo seleccionado
        document.getElementById('archivoInput').addEventListener('change', function() {
            const fileName = document.getElementById('fileName');
            if (this.files.length > 0) {
                fileName.textContent = '✓ Nuevo archivo: ' + this.files[0].name;
                fileName.style.display = 'block';
            } else {
                fileName.style.display = 'none';
            }
        });

        // Drag and drop
        const fileUpload = document.getElementById('fileUpload');
        
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            fileUpload.addEventListener(eventName, preventDefaults, false);
        });

        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }

        ['dragenter', 'dragover'].forEach(eventName => {
            fileUpload.addEventListener(eventName, () => {
                fileUpload.style.borderColor = 'var(--primary-blue)';
                fileUpload.style.background = 'var(--dark-bg)';
            });
        });

        ['dragleave', 'drop'].forEach(eventName => {
            fileUpload.addEventListener(eventName, () => {
                fileUpload.style.borderColor = 'var(--border-color)';
                fileUpload.style.background = 'var(--hover-bg)';
            });
        });

        fileUpload.addEventListener('drop', (e) => {
            const dt = e.dataTransfer;
            const files = dt.files;
            document.getElementById('archivoInput').files = files;
            
            // Trigger change event
            const event = new Event('change');
            document.getElementById('archivoInput').dispatchEvent(event);
        });
    </script>
</body>
</html>