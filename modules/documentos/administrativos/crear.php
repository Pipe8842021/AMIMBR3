<?php
/**
 * Endpoint para crear un documento administrativo.
 * Solo acepta POST vía AJAX; redirige cualquier otra petición al índice.
 */

require_once '../../../config/session.php';
require_once '../../../config/database.php';
require_once '../../../includes/auth_check.php';

require_role('admin');

// Solo acepta POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// Obtener datos del usuario actual
try {
    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE id = ? AND estado = 'activo'");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        session_destroy();
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Sesión inválida']);
            exit;
        }
        header('Location: ../../../auth/login.php');
        exit;
    }
} catch (PDOException $e) {
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Error del sistema']);
        exit;
    }
    die('Error del sistema');
}

// Leer campos del formulario
$titulo               = trim($_POST['titulo'] ?? '');
$categoria            = $_POST['categoria'] ?? '';
$descripcion          = trim($_POST['descripcion'] ?? '');
$visibilidad          = $_POST['visibilidad'] ?? '';
$profesor_especifico_id = $_POST['profesor_especifico_id'] ?? null;

$error = '';

// Validaciones
if (empty($titulo)) {
    $error = 'El título es obligatorio';
} elseif (empty($categoria)) {
    $error = 'La categoría es obligatoria';
} elseif (empty($visibilidad)) {
    $error = 'La visibilidad es obligatoria';
} elseif ($visibilidad === 'profesor_especifico' && empty($profesor_especifico_id)) {
    $error = 'Debes seleccionar un profesor';
} elseif (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] === UPLOAD_ERR_NO_FILE) {
    $error = 'Debes subir un archivo';
} else {
    $archivo = $_FILES['archivo'];

    if ($archivo['error'] !== UPLOAD_ERR_OK) {
        $error = 'Error al subir el archivo';
    } elseif ($archivo['size'] > 10 * 1024 * 1024) {
        $error = 'El archivo no puede superar 10 MB';
    } else {
        // Determinar tipo de archivo
        $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
        $tipo_map  = [
            'pdf'  => ['pdf'],
            'excel' => ['xls', 'xlsx', 'xlsm', 'csv'],
            'word'  => ['doc', 'docx'],
            'imagen' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
        ];
        $tipo_archivo = 'otro';
        foreach ($tipo_map as $tipo => $exts) {
            if (in_array($extension, $exts)) {
                $tipo_archivo = $tipo;
                break;
            }
        }

        // Crear directorio si no existe
        $upload_dir = '../../../assets/uploads/documentos/administrativos/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        $nombre_archivo = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $archivo['name']);
        $ruta_archivo   = $upload_dir . $nombre_archivo;

        if (!move_uploaded_file($archivo['tmp_name'], $ruta_archivo)) {
            $error = 'Error al mover el archivo al servidor';
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO documentos_administrativos
                        (titulo, categoria, descripcion, tipo_archivo, nombre_archivo,
                         ruta_archivo, tamanio_archivo, visibilidad, profesor_especifico_id, subido_por)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $titulo,
                    $categoria,
                    $descripcion,
                    $tipo_archivo,
                    $archivo['name'],
                    $ruta_archivo,
                    $archivo['size'],
                    $visibilidad,
                    $visibilidad === 'profesor_especifico' ? $profesor_especifico_id : null,
                    $user['id'],
                ]);

                if ($is_ajax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true]);
                    exit;
                }
                header('Location: index.php?success=documento_creado');
                exit;

            } catch (PDOException $e) {
                error_log('Error al guardar documento: ' . $e->getMessage());
                unlink($ruta_archivo);
                $error = 'Error al guardar el documento en la base de datos';
            }
        }
    }
}

// Respuesta de error
if ($is_ajax) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $error]);
    exit;
}

header('Location: index.php');
exit;
