<?php
/**
 * Grupos – Ver detalle del grupo
 * Accesible por admin y profesor (solo su grupo)
 */
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth_check.php';
require_any_role(['admin','profesor']);

$id  = (int)($_GET['id'] ?? 0);
$rol = $_SESSION['user_rol'];
$uid = (int)$_SESSION['user_id'];

if (!$id) { header("Location: index.php"); exit; }

$inline_error   = null;
$inline_success = null;
$open_modal     = null; // nombre del modal a reabrir en error

// ── Handler: eliminar grupo ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'eliminar_grupo') {
    $id_del = (int)($_POST['id_del'] ?? 0);
    if ($id_del === $id) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM matriculas WHERE grupo_id = ? AND estado = 'activa'");
            $stmt->execute([$id_del]);
            if ((int)$stmt->fetchColumn() > 0) {
                $inline_error = "No se puede eliminar: el grupo tiene matrículas activas. Cambia el estado a <strong>Cancelado</strong> primero.";
                $open_modal = 'modalEliminar';
            } else {
                $pdo->prepare("DELETE FROM horarios WHERE grupo_id = ?")->execute([$id_del]);
                $pdo->prepare("DELETE FROM grupos   WHERE id = ?")->execute([$id_del]);
                header("Location: " . ($rol === 'admin' ? 'admin.php' : 'profesor.php') . "?msg=eliminado");
                exit;
            }
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $inline_error = "Error al eliminar el grupo.";
            $open_modal = 'modalEliminar';
        }
    }
}

// ── Handler: editar grupo ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'editar_grupo') {
    $nombre       = trim($_POST['nombre']       ?? '');
    $curso_id     = (int)($_POST['curso_id']    ?? 0);
    $profesor_id  = (int)($_POST['profesor_id'] ?? 0);
    $cupo_maximo  = (int)($_POST['cupo_maximo'] ?? 20);
    $horario      = trim($_POST['horario']      ?? '');
    $aula         = trim($_POST['aula']         ?? '');
    $fecha_inicio = $_POST['fecha_inicio']      ?? '';
    $fecha_fin    = $_POST['fecha_fin']         ?: null;
    $estado       = $_POST['estado']            ?? 'planificado';
    if (!$nombre || !$curso_id || !$fecha_inicio) {
        $inline_error = "Completa los campos obligatorios: nombre, curso y fecha de inicio.";
        $open_modal = 'modalEditarGrupo';
    } else {
        try {
            $pdo->prepare("
                UPDATE grupos SET nombre=?, curso_id=?, profesor_id=?, cupo_maximo=?,
                    horario=?, aula=?, fecha_inicio=?, fecha_fin=?, estado=?
                WHERE id=?
            ")->execute([$nombre, $curso_id, $profesor_id ?: null, $cupo_maximo,
                         $horario, $aula ?: null, $fecha_inicio, $fecha_fin, $estado, $id]);
            header("Location: ver.php?id=$id&msg=editado");
            exit;
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $inline_error = "Error al actualizar el grupo.";
            $open_modal = 'modalEditarGrupo';
        }
    }
}

// ── Handler: guardar asistencia / bitácora ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'guardar_asistencia') {
    $titulo           = trim($_POST['titulo']        ?? '');
    $fecha_clase      = $_POST['fecha_clase']        ?? '';
    $hora_inicio      = $_POST['hora_inicio']        ?? '';
    $hora_fin         = $_POST['hora_fin']           ?? '';
    $temas            = trim($_POST['temas']         ?? '');
    $descripcion      = trim($_POST['descripcion']   ?? '');
    $observaciones_b  = trim($_POST['observaciones'] ?? '');
    $compromisos      = trim($_POST['compromisos']   ?? '');
    $asistencias_post = $_POST['asistencia']         ?? [];
    $obs_est_post     = $_POST['obs_est']            ?? [];

    if (!$titulo || !$fecha_clase || !$hora_inicio || !$hora_fin || !$temas) {
        $inline_error = "Completa los campos obligatorios de la bitácora (título, fecha, horas y temas).";
        $open_modal = 'modalAsistencia';
    } elseif (empty($asistencias_post)) {
        $inline_error = "Registra la asistencia de al menos un estudiante.";
        $open_modal = 'modalAsistencia';
    } else {
        try {
            $pdo->beginTransaction();
            $stmt_g = $pdo->prepare("SELECT curso_id, profesor_id FROM grupos WHERE id = ?");
            $stmt_g->execute([$id]);
            $g_data = $stmt_g->fetch(PDO::FETCH_ASSOC);
            $prof_id_b = ($rol === 'profesor') ? $uid : (int)($g_data['profesor_id'] ?? 0);

            $stmt_b = $pdo->prepare("
                INSERT INTO bitacoras
                    (grupo_id, curso_id, profesor_id, titulo, fecha_clase, hora_inicio, hora_fin,
                     temas_tratados, descripcion_clase, observaciones, compromisos_proxima_clase)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt_b->execute([$id, $g_data['curso_id'], $prof_id_b ?: null, $titulo, $fecha_clase,
                              $hora_inicio, $hora_fin, $temas, $descripcion,
                              $observaciones_b ?: null, $compromisos ?: null]);
            $bitacora_id = $pdo->lastInsertId();

            $stmt_m = $pdo->prepare("SELECT estudiante_id, id AS matricula_id FROM matriculas WHERE grupo_id = ? AND estado = 'activa'");
            $stmt_m->execute([$id]);
            $mat_map = [];
            foreach ($stmt_m->fetchAll(PDO::FETCH_ASSOC) as $m) {
                $mat_map[$m['estudiante_id']] = $m['matricula_id'];
            }

            $stmt_ba = $pdo->prepare("INSERT INTO bitacoras_asistencias (bitacora_id, estudiante_id, estado, observacion) VALUES (?, ?, ?, ?)");
            $stmt_as = $pdo->prepare("INSERT INTO asistencias (matricula_id, fecha, estado, observaciones, registrado_por) VALUES (?, ?, ?, ?, ?)");

            foreach ($asistencias_post as $est_id => $est_estado) {
                $est_id = (int)$est_id;
                $obs_e  = trim($obs_est_post[$est_id] ?? '');
                if (!in_array($est_estado, ['presente','ausente','justificado','tardanza'])) $est_estado = 'ausente';
                $stmt_ba->execute([$bitacora_id, $est_id, $est_estado, $obs_e ?: null]);
                if (isset($mat_map[$est_id])) {
                    $stmt_as->execute([$mat_map[$est_id], $fecha_clase, $est_estado, $obs_e ?: null, $uid]);
                }
            }

            $pdo->commit();

            // Evidencias fotográficas
            if (!empty($_FILES['evidencias']['name'][0])) {
                $upload_dir    = '../../assets/uploads/bitacoras/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                $tipos_validos = ['image/jpeg','image/png','image/webp'];
                $total_ev      = min(count($_FILES['evidencias']['name']), 5);
                $stmt_ev = $pdo->prepare("INSERT INTO bitacoras_evidencias (bitacora_id, nombre_archivo, ruta_archivo, descripcion, orden) VALUES (?, ?, ?, ?, ?)");
                for ($i = 0; $i < $total_ev; $i++) {
                    $arch = $_FILES['evidencias'];
                    if ($arch['error'][$i] !== UPLOAD_ERR_OK || $arch['size'][$i] > 5*1024*1024 || !in_array($arch['type'][$i], $tipos_validos)) continue;
                    $ext  = strtolower(pathinfo($arch['name'][$i], PATHINFO_EXTENSION));
                    $unic = 'ev_' . $bitacora_id . '_' . $i . '_' . time() . '.' . $ext;
                    $ruta = 'assets/uploads/bitacoras/' . $unic;
                    $desc_ev = trim($_POST['ev_desc'][$i] ?? '');
                    if (move_uploaded_file($arch['tmp_name'][$i], '../../' . $ruta)) {
                        $stmt_ev->execute([$bitacora_id, $arch['name'][$i], $ruta, $desc_ev ?: null, $i]);
                    }
                }
            }

            header("Location: ver.php?id=$id&msg=asistencia");
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log($e->getMessage());
            $inline_error = "Error al guardar la asistencia: " . $e->getMessage();
            $open_modal = 'modalAsistencia';
        }
    }
}

// Mensajes flash GET
$msg_map = [
    'creado'     => ['type' => 'success', 'text' => 'Grupo creado correctamente.'],
    'editado'    => ['type' => 'success', 'text' => 'Grupo actualizado correctamente.'],
    'asistencia' => ['type' => 'success', 'text' => 'Asistencia y bitácora registradas correctamente.'],
];
$flash_msg = $msg_map[$_GET['msg'] ?? ''] ?? null;
if (!$flash_msg && $inline_error)   $flash_msg = ['type' => 'danger',  'text' => $inline_error];
if (!$flash_msg && $inline_success) $flash_msg = ['type' => 'success', 'text' => $inline_success];

try {
    $stmt = $pdo->prepare("
        SELECT g.*, c.nombre AS curso_nombre, c.nivel AS curso_nivel,
               c.descripcion AS curso_desc, u.nombre AS profesor_nombre,
               u.email AS profesor_email
        FROM grupos g
        JOIN cursos c ON g.curso_id = c.id
        LEFT JOIN usuarios u ON g.profesor_id = u.id
        WHERE g.id = ?
    ");
    $stmt->execute([$id]);
    $grupo = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$grupo) { header("Location: index.php"); exit; }

    // El profesor solo puede ver sus grupos
    if ($rol === 'profesor' && $grupo['profesor_id'] != $uid) {
        header("Location: index.php"); exit;
    }

    // Datos para modales
    $cursos_edit    = $pdo->query("SELECT id, nombre, nivel FROM cursos WHERE estado='activo' ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
    $profesores_edit = $pdo->query("SELECT id, nombre FROM usuarios WHERE rol='profesor' AND estado='activo' ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

    // Estudiantes matriculados
    $stmt = $pdo->prepare("
        SELECT u.id, u.nombre, u.email, u.telefono, u.foto_perfil,
               m.id AS matricula_id, m.fecha_matricula, m.estado AS estado_matricula
        FROM matriculas m
        JOIN usuarios u ON m.estudiante_id = u.id
        WHERE m.grupo_id = ? AND m.estado = 'activa'
        ORDER BY u.nombre
    ");
    $stmt->execute([$id]);
    $estudiantes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Horarios del grupo
    $stmt = $pdo->prepare("
        SELECT * FROM horarios WHERE grupo_id = ?
        ORDER BY FIELD(dia_semana,'lunes','martes','miercoles','jueves','viernes','sabado','domingo')
    ");
    $stmt->execute([$id]);
    $horarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Últimas bitácoras
    $stmt = $pdo->prepare("
        SELECT b.id, b.titulo, b.fecha_clase, b.hora_inicio, b.hora_fin,
               b.temas_tratados, b.estado,
               (SELECT COUNT(*) FROM bitacoras_asistencias ba WHERE ba.bitacora_id = b.id) AS total_asist,
               (SELECT COUNT(*) FROM bitacoras_asistencias ba WHERE ba.bitacora_id = b.id AND ba.estado='presente') AS presentes
        FROM bitacoras b
        WHERE b.grupo_id = ? AND b.estado = 'activo'
        ORDER BY b.fecha_clase DESC
        LIMIT 8
    ");
    $stmt->execute([$id]);
    $bitacoras = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Estadísticas de asistencia del grupo
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN ba.estado='presente'    THEN 1 ELSE 0 END) AS presentes,
            SUM(CASE WHEN ba.estado='ausente'     THEN 1 ELSE 0 END) AS ausentes,
            SUM(CASE WHEN ba.estado='justificado' THEN 1 ELSE 0 END) AS justificados,
            SUM(CASE WHEN ba.estado='tardanza'    THEN 1 ELSE 0 END) AS tardanzas
        FROM bitacoras_asistencias ba
        JOIN bitacoras b ON ba.bitacora_id = b.id
        WHERE b.grupo_id = ?
    ");
    $stmt->execute([$id]);
    $stats_asist = $stmt->fetch(PDO::FETCH_ASSOC);
    $pct_asist = $stats_asist['total'] > 0
        ? round(($stats_asist['presentes'] / $stats_asist['total']) * 100) : 0;


    $stmt = $pdo->prepare("
        SELECT id, titulo, fecha_clase
        FROM bitacoras
        WHERE grupo_id = ? AND estado = 'activo'
        ORDER BY fecha_clase DESC
        LIMIT 10
    ");
    $stmt->execute([$id]);
    $bitacoras_asist = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Para cada bitácora, cargar sus registros de asistencia de una sola vez
    $registros_asist = [];
    if (count($bitacoras_asist) > 0) {
        $ids_bitacoras = array_column($bitacoras_asist, 'id');
        $placeholders  = implode(',', array_fill(0, count($ids_bitacoras), '?'));
        $stmt = $pdo->prepare("
            SELECT bitacora_id, estudiante_id, estado, observacion
            FROM bitacoras_asistencias
            WHERE bitacora_id IN ($placeholders)
        ");
        $stmt->execute($ids_bitacoras);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $registros_asist[$r['bitacora_id']][$r['estudiante_id']] = $r;
        }
    }

    // Totales por estudiante (para la columna de resumen)
    $stmt = $pdo->prepare("
        SELECT
            ba.estudiante_id,
            COUNT(*) AS total,
            SUM(CASE WHEN ba.estado='presente'    THEN 1 ELSE 0 END) AS presentes,
            SUM(CASE WHEN ba.estado='ausente'     THEN 1 ELSE 0 END) AS ausentes,
            SUM(CASE WHEN ba.estado='justificado' THEN 1 ELSE 0 END) AS justificados,
            SUM(CASE WHEN ba.estado='tardanza'    THEN 1 ELSE 0 END) AS tardanzas
        FROM bitacoras_asistencias ba
        JOIN bitacoras b ON ba.bitacora_id = b.id
        WHERE b.grupo_id = ?
        GROUP BY ba.estudiante_id
    ");
    $stmt->execute([$id]);
    $totales_est = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $totales_est[$t['estudiante_id']] = $t;
    }

} catch (PDOException $e) {
    error_log($e->getMessage());
    header("Location: index.php"); exit;
}

$estado_cfg = [
    'planificado' => ['cls' => 'badge-info',    'txt' => 'Planificado'],
    'activo'      => ['cls' => 'badge-success',  'txt' => 'Activo'],
    'finalizado'  => ['cls' => 'badge-warning',  'txt' => 'Finalizado'],
    'cancelado'   => ['cls' => 'badge-danger',   'txt' => 'Cancelado'],
];
$nivel_cfg = ['basico' => 'badge-info', 'intermedio' => 'badge-warning', 'avanzado' => 'badge-danger'];
$dias_es   = ['lunes'=>'Lunes','martes'=>'Martes','miercoles'=>'Miércoles','jueves'=>'Jueves','viernes'=>'Viernes','sabado'=>'Sábado','domingo'=>'Domingo'];
$meses_es  = ['','Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];

$est_g = $estado_cfg[$grupo['estado']] ?? $estado_cfg['planificado'];
$niv_g = $nivel_cfg[strtolower($grupo['curso_nivel'])] ?? 'badge-info';
$pct_cupo = $grupo['cupo_maximo'] > 0 ? round(($grupo['cupo_actual'] / $grupo['cupo_maximo']) * 100) : 0;
$bar_cls  = $pct_cupo >= 90 ? 'bar-danger' : ($pct_cupo >= 70 ? 'bar-warning' : 'bar-success');

function iniciales($nombre) {
    return implode('', array_map(fn($p) => strtoupper($p[0]), array_slice(explode(' ', $nombre), 0, 2)));
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($grupo['nombre']); ?> – Amimbré</title>
    <link rel="shortcut icon" href="../../assets/img/3.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,1,0">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="../../assets/css/colores.css">
    <link rel="stylesheet" href="../../assets/css/style-grupos.css">
    <script>(function(){ const t=localStorage.getItem('amimbre-theme'); if(t==='light') document.documentElement.setAttribute('data-theme','light'); })();</script>
</head>
<body>
<?php require_once '../../includes/header.php'; ?>
<main class="main-content">

    <?php if ($flash_msg): ?>
    <div class="alert alert-<?php echo $flash_msg['type']; ?>">
        <span class="material-symbols-rounded">check_circle</span>
        <?php echo $flash_msg['text']; ?>
    </div>
    <?php endif; ?>

    <!-- ── Encabezado del detalle ────────────────────────────────────────── -->
    <div class="detalle-header">
        <div class="detalle-titulo">
            <div style="display:flex; align-items:center; gap:10px; margin-bottom:8px;">
                <a href="<?php echo $rol==='admin' ? 'admin.php' : 'profesor.php'; ?>" class="btn-action back" style="padding:6px 10px;">
                    <span class="material-symbols-rounded">arrow_back</span>
                </a>
                <h2><?php echo htmlspecialchars($grupo['nombre']); ?></h2>
                <span class="badge <?php echo $est_g['cls']; ?>"><?php echo $est_g['txt']; ?></span>
                <span class="badge <?php echo $niv_g; ?>"><?php echo ucfirst($grupo['curso_nivel']); ?></span>
            </div>
            <div class="meta-row">
                <span><span class="material-symbols-rounded">menu_book</span><?php echo htmlspecialchars($grupo['curso_nombre']); ?></span>
                <?php if ($grupo['profesor_nombre']): ?>
                <span><span class="material-symbols-rounded">person_play</span><?php echo htmlspecialchars($grupo['profesor_nombre']); ?></span>
                <?php endif; ?>
                <?php if ($grupo['horario']): ?>
                <span><span class="material-symbols-rounded">schedule</span><?php echo htmlspecialchars($grupo['horario']); ?></span>
                <?php endif; ?>
                <?php if ($grupo['aula']): ?>
                <span><span class="material-symbols-rounded">meeting_room</span><?php echo htmlspecialchars($grupo['aula']); ?></span>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($rol === 'admin'): ?>
        <div class="detalle-actions">
            <button type="button" class="btn-action" onclick="abrirModalAsistencia()">
                <span class="material-symbols-rounded">fact_check</span> Registrar asistencia
            </button>
            <button type="button" class="btn-action edit" onclick="abrirModalEditar()">
                <span class="material-symbols-rounded">edit</span> Editar
            </button>
            <button type="button" class="btn-action danger" onclick="abrirModalEliminar(<?php echo $id; ?>, '<?php echo addslashes(htmlspecialchars($grupo['nombre'])); ?>')">
                <span class="material-symbols-rounded">delete</span>
            </button>
        </div>
        <?php else: ?>
        <div class="detalle-actions">
            <button type="button" class="btn-action" onclick="abrirModalAsistencia()">
                <span class="material-symbols-rounded">fact_check</span> Registrar asistencia
            </button>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Fila de info rápida ───────────────────────────────────────────── -->
    <div class="detalle-grid" style="margin-bottom:20px;">

        <div class="info-card">
            <div class="info-card-title">
                <span class="material-symbols-rounded">info</span> Información del grupo
            </div>
            <div class="info-row">
                <span class="label">Fecha inicio</span>
                <span class="value"><?php echo date('d/m/Y', strtotime($grupo['fecha_inicio'])); ?></span>
            </div>
            <div class="info-row">
                <span class="label">Fecha fin</span>
                <span class="value"><?php echo $grupo['fecha_fin'] ? date('d/m/Y', strtotime($grupo['fecha_fin'])) : '—'; ?></span>
            </div>
            <div class="info-row">
                <span class="label">Horario</span>
                <span class="value"><?php echo htmlspecialchars($grupo['horario'] ?: '—'); ?></span>
            </div>
            <div class="info-row">
                <span class="label">Aula</span>
                <span class="value"><?php echo htmlspecialchars($grupo['aula'] ?: '—'); ?></span>
            </div>
            <div class="info-row">
                <span class="label">Bitácoras registradas</span>
                <span class="value"><?php echo count($bitacoras); ?></span>
            </div>
        </div>

        <div class="info-card">
            <div class="info-card-title">
                <span class="material-symbols-rounded">people</span> Ocupación y asistencia
            </div>
            <div class="ocupacion-wrap" style="margin-bottom:14px;">
                <div class="ocupacion-numero"><?php echo $grupo['cupo_actual']; ?><span style="font-size:1rem; font-weight:400; color:var(--text-secondary);">/<?php echo $grupo['cupo_maximo']; ?></span></div>
                <div class="ocupacion-label">estudiantes matriculados</div>
                <div class="group-bar" style="margin-top:8px;">
                    <div class="group-bar-fill <?php echo $bar_cls; ?>" style="width:<?php echo $pct_cupo; ?>%"></div>
                </div>
            </div>
            <div class="info-row">
                <span class="label">% Asistencia general</span>
                <span class="value <?php echo $pct_asist >= 75 ? 'positive' : 'negative'; ?>"
                      style="color:<?php echo $pct_asist >= 75 ? 'var(--primary-green)' : 'var(--primary-orange)'; ?>; font-weight:700;">
                    <?php echo $pct_asist; ?>%
                </span>
            </div>
            <div class="info-row">
                <span class="label">Registros totales</span>
                <span class="value"><?php echo $stats_asist['total']; ?></span>
            </div>
            <div class="info-row">
                <span class="label">Presentes / Ausentes</span>
                <span class="value"><?php echo $stats_asist['presentes']; ?> / <?php echo $stats_asist['ausentes']; ?></span>
            </div>
        </div>

    </div>

    <!-- ── Horarios configurados ─────────────────────────────────────────── -->
    <?php if (count($horarios) > 0): ?>
    <div class="card" style="margin-bottom:20px;">
        <div class="section-header">
            <div>
                <h3 class="section-title">Horario Semanal</h3>
                <p class="section-subtitle">Días y horas de clase</p>
            </div>
        </div>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <?php foreach ($horarios as $h): ?>
            <div class="event-item" style="flex:1; min-width:200px;">
                <div class="event-date-box">
                    <div class="day"><?php echo strtoupper(substr($dias_es[$h['dia_semana']] ?? $h['dia_semana'], 0, 3)); ?></div>
                    <div class="month"><?php echo date('H:i', strtotime($h['hora_inicio'])); ?></div>
                </div>
                <div class="event-content">
                    <div class="event-title"><?php echo ucfirst($dias_es[$h['dia_semana']] ?? $h['dia_semana']); ?></div>
                    <div class="event-meta">
                        <span class="material-symbols-rounded">schedule</span>
                        <?php echo date('H:i', strtotime($h['hora_inicio'])); ?> – <?php echo date('H:i', strtotime($h['hora_fin'])); ?>
                        <?php if ($h['aula']): ?>
                        &nbsp;·&nbsp;<span class="material-symbols-rounded">meeting_room</span><?php echo htmlspecialchars($h['aula']); ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Fila: Estudiantes + Bitácoras ─────────────────────────────────── -->
    <div class="detalle-grid">

        <!-- Estudiantes -->
        <div class="card">
            <div class="section-header">
                <div>
                    <h3 class="section-title">Estudiantes Matriculados</h3>
                    <p class="section-subtitle"><?php echo count($estudiantes); ?> estudiante<?php echo count($estudiantes) != 1 ? 's' : ''; ?> activo<?php echo count($estudiantes) != 1 ? 's' : ''; ?></p>
                </div>
            </div>

            <?php if (count($estudiantes) > 0): ?>
            <div style="display:flex; flex-direction:column; gap:10px;">
                <?php foreach ($estudiantes as $est): ?>
                <div class="estudiante-row">
                    <div class="est-avatar"><?php echo iniciales($est['nombre']); ?></div>
                    <div class="est-info">
                        <div class="est-nombre"><?php echo htmlspecialchars($est['nombre']); ?></div>
                        <div class="est-meta"><?php echo htmlspecialchars($est['email']); ?></div>
                    </div>
                    <span class="badge badge-success" style="font-size:0.7rem;">Activo</span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <span class="material-symbols-rounded">person_off</span>
                <p>Sin estudiantes matriculados aún</p>
            </div>
            <?php endif; ?>
        </div>

        <!-- Bitácoras -->
        <div class="card">
            <div class="section-header">
                <div>
                    <h3 class="section-title">Bitácoras de Clase</h3>
                    <p class="section-subtitle">Clases registradas</p>
                </div>
                <?php if ($rol === 'profesor' || $rol === 'admin'): ?>
                <button type="button" class="btn-link" onclick="abrirModalAsistencia()">
                    + Nueva <span class="material-symbols-rounded">arrow_forward</span>
                </button>
                <?php endif; ?>
            </div>

            <?php if (count($bitacoras) > 0): ?>
            <div style="display:flex; flex-direction:column; gap:10px;">
                <?php foreach ($bitacoras as $b):
                    $dia  = date('d', strtotime($b['fecha_clase']));
                    $mes  = $meses_es[(int)date('n', strtotime($b['fecha_clase']))];
                    $pct_b = $b['total_asist'] > 0 ? round(($b['presentes'] / $b['total_asist']) * 100) : null;
                ?>
                <div class="bitacora-item">
                    <div class="bitacora-fecha">
                        <div class="day"><?php echo $dia; ?></div>
                        <div class="month"><?php echo $mes; ?></div>
                    </div>
                    <div class="bitacora-content">
                        <div class="bitacora-titulo"><?php echo htmlspecialchars($b['titulo']); ?></div>
                        <div class="bitacora-meta">
                            <span><?php echo date('H:i', strtotime($b['hora_inicio'])); ?> – <?php echo date('H:i', strtotime($b['hora_fin'])); ?></span>
                            <?php if ($pct_b !== null): ?>
                            <span>Asistencia: <?php echo $pct_b; ?>%</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <span class="material-symbols-rounded">menu_book</span>
                <p>Sin bitácoras registradas aún</p>
            </div>
            <?php endif; ?>
        </div>

    </div>

    <!-- ── Tabla de asistencia por estudiante ───────────────────────────── -->
    <div class="card" style="margin-bottom: 24px;">
        <div class="section-header">
            <div>
                <h3 class="section-title">Registro de Asistencia</h3>
                <p class="section-subtitle">
                    <?php if (count($bitacoras_asist) > 0): ?>
                        Últimas <?php echo count($bitacoras_asist); ?> clases · <?php echo count($estudiantes); ?> estudiante<?php echo count($estudiantes) != 1 ? 's' : ''; ?>
                    <?php else: ?>
                        Sin clases registradas aún
                    <?php endif; ?>
                </p>
            </div>
            <?php if ($rol === 'admin' || $rol === 'profesor'): ?>
            <button type="button" class="btn-submit" style="font-size:0.82rem; padding:8px 16px;" onclick="abrirModalAsistencia()">
                <span class="material-symbols-rounded">fact_check</span> Registrar clase
            </button>
            <?php endif; ?>
        </div>

        <?php if (count($bitacoras_asist) > 0 && count($estudiantes) > 0): ?>

        <?php
        // Config visual por estado
        $asist_cfg = [
            'presente'    => ['icono' => 'check_circle', 'cls' => 'presente',    'title' => 'Presente'],
            'ausente'     => ['icono' => 'cancel',       'cls' => 'ausente',     'title' => 'Ausente'],
            'justificado' => ['icono' => 'description',  'cls' => 'justificado', 'title' => 'Justificado'],
            'tardanza'    => ['icono' => 'schedule',     'cls' => 'tardanza',    'title' => 'Tardanza'],
        ];
        $meses_abr = ['','Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
        ?>

        <div class="asist-tabla-wrap">
            <table class="asist-tabla">
                <thead>
                    <tr>
                        <th class="asist-th-nombre">Estudiante</th>
                        <?php foreach ($bitacoras_asist as $bit): ?>
                        <th class="asist-th-fecha" title="<?php echo htmlspecialchars($bit['titulo']); ?>">
                            <div class="asist-fecha-col">
                                <span class="asist-fecha-dia"><?php echo date('d', strtotime($bit['fecha_clase'])); ?></span>
                                <span class="asist-fecha-mes"><?php echo $meses_abr[(int)date('n', strtotime($bit['fecha_clase']))]; ?></span>
                            </div>
                        </th>
                        <?php endforeach; ?>
                        <th class="asist-th-resumen">Resumen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($estudiantes as $est):
                        $tot = $totales_est[$est['id']] ?? ['total'=>0,'presentes'=>0,'ausentes'=>0,'justificados'=>0,'tardanzas'=>0];
                        $pct_e = $tot['total'] > 0 ? round(($tot['presentes'] / $tot['total']) * 100) : null;
                        $bar_e = $pct_e === null ? '' : ($pct_e >= 75 ? 'bar-success' : ($pct_e >= 50 ? 'bar-warning' : 'bar-danger'));
                    ?>
                    <tr>
                        <!-- Nombre del estudiante -->
                        <td class="asist-td-nombre">
                            <div style="display:flex; align-items:center; gap:9px;">
                                <div class="est-avatar" style="width:30px;height:30px;font-size:0.72rem;flex-shrink:0;">
                                    <?php echo iniciales($est['nombre']); ?>
                                </div>
                                <span><?php echo htmlspecialchars($est['nombre']); ?></span>
                            </div>
                        </td>

                        <!-- Celda por cada bitácora -->
                        <?php foreach ($bitacoras_asist as $bit):
                            $reg = $registros_asist[$bit['id']][$est['id']] ?? null;
                            $cfg = $reg ? ($asist_cfg[$reg['estado']] ?? null) : null;
                        ?>
                        <td class="asist-td-celda" title="<?php echo $reg ? $asist_cfg[$reg['estado']]['title'] . ($reg['observacion'] ? ': ' . htmlspecialchars($reg['observacion']) : '') : 'Sin registro'; ?>">
                            <?php if ($cfg): ?>
                                <span class="asist-icono <?php echo $cfg['cls']; ?>">
                                    <span class="material-symbols-rounded"><?php echo $cfg['icono']; ?></span>
                                </span>
                            <?php else: ?>
                                <span class="asist-icono sin-registro">—</span>
                            <?php endif; ?>
                        </td>
                        <?php endforeach; ?>

                        <!-- Resumen del estudiante -->
                        <td class="asist-td-resumen">
                            <?php if ($tot['total'] > 0): ?>
                            <div class="asist-resumen-cell">
                                <span class="asist-pct <?php echo $bar_e; ?>"><?php echo $pct_e; ?>%</span>
                                <div class="group-bar" style="width:60px;">
                                    <div class="group-bar-fill <?php echo $bar_e; ?>" style="width:<?php echo $pct_e; ?>%"></div>
                                </div>
                                <span class="asist-detalle">
                                    <span title="Presentes"  style="color:var(--primary-green);"><?php echo $tot['presentes']; ?>P</span>
                                    <span title="Ausentes"   style="color:var(--primary-orange);"><?php echo $tot['ausentes']; ?>A</span>
                                    <?php if ($tot['justificados'] > 0): ?>
                                    <span title="Justificados" style="color:var(--primary-yellow);"><?php echo $tot['justificados']; ?>J</span>
                                    <?php endif; ?>
                                    <?php if ($tot['tardanzas'] > 0): ?>
                                    <span title="Tardanzas" style="color:var(--primary-blue);"><?php echo $tot['tardanzas']; ?>T</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <?php else: ?>
                            <span style="color:var(--text-secondary); font-size:0.78rem;">Sin registros</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>

                <!-- Fila de totales por clase -->
                <tfoot>
                    <tr>
                        <td class="asist-td-nombre" style="font-weight:600; font-size:0.8rem; color:var(--text-secondary);">
                            % Asistencia
                        </td>
                        <?php foreach ($bitacoras_asist as $bit):
                            $regs_bit  = $registros_asist[$bit['id']] ?? [];
                            $total_bit = count($regs_bit);
                            $pres_bit  = count(array_filter($regs_bit, fn($r) => $r['estado'] === 'presente'));
                            $pct_bit   = $total_bit > 0 ? round(($pres_bit / $total_bit) * 100) : null;
                            $cls_bit   = $pct_bit === null ? '' : ($pct_bit >= 75 ? 'bar-success' : ($pct_bit >= 50 ? 'bar-warning' : 'bar-danger'));
                        ?>
                        <td class="asist-td-celda" style="text-align:center;">
                            <?php if ($pct_bit !== null): ?>
                            <span class="asist-pct <?php echo $cls_bit; ?>" style="font-size:0.72rem;">
                                <?php echo $pct_bit; ?>%
                            </span>
                            <?php else: ?>
                            <span style="color:var(--text-secondary); font-size:0.72rem;">—</span>
                            <?php endif; ?>
                        </td>
                        <?php endforeach; ?>
                        <td class="asist-td-resumen">
                            <span class="asist-pct <?php echo $pct_asist >= 75 ? 'bar-success' : ($pct_asist >= 50 ? 'bar-warning' : 'bar-danger'); ?>" style="font-size:0.82rem; font-weight:700;">
                                <?php echo $pct_asist; ?>% general
                            </span>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- Leyenda -->
        <div class="asist-leyenda">
            <?php foreach ($asist_cfg as $estado => $cfg): ?>
            <span class="asist-leyenda-item">
                <span class="asist-icono <?php echo $cfg['cls']; ?>" style="width:22px;height:22px;">
                    <span class="material-symbols-rounded" style="font-size:14px;"><?php echo $cfg['icono']; ?></span>
                </span>
                <?php echo $cfg['title']; ?>
            </span>
            <?php endforeach; ?>
            <span class="asist-leyenda-item">
                <span class="asist-icono sin-registro" style="width:22px;height:22px; font-size:0.8rem;">—</span>
                Sin registro
            </span>
        </div>

        <?php elseif (count($estudiantes) === 0): ?>
        <div class="empty-state">
            <span class="material-symbols-rounded">person_off</span>
            <p>Sin estudiantes matriculados en este grupo</p>
        </div>
        <?php else: ?>
        <div class="empty-state">
            <span class="material-symbols-rounded">fact_check</span>
            <p>Aún no se han registrado clases con asistencia</p>
            <?php if ($rol === 'admin' || $rol === 'profesor'): ?>
            <button type="button" class="btn-submit" style="margin-top:12px; font-size:0.82rem; padding:8px 16px;" onclick="abrirModalAsistencia()">
                <span class="material-symbols-rounded">add_circle</span> Registrar primera clase
            </button>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div>

</main>

<!-- ══════════════════════════════════════════════════════
     MODAL: Eliminar Grupo
═══════════════════════════════════════════════════════ -->
<div id="modalEliminar" class="modal-overlay">
    <div class="modal-content" style="max-width:460px;">
        <div class="modal-header">
            <div>
                <h3>Eliminar Grupo</h3>
                <p style="font-size:0.85rem;color:var(--text-secondary);">Esta acción no se puede deshacer</p>
            </div>
            <button onclick="cerrarModalEliminar()" class="btn-close-modal">
                <span class="material-symbols-rounded">close</span>
            </button>
        </div>
        <div class="modal-body" style="text-align:center;padding:16px 0 4px;">
            <div style="width:64px;height:64px;border-radius:16px;background:var(--subtle-red);color:var(--primary-red);display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
                <span class="material-symbols-rounded" style="font-size:32px;">delete_forever</span>
            </div>
            <p style="font-size:0.95rem;color:var(--text-primary);margin-bottom:8px;">
                ¿Eliminar el grupo<br>
                <strong id="del-nombre-grupo" style="color:var(--primary-red);"></strong>?
            </p>
            <p style="font-size:0.82rem;color:var(--text-secondary);margin-bottom:20px;">
                Se eliminarán los horarios asociados. Acción permanente.
            </p>
            <form method="POST">
                <input type="hidden" name="action" value="eliminar_grupo">
                <input type="hidden" name="id_del" id="del-id">
                <div style="display:flex;gap:10px;justify-content:center;">
                    <button type="button" onclick="cerrarModalEliminar()" class="btn-cancel">Cancelar</button>
                    <button type="submit" class="btn-submit danger">
                        <span class="material-symbols-rounded">delete_forever</span> Sí, eliminar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════
     MODAL: Editar Grupo
═══════════════════════════════════════════════════════ -->
<div id="modalEditarGrupo" class="modal-overlay">
    <div class="modal-content" style="max-width:660px;">
        <div class="modal-header">
            <div>
                <h3>Editar Grupo</h3>
                <p style="font-size:0.85rem;color:var(--text-secondary);">ID #<?php echo $id; ?> · Los campos con <span style="color:var(--primary-orange);">*</span> son obligatorios</p>
            </div>
            <button onclick="cerrarModalEditar()" class="btn-close-modal">
                <span class="material-symbols-rounded">close</span>
            </button>
        </div>
        <div class="modal-body">
            <form method="POST" id="formEditarModal" novalidate>
                <input type="hidden" name="action" value="editar_grupo">

                <div class="form-row">
                    <div class="input-group">
                        <label>Nombre del grupo <span class="req">*</span></label>
                        <input type="text" name="nombre" id="ver-edit-nombre" value="<?php echo htmlspecialchars($grupo['nombre']); ?>">
                    </div>
                </div>

                <div class="form-row form-row--2">
                    <div class="input-group">
                        <label>Curso <span class="req">*</span></label>
                        <select name="curso_id" id="ver-edit-curso_id">
                            <option value="">Seleccionar...</option>
                            <?php foreach ($cursos_edit as $c): ?>
                                <option value="<?= $c['id'] ?>" <?= $c['id'] == $grupo['curso_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($c['nombre']) ?> (<?= ucfirst($c['nivel']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="input-group">
                        <label>Profesor</label>
                        <select name="profesor_id">
                            <option value="">Sin asignar</option>
                            <?php foreach ($profesores_edit as $p): ?>
                                <option value="<?= $p['id'] ?>" <?= $p['id'] == $grupo['profesor_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($p['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row form-row--2">
                    <div class="input-group">
                        <label>Horario (texto)</label>
                        <input type="text" name="horario" value="<?= htmlspecialchars($grupo['horario'] ?? '') ?>" placeholder="Ej: Lun y Mié 08:00–10:00">
                    </div>
                    <div class="input-group">
                        <label>Aula</label>
                        <input type="text" name="aula" value="<?= htmlspecialchars($grupo['aula'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-row form-row--3">
                    <div class="input-group">
                        <label>Cupo máximo</label>
                        <input type="number" name="cupo_maximo" min="1" value="<?= $grupo['cupo_maximo'] ?>">
                    </div>
                    <div class="input-group">
                        <label>Fecha inicio <span class="req">*</span></label>
                        <input type="date" name="fecha_inicio" id="ver-edit-fecha_inicio" value="<?= $grupo['fecha_inicio'] ?>">
                    </div>
                    <div class="input-group">
                        <label>Fecha fin</label>
                        <input type="date" name="fecha_fin" value="<?= $grupo['fecha_fin'] ?? '' ?>">
                    </div>
                </div>

                <div class="form-row" style="max-width:200px;">
                    <div class="input-group">
                        <label>Estado</label>
                        <select name="estado">
                            <?php foreach (['planificado'=>'Planificado','activo'=>'Activo','finalizado'=>'Finalizado','cancelado'=>'Cancelado'] as $val=>$lbl): ?>
                                <option value="<?= $val ?>" <?= $val === $grupo['estado'] ? 'selected' : '' ?>><?= $lbl ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div id="modal-alert-editar-ver" class="modal-alert"></div>

                <div class="form-actions">
                    <button type="button" onclick="cerrarModalEditar()" class="btn-cancel">Cancelar</button>
                    <button type="submit" class="btn-submit">
                        <span class="material-symbols-rounded">save</span> Guardar cambios
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════
     MODAL: Registrar Asistencia / Nueva Bitácora
═══════════════════════════════════════════════════════ -->
<div id="modalAsistencia" class="modal-overlay">
    <div class="modal-content" style="max-width:860px;">
        <div class="modal-header">
            <div>
                <h3>Registrar Asistencia</h3>
                <p style="font-size:0.85rem;color:var(--text-secondary);"><?= htmlspecialchars($grupo['nombre']) ?> · <?= htmlspecialchars($grupo['curso_nombre'] ?? '') ?></p>
            </div>
            <button onclick="cerrarModalAsistencia()" class="btn-close-modal">
                <span class="material-symbols-rounded">close</span>
            </button>
        </div>
        <div class="modal-body">
            <?php if (count($estudiantes) === 0): ?>
                <div class="empty-state">
                    <span class="material-symbols-rounded">person_off</span>
                    <p>No hay estudiantes matriculados en este grupo.</p>
                </div>
            <?php else: ?>
            <form method="POST" id="formAsistenciaModal" enctype="multipart/form-data" novalidate>
                <input type="hidden" name="action" value="guardar_asistencia">

                <!-- Sección 1: Datos de la clase -->
                <div style="background:var(--hover-bg);border:1px solid var(--border-color);border-radius:14px;padding:18px;margin-bottom:16px;">
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid var(--border-color);">
                        <span class="material-symbols-rounded" style="color:var(--primary-green);font-size:22px;">edit_note</span>
                        <div>
                            <div style="font-weight:600;font-size:0.95rem;">Datos de la Clase</div>
                            <div style="font-size:0.78rem;color:var(--text-secondary);">Se guardará como bitácora</div>
                        </div>
                    </div>

                    <div class="input-group">
                        <label>Título de la clase <span class="req">*</span></label>
                        <input type="text" name="titulo" id="asist-titulo" placeholder="Ej: Introducción a los acordes">
                    </div>

                    <div class="form-row form-row--3">
                        <div class="input-group">
                            <label>Fecha <span class="req">*</span></label>
                            <input type="date" name="fecha_clase" id="asist-fecha" value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="input-group">
                            <label>Hora inicio <span class="req">*</span></label>
                            <input type="time" name="hora_inicio" id="asist-hora-inicio">
                        </div>
                        <div class="input-group">
                            <label>Hora fin <span class="req">*</span></label>
                            <input type="time" name="hora_fin" id="asist-hora-fin">
                        </div>
                    </div>

                    <div class="input-group">
                        <label>Temas tratados <span class="req">*</span></label>
                        <textarea name="temas" id="asist-temas" placeholder="Temas vistos en la clase..." style="min-height:70px;"></textarea>
                    </div>

                    <div class="form-row form-row--2">
                        <div class="input-group">
                            <label>Descripción de la clase</label>
                            <textarea name="descripcion" placeholder="Descripción general..." style="min-height:60px;"></textarea>
                        </div>
                        <div class="input-group">
                            <label>Observaciones</label>
                            <input type="text" name="observaciones" placeholder="Observaciones generales (opcional)">
                        </div>
                    </div>

                    <div class="input-group">
                        <label>Compromisos / Tarea próxima clase</label>
                        <input type="text" name="compromisos" placeholder="Tareas o compromisos (opcional)">
                    </div>

                    <div class="evidencias-section">
                        <div class="evidencias-header">
                            <span class="material-symbols-rounded">photo_camera</span>
                            <div>
                                <span class="evidencias-title">Evidencias fotográficas</span>
                                <span class="evidencias-sub">Máx. 5 imágenes · JPG, PNG, WEBP · 5MB c/u</span>
                            </div>
                        </div>
                        <div class="evidencias-dropzone" id="dropzone-modal">
                            <span class="material-symbols-rounded">cloud_upload</span>
                            <p>Arrastra imágenes aquí o <strong>haz clic para seleccionar</strong></p>
                            <input type="file" name="evidencias[]" id="evidencias-input-modal"
                                   accept="image/jpeg,image/png,image/webp" multiple style="display:none;">
                        </div>
                        <div class="evidencias-preview" id="evidencias-preview-modal"></div>
                        <div id="evidencias-descripciones-modal"></div>
                    </div>
                </div>

                <!-- Sección 2: Lista de asistencia -->
                <div style="background:var(--hover-bg);border:1px solid var(--border-color);border-radius:14px;padding:18px;">
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;padding-bottom:12px;border-bottom:1px solid var(--border-color);">
                        <span class="material-symbols-rounded" style="color:var(--primary-blue);font-size:22px;">fact_check</span>
                        <div>
                            <div style="font-weight:600;font-size:0.95rem;">Lista de Asistencia</div>
                            <div style="font-size:0.78rem;color:var(--text-secondary);"><?= count($estudiantes) ?> estudiante<?= count($estudiantes) != 1 ? 's' : '' ?></div>
                        </div>
                    </div>

                    <div class="asistencia-resumen" style="margin-bottom:14px;">
                        <div class="asist-chip presente"><span class="material-symbols-rounded">check_circle</span><span class="asist-count" id="modal-cnt-presente">0</span> Presentes</div>
                        <div class="asist-chip ausente"><span class="material-symbols-rounded">cancel</span><span class="asist-count" id="modal-cnt-ausente">0</span> Ausentes</div>
                        <div class="asist-chip justificado"><span class="material-symbols-rounded">description</span><span class="asist-count" id="modal-cnt-justificado">0</span> Justif.</div>
                        <div class="asist-chip tardanza"><span class="material-symbols-rounded">schedule</span><span class="asist-count" id="modal-cnt-tardanza">0</span> Tardanza</div>
                    </div>

                    <div style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;">
                        <button type="button" class="btn-cancel" style="font-size:0.78rem;padding:6px 12px;" onclick="marcarTodosModal('presente')">
                            <span class="material-symbols-rounded" style="font-size:14px;">done_all</span> Todos presentes
                        </button>
                        <button type="button" class="btn-cancel" style="font-size:0.78rem;padding:6px 12px;" onclick="marcarTodosModal('ausente')">
                            <span class="material-symbols-rounded" style="font-size:14px;">remove_done</span> Todos ausentes
                        </button>
                    </div>

                    <div style="display:flex;flex-direction:column;gap:10px;">
                        <?php foreach ($estudiantes as $est): ?>
                        <div class="asistencia-estudiante-row" data-mid="<?= $est['id'] ?>">
                            <div class="est-avatar"><?= iniciales($est['nombre']) ?></div>
                            <div class="est-info">
                                <div class="est-nombre"><?= htmlspecialchars($est['nombre']) ?></div>
                                <input type="text" name="obs_est[<?= $est['id'] ?>]"
                                       class="obs-input" placeholder="Observación (opcional)...">
                            </div>
                            <input type="hidden" name="asistencia[<?= $est['id'] ?>]" id="m-estado-<?= $est['id'] ?>" value="ausente">
                            <div class="asistencia-selector">
                                <button type="button" class="asist-btn modal-asist-btn" data-estado="presente" data-mid="<?= $est['id'] ?>" title="Presente">
                                    <span class="material-symbols-rounded">check_circle</span>
                                </button>
                                <button type="button" class="asist-btn modal-asist-btn" data-estado="ausente" data-mid="<?= $est['id'] ?>" title="Ausente">
                                    <span class="material-symbols-rounded">cancel</span>
                                </button>
                                <button type="button" class="asist-btn modal-asist-btn" data-estado="justificado" data-mid="<?= $est['id'] ?>" title="Justificado">
                                    <span class="material-symbols-rounded">description</span>
                                </button>
                                <button type="button" class="asist-btn modal-asist-btn" data-estado="tardanza" data-mid="<?= $est['id'] ?>" title="Tardanza">
                                    <span class="material-symbols-rounded">schedule</span>
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div id="modal-alert-asistencia" class="modal-alert"></div>

                    <div class="form-actions" style="margin-top:16px;">
                        <button type="button" onclick="cerrarModalAsistencia()" class="btn-cancel">Cancelar</button>
                        <button type="submit" class="btn-submit">
                            <span class="material-symbols-rounded">save</span> Guardar asistencia
                        </button>
                    </div>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// ── Auto-ocultar flash ────────────────────────────────────────────────────
setTimeout(() => {
    document.querySelectorAll('.alert').forEach(a => {
        a.style.transition = 'opacity 0.5s ease';
        a.style.opacity = '0';
        setTimeout(() => a.remove(), 500);
    });
}, 4000);

// ── Cerrar modales al clic en overlay ────────────────────────────────────
document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', function(e) {
        if (e.target === this) this.classList.remove('active');
    });
});

// ── Modal Eliminar ────────────────────────────────────────────────────────
function abrirModalEliminar(id, nombre) {
    document.getElementById('del-id').value = id;
    document.getElementById('del-nombre-grupo').textContent = nombre;
    document.getElementById('modalEliminar').classList.add('active');
}
function cerrarModalEliminar() {
    document.getElementById('modalEliminar').classList.remove('active');
}

// ── Alertas personalizadas dentro de modales ─────────────────────────────
function mostrarAlertaModal(divId, msg) {
    const div = document.getElementById(divId);
    if (!div) return;
    div.innerHTML = `<span class="material-symbols-rounded">error</span>${msg}`;
    div.style.display = 'flex';
    div.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}
function ocultarAlertaModal(divId) {
    const div = document.getElementById(divId);
    if (div) div.style.display = 'none';
}

// ── Modal Editar ──────────────────────────────────────────────────────────
function abrirModalEditar() {
    ocultarAlertaModal('modal-alert-editar-ver');
    document.getElementById('modalEditarGrupo').classList.add('active');
}
function cerrarModalEditar() {
    document.getElementById('modalEditarGrupo').classList.remove('active');
}

document.getElementById('formEditarModal').addEventListener('submit', function(e) {
    const nombre    = document.getElementById('ver-edit-nombre').value.trim();
    const curso_id  = document.getElementById('ver-edit-curso_id').value;
    const fecha_ini = document.getElementById('ver-edit-fecha_inicio').value;

    if (!nombre) {
        e.preventDefault();
        mostrarAlertaModal('modal-alert-editar-ver', 'El nombre del grupo es obligatorio.');
        return;
    }
    if (!curso_id) {
        e.preventDefault();
        mostrarAlertaModal('modal-alert-editar-ver', 'Debes seleccionar un curso.');
        return;
    }
    if (!fecha_ini) {
        e.preventDefault();
        mostrarAlertaModal('modal-alert-editar-ver', 'La fecha de inicio es obligatoria.');
        return;
    }
    ocultarAlertaModal('modal-alert-editar-ver');
});

// ── Modal Asistencia ──────────────────────────────────────────────────────
function abrirModalAsistencia() {
    ocultarAlertaModal('modal-alert-asistencia');
    document.getElementById('modalAsistencia').classList.add('active');
}
function cerrarModalAsistencia() {
    document.getElementById('modalAsistencia').classList.remove('active');
}

document.getElementById('formAsistenciaModal').addEventListener('submit', function(e) {
    const titulo     = document.getElementById('asist-titulo').value.trim();
    const fecha      = document.getElementById('asist-fecha').value;
    const horaInicio = document.getElementById('asist-hora-inicio').value;
    const horaFin    = document.getElementById('asist-hora-fin').value;
    const temas      = document.getElementById('asist-temas').value.trim();

    if (!titulo) {
        e.preventDefault();
        mostrarAlertaModal('modal-alert-asistencia', 'El título de la clase es obligatorio.');
        return;
    }
    if (!fecha) {
        e.preventDefault();
        mostrarAlertaModal('modal-alert-asistencia', 'La fecha de la clase es obligatoria.');
        return;
    }
    if (!horaInicio || !horaFin) {
        e.preventDefault();
        mostrarAlertaModal('modal-alert-asistencia', 'Las horas de inicio y fin son obligatorias.');
        return;
    }
    if (horaFin <= horaInicio) {
        e.preventDefault();
        mostrarAlertaModal('modal-alert-asistencia', 'La hora de fin debe ser posterior a la hora de inicio.');
        return;
    }
    if (!temas) {
        e.preventDefault();
        mostrarAlertaModal('modal-alert-asistencia', 'Los temas tratados son obligatorios.');
        return;
    }
    ocultarAlertaModal('modal-alert-asistencia');
});

// ── Auto-abrir modal si hubo error en POST ────────────────────────────────
<?php if ($open_modal): ?>
document.getElementById('<?= $open_modal ?>').classList.add('active');
<?php endif; ?>

// ── Botones de asistencia en modal ────────────────────────────────────────
document.querySelectorAll('.modal-asist-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const mid    = btn.dataset.mid;
        const estado = btn.dataset.estado;
        document.querySelectorAll(`.modal-asist-btn[data-mid="${mid}"]`).forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById(`m-estado-${mid}`).value = estado;
        const obs = document.querySelector(`.asistencia-estudiante-row[data-mid="${mid}"] .obs-input`);
        if (obs) obs.classList.toggle('visible', estado !== 'presente');
        actualizarContadoresModal();
    });
});

function marcarTodosModal(estado) {
    document.querySelectorAll(`.modal-asist-btn[data-estado="${estado}"]`).forEach(btn => btn.click());
}

function actualizarContadoresModal() {
    ['presente','ausente','justificado','tardanza'].forEach(e => {
        const n  = document.querySelectorAll(`.modal-asist-btn[data-estado="${e}"].active`).length;
        const el = document.getElementById(`modal-cnt-${e}`);
        if (el) el.textContent = n;
    });
}

// Marcar todos ausentes por defecto
document.querySelectorAll('.modal-asist-btn[data-estado="ausente"]').forEach(btn => btn.classList.add('active'));
actualizarContadoresModal();

// ── Evidencias fotográficas en modal ──────────────────────────────────────
(function () {
    const dropzone  = document.getElementById('dropzone-modal');
    const fileInput = document.getElementById('evidencias-input-modal');
    const preview   = document.getElementById('evidencias-preview-modal');
    const descWrap  = document.getElementById('evidencias-descripciones-modal');
    const MAX_FILES = 5;
    let archivos    = [];
    if (!dropzone) return;

    dropzone.addEventListener('click', () => fileInput.click());
    dropzone.addEventListener('dragover',  e  => { e.preventDefault(); dropzone.classList.add('drag-over'); });
    dropzone.addEventListener('dragleave', ()  => dropzone.classList.remove('drag-over'));
    dropzone.addEventListener('drop', e => {
        e.preventDefault();
        dropzone.classList.remove('drag-over');
        agregarArchivos(e.dataTransfer.files);
    });
    fileInput.addEventListener('change', () => { agregarArchivos(fileInput.files); fileInput.value = ''; });

    function agregarArchivos(nuevos) {
        const tiposValidos = ['image/jpeg','image/png','image/webp'];
        for (const f of nuevos) {
            if (archivos.length >= MAX_FILES) break;
            if (!tiposValidos.includes(f.type) || f.size > 5*1024*1024) continue;
            archivos.push(f);
        }
        renderizar();
        sincronizarInput();
    }

    function renderizar() {
        preview.innerHTML  = '';
        descWrap.innerHTML = '';
        archivos.forEach((f, i) => {
            const thumb = document.createElement('div');
            thumb.className = 'ev-thumb';
            const img = document.createElement('img');
            img.src = URL.createObjectURL(f);
            img.onload = () => URL.revokeObjectURL(img.src);
            const orden = document.createElement('span');
            orden.className = 'ev-orden';
            orden.textContent = `#${i + 1}`;
            const btn = document.createElement('button');
            btn.type = 'button'; btn.className = 'ev-remove'; btn.title = 'Quitar';
            btn.innerHTML = '<span class="material-symbols-rounded">close</span>';
            btn.addEventListener('click', () => { archivos.splice(i, 1); renderizar(); sincronizarInput(); });
            thumb.append(img, orden, btn);
            preview.appendChild(thumb);

            const group = document.createElement('div');
            group.className = 'ev-desc-group';
            const lbl = document.createElement('label');
            lbl.textContent = `Descripción imagen #${i+1} (${f.name})`;
            const inp = document.createElement('input');
            inp.type = 'text'; inp.name = `ev_desc[${i}]`; inp.placeholder = 'Descripción opcional...';
            group.append(lbl, inp);
            descWrap.appendChild(group);
        });
        const hint = dropzone.querySelector('p');
        hint.innerHTML = archivos.length > 0
            ? `<strong>${archivos.length}/${MAX_FILES}</strong> imagen${archivos.length > 1 ? 'es' : ''} seleccionada${archivos.length > 1 ? 's' : ''}`
            : 'Arrastra imágenes aquí o <strong>haz clic para seleccionar</strong>';
    }

    function sincronizarInput() {
        const dt = new DataTransfer();
        archivos.forEach(f => dt.items.add(f));
        fileInput.files = dt.files;
    }
})();
</script>

</body>
</html>