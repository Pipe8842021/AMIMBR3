<?php

require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth_check.php';
require_role('admin');

$success = null;
$error   = null;

if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'eliminado') {
        $success = "Grupo eliminado correctamente.";
    } elseif ($_GET['msg'] === 'finalizado') {
        $n = (int)($_GET['generados'] ?? 0);
        $success = "Grupo finalizado. Se " . ($n === 1 ? "generó 1 certificado" : "generaron $n certificados") . " automáticamente.";
    }
}

// AJAX GET: obtener estudiantes activos de un grupo
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
    && $_SERVER['REQUEST_METHOD'] === 'GET'
    && ($_GET['action'] ?? '') === 'get_estudiantes_grupo') {
    $id_ajax = (int)($_GET['id'] ?? 0);
    header('Content-Type: application/json');
    if ($id_ajax) {
        $st = $pdo->prepare("
            SELECT m.id AS matricula_id, m.estudiante_id, u.nombre AS estudiante_nombre
            FROM matriculas m
            JOIN usuarios u ON m.estudiante_id = u.id
            WHERE m.grupo_id = ? AND m.estado = 'activa'
            ORDER BY u.nombre
        ");
        $st->execute([$id_ajax]);
        echo json_encode(['success' => true, 'estudiantes' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    } else {
        echo json_encode(['success' => false, 'estudiantes' => []]);
    }
    exit;
}

// En la sección de manejo de POST de admin.php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'crear_grupo') {
    // Datos del Grupo
    $nombre       = trim($_POST['nombre']       ?? '');
    $curso_id     = (int)($_POST['curso_id']    ?? 0);
    $profesor_id  = (int)($_POST['profesor_id'] ?? 0);
    $cupo_maximo  = (int)($_POST['cupo_maximo'] ?? 20);
    $aula_input   = trim($_POST['aula']         ?? '');
    $fecha_inicio = $_POST['fecha_inicio']      ?? '';
    $fecha_fin    = ($_POST['fecha_fin'] ?? '')  ?: null;
    $estado       = $_POST['estado']            ?? 'planificado';

    // Datos del Horario (Nuevos campos)
    $dia_semana   = $_POST['dia_semana']        ?? '';
    $hora_inicio  = $_POST['hora_inicio']       ?? '';
    $hora_fin     = $_POST['hora_fin']          ?? '';
    $horario_texto = ucfirst($dia_semana) . ' ' . $hora_inicio . ' - ' . $hora_fin;
    if (!$nombre || !$curso_id || !$fecha_inicio || !$dia_semana || !$hora_inicio || !$hora_fin) {
        $error = "Completa los campos obligatorios, incluyendo el horario detallado.";
    } else {
        try {
            $pdo->beginTransaction();

            // 1. Validar conflictos de Horario/Aula/Profesor antes de insertar
            $conflictos = [];

            // Conflicto de AULA
            if (!empty($aula_input)) {
                $stmt = $pdo->prepare("
                    SELECT g.nombre FROM horarios h 
                    JOIN grupos g ON h.grupo_id = g.id 
                    WHERE h.aula = ? AND h.dia_semana = ? 
                    AND h.hora_inicio < ? AND h.hora_fin > ?
                ");
                $stmt->execute([$aula_input, $dia_semana, $hora_fin, $hora_inicio]);
                if ($stmt->fetch()) $conflictos[] = "El aula ya está ocupada en ese horario.";
            }

            // Conflicto de PROFESOR
            if ($profesor_id) {
                $stmt = $pdo->prepare("
                    SELECT g.nombre FROM horarios h 
                    JOIN grupos g ON h.grupo_id = g.id 
                    WHERE g.profesor_id = ? AND h.dia_semana = ? 
                    AND h.hora_inicio < ? AND h.hora_fin > ?
                ");
                $stmt->execute([$profesor_id, $dia_semana, $hora_fin, $hora_inicio]);
                if ($stmt->fetch()) $conflictos[] = "El profesor ya tiene otra clase asignada en este horario.";
            }

            if (!empty($conflictos)) {
                throw new Exception(implode(" ", $conflictos));
            }

            // 2. Insertar Grupo
            $stmt = $pdo->prepare("
                INSERT INTO grupos (nombre, curso_id, profesor_id, cupo_maximo, aula, fecha_inicio, fecha_fin, estado, horario)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$nombre, $curso_id, $profesor_id ?: null, $cupo_maximo, $aula_input ?: null, $fecha_inicio, $fecha_fin, $estado, $horario_texto]);
            $nuevo_id = $pdo->lastInsertId();

            // 3. Insertar Horario vinculado
            $stmtHorario = $pdo->prepare("
                INSERT INTO horarios (grupo_id, dia_semana, hora_inicio, hora_fin, aula) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmtHorario->execute([$nuevo_id, $dia_semana, $hora_inicio, $hora_fin, $aula_input]);

            $pdo->commit();
            $success = "Grupo creado exitosamente.";
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log($e->getMessage());
            $error = "Error: " . $e->getMessage();
        }
    }
}
// Respuesta AJAX para crear_grupo
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest' &&
    ($_POST['action'] ?? '') === 'crear_grupo') {
    header('Content-Type: application/json');
    if ($error) {
        echo json_encode(['success' => false, 'error' => strip_tags($error)]);
    } else {
        echo json_encode(['success' => true]);
    }
    exit;
}

// Manejo de edición de grupo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'editar') {
    $id_edit      = (int)($_POST['id']           ?? 0);
    $nombre       = trim($_POST['nombre']        ?? '');
    $curso_id     = (int)($_POST['curso_id']     ?? 0);
    $profesor_id  = (int)($_POST['profesor_id']  ?? 0);
    $cupo_maximo  = (int)($_POST['cupo_maximo']  ?? 20);
    $aula         = trim($_POST['aula']          ?? '');
    $fecha_inicio = $_POST['fecha_inicio']       ?? '';
    $fecha_fin    = $_POST['fecha_fin']          ?: null;
    $horario      = trim($_POST['horario']       ?? '');
    $estado       = $_POST['estado']             ?? 'planificado';
    $estados_validos = ['planificado', 'activo', 'finalizado', 'cancelado'];

    if (!$id_edit || !$nombre || !$curso_id || !$fecha_inicio || !in_array($estado, $estados_validos)) {
        $error = "Completa todos los campos obligatorios.";
    } else {
        try {
            $pdo->prepare("
                UPDATE grupos
                SET nombre = ?, curso_id = ?, profesor_id = ?, cupo_maximo = ?,
                    aula = ?, fecha_inicio = ?, fecha_fin = ?, horario = ?, estado = ?
                WHERE id = ?
            ")->execute([
                $nombre, $curso_id, $profesor_id ?: null, $cupo_maximo,
                $aula ?: null, $fecha_inicio, $fecha_fin, $horario ?: null, $estado,
                $id_edit
            ]);
            $success = "Grupo actualizado correctamente.";
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $error = "Error al actualizar el grupo.";
        }
    }
}

// Manejo de eliminación de grupo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'eliminar_grupo') {
    $id_del = (int)($_POST['id_del'] ?? 0);
    if ($id_del) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM matriculas WHERE grupo_id = ? AND estado = 'activa'");
            $stmt->execute([$id_del]);
            if ((int)$stmt->fetchColumn() > 0) {
                $error = "No se puede eliminar el grupo: tiene matrículas activas. Cambia su estado a <strong>Cancelado</strong> primero.";
            } else {
                $pdo->prepare("DELETE FROM horarios WHERE grupo_id = ?")->execute([$id_del]);
                $pdo->prepare("DELETE FROM grupos   WHERE id = ?")->execute([$id_del]);
                header("Location: admin.php?msg=eliminado");
                exit;
            }
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $error = "Error al eliminar el grupo.";
        }
    }
}

// Manejo de cambio de estado
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cambiar_estado') {
    $id_grupo     = (int)($_POST['id']           ?? 0);
    $nuevo_estado = $_POST['nuevo_estado']        ?? '';
    $estados_validos = ['planificado', 'activo', 'finalizado', 'cancelado'];

    if ($id_grupo && in_array($nuevo_estado, $estados_validos)) {
        try {
            $pdo->prepare("UPDATE grupos SET estado = ? WHERE id = ?")
                ->execute([$nuevo_estado, $id_grupo]);
            $success = "Estado del grupo actualizado correctamente.";
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $error = "Error al actualizar el estado.";
        }
    }
}

// Finalizar grupo con generación automática de certificados
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'finalizar_grupo') {
    $id_grupo = (int)($_POST['id'] ?? 0);
    $is_ajax_fin = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
                && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    // Mapa de calificaciones por estudiante enviadas desde el modal
    $cal_map = [];
    $cals_raw = json_decode($_POST['calificaciones_json'] ?? '[]', true) ?: [];
    foreach ($cals_raw as $c) {
        $cal_map[(int)$c['estudiante_id']] = max(0.1, min(5.0, (float)$c['calificacion']));
    }

    if ($id_grupo) {
        try {
            $pdo->beginTransaction();

            $stmtGrupo = $pdo->prepare("
                SELECT g.*, c.nivel AS curso_nivel
                FROM grupos g JOIN cursos c ON g.curso_id = c.id
                WHERE g.id = ?
            ");
            $stmtGrupo->execute([$id_grupo]);
            $grupo_info = $stmtGrupo->fetch(PDO::FETCH_ASSOC);
            if (!$grupo_info) throw new Exception("Grupo no encontrado");

            $pdo->prepare("UPDATE grupos SET estado = 'finalizado' WHERE id = ?")
                ->execute([$id_grupo]);

            $stmtMats = $pdo->prepare("
                SELECT m.id, m.estudiante_id
                FROM matriculas m
                WHERE m.grupo_id = ? AND m.estado = 'activa'
            ");
            $stmtMats->execute([$id_grupo]);
            $matriculas = $stmtMats->fetchAll(PDO::FETCH_ASSOC);

            $year = date('Y');
            $generados = 0;

            foreach ($matriculas as $mat) {
                $chk = $pdo->prepare("SELECT id FROM calificaciones_certificados WHERE estudiante_id = ? AND grupo_id = ?");
                $chk->execute([$mat['estudiante_id'], $id_grupo]);
                if ($chk->fetch()) continue;

                // Calificación individual del estudiante; si no se envió, usa 5.0
                $cal_est = $cal_map[(int)$mat['estudiante_id']] ?? 5.0;

                $cnt = (int)$pdo->query("SELECT COUNT(*) FROM calificaciones_certificados WHERE YEAR(fecha_aprobacion) = $year")->fetchColumn();
                $codigo = sprintf("AMB-%s-%04d-%03d-%03d", $year, $cnt + 1 + $generados, $mat['estudiante_id'], $grupo_info['curso_id']);

                $pdo->prepare("
                    INSERT INTO calificaciones_certificados (
                        estudiante_id, curso_id, grupo_id, matricula_id,
                        nivel_aprobado, calificacion_final,
                        fecha_inicio_curso, fecha_fin_curso, fecha_aprobacion,
                        aprobado_por, codigo_certificado, ruta_pdf, estado
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, ?, '', 'aprobado')
                ")->execute([
                    $mat['estudiante_id'], $grupo_info['curso_id'], $id_grupo, $mat['id'],
                    $grupo_info['curso_nivel'], $cal_est,
                    $grupo_info['fecha_inicio'], $grupo_info['fecha_fin'],
                    $_SESSION['user_id'], $codigo
                ]);
                $generados++;
            }

            $pdo->commit();

            if ($is_ajax_fin) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'generados' => $generados, 'total' => count($matriculas)]);
                exit;
            }
            $success = "Grupo finalizado. Se generaron $generados certificado" . ($generados !== 1 ? 's' : '') . " automáticamente.";
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log($e->getMessage());
            if ($is_ajax_fin) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Error al finalizar el grupo']);
                exit;
            }
            $error = "Error al finalizar el grupo.";
        }
    }
}

try {
    // Filtros GET
    $filtro_estado = $_GET['estado'] ?? '';
    $filtro_buscar = trim($_GET['buscar'] ?? '');

    $where  = [];
    $params = [];

    if ($filtro_estado) {
        $where[]  = "g.estado = ?";
        $params[] = $filtro_estado;
    }
    if ($filtro_buscar) {
        $where[]  = "(g.nombre LIKE ? OR c.nombre LIKE ? OR u.nombre LIKE ?)";
        $params[] = "%$filtro_buscar%";
        $params[] = "%$filtro_buscar%";
        $params[] = "%$filtro_buscar%";
    }

    $sql_where = $where ? "WHERE " . implode(" AND ", $where) : "";

    $pagina  = max(1, (int)($_GET['pagina'] ?? 1));
    $por_pag = 15;

    $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM grupos g JOIN cursos c ON g.curso_id = c.id LEFT JOIN usuarios u ON g.profesor_id = u.id $sql_where");
    $stmtTotal->execute($params);
    $total_grupos  = (int)$stmtTotal->fetchColumn();
    $total_paginas = max(1, (int)ceil($total_grupos / $por_pag));
    $pagina        = min($pagina, $total_paginas);
    $offset        = ($pagina - 1) * $por_pag;

    $stmt = $pdo->prepare("
        SELECT
            g.id, g.nombre, g.fecha_inicio, g.fecha_fin, g.estado,
            g.cupo_actual, g.cupo_maximo, g.aula, g.horario,
            g.curso_id, g.profesor_id,
            c.nombre AS curso_nombre,
            c.nivel  AS curso_nivel,
            u.nombre AS profesor_nombre,
            (SELECT COUNT(*) FROM matriculas WHERE grupo_id = g.id AND estado = 'activa') AS estudiantes_activos
        FROM grupos g
        JOIN cursos c        ON g.curso_id   = c.id
        LEFT JOIN usuarios u ON g.profesor_id = u.id
        $sql_where
        ORDER BY g.id DESC
        LIMIT $por_pag OFFSET $offset
    ");
    $stmt->execute($params);
    $grupos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // --- NUEVA LÓGICA: Obtener horarios para el MODAL ---
    $horarios_modal = [];
    if (!empty($grupos)) {
        $ids = array_column($grupos, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmtH = $pdo->prepare("SELECT * FROM horarios WHERE grupo_id IN ($placeholders) ORDER BY dia_semana, hora_inicio");
        $stmtH->execute($ids);
        while ($h = $stmtH->fetch(PDO::FETCH_ASSOC)) {
            $horarios_modal[$h['grupo_id']][] = $h;
        }
    }
    $dias_esp = ['mon' => 'Lunes', 'tue' => 'Martes', 'wed' => 'Miércoles', 'thu' => 'Jueves', 'fri' => 'Viernes', 'sat' => 'Sábado', 'sun' => 'Domingo'];
    // ----------------------------------------------------

    // Totales por estado para los chips
    $totales = [];
    foreach ($pdo->query("SELECT estado, COUNT(*) AS n FROM grupos GROUP BY estado")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $totales[$r['estado']] = $r['n'];
    }
    $total_general = array_sum($totales);
} catch (PDOException $e) {
    error_log($e->getMessage());
    $grupos = [];
    $totales = [];
    $total_general = 0;
    $total_grupos  = 0;
    $total_paginas = 1;
    $pagina        = 1;
    $por_pag       = 15;
    $offset        = 0;
}

$cursos     = $pdo->query("SELECT id, nombre, nivel FROM cursos WHERE estado='activo' ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
$profesores = $pdo->query("SELECT id, nombre FROM usuarios WHERE rol='profesor' AND estado='activo' ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

$estado_cfg = [
    'planificado' => ['cls' => 'badge-info',    'txt' => 'Planificado', 'icon' => 'schedule'],
    'activo'      => ['cls' => 'badge-success',  'txt' => 'Activo',      'icon' => 'play_circle'],
    'finalizado'  => ['cls' => 'badge-warning',  'txt' => 'Finalizado',  'icon' => 'check_circle'],
    'cancelado'   => ['cls' => 'badge-danger',   'txt' => 'Cancelado',   'icon' => 'cancel'],
];
$nivel_cfg = [
    'basico'     => 'badge-info',
    'intermedio' => 'badge-warning',
    'avanzado'   => 'badge-danger',
];

date_default_timezone_set('America/Bogota');
$dias  = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
$meses = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
$fecha_hoy = $dias[date('w')] . ', ' . date('d') . ' de ' . $meses[date('n')] . ' de ' . date('Y');
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Grupos – Amimbré</title>
    <link rel="shortcut icon" href="../../assets/img/3.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,1,0">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="../../assets/css/colores.css">
    <link rel="stylesheet" href="../../assets/css/style-grupos.css">
    <script>
        (function() {
            const t = localStorage.getItem('amimbre-theme');
            if (t === 'light') document.documentElement.setAttribute('data-theme', 'light');
        })();
    </script>
</head>

<body>

    <?php require_once '../../includes/header.php'; ?>

    <main class="main-content">

        <div class="dashboard-header">
            <div class="header-left">
                <button class="btn-back" onclick="window.history.back()">
                    <span class="material-symbols-rounded">arrow_back</span>
                </button>
                <div class="dashboard-title">
                    <h1>Gestión de Grupos</h1>
                    <p>Creación y control de secciones académicas</p>
                </div>
            </div>
            <button type="button" class="btn-submit" onclick="abrirModalCrear()">
                <span class="material-symbols-rounded">add_circle</span> Nuevo grupo
            </button>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <span class="material-symbols-rounded">check_circle</span>
                <?php echo $success; ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger">
                <span class="material-symbols-rounded">error</span>
                <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon total">
                    <span class="material-symbols-rounded">layers</span>
                </div>
                <div class="stat-info">
                    <span class="stat-label">Total Grupos</span>
                    <span class="stat-value"><?php echo $total_general; ?></span>
                </div>
            </div>
            <?php foreach ($estado_cfg as $est => $cfg): ?>
                <div class="stat-card">
                    <div class="stat-icon <?php echo $est; ?>">
                        <span class="material-symbols-rounded"><?php echo $cfg['icon']; ?></span>
                    </div>
                    <div class="stat-info">
                        <span class="stat-label"><?php echo $cfg['txt']; ?>s</span>
                        <span class="stat-value"><?php echo $totales[$est] ?? 0; ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="card">
            <div class="section-header">
                <div>
                    <h3 class="section-title">Grupos Registrados</h3>
                    <p class="section-subtitle"><?php echo $total_grupos; ?> resultado<?php echo $total_grupos != 1 ? 's' : ''; ?></p>
                </div>
            </div>

            <form method="GET" class="filtros-bar">
                <div class="filtros-buscar">
                    <span class="material-symbols-rounded">search</span>
                    <input type="text" name="buscar"
                        value="<?php echo htmlspecialchars($filtro_buscar); ?>"
                        placeholder="Buscar por nombre, curso o profesor...">
                </div>
                <select name="estado" onchange="this.form.submit()">
                    <option value="">Todos los estados</option>
                    <?php foreach ($estado_cfg as $est => $cfg): ?>
                        <option value="<?php echo $est; ?>" <?php echo $filtro_estado === $est ? 'selected' : ''; ?>>
                            <?php echo $cfg['txt']; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn-filtrar">
                    <span class="material-symbols-rounded">filter_list</span>
                </button>
                <?php if ($filtro_estado || $filtro_buscar): ?>
                    <a href="admin.php" class="btn-limpiar" title="Limpiar filtros">
                        <span class="material-symbols-rounded">close</span>
                    </a>
                <?php endif; ?>
            </form>

            <?php if (count($grupos) > 0): ?>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Grupo</th>
                                <th>Curso</th>
                                <th>Profesor</th>
                                <th>Horario / Aula</th>
                                <th>Cupos</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($grupos as $g):
                                $est = $estado_cfg[$g['estado']] ?? $estado_cfg['planificado'];
                                $niv = $nivel_cfg[strtolower($g['curso_nivel'])] ?? 'badge-info';
                                $pct = $g['cupo_maximo'] > 0 ? round(($g['cupo_actual'] / $g['cupo_maximo']) * 100) : 0;
                                $bar = $pct >= 90 ? 'bar-danger' : ($pct >= 70 ? 'bar-warning' : 'bar-success');
                            ?>
                                <tr>
                                    <td style="color:var(--text-secondary); font-size:0.8rem;">#<?php echo $g['id']; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($g['nombre']); ?></strong>
                                        <div style="font-size:0.75rem; color:var(--text-secondary); margin-top:2px;">
                                            Desde <?php echo date('d/m/Y', strtotime($g['fecha_inicio'])); ?>
                                            <?php if ($g['fecha_fin']): ?>
                                                · Hasta <?php echo date('d/m/Y', strtotime($g['fecha_fin'])); ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($g['curso_nombre']); ?>
                                        <div style="margin-top:3px;">
                                            <span class="badge <?php echo $niv; ?>"><?php echo ucfirst($g['curso_nivel']); ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <?php echo $g['profesor_nombre']
                                            ? htmlspecialchars($g['profesor_nombre'])
                                            : '<span style="color:var(--text-secondary);font-size:0.82rem;">Sin asignar</span>'; ?>
                                    </td>
                                    <td>
                                        <?php if ($g['aula']): ?>
                                            <div style="font-size:0.85rem;">
                                                <span class="material-symbols-rounded" style="font-size:16px; vertical-align:middle;">meeting_room</span>
                                                <?php echo htmlspecialchars($g['aula']); ?>
                                            </div>
                                        <?php else: ?>
                                            <span style="color:var(--text-secondary); font-size:0.8rem;">Sin aula asignada</span>
                                        <?php endif; ?>

                                        <div style="margin-top:4px;">
                                            <a href="../horarios/index.php?grupo_id=<?php echo $g['id']; ?>"
                                                style="font-size:0.7rem; color:var(--primary-green); text-decoration:none; font-weight:600;"
                                                onclick="event.preventDefault(); abrirModalHorarios(<?php echo $g['id']; ?>, '<?php echo htmlspecialchars($g['nombre']); ?>')">
                                                <span class="material-symbols-rounded" style="font-size:12px; vertical-align:middle;">calendar_month</span>
                                                Ver horarios detallados
                                            </a>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="cupo-cell">
                                            <span><?php echo $g['cupo_actual']; ?>/<?php echo $g['cupo_maximo']; ?></span>
                                            <div class="group-bar" style="width:80px;">
                                                <div class="group-bar-fill <?php echo $bar; ?>" style="width:<?php echo $pct; ?>%"></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $est['cls']; ?>"><?php echo $est['txt']; ?></span>
                                    </td>
                                    <td>
                                        <div class="table-actions">
                                            <a href="ver.php?id=<?php echo $g['id']; ?>" class="tbl-btn view" title="Ver detalle">
                                                <span class="material-symbols-rounded">visibility</span>
                                            </a>

                                            <button class="tbl-btn edit" onclick='abrirModalEditar(<?php echo json_encode($g); ?>)' class="btn-edit" title="Editar">
                                                <span class="material-symbols-rounded">edit</span>
                                            </button>
                                            <div class="dropdown-estado">
                                                <button type="button" class="tbl-btn estado" title="Cambiar estado">
                                                    <span class="material-symbols-rounded">swap_horiz</span>
                                                </button>
                                                <div class="dropdown-estado-menu">
                                                    <?php foreach ($estado_cfg as $est_k => $est_v):
                                                        if ($est_k === $g['estado']) continue;
                                                        if ($est_k === 'finalizado'): ?>
                                                        <button type="button" class="estado-option"
                                                            onclick="abrirModalFinalizar(<?php echo $g['id']; ?>, '<?php echo addslashes(htmlspecialchars($g['nombre'])); ?>', <?php echo (int)$g['estudiantes_activos']; ?>)">
                                                            <span class="material-symbols-rounded"><?php echo $est_v['icon']; ?></span>
                                                            <?php echo $est_v['txt']; ?>
                                                        </button>
                                                        <?php else: ?>
                                                        <form method="POST" style="margin:0;">
                                                            <input type="hidden" name="action" value="cambiar_estado">
                                                            <input type="hidden" name="id" value="<?php echo $g['id']; ?>">
                                                            <input type="hidden" name="nuevo_estado" value="<?php echo $est_k; ?>">
                                                            <button type="submit" class="estado-option">
                                                                <span class="material-symbols-rounded"><?php echo $est_v['icon']; ?></span>
                                                                <?php echo $est_v['txt']; ?>
                                                            </button>
                                                        </form>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                            <button type="button" class="tbl-btn delete"
                                                onclick="abrirModalEliminar(<?php echo $g['id']; ?>, '<?php echo addslashes(htmlspecialchars($g['nombre'])); ?>')"
                                                title="Eliminar grupo">
                                                <span class="material-symbols-rounded">delete</span>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="pagination-bar">
                    <span class="pagination-info">
                        Mostrando <?php echo ($offset + 1); ?>–<?php echo min($offset + $por_pag, $total_grupos); ?> de <?php echo $total_grupos; ?>
                    </span>
                    <?php if ($total_paginas > 1): ?>
                    <div class="pagination-btns">
                        <?php if ($pagina > 1): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $pagina - 1])); ?>" class="pag-btn" title="Anterior">
                                <span class="material-symbols-rounded">chevron_left</span>
                            </a>
                        <?php endif; ?>
                        <?php for ($i = 1; $i <= $total_paginas; $i++): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $i])); ?>"
                               class="pag-btn<?php echo $i === $pagina ? ' active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                        <?php if ($pagina < $total_paginas): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $pagina + 1])); ?>" class="pag-btn" title="Siguiente">
                                <span class="material-symbols-rounded">chevron_right</span>
                            </a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

            <?php else: ?>
                <div class="empty-state">
                    <span class="material-symbols-rounded">layers</span>
                    <p><?php echo ($filtro_estado || $filtro_buscar) ? 'No hay grupos con esos filtros.' : 'Aún no hay grupos registrados.'; ?></p>
                </div>
            <?php endif; ?>
        </div>
    </main>
    <div id="modalHorarios" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h3 id="modalTitulo">Horarios del Grupo</h3>
                    <p id="modalSubtitulo" style="font-size:0.85rem; color:var(--text-secondary);">Sesiones programadas en el sistema</p>
                </div>
                <button onclick="cerrarModal()" class="btn-close-modal">
                    <span class="material-symbols-rounded">close</span>
                </button>
            </div>
            <div class="modal-body" id="modalBody"></div>
            <div class="modal-footer" style="margin-top:20px; display:flex; gap:10px; justify-content:flex-end;">
                <button onclick="cerrarModal()" class="btn-secondary" style="padding:8px 16px; border-radius:8px; border:1px solid var(--border-color); background:none; color:var(--text-main); cursor:pointer;">Cerrar</button>
                <a href="../horarios/index.php" id="linkGestionar" class="btn-primary" style="padding:8px 16px; border-radius:8px; background:var(--primary-green); color:white; text-decoration:none; font-size:0.9rem;">Gestionar en Calendario</a>
            </div>
        </div>
    </div>

    <style>
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            z-index: 2000;
            backdrop-filter: blur(4px);
            align-items: center;
            justify-content: center;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal-content {
            background: var(--card-bg);
            width: 90%;
            max-width: 450px;
            border-radius: 20px;
            padding: 24px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.3);
            animation: slideUp 0.3s ease;
            max-height: 92vh;
            overflow-y: auto;
        }

        @keyframes slideUp {
            from {
                transform: translateY(20px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        .modal-body ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .modal-body li {
            padding: 14px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .modal-body li:last-child {
            border-bottom: none;
        }

        .btn-close-modal {
            background: none;
            border: none;
            cursor: pointer;
            color: var(--text-secondary);
            transition: 0.2s;
        }

        .btn-close-modal:hover {
            color: var(--primary-green);
        }
    </style>
    <!-- Modal para Crear Nuevo Grupo -->
    <div id="modalCrearGrupo" class="modal-overlay">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header">
                <div>
                    <h3>Nuevo Grupo</h3>
                    <p style="font-size:0.85rem; color:var(--text-secondary);">Registrar sección académica</p>
                </div>
                <button onclick="cerrarModalCrear()" class="btn-close-modal">
                    <span class="material-symbols-rounded">close</span>
                </button>
            </div>
            <div class="modal-body">
                <form method="POST" id="formCrearGrupo" novalidate>
                    <input type="hidden" name="estado" value="planificado">
                    <input type="hidden" name="action" value="crear_grupo">

                    <div class="form-row">
                        <div class="input-group">
                            <label>Nombre del grupo <span class="req">*</span></label>
                            <input type="text" name="nombre" id="crear-nombre" placeholder="Ej: Piano Básico A">
                        </div>
                    </div>

                    <div class="form-row form-row--2">
                        <div class="input-group">
                            <label>Curso <span class="req">*</span></label>
                            <select name="curso_id" id="crear-curso_id">
                                <option value="">Seleccionar...</option>
                                <?php foreach ($cursos as $c): ?>
                                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="input-group">
                            <label>Profesor</label>
                            <select name="profesor_id">
                                <option value="">Sin asignar</option>
                                <?php foreach ($profesores as $p): ?>
                                    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-row form-row--2">
                        <div class="input-group">
                            <label>Fecha de Inicio <span class="req">*</span></label>
                            <input type="date" name="fecha_inicio" id="crear-fecha_inicio" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="input-group">
                            <label>Cupo Máximo</label>
                            <input type="number" name="cupo_maximo" value="20" min="1">
                        </div>
                    </div>

                    <div class="form-row form-row--2">
                        <div class="input-group">
                            <label>Día <span class="req">*</span></label>
                            <select name="dia_semana" id="crear-dia_semana">
                                <option value="">Seleccionar día...</option>
                                <option value="lunes">Lunes</option>
                                <option value="martes">Martes</option>
                                <option value="miercoles">Miércoles</option>
                                <option value="jueves">Jueves</option>
                                <option value="viernes">Viernes</option>
                                <option value="sabado">Sábado</option>
                                <option value="domingo">Domingo</option>
                            </select>
                        </div>
                        <div class="input-group">
                            <label>Aula</label>
                            <input type="text" name="aula" placeholder="Ej: Salón 1">
                        </div>
                    </div>

                    <div class="form-row form-row--2">
                        <div class="input-group">
                            <label>Hora Inicio <span class="req">*</span></label>
                            <input type="time" name="hora_inicio" id="crear-hora_inicio">
                        </div>
                        <div class="input-group">
                            <label>Hora Fin <span class="req">*</span></label>
                            <input type="time" name="hora_fin" id="crear-hora_fin">
                        </div>
                    </div>

                    <div id="modal-alert-crear" class="modal-alert"></div>

                    <div class="form-actions" style="margin-top:16px; display:flex; justify-content:flex-end; gap:10px;">
                        <button type="button" class="btn-cancel" onclick="cerrarModalCrear()">Cancelar</button>
                        <button type="submit" class="btn-submit">Guardar Grupo</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <!-- Modal para Editar Grupo -->
    <div id="modalEditarGrupo" class="modal-overlay">
        <div class="modal-content" style="max-width: 700px;">
            <div class="modal-header">
                <div>
                    <h3>Editar Grupo</h3>
                    <p id="edit-grupo-id-label" style="font-size:0.85rem; color:var(--text-secondary);"></p>
                </div>
                <button onclick="cerrarModalEditar()" class="btn-close-modal">
                    <span class="material-symbols-rounded">close</span>
                </button>
            </div>
            <div class="modal-body">
                <form method="POST" id="formEditarGrupo" novalidate>
                    <input type="hidden" name="action" value="editar">
                    <input type="hidden" name="id" id="edit-id">

                    <div class="form-row">
                        <div class="input-group">
                            <label>Nombre del grupo <span class="req">*</span></label>
                            <input type="text" name="nombre" id="edit-nombre" placeholder="Ej: Piano Básico A">
                        </div>
                    </div>

                    <div class="form-row form-row--2">
                        <div class="input-group">
                            <label>Curso <span class="req">*</span></label>
                            <select name="curso_id" id="edit-curso_id">
                                <option value="">Seleccionar...</option>
                                <?php foreach ($cursos as $c): ?>
                                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="input-group">
                            <label>Profesor</label>
                            <select name="profesor_id" id="edit-profesor_id">
                                <option value="">Sin asignar</option>
                                <?php foreach ($profesores as $p): ?>
                                    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-row form-row--3">
                        <div class="input-group">
                            <label>Cupo Máximo</label>
                            <input type="number" name="cupo_maximo" id="edit-cupo" min="1">
                        </div>
                        <div class="input-group">
                            <label>Fecha Inicio <span class="req">*</span></label>
                            <input type="date" name="fecha_inicio" id="edit-fecha_inicio">
                        </div>
                        <div class="input-group">
                            <label>Fecha Fin</label>
                            <input type="date" name="fecha_fin" id="edit-fecha_fin">
                        </div>
                    </div>

                    <div class="form-row form-row--2">
                        <div class="input-group">
                            <label>Horario</label>
                            <input type="text" name="horario" id="edit-horario" placeholder="Ej: Lunes 08:00 - 10:00">
                        </div>
                        <div class="input-group">
                            <label>Aula</label>
                            <input type="text" name="aula" id="edit-aula">
                        </div>
                    </div>

                    <div class="form-row" style="max-width: 220px;">
                        <div class="input-group">
                            <label>Estado</label>
                            <select name="estado" id="edit-estado">
                                <option value="planificado">Planificado</option>
                                <option value="activo">Activo</option>
                                <option value="finalizado">Finalizado</option>
                                <option value="cancelado">Cancelado</option>
                            </select>
                        </div>
                    </div>

                    <div id="modal-alert-editar" class="modal-alert"></div>

                    <div class="form-actions" style="margin-top:16px; display:flex; justify-content:flex-end; gap:10px;">
                        <button type="button" onclick="cerrarModalEditar()" class="btn-cancel">Cancelar</button>
                        <button type="submit" class="btn-submit">Guardar Cambios</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Finalizar Grupo con generación de certificados -->
    <div id="modalFinalizarGrupo" class="modal-overlay">
        <div class="modal-content" style="max-width: 580px;">
            <div class="modal-header">
                <div>
                    <h3>Finalizar Grupo</h3>
                    <p style="font-size:0.85rem; color:var(--text-secondary);">Asigna la calificación final a cada estudiante</p>
                </div>
                <button onclick="cerrarModalFinalizar()" class="btn-close-modal">
                    <span class="material-symbols-rounded">close</span>
                </button>
            </div>
            <div class="modal-body" style="padding: 8px 0;">

                <!-- Encabezado del grupo -->
                <div style="background:rgba(34,197,94,0.08); border:1px solid rgba(34,197,94,0.2); border-radius:12px; padding:14px 16px; margin-bottom:14px; display:flex; align-items:center; gap:12px;">
                    <span class="material-symbols-rounded" style="color:var(--primary-green); font-size:26px; flex-shrink:0;">workspace_premium</span>
                    <div>
                        <div style="font-weight:600; font-size:0.95rem;" id="finalizar-grupo-nombre"></div>
                        <div style="font-size:0.82rem; color:var(--text-secondary); margin-top:2px;" id="finalizar-header-sub"></div>
                    </div>
                </div>

                <!-- Rellenar todos a la vez -->
                <div id="finalizar-fill-all" style="display:none; align-items:center; gap:8px; flex-wrap:wrap; margin-bottom:10px;">
                    <span style="font-size:0.83rem; color:var(--text-secondary);">Aplicar misma nota a todos:</span>
                    <input type="number" id="finalizar-cal-todos" min="0.1" max="5.0" step="0.1" placeholder="0.0–5.0"
                        style="width:88px; padding:5px 8px; border-radius:8px; border:1px solid var(--border-color); background:var(--card-bg); color:var(--text-main); font-size:0.88rem;">
                    <button type="button" onclick="aplicarCalATodos()"
                        style="padding:5px 14px; border-radius:8px; border:1px solid var(--primary-green); color:var(--primary-green); background:none; font-size:0.83rem; cursor:pointer; font-weight:600;">
                        Aplicar
                    </button>
                </div>

                <!-- Cargando -->
                <div id="finalizar-loading" style="text-align:center; padding:28px 16px; color:var(--text-secondary); font-size:0.9rem;">
                    <span class="material-symbols-rounded" style="font-size:30px; display:block; margin-bottom:8px; opacity:0.4;">hourglass_empty</span>
                    Cargando estudiantes...
                </div>

                <!-- Lista de estudiantes con calificación individual -->
                <div id="finalizar-lista" style="display:none; max-height:340px; overflow-y:auto; border:1px solid var(--border-color); border-radius:12px; margin-bottom:4px;"></div>

                <!-- Sin estudiantes -->
                <div id="finalizar-sin-estudiantes" style="display:none; border-radius:10px; padding:12px 14px; font-size:0.86rem; color:var(--text-secondary); background:rgba(249,115,22,0.08); border:1px solid rgba(249,115,22,0.2);">
                    <span class="material-symbols-rounded" style="vertical-align:middle; font-size:18px; margin-right:4px;">info</span>
                    Este grupo no tiene estudiantes activos. Solo se cambiará el estado a Finalizado.
                </div>

                <div id="finalizar-alert" style="display:none; border-radius:10px; padding:12px 14px; font-size:0.86rem; color:var(--primary-orange, #f97316); background:rgba(249,115,22,0.08); border:1px solid rgba(249,115,22,0.2); margin-top:10px;"></div>
            </div>
            <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:18px;">
                <button type="button" class="btn-cancel" onclick="cerrarModalFinalizar()">Cancelar</button>
                <button type="button" class="btn-submit" id="btn-confirmar-finalizar" onclick="confirmarFinalizarGrupo()" disabled>
                    <span class="material-symbols-rounded">check_circle</span> Finalizar y generar
                </button>
            </div>
            <input type="hidden" id="finalizar-grupo-id">
        </div>
    </div>

    <!-- Modal Confirmación Eliminar -->
    <div id="modalEliminar" class="modal-overlay">
        <div class="modal-content" style="max-width: 460px;">
            <div class="modal-header">
                <div>
                    <h3>Eliminar Grupo</h3>
                    <p style="font-size:0.85rem; color:var(--text-secondary);">Esta acción no se puede deshacer</p>
                </div>
                <button onclick="cerrarModalEliminar()" class="btn-close-modal">
                    <span class="material-symbols-rounded">close</span>
                </button>
            </div>
            <div class="modal-body" style="text-align:center; padding: 16px 0 4px;">
                <div style="width:64px;height:64px;border-radius:16px;background:var(--subtle-orange);color:var(--primary-orange);display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
                    <span class="material-symbols-rounded" style="font-size:32px;">delete_forever</span>
                </div>
                <p style="font-size:0.95rem;color:var(--text-primary);margin-bottom:8px;">
                    ¿Eliminar el grupo<br>
                    <strong id="del-nombre-grupo" style="color:var(--primary-orange);"></strong>?
                </p>
                <p style="font-size:0.82rem;color:var(--text-secondary);margin-bottom:20px;">
                    Se eliminarán también los horarios asociados. Esta acción es permanente.
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

    <script>
        // ── Dropdowns de estado ──────────────────────────────
        document.querySelectorAll('.dropdown-estado').forEach(dd => {
            const btn  = dd.querySelector('.tbl-btn.estado');
            const menu = dd.querySelector('.dropdown-estado-menu');
            btn.addEventListener('click', e => {
                e.stopPropagation();
                document.querySelectorAll('.dropdown-estado-menu.open').forEach(m => {
                    if (m !== menu) m.classList.remove('open');
                });
                menu.classList.toggle('open');
            });
        });
        document.addEventListener('click', () => {
            document.querySelectorAll('.dropdown-estado-menu.open').forEach(m => m.classList.remove('open'));
        });

        // ── Modal Horarios ───────────────────────────────────
        const horariosData = <?php echo json_encode($horarios_modal); ?>;
        const diasEsp = <?php echo json_encode($dias_esp); ?>;

        function abrirModalHorarios(grupoId, nombreGrupo) {
            const modal  = document.getElementById('modalHorarios');
            const body   = document.getElementById('modalBody');
            const titulo = document.getElementById('modalTitulo');
            const link   = document.getElementById('linkGestionar');

            titulo.innerText = nombreGrupo;
            link.href = "../horarios/index.php?grupo_id=" + grupoId;

            let html = "";
            if (horariosData[grupoId] && horariosData[grupoId].length > 0) {
                html = "<ul>";
                horariosData[grupoId].forEach(h => {
                    html += `<li>
                        <span class="material-symbols-rounded" style="color:var(--primary-green);font-size:20px;">event_available</span>
                        <div>
                            <div style="font-weight:600;font-size:0.95rem;">${diasEsp[h.dia_semana] || h.dia_semana}</div>
                            <div style="font-size:0.85rem;color:var(--text-secondary)">
                                ${h.hora_inicio.substring(0,5)} - ${h.hora_fin.substring(0,5)}
                                ${h.aula ? '· Aula: ' + h.aula : ''}
                            </div>
                        </div>
                    </li>`;
                });
                html += "</ul>";
            } else {
                html = `<div style="text-align:center;padding:20px;color:var(--text-secondary);">
                    <span class="material-symbols-rounded" style="font-size:40px;display:block;margin-bottom:10px;opacity:0.5;">calendar_today</span>
                    Este grupo no tiene horarios registrados.
                </div>`;
            }
            body.innerHTML = html;
            modal.classList.add('active');
        }

        function cerrarModal() {
            document.getElementById('modalHorarios').classList.remove('active');
        }

        // ── Alertas personalizadas dentro de modales ─────────
        function mostrarAlertaModal(divId, msg) {
            const div = document.getElementById(divId);
            div.innerHTML = `<span class="material-symbols-rounded">error</span>${msg}`;
            div.style.display = 'flex';
            div.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
        function ocultarAlertaModal(divId) {
            const div = document.getElementById(divId);
            if (div) div.style.display = 'none';
        }

        // ── Auto-ocultar alertas de página ───────────────────
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(a => {
                a.style.transition = 'opacity 0.5s ease';
                a.style.opacity = '0';
                setTimeout(() => a.remove(), 500);
            });
        }, 4000);

        // ── Modal Crear ──────────────────────────────────────
        function abrirModalCrear() {
            ocultarAlertaModal('modal-alert-crear');
            document.getElementById('modalCrearGrupo').classList.add('active');
        }
        function cerrarModalCrear() {
            document.getElementById('modalCrearGrupo').classList.remove('active');
        }

        document.getElementById('formCrearGrupo').addEventListener('submit', function(e) {
            const nombre      = document.getElementById('crear-nombre').value.trim();
            const curso_id    = document.getElementById('crear-curso_id').value;
            const fecha_ini   = document.getElementById('crear-fecha_inicio').value;
            const dia         = document.getElementById('crear-dia_semana').value;
            const hora_inicio = document.getElementById('crear-hora_inicio').value;
            const hora_fin    = document.getElementById('crear-hora_fin').value;

            if (!nombre) {
                e.preventDefault();
                mostrarAlertaModal('modal-alert-crear', 'El nombre del grupo es obligatorio.');
                return;
            }
            if (!curso_id) {
                e.preventDefault();
                mostrarAlertaModal('modal-alert-crear', 'Debes seleccionar un curso.');
                return;
            }
            if (!fecha_ini) {
                e.preventDefault();
                mostrarAlertaModal('modal-alert-crear', 'La fecha de inicio es obligatoria.');
                return;
            }
            if (!dia) {
                e.preventDefault();
                mostrarAlertaModal('modal-alert-crear', 'Debes seleccionar el día de la clase.');
                return;
            }
            if (!hora_inicio || !hora_fin) {
                e.preventDefault();
                mostrarAlertaModal('modal-alert-crear', 'Las horas de inicio y fin son obligatorias.');
                return;
            }
            if (hora_fin <= hora_inicio) {
                e.preventDefault();
                mostrarAlertaModal('modal-alert-crear', 'La hora de fin debe ser posterior a la hora de inicio.');
                return;
            }
            ocultarAlertaModal('modal-alert-crear');
        });

        // ── Modal Editar ─────────────────────────────────────
        function abrirModalEditar(datos) {
            ocultarAlertaModal('modal-alert-editar');

            document.getElementById('edit-id').value              = datos.id;
            document.getElementById('edit-grupo-id-label').innerText = 'Editando grupo #' + datos.id;
            document.getElementById('edit-nombre').value          = datos.nombre       || '';
            document.getElementById('edit-curso_id').value        = datos.curso_id     || '';
            document.getElementById('edit-profesor_id').value     = datos.profesor_id  || '';
            document.getElementById('edit-cupo').value            = datos.cupo_maximo  || 20;
            document.getElementById('edit-horario').value         = datos.horario      || '';
            document.getElementById('edit-aula').value            = datos.aula         || '';
            document.getElementById('edit-fecha_inicio').value    = datos.fecha_inicio || '';
            document.getElementById('edit-fecha_fin').value       = datos.fecha_fin    || '';
            document.getElementById('edit-estado').value          = datos.estado       || 'planificado';

            document.getElementById('modalEditarGrupo').classList.add('active');
        }
        function cerrarModalEditar() {
            document.getElementById('modalEditarGrupo').classList.remove('active');
        }

        document.getElementById('formEditarGrupo').addEventListener('submit', function(e) {
            const nombre    = document.getElementById('edit-nombre').value.trim();
            const curso_id  = document.getElementById('edit-curso_id').value;
            const fecha_ini = document.getElementById('edit-fecha_inicio').value;

            if (!nombre) {
                e.preventDefault();
                mostrarAlertaModal('modal-alert-editar', 'El nombre del grupo es obligatorio.');
                return;
            }
            if (!curso_id) {
                e.preventDefault();
                mostrarAlertaModal('modal-alert-editar', 'Debes seleccionar un curso.');
                return;
            }
            if (!fecha_ini) {
                e.preventDefault();
                mostrarAlertaModal('modal-alert-editar', 'La fecha de inicio es obligatoria.');
                return;
            }
            ocultarAlertaModal('modal-alert-editar');
        });

        // ── Modal Eliminar ───────────────────────────────────
        function abrirModalEliminar(id, nombre) {
            document.getElementById('del-id').value = id;
            document.getElementById('del-nombre-grupo').textContent = nombre;
            document.getElementById('modalEliminar').classList.add('active');
        }
        function cerrarModalEliminar() {
            document.getElementById('modalEliminar').classList.remove('active');
        }

        // ── Modal Finalizar Grupo ────────────────────────────
        function abrirModalFinalizar(id, nombre, estudiantesActivos) {
            document.getElementById('finalizar-grupo-id').value = id;
            document.getElementById('finalizar-grupo-nombre').textContent = nombre;
            document.getElementById('finalizar-header-sub').textContent =
                estudiantesActivos + ' estudiante' + (estudiantesActivos !== 1 ? 's' : '') + ' activo' + (estudiantesActivos !== 1 ? 's' : '');
            document.getElementById('finalizar-alert').style.display = 'none';
            document.getElementById('finalizar-loading').style.display = 'block';
            document.getElementById('finalizar-lista').style.display = 'none';
            document.getElementById('finalizar-sin-estudiantes').style.display = 'none';
            document.getElementById('finalizar-fill-all').style.display = 'none';

            const btn = document.getElementById('btn-confirmar-finalizar');
            btn.disabled = true;
            btn.innerHTML = '<span class="material-symbols-rounded">check_circle</span> Finalizar y generar';

            document.getElementById('modalFinalizarGrupo').classList.add('active');

            fetch('admin.php?action=get_estudiantes_grupo&id=' + id, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(r => r.json())
            .then(data => {
                document.getElementById('finalizar-loading').style.display = 'none';
                const estudiantes = data.estudiantes || [];

                if (estudiantes.length === 0) {
                    document.getElementById('finalizar-sin-estudiantes').style.display = 'block';
                    document.getElementById('finalizar-header-sub').textContent = 'Sin estudiantes activos';
                } else {
                    renderListaEstudiantes(estudiantes);
                    document.getElementById('finalizar-lista').style.display = 'block';
                    document.getElementById('finalizar-fill-all').style.display = 'flex';
                    document.getElementById('finalizar-header-sub').textContent =
                        estudiantes.length + ' estudiante' + (estudiantes.length !== 1 ? 's' : '') + ' recibirán certificado';
                }
                btn.disabled = false;
            })
            .catch(() => {
                document.getElementById('finalizar-loading').style.display = 'none';
                document.getElementById('finalizar-alert').textContent = 'No se pudieron cargar los estudiantes. Intenta de nuevo.';
                document.getElementById('finalizar-alert').style.display = 'block';
            });
        }

        function renderListaEstudiantes(estudiantes) {
            const lista = document.getElementById('finalizar-lista');
            let html = '';
            estudiantes.forEach((e, i) => {
                const borde = i < estudiantes.length - 1 ? 'border-bottom:1px solid var(--border-color);' : '';
                html += `<div style="display:flex; align-items:center; justify-content:space-between; padding:10px 14px; ${borde}">
                    <div style="display:flex; align-items:center; gap:10px; min-width:0; flex:1;">
                        <span class="material-symbols-rounded" style="color:var(--primary-green); font-size:20px; flex-shrink:0;">person</span>
                        <span style="font-size:0.88rem; font-weight:500; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${escHtml(e.estudiante_nombre)}</span>
                    </div>
                    <div style="display:flex; align-items:center; gap:6px; flex-shrink:0; margin-left:12px;">
                        <input type="number" class="cal-input"
                            data-estudiante-id="${e.estudiante_id}"
                            data-matricula-id="${e.matricula_id}"
                            min="0.1" max="5.0" step="0.1" value="5.0"
                            style="width:74px; padding:5px 8px; border-radius:8px; border:1px solid var(--border-color); background:var(--card-bg); color:var(--text-main); font-size:0.9rem; text-align:center;">
                        <span style="font-size:0.78rem; color:var(--text-secondary); white-space:nowrap;">/ 5.0</span>
                    </div>
                </div>`;
            });
            lista.innerHTML = html;
        }

        function escHtml(str) {
            return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }

        function aplicarCalATodos() {
            const cal = parseFloat(document.getElementById('finalizar-cal-todos').value);
            if (isNaN(cal) || cal < 0.1 || cal > 5.0) return;
            document.querySelectorAll('#finalizar-lista .cal-input').forEach(inp => {
                inp.value = cal.toFixed(1);
                inp.style.borderColor = '';
            });
        }

        function cerrarModalFinalizar() {
            document.getElementById('modalFinalizarGrupo').classList.remove('active');
        }

        function confirmarFinalizarGrupo() {
            const id = document.getElementById('finalizar-grupo-id').value;
            const alertDiv = document.getElementById('finalizar-alert');
            const btn = document.getElementById('btn-confirmar-finalizar');
            alertDiv.style.display = 'none';

            // Recoger calificación por estudiante
            const calificaciones = [];
            let valido = true;
            document.querySelectorAll('#finalizar-lista .cal-input').forEach(inp => {
                const cal = parseFloat(inp.value);
                if (isNaN(cal) || cal < 0.1 || cal > 5.0) {
                    valido = false;
                    inp.style.borderColor = '#f97316';
                } else {
                    inp.style.borderColor = '';
                    calificaciones.push({
                        estudiante_id: inp.dataset.estudianteId,
                        matricula_id:  inp.dataset.matriculaId,
                        calificacion:  cal
                    });
                }
            });

            if (!valido) {
                alertDiv.textContent = 'Revisa las calificaciones marcadas: deben estar entre 0.1 y 5.0';
                alertDiv.style.display = 'block';
                return;
            }

            btn.disabled = true;
            btn.innerHTML = '<span class="material-symbols-rounded">hourglass_empty</span> Procesando...';

            const formData = new FormData();
            formData.append('action', 'finalizar_grupo');
            formData.append('id', id);
            formData.append('calificaciones_json', JSON.stringify(calificaciones));

            fetch('admin.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    cerrarModalFinalizar();
                    const params = new URLSearchParams(window.location.search);
                    params.set('msg', 'finalizado');
                    params.set('generados', data.generados);
                    window.location.href = 'admin.php?' + params.toString();
                } else {
                    alertDiv.textContent = data.error || 'Error al finalizar el grupo';
                    alertDiv.style.display = 'block';
                    btn.disabled = false;
                    btn.innerHTML = '<span class="material-symbols-rounded">check_circle</span> Finalizar y generar';
                }
            })
            .catch(() => {
                alertDiv.textContent = 'Error de conexión. Intenta de nuevo.';
                alertDiv.style.display = 'block';
                btn.disabled = false;
                btn.innerHTML = '<span class="material-symbols-rounded">check_circle</span> Finalizar y generar';
            });
        }

        // ── Cerrar al clic en overlay ────────────────────────
        document.querySelectorAll('.modal-overlay').forEach(overlay => {
            overlay.addEventListener('click', function(e) {
                if (e.target === this) this.classList.remove('active');
            });
        });

        // ── Indicador scroll horizontal tabla de grupos ──────
        (function () {
            var container = document.querySelector('.table-wrapper');
            if (!container) return;
            if (container.parentElement.classList.contains('table-scroll-outer')) return;

            var outer = document.createElement('div');
            outer.className = 'table-scroll-outer';
            container.parentNode.insertBefore(outer, container);
            outer.appendChild(container);

            var badge = document.createElement('div');
            badge.className = 'table-scroll-badge';
            badge.innerHTML = '<span class="material-symbols-rounded">keyboard_double_arrow_right</span><span>Deslizar</span>';
            outer.appendChild(badge);

            function check() {
                var overflows = container.scrollWidth > container.clientWidth + 2;
                var atEnd = container.scrollLeft >= container.scrollWidth - container.clientWidth - 4;
                outer.classList.toggle('has-overflow', overflows && !atEnd);
            }
            check();
            container.addEventListener('scroll', check, { passive: true });
            window.addEventListener('resize', check, { passive: true });
        })();
    </script>
</body>

</html>