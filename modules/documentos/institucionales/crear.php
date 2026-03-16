<?php
/**
 * Crear Nuevo Documento Institucional
 * (Certificados, Comunicados, Actas)
 */

require_once '../../../config/session.php';
require_once '../../../config/database.php';
require_once '../../../includes/auth_check.php';

// Solo admin puede subir documentos institucionales
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

// Obtener listas para selects
try {
    // Estudiantes
    $stmt = $pdo->query("SELECT id, nombre FROM usuarios WHERE rol = 'estudiante' AND estado = 'activo' ORDER BY nombre");
    $estudiantes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Cursos
    $stmt = $pdo->query("SELECT id, nombre FROM cursos WHERE estado = 'activo' ORDER BY nombre");
    $cursos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error: " . $e->getMessage());
    $estudiantes = [];
    $cursos = [];
}

$error = '';
$success = '';

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tipo_documento = $_POST['tipo_documento'] ?? '';
    $titulo = trim($_POST['titulo'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');
    $fecha_documento = $_POST['fecha_documento'] ?? date('Y-m-d');
    
    // Validaciones básicas
    if (empty($tipo_documento)) {
        $error = 'Debes seleccionar el tipo de documento';
    } elseif (empty($titulo)) {
        $error = 'El título es obligatorio';
    } elseif (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] === UPLOAD_ERR_NO_FILE) {
        $error = 'Debes seleccionar un archivo';
    } else {
        $archivo = $_FILES['archivo'];
        
        if ($archivo['error'] !== UPLOAD_ERR_OK) {
            $error = 'Error al subir el archivo';
        } else {
            // Validar tamaño (máximo 10MB)
            $max_size = 10 * 1024 * 1024;
            if ($archivo['size'] > $max_size) {
                $error = 'El archivo no puede superar 10MB';
            } else {
                try {
                    // Procesar según el tipo de documento
                    if ($tipo_documento === 'certificado') {
                        // CERTIFICADO
                        $curso_id = isset($_POST['curso_id']) ? (int)$_POST['curso_id'] : 0;
                        $grupo_id = isset($_POST['grupo_id']) ? (int)$_POST['grupo_id'] : 0;
                        $estudiante_id = isset($_POST['estudiante_id']) ? (int)$_POST['estudiante_id'] : 0;
                        $nivel_aprobado = $_POST['nivel_aprobado'] ?? '';
                        $calificacion_final = isset($_POST['calificacion_final']) ? (float)$_POST['calificacion_final'] : 0;
                        $fecha_inicio = $_POST['fecha_inicio_curso'] ?? '';
                        $fecha_fin = $_POST['fecha_fin_curso'] ?? '';
                        
                        if ($curso_id === 0) {
                            $error = 'Debes seleccionar un curso';
                        } elseif ($grupo_id === 0) {
                            $error = 'Debes seleccionar un grupo';
                        } elseif ($estudiante_id === 0) {
                            $error = 'Debes seleccionar un estudiante';
                        } elseif (empty($nivel_aprobado)) {
                            $error = 'Debes especificar el nivel aprobado';
                        } elseif ($calificacion_final <= 0) {
                            $error = 'Debes ingresar la calificación final';
                        } elseif (empty($fecha_inicio) || empty($fecha_fin)) {
                            $error = 'Debes especificar las fechas de inicio y fin del curso';
                        } else {
                            // Obtener matrícula del estudiante
                            $stmt = $pdo->prepare("
                                SELECT id FROM matriculas 
                                WHERE estudiante_id = ? AND grupo_id = ? AND estado = 'activa'
                                LIMIT 1
                            ");
                            $stmt->execute([$estudiante_id, $grupo_id]);
                            $matricula = $stmt->fetch();
                            
                            if (!$matricula) {
                                $error = 'El estudiante no está matriculado en este grupo';
                            } else {
                                // Verificar si ya existe un certificado para este estudiante/curso/grupo
                                $stmt = $pdo->prepare("
                                    SELECT id, estado FROM calificaciones_certificados 
                                    WHERE estudiante_id = ? AND curso_id = ? AND grupo_id = ?
                                ");
                                $stmt->execute([$estudiante_id, $curso_id, $grupo_id]);
                                $certificado_existente = $stmt->fetch(PDO::FETCH_ASSOC);
                                
                                // Generar código único para el certificado
                                if ($certificado_existente) {
                                    // Si existe, reutilizar el código
                                    $codigo_certificado = null; // No actualizar el código
                                } else {
                                    // Si no existe, generar nuevo código
                                    $year = date('Y');
                                    $stmt = $pdo->query("SELECT COUNT(*) as total FROM calificaciones_certificados WHERE YEAR(fecha_aprobacion) = $year");
                                    $consecutivo = $stmt->fetch()['total'] + 1;
                                    $codigo_certificado = sprintf("AMB-%s-%04d-%03d-%03d", $year, $consecutivo, $estudiante_id, $curso_id);
                                }
                                
                                // Guardar archivo
                                $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/assets/uploads/documentos/certificados/';
                                if (!is_dir($upload_dir)) {
                                    mkdir($upload_dir, 0755, true);
                                }
                                
                                $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
                                $nombre_archivo = uniqid() . '_certificado_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $archivo['name']);
                                $ruta_archivo = $upload_dir . $nombre_archivo;
                                
                                if (move_uploaded_file($archivo['tmp_name'], $ruta_archivo)) {
                                    if ($certificado_existente) {
                                        // ACTUALIZAR certificado existente
                                        $stmt = $pdo->prepare("
                                            UPDATE calificaciones_certificados SET
                                                nivel_aprobado = ?,
                                                calificacion_final = ?,
                                                fecha_inicio_curso = ?,
                                                fecha_fin_curso = ?,
                                                fecha_aprobacion = ?,
                                                aprobado_por = ?,
                                                ruta_pdf = ?,
                                                estado = 'aprobado',
                                                fecha_generacion = NOW()
                                            WHERE id = ?
                                        ");
                                        $stmt->execute([
                                            $nivel_aprobado,
                                            $calificacion_final,
                                            $fecha_inicio,
                                            $fecha_fin,
                                            $fecha_documento,
                                            $user['id'],
                                            $ruta_archivo,
                                            $certificado_existente['id']
                                        ]);
                                        
                                        $certificado_id = $certificado_existente['id'];
                                        
                                        // Eliminar archivo anterior si existe
                                        $stmt_old = $pdo->prepare("SELECT ruta_pdf FROM calificaciones_certificados WHERE id = ?");
                                        $stmt_old->execute([$certificado_id]);
                                        $old_cert = $stmt_old->fetch();
                                        if ($old_cert && !empty($old_cert['ruta_pdf']) && file_exists($old_cert['ruta_pdf'])) {
                                            unlink($old_cert['ruta_pdf']);
                                        }
                                    } else {
                                        // INSERTAR nuevo certificado
                                        $stmt = $pdo->prepare("
                                            INSERT INTO calificaciones_certificados (
                                                estudiante_id, curso_id, grupo_id, matricula_id,
                                                nivel_aprobado, calificacion_final, 
                                                fecha_inicio_curso, fecha_fin_curso, fecha_aprobacion,
                                                aprobado_por, codigo_certificado, ruta_pdf, estado
                                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'aprobado')
                                        ");
                                        $stmt->execute([
                                            $estudiante_id,
                                            $curso_id,
                                            $grupo_id,
                                            $matricula['id'],
                                            $nivel_aprobado,
                                            $calificacion_final,
                                            $fecha_inicio,
                                            $fecha_fin,
                                            $fecha_documento,
                                            $user['id'], // aprobado_por
                                            $codigo_certificado,
                                            $ruta_archivo
                                        ]);
                                        
                                        $certificado_id = $pdo->lastInsertId();
                                    }
                                    
                                    $mensaje = $certificado_existente ? 'certificado_actualizado' : 'certificado_creado';
                                    header("Location: index.php?success=$mensaje");
                                    exit;
                                } else {
                                    $error = 'Error al guardar el archivo';
                                }
                            }
                        }
                        
                    } elseif ($tipo_documento === 'comunicado') {
                        // COMUNICADO
                        $categoria = $_POST['categoria_comunicado'] ?? 'general';
                        $prioridad = $_POST['prioridad'] ?? 'normal';
                        $dirigido_a = $_POST['dirigido_a'] ?? 'todos';
                        
                        // Guardar archivo
                        $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/assets/uploads/documentos/comunicados/';
                        if (!is_dir($upload_dir)) {
                            mkdir($upload_dir, 0755, true);
                        }
                        
                        $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
                        $tipo_archivo = in_array($extension, ['pdf']) ? 'pdf' : 'otro';
                        $nombre_archivo = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $archivo['name']);
                        $ruta_archivo = $upload_dir . $nombre_archivo;
                        
                        if (move_uploaded_file($archivo['tmp_name'], $ruta_archivo)) {
                            // Crear tabla si no existe (por si acaso)
                            $pdo->exec("
                                CREATE TABLE IF NOT EXISTS documentos_comunicados (
                                    id INT AUTO_INCREMENT PRIMARY KEY,
                                    titulo VARCHAR(255) NOT NULL,
                                    descripcion TEXT,
                                    categoria VARCHAR(50) DEFAULT 'general',
                                    prioridad VARCHAR(20) DEFAULT 'normal',
                                    dirigido_a VARCHAR(50) DEFAULT 'todos',
                                    tipo_archivo VARCHAR(20),
                                    nombre_archivo VARCHAR(255),
                                    ruta_archivo VARCHAR(500),
                                    tamanio_archivo INT,
                                    fecha_publicacion DATE,
                                    publicado_por INT,
                                    estado VARCHAR(20) DEFAULT 'activo',
                                    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                                    FOREIGN KEY (publicado_por) REFERENCES usuarios(id)
                                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                            ");
                            
                            // Insertar comunicado
                            $stmt = $pdo->prepare("
                                INSERT INTO documentos_comunicados (
                                    titulo, descripcion, categoria, prioridad, dirigido_a,
                                    tipo_archivo, nombre_archivo, ruta_archivo, tamanio_archivo,
                                    fecha_publicacion, publicado_por
                                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([
                                $titulo,
                                $descripcion,
                                $categoria,
                                $prioridad,
                                $dirigido_a,
                                $tipo_archivo,
                                $archivo['name'],
                                $ruta_archivo,
                                $archivo['size'],
                                $fecha_documento,
                                $user['id']
                            ]);
                            
                            header("Location: index.php?success=comunicado_creado");
                            exit;
                        } else {
                            $error = 'Error al guardar el archivo';
                        }
                        
                    } elseif ($tipo_documento === 'acta') {
                        // ACTA
                        $tipo_reunion = $_POST['tipo_reunion'] ?? '';
                        $lugar = trim($_POST['lugar'] ?? '');
                        $asistentes = trim($_POST['asistentes'] ?? '');
                        $visibilidad = $_POST['visibilidad_acta'] ?? 'solo_admin';
                        
                        if (empty($tipo_reunion)) {
                            $error = 'Debes especificar el tipo de reunión';
                        } else {
                            // Guardar archivo
                            $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/assets/uploads/documentos/actas/';
                            if (!is_dir($upload_dir)) {
                                mkdir($upload_dir, 0755, true);
                            }
                            
                            $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
                            $tipo_archivo = in_array($extension, ['pdf']) ? 'pdf' : 'otro';
                            $nombre_archivo = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $archivo['name']);
                            $ruta_archivo = $upload_dir . $nombre_archivo;
                            
                            if (move_uploaded_file($archivo['tmp_name'], $ruta_archivo)) {
                                try {
                                    // Verificar si la columna visibilidad existe
                                    $stmt = $pdo->query("SHOW COLUMNS FROM documentos_actas LIKE 'visibilidad'");
                                    $column_exists = $stmt->rowCount() > 0;
                                    
                                    if ($column_exists) {
                                        // Insertar con visibilidad
                                        $stmt = $pdo->prepare("
                                            INSERT INTO documentos_actas (
                                                titulo, descripcion, tipo_reunion, lugar, asistentes,
                                                visibilidad, tipo_archivo, nombre_archivo, ruta_archivo, 
                                                tamanio_archivo, fecha_reunion, creado_por
                                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                                        ");
                                        $stmt->execute([
                                            $titulo,
                                            $descripcion,
                                            $tipo_reunion,
                                            $lugar,
                                            $asistentes,
                                            $visibilidad,
                                            $tipo_archivo,
                                            $archivo['name'],
                                            $ruta_archivo,
                                            $archivo['size'],
                                            $fecha_documento,
                                            $user['id']
                                        ]);
                                    } else {
                                        // Insertar sin visibilidad (para retrocompatibilidad)
                                        $stmt = $pdo->prepare("
                                            INSERT INTO documentos_actas (
                                                titulo, descripcion, tipo_reunion, lugar, asistentes,
                                                tipo_archivo, nombre_archivo, ruta_archivo, 
                                                tamanio_archivo, fecha_reunion, creado_por
                                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                                        ");
                                        $stmt->execute([
                                            $titulo,
                                            $descripcion,
                                            $tipo_reunion,
                                            $lugar,
                                            $asistentes,
                                            $tipo_archivo,
                                            $archivo['name'],
                                            $ruta_archivo,
                                            $archivo['size'],
                                            $fecha_documento,
                                            $user['id']
                                        ]);
                                    }
                                    
                                    header("Location: index.php?success=acta_creada");
                                    exit;
                                    
                                } catch (PDOException $e) {
                                    error_log("Error al guardar acta: " . $e->getMessage());
                                    // Eliminar archivo si falló la inserción
                                    if (file_exists($ruta_archivo)) {
                                        unlink($ruta_archivo);
                                    }
                                    $error = 'Error al guardar en la base de datos: ' . $e->getMessage();
                                }
                            } else {
                                $error = 'Error al guardar el archivo';
                            }
                        }
                    }
                    
                } catch (PDOException $e) {
                    error_log("Error al crear documento: " . $e->getMessage());
                    $error = 'Error al guardar en la base de datos';
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
    <title>Nuevo Archivo Institucional - Amimbré</title>
    <link rel="shortcut icon" href="../../../assets/img/3.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0" />
    <link rel="stylesheet" href="../../../assets/css/style-documentos-institucionales.css">
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
            max-width: 900px;
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

        .conditional-fields {
            display: none;
        }

        .conditional-fields.active {
            display: block;
        }

        #fileName {
            margin-top: 12px;
            color: var(--primary-green);
            display: none;
            text-align: center;
        }

        .info-box {
            background: var(--subtle-blue);
            border: 1px solid var(--primary-blue);
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 20px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        .info-box span {
            color: var(--primary-blue);
            font-size: 24px;
        }

        .info-box-content {
            flex: 1;
            color: var(--primary-blue);
            font-size: 0.9rem;
            line-height: 1.6;
        }
    </style>
</head>
<body>
    <?php require_once '../../../includes/header.php'; ?>

    <main class="main-content">
        <div class="page-header">
            <div class="header-left">
                <button class="btn-back" onclick="window.location.href='index.php'">
                    <span class="material-symbols-rounded">arrow_back</span>
                </button>
                <div class="header-info">
                    <h1>Nuevo Archivo Institucional</h1>
                    <p>Sube certificados, comunicados o actas</p>
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
                <!-- Selección de Tipo de Documento -->
                <div class="form-card">
                    <h2 class="form-section-title">
                        <span class="material-symbols-rounded">category</span>
                        Tipo de Documento
                    </h2>

                    <div class="info-box">
                        <span class="material-symbols-rounded">info</span>
                        <div class="info-box-content">
                            Selecciona el tipo de archivo institucional que deseas subir. Los campos del formulario cambiarán según tu selección.
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Tipo de Archivo</label>
                        <select name="tipo_documento" id="tipoDocumento" class="form-select" required>
                            <option value="">Seleccionar tipo</option>
                            <option value="certificado">📜 Certificado de Curso</option>
                            <option value="comunicado">📢 Comunicado Oficial</option>
                            <option value="acta">📋 Acta de Reunión</option>
                        </select>
                    </div>
                </div>

                <!-- Información Básica -->
                <div class="form-card">
                    <h2 class="form-section-title">
                        <span class="material-symbols-rounded">description</span>
                        Información General
                    </h2>

                    <div class="form-group">
                        <label class="form-label required">Título</label>
                        <input type="text" name="titulo" class="form-input" 
                               placeholder="Ej: Certificado de Piano Básico - Juan Pérez" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Descripción</label>
                        <textarea name="descripcion" class="form-textarea" 
                                  placeholder="Descripción adicional del documento..."></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Fecha del Documento</label>
                        <input type="date" name="fecha_documento" class="form-input" 
                               value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                </div>

                <!-- Campos Específicos para CERTIFICADO -->
                <div class="form-card conditional-fields" id="certificadoFields">
                    <h2 class="form-section-title">
                        <span class="material-symbols-rounded">workspace_premium</span>
                        Datos del Certificado
                    </h2>

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label required">Curso</label>
                            <select name="curso_id" id="cursoSelect" class="form-select">
                                <option value="">Seleccionar curso</option>
                                <?php foreach ($cursos as $curso): ?>
                                <option value="<?php echo $curso['id']; ?>">
                                    <?php echo htmlspecialchars($curso['nombre']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label required">Grupo</label>
                            <select name="grupo_id" id="grupoSelect" class="form-select">
                                <option value="">Primero selecciona un curso</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Estudiante</label>
                        <select name="estudiante_id" id="estudianteSelect" class="form-select">
                            <option value="">Primero selecciona un grupo</option>
                        </select>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label required">Nivel Aprobado</label>
                            <select name="nivel_aprobado" class="form-select">
                                <option value="">Seleccionar nivel</option>
                                <option value="basico">Básico</option>
                                <option value="intermedio">Intermedio</option>
                                <option value="avanzado">Avanzado</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label required">Calificación Final</label>
                            <input type="number" name="calificacion_final" class="form-input" 
                                   min="0" max="5" step="0.1" placeholder="Ej: 4.5">
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label required">Fecha Inicio Curso</label>
                            <input type="date" name="fecha_inicio_curso" class="form-input">
                        </div>

                        <div class="form-group">
                            <label class="form-label required">Fecha Fin Curso</label>
                            <input type="date" name="fecha_fin_curso" class="form-input">
                        </div>
                    </div>
                </div>

                <!-- Campos Específicos para COMUNICADO -->
                <div class="form-card conditional-fields" id="comunicadoFields">
                    <h2 class="form-section-title">
                        <span class="material-symbols-rounded">campaign</span>
                        Datos del Comunicado
                    </h2>

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Categoría</label>
                            <select name="categoria_comunicado" class="form-select">
                                <option value="general">General</option>
                                <option value="academico">Académico</option>
                                <option value="administrativo">Administrativo</option>
                                <option value="evento">Evento</option>
                                <option value="urgente">Urgente</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Prioridad</label>
                            <select name="prioridad" class="form-select">
                                <option value="normal">Normal</option>
                                <option value="alta">Alta</option>
                                <option value="urgente">Urgente</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Dirigido a</label>
                        <select name="dirigido_a" class="form-select">
                            <option value="todos">Todos</option>
                            <option value="estudiantes">Estudiantes</option>
                            <option value="profesores">Profesores</option>
                            <option value="padres">Padres de Familia</option>
                        </select>
                    </div>
                </div>

                <!-- Campos Específicos para ACTA -->
                <div class="form-card conditional-fields" id="actaFields">
                    <h2 class="form-section-title">
                        <span class="material-symbols-rounded">assignment</span>
                        Datos del Acta
                    </h2>

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label required">Tipo de Reunión</label>
                            <select name="tipo_reunion" class="form-select">
                                <option value="">Seleccionar tipo</option>
                                <option value="consejo_academico">Consejo Académico</option>
                                <option value="reunion_docentes">Reunión de Docentes</option>
                                <option value="reunion_padres">Reunión de Padres</option>
                                <option value="comite_directivo">Comité Directivo</option>
                                <option value="otra">Otra</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Lugar</label>
                            <input type="text" name="lugar" class="form-input" 
                                   placeholder="Ej: Sala de reuniones - Sede principal">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Asistentes</label>
                        <textarea name="asistentes" class="form-textarea" 
                                  placeholder="Lista de asistentes a la reunión..."></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Visibilidad del Acta</label>
                        <select name="visibilidad_acta" class="form-select">
                            <option value="solo_admin">Solo Administradores</option>
                            <option value="admin_profesores">Administradores y Profesores</option>
                            <option value="todos">Todos (Admin, Profesores y Estudiantes)</option>
                        </select>
                        <small style="color: var(--text-secondary); font-size: 0.8rem; display: block; margin-top: 6px;">
                            Define quién puede ver este acta en el sistema
                        </small>
                    </div>
                </div>

                <!-- Upload de Archivo -->
                <div class="form-card">
                    <h2 class="form-section-title">
                        <span class="material-symbols-rounded">upload_file</span>
                        Archivo del Documento
                    </h2>

                    <div class="file-upload-zone" id="uploadZone">
                        <input type="file" name="archivo" id="archivoInput" 
                               accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" required>
                        <span class="material-symbols-rounded upload-icon">cloud_upload</span>
                        <div class="upload-text">Arrastra el archivo aquí o haz clic para seleccionar</div>
                        <div class="upload-hint">PDF, Word o Imágenes (máx. 10MB)</div>
                    </div>
                    <div id="fileName"></div>
                </div>

                <!-- Acciones -->
                <div class="form-actions">
                    <button type="button" class="btn-cancel" onclick="window.location.href='index.php'">
                        <span class="material-symbols-rounded">close</span>
                        Cancelar
                    </button>
                    <button type="submit" class="btn-primary">
                        <span class="material-symbols-rounded">save</span>
                        Guardar Documento
                    </button>
                </div>
            </form>
        </div>
    </main>

    <script>
        // Mostrar campos según tipo de documento
        document.getElementById('tipoDocumento').addEventListener('change', function() {
            const tipo = this.value;
            
            // Ocultar todos los campos condicionales
            document.querySelectorAll('.conditional-fields').forEach(field => {
                field.classList.remove('active');
            });
            
            // Mostrar campos específicos
            if (tipo === 'certificado') {
                document.getElementById('certificadoFields').classList.add('active');
            } else if (tipo === 'comunicado') {
                document.getElementById('comunicadoFields').classList.add('active');
            } else if (tipo === 'acta') {
                document.getElementById('actaFields').classList.add('active');
            }
        });

        // CERTIFICADOS: Cargar grupos cuando se selecciona un curso
        document.getElementById('cursoSelect').addEventListener('change', function() {
            const cursoId = this.value;
            const grupoSelect = document.getElementById('grupoSelect');
            const estudianteSelect = document.getElementById('estudianteSelect');
            
            console.log('Curso seleccionado:', cursoId); // Debug
            
            // Limpiar selects dependientes
            grupoSelect.innerHTML = '<option value="">Seleccionar grupo</option>';
            estudianteSelect.innerHTML = '<option value="">Primero selecciona un grupo</option>';
            
            if (!cursoId) return;
            
            grupoSelect.innerHTML = '<option value="">Cargando grupos...</option>';
            
            // Cargar grupos del curso
            fetch(`get_grupos_curso.php?curso_id=${cursoId}`)
                .then(res => {
                    console.log('Response status grupos:', res.status); // Debug
                    if (!res.ok) {
                        throw new Error(`HTTP error! status: ${res.status}`);
                    }
                    return res.json();
                })
                .then(data => {
                    console.log('Grupos recibidos:', data); // Debug
                    grupoSelect.innerHTML = '<option value="">Seleccionar grupo</option>';
                    
                    if (data.error) {
                        console.error('Error del servidor:', data.error);
                        grupoSelect.innerHTML = `<option value="">Error: ${data.error}</option>`;
                        return;
                    }
                    
                    if (data.grupos && data.grupos.length > 0) {
                        data.grupos.forEach(grupo => {
                            grupoSelect.innerHTML += `<option value="${grupo.id}">${grupo.nombre}</option>`;
                        });
                    } else {
                        grupoSelect.innerHTML = '<option value="">No hay grupos disponibles</option>';
                    }
                })
                .catch(err => {
                    console.error('Error completo:', err);
                    grupoSelect.innerHTML = '<option value="">Error al cargar grupos</option>';
                    alert('Error al cargar grupos. Revisa la consola del navegador (F12) para más detalles.');
                });
        });

        // CERTIFICADOS: Cargar estudiantes cuando se selecciona un grupo
        document.getElementById('grupoSelect').addEventListener('change', function() {
            const grupoId = this.value;
            const estudianteSelect = document.getElementById('estudianteSelect');
            
            console.log('Grupo seleccionado:', grupoId); // Debug
            
            estudianteSelect.innerHTML = '<option value="">Cargando estudiantes...</option>';
            
            if (!grupoId) {
                estudianteSelect.innerHTML = '<option value="">Primero selecciona un grupo</option>';
                return;
            }
            
            // Cargar estudiantes del grupo
            fetch(`bitacoras/get_estudiantes.php?grupo_id=${grupoId}`)
                .then(res => {
                    console.log('Response status:', res.status); // Debug
                    if (!res.ok) {
                        throw new Error(`HTTP error! status: ${res.status}`);
                    }
                    return res.json();
                })
                .then(data => {
                    console.log('Datos recibidos:', data); // Debug
                    estudianteSelect.innerHTML = '<option value="">Seleccionar estudiante</option>';
                    
                    if (data.error) {
                        console.error('Error del servidor:', data.error);
                        estudianteSelect.innerHTML = `<option value="">Error: ${data.error}</option>`;
                        return;
                    }
                    
                    if (data.estudiantes && data.estudiantes.length > 0) {
                        data.estudiantes.forEach(est => {
                            estudianteSelect.innerHTML += `<option value="${est.id}">${est.nombre}</option>`;
                        });
                    } else {
                        estudianteSelect.innerHTML = '<option value="">No hay estudiantes matriculados</option>';
                    }
                })
                .catch(err => {
                    console.error('Error completo:', err);
                    estudianteSelect.innerHTML = '<option value="">Error al cargar estudiantes</option>';
                    alert('Error al cargar estudiantes. Revisa la consola del navegador (F12) para más detalles.');
                });
        });

        // Manejo de archivo
        const archivoInput = document.getElementById('archivoInput');
        const fileName = document.getElementById('fileName');
        const uploadZone = document.getElementById('uploadZone');

        archivoInput.addEventListener('change', function() {
            if (this.files.length > 0) {
                fileName.textContent = '✓ Archivo seleccionado: ' + this.files[0].name;
                fileName.style.display = 'block';
            } else {
                fileName.style.display = 'none';
            }
        });

        // Drag and drop
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            uploadZone.addEventListener(eventName, (e) => {
                e.preventDefault();
                e.stopPropagation();
            });
        });

        ['dragenter', 'dragover'].forEach(eventName => {
            uploadZone.addEventListener(eventName, () => {
                uploadZone.style.borderColor = 'var(--primary-blue)';
                uploadZone.style.background = 'var(--dark-bg)';
            });
        });

        ['dragleave', 'drop'].forEach(eventName => {
            uploadZone.addEventListener(eventName, () => {
                uploadZone.style.borderColor = 'var(--border-color)';
                uploadZone.style.background = 'var(--hover-bg)';
            });
        });

        uploadZone.addEventListener('drop', (e) => {
            const dt = e.dataTransfer;
            const files = dt.files;
            archivoInput.files = files;
            
            const event = new Event('change');
            archivoInput.dispatchEvent(event);
        });
    </script>
</body>
</html>