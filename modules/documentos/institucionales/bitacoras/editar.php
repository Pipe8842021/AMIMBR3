<?php
/**
 * Editar Bitácora Existente
 */

require_once '../../../../config/session.php';
require_once '../../../../config/database.php';
require_once '../../../../includes/auth_check.php';

// Solo admin y profesores pueden editar
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

// Obtener ID de la bitácora
$bitacora_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($bitacora_id === 0) {
    header("Location: ../index.php?error=bitacora_no_encontrada");
    exit;
}

// Obtener bitácora
try {
    $stmt = $pdo->prepare("
        SELECT b.*, g.profesor_id as grupo_profesor_id
        FROM bitacoras b
        INNER JOIN grupos g ON b.grupo_id = g.id
        WHERE b.id = ? AND b.estado = 'activo'
    ");
    $stmt->execute([$bitacora_id]);
    $bitacora = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$bitacora) {
        header("Location: ../index.php?error=bitacora_no_encontrada");
        exit;
    }
    
    // Verificar permisos - solo el profesor dueño o admin pueden editar
    if ($user['rol'] === 'profesor' && $bitacora['profesor_id'] != $user['id']) {
        header("Location: ver.php?id=$bitacora_id&error=sin_permisos");
        exit;
    }
    
    // Obtener asistencias existentes
    $stmt = $pdo->prepare("SELECT * FROM bitacoras_asistencias WHERE bitacora_id = ?");
    $stmt->execute([$bitacora_id]);
    $asistencias_existentes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Convertir a array asociativo para fácil acceso
    $asistencias_map = [];
    foreach ($asistencias_existentes as $asist) {
        $asistencias_map[$asist['estudiante_id']] = $asist;
    }
    
    // Obtener evidencias existentes
    $stmt = $pdo->prepare("SELECT * FROM bitacoras_evidencias WHERE bitacora_id = ? ORDER BY orden");
    $stmt->execute([$bitacora_id]);
    $evidencias_existentes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error: " . $e->getMessage());
    header("Location: ../index.php?error=error_sistema");
    exit;
}

// Obtener grupos y cursos
try {
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
        $stmt = $pdo->query("
            SELECT g.*, c.nombre as curso_nombre 
            FROM grupos g
            INNER JOIN cursos c ON g.curso_id = c.id
            WHERE g.estado = 'activo'
            ORDER BY c.nombre, g.nombre
        ");
    }
    $grupos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error: " . $e->getMessage());
    $grupos = [];
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
                // Verificar permisos
                if ($user['rol'] === 'profesor' && $grupo['profesor_id'] != $user['id']) {
                    $error = 'No tienes permiso para este grupo';
                } else {
                    // Actualizar bitácora
                    $stmt = $pdo->prepare("
                        UPDATE bitacoras SET
                            grupo_id = ?,
                            curso_id = ?,
                            titulo = ?,
                            fecha_clase = ?,
                            hora_inicio = ?,
                            hora_fin = ?,
                            temas_tratados = ?,
                            descripcion_clase = ?,
                            observaciones = ?,
                            compromisos_proxima_clase = ?,
                            fecha_modificacion = NOW()
                        WHERE id = ?
                    ");
                    
                    $stmt->execute([
                        $grupo_id,
                        $grupo['curso_id'],
                        $titulo,
                        $fecha_clase,
                        $hora_inicio,
                        $hora_fin,
                        $temas_tratados,
                        $descripcion_clase,
                        $observaciones,
                        $compromisos,
                        $bitacora_id
                    ]);
                    
                    // Actualizar asistencias
                    if (isset($_POST['asistencias']) && is_array($_POST['asistencias'])) {
                        // Eliminar asistencias existentes
                        $stmt = $pdo->prepare("DELETE FROM bitacoras_asistencias WHERE bitacora_id = ?");
                        $stmt->execute([$bitacora_id]);
                        
                        // Insertar nuevas
                        foreach ($_POST['asistencias'] as $estudiante_id => $estado) {
                            $obs = $_POST['asistencia_obs'][$estudiante_id] ?? '';
                            
                            $stmt = $pdo->prepare("
                                INSERT INTO bitacoras_asistencias (bitacora_id, estudiante_id, estado, observacion)
                                VALUES (?, ?, ?, ?)
                            ");
                            $stmt->execute([$bitacora_id, $estudiante_id, $estado, $obs]);
                        }
                    }
                    
                    // Procesar nuevas evidencias fotográficas
                    if (isset($_FILES['evidencias']) && !empty($_FILES['evidencias']['name'][0])) {
                        $upload_dir = '../../../../assets/uploads/bitacoras/evidencias/';
                        if (!is_dir($upload_dir)) {
                            mkdir($upload_dir, 0755, true);
                        }
                        
                        // Obtener el orden máximo actual
                        $stmt = $pdo->prepare("SELECT MAX(orden) as max_orden FROM bitacoras_evidencias WHERE bitacora_id = ?");
                        $stmt->execute([$bitacora_id]);
                        $max_orden = $stmt->fetch()['max_orden'] ?? -1;
                        $orden = $max_orden + 1;
                        
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
                    
                    // Eliminar evidencias marcadas para eliminar
                    if (isset($_POST['eliminar_evidencias']) && is_array($_POST['eliminar_evidencias'])) {
                        foreach ($_POST['eliminar_evidencias'] as $evidencia_id) {
                            // Obtener archivo para eliminarlo físicamente
                            $stmt = $pdo->prepare("SELECT ruta_archivo FROM bitacoras_evidencias WHERE id = ?");
                            $stmt->execute([$evidencia_id]);
                            $evidencia = $stmt->fetch();
                            
                            if ($evidencia && file_exists($evidencia['ruta_archivo'])) {
                                unlink($evidencia['ruta_archivo']);
                            }
                            
                            // Eliminar de la base de datos
                            $stmt = $pdo->prepare("DELETE FROM bitacoras_evidencias WHERE id = ?");
                            $stmt->execute([$evidencia_id]);
                        }
                    }
                    
                    header("Location: ver.php?id=$bitacora_id&success=bitacora_actualizada");
                    exit;
                }
            }
        } catch (PDOException $e) {
            error_log("Error al actualizar bitácora: " . $e->getMessage());
            $error = 'Error al guardar los cambios';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Bitácora - Amimbré</title>
    <link rel="shortcut icon" href="../../../../assets/img/3.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0" />
    <link rel="stylesheet" href="../../../../assets/css/colores.css">
    <link rel="stylesheet" href="../../../../assets/css/style-documentos-institucionales.css">
    <script>
        (function() {
            const theme = localStorage.getItem('amimbre-theme');
            if (theme === 'light') {
                document.documentElement.setAttribute('data-theme', 'light');
            }
        })();
    </script>
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

        .existing-evidences {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .evidence-item {
            position: relative;
            background: var(--hover-bg);
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid var(--border-color);
        }

        .evidence-item img {
            width: 100%;
            height: 150px;
            object-fit: cover;
        }

        .evidence-item .evidence-caption {
            padding: 8px;
            color: var(--text-secondary);
            font-size: 0.8rem;
        }

        .delete-evidence {
            position: absolute;
            top: 8px;
            right: 8px;
            width: 32px;
            height: 32px;
            background: rgba(239, 68, 68, 0.9);
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .delete-evidence:hover {
            background: #ef4444;
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
    </style>
</head>
<body>
    <?php require_once '../../../../includes/header.php'; ?>

    <main class="main-content">
        <div class="page-header">
            <div class="header-left">
                <button class="btn-back" onclick="window.location.href='ver.php?id=<?php echo $bitacora_id; ?>'">
                    <span class="material-symbols-rounded">arrow_back</span>
                </button>
                <div class="header-info">
                    <h1>Editar Bitácora</h1>
                    <p>Modifica la información de la clase</p>
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
                                        data-curso="<?php echo $grupo['curso_id']; ?>"
                                        <?php echo $grupo['id'] == $bitacora['grupo_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($grupo['curso_nombre'] . ' - ' . $grupo['nombre']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label required">Fecha de Clase</label>
                            <input type="date" name="fecha_clase" class="form-input" 
                                   value="<?php echo $bitacora['fecha_clase']; ?>" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Título de la Clase</label>
                        <input type="text" name="titulo" class="form-input" 
                               value="<?php echo htmlspecialchars($bitacora['titulo']); ?>" required>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label required">Hora Inicio</label>
                            <input type="time" name="hora_inicio" class="form-input" 
                                   value="<?php echo substr($bitacora['hora_inicio'], 0, 5); ?>" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label required">Hora Fin</label>
                            <input type="time" name="hora_fin" class="form-input" 
                                   value="<?php echo substr($bitacora['hora_fin'], 0, 5); ?>" required>
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
                               value="<?php echo htmlspecialchars($bitacora['temas_tratados']); ?>" required>
                        <small style="color: var(--text-secondary); font-size: 0.8rem;">Separa los temas con comas</small>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Descripción de la Clase</label>
                        <textarea name="descripcion_clase" class="form-textarea" required><?php echo htmlspecialchars($bitacora['descripcion_clase']); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Observaciones</label>
                        <textarea name="observaciones" class="form-textarea"><?php echo htmlspecialchars($bitacora['observaciones'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Compromisos para la Próxima Clase</label>
                        <textarea name="compromisos" class="form-textarea"><?php echo htmlspecialchars($bitacora['compromisos_proxima_clase'] ?? ''); ?></textarea>
                    </div>
                </div>

                <!-- Asistencia -->
                <div class="form-card" id="asistenciaCard">
                    <h2 class="form-section-title">
                        <span class="material-symbols-rounded">how_to_reg</span>
                        Registro de Asistencia
                    </h2>
                    <div id="studentsContainer" class="students-list"></div>
                </div>

                <!-- Evidencias Fotográficas Existentes -->
                <?php if (count($evidencias_existentes) > 0): ?>
                <div class="form-card">
                    <h2 class="form-section-title">
                        <span class="material-symbols-rounded">collections</span>
                        Evidencias Actuales
                    </h2>

                    <div class="existing-evidences">
                        <?php foreach ($evidencias_existentes as $evidencia): ?>
                        <div class="evidence-item">
                            <img src="../../../../assets/uploads/bitacoras/evidencias/<?php echo basename($evidencia['ruta_archivo']); ?>" 
                                 alt="Evidencia">
                            <button type="button" class="delete-evidence" 
                                    onclick="markForDeletion(<?php echo $evidencia['id']; ?>, this)">
                                <span class="material-symbols-rounded" style="font-size: 18px;">delete</span>
                            </button>
                            <?php if (!empty($evidencia['descripcion'])): ?>
                            <div class="evidence-caption">
                                <?php echo htmlspecialchars($evidencia['descripcion']); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Nuevas Evidencias Fotográficas -->
                <div class="form-card">
                    <h2 class="form-section-title">
                        <span class="material-symbols-rounded">add_photo_alternate</span>
                        Agregar Más Evidencias (Opcional)
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
                    <button type="button" class="btn-cancel" onclick="window.location.href='ver.php?id=<?php echo $bitacora_id; ?>'">
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
        // Array para evidencias marcadas para eliminar
        const evidenciasEliminar = [];

        // Marcar evidencia para eliminar
        function markForDeletion(evidenciaId, button) {
            if (confirm('¿Eliminar esta evidencia?')) {
                evidenciasEliminar.push(evidenciaId);
                button.closest('.evidence-item').style.opacity = '0.3';
                button.innerHTML = '<span class="material-symbols-rounded" style="font-size: 18px;">undo</span>';
                button.onclick = function() { unmarkForDeletion(evidenciaId, button); };
                
                // Agregar input hidden
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'eliminar_evidencias[]';
                input.value = evidenciaId;
                input.id = 'delete_' + evidenciaId;
                document.querySelector('form').appendChild(input);
            }
        }

        function unmarkForDeletion(evidenciaId, button) {
            const index = evidenciasEliminar.indexOf(evidenciaId);
            if (index > -1) {
                evidenciasEliminar.splice(index, 1);
            }
            button.closest('.evidence-item').style.opacity = '1';
            button.innerHTML = '<span class="material-symbols-rounded" style="font-size: 18px;">delete</span>';
            button.onclick = function() { markForDeletion(evidenciaId, button); };
            
            // Eliminar input hidden
            const input = document.getElementById('delete_' + evidenciaId);
            if (input) input.remove();
        }

        // Cargar estudiantes del grupo
        function loadStudents(grupoId) {
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
                        
                        const asistenciasExistentes = <?php echo json_encode($asistencias_map); ?>;
                        
                        data.estudiantes.forEach(est => {
                            const asistencia = asistenciasExistentes[est.id];
                            const estado = asistencia ? asistencia.estado : 'presente';
                            const obs = asistencia ? asistencia.observacion : '';
                            
                            container.innerHTML += `
                                <div class="student-item">
                                    <div class="student-name">${est.nombre}</div>
                                    <select name="asistencias[${est.id}]" class="attendance-select">
                                        <option value="presente" ${estado === 'presente' ? 'selected' : ''}>Presente</option>
                                        <option value="ausente" ${estado === 'ausente' ? 'selected' : ''}>Ausente</option>
                                        <option value="justificado" ${estado === 'justificado' ? 'selected' : ''}>Justificado</option>
                                        <option value="tardanza" ${estado === 'tardanza' ? 'selected' : ''}>Tardanza</option>
                                    </select>
                                    <input type="text" name="asistencia_obs[${est.id}]" 
                                           class="obs-input" placeholder="Observación (opcional)" value="${obs}">
                                </div>
                            `;
                        });
                        
                        document.getElementById('asistenciaCard').style.display = 'block';
                    }
                });
        }

        // Cargar estudiantes al inicio
        document.addEventListener('DOMContentLoaded', function() {
            const grupoSelect = document.getElementById('grupoSelect');
            loadStudents(grupoSelect.value);
            
            grupoSelect.addEventListener('change', function() {
                loadStudents(this.value);
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