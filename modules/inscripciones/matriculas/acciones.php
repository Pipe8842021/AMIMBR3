<?php
/**
 * Acciones del Módulo de Matrículas
 *
 * Lógica de pagos:
 *  - Al matricular/asignar grupo: se genera 1 solo pago (el del mes actual).
 *  - Cada mes se genera el siguiente pago (via generar_proximo_pago).
 *  - Un cron externo o el propio sistema marca como 'vencido' los pagos
 *    pendientes cuya fecha_vencimiento < HOY.
 */
require_once '../../../config/session.php';
require_once '../../../config/database.php';
require_once '../../../includes/auth_check.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header("Location: index.php"); exit; }

$accion           = $_POST['accion']             ?? '';
$matricula_id     = (int)($_POST['matricula_id']      ?? 0);
$redir_estudiante = (int)($_POST['redir_estudiante']  ?? 0);
$admin_id         = $_SESSION['user_id'];

// ── Helpers redirección ──────────────────────────────────────
function redir_ok($est, $mat, $msg) {
    header("Location: detalle.php?estudiante=$est&tab=$mat&msg=".urlencode($msg)."&type=success");
    exit;
}
function redir_err($est, $mat, $msg) {
    $url = $est
        ? "detalle.php?estudiante=$est&tab=$mat&msg=".urlencode($msg)."&type=error"
        : "index.php?msg=".urlencode($msg)."&type=error";
    header("Location: $url"); exit;
}

// ── Genera 1 pago para el mes indicado ───────────────────────
/**
 * Genera exactamente 1 cuota mensual para una matrícula.
 * No genera si ya existe un pago (pendiente o pagado) para ese mes/año.
 *
 * @param string $fecha_base   YYYY-MM-DD — define el día del mes de vencimiento
 * @param int    $mes_offset   0 = mes actual, 1 = siguiente mes, etc.
 * @return bool  true si se generó, false si ya existía
 */
function generar_pago_mes(
    PDO    $pdo,
    int    $matricula_id,
    int    $estudiante_id,
    float  $precio,
    string $fecha_base,
    string $curso_nombre,
    int    $admin_id,
    int    $mes_offset = 0
): bool {

    $meses_es = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio',
                 'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];

    $dt       = new DateTime($fecha_base);
    $dia_pago = (int)$dt->format('j');

    if ($mes_offset > 0) $dt->modify("+$mes_offset month");

    $ultimo  = (int)$dt->format('t');
    $dia_ok  = min($dia_pago, $ultimo);
    $dt->setDate((int)$dt->format('Y'), (int)$dt->format('n'), $dia_ok);

    $anio_venc = (int)$dt->format('Y');
    $mes_venc  = (int)$dt->format('n');
    $fecha_venc = $dt->format('Y-m-d');

    // Verificar si ya existe pago para ese mes/año en esta matrícula
    $chk = $pdo->prepare("
        SELECT COUNT(*) FROM pagos
        WHERE matricula_id = ?
          AND YEAR(fecha_vencimiento)  = ?
          AND MONTH(fecha_vencimiento) = ?
          AND estado != 'anulado'
    ");
    $chk->execute([$matricula_id, $anio_venc, $mes_venc]);
    if ((int)$chk->fetchColumn() > 0) return false;

    $mes_nombre = $meses_es[$mes_venc];
    $pdo->prepare("
        INSERT INTO pagos
            (estudiante_id, matricula_id, monto, concepto, metodo_pago,
             estado, fecha_vencimiento, registrado_por)
        VALUES (?, ?, ?, ?, 'efectivo', 'pendiente', ?, ?)
    ")->execute([
        $estudiante_id,
        $matricula_id,
        $precio,
        "Mensualidad $mes_nombre $anio_venc — $curso_nombre",
        $fecha_venc,
        $admin_id,
    ]);
    return true;
}

// ── Marcar pagos vencidos ────────────────────────────────────
// Siempre que se cargue esta página, actualizamos los pagos vencidos
try {
    $pdo->exec("
        UPDATE pagos
        SET estado = 'vencido'
        WHERE estado = 'pendiente'
          AND fecha_vencimiento < CURDATE()
    ");
} catch (PDOException $e) {
    error_log("Error marcando vencidos: " . $e->getMessage());
}

// ── Obtener estudiante_id si no vino en el POST ──────────────
if (!$redir_estudiante && $matricula_id) {
    try {
        $s = $pdo->prepare("SELECT estudiante_id FROM matriculas WHERE id = ?");
        $s->execute([$matricula_id]);
        $redir_estudiante = (int)($s->fetchColumn() ?: 0);
    } catch (PDOException $e) {}
}

// ════════════════════════════════════════════════════════════
try {
    switch ($accion) {

        // ── Cancelar matrícula ───────────────────────────────
        case 'cancelar':
            if (!$matricula_id) { header("Location: index.php"); exit; }
            $pdo->prepare("UPDATE matriculas SET estado='retirado', fecha_retiro=CURDATE() WHERE id=?")
                ->execute([$matricula_id]);
            $pdo->prepare("INSERT INTO logs_actividad (usuario_id,accion,detalles,ip_address) VALUES (?,?,?,?)")
                ->execute([$admin_id,'matricula_cancelada',"Matrícula #$matricula_id cancelada",$_SERVER['REMOTE_ADDR']??null]);
            $url = $redir_estudiante
                ? "detalle.php?estudiante=$redir_estudiante&msg=".urlencode('Matrícula cancelada')."&type=success"
                : "index.php?msg=".urlencode('Matrícula cancelada')."&type=success";
            header("Location: $url"); exit;

        // ── Asignar / cambiar grupo ──────────────────────────
        case 'asignar_grupo':
        case 'cambiar_grupo':
            $grupo_id = (int)($_POST['grupo_id'] ?? 0);
            if (!$matricula_id || !$grupo_id) redir_err($redir_estudiante, $matricula_id, 'Datos incompletos');

            $g = $pdo->prepare("SELECT cupo_actual, cupo_maximo FROM grupos WHERE id = ?");
            $g->execute([$grupo_id]); $gr = $g->fetch();
            if (!$gr) redir_err($redir_estudiante, $matricula_id, 'Grupo no encontrado');
            if ($gr['cupo_actual'] >= $gr['cupo_maximo'])
                redir_err($redir_estudiante, $matricula_id, 'El grupo no tiene cupo disponible');

            $sm = $pdo->prepare("SELECT m.grupo_id AS ant, m.estudiante_id, m.fecha_matricula FROM matriculas m WHERE m.id = ?");
            $sm->execute([$matricula_id]); $mat_data = $sm->fetch(PDO::FETCH_ASSOC);
            $grupo_anterior = $mat_data['ant'] ?? null;

            // Datos del NUEVO curso
            $sg = $pdo->prepare("SELECT c.precio_mensual, c.nombre AS curso_nombre FROM grupos g JOIN cursos c ON g.curso_id=c.id WHERE g.id=?");
            $sg->execute([$grupo_id]); $curso_nuevo = $sg->fetch(PDO::FETCH_ASSOC);

            $pdo->beginTransaction();
            $pdo->prepare("UPDATE matriculas SET grupo_id=? WHERE id=?")->execute([$grupo_id, $matricula_id]);
            $pdo->prepare("UPDATE grupos SET cupo_actual=cupo_actual+1 WHERE id=?")->execute([$grupo_id]);
            if ($grupo_anterior && $grupo_anterior != $grupo_id) {
                $pdo->prepare("UPDATE grupos SET cupo_actual=GREATEST(cupo_actual-1,0) WHERE id=?")->execute([$grupo_anterior]);
            }

            // Si es primera asignación y el curso tiene precio → generar 1 pago del mes actual
            $pago_generado = false;
            if (!$grupo_anterior && $curso_nuevo && $curso_nuevo['precio_mensual'] > 0) {
                $pago_generado = generar_pago_mes(
                    $pdo, $matricula_id,
                    $mat_data['estudiante_id'],
                    (float)$curso_nuevo['precio_mensual'],
                    $mat_data['fecha_matricula'],
                    $curso_nuevo['curso_nombre'],
                    $admin_id, 0
                );
            }
            $pdo->commit();

            $label = $accion === 'asignar_grupo' ? 'Grupo asignado correctamente' : 'Grupo actualizado correctamente';
            if ($pago_generado) $label .= '. Se generó el pago del mes actual.';
            redir_ok($redir_estudiante, $matricula_id, $label);

        // ── Cambiar estado ───────────────────────────────────
        case 'cambiar_estado':
            $nuevo = $_POST['nuevo_estado'] ?? '';
            $obs   = trim($_POST['observaciones'] ?? '');
            if (!in_array($nuevo, ['activa','suspendida','graduado','retirado']))
                redir_err($redir_estudiante, $matricula_id, 'Estado inválido');
            $fr = $nuevo === 'retirado' ? ', fecha_retiro=CURDATE()' : '';
            $pdo->prepare("UPDATE matriculas SET estado=?, observaciones=? $fr WHERE id=?")
                ->execute([$nuevo, $obs ?: null, $matricula_id]);
            $pdo->prepare("INSERT INTO logs_actividad (usuario_id,accion,detalles,ip_address) VALUES (?,?,?,?)")
                ->execute([$admin_id,'cambio_estado_matricula',"Matrícula #$matricula_id → $nuevo",$_SERVER['REMOTE_ADDR']??null]);
            redir_ok($redir_estudiante, $matricula_id, 'Estado actualizado correctamente');

        // ── Generar pago del próximo mes ─────────────────────
        // Llámalo con cron mensual: POST accion=generar_proximo_pago&matricula_id=X
        case 'generar_proximo_pago':
            $sm = $pdo->prepare("
                SELECT m.estudiante_id, m.fecha_matricula,
                       c.precio_mensual, c.nombre AS curso_nombre
                FROM matriculas m
                JOIN grupos g ON m.grupo_id=g.id
                JOIN cursos c ON g.curso_id=c.id
                WHERE m.id=? AND m.estado='activa'
            ");
            $sm->execute([$matricula_id]); $dat = $sm->fetch(PDO::FETCH_ASSOC);
            if (!$dat) redir_err($redir_estudiante, $matricula_id, 'Matrícula no activa o sin curso');

            // Calcular cuántos meses han pasado desde fecha_matricula
            $inicio = new DateTime($dat['fecha_matricula']);
            $hoy    = new DateTime();
            $diff   = (int)$inicio->diff($hoy)->m + ((int)$inicio->diff($hoy)->y * 12);

            $generado = generar_pago_mes(
                $pdo, $matricula_id,
                $dat['estudiante_id'],
                (float)$dat['precio_mensual'],
                $dat['fecha_matricula'],
                $dat['curso_nombre'],
                $admin_id, $diff
            );
            $msg = $generado ? 'Pago del mes generado correctamente.' : 'Ya existe un pago para este mes.';
            redir_ok($redir_estudiante, $matricula_id, $msg);

        // ── Editar pago (vencido o pendiente) ───────────────
        case 'editar_pago':
            $pago_id  = (int)($_POST['pago_id'] ?? 0);
            $concepto = trim($_POST['concepto'] ?? '');
            $monto    = (float)($_POST['monto'] ?? 0);
            $metodo   = $_POST['metodo_pago']  ?? '';
            $estado_p = $_POST['estado']       ?? '';
            $fvence   = $_POST['fecha_vencimiento'] ?? '';
            $fpago    = $_POST['fecha_pago']   ?? null;
            $obs      = trim($_POST['observaciones'] ?? '');

            if (!$pago_id) redir_err($redir_estudiante, $matricula_id, 'ID de pago inválido');
            if (!$concepto || $monto <= 0)
                redir_err($redir_estudiante, $matricula_id, 'Concepto y monto son obligatorios');
            if (!in_array($metodo, ['efectivo','transferencia','tarjeta','pse']))
                redir_err($redir_estudiante, $matricula_id, 'Método de pago inválido');
            if (!in_array($estado_p, ['pendiente','vencido','pagado','anulado']))
                redir_err($redir_estudiante, $matricula_id, 'Estado inválido');

            // Solo permitir editar pagos que NO estén ya pagados (salvo que el admin cambie el estado)
            $chkPago = $pdo->prepare("SELECT estado FROM pagos WHERE id = ? AND matricula_id = ?");
            $chkPago->execute([$pago_id, $matricula_id]);
            $pagoActual = $chkPago->fetch(PDO::FETCH_ASSOC);
            if (!$pagoActual) redir_err($redir_estudiante, $matricula_id, 'Pago no encontrado');

            $fpago_val = ($estado_p === 'pagado') ? ($fpago ?: date('Y-m-d')) : null;

            $pdo->prepare("
                UPDATE pagos
                SET concepto          = ?,
                    monto             = ?,
                    metodo_pago       = ?,
                    estado            = ?,
                    fecha_vencimiento = ?,
                    fecha_pago        = ?,
                    observaciones     = ?
                WHERE id = ? AND matricula_id = ?
            ")->execute([
                $concepto, $monto, $metodo, $estado_p,
                $fvence ?: null, $fpago_val, $obs ?: null,
                $pago_id, $matricula_id,
            ]);

            // Si se marcó como pagado al editar, también generar el siguiente mes
            $siguiente_msg = '';
            if ($estado_p === 'pagado' && $pagoActual['estado'] !== 'pagado') {
                $sm = $pdo->prepare("SELECT m.estudiante_id, m.fecha_matricula, c.precio_mensual, c.nombre AS curso_nombre FROM matriculas m JOIN grupos g ON m.grupo_id=g.id JOIN cursos c ON g.curso_id=c.id WHERE m.id=? AND m.estado='activa'");
                $sm->execute([$matricula_id]);
                $dat = $sm->fetch(PDO::FETCH_ASSOC);
                if ($dat && $dat['precio_mensual'] > 0) {
                    $inicio   = new DateTime($dat['fecha_matricula']);
                    $hoy      = new DateTime();
                    $diff     = (int)$inicio->diff($hoy)->m + ((int)$inicio->diff($hoy)->y * 12);
                    $generado = generar_pago_mes($pdo, $matricula_id, $dat['estudiante_id'],
                        (float)$dat['precio_mensual'], $dat['fecha_matricula'],
                        $dat['curso_nombre'], $admin_id, $diff + 1);
                    if ($generado) $siguiente_msg = ' Se generó el pago del próximo mes.';
                }
            }
            redir_ok($redir_estudiante, $matricula_id, 'Pago actualizado correctamente.' . $siguiente_msg);

        // ── Registrar pago manual ────────────────────────────
        case 'registrar_pago':
            $concepto = trim($_POST['concepto'] ?? '');
            $monto    = (float)($_POST['monto'] ?? 0);
            $metodo   = $_POST['metodo_pago']  ?? '';
            $estado_p = $_POST['estado']       ?? 'pendiente';
            $fvence   = $_POST['fecha_vencimiento'] ?? date('Y-m-t');
            $fpago    = $_POST['fecha_pago']   ?? null;
            $obs      = trim($_POST['observaciones'] ?? '');
            $est_id   = (int)($_POST['estudiante_id'] ?? $redir_estudiante);

            if (!$concepto || $monto <= 0 || !$metodo)
                redir_err($redir_estudiante, $matricula_id, 'Completa los campos requeridos');
            if (!in_array($metodo, ['efectivo','transferencia','tarjeta','pse']))
                redir_err($redir_estudiante, $matricula_id, 'Método de pago inválido');

            $fpago_val = ($estado_p === 'pagado') ? ($fpago ?: date('Y-m-d')) : null;
            $pdo->prepare("INSERT INTO pagos (estudiante_id,matricula_id,monto,concepto,metodo_pago,estado,fecha_vencimiento,fecha_pago,observaciones,registrado_por) VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$est_id,$matricula_id,$monto,$concepto,$metodo,$estado_p,$fvence,$fpago_val,$obs?:null,$admin_id]);
            redir_ok($redir_estudiante, $matricula_id, 'Pago registrado correctamente');

        // ── Marcar pago como pagado ──────────────────────────
        case 'marcar_pagado':
            $pago_id = (int)($_POST['pago_id'] ?? 0);
            if (!$pago_id) redir_err($redir_estudiante, $matricula_id, 'ID de pago inválido');
            $pdo->prepare("UPDATE pagos SET estado='pagado', fecha_pago=CURDATE() WHERE id=? AND matricula_id=?")
                ->execute([$pago_id, $matricula_id]);

            // Tras marcar pagado, generar el pago del mes siguiente si no existe
            $sm = $pdo->prepare("
                SELECT m.estudiante_id, m.fecha_matricula,
                       c.precio_mensual, c.nombre AS curso_nombre
                FROM matriculas m
                JOIN grupos g ON m.grupo_id=g.id
                JOIN cursos c ON g.curso_id=c.id
                WHERE m.id=? AND m.estado='activa'
            ");
            $sm->execute([$matricula_id]); $dat = $sm->fetch(PDO::FETCH_ASSOC);
            $siguiente_msg = '';
            if ($dat && $dat['precio_mensual'] > 0) {
                $inicio = new DateTime($dat['fecha_matricula']);
                $hoy    = new DateTime();
                $diff   = (int)$inicio->diff($hoy)->m + ((int)$inicio->diff($hoy)->y * 12);
                // Generar el pago del MES SIGUIENTE al actual
                $generado = generar_pago_mes(
                    $pdo, $matricula_id,
                    $dat['estudiante_id'],
                    (float)$dat['precio_mensual'],
                    $dat['fecha_matricula'],
                    $dat['curso_nombre'],
                    $admin_id, $diff + 1
                );
                if ($generado) $siguiente_msg = ' Se generó el pago del próximo mes.';
            }
            redir_ok($redir_estudiante, $matricula_id, 'Pago marcado como pagado.' . $siguiente_msg);

        default:
            header("Location: index.php"); exit;
    }

} catch (PDOException $e) {
    error_log("Error acciones matrícula: " . $e->getMessage());
    redir_err($redir_estudiante, $matricula_id, 'Error del sistema. Intenta de nuevo.');
}