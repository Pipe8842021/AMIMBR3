<?php
/**
 * Acciones del Módulo de Matrículas
 * Procesa: cancelar, cambiar_grupo, asignar_grupo, cambiar_estado, registrar_pago, marcar_pagado
 */

require_once '../../../config/session.php';
require_once '../../../config/database.php';
require_once '../../../includes/auth_check.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit;
}

$accion       = $_POST['accion'] ?? '';
$matricula_id = (int)($_POST['matricula_id'] ?? 0);
$admin_id     = $_SESSION['user_id'];

function redir($url) { header("Location: $url"); exit; }
function redir_detalle($id, $msg, $type = 'success') {
    header("Location: detalle.php?id=$id&msg=" . urlencode($msg) . "&type=$type");
    exit;
}

try {
    switch ($accion) {

        // ── Cancelar matrícula (retirar estudiante) ────────────────────────────
        case 'cancelar':
            if (!$matricula_id) redir('index.php');
            $stmt = $pdo->prepare("UPDATE matriculas SET estado = 'retirado', fecha_retiro = CURDATE() WHERE id = ?");
            $stmt->execute([$matricula_id]);

            // Log
            $pdo->prepare("INSERT INTO logs_actividad (usuario_id, accion, detalles, ip_address) VALUES (?,?,?,?)")
                ->execute([$admin_id, 'matricula_cancelada', "Matrícula #$matricula_id cancelada", $_SERVER['REMOTE_ADDR'] ?? null]);

            redir('index.php?msg=' . urlencode('Matrícula cancelada correctamente') . '&type=success');
            break;

        // ── Asignar grupo ──────────────────────────────────────────────────────
        case 'asignar_grupo':
        case 'cambiar_grupo':
            $grupo_id = (int)($_POST['grupo_id'] ?? 0);
            if (!$matricula_id || !$grupo_id) redir_detalle($matricula_id, 'Datos incompletos', 'error');

            // Verificar cupo
            $stmt = $pdo->prepare("SELECT cupo_actual, cupo_maximo FROM grupos WHERE id = ?");
            $stmt->execute([$grupo_id]);
            $grupo = $stmt->fetch();
            if (!$grupo) redir_detalle($matricula_id, 'Grupo no encontrado', 'error');
            if ($grupo['cupo_actual'] >= $grupo['cupo_maximo']) redir_detalle($matricula_id, 'El grupo no tiene cupo disponible', 'error');

            // Obtener grupo anterior para liberar cupo
            $stmt = $pdo->prepare("SELECT grupo_id FROM matriculas WHERE id = ?");
            $stmt->execute([$matricula_id]);
            $mat = $stmt->fetch();
            $grupo_anterior = $mat['grupo_id'] ?? null;

            $pdo->beginTransaction();
            // Actualizar matrícula
            $pdo->prepare("UPDATE matriculas SET grupo_id = ? WHERE id = ?")->execute([$grupo_id, $matricula_id]);
            // Incrementar cupo nuevo grupo
            $pdo->prepare("UPDATE grupos SET cupo_actual = cupo_actual + 1 WHERE id = ?")->execute([$grupo_id]);
            // Liberar cupo grupo anterior
            if ($grupo_anterior && $grupo_anterior != $grupo_id) {
                $pdo->prepare("UPDATE grupos SET cupo_actual = GREATEST(cupo_actual - 1, 0) WHERE id = ?")->execute([$grupo_anterior]);
            }
            $pdo->commit();

            $label = $accion === 'asignar_grupo' ? 'Grupo asignado correctamente' : 'Grupo actualizado correctamente';
            redir_detalle($matricula_id, $label);
            break;

        // ── Cambiar estado ─────────────────────────────────────────────────────
        case 'cambiar_estado':
            $nuevo_estado   = $_POST['nuevo_estado'] ?? '';
            $observaciones  = trim($_POST['observaciones'] ?? '');
            $estados_validos = ['activa', 'suspendida', 'graduado', 'retirado'];
            if (!in_array($nuevo_estado, $estados_validos)) redir_detalle($matricula_id, 'Estado inválido', 'error');

            $fecha_retiro_sql = $nuevo_estado === 'retirado' ? ', fecha_retiro = CURDATE()' : '';
            $stmt = $pdo->prepare("UPDATE matriculas SET estado = ?, observaciones = ? $fecha_retiro_sql WHERE id = ?");
            $stmt->execute([$nuevo_estado, $observaciones ?: null, $matricula_id]);

            $pdo->prepare("INSERT INTO logs_actividad (usuario_id, accion, detalles, ip_address) VALUES (?,?,?,?)")
                ->execute([$admin_id, 'cambio_estado_matricula', "Matrícula #$matricula_id → $nuevo_estado", $_SERVER['REMOTE_ADDR'] ?? null]);

            redir_detalle($matricula_id, 'Estado actualizado correctamente');
            break;

        // ── Registrar pago ─────────────────────────────────────────────────────
        case 'registrar_pago':
            $concepto          = trim($_POST['concepto'] ?? '');
            $monto             = (float)($_POST['monto'] ?? 0);
            $metodo_pago       = $_POST['metodo_pago'] ?? '';
            $estado_pago       = $_POST['estado'] ?? 'pendiente';
            $fecha_vencimiento = $_POST['fecha_vencimiento'] ?? date('Y-m-t');
            $fecha_pago        = $_POST['fecha_pago'] ?? null;
            $observaciones     = trim($_POST['observaciones'] ?? '');
            $estudiante_id     = (int)($_POST['estudiante_id'] ?? 0);

            if (!$concepto || $monto <= 0 || !$metodo_pago) {
                redir_detalle($matricula_id, 'Completa todos los campos requeridos', 'error');
            }
            $metodos_validos = ['efectivo', 'transferencia', 'tarjeta', 'pse'];
            if (!in_array($metodo_pago, $metodos_validos)) redir_detalle($matricula_id, 'Método de pago inválido', 'error');

            $fecha_pago_val = ($estado_pago === 'pagado' && $fecha_pago) ? $fecha_pago : null;
            if ($estado_pago === 'pagado' && !$fecha_pago_val) $fecha_pago_val = date('Y-m-d');

            $stmt = $pdo->prepare("
                INSERT INTO pagos (estudiante_id, matricula_id, monto, concepto, metodo_pago, estado, fecha_vencimiento, fecha_pago, observaciones, registrado_por)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $estudiante_id, $matricula_id, $monto, $concepto,
                $metodo_pago, $estado_pago, $fecha_vencimiento,
                $fecha_pago_val, $observaciones ?: null, $admin_id
            ]);

            redir_detalle($matricula_id, 'Pago registrado correctamente');
            break;

        // ── Marcar pago como pagado ────────────────────────────────────────────
        case 'marcar_pagado':
            $pago_id = (int)($_POST['pago_id'] ?? 0);
            if (!$pago_id) redir_detalle($matricula_id, 'ID de pago inválido', 'error');

            $pdo->prepare("UPDATE pagos SET estado = 'pagado', fecha_pago = CURDATE() WHERE id = ? AND matricula_id = ?")
                ->execute([$pago_id, $matricula_id]);

            redir_detalle($matricula_id, 'Pago marcado como pagado');
            break;

        default:
            redir('index.php');
    }

} catch (PDOException $e) {
    error_log("Error en acciones matrícula: " . $e->getMessage());
    if ($matricula_id) {
        redir_detalle($matricula_id, 'Error del sistema. Intenta de nuevo.', 'error');
    } else {
        redir('index.php?error=1');
    }
}