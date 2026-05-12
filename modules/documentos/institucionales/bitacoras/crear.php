<?php
/**
 * Crear Nueva Bitácora – endpoint AJAX
 */

require_once '../../../../config/session.php';
require_once '../../../../config/database.php';
require_once '../../../../includes/auth_check.php';

header('Content-Type: application/json');

$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($_SESSION['user_rol'] !== 'admin' && $_SESSION['user_rol'] !== 'profesor') {
    echo json_encode(['success' => false, 'error' => 'Sin permisos']); exit;
}

try {
    $stmt = $pdo->prepare("SELECT id, nombre, rol FROM usuarios WHERE id = ? AND estado = 'activo'");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) { echo json_encode(['success' => false, 'error' => 'Sesión inválida']); exit; }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Error del sistema']); exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Método no permitido']); exit;
}

$grupo_id        = isset($_POST['grupo_id'])        ? (int)$_POST['grupo_id']        : 0;
$titulo          = trim($_POST['titulo']          ?? '');
$fecha_clase     = $_POST['fecha_clase']     ?? '';
$hora_inicio     = $_POST['hora_inicio']     ?? '';
$hora_fin        = $_POST['hora_fin']        ?? '';
$temas_tratados  = trim($_POST['temas_tratados']  ?? '');
$descripcion_clase = trim($_POST['descripcion_clase'] ?? '');
$observaciones   = trim($_POST['observaciones']   ?? '');
$compromisos     = trim($_POST['compromisos']     ?? '');

if ($grupo_id === 0)               { echo json_encode(['success' => false, 'error' => 'Debes seleccionar un grupo']); exit; }
if (empty($titulo))                { echo json_encode(['success' => false, 'error' => 'El título es obligatorio']); exit; }
if (empty($fecha_clase))           { echo json_encode(['success' => false, 'error' => 'La fecha de clase es obligatoria']); exit; }
if (empty($hora_inicio) || empty($hora_fin)) { echo json_encode(['success' => false, 'error' => 'Las horas de inicio y fin son obligatorias']); exit; }
if (empty($temas_tratados))        { echo json_encode(['success' => false, 'error' => 'Los temas tratados son obligatorios']); exit; }
if (empty($descripcion_clase))     { echo json_encode(['success' => false, 'error' => 'La descripción de la clase es obligatoria']); exit; }

try {
    $stmt = $pdo->prepare("SELECT curso_id, profesor_id FROM grupos WHERE id = ?");
    $stmt->execute([$grupo_id]);
    $grupo = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$grupo) { echo json_encode(['success' => false, 'error' => 'Grupo no encontrado']); exit; }
    if ($user['rol'] === 'profesor' && $grupo['profesor_id'] != $user['id']) {
        echo json_encode(['success' => false, 'error' => 'No tienes permiso para este grupo']); exit;
    }

    $profesor_id = $user['rol'] === 'profesor' ? $user['id'] : $grupo['profesor_id'];

    $stmt = $pdo->prepare("
        INSERT INTO bitacoras (
            grupo_id, curso_id, profesor_id, titulo,
            fecha_clase, hora_inicio, hora_fin,
            temas_tratados, descripcion_clase, observaciones,
            compromisos_proxima_clase
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $grupo_id, $grupo['curso_id'], $profesor_id, $titulo,
        $fecha_clase, $hora_inicio, $hora_fin,
        $temas_tratados, $descripcion_clase, $observaciones, $compromisos
    ]);
    $bitacora_id = $pdo->lastInsertId();

    // Asistencias
    if (isset($_POST['asistencias']) && is_array($_POST['asistencias'])) {
        foreach ($_POST['asistencias'] as $estudiante_id => $estado) {
            $obs = $_POST['asistencia_obs'][$estudiante_id] ?? '';
            $stmt = $pdo->prepare("INSERT INTO bitacoras_asistencias (bitacora_id, estudiante_id, estado, observacion) VALUES (?, ?, ?, ?)");
            $stmt->execute([$bitacora_id, $estudiante_id, $estado, $obs]);
        }
    }

    // Evidencias
    if (isset($_FILES['evidencias']) && !empty($_FILES['evidencias']['name'][0])) {
        $upload_dir = '../../../../assets/uploads/bitacoras/evidencias/';
        if (!is_dir($upload_dir)) { mkdir($upload_dir, 0755, true); }
        $orden = 0;
        foreach ($_FILES['evidencias']['tmp_name'] as $key => $tmp_name) {
            if ($_FILES['evidencias']['error'][$key] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['evidencias']['name'][$key], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                    $nombre = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $_FILES['evidencias']['name'][$key]);
                    $ruta   = $upload_dir . $nombre;
                    if (move_uploaded_file($tmp_name, $ruta)) {
                        $desc = $_POST['evidencia_desc'][$key] ?? '';
                        $stmt = $pdo->prepare("INSERT INTO bitacoras_evidencias (bitacora_id, nombre_archivo, ruta_archivo, descripcion, orden) VALUES (?, ?, ?, ?, ?)");
                        $stmt->execute([$bitacora_id, $_FILES['evidencias']['name'][$key], $ruta, $desc, $orden]);
                        $orden++;
                    }
                }
            }
        }
    }

    echo json_encode(['success' => true, 'id' => $bitacora_id]);

} catch (PDOException $e) {
    error_log("Error al crear bitácora: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error al guardar la bitácora']);
}
