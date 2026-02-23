<?php
/**
 * Crear Nueva Bitácora
 */

require_once '../../../../config/session.php';
require_once '../../../../config/database.php';
require_once '../../../../includes/auth_check.php';

// Solo admin y profesores pueden crear bitácoras
if ($_SESSION['user_rol'] !== 'admin' && $_SESSION['user_rol'] !== 'profesor') {
    header("Location: ../index.php?error=sin_permisos");
    exit;
}

// Obtener datos del usuario actual
try {
    $stmt = $pdo->prepare("SELECT id, nombre, email, rol FROM usuarios WHERE id = ? AND estado = 'activo'");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        session_destroy();
        header("Location: ../../../../auth/login.php");
        exit;
    }
} catch (PDOException $e) {
    die("Error del sistema");
}

// Obtener grupos y cursos
try {
    // Si es profesor, solo obtener sus grupos
    if ($user['rol'] === 'profesor') {
        $stmt = $pdo->prepare("
            SELECT g.*, c.nombre as curso_nombre 
            FROM grupos g
            INNER JOIN cursos c ON g.curso_id = c.id
            WHERE g.profesor_id = ? AND g.estado = 'activo'
            ORDER BY c.nombre, g.nombre
        ");
        $stmt->execute([$user['id']]);
    } else {
        // Si es admin, obtener todos los grupos
        $stmt = $pdo->query("
            SELECT g.*, c.nombre as curso_nombre 
            FROM grupos g
            INNER JOIN cursos c ON g.curso_id = c.id
            WHERE g.estado = 'activo'
            ORDER BY c.nombre, g.nombre
        ");
    }
    $grupos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener cursos
    $stmt = $pdo->query("SELECT id, nombre FROM cursos WHERE estado = 'activo' ORDER BY nombre");
    $cursos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error: " . $e->getMessage());
    $grupos = [];
    $cursos = [];
}

$error = '';
$success = '';

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $grupo_id = isset($_POST['grupo_id']) ? (int)$_POST['grupo_id'] : 0;
    $titulo = trim($_POST['titulo'] ?? '');
    $fecha_clase = $_POST['fecha_clase'] ?? '';
    $hora_inicio = $_POST['hora_inicio'] ?? '';
    $hora_fin = $_POST['hora_fin'] ?? '';
    $temas_tratados = trim($_POST['temas_tratados'] ?? '');
    $descripcion_clase = trim($_POST['descripcion_clase'] ?? '');
    $observaciones = trim($_POST['observaciones'] ?? '');
    $compromisos = trim($_POST['compromisos'] ?? '');
    
    // Validaciones
    if ($grupo_id === 0) {
        $error = 'Debes seleccionar un grupo';
    } elseif (empty($titulo)) {
        $error = 'El título es obligatorio';
    } elseif (empty($fecha_clase)) {
        $error = 'La fecha de clase es obligatoria';
    } elseif (empty($hora_inicio) || empty($hora_fin)) {
        $error = 'Las horas de inicio y fin son obligatorias';
    } elseif (empty($temas_tratados)) {
        $error = 'Los temas tratados son obligatorios';
    } elseif (empty($descripcion_clase)) {
        $error = 'La descripción de la clase es obligatoria';
    } else {
        try {
            // Obtener información del grupo
            $stmt = $pdo->prepare("SELECT curso_id, profesor_id FROM grupos WHERE id = ?");
            $stmt->execute([$grupo_id]);
            $grupo = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$grupo) {
                $error = 'Grupo no encontrado';
            } else {
                // Verificar que el profesor tenga permiso
                if ($user['rol'] === 'profesor' && $grupo['profesor_id'] != $user['id']) {
                    $error = 'No tienes permiso para este grupo';
                } else {
                    // Insertar bitácora
                    $stmt = $pdo->prepare("
                        INSERT INTO bitacoras (
                            grupo_id, curso_id, profesor_id, titulo,
                            fecha_clase, hora_inicio, hora_fin,
                            temas_tratados, descripcion_clase, observaciones,
                            compromisos_proxima_clase
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    $profesor_id = $user['rol'] === 'profesor' ? $user['id'] : $grupo['profesor_id'];
                    
                    $stmt->execute([
                        $grupo_id,
                        $grupo['curso_id'],
                        $profesor_id,
                        $titulo,
                        $fecha_clase,
                        $hora_inicio,
                        $hora_fin,
                        $temas_tratados,
                        $descripcion_clase,
                        $observaciones,
                        $compromisos
                    ]);
                    
                    $bitacora_id = $pdo->lastInsertId();
                    
                    // Procesar asistencias si se enviaron
                    if (isset($_POST['asistencias']) && is_array($_POST['asistencias'])) {
                        foreach ($_POST['asistencias'] as $estudiante_id => $estado) {
                            $obs = $_POST['asistencia_obs'][$estudiante_id] ?? '';
                            
                            $stmt = $pdo->prepare("
                                INSERT INTO bitacoras_asistencias (bitacora_id, estudiante_id, estado, observacion)
                                VALUES (?, ?, ?, ?)
                            ");
                            $stmt->execute([$bitacora_id, $estudiante_id, $estado, $obs]);
                        }
                    }
                    
                    // Procesar evidencias fotográficas
                    if (isset($_FILES['evidencias']) && !empty($_FILES['evidencias']['name'][0])) {
                        $upload_dir = '../../../../assets/uploads/bitacoras/evidencias/';
                        if (!is_dir($upload_dir)) {
                            mkdir($upload_dir, 0755, true);
                        }
                        
                        $orden = 0;
                        foreach ($_FILES['evidencias']['tmp_name'] as $key => $tmp_name) {
                            if ($_FILES['evidencias']['error'][$key] === UPLOAD_ERR_OK) {
                                $extension = strtolower(pathinfo($_FILES['evidencias']['name'][$key], PATHINFO_EXTENSION));
                                
                                if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                                    $nombre_archivo = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $_FILES['evidencias']['name'][$key]);
                                    $ruta_archivo = $upload_dir . $nombre_archivo;
                                    
                                    if (move_uploaded_file($tmp_name, $ruta_archivo)) {
                                        $desc_evidencia = $_POST['evidencia_desc'][$key] ?? '';
                                        
                                        $stmt = $pdo->prepare("
                                            INSERT INTO bitacoras_evidencias (bitacora_id, nombre_archivo, ruta_archivo, descripcion, orden)
                                            VALUES (?, ?, ?, ?, ?)
                                        ");
                                        $stmt->execute([$bitacora_id, $_FILES['evidencias']['name'][$key], $ruta_archivo, $desc_evidencia, $orden]);
                                        $orden++;
                                    }
                                }
                            }
                        }
                    }
                    
                    header("Location: ver.php?id=$bitacora_id&success=bitacora_creada");
                    exit;
                }
            }
        } catch (PDOException $e) {
            error_log("Error al crear bitácora: " . $e->getMessage());
            $error = 'Error al guardar la bitácora';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nueva Bitácora - Amimbré</title>
    <link rel="shortcut icon" href="../../../../assets/img/3.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0" />
    <link rel="stylesheet" href="../../../../assets/css/colores.css">
    <link rel="stylesheet" href="../../../../assets/css/style-documentos-institucionales.css">
    <style>
        .form-container {
            max-width: 1000px;
            margin: 0 auto;
        }

        .form-card {
            background: var(--dark-bg);
            border-radius: 16px;
            padding: 32px;
            border: 1px solid var(--border-color);
            margin-bottom: 24px;
        }

        .form-section-title {
            color: var(--text-primary);
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 20px;
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
            font-family: "Poppins", sans-serif;
        }

        .file-upload-zone {
            background: var(--hover-bg);
            border: 2px dashed var(--border-color);
            border-radius: 12px;
            padding: 32px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }

        .file-upload-zone:hover {
            border-color: var(--primary-blue);
            background: var(--dark-bg);
        }

        .file-upload-zone input[type="file"] {
            position: absolute;
            opacity: 0;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            cursor: pointer;
        }

        .upload-icon {
            font-size: 48px;
            color: var(--primary-blue);
            margin-bottom: 12px;
        }

        .upload-text {
            color: var(--text-primary);
            font-size: 0.95rem;
            margin-bottom: 4px;
        }

        .upload-hint {
            color: var(--text-secondary);
            font-size: 0.8rem;
        }

        .form-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
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

        .students-list {
            display: grid;
            gap: 12px;
        }

        .student-item {
            background: var(--hover-bg);
            padding: 16px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            display: grid;
            grid-template-columns: 1fr auto auto;
            gap: 16px;
            align-items: center;
        }

        .student-name {
            color: var(--text-primary);
            font-weight: 500;
        }

        .attendance-select {
            background: var(--dark-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 8px 12px;
            color: var(--text-primary);
            font-size: 0.85rem;
            cursor: pointer;
        }

        .obs-input {
            background: var(--dark-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 8px 12px;
            color: var(--text-primary);
            font-size: 0.85rem;
            min-width: 200px;
        }

        #filesList {
            margin-top: 16px;
            display: grid;
            gap: 12px;
        }

        .file-item {
            background: var(--hover-bg);
            padding: 12px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .file-item span {
            flex: 1;
            color: var(--text-primary);
            font-size: 0.9rem;
        }

        .file-item button {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
            border: none;
            border-radius: 6px;
            padding: 6px 12px;
            cursor: pointer;
            font-size: 0.8rem;
        }
    </style>
</head>
<body>
    <?php require_once '../../../../includes/header.php'; ?>

    <main class="main-content">
        <div class="page-header">
            <div class="header-left">
                <button class="btn-back" onclick="window.location.href='../index.php'">
                    <span class="material-symbols-rounded">arrow_back</span>
                </button>
                <div class="header-info">
                    <h1>Nueva Bitácora</h1>
                    <p>Registra la clase y asistencia de estudiantes</p>
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
                <!-- Información Básica -->
                <div class="form-card">
                    <h2 class="form-section-title">
                        <span class="material-symbols-rounded">info</span>
                        Información Básica
                    </h2>

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label required">Grupo</label>
                            <select name="grupo_id" id="grupoSelect" class="form-select" required>
                                <option value="">Seleccionar grupo</option>
                                <?php foreach ($grupos as $grupo): ?>
                                <option value="<?php echo $grupo['id']; ?>" 
                                        data-curso="<?php echo $grupo['curso_id']; ?>">
                                    <?php echo htmlspecialchars($grupo['curso_nombre'] . ' - ' . $grupo['nombre']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label required">Fecha de Clase</label>
                            <input type="date" name="fecha_clase" class="form-input" 
                                    value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Título de la Clase</label>
                        <input type="text" name="titulo" class="form-input" 
                                placeholder="Ej: Introducción a escalas mayores" required>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label required">Hora Inicio</label>
                            <input type="time" name="hora_inicio" class="form-input" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label required">Hora Fin</label>
                            <input type="time" name="hora_fin" class="form-input" required>
                        </div>
                    </div>
                </div>

                <!-- Contenido de la Clase -->
                <div class="form-card">
                    <h2 class="form-section-title">
                        <span class="material-symbols-rounded">description</span>
                        Contenido de la Clase
                    </h2>

                    <div class="form-group">
                        <label class="form-label required">Temas Tratados</label>
                        <input type="text" name="temas_tratados" class="form-input" 
                                placeholder="Ej: escalas, arpegios, lectura" required>
                        <small style="color: var(--text-secondary); font-size: 0.8rem;">Separa los temas con comas</small>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Descripción de la Clase</label>
                        <textarea name="descripcion_clase" class="form-textarea" 
                                placeholder="Describe detalladamente lo que se trabajó en la clase..." required></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Observaciones</label>
                        <textarea name="observaciones" class="form-textarea" 
                                    placeholder="Observaciones generales sobre la clase..."></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Compromisos para la Próxima Clase</label>
                        <textarea name="compromisos" class="form-textarea" 
                                    placeholder="Tareas, ejercicios o temas para la próxima sesión..."></textarea>
                    </div>
                </div>

                <!-- Asistencia -->
                <div class="form-card" id="asistenciaCard" style="display: none;">
                    <h2 class="form-section-title">
                        <span class="material-symbols-rounded">how_to_reg</span>
                        Registro de Asistencia
                    </h2>
                    <div id="studentsContainer" class="students-list"></div>
                </div>

                <!-- Evidencias Fotográficas -->
                <div class="form-card">
                    <h2 class="form-section-title">
                        <span class="material-symbols-rounded">photo_camera</span>
                        Evidencias Fotográficas (Opcional)
                    </h2>

                    <div class="file-upload-zone" id="uploadZone">
                        <input type="file" name="evidencias[]" id="evidenciasInput" 
                                accept="image/*" multiple>
                        <span class="material-symbols-rounded upload-icon">add_photo_alternate</span>
                        <div class="upload-text">Arrastra fotos aquí o haz clic para seleccionar</div>
                        <div class="upload-hint">Imágenes JPG, PNG, GIF (máx. 5MB cada una)</div>
                    </div>
                    <div id="filesList"></div>
                </div>

                <!-- Acciones -->
                <div class="form-actions">
                    <button type="button" class="btn-cancel" onclick="window.location.href='../index.php'">
                        <span class="material-symbols-rounded">close</span>
                        Cancelar
                    </button>
                    <button type="submit" class="btn-primary">
                        <span class="material-symbols-rounded">save</span>
                        Guardar Bitácora
                    </button>
                </div>
            </form>
        </div>
    </main>

    <script>
        // Cargar estudiantes del grupo seleccionado
        document.getElementById('grupoSelect').addEventListener('change', function() {
            const grupoId = this.value;
            if (!grupoId) {
                document.getElementById('asistenciaCard').style.display = 'none';
                return;
            }

            fetch(`get_estudiantes.php?grupo_id=${grupoId}`)
                .then(res => res.json())
                .then(data => {
                    if (data.estudiantes && data.estudiantes.length > 0) {
                        const container = document.getElementById('studentsContainer');
                        container.innerHTML = '';
                        
                        data.estudiantes.forEach(est => {
                            container.innerHTML += `
                                <div class="student-item">
                                    <div class="student-name">${est.nombre}</div>
                                    <select name="asistencias[${est.id}]" class="attendance-select">
                                        <option value="presente">Presente</option>
                                        <option value="ausente">Ausente</option>
                                        <option value="justificado">Justificado</option>
                                        <option value="tardanza">Tardanza</option>
                                    </select>
                                    <input type="text" name="asistencia_obs[${est.id}]" 
                                            class="obs-input" placeholder="Observación (opcional)">
                                </div>
                            `;
                        });
                        
                        document.getElementById('asistenciaCard').style.display = 'block';
                    }
                });
        });

        // Manejo de archivos
        const evidenciasInput = document.getElementById('evidenciasInput');
        const filesList = document.getElementById('filesList');
        const uploadZone = document.getElementById('uploadZone');

        evidenciasInput.addEventListener('change', function() {
            displayFiles(this.files);
        });

        // Drag and drop
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            uploadZone.addEventListener(eventName, (e) => {
                e.preventDefault();
                e.stopPropagation();
            });
        });

        uploadZone.addEventListener('drop', (e) => {
            const dt = e.dataTransfer;
            evidenciasInput.files = dt.files;
            displayFiles(dt.files);
        });

        function displayFiles(files) {
            filesList.innerHTML = '';
            Array.from(files).forEach((file, index) => {
                const fileItem = document.createElement('div');
                fileItem.className = 'file-item';
                fileItem.innerHTML = `
                    <span class="material-symbols-rounded">image</span>
                    <span>${file.name}</span>
                    <input type="text" name="evidencia_desc[${index}]" 
                            placeholder="Descripción (opcional)" class="obs-input">
                `;
                filesList.appendChild(fileItem);
            });
        }
    </script>
</body>
</html>