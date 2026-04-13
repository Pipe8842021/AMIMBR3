<?php
/**
 * acciones.php — Módulo Matrículas — Amimbré
 */
require_once '../../../config/session.php';
require_once '../../../config/database.php';
require_once '../../../includes/auth_check.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php"); exit;
}

$accion           = $_POST['accion']           ?? '';
$matricula_id     = (int)($_POST['matricula_id']     ?? 0);
$redir_estudiante = (int)($_POST['redir_estudiante'] ?? 0);

function redir(int $est, int $tab, string $msg, string $type = 'success'): never {
    header("Location: detalle.php?estudiante=$est&tab=$tab&msg=" . urlencode($msg) . "&type=$type");
    exit;
}
function redir_index(string $msg, string $type = 'success'): never {
    header("Location: index.php?msg=" . urlencode($msg) . "&type=$type");
    exit;
}

$meses_es = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',
             7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];

try {

/* ════════════════════════════════════════════════════════════
   CAMBIAR ESTADO
   Reglas de negocio:
   - suspendida: anula pagos futuros e inserta placeholders 'anulado'
     para los próximos meses (bloqueando al cron).
   - activa (desde suspendida): borra fecha_suspension; el cron solo
     genera desde el mes actual en adelante porque los meses suspendidos
     ya tienen registros 'anulado'.
   - retirado / graduado: anula todos los pagos pendientes/vencidos.
   ════════════════════════════════════════════════════════════ */
if ($accion === 'cambiar_estado') {

    $nuevo_estado  = $_POST['nuevo_estado'] ?? '';
    $observaciones = trim($_POST['observaciones'] ?? '');
    $estados_ok    = ['activa','suspendida','graduado','retirado'];

    if (!in_array($nuevo_estado, $estados_ok, true))
        redir($redir_estudiante, $matricula_id, 'Estado no válido.', 'danger');

    $stmt = $pdo->prepare("
        SELECT m.estado AS estado_actual, m.fecha_suspension, m.estudiante_id,
               c.precio_mensual, c.nombre AS curso_nombre
        FROM matriculas m
        LEFT JOIN grupos g ON m.grupo_id = g.id
        LEFT JOIN cursos c ON g.curso_id = c.id
        WHERE m.id = ?
    ");
    $stmt->execute([$matricula_id]);
    $mat = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$mat) redir($redir_estudiante, $matricula_id, 'Matrícula no encontrada.', 'danger');

    $estado_actual = $mat['estado_actual'];
    $hoy           = date('Y-m-d');

    /* ── SUSPENDER ── */
    if ($nuevo_estado === 'suspendida' && $estado_actual === 'activa') {

        $pdo->prepare("
            UPDATE matriculas SET estado='suspendida', fecha_suspension=?, observaciones=? WHERE id=?
        ")->execute([$hoy, $observaciones ?: null, $matricula_id]);

        // Anular pagos pendientes/vencidos con fecha >= hoy
        $pdo->prepare("
            UPDATE pagos
            SET estado='anulado',
                observaciones=CONCAT(COALESCE(observaciones,''), ' [Anulado por suspensión]')
            WHERE matricula_id=? AND estado IN ('pendiente','vencido') AND fecha_vencimiento >= ?
        ")->execute([$matricula_id, $hoy]);

        // Insertar placeholders 'anulado' para los próximos 12 meses sin cobertura
        // → el cron los detectará y no generará pagos para esos meses al reactivar
        if ($mat['precio_mensual'] > 0 && $mat['curso_nombre']) {
            $chk = $pdo->prepare("
                SELECT COUNT(*) FROM pagos
                WHERE matricula_id=?
                  AND YEAR(fecha_vencimiento)=?
                  AND MONTH(fecha_vencimiento)=?
                  AND estado!='anulado'
            ");
            $ins_sus = $pdo->prepare("
                INSERT INTO pagos
                    (estudiante_id,matricula_id,monto,concepto,metodo_pago,estado,fecha_vencimiento,registrado_por)
                VALUES (?,?,0,?,'efectivo','anulado',?,1)
            ");
            $iter = new DateTime($hoy);
            $iter->modify('first day of this month');
            for ($i = 0; $i < 12; $i++) {
                $a  = (int)$iter->format('Y');
                $me = (int)$iter->format('n');
                $chk->execute([$matricula_id, $a, $me]);
                if ((int)$chk->fetchColumn() === 0) {
                    $concepto_sus = "[Suspendido] Mensualidad {$meses_es[$me]} $a — {$mat['curso_nombre']}";
                    $ins_sus->execute([$mat['estudiante_id'], $matricula_id, $concepto_sus, $iter->format('Y-m-01')]);
                }
                $iter->modify('+1 month');
            }
        }

        redir($redir_estudiante, $matricula_id, 'Matrícula suspendida. Los pagos futuros han sido pausados.');
    }

    /* ── REACTIVAR desde suspendida ── */
    if ($nuevo_estado === 'activa' && $estado_actual === 'suspendida') {
        $pdo->prepare("
            UPDATE matriculas SET estado='activa', fecha_suspension=NULL, observaciones=? WHERE id=?
        ")->execute([$observaciones ?: null, $matricula_id]);

        // Los placeholders 'anulado' de los meses suspendidos quedan intactos
        // → el cron solo generará desde el mes actual en adelante.
        redir($redir_estudiante, $matricula_id, 'Matrícula reactivada. Los pagos se reanudarán desde este mes.');
    }

    /* ── RETIRADO / GRADUADO / otros ── */
    $extra_fields = '';
    $params       = [$nuevo_estado, $observaciones ?: null];

    if ($nuevo_estado === 'retirado') {
        $extra_fields = ', fecha_retiro=?';
        $params[]     = $hoy;
        $pdo->prepare("UPDATE pagos SET estado='anulado' WHERE matricula_id=? AND estado IN ('pendiente','vencido')")
            ->execute([$matricula_id]);
    }

    $params[] = $matricula_id;
    $pdo->prepare("UPDATE matriculas SET estado=?, observaciones=? $extra_fields WHERE id=?")->execute($params);

    $msgs = [
        'graduado' => 'Matrícula marcada como Graduado.',
        'retirado' => 'Matrícula marcada como Retirado. Pagos pendientes anulados.',
        'activa'   => 'Estado actualizado a Activa.',
    ];
    redir($redir_estudiante, $matricula_id, $msgs[$nuevo_estado] ?? 'Estado actualizado.');
}

/* ════════════════════════════════════════════════════════════
   ELIMINAR MATRÍCULA
   Requiere que el admin escriba "eliminar" en el campo de confirmación.
   Conserva el historial de pagos (los marca anulados) y registra en log.
   ════════════════════════════════════════════════════════════ */
if ($accion === 'eliminar_matricula') {

    $stmt = $pdo->prepare("
        SELECT m.*, u.nombre AS estudiante_nombre
        FROM matriculas m JOIN usuarios u ON m.estudiante_id=u.id
        WHERE m.id=?
    ");
    $stmt->execute([$matricula_id]);
    $mat = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$mat) redir_index('Matrícula no encontrada.', 'danger');

    if (strtolower(trim($_POST['confirmacion'] ?? '')) !== 'eliminar')
        redir($redir_estudiante, $matricula_id, 'Debes escribir "eliminar" para confirmar la eliminación.', 'danger');

    $pdo->beginTransaction();

    // Anular pagos (conservar historial, no borrar)
    $pdo->prepare("UPDATE pagos SET estado='anulado' WHERE matricula_id=?")->execute([$matricula_id]);

    // Eliminar la matrícula (CASCADE borra asistencias, calificaciones, etc.)
    $pdo->prepare("DELETE FROM matriculas WHERE id=?")->execute([$matricula_id]);

    // Log
    $pdo->prepare("
        INSERT INTO logs_actividad (usuario_id,accion,detalles,ip_address)
        VALUES (?,?,?,?)
    ")->execute([
        $_SESSION['usuario_id'] ?? 1,
        'eliminar_matricula',
        "Matrícula #{$matricula_id} del estudiante '{$mat['estudiante_nombre']}' eliminada manualmente.",
        $_SERVER['REMOTE_ADDR'] ?? 'cli',
    ]);

    // Liberar cupo del grupo si tenía
    if ($mat['grupo_id']) {
        $pdo->prepare("UPDATE grupos SET cupo_actual=GREATEST(0,cupo_actual-1) WHERE id=?")
            ->execute([$mat['grupo_id']]);
    }

    $pdo->commit();

    // Redirigir: si quedan más matrículas del estudiante → detalle; si no → index
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM matriculas WHERE estudiante_id=?");
    $stmt->execute([$redir_estudiante]);
    if ((int)$stmt->fetchColumn() > 0) {
        header("Location: detalle.php?estudiante={$redir_estudiante}&msg=" . urlencode('Matrícula eliminada correctamente.') . "&type=success");
    } else {
        redir_index('Matrícula eliminada. El estudiante ya no tiene matrículas registradas.');
    }
    exit;
}

/* ════════════════════════════════════════════════════════════
   REGISTRAR PAGO
   ════════════════════════════════════════════════════════════ */
if ($accion === 'registrar_pago') {
    $concepto          = trim($_POST['concepto'] ?? '');
    $monto             = (float)($_POST['monto'] ?? 0);
    $metodo_pago       = $_POST['metodo_pago']        ?? 'efectivo';
    $estado_pago       = $_POST['estado']              ?? 'pagado';
    $fecha_vencimiento = $_POST['fecha_vencimiento']   ?? date('Y-m-t');
    $fecha_pago        = $_POST['fecha_pago']          ?: null;
    $observaciones     = trim($_POST['observaciones']  ?? '');
    $estudiante_id     = (int)($_POST['estudiante_id'] ?? 0);

    if (!$concepto || $monto <= 0)
        redir($redir_estudiante, $matricula_id, 'Concepto y monto son obligatorios.', 'danger');

    $pdo->prepare("
        INSERT INTO pagos
            (estudiante_id,matricula_id,monto,concepto,metodo_pago,estado,
             fecha_vencimiento,fecha_pago,observaciones,registrado_por)
        VALUES (?,?,?,?,?,?,?,?,?,?)
    ")->execute([
        $estudiante_id, $matricula_id, $monto, $concepto,
        $metodo_pago, $estado_pago, $fecha_vencimiento,
        $fecha_pago, $observaciones ?: null, $_SESSION['usuario_id'] ?? 1,
    ]);

    redir($redir_estudiante, $matricula_id, 'Pago registrado correctamente.');
}

/* ════════════════════════════════════════════════════════════
   MARCAR PAGADO
   ════════════════════════════════════════════════════════════ */
if ($accion === 'marcar_pagado') {
    $pago_id = (int)($_POST['pago_id'] ?? 0);
    $pdo->prepare("UPDATE pagos SET estado='pagado', fecha_pago=CURDATE() WHERE id=? AND matricula_id=?")
        ->execute([$pago_id, $matricula_id]);
    redir($redir_estudiante, $matricula_id, 'Pago marcado como pagado.');
}

/* ════════════════════════════════════════════════════════════
   EDITAR PAGO
   ════════════════════════════════════════════════════════════ */
if ($accion === 'editar_pago') {
    $pago_id           = (int)($_POST['pago_id']          ?? 0);
    $concepto          = trim($_POST['concepto']           ?? '');
    $monto             = (float)($_POST['monto']           ?? 0);
    $metodo_pago       = $_POST['metodo_pago']             ?? 'efectivo';
    $estado_pago       = $_POST['estado']                  ?? 'pendiente';
    $fecha_vencimiento = $_POST['fecha_vencimiento']       ?? null;
    $fecha_pago        = $_POST['fecha_pago']              ?: null;
    $observaciones     = trim($_POST['observaciones']      ?? '');

    $pdo->prepare("
        UPDATE pagos SET concepto=?,monto=?,metodo_pago=?,estado=?,
                         fecha_vencimiento=?,fecha_pago=?,observaciones=?
        WHERE id=? AND matricula_id=?
    ")->execute([
        $concepto,$monto,$metodo_pago,$estado_pago,
        $fecha_vencimiento,$fecha_pago,
        $observaciones ?: null, $pago_id,$matricula_id,
    ]);
    redir($redir_estudiante, $matricula_id, 'Pago actualizado correctamente.');
}

/* ════════════════════════════════════════════════════════════
   REGENERAR CUOTAS
   ════════════════════════════════════════════════════════════ */
if ($accion === 'regenerar_pagos') {
    $stmt = $pdo->prepare("
        SELECT m.fecha_matricula, m.estudiante_id, c.precio_mensual, c.nombre AS curso_nombre
        FROM matriculas m
        JOIN grupos g ON m.grupo_id=g.id
        JOIN cursos c ON g.curso_id=c.id
        WHERE m.id=?
    ");
    $stmt->execute([$matricula_id]);
    $mat = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$mat) redir($redir_estudiante, $matricula_id, 'Matrícula no encontrada.', 'danger');

    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM pagos WHERE matricula_id=? AND estado IN ('pendiente','vencido')")->execute([$matricula_id]);

    $chk = $pdo->prepare("
        SELECT COUNT(*) FROM pagos
        WHERE matricula_id=? AND YEAR(fecha_vencimiento)=? AND MONTH(fecha_vencimiento)=? AND estado!='anulado'
    ");
    $ins = $pdo->prepare("
        INSERT INTO pagos (estudiante_id,matricula_id,monto,concepto,metodo_pago,estado,fecha_vencimiento,registrado_por)
        VALUES (?,?,?,?,'efectivo',?,?,1)
    ");

    $fmat = new DateTime($mat['fecha_matricula']);
    $dbase= (int)$fmat->format('j');
    $ia   = (int)$fmat->format('Y');
    $im   = (int)$fmat->format('n');
    $hoy  = new DateTime();
    $la   = (int)$hoy->format('Y');
    $lm   = (int)$hoy->format('n');

    while ($ia < $la || ($ia === $la && $im <= $lm)) {
        $chk->execute([$matricula_id, $ia, $im]);
        if ((int)$chk->fetchColumn() === 0) {
            $t  = (int)(new DateTime("$ia-$im-01"))->format('t');
            $d  = min($dbase,$t);
            $fv = sprintf('%04d-%02d-%02d',$ia,$im,$d);
            $es = ($fv < date('Y-m-d')) ? 'vencido' : 'pendiente';
            $co = "Mensualidad {$meses_es[$im]} $ia — {$mat['curso_nombre']}";
            $ins->execute([$mat['estudiante_id'],$matricula_id,$mat['precio_mensual'],$co,$es,$fv]);
        }
        $im++; if ($im>12){$im=1;$ia++;}
    }
    $pdo->commit();
    redir($redir_estudiante, $matricula_id, 'Cuotas regeneradas correctamente.');
}

/* ════════════════════════════════════════════════════════════
   ASIGNAR / CAMBIAR GRUPO
   ════════════════════════════════════════════════════════════ */
if (in_array($accion, ['asignar_grupo','cambiar_grupo'], true)) {
    $grupo_id = (int)($_POST['grupo_id'] ?? 0);
    if (!$grupo_id) redir($redir_estudiante, $matricula_id, 'Selecciona un grupo.', 'danger');

    $pdo->beginTransaction();
    $stmt = $pdo->prepare("SELECT grupo_id FROM matriculas WHERE id=?");
    $stmt->execute([$matricula_id]);
    $anterior = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($anterior && $anterior['grupo_id'])
        $pdo->prepare("UPDATE grupos SET cupo_actual=GREATEST(0,cupo_actual-1) WHERE id=?")->execute([$anterior['grupo_id']]);

    $pdo->prepare("UPDATE matriculas SET grupo_id=? WHERE id=?")->execute([$grupo_id,$matricula_id]);
    $pdo->prepare("UPDATE grupos SET cupo_actual=cupo_actual+1 WHERE id=?")->execute([$grupo_id]);
    $pdo->commit();

    redir($redir_estudiante, $matricula_id, $accion==='cambiar_grupo' ? 'Grupo actualizado.' : 'Grupo asignado.');
}

redir_index('Acción no reconocida.', 'danger');

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[acciones matriculas] '.$e->getMessage());
    if ($redir_estudiante)
        redir($redir_estudiante, $matricula_id, 'Error interno: '.$e->getMessage(), 'danger');
    else
        redir_index('Error interno: '.$e->getMessage(), 'danger');
}