<?php
/**
 * Módulo de Prematrículas / Preinscripciones
 *
 * Estados reales del ENUM en BD:
 *   'pendiente' | 'contactado' | 'matriculado' | 'rechazado'
 */

require_once '../../../config/session.php';
require_once '../../../config/database.php';
require_once '../../../includes/auth_check.php';
require_once '../../../includes/notificaciones_helper.php';
require_role('admin');

// Datos del admin en sesión
try {
    $stmtU = $pdo->prepare("SELECT id, nombre FROM usuarios WHERE id = ?");
    $stmtU->execute([$_SESSION['user_id']]);
    $user = $stmtU->fetch(PDO::FETCH_ASSOC) ?: ['id' => $_SESSION['user_id'], 'nombre' => 'Administrador'];
} catch (PDOException $e) {
    $user = ['id' => $_SESSION['user_id'], 'nombre' => 'Administrador'];
}

$mensaje      = null;
$tipo_mensaje = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    // ──────────────────────────────────────────────────────
    // APROBAR
    // ──────────────────────────────────────────────────────
    if ($accion === 'aprobar') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT * FROM preinscripciones WHERE id = ? AND (estado IN ('pendiente','contactado') OR estado = '' OR estado IS NULL)");
            $stmt->execute([$id]);
            $pre = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$pre) throw new Exception("Preinscripción no encontrada o ya fue procesada.");

            $chk = $pdo->prepare("SELECT id FROM usuarios WHERE email = ? OR documento = ?");
            $chk->execute([$pre['email'], $pre['numero_documento']]);
            if ($chk->fetch()) throw new Exception("Ya existe un usuario con ese correo o documento.");

            $pass_hash = password_hash($pre['numero_documento'], PASSWORD_BCRYPT);
            $pdo->prepare("
                INSERT INTO usuarios
                    (nombre, email, password, documento, telefono,
                     direccion, fecha_nacimiento, rol, estado, fecha_registro)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'estudiante', 'activo', NOW())
            ")->execute([
                $pre['nombres_apellidos'],
                $pre['email'],
                $pass_hash,
                $pre['numero_documento'],
                $pre['celular']          ?? null,
                $pre['direccion']        ?? null,
                $pre['fecha_nacimiento'] ?? null,
            ]);
            $nuevo_usuario_id = $pdo->lastInsertId();

            $fecha_inicio_mat = !empty($pre['fecha_inicio']) ? $pre['fecha_inicio'] : date('Y-m-d');
            $pdo->prepare("
                INSERT INTO matriculas
                    (estudiante_id, grupo_id, fecha_matricula, fecha_inicio,
                     estado, observaciones, preinscripcion_id)
                VALUES (?, NULL, CURDATE(), ?, 'activa', ?, ?)
            ")->execute([
                $nuevo_usuario_id,
                $fecha_inicio_mat,
                "Matrícula generada desde preinscripción #{$id} — Programa: " . ($pre['programa'] ?? '—'),
                $id,
            ]);

            $pdo->prepare("
                UPDATE preinscripciones
                SET estado            = 'matriculado',
                    fecha_aprobacion  = NOW(),
                    usuario_creado_id = ?
                WHERE id = ?
            ")->execute([$nuevo_usuario_id, $id]);

            NotificacionesHelper::crearParaRoles(
                $pdo, ['admin'], 'sistema',
                'Nueva matrícula creada',
                "Se aprobó la preinscripción de {$pre['nombres_apellidos']} y se creó su matrícula. Pendiente asignar grupo.",
                $user['nombre'], 'alta',
                '/AMIMBR3/modules/inscripciones/matriculas/index.php'
            );
            NotificacionesHelper::estadoPreinscripcionCambiado($pdo, $nuevo_usuario_id, 'matriculado');

            $pdo->prepare("
                INSERT INTO logs_actividad (usuario_id, accion, detalles, ip_address)
                VALUES (?, 'preinscripcion_aprobada', ?, ?)
            ")->execute([
                $_SESSION['user_id'],
                "Aprobada preinscripción #{$id} — {$pre['nombres_apellidos']} — Matrícula creada",
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]);

            $pdo->commit();
            $mensaje      = "✅ Preinscripción aprobada. Matrícula creada — contraseña temporal: <strong>{$pre['numero_documento']}</strong>. Recuerda asignarle un grupo.";
            $tipo_mensaje = 'success';

        } catch (Exception $e) {
            $pdo->rollBack();
            $mensaje      = "❌ Error: " . htmlspecialchars($e->getMessage());
            $tipo_mensaje = 'error';
        }

    // ──────────────────────────────────────────────────────
    // MARCAR COMO CONTACTADO
    // ──────────────────────────────────────────────────────
    } elseif ($accion === 'contactar') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $rows = $pdo->prepare("
                UPDATE preinscripciones
                SET estado = 'contactado'
                WHERE id = ? AND (estado IN ('pendiente') OR estado = '' OR estado IS NULL)
            ");
            $rows->execute([$id]);

            if ($rows->rowCount() === 0) throw new Exception("La preinscripción no está en estado pendiente.");

            $pdo->prepare("
                INSERT INTO logs_actividad (usuario_id, accion, detalles, ip_address)
                VALUES (?, 'preinscripcion_contactada', ?, ?)
            ")->execute([
                $_SESSION['user_id'],
                "Marcada como contactado preinscripción #{$id}",
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]);

            $mensaje      = "Preinscripción marcada como contactada.";
            $tipo_mensaje = 'info';
        } catch (Exception $e) {
            $mensaje      = "Error: " . htmlspecialchars($e->getMessage());
            $tipo_mensaje = 'error';
        }

    // ──────────────────────────────────────────────────────
    // RECHAZAR
    // ──────────────────────────────────────────────────────
    } elseif ($accion === 'rechazar') {
        $id     = (int)($_POST['id'] ?? 0);
        $motivo = htmlspecialchars(strip_tags(trim($_POST['motivo'] ?? '')));
        try {
            $pdo->prepare("
                UPDATE preinscripciones
                SET estado           = 'rechazado',
                    motivo_rechazo   = ?,
                    fecha_aprobacion = NOW()
                WHERE id = ? AND (estado IN ('pendiente','contactado') OR estado = '' OR estado IS NULL)
            ")->execute([$motivo, $id]);

            $pdo->prepare("
                INSERT INTO logs_actividad (usuario_id, accion, detalles, ip_address)
                VALUES (?, 'preinscripcion_rechazada', ?, ?)
            ")->execute([
                $_SESSION['user_id'],
                "Rechazada preinscripción #{$id}. Motivo: {$motivo}",
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]);

            $mensaje      = "Preinscripción rechazada correctamente.";
            $tipo_mensaje = 'info';
        } catch (PDOException $e) {
            $mensaje      = "Error al rechazar: " . htmlspecialchars($e->getMessage());
            $tipo_mensaje = 'error';
        }

    // ──────────────────────────────────────────────────────
    // CREAR DESDE ADMIN
    // ──────────────────────────────────────────────────────
    } elseif ($accion === 'crear_admin') {
        try {
            $pdo->beginTransaction();

            $nombres      = htmlspecialchars(strip_tags(trim($_POST['nombres_apellidos']   ?? '')));
            $tipo_doc     = $_POST['tipo_documento']                                        ?? 'TI';
            $num_doc      = trim($_POST['numero_documento']                                 ?? '');
            $email        = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
            $celular      = trim($_POST['celular']                                          ?? '');
            $fecha_nac    = $_POST['fecha_nacimiento']                                      ?: null;
            $edad         = !empty($_POST['edad'])    ? (int)$_POST['edad']                 : null;
            $lugar_nac    = trim($_POST['lugar_nacimiento']                                 ?? '');
            $direccion    = trim($_POST['direccion']                                        ?? '');
            $barrio       = trim($_POST['barrio']                                           ?? '');
            $municipio    = trim($_POST['municipio']                                        ?? '');
            $zona         = trim($_POST['zona']                                             ?? '');
            $eps          = trim($_POST['eps']                                              ?? '');
            $est_primaria = isset($_POST['estudio_primaria'])     ? 1 : 0;
            $est_secund   = isset($_POST['estudio_secundaria'])   ? 1 : 0;
            $est_tecnico  = isset($_POST['estudio_tecnico'])      ? 1 : 0;
            $est_tecno    = isset($_POST['estudio_tecnologico'])  ? 1 : 0;
            $est_univ     = isset($_POST['estudio_universitario'])? 1 : 0;
            $est_otro     = trim($_POST['estudio_otro']                                     ?? '');
            $institucion  = trim($_POST['institucion_educativa']                            ?? '');
            $ocupacion    = trim($_POST['ocupacion']                                        ?? '');
            $sisben       = trim($_POST['nivel_sisben']                                     ?? '');
            $estrato      = !empty($_POST['estrato'])  ? (int)$_POST['estrato']             : null;
            $nom_acud     = trim($_POST['nombre_acudiente']                                 ?? '');
            $par_acud     = trim($_POST['parentesco_acudiente']                             ?? '');
            $tel_acud     = trim($_POST['telefono_acudiente']                               ?? '');
            $email_acud   = trim($_POST['email_acudiente']                                  ?? '');
            $num_recibo   = trim($_POST['numero_recibo']                                    ?? '');
            $programa     = trim($_POST['programa']                                         ?? '');
            $taller       = trim($_POST['taller']                                           ?? '');
            $fecha_inicio = $_POST['fecha_inicio']                                          ?: null;
            $dia_clase    = trim($_POST['dia_clase']                                        ?? '');
            $hora_clase   = !empty($_POST['hora_clase']) ? $_POST['hora_clase']             : null;
            $aut_imagen   = isset($_POST['autoriza_imagen'])      ? 1 : 0;
            $cc_acud      = trim($_POST['firma_acudiente_cc']                               ?? '');
            $observaciones= trim($_POST['observaciones']                                    ?? '');

            if (empty($nombres) || empty($num_doc) || empty($email) || empty($programa)) {
                throw new Exception("Faltan campos obligatorios: nombre, documento, email y programa.");
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("El correo electrónico no es válido.");
            }
            if (strlen($num_doc) < 5) {
                throw new Exception("El número de documento debe tener al menos 5 caracteres.");
            }

            $chkP = $pdo->prepare("SELECT id FROM preinscripciones WHERE email = ? OR numero_documento = ?");
            $chkP->execute([$email, $num_doc]);
            if ($chkP->fetch()) throw new Exception("Ya existe una preinscripción con ese correo o documento.");

            $pdo->prepare("
                INSERT INTO preinscripciones (
                    nombres_apellidos, tipo_documento, numero_documento, email, celular,
                    fecha_nacimiento, edad, lugar_nacimiento, direccion, barrio,
                    municipio, zona, eps,
                    estudio_primaria, estudio_secundaria, estudio_tecnico,
                    estudio_tecnologico, estudio_universitario, estudio_otro,
                    institucion_educativa, ocupacion, nivel_sisben, estrato,
                    nombre_acudiente, parentesco_acudiente, telefono_acudiente,
                    email_acudiente, numero_recibo,
                    programa, taller, fecha_inicio, dia_clase, hora_clase,
                    autoriza_imagen, firma_acudiente_cc,
                    observaciones, estado, ip_address
                ) VALUES (
                    ?,?,?,?,?,  ?,?,?,?,?,  ?,?,?,
                    ?,?,?,      ?,?,?,
                    ?,?,?,?,    ?,?,?,
                    ?,?,        ?,?,?,?,?,
                    ?,?,        ?,'pendiente',?
                )
            ")->execute([
                $nombres, $tipo_doc, $num_doc, $email, $celular ?: null,
                $fecha_nac, $edad, $lugar_nac ?: null, $direccion ?: null, $barrio ?: null,
                $municipio ?: null, $zona ?: null, $eps ?: null,
                $est_primaria, $est_secund, $est_tecnico,
                $est_tecno, $est_univ, $est_otro ?: null,
                $institucion ?: null, $ocupacion ?: null, $sisben ?: null, $estrato,
                $nom_acud ?: null, $par_acud ?: null, $tel_acud ?: null,
                $email_acud ?: null, $num_recibo ?: null,
                $programa, $taller ?: null, $fecha_inicio, $dia_clase ?: null, $hora_clase,
                $aut_imagen, $cc_acud ?: null,
                $observaciones ?: null, $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
            $preId = $pdo->lastInsertId();

            $pdo->prepare("
                INSERT INTO logs_actividad (usuario_id, accion, detalles, ip_address)
                VALUES (?, 'preinscripcion_creada_admin', ?, ?)
            ")->execute([
                $_SESSION['user_id'],
                "Admin registró preinscripción #{$preId} para {$nombres} — Programa: {$programa}",
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]);

            $pdo->commit();
            $mensaje      = "✅ Prematrícula registrada correctamente para <strong>{$nombres}</strong>. Ahora puedes aprobarla desde el listado.";
            $tipo_mensaje = 'success';

        } catch (Exception $e) {
            $pdo->rollBack();
            $mensaje      = "❌ Error: " . htmlspecialchars($e->getMessage());
            $tipo_mensaje = 'error';
        }
    }
}

// ═══════════════════════════════════════════════════════════
//  FILTROS Y PAGINACIÓN
// ═══════════════════════════════════════════════════════════
$buscar  = trim($_GET['q']      ?? '');
$estado  = $_GET['estado']      ?? 'todos';
$pagina  = max(1, (int)($_GET['page'] ?? 1));
$por_pag = 10;
$offset  = ($pagina - 1) * $por_pag;

$where  = [];
$params = [];

if ($buscar !== '') {
    $where[]  = "(p.nombres_apellidos LIKE ? OR p.email LIKE ? OR p.numero_documento LIKE ? OR p.programa LIKE ?)";
    $like     = "%{$buscar}%";
    $params   = array_merge($params, [$like, $like, $like, $like]);
}
if ($estado !== 'todos') {
    $where[]  = "p.estado = ?";
    $params[] = $estado;
}

$sql_where = $where ? 'WHERE ' . implode(' AND ', $where) : '';

try {
    $cntRow = $pdo->query("
        SELECT
            SUM(estado = 'pendiente' OR estado = '' OR estado IS NULL) AS pendientes,
            SUM(estado = 'contactado')                                  AS contactados,
            SUM(estado = 'matriculado')                                 AS matriculados,
            SUM(estado = 'rechazado')                                   AS rechazados,
            COUNT(*)                                                     AS total
        FROM preinscripciones
    ")->fetch(PDO::FETCH_ASSOC);
    $cnt = $cntRow ?: ['pendientes'=>0,'contactados'=>0,'matriculados'=>0,'rechazados'=>0,'total'=>0];
} catch (PDOException $e) {
    $cnt = ['pendientes'=>0,'contactados'=>0,'matriculados'=>0,'rechazados'=>0,'total'=>0];
}

try {
    $stmtC = $pdo->prepare("SELECT COUNT(*) FROM preinscripciones p {$sql_where}");
    $stmtC->execute($params);
    $total_registros = (int)$stmtC->fetchColumn();
    $total_paginas   = max(1, (int)ceil($total_registros / $por_pag));
} catch (PDOException $e) {
    $total_registros = 0;
    $total_paginas   = 1;
}

try {
    $stmtL = $pdo->prepare("
        SELECT p.*
        FROM preinscripciones p
        {$sql_where}
        ORDER BY
            FIELD(p.estado, 'pendiente', 'contactado', 'matriculado', 'rechazado'),
            p.fecha_preinscripcion DESC
        LIMIT {$por_pag} OFFSET {$offset}
    ");
    $stmtL->execute($params);
    $preinscripciones = $stmtL->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $preinscripciones = [];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prematrículas — Amimbré</title>
    <link rel="shortcut icon" href="../../../assets/img/3.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,1,0"/>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="../../../assets/css/colores.css">
    <link rel="stylesheet" href="../../../assets/css/style-prematriculas.css">
    <script>
        (function() {
            const t = localStorage.getItem('amimbre-theme');
            if (t === 'light') document.documentElement.setAttribute('data-theme', 'light');
        })();
    </script>
</head>
<body>

<?php require_once '../../../includes/header.php'; ?>

<main class="main-content" id="mainContent">

    <div class="page-header">
        <div>
            <h1 class="page-title">Prematrículas</h1>
            <p class="page-subtitle">Gestiona las solicitudes de preinscripción recibidas</p>
        </div>
        <button class="btn-primary" onclick="abrirModal('modalCrear')">
            <span class="material-symbols-rounded">person_add</span>
            Nueva Prematrícula
        </button>
    </div>

    <?php if ($mensaje): ?>
    <div class="alert alert-<?= $tipo_mensaje ?>" id="alertMsg">
        <?= $mensaje ?>
        <button class="alert-close" onclick="this.parentElement.remove()">
            <span class="material-symbols-rounded">close</span>
        </button>
    </div>
    <?php endif; ?>

    <div class="stats-row">
        <a class="stat-pill stat-pill--warning <?= $estado==='pendiente'   ? 'stat-pill--active' : '' ?>" href="?estado=pendiente">
            <span class="material-symbols-rounded">schedule</span>
            <div><span class="pill-num"><?= (int)$cnt['pendientes'] ?></span><span class="pill-lbl">Pendientes</span></div>
        </a>
        <a class="stat-pill stat-pill--info <?= $estado==='contactado'  ? 'stat-pill--active' : '' ?>" href="?estado=contactado">
            <span class="material-symbols-rounded">contact_phone</span>
            <div><span class="pill-num"><?= (int)$cnt['contactados'] ?></span><span class="pill-lbl">Contactados</span></div>
        </a>
        <a class="stat-pill stat-pill--success <?= $estado==='matriculado' ? 'stat-pill--active' : '' ?>" href="?estado=matriculado">
            <span class="material-symbols-rounded">check_circle</span>
            <div><span class="pill-num"><?= (int)$cnt['matriculados'] ?></span><span class="pill-lbl">Matriculados</span></div>
        </a>
        <a class="stat-pill stat-pill--danger <?= $estado==='rechazado'  ? 'stat-pill--active' : '' ?>" href="?estado=rechazado">
            <span class="material-symbols-rounded">cancel</span>
            <div><span class="pill-num"><?= (int)$cnt['rechazados'] ?></span><span class="pill-lbl">Rechazados</span></div>
        </a>
        <a class="stat-pill stat-pill--neutral <?= $estado==='todos' ? 'stat-pill--active' : '' ?>" href="?estado=todos">
            <span class="material-symbols-rounded">list_alt</span>
            <div><span class="pill-num"><?= (int)$cnt['total'] ?></span><span class="pill-lbl">Total</span></div>
        </a>
    </div>

    <div class="toolbar">
        <form method="GET" class="search-box" id="searchForm">
            <span class="material-symbols-rounded">search</span>
            <input type="text" name="q"
                   value="<?= htmlspecialchars($buscar) ?>"
                   placeholder="Buscar por nombre, email, documento o programa…"
                   autocomplete="off"
                   oninput="clearTimeout(window._st);window._st=setTimeout(()=>this.form.submit(),400)">
            <input type="hidden" name="estado" value="<?= htmlspecialchars($estado) ?>">
        </form>

        <?php
        $filtro_opciones = [
            'todos'       => ['lbl' => 'Todos los estados', 'icon' => 'list_alt',      'cls' => ''],
            'pendiente'   => ['lbl' => 'Pendientes',        'icon' => 'schedule',       'cls' => 'fdd--warning'],
            'contactado'  => ['lbl' => 'Contactados',       'icon' => 'contact_phone',  'cls' => 'fdd--info'],
            'matriculado' => ['lbl' => 'Matriculados',      'icon' => 'check_circle',   'cls' => 'fdd--success'],
            'rechazado'   => ['lbl' => 'Rechazados',        'icon' => 'cancel',         'cls' => 'fdd--danger'],
        ];
        $actual = $filtro_opciones[$estado] ?? $filtro_opciones['todos'];
        ?>
        <div class="fdd-wrap" id="fddWrap">
            <button type="button" class="fdd-trigger <?= $actual['cls'] ?>" id="fddTrigger" onclick="toggleFdd()">
                <span class="material-symbols-rounded">filter_list</span>
                <span class="fdd-trigger-lbl"><?= $actual['lbl'] ?></span>
                <span class="material-symbols-rounded fdd-chevron">expand_more</span>
            </button>
            <div class="fdd-menu" id="fddMenu">
                <?php foreach ($filtro_opciones as $val => $opt): ?>
                <a href="?estado=<?= $val ?>&q=<?= urlencode($buscar) ?>"
                   class="fdd-item <?= $opt['cls'] ?> <?= $estado === $val ? 'fdd-item--active' : '' ?>">
                    <span class="material-symbols-rounded"><?= $opt['icon'] ?></span>
                    <?= $opt['lbl'] ?>
                    <?php if ($estado === $val): ?>
                    <span class="material-symbols-rounded fdd-check">check</span>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="inscripciones-lista">
        <?php if (empty($preinscripciones)): ?>
        <div class="empty-state">
            <span class="material-symbols-rounded">inbox</span>
            <p>No hay preinscripciones que coincidan con los filtros.</p>
        </div>
        <?php else: ?>
        <?php foreach ($preinscripciones as $p):
            $estado_real = (!empty($p['estado'])) ? $p['estado'] : 'pendiente';
            $badge_cls = match($estado_real) {
                'matriculado' => 'badge--success',
                'rechazado'   => 'badge--danger',
                'contactado'  => 'badge--info',
                default       => 'badge--warning',
            };
            $badge_lbl = match($estado_real) {
                'matriculado' => 'Matriculado',
                'rechazado'   => 'Rechazado',
                'contactado'  => 'Contactado',
                default       => 'Pendiente',
            };
            $fecha = !empty($p['fecha_preinscripcion'])
                ? date('d/m/Y', strtotime($p['fecha_preinscripcion'])) : '—';
        ?>
        <div class="inscripcion-card" id="card-<?= $p['id'] ?>">
            <?php
            $avatar_color = match($estado_real) {
                'matriculado' => 'avatar-matriculado',
                'rechazado'   => 'avatar-rechazado',
                'contactado'  => 'avatar-contactado',
                default       => 'avatar-pendiente',
            };
            ?>
            <div class="card-avatar <?= $avatar_color ?>">
                <?= mb_strtoupper(mb_substr($p['nombres_apellidos'], 0, 1)) ?>
            </div>
            <div class="card-info">
                <div class="card-top">
                    <span class="card-nombre"><?= htmlspecialchars($p['nombres_apellidos']) ?></span>
                    <span class="badge <?= $badge_cls ?>"><?= $badge_lbl ?></span>
                </div>
                <span class="card-email"><?= htmlspecialchars($p['email']) ?></span>
                <div class="card-meta">
                    <span><span class="material-symbols-rounded">badge</span><?= htmlspecialchars($p['tipo_documento'] ?? '') ?> <?= htmlspecialchars($p['numero_documento'] ?? '—') ?></span>
                    <span><span class="material-symbols-rounded">music_note</span><?= htmlspecialchars($p['programa'] ?? '—') ?></span>
                    <span><span class="material-symbols-rounded">calendar_today</span><?= $fecha ?></span>
                    <?php if (!empty($p['municipio'])): ?>
                    <span><span class="material-symbols-rounded">location_on</span><?= htmlspecialchars($p['municipio']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-actions">
                <button class="btn-icon btn-icon--view" onclick="verDetalle(<?= $p['id'] ?>)" title="Ver detalles">
                    <span class="material-symbols-rounded">visibility</span> Ver
                </button>
                <?php if ($p['estado'] === 'pendiente' || $p['estado'] === 'contactado'): ?>
                <button class="btn-icon btn-icon--approve"
                        onclick="confirmarAprobar(<?= $p['id'] ?>, '<?= addslashes(htmlspecialchars($p['nombres_apellidos'])) ?>')"
                        title="Aprobar y matricular">
                    <span class="material-symbols-rounded">check_circle</span> Aprobar
                </button>
                <button class="btn-icon btn-icon--reject"
                        onclick="abrirModalRechazar(<?= $p['id'] ?>, '<?= addslashes(htmlspecialchars($p['nombres_apellidos'])) ?>')"
                        title="Rechazar">
                    <span class="material-symbols-rounded">cancel</span> Rechazar
                </button>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php if ($total_paginas > 1): ?>
    <div class="pagination">
        <?php if ($pagina > 1): ?>
        <a href="?page=<?= $pagina-1 ?>&q=<?= urlencode($buscar) ?>&estado=<?= urlencode($estado) ?>" class="page-btn">
            <span class="material-symbols-rounded">chevron_left</span>
        </a>
        <?php endif; ?>
        <?php for ($i = max(1,$pagina-2); $i <= min($total_paginas,$pagina+2); $i++): ?>
        <a href="?page=<?= $i ?>&q=<?= urlencode($buscar) ?>&estado=<?= urlencode($estado) ?>"
           class="page-btn <?= $i===$pagina ? 'page-btn--active' : '' ?>"><?= $i ?></a>
        <?php endfor; ?>
        <?php if ($pagina < $total_paginas): ?>
        <a href="?page=<?= $pagina+1 ?>&q=<?= urlencode($buscar) ?>&estado=<?= urlencode($estado) ?>" class="page-btn">
            <span class="material-symbols-rounded">chevron_right</span>
        </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</main>


<!-- ══ MODAL: Ver Detalle ════════════════════════════════════ -->
<div class="modal-overlay" id="modalDetalle" aria-hidden="true">
    <div class="modal modal--lg">
        <div class="modal-header" id="detalleHeader">
            <h2 class="modal-title">
                <span class="material-symbols-rounded">person</span>
                Detalle de Preinscripción
            </h2>
            <button class="modal-close" onclick="cerrarModal('modalDetalle')">
                <span class="material-symbols-rounded">close</span>
            </button>
        </div>
        <div class="estado-progreso" id="estadoProgreso"></div>
        <div class="modal-body" id="detalleContent">
            <p class="loading-txt">Cargando información…</p>
        </div>
        <div class="modal-footer" id="detalleFooter">
            <button class="btn-secondary" onclick="cerrarModal('modalDetalle')">Cerrar</button>
        </div>
    </div>
</div>

<!-- ══ MODAL: Confirmar Aprobar ══════════════════════════════ -->
<div class="modal-overlay" id="modalAprobar" aria-hidden="true">
    <div class="modal modal--sm">
        <div class="modal-header modal-header--success">
            <h2 class="modal-title">
                <span class="material-symbols-rounded">check_circle</span>
                Confirmar Aprobación
            </h2>
            <button class="modal-close" onclick="cerrarModal('modalAprobar')">
                <span class="material-symbols-rounded">close</span>
            </button>
        </div>
        <div class="modal-body">
            <p id="aprobarTexto" style="font-size:.95rem;color:var(--text-primary);margin-bottom:14px;"></p>
            <div class="modal-info-box modal-info-box--success">
                <span class="material-symbols-rounded">key</span>
                Se creará el usuario con <strong>contraseña temporal = número de documento</strong>.
                El estudiante podrá cambiarla al iniciar sesión.
            </div>
        </div>
        <form method="POST">
            <input type="hidden" name="accion" value="aprobar">
            <input type="hidden" name="id" id="aprobarId">
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="cerrarModal('modalAprobar')">Cancelar</button>
                <button type="submit" class="btn-success">
                    <span class="material-symbols-rounded">check_circle</span> Sí, aprobar
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ══ MODAL: Rechazar ═══════════════════════════════════════ -->
<div class="modal-overlay" id="modalRechazar" aria-hidden="true">
    <div class="modal modal--sm">
        <div class="modal-header modal-header--danger">
            <h2 class="modal-title">
                <span class="material-symbols-rounded">cancel</span>
                Rechazar Preinscripción
            </h2>
            <button class="modal-close" onclick="cerrarModal('modalRechazar')">
                <span class="material-symbols-rounded">close</span>
            </button>
        </div>
        <div class="modal-body">
            <p id="rechazarNombre" class="rechazar-nombre"></p>
            <form method="POST" id="formRechazar">
                <input type="hidden" name="accion" value="rechazar">
                <input type="hidden" name="id" id="rechazarId">
                <div class="form-group-modal">
                    <label for="motivoRechazo">Motivo del rechazo <span class="req">*</span></label>
                    <textarea id="motivoRechazo" name="motivo" rows="4"
                              placeholder="Explica el motivo del rechazo…" required></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="cerrarModal('modalRechazar')">Cancelar</button>
                    <button type="submit" class="btn-danger">
                        <span class="material-symbols-rounded">cancel</span> Confirmar Rechazo
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ══ MODAL: Nueva Prematrícula ═════════════════════════════ -->
<div class="modal-overlay" id="modalCrear" aria-hidden="true">
    <div class="modal modal--xl">
        <div class="modal-header modal-header--primary">
            <h2 class="modal-title">
                <span class="material-symbols-rounded">assignment_ind</span>
                Nueva Prematrícula
            </h2>
            <button class="modal-close" onclick="cerrarModal('modalCrear')">
                <span class="material-symbols-rounded">close</span>
            </button>
        </div>

        <form method="POST" id="formCrear" onsubmit="return validarFormCrear()">
            <input type="hidden" name="accion" value="crear_admin">
            <div class="modal-body">

                <!-- ── 1. Datos personales ─────────────────────── -->
                <div class="form-seccion">
                    <div class="form-seccion-titulo">
                        <span class="material-symbols-rounded">person</span>
                        Datos Personales
                    </div>
                    <div class="modal-grid">
                        <div class="form-group-modal col-full">
                            <label>Nombres y apellidos <span class="req">*</span></label>
                            <input type="text" name="nombres_apellidos" required
                                   placeholder="Nombre completo del estudiante">
                        </div>
                        <div class="form-group-modal">
                            <label>Tipo de documento <span class="req">*</span></label>
                            <select name="tipo_documento" required>
                                <option value="TI">Tarjeta de Identidad (TI)</option>
                                <option value="CC">Cédula de Ciudadanía (CC)</option>
                                <option value="CE">Cédula de Extranjería (CE)</option>
                                <option value="PA">Pasaporte (PA)</option>
                                <option value="RC">Registro Civil (RC)</option>
                                <option value="NIT">NIT</option>
                                <option value="OTRO">Otro</option>
                            </select>
                        </div>
                        <div class="form-group-modal">
                            <label>Número de documento <span class="req">*</span></label>
                            <input type="text" name="numero_documento" id="inputDoc" required
                                   placeholder="Sin puntos ni guiones">
                        </div>
                        <div class="form-group-modal">
                            <label>Fecha de nacimiento</label>
                            <input type="date" name="fecha_nacimiento" id="inputFechaNac"
                                   onchange="calcularEdad()">
                        </div>
                        <div class="form-group-modal">
                            <label>Edad</label>
                            <input type="number" name="edad" id="inputEdad" min="1" max="120"
                                   placeholder="Se calcula automáticamente">
                        </div>
                        <div class="form-group-modal">
                            <label>Lugar de nacimiento</label>
                            <input type="text" name="lugar_nacimiento" placeholder="Ciudad o municipio">
                        </div>
                        <div class="form-group-modal">
                            <label>Correo electrónico <span class="req">*</span></label>
                            <input type="email" name="email" required placeholder="correo@ejemplo.com">
                        </div>
                        <div class="form-group-modal">
                            <label>Celular <span class="req">*</span></label>
                            <input type="tel" name="celular" required placeholder="3XX XXX XXXX">
                        </div>
                        <div class="form-group-modal">
                            <label>Ocupación</label>
                            <input type="text" name="ocupacion" placeholder="Ej: Estudiante, Empleado…">
                        </div>
                        <!-- CAMPO TI ELIMINADO: era redundante con "Número de documento" -->
                    </div>
                </div>

                <!-- ── 2. Ubicación ───────────────────────────── -->
                <div class="form-seccion">
                    <div class="form-seccion-titulo">
                        <span class="material-symbols-rounded">location_on</span>
                        Ubicación
                    </div>
                    <div class="modal-grid">
                        <div class="form-group-modal col-full">
                            <label>Dirección</label>
                            <input type="text" name="direccion" placeholder="Carrera / Calle y número">
                        </div>
                        <div class="form-group-modal">
                            <label>Barrio</label>
                            <input type="text" name="barrio" placeholder="Barrio de residencia">
                        </div>
                        <div class="form-group-modal">
                            <label>Municipio</label>
                            <input type="text" name="municipio" placeholder="Ej: El Carmen de Viboral">
                        </div>
                        <div class="form-group-modal">
                            <label>Zona</label>
                            <select name="zona">
                                <option value="">— Selecciona —</option>
                                <option>Urbana</option>
                                <option>Rural</option>
                            </select>
                        </div>
                        <div class="form-group-modal">
                            <label>Estrato</label>
                            <select name="estrato">
                                <option value="">— Selecciona —</option>
                                <option value="1">1</option>
                                <option value="2">2</option>
                                <option value="3">3</option>
                                <option value="4">4</option>
                                <option value="5">5</option>
                                <option value="6">6</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- ── 3. Salud y socioeconómico ──────────────── -->
                <div class="form-seccion">
                    <div class="form-seccion-titulo">
                        <span class="material-symbols-rounded">local_hospital</span>
                        Salud y Datos Socioeconómicos
                    </div>
                    <div class="modal-grid">
                        <div class="form-group-modal">
                            <label>EPS</label>
                            <input type="text" name="eps" placeholder="Entidad de salud">
                        </div>
                        <div class="form-group-modal">
                            <label>Nivel SISBEN</label>
                            <input type="text" name="nivel_sisben" placeholder="Ej: A1, B2…">
                        </div>
                        <div class="form-group-modal">
                            <label>Institución educativa</label>
                            <input type="text" name="institucion_educativa"
                                   placeholder="Colegio o institución actual">
                        </div>
                    </div>

                    <!-- Checkboxes nivel educativo — ESTRUCTURA CORREGIDA -->
                    <div class="form-group-modal" style="margin-top:14px;">
                        <label>Nivel de estudios alcanzado</label>
                        <div class="checkboxes-grid">
                            <label class="check-item">
                                <input type="checkbox" name="estudio_primaria" value="1">
                                <span class="check-box"></span>
                                <span class="check-label">Primaria</span>
                            </label>
                            <label class="check-item">
                                <input type="checkbox" name="estudio_secundaria" value="1">
                                <span class="check-box"></span>
                                <span class="check-label">Secundaria</span>
                            </label>
                            <label class="check-item">
                                <input type="checkbox" name="estudio_tecnico" value="1">
                                <span class="check-box"></span>
                                <span class="check-label">Técnico</span>
                            </label>
                            <label class="check-item">
                                <input type="checkbox" name="estudio_tecnologico" value="1">
                                <span class="check-box"></span>
                                <span class="check-label">Tecnológico</span>
                            </label>
                            <label class="check-item">
                                <input type="checkbox" name="estudio_universitario" value="1">
                                <span class="check-box"></span>
                                <span class="check-label">Universitario</span>
                            </label>
                        </div>
                    </div>

                    <div class="form-group-modal" style="margin-top:10px;">
                        <label>Otro nivel de estudios</label>
                        <input type="text" name="estudio_otro" placeholder="Especifica si aplica">
                    </div>
                </div>

                <!-- ── 4. Programa ────────────────────────────── -->
                <div class="form-seccion">
                    <div class="form-seccion-titulo">
                        <span class="material-symbols-rounded">music_note</span>
                        Programa Musical
                    </div>
                    <div class="modal-grid">
                        <div class="form-group-modal">
                            <label>Programa <span class="req">*</span></label>
                            <select name="programa" required>
                                <option value="">— Selecciona —</option>
                                <option>Iniciación Musical Infantil</option>
                                <option>Guitarra</option>
                                <option>Piano</option>
                                <option>Instrumentos de Viento</option>
                                <option>Técnica Vocal y Canto</option>
                                <option>Teoría y Lenguaje Musical</option>
                                <option>Ensambles Musicales</option>
                                <option>Preparación Universitaria</option>
                            </select>
                        </div>
                        <div class="form-group-modal">
                            <label>Taller específico</label>
                            <input type="text" name="taller" placeholder="Ej: Guitarra eléctrica, Saxofón…">
                        </div>
                        <div class="form-group-modal">
                            <label>Fecha de inicio deseada</label>
                            <input type="date" name="fecha_inicio">
                        </div>
                        <div class="form-group-modal">
                            <label>Día(s) de clase preferido</label>
                            <input type="text" name="dia_clase" placeholder="Ej: Lunes y Miércoles">
                        </div>
                        <div class="form-group-modal">
                            <label>Hora de clase preferida</label>
                            <input type="time" name="hora_clase">
                        </div>
                        <div class="form-group-modal">
                            <label>Número de recibo de pago</label>
                            <input type="text" name="numero_recibo" placeholder="N° del recibo si ya pagó">
                        </div>
                    </div>
                </div>

                <!-- ── 5. Acudiente ───────────────────────────── -->
                <div class="form-seccion">
                    <div class="form-seccion-titulo">
                        <span class="material-symbols-rounded">family_restroom</span>
                        Datos del Acudiente
                    </div>
                    <div class="modal-grid">
                        <div class="form-group-modal">
                            <label>Nombre del acudiente</label>
                            <input type="text" name="nombre_acudiente" placeholder="Nombre completo">
                        </div>
                        <div class="form-group-modal">
                            <label>Parentesco</label>
                            <select name="parentesco_acudiente">
                                <option value="">— Selecciona —</option>
                                <option>Madre</option>
                                <option>Padre</option>
                                <option>Abuelo/a</option>
                                <option>Hermano/a</option>
                                <option>Tío/a</option>
                                <option>Tutor legal</option>
                                <option>Otro</option>
                            </select>
                        </div>
                        <div class="form-group-modal">
                            <label>Teléfono del acudiente</label>
                            <input type="tel" name="telefono_acudiente" placeholder="3XX XXX XXXX">
                        </div>
                        <div class="form-group-modal">
                            <label>Email del acudiente</label>
                            <input type="email" name="email_acudiente" placeholder="correo@ejemplo.com">
                        </div>
                        <div class="form-group-modal">
                            <label>CC del acudiente firmante</label>
                            <input type="text" name="firma_acudiente_cc" placeholder="Número de cédula">
                        </div>
                    </div>
                </div>

                <!-- ── 6. Autorización y observaciones ────────── -->
                <div class="form-seccion">
                    <div class="form-seccion-titulo">
                        <span class="material-symbols-rounded">policy</span>
                        Autorización y Observaciones
                    </div>
                    <div class="modal-grid">
                        <div class="form-group-modal col-full">
                            <!-- Checkbox autorización imagen — misma estructura que los educativos -->
                            <label class="check-item check-item--full">
                                <input type="checkbox" name="autoriza_imagen" value="1">
                                <span class="check-box"></span>
                                <span class="check-label">El acudiente <strong>autoriza el uso de imagen</strong> del estudiante con fines pedagógicos e institucionales</span>
                            </label>
                        </div>
                        <div class="form-group-modal col-full">
                            <label>Observaciones</label>
                            <textarea name="observaciones" rows="3"
                                      placeholder="Información adicional relevante…"></textarea>
                        </div>
                    </div>
                </div>

            </div><!-- /modal-body -->

            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="cerrarModal('modalCrear')">Cancelar</button>
                <button type="submit" class="btn-primary">
                    <span class="material-symbols-rounded">save</span>
                    Registrar Prematrícula
                </button>
            </div>
        </form>
    </div>
</div>


<!-- ══ Datos JSON para modal de detalle ══════════════════════ -->
<script>
const DATA = <?= json_encode(
    array_map(function($p) {
        if (empty($p['estado'])) $p['estado'] = 'pendiente';
        return $p;
    }, array_column($preinscripciones, null, 'id')),
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG
) ?>;

// ── Helpers modales ───────────────────────────────────────────
function abrirModal(id) {
    const m = document.getElementById(id);
    if (!m) return;
    m.classList.add('open');
    m.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
}
function cerrarModal(id) {
    const m = document.getElementById(id);
    if (!m) return;
    m.classList.remove('open');
    m.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
}
document.querySelectorAll('.modal-overlay').forEach(o =>
    o.addEventListener('click', e => { if (e.target === o) cerrarModal(o.id); })
);
document.addEventListener('keydown', e => {
    if (e.key === 'Escape')
        document.querySelectorAll('.modal-overlay.open').forEach(o => cerrarModal(o.id));
});

// ── Confirmar aprobar ─────────────────────────────────────────
function confirmarAprobar(id, nombre) {
    document.getElementById('aprobarId').value      = id;
    document.getElementById('aprobarTexto').innerHTML =
        `¿Aprobar y matricular a <strong>${nombre}</strong>?`;
    abrirModal('modalAprobar');
}

// ── Abrir modal rechazar ──────────────────────────────────────
function abrirModalRechazar(id, nombre) {
    document.getElementById('rechazarId').value         = id;
    document.getElementById('rechazarNombre').innerHTML =
        `Rechazando solicitud de: <strong>${nombre}</strong>`;
    document.getElementById('motivoRechazo').value      = '';
    abrirModal('modalRechazar');
}

// ── Ver detalle con barra de progreso corregida ───────────────
function verDetalle(id) {
    const p = DATA[id];
    if (!p) { alert('No se encontraron los datos.'); return; }

    const val = v => (v !== null && v !== undefined && v !== '')
        ? v : '<span class="nd">—</span>';
    const fmt = f => f ? new Date(f + 'T00:00:00').toLocaleDateString('es-CO') : '—';

    const stCls = { matriculado:'badge--success', rechazado:'badge--danger',
                    contactado:'badge--info',      pendiente:'badge--warning' };
    const stLbl = { matriculado:'Matriculado', rechazado:'Rechazado',
                    contactado:'Contactado',   pendiente:'Pendiente' };

    // ── Barra de progreso ─────────────────────────────────────
    // Orden numérico de los estados del flujo principal
    const pasos = [
        { key: 'pendiente',   icon: 'schedule',       lbl: 'Pendiente' },
        { key: 'contactado',  icon: 'contact_phone',  lbl: 'Contactado' },
        { key: 'matriculado', icon: 'check_circle',   lbl: 'Matriculado' },
    ];
    const orden = { pendiente: 0, contactado: 1, matriculado: 2, rechazado: -1 };
    const posActual = orden[p.estado] ?? -1;

    let progresoHtml = '';

    if (p.estado === 'rechazado') {
        progresoHtml = `
        <div class="estado-progreso-inner estado-rechazado">
            <span class="material-symbols-rounded">cancel</span>
            Esta preinscripción fue <strong>rechazada</strong>
            ${p.motivo_rechazo ? `<span class="progreso-motivo">${p.motivo_rechazo}</span>` : ''}
        </div>`;
    } else {
        const pasosHtml = pasos.map((paso, i) => {
            // Un paso está "hecho" si su índice es MENOR que la posición actual
            const hecho  = i < posActual;
            // Un paso es el "actual" si su índice IGUALA la posición actual
            const activo = i === posActual;

            let cls;
            if (hecho) {
                // Pasos ya superados → siempre verde
                cls = 'paso-hecho';
            } else if (activo) {
                // Paso actual:
                // - Si es 'matriculado' (posición 2, el último) → verde completo
                // - Si es cualquier otro estado activo → azul en curso
                cls = (p.estado === 'matriculado') ? 'paso-completo' : 'paso-activo';
            } else {
                // Pasos futuros → gris pendiente
                cls = 'paso-pendiente';
            }

            // Icono: pasos ya hechos o el matriculado completado muestran check
            const iconoActual = (hecho || p.estado === 'matriculado') ? 'check' : paso.icon;

            return `
            <div class="progreso-paso ${cls}">
                <div class="progreso-circulo">
                    <span class="material-symbols-rounded">${iconoActual}</span>
                </div>
                <span class="progreso-lbl">${paso.lbl}</span>
            </div>
            ${i < pasos.length - 1
                ? `<div class="progreso-linea ${i < posActual ? 'linea-hecha' : (p.estado === 'matriculado' && i === posActual - 1 ? 'linea-hecha' : '')}"></div>`
                : ''}`;
        }).join('');
        progresoHtml = `<div class="estado-progreso-inner">${pasosHtml}</div>`;
    }
    document.getElementById('estadoProgreso').innerHTML = progresoHtml;

    // ── Contenido detalle ─────────────────────────────────────
    const html = `
    <div class="detalle-grid">

        <div class="detalle-seccion col-full">
            <div class="detalle-avatar">${p.nombres_apellidos.charAt(0).toUpperCase()}</div>
            <div>
                <h3 class="detalle-nombre">${p.nombres_apellidos}</h3>
                <span class="badge ${stCls[p.estado]||'badge--warning'}" style="margin-bottom:4px;display:inline-flex;">
                    ${stLbl[p.estado]||p.estado}
                </span>
                <p class="detalle-sub">${p.email} &bull; ${p.celular||'—'}</p>
            </div>
        </div>

        <div class="detalle-seccion">
            <h4 class="det-title"><span class="material-symbols-rounded">music_note</span>Programa</h4>
            <div class="det-row"><span>Programa:</span>${val(p.programa)}</div>
            <div class="det-row"><span>Taller:</span>${val(p.taller)}</div>
            <div class="det-row"><span>Día clase:</span>${val(p.dia_clase)}</div>
            <div class="det-row"><span>Hora:</span>${val(p.hora_clase)}</div>
            <div class="det-row"><span>Inicio:</span>${fmt(p.fecha_inicio)}</div>
        </div>

        <div class="detalle-seccion">
            <h4 class="det-title"><span class="material-symbols-rounded">badge</span>Identificación</h4>
            <div class="det-row"><span>Tipo doc.:</span>${val(p.tipo_documento)}</div>
            <div class="det-row"><span>Número:</span>${val(p.numero_documento)}</div>
            <div class="det-row"><span>Nacimiento:</span>${fmt(p.fecha_nacimiento)}</div>
            <div class="det-row"><span>Edad:</span>${val(p.edad)}</div>
            <div class="det-row"><span>Lugar nac.:</span>${val(p.lugar_nacimiento)}</div>
        </div>

        <div class="detalle-seccion">
            <h4 class="det-title"><span class="material-symbols-rounded">location_on</span>Ubicación</h4>
            <div class="det-row"><span>Dirección:</span>${val(p.direccion)}</div>
            <div class="det-row"><span>Barrio:</span>${val(p.barrio)}</div>
            <div class="det-row"><span>Municipio:</span>${val(p.municipio)}</div>
            <div class="det-row"><span>Zona:</span>${val(p.zona)}</div>
            <div class="det-row"><span>Estrato:</span>${val(p.estrato)}</div>
        </div>

        <div class="detalle-seccion">
            <h4 class="det-title"><span class="material-symbols-rounded">local_hospital</span>Salud y Socioecon.</h4>
            <div class="det-row"><span>EPS:</span>${val(p.eps)}</div>
            <div class="det-row"><span>SISBEN:</span>${val(p.nivel_sisben)}</div>
            <div class="det-row"><span>Ocupación:</span>${val(p.ocupacion)}</div>
            <div class="det-row"><span>Institución:</span>${val(p.institucion_educativa)}</div>
        </div>

        <div class="detalle-seccion">
            <h4 class="det-title"><span class="material-symbols-rounded">family_restroom</span>Acudiente</h4>
            <div class="det-row"><span>Nombre:</span>${val(p.nombre_acudiente)}</div>
            <div class="det-row"><span>Parentesco:</span>${val(p.parentesco_acudiente)}</div>
            <div class="det-row"><span>Teléfono:</span>${val(p.telefono_acudiente)}</div>
            <div class="det-row"><span>Email:</span>${val(p.email_acudiente)}</div>
            <div class="det-row"><span>N° Recibo:</span>${val(p.numero_recibo)}</div>
        </div>

        <div class="detalle-seccion">
            <h4 class="det-title"><span class="material-symbols-rounded">photo_camera</span>Autorización Imagen</h4>
            <div class="det-row">
                <span>Autoriza:</span>
                ${p.autoriza_imagen == 1
                    ? '<span class="text-success">Sí autoriza</span>'
                    : '<span class="text-danger">No autoriza</span>'}
            </div>
            <div class="det-row"><span>CC acudiente:</span>${val(p.firma_acudiente_cc)}</div>
        </div>

        ${p.observaciones ? `
        <div class="detalle-seccion col-full">
            <h4 class="det-title"><span class="material-symbols-rounded">notes</span>Observaciones</h4>
            <p class="det-observaciones">${p.observaciones}</p>
        </div>` : ''}

        ${p.motivo_rechazo ? `
        <div class="detalle-seccion col-full detalle-seccion--danger">
            <h4 class="det-title"><span class="material-symbols-rounded">report</span>Motivo de Rechazo</h4>
            <p class="det-observaciones">${p.motivo_rechazo}</p>
        </div>` : ''}

    </div>`;

    document.getElementById('detalleContent').innerHTML = html;

    // Footer con acciones según estado
    const footer = document.getElementById('detalleFooter');
    const n = p.nombres_apellidos.replace(/'/g, "\\'");

    if (p.estado === 'pendiente') {
        footer.innerHTML = `
            <button class="btn-secondary" onclick="cerrarModal('modalDetalle')">Cerrar</button>
            <button class="btn-icon btn-icon--view" style="padding:9px 16px; border-radius:10px;"
                    onclick="submitAccion(${p.id}, 'contactar')">
                <span class="material-symbols-rounded">contact_phone</span> Marcar contactado
            </button>
            <button class="btn-danger"
                    onclick="cerrarModal('modalDetalle');abrirModalRechazar(${p.id},'${n}')">
                <span class="material-symbols-rounded">cancel</span> Rechazar
            </button>
            <button class="btn-success"
                    onclick="cerrarModal('modalDetalle');confirmarAprobar(${p.id},'${n}')">
                <span class="material-symbols-rounded">check_circle</span> Aprobar y matricular
            </button>`;
    } else if (p.estado === 'contactado') {
        footer.innerHTML = `
            <button class="btn-secondary" onclick="cerrarModal('modalDetalle')">Cerrar</button>
            <button class="btn-danger"
                    onclick="cerrarModal('modalDetalle');abrirModalRechazar(${p.id},'${n}')">
                <span class="material-symbols-rounded">cancel</span> Rechazar
            </button>
            <button class="btn-success"
                    onclick="cerrarModal('modalDetalle');confirmarAprobar(${p.id},'${n}')">
                <span class="material-symbols-rounded">check_circle</span> Aprobar y matricular
            </button>`;
    } else {
        footer.innerHTML = `
            <button class="btn-secondary" onclick="cerrarModal('modalDetalle')">Cerrar</button>`;
    }

    abrirModal('modalDetalle');
}

// ── Enviar acción directa (contactar) ────────────────────────
function submitAccion(id, accion) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.style.display = 'none';
    form.innerHTML = `<input name="accion" value="${accion}"><input name="id" value="${id}">`;
    document.body.appendChild(form);
    cerrarModal('modalDetalle');
    form.submit();
}

// ── Calcular edad automáticamente ────────────────────────────
function calcularEdad() {
    const fnac = document.getElementById('inputFechaNac').value;
    if (!fnac) return;
    const hoy  = new Date();
    const nac  = new Date(fnac);
    let edad   = hoy.getFullYear() - nac.getFullYear();
    const m    = hoy.getMonth() - nac.getMonth();
    if (m < 0 || (m === 0 && hoy.getDate() < nac.getDate())) edad--;
    document.getElementById('inputEdad').value = edad > 0 ? edad : '';
}

// ── Validar formulario crear ──────────────────────────────────
function validarFormCrear() {
    const doc = document.getElementById('inputDoc')?.value.trim() ?? '';
    if (doc.length < 5) {
        alert('El número de documento debe tener al menos 5 caracteres.');
        return false;
    }
    return true;
}

// ── Dropdown de filtro ────────────────────────────────────────
function toggleFdd() {
    const trigger = document.getElementById('fddTrigger');
    const menu    = document.getElementById('fddMenu');
    const isOpen  = menu.classList.contains('fdd-menu--open');
    trigger.classList.toggle('fdd-open', !isOpen);
    menu.classList.toggle('fdd-menu--open', !isOpen);
}
document.addEventListener('click', e => {
    const wrap = document.getElementById('fddWrap');
    if (wrap && !wrap.contains(e.target)) {
        document.getElementById('fddTrigger')?.classList.remove('fdd-open');
        document.getElementById('fddMenu')?.classList.remove('fdd-menu--open');
    }
});

// Auto-ocultar alerta tras 7 s
setTimeout(() => {
    const a = document.getElementById('alertMsg');
    if (a) {
        a.style.transition = 'opacity .5s';
        a.style.opacity    = '0';
        setTimeout(() => a.remove(), 500);
    }
}, 7000);
</script>

</body>
</html>