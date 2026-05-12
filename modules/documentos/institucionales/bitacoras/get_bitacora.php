<?php
/**
 * JSON endpoint: datos completos de una bitácora (para modal editar)
 */

require_once '../../../../config/session.php';
require_once '../../../../config/database.php';
require_once '../../../../includes/auth_check.php';

header('Content-Type: application/json');

$bitacora_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($bitacora_id === 0) { echo json_encode(['success' => false, 'error' => 'ID inválido']); exit; }

$uid = (int)$_SESSION['user_id'];
$rol = $_SESSION['user_rol'];

try {
    $stmt = $pdo->prepare("
        SELECT b.id, b.titulo, b.fecha_clase, b.hora_inicio, b.hora_fin,
               b.grupo_id, b.curso_id, b.profesor_id,
               b.temas_tratados, b.descripcion_clase, b.observaciones, b.compromisos_proxima_clase,
               c.nombre AS curso_nombre,
               g.nombre AS grupo_nombre,
               u.nombre AS profesor_nombre
        FROM bitacoras b
        INNER JOIN cursos   c ON b.curso_id    = c.id
        INNER JOIN grupos   g ON b.grupo_id    = g.id
        INNER JOIN usuarios u ON b.profesor_id = u.id
        WHERE b.id = ? AND b.estado = 'activo'
    ");
    $stmt->execute([$bitacora_id]);
    $bitacora = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$bitacora) { echo json_encode(['success' => false, 'error' => 'Bitácora no encontrada']); exit; }

    if ($rol !== 'admin' && $bitacora['profesor_id'] != $uid) {
        echo json_encode(['success' => false, 'error' => 'Sin permisos']); exit;
    }

    // Asistencias con nombre de estudiante
    $stmt = $pdo->prepare("
        SELECT ba.estudiante_id, ba.estado, ba.observacion, u.nombre
        FROM bitacoras_asistencias ba
        INNER JOIN usuarios u ON ba.estudiante_id = u.id
        WHERE ba.bitacora_id = ?
        ORDER BY u.nombre
    ");
    $stmt->execute([$bitacora_id]);
    $asistencias = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Evidencias (con filename extraído para el src en el modal)
    $stmt = $pdo->prepare("SELECT id, nombre_archivo, ruta_archivo, descripcion FROM bitacoras_evidencias WHERE bitacora_id = ? ORDER BY orden");
    $stmt->execute([$bitacora_id]);
    $evidencias_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $evidencias = array_map(function($ev) {
        $ev['archivo'] = basename($ev['ruta_archivo']);
        return $ev;
    }, $evidencias_raw);

    echo json_encode([
        'success'    => true,
        'bitacora'   => $bitacora,
        'asistencias' => $asistencias,
        'evidencias' => $evidencias,
    ]);

} catch (PDOException $e) {
    error_log("Error get_bitacora: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error del sistema']);
}
