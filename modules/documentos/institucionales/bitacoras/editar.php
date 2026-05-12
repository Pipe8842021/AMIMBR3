<?php
/**
 * Editar Bitácora – endpoint AJAX
 */

require_once '../../../../config/session.php';
require_once '../../../../config/database.php';
require_once '../../../../includes/auth_check.php';

header('Content-Type: application/json');

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

$bitacora_id = isset($_POST['bitacora_id']) ? (int)$_POST['bitacora_id'] : 0;
if ($bitacora_id === 0) { echo json_encode(['success' => false, 'error' => 'ID inválido']); exit; }

try {
    $stmt = $pdo->prepare("SELECT b.*, g.profesor_id as grupo_profesor_id FROM bitacoras b INNER JOIN grupos g ON b.grupo_id = g.id WHERE b.id = ? AND b.estado = 'activo'");
    $stmt->execute([$bitacora_id]);
    $bitacora = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$bitacora) { echo json_encode(['success' => false, 'error' => 'Bitácora no encontrada']); exit; }

    if ($user['rol'] === 'profesor' && $bitacora['profesor_id'] != $user['id']) {
        echo json_encode(['success' => false, 'error' => 'Sin permisos para editar esta bitácora']); exit;
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Error del sistema']); exit;
}

$grupo_id          = isset($_POST['grupo_id'])          ? (int)$_POST['grupo_id']          : 0;
$titulo            = trim($_POST['titulo']            ?? '');
$fecha_clase       = $_POST['fecha_clase']       ?? '';
$hora_inicio       = $_POST['hora_inicio']       ?? '';
$hora_fin          = $_POST['hora_fin']          ?? '';
$temas_tratados    = trim($_POST['temas_tratados']    ?? '');
$descripcion_clase = trim($_POST['descripcion_clase'] ?? '');
$observaciones     = trim($_POST['observaciones']     ?? '');
$compromisos       = trim($_POST['compromisos']       ?? '');

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

    $stmt = $pdo->prepare("
        UPDATE bitacoras SET
            grupo_id = ?, curso_id = ?, titulo = ?,
            fecha_clase = ?, hora_inicio = ?, hora_fin = ?,
            temas_tratados = ?, descripcion_clase = ?,
            observaciones = ?, compromisos_proxima_clase = ?,
            fecha_modificacion = NOW()
        WHERE id = ?
    ");
    $stmt->execute([
        $grupo_id, $grupo['curso_id'], $titulo,
        $fecha_clase, $hora_inicio, $hora_fin,
        $temas_tratados, $descripcion_clase,
        $observaciones, $compromisos,
        $bitacora_id
    ]);

    // Actualizar asistencias
    if (isset($_POST['asistencias']) && is_array($_POST['asistencias'])) {
        $stmt = $pdo->prepare("DELETE FROM bitacoras_asistencias WHERE bitacora_id = ?");
        $stmt->execute([$bitacora_id]);
        foreach ($_POST['asistencias'] as $estudiante_id => $estado) {
            $obs = $_POST['asistencia_obs'][$estudiante_id] ?? '';
            $stmt = $pdo->prepare("INSERT INTO bitacoras_asistencias (bitacora_id, estudiante_id, estado, observacion) VALUES (?, ?, ?, ?)");
            $stmt->execute([$bitacora_id, $estudiante_id, $estado, $obs]);
        }
    }

    // Nuevas evidencias
    if (isset($_FILES['evidencias']) && !empty($_FILES['evidencias']['name'][0])) {
        $upload_dir = '../../../../assets/uploads/bitacoras/evidencias/';
        if (!is_dir($upload_dir)) { mkdir($upload_dir, 0755, true); }

        $stmt = $pdo->prepare("SELECT MAX(orden) as max_orden FROM bitacoras_evidencias WHERE bitacora_id = ?");
        $stmt->execute([$bitacora_id]);
        $orden = ((int)($stmt->fetch()['max_orden'] ?? -1)) + 1;

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

    // Eliminar evidencias marcadas
    if (isset($_POST['eliminar_evidencias']) && is_array($_POST['eliminar_evidencias'])) {
        foreach ($_POST['eliminar_evidencias'] as $evidencia_id) {
            $stmt = $pdo->prepare("SELECT ruta_archivo FROM bitacoras_evidencias WHERE id = ?");
            $stmt->execute([$evidencia_id]);
            $ev = $stmt->fetch();
            if ($ev && file_exists($ev['ruta_archivo'])) { unlink($ev['ruta_archivo']); }
            $stmt = $pdo->prepare("DELETE FROM bitacoras_evidencias WHERE id = ?");
            $stmt->execute([$evidencia_id]);
        }
    }

    echo json_encode(['success' => true]);

} catch (PDOException $e) {
    error_log("Error al actualizar bitácora: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error al guardar los cambios']);
}
