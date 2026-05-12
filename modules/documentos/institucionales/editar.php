<?php
/**
 * Editar Documento Institucional
 * Acepta solo POST (AJAX modal o form legacy).
 */

require_once '../../../config/session.php';
require_once '../../../config/database.php';
require_once '../../../includes/auth_check.php';

require_role('admin');

$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($is_ajax) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'error' => 'Método no permitido']); exit; }
    header("Location: index.php"); exit;
}

$tipo   = $_POST['tipo']   ?? '';
$doc_id = isset($_POST['doc_id']) ? (int)$_POST['doc_id'] : 0;

if (empty($tipo) || $doc_id === 0) {
    if ($is_ajax) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'error' => 'Datos inválidos']); exit; }
    header("Location: index.php?error=datos_invalidos"); exit;
}

try {
    if ($tipo === 'certificado') {
        $nivel        = trim($_POST['nivel_aprobado']    ?? '');
        $calificacion = (float)($_POST['calificacion_final'] ?? 0);
        $fecha_apro   = $_POST['fecha_aprobacion']       ?? '';
        $fecha_inicio = $_POST['fecha_inicio_curso']     ?? '';
        $fecha_fin    = $_POST['fecha_fin_curso']        ?? '';
        $observaciones = trim($_POST['observaciones']    ?? '');

        if (empty($nivel)) {
            if ($is_ajax) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'error' => 'El nivel es obligatorio']); exit; }
            header("Location: index.php?error=datos_invalidos"); exit;
        }
        if ($calificacion < 0 || $calificacion > 5) {
            if ($is_ajax) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'error' => 'La calificación debe estar entre 0 y 5']); exit; }
            header("Location: index.php?error=datos_invalidos"); exit;
        }

        $stmt = $pdo->prepare("
            UPDATE calificaciones_certificados
               SET nivel_aprobado    = ?,
                   calificacion_final = ?,
                   fecha_aprobacion  = ?,
                   fecha_inicio_curso = ?,
                   fecha_fin_curso   = ?,
                   observaciones     = ?
             WHERE id = ? AND estado = 'aprobado'
        ");
        $stmt->execute([
            $nivel,
            $calificacion,
            $fecha_apro   ?: null,
            $fecha_inicio ?: null,
            $fecha_fin    ?: null,
            $observaciones ?: null,
            $doc_id,
        ]);

        $success_key = 'certificado_actualizado';

    } elseif ($tipo === 'comunicado') {
        $titulo      = trim($_POST['titulo']               ?? '');
        $descripcion = trim($_POST['descripcion']          ?? '');
        $categoria   = trim($_POST['categoria_comunicado'] ?? 'general');
        $prioridad   = trim($_POST['prioridad']            ?? 'normal');
        $dirigido_a  = trim($_POST['dirigido_a']           ?? 'todos');
        $fecha       = $_POST['fecha_documento']           ?? date('Y-m-d');

        if (empty($titulo)) {
            if ($is_ajax) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'error' => 'El título es obligatorio']); exit; }
            header("Location: index.php?error=datos_invalidos"); exit;
        }

        $stmt = $pdo->prepare("
            UPDATE documentos_comunicados
               SET titulo           = ?,
                   descripcion      = ?,
                   categoria        = ?,
                   prioridad        = ?,
                   dirigido_a       = ?,
                   fecha_publicacion = ?
             WHERE id = ? AND estado = 'activo'
        ");
        $stmt->execute([$titulo, $descripcion ?: null, $categoria, $prioridad, $dirigido_a, $fecha, $doc_id]);

        $success_key = 'comunicado_actualizado';

    } elseif ($tipo === 'acta') {
        $titulo       = trim($_POST['titulo']          ?? '');
        $descripcion  = trim($_POST['descripcion']     ?? '');
        $tipo_reunion = trim($_POST['tipo_reunion']    ?? '');
        $lugar        = trim($_POST['lugar']           ?? '');
        $asistentes   = trim($_POST['asistentes']      ?? '');
        $visibilidad  = trim($_POST['visibilidad_acta'] ?? 'solo_admin');
        $fecha        = $_POST['fecha_documento']      ?? date('Y-m-d');

        if (empty($titulo)) {
            if ($is_ajax) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'error' => 'El título es obligatorio']); exit; }
            header("Location: index.php?error=datos_invalidos"); exit;
        }

        $stmt = $pdo->prepare("
            UPDATE documentos_actas
               SET titulo       = ?,
                   descripcion  = ?,
                   tipo_reunion = ?,
                   lugar        = ?,
                   asistentes   = ?,
                   visibilidad  = ?,
                   fecha_reunion = ?
             WHERE id = ? AND estado = 'activo'
        ");
        $stmt->execute([
            $titulo, $descripcion ?: null, $tipo_reunion, $lugar ?: null,
            $asistentes ?: null, $visibilidad, $fecha, $doc_id,
        ]);

        $success_key = 'acta_actualizada';

    } else {
        if ($is_ajax) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'error' => 'Tipo inválido']); exit; }
        header("Location: index.php?error=tipo_invalido"); exit;
    }

    if ($is_ajax) { header('Content-Type: application/json'); echo json_encode(['success' => true, 'message' => $success_key]); exit; }
    header("Location: index.php?success=$success_key"); exit;

} catch (PDOException $e) {
    error_log("Error al editar documento institucional: " . $e->getMessage());
    if ($is_ajax) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'error' => 'Error al guardar los cambios']); exit; }
    header("Location: index.php?error=error_sistema"); exit;
}
