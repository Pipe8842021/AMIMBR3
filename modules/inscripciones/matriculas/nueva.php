<?php
/**
 * Nueva Matrícula
 * Si se selecciona grupo, genera automáticamente las cuotas mensuales del curso.
 */
require_once '../../../config/session.php';
require_once '../../../config/database.php';
require_once '../../../includes/auth_check.php';
require_role('admin');

// ── Función de generación de pagos (misma lógica que acciones.php) ──────────
// ── Genera 1 solo pago (mes actual) — igual que acciones.php ────
function generar_pago_mes(PDO $pdo, int $matricula_id, int $estudiante_id,
    float $precio, string $fecha_base, string $curso_nombre, int $admin_id): bool {
    $meses_es = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio',
                 'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    $dt      = new DateTime($fecha_base);
    $dia     = (int)$dt->format('j');
    // Usar el mes/año actuales pero con el día de la matrícula
    $hoy     = new DateTime();
    $ultimo  = (int)$hoy->format('t');
    $dia_ok  = min($dia, $ultimo);
    $hoy->setDate((int)$hoy->format('Y'), (int)$hoy->format('n'), $dia_ok);
    $anio_v  = (int)$hoy->format('Y');
    $mes_v   = (int)$hoy->format('n');
    $fvence  = $hoy->format('Y-m-d');
    // No duplicar
    $chk = $pdo->prepare("SELECT COUNT(*) FROM pagos WHERE matricula_id=? AND YEAR(fecha_vencimiento)=? AND MONTH(fecha_vencimiento)=? AND estado!='anulado'");
    $chk->execute([$matricula_id, $anio_v, $mes_v]);
    if ((int)$chk->fetchColumn() > 0) return false;
    $pdo->prepare("INSERT INTO pagos (estudiante_id,matricula_id,monto,concepto,metodo_pago,estado,fecha_vencimiento,registrado_por) VALUES (?,?,?,?,'efectivo','pendiente',?,?)")
        ->execute([$estudiante_id,$matricula_id,$precio,"Mensualidad {$meses_es[$mes_v]} $anio_v — $curso_nombre",$fvence,$admin_id]);
    return true;
}

// ── Procesar POST ────────────────────────────────────────────
$error          = '';
// Prellenar estudiante si viene por GET (desde el detalle)
$pre_estudiante = (int)($_GET['estudiante_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $estudiante_id = (int)($_POST['estudiante_id'] ?? 0);
    $grupo_id      = (int)($_POST['grupo_id'] ?? 0) ?: null;
    $fecha_inicio  = $_POST['fecha_inicio'] ?? date('Y-m-d');
    $observaciones = trim($_POST['observaciones'] ?? '');

    if (!$estudiante_id) {
        $error = 'Debes seleccionar un estudiante.';
    } else {
        try {
            if ($grupo_id) {
                $chk = $pdo->prepare("SELECT id FROM matriculas WHERE estudiante_id=? AND grupo_id=? AND estado='activa'");
                $chk->execute([$estudiante_id, $grupo_id]);
                if ($chk->fetch()) $error = 'Este estudiante ya tiene una matrícula activa en ese grupo.';
            }
            if (!$error && $grupo_id) {
                $gq = $pdo->prepare("SELECT cupo_actual, cupo_maximo FROM grupos WHERE id=?");
                $gq->execute([$grupo_id]); $gr = $gq->fetch();
                if ($gr && $gr['cupo_actual'] >= $gr['cupo_maximo'])
                    $error = 'El grupo seleccionado no tiene cupo disponible.';
            }

            if (!$error) {
                $pdo->beginTransaction();

                $pdo->prepare("INSERT INTO matriculas (estudiante_id,grupo_id,fecha_matricula,fecha_inicio,estado,observaciones) VALUES (?,?,CURDATE(),?,'activa',?)")
                    ->execute([$estudiante_id, $grupo_id, $fecha_inicio ?: null, $observaciones ?: null]);
                $nueva_id  = $pdo->lastInsertId();
                $fecha_hoy = date('Y-m-d');

                if ($grupo_id) {
                    $pdo->prepare("UPDATE grupos SET cupo_actual=cupo_actual+1 WHERE id=?")->execute([$grupo_id]);
                }

                // Generar 1 solo pago del mes actual
                $pago_generado = false;
                if ($grupo_id) {
                    $cq = $pdo->prepare("SELECT c.precio_mensual, c.nombre AS curso_nombre FROM grupos g JOIN cursos c ON g.curso_id=c.id WHERE g.id=?");
                    $cq->execute([$grupo_id]); $curso = $cq->fetch(PDO::FETCH_ASSOC);
                    if ($curso && $curso['precio_mensual'] > 0) {
                        $pago_generado = generar_pago_mes($pdo, $nueva_id, $estudiante_id,
                            (float)$curso['precio_mensual'], $fecha_hoy, $curso['curso_nombre'], $_SESSION['user_id']);
                    }
                }

                $pdo->prepare("INSERT INTO logs_actividad (usuario_id,accion,detalles,ip_address) VALUES (?,?,?,?)")
                    ->execute([$_SESSION['user_id'],'matricula_creada',"Nueva matrícula #$nueva_id — Estudiante ID $estudiante_id",$_SERVER['REMOTE_ADDR']??null]);
                $pdo->commit();

                $msg = 'Matrícula creada correctamente';
                if ($pago_generado)   $msg .= '. Se generó el primer pago mensual.';
                elseif (!$grupo_id)   $msg .= '. Asigna un grupo para generar el primer pago.';

                header("Location: detalle.php?estudiante=$estudiante_id&tab=$nueva_id&msg=".urlencode($msg)."&type=success");
                exit;
            }

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log($e->getMessage());
            $error = 'Error del sistema al crear la matrícula.';
        }
    }

    if ($error) {
        header("Location: index.php?msg=" . urlencode($error) . "&type=error&open_modal=1");
        exit;
    }
}

// ── Cargar datos para el formulario ─────────────────────────
try {
    $estudiantes = $pdo->query("
        SELECT id, nombre, email, documento
        FROM usuarios
        WHERE rol = 'estudiante' AND estado = 'activo'
        ORDER BY nombre
    ")->fetchAll(PDO::FETCH_ASSOC);

    $grupos_raw = $pdo->query("
        SELECT g.id, g.nombre, g.horario, g.cupo_actual, g.cupo_maximo,
               c.nombre as curso_nombre, c.precio_mensual, c.duracion_meses,
               u.nombre as profesor_nombre
        FROM grupos g
        INNER JOIN cursos c ON g.curso_id = c.id
        LEFT JOIN usuarios u ON g.profesor_id = u.id
        WHERE g.estado IN ('activo','planificado')
          AND g.cupo_actual < g.cupo_maximo
        ORDER BY c.nombre, g.nombre
    ")->fetchAll(PDO::FETCH_ASSOC);

    $grupos_por_curso = [];
    foreach ($grupos_raw as $g) $grupos_por_curso[$g['curso_nombre']][] = $g;

} catch (PDOException $e) {
    $estudiantes = []; $grupos_por_curso = [];
}
?>