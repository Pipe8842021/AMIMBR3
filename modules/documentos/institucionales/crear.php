<?php
/**
 * Crear Nuevo Documento Institucional - Endpoint puro
 * (Certificados, Comunicados, Actas)
 */

require_once '../../../config/session.php';
require_once '../../../config/database.php';
require_once '../../../includes/auth_check.php';

require_role('admin');

$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id, nombre, email, rol FROM usuarios WHERE id = ? AND estado = 'activo'");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        session_destroy();
        if ($is_ajax) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'error' => 'Sesión inválida']); exit; }
        header("Location: ../../../auth/login.php"); exit;
    }
} catch (PDOException $e) {
    if ($is_ajax) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'error' => 'Error del sistema']); exit; }
    die("Error del sistema");
}

$error = '';
$tipo_documento  = $_POST['tipo_documento']  ?? '';
$titulo          = trim($_POST['titulo']      ?? '');
$descripcion     = trim($_POST['descripcion'] ?? '');
$fecha_documento = $_POST['fecha_documento']  ?? date('Y-m-d');

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
    } elseif ($archivo['size'] > 10 * 1024 * 1024) {
        $error = 'El archivo no puede superar 10MB';
    } else {
        try {
            if ($tipo_documento === 'certificado') {
                $curso_id           = isset($_POST['curso_id'])           ? (int)$_POST['curso_id']           : 0;
                $grupo_id           = isset($_POST['grupo_id'])           ? (int)$_POST['grupo_id']           : 0;
                $estudiante_id      = isset($_POST['estudiante_id'])      ? (int)$_POST['estudiante_id']      : 0;
                $nivel_aprobado     = $_POST['nivel_aprobado']            ?? '';
                $calificacion_final = isset($_POST['calificacion_final']) ? (float)$_POST['calificacion_final'] : 0;
                $fecha_inicio       = $_POST['fecha_inicio_curso']        ?? '';
                $fecha_fin          = $_POST['fecha_fin_curso']           ?? '';

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
                    $stmt = $pdo->prepare("SELECT id FROM matriculas WHERE estudiante_id = ? AND grupo_id = ? AND estado = 'activa' LIMIT 1");
                    $stmt->execute([$estudiante_id, $grupo_id]);
                    $matricula = $stmt->fetch();

                    if (!$matricula) {
                        $error = 'El estudiante no está matriculado en este grupo';
                    } else {
                        $stmt = $pdo->prepare("SELECT id, estado FROM calificaciones_certificados WHERE estudiante_id = ? AND curso_id = ? AND grupo_id = ?");
                        $stmt->execute([$estudiante_id, $curso_id, $grupo_id]);
                        $certificado_existente = $stmt->fetch(PDO::FETCH_ASSOC);

                        if (!$certificado_existente) {
                            $year        = date('Y');
                            $stmt_cnt    = $pdo->query("SELECT COUNT(*) as total FROM calificaciones_certificados WHERE YEAR(fecha_aprobacion) = $year");
                            $consecutivo = $stmt_cnt->fetch()['total'] + 1;
                            $codigo_certificado = sprintf("AMB-%s-%04d-%03d-%03d", $year, $consecutivo, $estudiante_id, $curso_id);
                        }

                        $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/assets/uploads/documentos/certificados/';
                        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

                        $nombre_archivo = uniqid() . '_certificado_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $archivo['name']);
                        $ruta_archivo   = $upload_dir . $nombre_archivo;

                        if (move_uploaded_file($archivo['tmp_name'], $ruta_archivo)) {
                            if ($certificado_existente) {
                                $stmt = $pdo->prepare("
                                    UPDATE calificaciones_certificados SET
                                        nivel_aprobado = ?, calificacion_final = ?,
                                        fecha_inicio_curso = ?, fecha_fin_curso = ?,
                                        fecha_aprobacion = ?, aprobado_por = ?,
                                        ruta_pdf = ?, estado = 'aprobado', fecha_generacion = NOW()
                                    WHERE id = ?
                                ");
                                $stmt->execute([
                                    $nivel_aprobado, $calificacion_final,
                                    $fecha_inicio, $fecha_fin,
                                    $fecha_documento, $user['id'],
                                    $ruta_archivo, $certificado_existente['id']
                                ]);
                                $success_key = 'certificado_actualizado';
                            } else {
                                $stmt = $pdo->prepare("
                                    INSERT INTO calificaciones_certificados (
                                        estudiante_id, curso_id, grupo_id, matricula_id,
                                        nivel_aprobado, calificacion_final,
                                        fecha_inicio_curso, fecha_fin_curso, fecha_aprobacion,
                                        aprobado_por, codigo_certificado, ruta_pdf, estado
                                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'aprobado')
                                ");
                                $stmt->execute([
                                    $estudiante_id, $curso_id, $grupo_id, $matricula['id'],
                                    $nivel_aprobado, $calificacion_final,
                                    $fecha_inicio, $fecha_fin,
                                    $fecha_documento, $user['id'],
                                    $codigo_certificado, $ruta_archivo
                                ]);
                                $success_key = 'certificado_creado';
                            }

                            if ($is_ajax) { header('Content-Type: application/json'); echo json_encode(['success' => true, 'message' => $success_key]); exit; }
                            header("Location: index.php?success=$success_key"); exit;
                        } else {
                            $error = 'Error al guardar el archivo';
                        }
                    }
                }

            } elseif ($tipo_documento === 'comunicado') {
                $categoria  = $_POST['categoria_comunicado'] ?? 'general';
                $prioridad  = $_POST['prioridad']            ?? 'normal';
                $dirigido_a = $_POST['dirigido_a']           ?? 'todos';

                $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/assets/uploads/documentos/comunicados/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

                $extension      = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
                $tipo_archivo   = ($extension === 'pdf') ? 'pdf' : 'otro';
                $nombre_archivo = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $archivo['name']);
                $ruta_archivo   = $upload_dir . $nombre_archivo;

                if (move_uploaded_file($archivo['tmp_name'], $ruta_archivo)) {
                    $pdo->exec("CREATE TABLE IF NOT EXISTS documentos_comunicados (
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
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

                    $stmt = $pdo->prepare("
                        INSERT INTO documentos_comunicados (
                            titulo, descripcion, categoria, prioridad, dirigido_a,
                            tipo_archivo, nombre_archivo, ruta_archivo, tamanio_archivo,
                            fecha_publicacion, publicado_por
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $titulo, $descripcion, $categoria, $prioridad, $dirigido_a,
                        $tipo_archivo, $archivo['name'], $ruta_archivo, $archivo['size'],
                        $fecha_documento, $user['id']
                    ]);

                    if ($is_ajax) { header('Content-Type: application/json'); echo json_encode(['success' => true, 'message' => 'comunicado_creado']); exit; }
                    header("Location: index.php?success=comunicado_creado"); exit;
                } else {
                    $error = 'Error al guardar el archivo';
                }

            } elseif ($tipo_documento === 'acta') {
                $tipo_reunion = $_POST['tipo_reunion']     ?? '';
                $lugar        = trim($_POST['lugar']       ?? '');
                $asistentes   = trim($_POST['asistentes']  ?? '');
                $visibilidad  = $_POST['visibilidad_acta'] ?? 'solo_admin';

                if (empty($tipo_reunion)) {
                    $error = 'Debes especificar el tipo de reunión';
                } else {
                    $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/assets/uploads/documentos/actas/';
                    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

                    $extension      = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
                    $tipo_archivo   = ($extension === 'pdf') ? 'pdf' : 'otro';
                    $nombre_archivo = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $archivo['name']);
                    $ruta_archivo   = $upload_dir . $nombre_archivo;

                    if (move_uploaded_file($archivo['tmp_name'], $ruta_archivo)) {
                        $stmt_col   = $pdo->query("SHOW COLUMNS FROM documentos_actas LIKE 'visibilidad'");
                        $col_exists = $stmt_col->rowCount() > 0;

                        if ($col_exists) {
                            $stmt = $pdo->prepare("
                                INSERT INTO documentos_actas (
                                    titulo, descripcion, tipo_reunion, lugar, asistentes,
                                    visibilidad, tipo_archivo, nombre_archivo, ruta_archivo,
                                    tamanio_archivo, fecha_reunion, creado_por
                                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([
                                $titulo, $descripcion, $tipo_reunion, $lugar, $asistentes,
                                $visibilidad, $tipo_archivo, $archivo['name'], $ruta_archivo,
                                $archivo['size'], $fecha_documento, $user['id']
                            ]);
                        } else {
                            $stmt = $pdo->prepare("
                                INSERT INTO documentos_actas (
                                    titulo, descripcion, tipo_reunion, lugar, asistentes,
                                    tipo_archivo, nombre_archivo, ruta_archivo,
                                    tamanio_archivo, fecha_reunion, creado_por
                                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([
                                $titulo, $descripcion, $tipo_reunion, $lugar, $asistentes,
                                $tipo_archivo, $archivo['name'], $ruta_archivo,
                                $archivo['size'], $fecha_documento, $user['id']
                            ]);
                        }

                        if ($is_ajax) { header('Content-Type: application/json'); echo json_encode(['success' => true, 'message' => 'acta_creada']); exit; }
                        header("Location: index.php?success=acta_creada"); exit;
                    } else {
                        $error = 'Error al guardar el archivo';
                    }
                }
            } else {
                $error = 'Tipo de documento inválido';
            }

        } catch (PDOException $e) {
            error_log("Error al crear documento institucional: " . $e->getMessage());
            $error = 'Error al guardar en la base de datos';
        }
    }
}

if ($is_ajax) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $error ?: 'Error desconocido']);
    exit;
}
header('Location: index.php');
exit;
