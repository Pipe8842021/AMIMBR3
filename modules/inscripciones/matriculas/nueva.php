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
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nueva Matrícula — Amimbré</title>
    <link rel="shortcut icon" href="../../../assets/img/3.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,1,0"/>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="../../../assets/css/colores.css">
    <link rel="stylesheet" href="../../../assets/css/style-matriculas.css">
    <script>(function(){ const t=localStorage.getItem('amimbre-theme'); if(t==='light') document.documentElement.setAttribute('data-theme','light'); })();</script>
</head>
<body>
<?php require_once '../../../includes/header.php'; ?>
<main class="main-content">

    <div class="dashboard-header">
        <div class="dashboard-title">
            <a href="index.php" class="back-link">
                <span class="material-symbols-rounded">arrow_back</span> Matrículas
            </a>
            <h1>Nueva Matrícula</h1>
            <p>Registra una nueva matrícula y se generarán los pagos mensuales automáticamente</p>
        </div>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger">
        <span class="material-symbols-rounded">error</span>
        <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <div class="form-page-container">
        <div class="card">
            <div class="card-header">
                <span class="material-symbols-rounded card-header-icon">person_add</span>
                <h3>Datos de la matrícula</h3>
            </div>
            <div class="card-body">

                <!-- Info sobre la generación automática -->
                <div class="nueva-mat-info">
                    <span class="material-symbols-rounded">auto_awesome</span>
                    <div>
                        <strong>Generación automática de pagos</strong>
                        <p>Al seleccionar un grupo, se crearán automáticamente las cuotas mensuales
                           del curso — una por cada mes de duración, con vencimiento el mismo día
                           del mes en que se registra la matrícula.</p>
                    </div>
                </div>

                <form method="POST" class="form-nueva-matricula" id="formNueva">

                    <!-- Buscador + select de estudiante -->
                    <div class="form-group">
                        <label class="form-label">
                            Estudiante <span style="color:var(--primary-orange)">*</span>
                        </label>
                        <div class="search-input-wrap" style="margin-bottom:8px;">
                            <span class="material-symbols-rounded search-icon">search</span>
                            <input type="text" id="buscarEstudiante" class="search-input"
                                   placeholder="Buscar por nombre, email o documento…"
                                   autocomplete="off">
                        </div>
                        <select name="estudiante_id" id="selectEstudiante"
                                class="form-control" required
                                size="5" style="height:auto; max-height:200px; overflow-y:auto;">
                            <option value="">— Busca y selecciona —</option>
                            <?php foreach ($estudiantes as $e): ?>
                            <option value="<?= $e['id'] ?>"
                                    data-texto="<?= strtolower($e['nombre'].' '.$e['email'].' '.$e['documento']) ?>"
                                    <?= $pre_estudiante === $e['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($e['nombre']) ?> — <?= htmlspecialchars($e['email']) ?>
                                (<?= htmlspecialchars($e['documento']) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="form-hint">Clic en el nombre para seleccionar</span>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">
                                Grupo
                                <span class="form-hint" style="display:inline; text-transform:none; font-size:0.75rem;">(opcional — genera pagos automáticos)</span>
                            </label>
                            <select name="grupo_id" id="selectGrupo" class="form-control"
                                    onchange="mostrarInfoGrupo(this)">
                                <option value="">— Sin grupo asignado —</option>
                                <?php foreach ($grupos_por_curso as $curso_nombre => $gs): ?>
                                <optgroup label="<?= htmlspecialchars($curso_nombre) ?>">
                                    <?php foreach ($gs as $g): ?>
                                    <option value="<?= $g['id'] ?>"
                                            data-precio="<?= $g['precio_mensual'] ?>"
                                            data-meses="<?= $g['duracion_meses'] ?>"
                                            data-curso="<?= htmlspecialchars($g['curso_nombre']) ?>">
                                        <?= htmlspecialchars($g['nombre']) ?>
                                        · <?= htmlspecialchars($g['horario'] ?? '—') ?>
                                        (<?= $g['cupo_actual'] ?>/<?= $g['cupo_maximo'] ?> cupos)
                                        <?= $g['profesor_nombre'] ? '· '.$g['profesor_nombre'] : '' ?>
                                    </option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <?php endforeach; ?>
                            </select>
                            <!-- Preview de pagos a generar -->
                            <div id="infoGrupo" class="grupo-preview" style="display:none;"></div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Fecha de inicio</label>
                            <input type="date" name="fecha_inicio" class="form-control"
                                   value="<?= date('Y-m-d') ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Observaciones</label>
                        <textarea name="observaciones" class="form-control" rows="3"
                                  placeholder="Información adicional sobre la matrícula…"></textarea>
                    </div>

                    <div class="form-actions">
                        <a href="index.php" class="btn-secondary">Cancelar</a>
                        <button type="submit" class="btn-primary">
                            <span class="material-symbols-rounded">save</span> Crear matrícula
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

</main>

<script>
// Filtro de búsqueda en select de estudiantes
document.getElementById('buscarEstudiante').addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#selectEstudiante option').forEach(opt => {
        if (!opt.value) return;
        opt.style.display = (opt.dataset.texto || '').includes(q) ? '' : 'none';
    });
});

// Preview de pagos al seleccionar grupo
function mostrarInfoGrupo(sel) {
    const opt    = sel.options[sel.selectedIndex];
    const info   = document.getElementById('infoGrupo');
    const precio = parseFloat(opt.dataset.precio || 0);
    const curso  = opt.dataset.curso || '';

    if (!sel.value || precio <= 0) {
        info.style.display = 'none';
        return;
    }

    const fmt       = n => '$' + n.toLocaleString('es-CO');
    const hoy       = new Date();
    const dia       = hoy.getDate();
    const mesesNom  = ['Enero','Febrero','Marzo','Abril','Mayo','Junio',
                       'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    const mesActual = mesesNom[hoy.getMonth()];

    info.style.display = 'flex';
    info.innerHTML = `
        <span class="material-symbols-rounded">auto_awesome</span>
        <div>
            <strong>Se generará 1 pago al matricular</strong>
            <p>Mensualidad <strong>${mesActual} ${hoy.getFullYear()}</strong> · ${fmt(precio)} · Vence el día <strong>${dia}</strong>.
            El siguiente pago se generará automáticamente al marcar este como pagado.</p>
        </div>
    `;
}
</script>
</body>
</html>