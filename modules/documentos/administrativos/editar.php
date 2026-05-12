<?php
/**
 * Endpoint para editar un documento administrativo.
 * Solo acepta POST vía AJAX; redirige cualquier otra petición al índice.
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

// Obtener usuario actual
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

// Leer campos
$doc_id               = (int)($_POST['doc_id'] ?? 0);
$titulo               = trim($_POST['titulo'] ?? '');
$categoria            = $_POST['categoria'] ?? '';
$descripcion          = trim($_POST['descripcion'] ?? '');
$visibilidad          = $_POST['visibilidad'] ?? '';
$profesor_especifico_id = $_POST['profesor_especifico_id'] ?? null;
$reemplazar_archivo   = isset($_FILES['archivo']) && $_FILES['archivo']['error'] !== UPLOAD_ERR_NO_FILE;

$error = '';

// Validaciones básicas
if ($doc_id === 0) {
    $error = 'Documento no válido';
} elseif (empty($titulo)) {
    $error = 'El título es obligatorio';
} elseif (empty($categoria)) {
    $error = 'La categoría es obligatoria';
} elseif (empty($visibilidad)) {
    $error = 'La visibilidad es obligatoria';
} elseif ($visibilidad === 'profesor_especifico' && empty($profesor_especifico_id)) {
    $error = 'Debes seleccionar un profesor';
}

if (empty($error)) {
    // Verificar que el documento existe
    try {
        $stmt = $pdo->prepare("SELECT * FROM documentos_administrativos WHERE id = ? AND estado = 'activo'");
        $stmt->execute([$doc_id]);
        $documento = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$documento) {
            $error = 'Documento no encontrado';
        }
    } catch (PDOException $e) {
        $error = 'Error al buscar el documento';
    }
}

if (empty($error)) {
    $datos = [
        'titulo'               => $titulo,
        'categoria'            => $categoria,
        'descripcion'          => $descripcion,
        'visibilidad'          => $visibilidad,
        'profesor_especifico_id' => $visibilidad === 'profesor_especifico' ? $profesor_especifico_id : null,
    ];

    // Procesar nuevo archivo si se subió
    if ($reemplazar_archivo) {
        $archivo = $_FILES['archivo'];

        if ($archivo['error'] !== UPLOAD_ERR_OK) {
            $error = 'Error al subir el archivo';
        } elseif ($archivo['size'] > 10 * 1024 * 1024) {
            $error = 'El archivo no puede superar 10 MB';
        } else {
            $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
            $tipo_map  = [
                'pdf'    => ['pdf'],
                'excel'  => ['xls', 'xlsx', 'xlsm', 'csv'],
                'word'   => ['doc', 'docx'],
                'imagen' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
            ];
            $tipo_archivo = 'otro';
            foreach ($tipo_map as $tipo => $exts) {
                if (in_array($extension, $exts)) {
                    $tipo_archivo = $tipo;
                    break;
                }
            }

            $upload_dir = '../../../assets/uploads/documentos/administrativos/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            $nombre_archivo = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $archivo['name']);
            $ruta_archivo   = $upload_dir . $nombre_archivo;

            if (!move_uploaded_file($archivo['tmp_name'], $ruta_archivo)) {
                $error = 'Error al mover el archivo al servidor';
            } else {
                // Eliminar archivo anterior
                if (!empty($documento['ruta_archivo']) && file_exists($documento['ruta_archivo'])) {
                    unlink($documento['ruta_archivo']);
                }

                $datos['tipo_archivo']    = $tipo_archivo;
                $datos['nombre_archivo']  = $archivo['name'];
                $datos['ruta_archivo']    = $ruta_archivo;
                $datos['tamanio_archivo'] = $archivo['size'];
            }
        }
    }
}

// Actualizar en base de datos
if (empty($error)) {
    try {
        $campos  = array_map(fn($c) => "$c = ?", array_keys($datos));
        $valores = array_values($datos);
        $valores[] = $doc_id;

        $stmt = $pdo->prepare(
            "UPDATE documentos_administrativos SET " . implode(', ', $campos) . " WHERE id = ?"
        );
        $stmt->execute($valores);

        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;
        }
        header("Location: index.php?success=documento_actualizado");
        exit;

    } catch (PDOException $e) {
        error_log('Error al actualizar documento: ' . $e->getMessage());
        // Revertir archivo nuevo si la BD falló
        if ($reemplazar_archivo && isset($ruta_archivo) && file_exists($ruta_archivo)) {
            unlink($ruta_archivo);
        }
        $error = 'Error al actualizar el documento en la base de datos';
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
