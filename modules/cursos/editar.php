<?php

require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth_check.php';
require_role('admin');

$curso_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($curso_id <= 0) {
    header("Location: index.php?error=curso_no_encontrado");
    exit;
}

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    $nombre = trim($_POST['nombre']);
    $descripcion = trim($_POST['descripcion']);
    $duracion_meses = (int)$_POST['duracion_meses'];
    $nivel = $_POST['nivel'];
    $cupo_maximo = (int)$_POST['cupo_maximo'];
    $precio_mensual = (float)$_POST['precio_mensual'];
    $requisitos = trim($_POST['requisitos']);
    $estado = $_POST['estado'];

    $errores = [];

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

    $imagen = $curso['imagen'];
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
                if ($curso['imagen'] && file_exists($upload_dir . $curso['imagen'])) {
                    unlink($upload_dir . $curso['imagen']);
                }
                $imagen = $new_filename;
            } else {
                $errores[] = "Error al guardar la imagen. Verifique permisos del directorio.";
            }
        }
    }

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

            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true]);
                exit;
            }
            header("Location: index.php?success=curso_actualizado");
            exit;

        } catch (PDOException $e) {
            error_log("Error al actualizar curso: " . $e->getMessage());
            $errores[] = "Error al actualizar el curso. Intente nuevamente.";
        }
    }

    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'errores' => $errores]);
        exit;
    }
}

header("Location: index.php");
exit;
