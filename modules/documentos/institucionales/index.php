<?php
/**
 * Archivos Institucionales
 * Documentos de interés para la comunidad educativa
 * Visibilidad estricta por rol:
 *   - Admin     → todo
 *   - Profesor  → bitácoras propias + comunicados (todos|profesores) + actas (admin_profesores|todos) + sus certificados generados
 *   - Estudiante→ bitácoras de sus grupos + comunicados (todos|estudiantes) + actas (todos) + sus propios certificados
 */

require_once '../../../config/session.php';
require_once '../../../config/database.php';
require_once '../../../includes/auth_check.php';

// ── Datos del usuario actual ──────────────────────────────────────────────────
try {
    $stmt = $pdo->prepare("
        SELECT id, nombre, email, rol, estado, foto_perfil
        FROM usuarios
        WHERE id = ? AND estado = 'activo'
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        session_destroy();
        header("Location: ../../../auth/login.php?error=usuario_no_encontrado");
        exit;
    }
} catch (PDOException $e) {
    error_log("Error al obtener datos de usuario: " . $e->getMessage());
    die("Error del sistema. Por favor, intenta más tarde.");
}

$uid  = (int)$user['id'];
$rol  = $user['rol'];

// ── Alerta de éxito ───────────────────────────────────────────────────────────
$success_messages = [
    'certificado_creado'      => 'Certificado creado correctamente',
    'certificado_actualizado' => 'Certificado actualizado correctamente',
    'comunicado_creado'       => 'Comunicado creado correctamente',
    'acta_creada'             => 'Acta creada correctamente',
    'comunicado_actualizado'  => 'Comunicado actualizado correctamente',
    'acta_actualizada'        => 'Acta actualizada correctamente',
    'certificado_eliminado'   => 'Certificado eliminado correctamente',
    'comunicado_eliminado'    => 'Comunicado eliminado correctamente',
    'acta_eliminada'          => 'Acta eliminada correctamente',
    'bitacora_creada'         => 'Bitácora creada correctamente',
    'bitacora_actualizada'    => 'Bitácora actualizada correctamente',
    'bitacora_eliminada'      => 'Bitácora eliminada correctamente',
];
$success_key = $_GET['success'] ?? '';
$success_msg = $success_messages[$success_key] ?? '';

// ── Cursos para el modal de crear certificado ─────────────────────────────────
try {
    $stmt_cursos = $pdo->query("SELECT id, nombre FROM cursos WHERE estado = 'activo' ORDER BY nombre");
    $cursos_list = $stmt_cursos->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $cursos_list = [];
}

// ── Grupos para el modal de bitácoras ─────────────────────────────────────────
try {
    if ($rol === 'profesor') {
        $stmt_grupos_bit = $pdo->prepare("
            SELECT g.id, g.nombre, c.nombre as curso_nombre
            FROM grupos g
            INNER JOIN cursos c ON g.curso_id = c.id
            WHERE g.profesor_id = ? AND g.estado = 'activo'
            ORDER BY c.nombre, g.nombre
        ");
        $stmt_grupos_bit->execute([$uid]);
    } elseif ($rol === 'admin') {
        $stmt_grupos_bit = $pdo->query("
            SELECT g.id, g.nombre, c.nombre as curso_nombre
            FROM grupos g
            INNER JOIN cursos c ON g.curso_id = c.id
            WHERE g.estado = 'activo'
            ORDER BY c.nombre, g.nombre
        ");
    } else {
        $stmt_grupos_bit = null;
    }
    $grupos_bit = $stmt_grupos_bit ? $stmt_grupos_bit->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (PDOException $e) {
    $grupos_bit = [];
}

// ── Filtros de búsqueda ───────────────────────────────────────────────────────
$search           = isset($_GET['search'])    ? trim($_GET['search'])    : '';
$tipo_filter      = isset($_GET['tipo'])      ? $_GET['tipo']            : 'todos';

// ── Helper: formatear fecha ───────────────────────────────────────────────────
function formatear_fecha($fecha) {
    $ts    = strtotime($fecha);
    $meses = ['', 'Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
    return date('d', $ts) . ' ' . $meses[(int)date('n', $ts)] . ' ' . date('Y', $ts);
}

// ══════════════════════════════════════════════════════════════════════════════
// BITÁCORAS
// ══════════════════════════════════════════════════════════════════════════════
try {
    if ($rol === 'admin') {
        // Admin ve todas las bitácoras activas
        $sql = "
            SELECT b.*,
                   c.nombre  AS curso_nombre,
                   u.nombre  AS profesor_nombre,
                   (SELECT COUNT(*) FROM bitacoras_asistencias WHERE bitacora_id = b.id) AS total_asistencias,
                   (SELECT COUNT(*) FROM bitacoras_evidencias   WHERE bitacora_id = b.id) AS total_evidencias
            FROM bitacoras b
            INNER JOIN cursos   c ON b.curso_id    = c.id
            INNER JOIN usuarios u ON b.profesor_id = u.id
            WHERE b.estado = 'activo'
        ";
        if ($search !== '') $sql .= " AND (b.titulo LIKE :search OR b.temas_tratados LIKE :search)";
        $sql .= " ORDER BY b.fecha_clase DESC";

        $stmt = $pdo->prepare($sql);
        if ($search !== '') $stmt->bindValue(':search', "%$search%", PDO::PARAM_STR);
        $stmt->execute();

    } elseif ($rol === 'profesor') {
        // Profesor solo ve las bitácoras de los grupos que imparte
        $sql = "
            SELECT b.*,
                   c.nombre  AS curso_nombre,
                   u.nombre  AS profesor_nombre,
                   (SELECT COUNT(*) FROM bitacoras_asistencias WHERE bitacora_id = b.id) AS total_asistencias,
                   (SELECT COUNT(*) FROM bitacoras_evidencias   WHERE bitacora_id = b.id) AS total_evidencias
            FROM bitacoras b
            INNER JOIN cursos   c ON b.curso_id    = c.id
            INNER JOIN usuarios u ON b.profesor_id = u.id
            WHERE b.estado = 'activo'
              AND b.profesor_id = :uid
        ";
        if ($search !== '') $sql .= " AND (b.titulo LIKE :search OR b.temas_tratados LIKE :search)";
        $sql .= " ORDER BY b.fecha_clase DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':uid', $uid, PDO::PARAM_INT);
        if ($search !== '') $stmt->bindValue(':search', "%$search%", PDO::PARAM_STR);
        $stmt->execute();

    } else {
        // Estudiante: solo bitácoras de los grupos en que está matriculado (activo)
        $sql = "
            SELECT b.*,
                   c.nombre  AS curso_nombre,
                   u.nombre  AS profesor_nombre,
                   (SELECT COUNT(*) FROM bitacoras_asistencias WHERE bitacora_id = b.id) AS total_asistencias,
                   (SELECT COUNT(*) FROM bitacoras_evidencias   WHERE bitacora_id = b.id) AS total_evidencias
            FROM bitacoras b
            INNER JOIN cursos    c  ON b.curso_id    = c.id
            INNER JOIN usuarios  u  ON b.profesor_id = u.id
            INNER JOIN matriculas m ON m.grupo_id    = b.grupo_id
            WHERE b.estado   = 'activo'
              AND m.estudiante_id = :uid
              AND m.estado        = 'activa'
        ";
        if ($search !== '') $sql .= " AND (b.titulo LIKE :search OR b.temas_tratados LIKE :search)";
        $sql .= " ORDER BY b.fecha_clase DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':uid', $uid, PDO::PARAM_INT);
        if ($search !== '') $stmt->bindValue(':search', "%$search%", PDO::PARAM_STR);
        $stmt->execute();
    }

    $bitacoras = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Error bitácoras: " . $e->getMessage());
    $bitacoras = [];
}

// ══════════════════════════════════════════════════════════════════════════════
// CERTIFICADOS
// ══════════════════════════════════════════════════════════════════════════════
try {
    if ($rol === 'admin') {
        $stmt = $pdo->query("
            SELECT cc.*, e.nombre AS estudiante_nombre, c.nombre AS curso_nombre
            FROM calificaciones_certificados cc
            INNER JOIN usuarios e ON cc.estudiante_id = e.id
            INNER JOIN cursos   c ON cc.curso_id      = c.id
            WHERE cc.estado = 'aprobado'
            ORDER BY cc.fecha_aprobacion DESC
        ");
    } elseif ($rol === 'profesor') {
        // Profesor ve los certificados de los estudiantes de SUS grupos
        $stmt = $pdo->prepare("
            SELECT cc.*, e.nombre AS estudiante_nombre, c.nombre AS curso_nombre
            FROM calificaciones_certificados cc
            INNER JOIN usuarios e ON cc.estudiante_id = e.id
            INNER JOIN cursos   c ON cc.curso_id      = c.id
            INNER JOIN grupos   g ON cc.grupo_id      = g.id
            WHERE cc.estado    = 'aprobado'
              AND g.profesor_id = :uid
            ORDER BY cc.fecha_aprobacion DESC
        ");
        $stmt->bindValue(':uid', $uid, PDO::PARAM_INT);
        $stmt->execute();
    } else {
        // Estudiante solo ve los suyos
        $stmt = $pdo->prepare("
            SELECT cc.*, e.nombre AS estudiante_nombre, c.nombre AS curso_nombre
            FROM calificaciones_certificados cc
            INNER JOIN usuarios e ON cc.estudiante_id = e.id
            INNER JOIN cursos   c ON cc.curso_id      = c.id
            WHERE cc.estado        = 'aprobado'
              AND cc.estudiante_id  = :uid
            ORDER BY cc.fecha_aprobacion DESC
        ");
        $stmt->bindValue(':uid', $uid, PDO::PARAM_INT);
        $stmt->execute();
    }

    if ($rol === 'admin') $stmt->execute();   // query() no necesita execute, pero las demás sí
    $certificados = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Error certificados: " . $e->getMessage());
    $certificados = [];
}

// ══════════════════════════════════════════════════════════════════════════════
// COMUNICADOS  (dirigido_a: 'todos' | 'profesores' | 'estudiantes')
// ══════════════════════════════════════════════════════════════════════════════
try {
    if ($rol === 'admin') {
        $stmt = $pdo->query("
            SELECT dc.*, u.nombre AS autor_nombre
            FROM documentos_comunicados dc
            INNER JOIN usuarios u ON dc.publicado_por = u.id
            WHERE dc.estado = 'activo'
            ORDER BY dc.fecha_publicacion DESC
        ");
        $stmt->execute();
    } elseif ($rol === 'profesor') {
        $stmt = $pdo->query("
            SELECT dc.*, u.nombre AS autor_nombre
            FROM documentos_comunicados dc
            INNER JOIN usuarios u ON dc.publicado_por = u.id
            WHERE dc.estado    = 'activo'
              AND dc.dirigido_a IN ('todos', 'profesores')
            ORDER BY dc.fecha_publicacion DESC
        ");
        $stmt->execute();
    } else {
        // Estudiante
        $stmt = $pdo->query("
            SELECT dc.*, u.nombre AS autor_nombre
            FROM documentos_comunicados dc
            INNER JOIN usuarios u ON dc.publicado_por = u.id
            WHERE dc.estado    = 'activo'
              AND dc.dirigido_a IN ('todos', 'estudiantes')
            ORDER BY dc.fecha_publicacion DESC
        ");
        $stmt->execute();
    }
    $comunicados = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Error comunicados: " . $e->getMessage());
    $comunicados = [];
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTAS  (visibilidad: 'solo_admin' | 'admin_profesores' | 'todos')
// ══════════════════════════════════════════════════════════════════════════════
try {
    if ($rol === 'admin') {
        $stmt = $pdo->query("
            SELECT da.*, u.nombre AS autor_nombre
            FROM documentos_actas da
            INNER JOIN usuarios u ON da.creado_por = u.id
            WHERE da.estado = 'activo'
            ORDER BY da.fecha_reunion DESC
        ");
        $stmt->execute();
    } elseif ($rol === 'profesor') {
        $stmt = $pdo->query("
            SELECT da.*, u.nombre AS autor_nombre
            FROM documentos_actas da
            INNER JOIN usuarios u ON da.creado_por = u.id
            WHERE da.estado      = 'activo'
              AND da.visibilidad IN ('admin_profesores', 'todos')
            ORDER BY da.fecha_reunion DESC
        ");
        $stmt->execute();
    } else {
        // Estudiante
        $stmt = $pdo->query("
            SELECT da.*, u.nombre AS autor_nombre
            FROM documentos_actas da
            INNER JOIN usuarios u ON da.creado_por = u.id
            WHERE da.estado     = 'activo'
              AND da.visibilidad = 'todos'
            ORDER BY da.fecha_reunion DESC
        ");
        $stmt->execute();
    }
    $actas = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Error actas: " . $e->getMessage());
    $actas = [];
}

// ══════════════════════════════════════════════════════════════════════════════
// ESTADÍSTICAS
// ══════════════════════════════════════════════════════════════════════════════
$total_bitacoras   = count($bitacoras);
$total_certificados = count($certificados);
$total_comunicados = count($comunicados);
$total_actas       = count($actas);
$total_archivos    = $total_bitacoras + $total_certificados + $total_comunicados + $total_actas;

// ══════════════════════════════════════════════════════════════════════════════
// COMBINAR Y ORDENAR DOCUMENTOS
// ══════════════════════════════════════════════════════════════════════════════
$documentos = [];

if ($tipo_filter === 'todos' || $tipo_filter === 'bitacora') {
    foreach ($bitacoras as $b) {
        $documentos[] = [
            'tipo'        => 'bitacora',
            'id'          => $b['id'],
            'titulo'      => $b['titulo'],
            'descripcion' => $b['temas_tratados'] ?? '',
            'categoria'   => 'Bitácora',
            'badge_class' => 'bitacora',
            'fecha'       => $b['fecha_clase'],
            'autor'       => $b['profesor_nombre'],
            'profesor_id' => $b['profesor_id'],
            'metadata'    => [
                'curso'       => $b['curso_nombre'],
                'asistencias' => $b['total_asistencias'],
                'evidencias'  => $b['total_evidencias'],
            ],
            'tags' => array_filter(array_map('trim', explode(',', $b['temas_tratados'] ?? ''))),
        ];
    }
}

if ($tipo_filter === 'todos' || $tipo_filter === 'certificado') {
    foreach ($certificados as $cert) {
        $documentos[] = [
            'tipo'        => 'certificado',
            'id'          => $cert['id'],
            'titulo'      => 'Certificado – ' . $cert['curso_nombre'],
            'descripcion' => $cert['estudiante_nombre'],
            'categoria'   => 'Certificado',
            'badge_class' => 'certificado',
            'fecha'       => $cert['fecha_aprobacion'],
            'autor'       => $cert['estudiante_nombre'],
            'metadata'    => [
                'nivel'        => ucfirst($cert['nivel_aprobado']),
                'calificacion' => $cert['calificacion_final'],
                'codigo'       => $cert['codigo_certificado'],
            ],
            'tags' => [],
        ];
    }
}

if ($tipo_filter === 'todos' || $tipo_filter === 'comunicado') {
    foreach ($comunicados as $com) {
        $documentos[] = [
            'tipo'        => 'comunicado',
            'id'          => $com['id'],
            'titulo'      => $com['titulo'],
            'descripcion' => $com['descripcion'] ?? '',
            'categoria'   => 'Comunicado',
            'badge_class' => 'comunicado',
            'fecha'       => $com['fecha_publicacion'],
            'autor'       => $com['autor_nombre'],
            'metadata'    => [
                'categoria'   => ucfirst($com['categoria']),
                'prioridad'   => ucfirst($com['prioridad']),
                'dirigido_a'  => ucfirst($com['dirigido_a']),
            ],
            'tags' => [],
        ];
    }
}

if ($tipo_filter === 'todos' || $tipo_filter === 'acta') {
    foreach ($actas as $acta) {
        $documentos[] = [
            'tipo'        => 'acta',
            'id'          => $acta['id'],
            'titulo'      => $acta['titulo'],
            'descripcion' => $acta['descripcion'] ?? '',
            'categoria'   => 'Acta',
            'badge_class' => 'acta',
            'fecha'       => $acta['fecha_reunion'],
            'autor'       => $acta['autor_nombre'],
            'metadata'    => [
                'tipo_reunion' => ucfirst(str_replace('_', ' ', $acta['tipo_reunion'] ?? '')),
                'lugar'        => $acta['lugar'] ?? '—',
            ],
            'tags' => [],
        ];
    }
}

// Ordenar por fecha descendente
usort($documentos, fn($a, $b) => strtotime($b['fecha']) - strtotime($a['fecha']));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Archivos Institucionales – Amimbré</title>
    <link rel="shortcut icon" href="../../../assets/img/3.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="../../../assets/css/colores.css">
    <link rel="stylesheet" href="../../../assets/css/style-documentos-institucionales.css">
    <link rel="stylesheet" href="../../../assets/css/style-documentos-bitacora.css">
    <script>
        (function () {
            const theme = localStorage.getItem('amimbre-theme');
            if (theme === 'light') document.documentElement.setAttribute('data-theme', 'light');
        })();
    </script>
</head>
<body>
    <?php require_once '../../../includes/header.php'; ?>

    <main class="main-content">

        <?php if ($success_msg): ?>
        <div class="alerta-inst alerta-exito" id="alertaInstPHP">
            <span class="material-symbols-rounded">check_circle</span>
            <?php echo htmlspecialchars($success_msg); ?>
        </div>
        <script>setTimeout(function(){ var a=document.getElementById('alertaInstPHP'); if(a) a.style.display='none'; }, 4000);</script>
        <?php endif; ?>
        <div class="alerta-inst" id="alertaInst" style="display:none;"></div>

        <!-- ── Encabezado ── -->
        <div class="page-header">
            <div class="header-left">
                <button class="btn-back" onclick="window.history.back()">
                    <span class="material-symbols-rounded">arrow_back</span>
                </button>
                <div class="header-info">
                    <h1>Archivos Institucionales</h1>
                    <p>Documentos de interés para la comunidad educativa</p>
                </div>
            </div>
            <div class="header-actions">
                <?php if ($rol === 'admin' || $rol === 'profesor'): ?>
                <button class="btn-secondary" onclick="abrirModalCrearBitacora()">
                    <span class="material-symbols-rounded">menu_book</span>
                    Nueva Bitácora
                </button>
                <?php endif; ?>
                <?php if ($rol === 'admin'): ?>
                <button class="btn-primary" onclick="abrirModalCrearInst()">
                    <span class="material-symbols-rounded">add</span>
                    Nuevo Archivo
                </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── Estadísticas ── -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background:var(--subtle-blue); color:var(--primary-blue);">
                    <span class="material-symbols-rounded">folder_open</span>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Total Archivos</div>
                    <div class="stat-value"><?php echo $total_archivos; ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:var(--subtle-orange); color:var(--primary-orange);">
                    <span class="material-symbols-rounded">book</span>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Bitácoras</div>
                    <div class="stat-value"><?php echo $total_bitacoras; ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:var(--subtle-blue); color:var(--primary-blue);">
                    <span class="material-symbols-rounded">forum</span>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Comunicados</div>
                    <div class="stat-value"><?php echo $total_comunicados; ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:var(--subtle-green); color:var(--primary-green);">
                    <span class="material-symbols-rounded">description</span>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Actas</div>
                    <div class="stat-value"><?php echo $total_actas; ?></div>
                </div>
            </div>
        </div>

        <!-- ── Filtros y búsqueda ── -->
        <div class="filters-container">
            <div class="search-box">
                <span class="material-symbols-rounded">search</span>
                <input type="text" id="searchInput"
                       placeholder="Buscar archivos..."
                       value="<?php echo htmlspecialchars($search); ?>">
            </div>

            <div class="filter-group">
                <select id="tipoFilter" class="filter-select">
                    <option value="todos"       <?php echo $tipo_filter === 'todos'       ? 'selected' : ''; ?>>Todos los tipos</option>
                    <option value="bitacora"    <?php echo $tipo_filter === 'bitacora'    ? 'selected' : ''; ?>>Bitácoras</option>
                    <option value="certificado" <?php echo $tipo_filter === 'certificado' ? 'selected' : ''; ?>>Certificados</option>
                    <option value="comunicado"  <?php echo $tipo_filter === 'comunicado'  ? 'selected' : ''; ?>>Comunicados</option>
                    <option value="acta"        <?php echo $tipo_filter === 'acta'        ? 'selected' : ''; ?>>Actas</option>
                </select>

            </div>

            <div class="view-toggle">
                <button class="view-btn active" data-view="grid">
                    <span class="material-symbols-rounded">grid_view</span>
                </button>
                <button class="view-btn" data-view="list">
                    <span class="material-symbols-rounded">list</span>
                </button>
            </div>
        </div>

        <!-- ── Grid de documentos ── -->
        <div class="documents-grid" id="documentsGrid">
            <?php if (count($documentos) > 0): ?>
                <?php foreach ($documentos as $doc): ?>
                    <div class="document-card">

                        <!-- Header -->
                        <div class="document-header">
                            <div class="document-icon-wrapper">
                                <div class="document-icon <?php echo $doc['badge_class']; ?>">
                                    <span class="material-symbols-rounded">
                                        <?php
                                        $icons = [
                                            'bitacora'    => 'menu_book',
                                            'certificado' => 'workspace_premium',
                                            'comunicado'  => 'campaign',
                                            'acta'        => 'description',
                                        ];
                                        echo $icons[$doc['tipo']] ?? 'draft';
                                        ?>
                                    </span>
                                </div>
                                <span class="badge badge-<?php echo $doc['badge_class']; ?>">
                                    <?php echo $doc['categoria']; ?>
                                </span>
                            </div>
                        </div>

                        <!-- Cuerpo -->
                        <div class="document-body">
                            <h3 class="document-title"><?php echo htmlspecialchars($doc['titulo']); ?></h3>
                            <p class="document-description">
                                <?php
                                $desc = htmlspecialchars($doc['descripcion']);
                                echo mb_strlen($desc) > 100 ? mb_substr($desc, 0, 100) . '…' : $desc;
                                ?>
                            </p>

                            <?php if (!empty($doc['tags'])): ?>
                            <div class="document-tags">
                                <?php foreach (array_slice($doc['tags'], 0, 3) as $tag): ?>
                                    <?php if (!empty($tag)): ?>
                                    <span class="tag"><?php echo htmlspecialchars(mb_substr($tag, 0, 20)); ?></span>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Footer -->
                        <div class="document-footer">
                            <div class="document-meta">
                                <span class="meta-icon">
                                    <span class="material-symbols-rounded">event</span>
                                    <?php echo formatear_fecha($doc['fecha']); ?>
                                </span>
                                <span class="meta-icon">
                                    <span class="material-symbols-rounded">person</span>
                                    <?php echo htmlspecialchars($doc['autor']); ?>
                                </span>
                            </div>
                            <div class="document-actions">
                                <?php if ($doc['tipo'] === 'bitacora'): ?>
                                    <button class="btn-action"
                                            onclick="abrirModalVerBitacora(<?php echo $doc['id']; ?>)">
                                        <span class="material-symbols-rounded">visibility</span> Ver
                                    </button>
                                    <?php if ($rol === 'admin' || ($rol === 'profesor' && isset($doc['profesor_id']) && $doc['profesor_id'] == $uid)): ?>
                                    <button class="btn-action-edit"
                                            onclick="abrirModalEditarBitacora(<?php echo $doc['id']; ?>)"
                                            title="Editar">
                                        <span class="material-symbols-rounded">edit</span>
                                    </button>
                                    <button class="btn-action-danger"
                                            onclick="abrirModalEliminarBitacora(<?php echo $doc['id']; ?>, <?php echo htmlspecialchars(json_encode($doc['titulo']), ENT_QUOTES); ?>)"
                                            title="Eliminar">
                                        <span class="material-symbols-rounded">delete</span>
                                    </button>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <button class="btn-action"
                                            onclick="abrirModalVerInst('<?php echo $doc['tipo']; ?>', <?php echo $doc['id']; ?>)">
                                        <span class="material-symbols-rounded">visibility</span> Ver
                                    </button>
                                    <?php if ($rol === 'admin'): ?>
                                    <button class="btn-action-edit"
                                            onclick="abrirModalEditarInst('<?php echo $doc['tipo']; ?>', <?php echo $doc['id']; ?>)"
                                            title="Editar">
                                        <span class="material-symbols-rounded">edit</span>
                                    </button>
                                    <button class="btn-action-danger"
                                            onclick="abrirModalEliminarInst('<?php echo $doc['tipo']; ?>', <?php echo $doc['id']; ?>, <?php echo htmlspecialchars(json_encode($doc['titulo']), ENT_QUOTES); ?>)">
                                        <span class="material-symbols-rounded">delete</span>
                                    </button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <span class="material-symbols-rounded">folder_off</span>
                    <h3>No hay documentos</h3>
                    <p>No se encontraron documentos disponibles para tu perfil</p>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <?php if ($rol === 'admin'): ?>
    <!-- ── Modal: Crear Archivo Institucional ──────────────────────────────── -->
    <div class="modal" id="modalCrearInst" style="display:none;">
        <div class="modal-content" style="max-width:720px;">
            <div class="modal-header-crear">
                <h2><span class="material-symbols-rounded">add_circle</span> Nuevo Archivo Institucional</h2>
                <button class="modal-close-btn" onclick="cerrarModalCrearInst()">
                    <span class="material-symbols-rounded">close</span>
                </button>
            </div>
            <div class="modal-body-scroll">
                <div class="mci-error" id="mciError">
                    <span class="material-symbols-rounded">error</span>
                    <span id="mciErrorText"></span>
                </div>
                <form id="formCrearInst" enctype="multipart/form-data">

                    <!-- Tipo de documento -->
                    <div class="mci-form-group">
                        <label class="mci-label required">Tipo de Archivo</label>
                        <select name="tipo_documento" id="mciTipo" class="mci-select" onchange="mciCambiarTipo(this.value)">
                            <option value="">Seleccionar tipo</option>
                            <option value="certificado">📜 Certificado de Curso</option>
                            <option value="comunicado">📢 Comunicado Oficial</option>
                            <option value="acta">📋 Acta de Reunión</option>
                        </select>
                    </div>

                    <!-- Información general -->
                    <div class="mci-form-group">
                        <label class="mci-label required">Título</label>
                        <input type="text" name="titulo" class="mci-input" placeholder="Título del documento" required>
                    </div>
                    <div class="mci-form-group">
                        <label class="mci-label">Descripción</label>
                        <textarea name="descripcion" class="mci-textarea" placeholder="Descripción adicional..."></textarea>
                    </div>
                    <div class="mci-form-group">
                        <label class="mci-label required">Fecha del Documento</label>
                        <input type="date" name="fecha_documento" class="mci-input" id="mciFecha">
                    </div>

                    <!-- Campos: CERTIFICADO -->
                    <div class="mci-conditional" id="mciCertificadoFields">
                        <div class="mci-section-title">
                            <span class="material-symbols-rounded">workspace_premium</span> Datos del Certificado
                        </div>
                        <div class="mci-grid">
                            <div class="mci-form-group">
                                <label class="mci-label required">Curso</label>
                                <select name="curso_id" id="mciCursoSelect" class="mci-select" onchange="mciCargarGrupos(this.value)">
                                    <option value="">Seleccionar curso</option>
                                    <?php foreach ($cursos_list as $c): ?>
                                    <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['nombre']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mci-form-group">
                                <label class="mci-label required">Grupo</label>
                                <select name="grupo_id" id="mciGrupoSelect" class="mci-select" onchange="mciCargarEstudiantes(this.value)">
                                    <option value="">Primero selecciona un curso</option>
                                </select>
                            </div>
                        </div>
                        <div class="mci-form-group">
                            <label class="mci-label required">Estudiante</label>
                            <select name="estudiante_id" id="mciEstudianteSelect" class="mci-select">
                                <option value="">Primero selecciona un grupo</option>
                            </select>
                        </div>
                        <div class="mci-grid">
                            <div class="mci-form-group">
                                <label class="mci-label required">Nivel Aprobado</label>
                                <select name="nivel_aprobado" class="mci-select">
                                    <option value="">Seleccionar nivel</option>
                                    <option value="basico">Básico</option>
                                    <option value="intermedio">Intermedio</option>
                                    <option value="avanzado">Avanzado</option>
                                </select>
                            </div>
                            <div class="mci-form-group">
                                <label class="mci-label required">Calificación Final</label>
                                <input type="number" name="calificacion_final" class="mci-input" min="0" max="5" step="0.1" placeholder="Ej: 4.5">
                            </div>
                        </div>
                        <div class="mci-grid">
                            <div class="mci-form-group">
                                <label class="mci-label required">Fecha Inicio Curso</label>
                                <input type="date" name="fecha_inicio_curso" class="mci-input">
                            </div>
                            <div class="mci-form-group">
                                <label class="mci-label required">Fecha Fin Curso</label>
                                <input type="date" name="fecha_fin_curso" class="mci-input">
                            </div>
                        </div>
                    </div>

                    <!-- Campos: COMUNICADO -->
                    <div class="mci-conditional" id="mciComunicadoFields">
                        <div class="mci-section-title">
                            <span class="material-symbols-rounded">campaign</span> Datos del Comunicado
                        </div>
                        <div class="mci-grid">
                            <div class="mci-form-group">
                                <label class="mci-label">Categoría</label>
                                <select name="categoria_comunicado" class="mci-select">
                                    <option value="general">General</option>
                                    <option value="academico">Académico</option>
                                    <option value="administrativo">Administrativo</option>
                                    <option value="evento">Evento</option>
                                    <option value="urgente">Urgente</option>
                                </select>
                            </div>
                            <div class="mci-form-group">
                                <label class="mci-label">Prioridad</label>
                                <select name="prioridad" class="mci-select">
                                    <option value="normal">Normal</option>
                                    <option value="alta">Alta</option>
                                    <option value="urgente">Urgente</option>
                                </select>
                            </div>
                        </div>
                        <div class="mci-form-group">
                            <label class="mci-label">Dirigido a</label>
                            <select name="dirigido_a" class="mci-select">
                                <option value="todos">Todos</option>
                                <option value="estudiantes">Estudiantes</option>
                                <option value="profesores">Profesores</option>
                                <option value="padres">Padres de Familia</option>
                            </select>
                        </div>
                    </div>

                    <!-- Campos: ACTA -->
                    <div class="mci-conditional" id="mciActaFields">
                        <div class="mci-section-title">
                            <span class="material-symbols-rounded">assignment</span> Datos del Acta
                        </div>
                        <div class="mci-grid">
                            <div class="mci-form-group">
                                <label class="mci-label required">Tipo de Reunión</label>
                                <select name="tipo_reunion" class="mci-select">
                                    <option value="">Seleccionar tipo</option>
                                    <option value="consejo_academico">Consejo Académico</option>
                                    <option value="reunion_docentes">Reunión de Docentes</option>
                                    <option value="reunion_padres">Reunión de Padres</option>
                                    <option value="comite_directivo">Comité Directivo</option>
                                    <option value="otra">Otra</option>
                                </select>
                            </div>
                            <div class="mci-form-group">
                                <label class="mci-label">Lugar</label>
                                <input type="text" name="lugar" class="mci-input" placeholder="Ej: Sala de reuniones">
                            </div>
                        </div>
                        <div class="mci-form-group">
                            <label class="mci-label">Asistentes</label>
                            <textarea name="asistentes" class="mci-textarea" placeholder="Lista de asistentes..."></textarea>
                        </div>
                        <div class="mci-form-group">
                            <label class="mci-label required">Visibilidad del Acta</label>
                            <select name="visibilidad_acta" class="mci-select">
                                <option value="solo_admin">Solo Administradores</option>
                                <option value="admin_profesores">Administradores y Profesores</option>
                                <option value="todos">Todos</option>
                            </select>
                        </div>
                    </div>

                    <!-- Archivo -->
                    <div class="mci-section-title">
                        <span class="material-symbols-rounded">upload_file</span> Archivo del Documento
                    </div>
                    <div class="mci-file-zone" id="mciFileZone">
                        <input type="file" name="archivo" id="mciArchivo" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                        <span class="material-symbols-rounded" style="font-size:40px;color:var(--primary-blue);">cloud_upload</span>
                        <div style="color:var(--text-primary);font-size:.9rem;margin-top:8px;">Arrastra el archivo o haz clic</div>
                        <div style="color:var(--text-secondary);font-size:.8rem;">PDF, Word o Imágenes (máx. 10MB)</div>
                        <div id="mciFileName" style="color:var(--primary-green);margin-top:8px;display:none;"></div>
                    </div>

                </form>
            </div>
            <div class="modal-footer-crear">
                <button class="btn-cancelar" onclick="cerrarModalCrearInst()">Cancelar</button>
                <button class="btn-primario" id="mciSubmitBtn" onclick="submitCrearInst()">
                    <span class="material-symbols-rounded">save</span> Guardar
                </button>
            </div>
        </div>
    </div>

    <!-- ── Modal: Editar Documento ─────────────────────────────────────────── -->
    <div class="modal" id="modalEditarInst" style="display:none;">
        <div class="modal-content" style="max-width:720px;">
            <div class="modal-header-crear">
                <h2 id="meiEditTitulo"><span class="material-symbols-rounded">edit</span> Editar Documento</h2>
                <button class="modal-close-btn" onclick="cerrarModalEditarInst()">
                    <span class="material-symbols-rounded">close</span>
                </button>
            </div>
            <div class="modal-body-scroll" id="meiEditBody">
                <div style="text-align:center;padding:40px;">
                    <span class="material-symbols-rounded" style="font-size:40px;color:var(--text-secondary);">hourglass_empty</span>
                </div>
            </div>
            <div class="modal-footer-crear">
                <button class="btn-cancelar" onclick="cerrarModalEditarInst()">Cancelar</button>
                <button class="btn-primario" id="meiEditSubmitBtn" onclick="submitEditarInst()" disabled>
                    <span class="material-symbols-rounded">save</span> Guardar
                </button>
            </div>
        </div>
    </div>

    <!-- ── Modal: Eliminar Documento ───────────────────────────────────────── -->
    <div class="modal" id="modalEliminarInst" style="display:none;">
        <div class="modal-content" style="max-width:440px;">
            <div class="modal-header-crear">
                <h2><span class="material-symbols-rounded">delete</span> Eliminar Documento</h2>
                <button class="modal-close-btn" onclick="cerrarModalEliminarInst()">
                    <span class="material-symbols-rounded">close</span>
                </button>
            </div>
            <div class="modal-body-scroll">
                <p style="color:var(--text-secondary);line-height:1.6;">
                    ¿Estás seguro de que deseas eliminar
                    <strong style="color:var(--text-primary);" id="meiTitulo"></strong>?
                    Esta acción no se puede deshacer.
                </p>
                <input type="hidden" id="meiTipo">
                <input type="hidden" id="meiId">
            </div>
            <div class="modal-footer-crear">
                <button class="btn-cancelar" onclick="cerrarModalEliminarInst()">Cancelar</button>
                <button class="btn-danger" onclick="submitEliminarInst()">
                    <span class="material-symbols-rounded">delete</span> Eliminar
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($rol === 'admin' || $rol === 'profesor'): ?>
    <!-- ── Modal: Crear Bitácora ──────────────────────────────────────────── -->
    <div class="modal" id="modalCrearBitacora" style="display:none;">
        <div class="modal-content" style="max-width:720px;">
            <div class="modal-header-crear">
                <h2><span class="material-symbols-rounded">menu_book</span> Nueva Bitácora</h2>
                <button class="modal-close-btn" onclick="cerrarModalCrearBitacora()">
                    <span class="material-symbols-rounded">close</span>
                </button>
            </div>
            <div class="modal-body-scroll">
                <div class="mci-error" id="mcrBitFormError" style="display:none;"></div>
                <form id="formCrearBitacora" enctype="multipart/form-data">
                    <div class="mci-grid">
                        <div class="mci-form-group">
                            <label class="mci-label required">Grupo</label>
                            <select name="grupo_id" id="mcrBitGrupoSelect" class="mci-select" onchange="mcrBitCargarEstudiantes(this.value)">
                                <option value="">Seleccionar grupo</option>
                                <?php foreach ($grupos_bit as $g): ?>
                                <option value="<?php echo $g['id']; ?>"><?php echo htmlspecialchars($g['curso_nombre'] . ' - ' . $g['nombre']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mci-form-group">
                            <label class="mci-label required">Fecha de Clase</label>
                            <input type="date" name="fecha_clase" id="mcrBitFecha" class="mci-input" required>
                        </div>
                    </div>
                    <div class="mci-form-group">
                        <label class="mci-label required">Título de la Clase</label>
                        <input type="text" name="titulo" class="mci-input" placeholder="Ej: Introducción a escalas mayores" required>
                    </div>
                    <div class="mci-grid">
                        <div class="mci-form-group">
                            <label class="mci-label required">Hora Inicio</label>
                            <input type="time" name="hora_inicio" class="mci-input" required>
                        </div>
                        <div class="mci-form-group">
                            <label class="mci-label required">Hora Fin</label>
                            <input type="time" name="hora_fin" class="mci-input" required>
                        </div>
                    </div>
                    <div class="mci-form-group">
                        <label class="mci-label required">Temas Tratados</label>
                        <input type="text" name="temas_tratados" class="mci-input" placeholder="Ej: escalas, arpegios, lectura" required>
                        <small style="color:var(--text-secondary);font-size:.8rem;">Separa los temas con comas</small>
                    </div>
                    <div class="mci-form-group">
                        <label class="mci-label required">Descripción de la Clase</label>
                        <textarea name="descripcion_clase" class="mci-textarea" placeholder="Describe detalladamente lo que se trabajó en la clase..." required></textarea>
                    </div>
                    <div class="mci-form-group">
                        <label class="mci-label">Observaciones</label>
                        <textarea name="observaciones" class="mci-textarea" placeholder="Observaciones generales sobre la clase..."></textarea>
                    </div>
                    <div class="mci-form-group">
                        <label class="mci-label">Compromisos para la Próxima Clase</label>
                        <textarea name="compromisos" class="mci-textarea" placeholder="Tareas o temas para la próxima sesión..."></textarea>
                    </div>
                    <div class="mci-section-title">
                        <span class="material-symbols-rounded">how_to_reg</span> Registro de Asistencia
                    </div>
                    <div id="mcrBitEstContainer">
                        <p style="color:var(--text-secondary);font-size:.85rem;">Selecciona un grupo para ver los estudiantes</p>
                    </div>
                    <div class="mci-section-title" style="margin-top:20px;">
                        <span class="material-symbols-rounded">add_photo_alternate</span> Evidencias Fotográficas (Opcional)
                    </div>
                    <div class="bit-upload-zone" id="mcrBitUploadZone">
                        <input type="file" name="evidencias[]" id="mcrBitEvidenciasInput" accept="image/*" multiple>
                        <span class="material-symbols-rounded" style="font-size:40px;color:var(--primary-blue);">add_photo_alternate</span>
                        <div style="color:var(--text-primary);font-size:.9rem;margin-top:8px;">Arrastra fotos aquí o haz clic</div>
                        <div style="color:var(--text-secondary);font-size:.8rem;">JPG, PNG, GIF (máx. 5MB cada una)</div>
                    </div>
                    <div id="mcrBitFilesList"></div>
                </form>
            </div>
            <div class="modal-footer-crear">
                <button class="btn-cancelar" onclick="cerrarModalCrearBitacora()">Cancelar</button>
                <button class="btn-primario" id="mcrBitSubmitBtn" onclick="submitCrearBitacora()">
                    <span class="material-symbols-rounded">save</span> Guardar
                </button>
            </div>
        </div>
    </div>

    <!-- ── Modal: Editar Bitácora ──────────────────────────────────────────── -->
    <div class="modal" id="modalEditarBitacora" style="display:none;">
        <div class="modal-content" style="max-width:720px;">
            <div class="modal-header-crear">
                <h2><span class="material-symbols-rounded">edit</span> Editar Bitácora</h2>
                <button class="modal-close-btn" onclick="cerrarModalEditarBitacora()">
                    <span class="material-symbols-rounded">close</span>
                </button>
            </div>
            <div class="modal-body-scroll" id="mebBitBody">
                <div style="text-align:center;padding:40px;">
                    <span class="material-symbols-rounded" style="font-size:40px;color:var(--text-secondary);">hourglass_empty</span>
                </div>
            </div>
            <div class="modal-footer-crear">
                <button class="btn-cancelar" onclick="cerrarModalEditarBitacora()">Cancelar</button>
                <button class="btn-primario" id="mebBitSubmitBtn" onclick="submitEditarBitacora()" disabled>
                    <span class="material-symbols-rounded">save</span> Guardar
                </button>
            </div>
        </div>
    </div>

    <!-- ── Modal: Eliminar Bitácora ──────────────────────────────────────────── -->
    <div class="modal" id="modalEliminarBitacora" style="display:none;">
        <div class="modal-content" style="max-width:440px;">
            <div class="modal-header-crear">
                <h2><span class="material-symbols-rounded">delete</span> Eliminar Bitácora</h2>
                <button class="modal-close-btn" onclick="cerrarModalEliminarBitacora()">
                    <span class="material-symbols-rounded">close</span>
                </button>
            </div>
            <div class="modal-body-scroll">
                <p style="color:var(--text-secondary);line-height:1.6;">
                    ¿Estás seguro de que deseas eliminar la bitácora
                    <strong style="color:var(--text-primary);" id="melbTitulo"></strong>?
                    Esta acción no se puede deshacer.
                </p>
                <input type="hidden" id="melbId">
            </div>
            <div class="modal-footer-crear">
                <button class="btn-cancelar" onclick="cerrarModalEliminarBitacora()">Cancelar</button>
                <button class="btn-danger" id="melbConfirmBtn" onclick="submitEliminarBitacora()">
                    <span class="material-symbols-rounded">delete</span> Eliminar
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Modal: Ver Bitácora ───────────────────────────────────────────────── -->
    <div class="modal" id="modalVerBitacora" style="display:none;">
        <div class="modal-content modal-ver-inst-content">
            <div class="modal-header-crear">
                <h2 id="mvbTitulo" style="font-size:1rem;max-width:90%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:flex;align-items:center;gap:8px;"></h2>
                <button class="modal-close-btn" onclick="cerrarModalVerBitacora()">
                    <span class="material-symbols-rounded">close</span>
                </button>
            </div>
            <div class="modal-body-scroll" id="mvbBody">
                <div style="text-align:center;padding:40px;">
                    <span class="material-symbols-rounded" style="font-size:40px;color:var(--text-secondary);">hourglass_empty</span>
                </div>
            </div>
            <div class="modal-footer-crear">
                <button class="btn-cancelar" onclick="cerrarModalVerBitacora()">Cerrar</button>
                <a id="mvbPdfBtn" href="#" target="_blank" class="btn-primario" style="display:none;text-decoration:none;">
                    <span class="material-symbols-rounded">picture_as_pdf</span>Generar
                </a>
                <?php if ($rol === 'admin' || $rol === 'profesor'): ?>
                <button id="mvbEditBtn" class="btn-primario" onclick="verBitAEditar()"
                        style="display:none;background:var(--subtle-blue);color:var(--primary-blue);border:1px solid var(--primary-blue);"
                        onmouseover="this.style.background='var(--primary-blue)';this.style.color='white'"
                        onmouseout="this.style.background='var(--subtle-blue)';this.style.color='var(--primary-blue)'">
                    <span class="material-symbols-rounded">edit</span> Editar
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ── Modal: Ver Documento ─────────────────────────────────────────────── -->
    <div class="modal" id="modalVerInst" style="display:none;">
        <div class="modal-content modal-ver-inst-content">
            <div class="modal-header-crear">
                <h2 id="mviTitulo" style="font-size:1rem;max-width:90%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></h2>
                <button class="modal-close-btn" onclick="cerrarModalVerInst()">
                    <span class="material-symbols-rounded">close</span>
                </button>
            </div>
            <div class="modal-body-scroll" id="mviBody">
                <div style="text-align:center;padding:40px;">
                    <span class="material-symbols-rounded" style="font-size:40px;color:var(--text-secondary);">hourglass_empty</span>
                </div>
            </div>
            <div class="modal-footer-crear">
                <button class="btn-cancelar" onclick="cerrarModalVerInst()">Cerrar</button>
                <?php if ($rol === 'admin'): ?>
                <button class="btn-danger" id="mviEliminarBtn" onclick="verInstAEliminar()" style="display:none;">
                    <span class="material-symbols-rounded">delete</span> Eliminar
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        function applyFilters() {
            const url = new URL(window.location.href);
            url.searchParams.set('search',    document.getElementById('searchInput').value);
            url.searchParams.set('tipo',      document.getElementById('tipoFilter').value);
            window.location.href = url.toString();
        }

        document.getElementById('searchInput').addEventListener('keypress', e => {
            if (e.key === 'Enter') applyFilters();
        });
        document.getElementById('tipoFilter').addEventListener('change', applyFilters);

        // Toggle vista grid / list
        document.querySelectorAll('.view-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.view-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                document.getElementById('documentsGrid')
                        .classList.toggle('list-view', btn.dataset.view === 'list');
            });
        });
    </script>

    <script>
        // ── Estado global ──────────────────────────────────────────────
        var _verInstData = null;

        // ── Helpers ────────────────────────────────────────────────────
        function htmlEsc(str) {
            if (str == null) return '';
            return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }
        function cap(str) { return str ? str.charAt(0).toUpperCase() + str.slice(1) : ''; }

        function mostrarAlertaInst(msg, tipo) {
            var el = document.getElementById('alertaInst');
            el.innerHTML = '<span class="material-symbols-rounded">' + (tipo === 'error' ? 'error' : 'check_circle') + '</span>' + htmlEsc(msg);
            el.className = 'alerta-inst ' + (tipo === 'error' ? 'alerta-error' : 'alerta-exito');
            el.style.display = 'flex';
            setTimeout(function(){ el.style.display = 'none'; }, 4000);
        }

        // ── Modal Crear ────────────────────────────────────────────────
        function abrirModalCrearInst() {
            document.getElementById('mciFecha').value = new Date().toISOString().slice(0,10);
            document.getElementById('modalCrearInst').style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function cerrarModalCrearInst() {
            document.getElementById('modalCrearInst').style.display = 'none';
            document.body.style.overflow = '';
            document.getElementById('formCrearInst').reset();
            document.querySelectorAll('#formCrearInst .mci-conditional').forEach(function(f){ f.classList.remove('active'); });
            var err = document.getElementById('mciError');
            err.style.display = 'none';
            var fn = document.getElementById('mciFileName');
            fn.style.display = 'none';
        }

        function mciCambiarTipo(tipo) {
            document.querySelectorAll('#formCrearInst .mci-conditional').forEach(function(f){ f.classList.remove('active'); });
            if (tipo === 'certificado') document.getElementById('mciCertificadoFields').classList.add('active');
            else if (tipo === 'comunicado') document.getElementById('mciComunicadoFields').classList.add('active');
            else if (tipo === 'acta') document.getElementById('mciActaFields').classList.add('active');
        }

        function mciCargarGrupos(cursoId) {
            var sel = document.getElementById('mciGrupoSelect');
            var estSel = document.getElementById('mciEstudianteSelect');
            sel.innerHTML = '<option value="">Cargando grupos...</option>';
            estSel.innerHTML = '<option value="">Primero selecciona un grupo</option>';
            if (!cursoId) { sel.innerHTML = '<option value="">Primero selecciona un curso</option>'; return; }
            fetch('get_grupos_curso.php?curso_id=' + cursoId)
                .then(function(r){ return r.json(); })
                .then(function(data){
                    sel.innerHTML = '<option value="">Seleccionar grupo</option>';
                    if (data.grupos && data.grupos.length > 0) {
                        data.grupos.forEach(function(g){ sel.innerHTML += '<option value="' + g.id + '">' + htmlEsc(g.nombre) + '</option>'; });
                    } else { sel.innerHTML = '<option value="">No hay grupos disponibles</option>'; }
                })
                .catch(function(){ sel.innerHTML = '<option value="">Error al cargar grupos</option>'; });
        }

        function mciCargarEstudiantes(grupoId) {
            var sel = document.getElementById('mciEstudianteSelect');
            sel.innerHTML = '<option value="">Cargando estudiantes...</option>';
            if (!grupoId) { sel.innerHTML = '<option value="">Primero selecciona un grupo</option>'; return; }
            fetch('bitacoras/get_estudiantes.php?grupo_id=' + grupoId)
                .then(function(r){ return r.json(); })
                .then(function(data){
                    sel.innerHTML = '<option value="">Seleccionar estudiante</option>';
                    if (data.estudiantes && data.estudiantes.length > 0) {
                        data.estudiantes.forEach(function(e){ sel.innerHTML += '<option value="' + e.id + '">' + htmlEsc(e.nombre) + '</option>'; });
                    } else { sel.innerHTML = '<option value="">No hay estudiantes matriculados</option>'; }
                })
                .catch(function(){ sel.innerHTML = '<option value="">Error al cargar estudiantes</option>'; });
        }

        document.addEventListener('DOMContentLoaded', function() {
            var archivoInput = document.getElementById('mciArchivo');
            if (archivoInput) {
                archivoInput.addEventListener('change', function(){
                    var fn = document.getElementById('mciFileName');
                    if (this.files.length > 0) { fn.textContent = '✓ ' + this.files[0].name; fn.style.display = 'block'; }
                    else { fn.style.display = 'none'; }
                });
            }
        });

        function submitCrearInst() {
            var btn = document.getElementById('mciSubmitBtn');
            var errEl = document.getElementById('mciError');
            errEl.style.display = 'none';
            btn.disabled = true;
            btn.innerHTML = '<span class="material-symbols-rounded" style="animation:mvSpin 1s linear infinite;">refresh</span> Guardando...';

            var data = new FormData(document.getElementById('formCrearInst'));
            fetch('crear.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: data
            })
            .then(function(r){ return r.json(); })
            .then(function(res){
                btn.disabled = false;
                btn.innerHTML = '<span class="material-symbols-rounded">save</span> Guardar ';
                if (res.success) {
                    cerrarModalCrearInst();
                    mostrarAlertaInst('Documento creado correctamente', 'success');
                    setTimeout(function(){ window.location.reload(); }, 1500);
                } else {
                    document.getElementById('mciErrorText').textContent = res.error || 'Error al crear el documento';
                    errEl.style.display = 'flex';
                }
            })
            .catch(function(){
                btn.disabled = false;
                btn.innerHTML = '<span class="material-symbols-rounded">save</span> Guardar ';
                document.getElementById('mciErrorText').textContent = 'Error de conexión';
                errEl.style.display = 'flex';
            });
        }

        // ── Modal Ver ──────────────────────────────────────────────────
        function abrirModalVerInst(tipo, id) {
            _verInstData = null;
            document.getElementById('mviBody').innerHTML = '<div style="text-align:center;padding:40px;"><span class="material-symbols-rounded" style="font-size:40px;animation:mvSpin 1s linear infinite;color:var(--text-secondary);">refresh</span></div>';
            document.getElementById('mviTitulo').textContent = 'Cargando...';
            var elim = document.getElementById('mviEliminarBtn');
            if (elim) elim.style.display = 'none';
            document.getElementById('modalVerInst').style.display = 'flex';
            document.body.style.overflow = 'hidden';

            fetch('ver.php?tipo=' + tipo + '&id=' + id, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(r){ return r.json(); })
            .then(function(res){
                if (!res.success) {
                    document.getElementById('mviBody').innerHTML = '<p style="color:#ef4444;padding:20px;">Error al cargar el documento</p>';
                    return;
                }
                _verInstData = res;
                renderVerInstModal(res);
                if (elim) elim.style.display = 'flex';
            })
            .catch(function(){
                document.getElementById('mviBody').innerHTML = '<p style="color:#ef4444;padding:20px;">Error de conexión</p>';
            });
        }

        function renderVerInstModal(res) {
            var doc  = res.documento;
            var tipo = res.tipo;
            var html = '';

            document.getElementById('mviTitulo').innerHTML =
                '<span class="material-symbols-rounded">' + htmlEsc(res.icono) + '</span> ' + htmlEsc(res.titulo_display);

            if (tipo === 'certificado') {
                html += '<div class="mvi-code-box"><div class="mvi-code-label">Código del Certificado</div><div class="mvi-code-value">' + htmlEsc(doc.codigo_certificado) + '</div></div>';
                html += '<div class="mvi-info-grid">';
                html += '<div class="mvi-info-item"><div class="mvi-label">Estudiante</div><div class="mvi-value">' + htmlEsc(doc.estudiante_nombre) + '</div></div>';
                html += '<div class="mvi-info-item"><div class="mvi-label">Curso</div><div class="mvi-value">' + htmlEsc(doc.curso_nombre) + '</div></div>';
                html += '<div class="mvi-info-item"><div class="mvi-label">Grupo</div><div class="mvi-value">' + htmlEsc(doc.grupo_nombre) + '</div></div>';
                html += '<div class="mvi-info-item"><div class="mvi-label">Nivel Aprobado</div><div class="mvi-value">' + cap(doc.nivel_aprobado) + '</div></div>';
                html += '<div class="mvi-info-item"><div class="mvi-label">Calificación Final</div><div class="mvi-value" style="color:var(--primary-green);">' + parseFloat(doc.calificacion_final).toFixed(1) + '</div></div>';
                html += '<div class="mvi-info-item"><div class="mvi-label">Fecha de Aprobación</div><div class="mvi-value" style="font-size:.9rem;">' + htmlEsc(res.fecha_display) + '</div></div>';
                if (res.periodo) html += '<div class="mvi-info-item"><div class="mvi-label">Periodo del Curso</div><div class="mvi-value" style="font-size:.9rem;">' + htmlEsc(res.periodo) + '</div></div>';
                if (doc.aprobado_por_nombre) html += '<div class="mvi-info-item"><div class="mvi-label">Aprobado por</div><div class="mvi-value" style="font-size:.9rem;">' + htmlEsc(doc.aprobado_por_nombre) + '</div></div>';
                html += '</div>';
                if (doc.observaciones) html += '<div style="margin-top:12px;"><div class="mvi-label" style="margin-bottom:6px;">Observaciones</div><div class="mvi-content-box">' + htmlEsc(doc.observaciones).replace(/\n/g,'<br>') + '</div></div>';

            } else if (tipo === 'comunicado') {
                html += '<div class="mvi-info-grid">';
                html += '<div class="mvi-info-item"><div class="mvi-label">Categoría</div><div class="mvi-value">' + cap(doc.categoria) + '</div></div>';
                html += '<div class="mvi-info-item"><div class="mvi-label">Prioridad</div><div class="mvi-value"' + (doc.prioridad === 'urgente' ? ' style="color:#ef4444;"' : '') + '>' + cap(doc.prioridad) + '</div></div>';
                html += '<div class="mvi-info-item"><div class="mvi-label">Dirigido a</div><div class="mvi-value">' + cap(doc.dirigido_a) + '</div></div>';
                html += '<div class="mvi-info-item"><div class="mvi-label">Fecha de Publicación</div><div class="mvi-value" style="font-size:.9rem;">' + htmlEsc(res.fecha_display) + '</div></div>';
                html += '<div class="mvi-info-item"><div class="mvi-label">Publicado por</div><div class="mvi-value" style="font-size:.9rem;">' + htmlEsc(doc.publicado_por_nombre) + '</div></div>';
                html += '</div>';
                if (doc.descripcion) html += '<div style="margin-top:12px;"><div class="mvi-label" style="margin-bottom:6px;">Descripción</div><div class="mvi-content-box">' + htmlEsc(doc.descripcion).replace(/\n/g,'<br>') + '</div></div>';

            } else if (tipo === 'acta') {
                html += '<div class="mvi-info-grid">';
                html += '<div class="mvi-info-item"><div class="mvi-label">Tipo de Reunión</div><div class="mvi-value">' + htmlEsc(res.tipo_reunion_label) + '</div></div>';
                html += '<div class="mvi-info-item"><div class="mvi-label">Lugar</div><div class="mvi-value" style="font-size:.9rem;">' + htmlEsc(doc.lugar || 'No especificado') + '</div></div>';
                html += '<div class="mvi-info-item"><div class="mvi-label">Fecha de Reunión</div><div class="mvi-value" style="font-size:.9rem;">' + htmlEsc(res.fecha_display) + '</div></div>';
                html += '<div class="mvi-info-item"><div class="mvi-label">Creado por</div><div class="mvi-value" style="font-size:.9rem;">' + htmlEsc(doc.creado_por_nombre) + '</div></div>';
                if (res.visibilidad_label) html += '<div class="mvi-info-item"><div class="mvi-label">Visibilidad</div><div class="mvi-value" style="font-size:.9rem;">' + htmlEsc(res.visibilidad_label) + '</div></div>';
                html += '</div>';
                if (doc.descripcion) html += '<div style="margin-top:12px;"><div class="mvi-label" style="margin-bottom:6px;">Descripción</div><div class="mvi-content-box">' + htmlEsc(doc.descripcion).replace(/\n/g,'<br>') + '</div></div>';
                if (doc.asistentes) html += '<div style="margin-top:12px;"><div class="mvi-label" style="margin-bottom:6px;">Asistentes</div><div class="mvi-content-box">' + htmlEsc(doc.asistentes).replace(/\n/g,'<br>') + '</div></div>';
            }

            html += '<div style="margin-top:16px;">';
            html += '<a href="generar.php?tipo=' + tipo + '&id=' + doc.id + '" target="_blank" style="display:inline-flex;align-items:center;gap:8px;padding:10px 20px;background:var(--subtle-orange);color:var(--primary-orange);border:1px solid var(--primary-orange);border-radius:10px;text-decoration:none;font-size:.9rem;font-weight:500;">';
            html += '<span class="material-symbols-rounded">picture_as_pdf</span> Ver / Descargar PDF</a>';
            html += '</div>';

            document.getElementById('mviBody').innerHTML = html;
        }

        function cerrarModalVerInst() {
            document.getElementById('modalVerInst').style.display = 'none';
            document.body.style.overflow = '';
            _verInstData = null;
        }

        function verInstAEliminar() {
            if (!_verInstData) return;
            var tipo   = _verInstData.tipo;
            var id     = _verInstData.documento.id;
            var titulo = _verInstData.titulo_display;
            cerrarModalVerInst();
            abrirModalEliminarInst(tipo, id, titulo);
        }

        // ── Modal Editar ───────────────────────────────────────────────
        function abrirModalEditarInst(tipo, id) {
            document.getElementById('meiEditTitulo').innerHTML = '<span class="material-symbols-rounded">edit</span> Cargando...';
            document.getElementById('meiEditBody').innerHTML = '<div style="text-align:center;padding:40px;"><span class="material-symbols-rounded" style="font-size:40px;animation:mvSpin 1s linear infinite;color:var(--text-secondary);">refresh</span></div>';
            document.getElementById('meiEditSubmitBtn').disabled = true;
            document.getElementById('modalEditarInst').style.display = 'flex';
            document.body.style.overflow = 'hidden';

            fetch('ver.php?tipo=' + tipo + '&id=' + id, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(r){ return r.json(); })
            .then(function(res){
                if (!res.success) {
                    document.getElementById('meiEditBody').innerHTML = '<p style="color:#ef4444;padding:20px;">Error al cargar el documento</p>';
                    return;
                }
                var icons = { certificado: 'workspace_premium', comunicado: 'campaign', acta: 'description' };
                document.getElementById('meiEditTitulo').innerHTML =
                    '<span class="material-symbols-rounded">' + (icons[res.tipo] || 'edit') + '</span> Editar ' + cap(res.tipo);
                document.getElementById('meiEditBody').innerHTML = renderEditarInstModal(res);
                document.getElementById('meiEditSubmitBtn').disabled = false;
            })
            .catch(function(){
                document.getElementById('meiEditBody').innerHTML = '<p style="color:#ef4444;padding:20px;">Error de conexión</p>';
            });
        }

        function renderEditarInstModal(res) {
            var doc  = res.documento;
            var tipo = res.tipo;
            var html = '<div class="mci-error" id="meiEditError" style="display:none;"><span class="material-symbols-rounded">error</span><span id="meiEditErrorText"></span></div>';
            html += '<form id="formEditarInst">';
            html += '<input type="hidden" name="tipo"   value="' + htmlEsc(tipo) + '">';
            html += '<input type="hidden" name="doc_id" value="' + doc.id + '">';

            if (tipo === 'certificado') {
                html += '<div style="background:var(--hover-bg);border:1px solid var(--border-color);border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:.85rem;color:var(--text-secondary);">';
                html += '<span class="material-symbols-rounded" style="font-size:16px;vertical-align:middle;margin-right:4px;">info</span>';
                html += '<strong style="color:var(--text-primary);">' + htmlEsc(doc.estudiante_nombre) + '</strong>';
                html += ' &nbsp;·&nbsp; Curso: <strong style="color:var(--text-primary);">' + htmlEsc(doc.curso_nombre) + '</strong>';
                html += ' &nbsp;·&nbsp; Grupo: <strong style="color:var(--text-primary);">' + htmlEsc(doc.grupo_nombre || '—') + '</strong></div>';

                html += '<div class="mci-grid">';
                html += '<div class="mci-form-group"><label class="mci-label required">Nivel Aprobado</label><select name="nivel_aprobado" class="mci-select">';
                [['basico','Básico'],['intermedio','Intermedio'],['avanzado','Avanzado']].forEach(function(o) {
                    html += '<option value="' + o[0] + '"' + (doc.nivel_aprobado === o[0] ? ' selected' : '') + '>' + o[1] + '</option>';
                });
                html += '</select></div>';
                html += '<div class="mci-form-group"><label class="mci-label required">Calificación Final</label>';
                html += '<input type="number" name="calificacion_final" class="mci-input" min="0" max="5" step="0.1" value="' + parseFloat(doc.calificacion_final || 0).toFixed(1) + '"></div>';
                html += '</div>';

                html += '<div class="mci-form-group"><label class="mci-label required">Fecha de Aprobación</label>';
                html += '<input type="date" name="fecha_aprobacion" class="mci-input" value="' + (doc.fecha_aprobacion || '').slice(0,10) + '"></div>';

                html += '<div class="mci-grid">';
                html += '<div class="mci-form-group"><label class="mci-label">Fecha Inicio Curso</label>';
                html += '<input type="date" name="fecha_inicio_curso" class="mci-input" value="' + (doc.fecha_inicio_curso || '').slice(0,10) + '"></div>';
                html += '<div class="mci-form-group"><label class="mci-label">Fecha Fin Curso</label>';
                html += '<input type="date" name="fecha_fin_curso" class="mci-input" value="' + (doc.fecha_fin_curso || '').slice(0,10) + '"></div>';
                html += '</div>';

                html += '<div class="mci-form-group"><label class="mci-label">Observaciones</label>';
                html += '<textarea name="observaciones" class="mci-textarea">' + htmlEsc(doc.observaciones || '') + '</textarea></div>';

            } else if (tipo === 'comunicado') {
                html += '<div class="mci-form-group"><label class="mci-label required">Título</label>';
                html += '<input type="text" name="titulo" class="mci-input" value="' + htmlEsc(doc.titulo || '') + '" required></div>';

                html += '<div class="mci-form-group"><label class="mci-label">Descripción</label>';
                html += '<textarea name="descripcion" class="mci-textarea">' + htmlEsc(doc.descripcion || '') + '</textarea></div>';

                html += '<div class="mci-form-group"><label class="mci-label required">Fecha de Publicación</label>';
                html += '<input type="date" name="fecha_documento" class="mci-input" value="' + (doc.fecha_publicacion || '').slice(0,10) + '"></div>';

                html += '<div class="mci-grid">';
                html += '<div class="mci-form-group"><label class="mci-label">Categoría</label><select name="categoria_comunicado" class="mci-select">';
                [['general','General'],['academico','Académico'],['administrativo','Administrativo'],['evento','Evento'],['urgente','Urgente']].forEach(function(o) {
                    html += '<option value="' + o[0] + '"' + (doc.categoria === o[0] ? ' selected' : '') + '>' + o[1] + '</option>';
                });
                html += '</select></div>';
                html += '<div class="mci-form-group"><label class="mci-label">Prioridad</label><select name="prioridad" class="mci-select">';
                [['normal','Normal'],['alta','Alta'],['urgente','Urgente']].forEach(function(o) {
                    html += '<option value="' + o[0] + '"' + (doc.prioridad === o[0] ? ' selected' : '') + '>' + o[1] + '</option>';
                });
                html += '</select></div>';
                html += '</div>';

                html += '<div class="mci-form-group"><label class="mci-label">Dirigido a</label><select name="dirigido_a" class="mci-select">';
                [['todos','Todos'],['estudiantes','Estudiantes'],['profesores','Profesores'],['padres','Padres de Familia']].forEach(function(o) {
                    html += '<option value="' + o[0] + '"' + (doc.dirigido_a === o[0] ? ' selected' : '') + '>' + o[1] + '</option>';
                });
                html += '</select></div>';

            } else if (tipo === 'acta') {
                html += '<div class="mci-form-group"><label class="mci-label required">Título</label>';
                html += '<input type="text" name="titulo" class="mci-input" value="' + htmlEsc(doc.titulo || '') + '" required></div>';

                html += '<div class="mci-form-group"><label class="mci-label">Descripción / Desarrollo</label>';
                html += '<textarea name="descripcion" class="mci-textarea" style="min-height:100px;">' + htmlEsc(doc.descripcion || '') + '</textarea></div>';

                html += '<div class="mci-form-group"><label class="mci-label required">Fecha de Reunión</label>';
                html += '<input type="date" name="fecha_documento" class="mci-input" value="' + (doc.fecha_reunion || '').slice(0,10) + '"></div>';

                html += '<div class="mci-grid">';
                html += '<div class="mci-form-group"><label class="mci-label required">Tipo de Reunión</label><select name="tipo_reunion" class="mci-select">';
                [['consejo_academico','Consejo Académico'],['reunion_docentes','Reunión de Docentes'],['reunion_padres','Reunión de Padres'],['comite_directivo','Comité Directivo'],['otra','Otra']].forEach(function(o) {
                    html += '<option value="' + o[0] + '"' + (doc.tipo_reunion === o[0] ? ' selected' : '') + '>' + o[1] + '</option>';
                });
                html += '</select></div>';
                html += '<div class="mci-form-group"><label class="mci-label">Lugar</label>';
                html += '<input type="text" name="lugar" class="mci-input" value="' + htmlEsc(doc.lugar || '') + '"></div>';
                html += '</div>';

                html += '<div class="mci-form-group"><label class="mci-label">Asistentes</label>';
                html += '<textarea name="asistentes" class="mci-textarea">' + htmlEsc(doc.asistentes || '') + '</textarea></div>';

                html += '<div class="mci-form-group"><label class="mci-label required">Visibilidad</label><select name="visibilidad_acta" class="mci-select">';
                [['solo_admin','Solo Administradores'],['admin_profesores','Admin y Profesores'],['todos','Todos']].forEach(function(o) {
                    html += '<option value="' + o[0] + '"' + (doc.visibilidad === o[0] ? ' selected' : '') + '>' + o[1] + '</option>';
                });
                html += '</select></div>';
            }

            html += '</form>';
            return html;
        }

        function cerrarModalEditarInst() {
            document.getElementById('modalEditarInst').style.display = 'none';
            document.body.style.overflow = '';
        }

        function submitEditarInst() {
            var form = document.getElementById('formEditarInst');
            if (!form) return;
            var btn    = document.getElementById('meiEditSubmitBtn');
            var errEl  = document.getElementById('meiEditError');
            var errTxt = document.getElementById('meiEditErrorText');
            if (errEl) errEl.style.display = 'none';
            btn.disabled = true;
            btn.innerHTML = '<span class="material-symbols-rounded" style="animation:mvSpin 1s linear infinite;">refresh</span> Guardando...';

            fetch('editar.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: new FormData(form)
            })
            .then(function(r){ return r.json(); })
            .then(function(res){
                btn.disabled = false;
                btn.innerHTML = '<span class="material-symbols-rounded">save</span> Guardar ';
                if (res.success) {
                    cerrarModalEditarInst();
                    mostrarAlertaInst('Cambios guardados correctamente', 'success');
                    setTimeout(function(){ window.location.reload(); }, 1500);
                } else {
                    if (errEl && errTxt) { errTxt.textContent = res.error || 'Error al guardar los cambios'; errEl.style.display = 'flex'; }
                }
            })
            .catch(function(){
                btn.disabled = false;
                btn.innerHTML = '<span class="material-symbols-rounded">save</span> Guardar ';
                if (errEl && errTxt) { errTxt.textContent = 'Error de conexión'; errEl.style.display = 'flex'; }
            });
        }

        // ── Modal Eliminar ─────────────────────────────────────────────
        function abrirModalEliminarInst(tipo, id, titulo) {
            document.getElementById('meiTipo').value = tipo;
            document.getElementById('meiId').value   = id;
            document.getElementById('meiTitulo').textContent = titulo;
            document.getElementById('modalEliminarInst').style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function cerrarModalEliminarInst() {
            document.getElementById('modalEliminarInst').style.display = 'none';
            document.body.style.overflow = '';
        }

        function submitEliminarInst() {
            var tipo = document.getElementById('meiTipo').value;
            var id   = document.getElementById('meiId').value;
            var data = new FormData();
            data.append('tipo',   tipo);
            data.append('doc_id', id);

            fetch('eliminar.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: data
            })
            .then(function(r){ return r.json(); })
            .then(function(res){
                cerrarModalEliminarInst();
                if (res.success) {
                    mostrarAlertaInst('Documento eliminado correctamente', 'success');
                    setTimeout(function(){ window.location.reload(); }, 1500);
                } else {
                    mostrarAlertaInst(res.error || 'Error al eliminar', 'error');
                }
            })
            .catch(function(){
                cerrarModalEliminarInst();
                mostrarAlertaInst('Error de conexión', 'error');
            });
        }

        // ── Modal Ver Bitácora ─────────────────────────────────────

        var _verBitData = null;

        function abrirModalVerBitacora(id) {
            _verBitData = null;
            document.getElementById('mvbBody').innerHTML = '<div style="text-align:center;padding:40px;"><span class="material-symbols-rounded" style="font-size:40px;animation:mvSpin 1s linear infinite;color:var(--text-secondary);">refresh</span></div>';
            document.getElementById('mvbTitulo').textContent = 'Cargando...';
            document.getElementById('mvbPdfBtn').style.display = 'none';
            var editBtn = document.getElementById('mvbEditBtn');
            if (editBtn) editBtn.style.display = 'none';
            document.getElementById('modalVerBitacora').style.display = 'flex';
            document.body.style.overflow = 'hidden';

            fetch('bitacoras/get_bitacora.php?id=' + id, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(r){ return r.json(); })
            .then(function(res){
                if (!res.success) {
                    document.getElementById('mvbBody').innerHTML = '<p style="color:var(--primary-red);padding:20px;">Error al cargar la bitácora</p>';
                    return;
                }
                _verBitData = res;
                renderVerBitacoraModal(res);
                var pdfBtn = document.getElementById('mvbPdfBtn');
                pdfBtn.href    = 'bitacoras/generar.php?id=' + res.bitacora.id;
                pdfBtn.style.display = 'flex';
                var eb = document.getElementById('mvbEditBtn');
                if (eb) {
                    var isAdmin = <?php echo $rol === 'admin' ? 'true' : 'false'; ?>;
                    var isOwner = res.bitacora.profesor_id == <?php echo $uid; ?>;
                    eb.style.display = (isAdmin || isOwner) ? 'flex' : 'none';
                }
            })
            .catch(function(){
                document.getElementById('mvbBody').innerHTML = '<p style="color:var(--primary-red);padding:20px;">Error de conexión</p>';
            });
        }

        function renderVerBitacoraModal(res) {
            var bit        = res.bitacora;
            var asistencias = res.asistencias || [];
            var evidencias  = res.evidencias  || [];

            document.getElementById('mvbTitulo').innerHTML =
                '<span class="material-symbols-rounded">menu_book</span>' + htmlEsc(bit.titulo);

            function fmtHora(h) {
                if (!h) return '—';
                var p = h.split(':'), hr = parseInt(p[0]), mn = p[1] || '00';
                var ap = hr >= 12 ? 'PM' : 'AM';
                hr = hr % 12 || 12;
                return (hr < 10 ? '0' + hr : hr) + ':' + mn + ' ' + ap;
            }

            var html = '';

            // Info grid
            html += '<div class="mvi-info-grid">';
            html += '<div class="mvi-info-item"><div class="mvi-label">Fecha</div><div class="mvi-value" style="font-size:.9rem;">' + htmlEsc(bit.fecha_clase || '') + '</div></div>';
            html += '<div class="mvi-info-item"><div class="mvi-label">Horario</div><div class="mvi-value" style="font-size:.9rem;">' + fmtHora(bit.hora_inicio) + ' – ' + fmtHora(bit.hora_fin) + '</div></div>';
            html += '<div class="mvi-info-item"><div class="mvi-label">Curso</div><div class="mvi-value">' + htmlEsc(bit.curso_nombre || '') + '</div></div>';
            html += '<div class="mvi-info-item"><div class="mvi-label">Grupo</div><div class="mvi-value">' + htmlEsc(bit.grupo_nombre || '') + '</div></div>';
            html += '<div class="mvi-info-item"><div class="mvi-label">Profesor</div><div class="mvi-value">' + htmlEsc(bit.profesor_nombre || '') + '</div></div>';
            html += '</div>';

            // Temas
            if (bit.temas_tratados) {
                var temas = bit.temas_tratados.split(',').map(function(t){ return t.trim(); }).filter(Boolean);
                if (temas.length) {
                    html += '<div style="margin-bottom:16px;"><div class="mvi-label" style="margin-bottom:8px;">Temas Tratados</div>';
                    html += '<div style="display:flex;flex-wrap:wrap;gap:6px;">';
                    temas.forEach(function(t){ html += '<span class="tag">' + htmlEsc(t) + '</span>'; });
                    html += '</div></div>';
                }
            }

            // Descripción
            if (bit.descripcion_clase) {
                html += '<div style="margin-bottom:16px;"><div class="mvi-label" style="margin-bottom:6px;">Descripción de la Clase</div>';
                html += '<div class="mvi-content-box">' + htmlEsc(bit.descripcion_clase).replace(/\n/g,'<br>') + '</div></div>';
            }

            // Observaciones
            if (bit.observaciones) {
                html += '<div style="margin-bottom:16px;"><div class="mvi-label" style="margin-bottom:6px;">Observaciones</div>';
                html += '<div class="mvi-content-box">' + htmlEsc(bit.observaciones).replace(/\n/g,'<br>') + '</div></div>';
            }

            // Compromisos
            if (bit.compromisos_proxima_clase) {
                html += '<div style="margin-bottom:16px;"><div class="mvi-label" style="margin-bottom:6px;">Compromisos para la Próxima Clase</div>';
                html += '<div class="mvi-content-box">' + htmlEsc(bit.compromisos_proxima_clase).replace(/\n/g,'<br>') + '</div></div>';
            }

            // Asistencia
            if (asistencias.length > 0) {
                var stats = {presente:0, ausente:0, justificado:0, tardanza:0};
                asistencias.forEach(function(a){ if (stats[a.estado] !== undefined) stats[a.estado]++; });

                html += '<div class="mvi-label" style="margin-bottom:8px;">Asistencia (' + asistencias.length + ' estudiantes)</div>';
                html += '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;">';
                if (stats.presente)    html += '<span style="background:var(--subtle-green);color:var(--primary-green);padding:4px 10px;border-radius:6px;font-size:.8rem;font-weight:500;">' + stats.presente + ' presente</span>';
                if (stats.ausente)     html += '<span style="background:var(--subtle-red);color:var(--primary-red);padding:4px 10px;border-radius:6px;font-size:.8rem;font-weight:500;">' + stats.ausente + ' ausente</span>';
                if (stats.tardanza)    html += '<span style="background:var(--subtle-orange);color:var(--primary-orange);padding:4px 10px;border-radius:6px;font-size:.8rem;font-weight:500;">' + stats.tardanza + ' tardanza</span>';
                if (stats.justificado) html += '<span style="background:var(--subtle-yellow);color:var(--primary-yellow);padding:4px 10px;border-radius:6px;font-size:.8rem;font-weight:500;">' + stats.justificado + ' justificado</span>';
                html += '</div>';

                html += '<div style="background:var(--hover-bg);border-radius:10px;overflow:hidden;border:1px solid var(--border-color);margin-bottom:16px;">';
                html += '<table style="width:100%;border-collapse:collapse;">';
                html += '<thead><tr style="border-bottom:1px solid var(--border-color);">';
                html += '<th style="padding:10px 14px;text-align:left;color:var(--text-secondary);font-size:.8rem;font-weight:500;">Estudiante</th>';
                html += '<th style="padding:10px 14px;text-align:center;color:var(--text-secondary);font-size:.8rem;font-weight:500;">Estado</th>';
                html += '<th style="padding:10px 14px;text-align:left;color:var(--text-secondary);font-size:.8rem;font-weight:500;">Observación</th>';
                html += '</tr></thead><tbody>';

                var badgeBgs    = {presente:'var(--subtle-green)', ausente:'var(--subtle-red)', tardanza:'var(--subtle-orange)', justificado:'var(--subtle-yellow)'};
                var badgeColors = {presente:'var(--primary-green)', ausente:'var(--primary-red)', tardanza:'var(--primary-orange)', justificado:'var(--primary-yellow)'};

                asistencias.forEach(function(a, i) {
                    var bg    = i % 2 === 0 ? 'transparent' : 'var(--hover-bg)';
                    var bbg   = badgeBgs[a.estado]    || 'transparent';
                    var bcol  = badgeColors[a.estado] || 'var(--text-secondary)';
                    var label = a.estado.charAt(0).toUpperCase() + a.estado.slice(1);
                    html += '<tr style="background:' + bg + ';border-bottom:1px solid var(--border-color);">';
                    html += '<td style="padding:10px 14px;color:var(--text-primary);font-size:.85rem;">' + htmlEsc(a.nombre) + '</td>';
                    html += '<td style="padding:10px 14px;text-align:center;"><span style="background:' + bbg + ';color:' + bcol + ';padding:3px 8px;border-radius:6px;font-size:.75rem;font-weight:500;">' + label + '</span></td>';
                    html += '<td style="padding:10px 14px;color:var(--text-secondary);font-size:.8rem;">' + htmlEsc(a.observacion || '—') + '</td>';
                    html += '</tr>';
                });
                html += '</tbody></table></div>';
            }

            // Evidencias
            if (evidencias.length > 0) {
                html += '<div class="mvi-label" style="margin-bottom:8px;">Evidencias Fotográficas</div>';
                html += '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;margin-bottom:16px;">';
                evidencias.forEach(function(ev) {
                    html += '<div style="border-radius:8px;overflow:hidden;border:1px solid var(--border-color);">';
                    html += '<img src="../../../assets/uploads/bitacoras/evidencias/' + htmlEsc(ev.archivo) + '" style="width:100%;height:120px;object-fit:cover;display:block;" onerror="this.style.display=\'none\'" alt="">';
                    if (ev.descripcion) html += '<div style="padding:4px 8px;color:var(--text-secondary);font-size:.75rem;">' + htmlEsc(ev.descripcion) + '</div>';
                    html += '</div>';
                });
                html += '</div>';
            }

            document.getElementById('mvbBody').innerHTML = html;
        }

        function cerrarModalVerBitacora() {
            document.getElementById('modalVerBitacora').style.display = 'none';
            document.body.style.overflow = '';
            _verBitData = null;
        }

        function verBitAEditar() {
            if (!_verBitData) return;
            var id = _verBitData.bitacora.id;
            cerrarModalVerBitacora();
            abrirModalEditarBitacora(id);
        }

        // Cerrar modales al hacer clic en el fondo
        ['modalCrearInst','modalEditarInst','modalVerInst','modalEliminarInst','modalCrearBitacora','modalEditarBitacora','modalVerBitacora','modalEliminarBitacora'].forEach(function(id) {
            var el = document.getElementById(id);
            if (!el) return;
            el.addEventListener('click', function(e) {
                if (e.target === el) {
                    if (id === 'modalCrearInst')              cerrarModalCrearInst();
                    else if (id === 'modalEditarInst')        cerrarModalEditarInst();
                    else if (id === 'modalVerInst')           cerrarModalVerInst();
                    else if (id === 'modalCrearBitacora')     cerrarModalCrearBitacora();
                    else if (id === 'modalEditarBitacora')    cerrarModalEditarBitacora();
                    else if (id === 'modalVerBitacora')       cerrarModalVerBitacora();
                    else if (id === 'modalEliminarBitacora')  cerrarModalEliminarBitacora();
                    else                                      cerrarModalEliminarInst();
                }
            });
        });
    </script>

    <?php if ($rol === 'admin' || $rol === 'profesor'): ?>
    <script>
        var GRUPOS_BITACORA = <?php echo json_encode($grupos_bit); ?>;

        // ── Modal Crear Bitácora ───────────────────────────────────────

        function abrirModalCrearBitacora() {
            document.getElementById('formCrearBitacora').reset();
            document.getElementById('mcrBitFecha').value = new Date().toISOString().slice(0,10);
            document.getElementById('mcrBitEstContainer').innerHTML = '<p style="color:var(--text-secondary);font-size:.85rem;">Selecciona un grupo para ver los estudiantes</p>';
            document.getElementById('mcrBitFilesList').innerHTML = '';
            document.getElementById('mcrBitFormError').style.display = 'none';
            document.getElementById('mcrBitSubmitBtn').disabled = false;
            document.getElementById('mcrBitSubmitBtn').innerHTML = '<span class="material-symbols-rounded">save</span> Guardar';
            document.getElementById('modalCrearBitacora').style.display = 'flex';
            document.body.style.overflow = 'hidden';

            var inp = document.getElementById('mcrBitEvidenciasInput');
            if (inp) {
                inp.removeEventListener('change', mcrBitHandleFiles);
                inp.addEventListener('change', mcrBitHandleFiles);
            }
        }

        function mcrBitHandleFiles() {
            mcrBitMostrarArchivos(this.files);
        }

        function cerrarModalCrearBitacora() {
            document.getElementById('modalCrearBitacora').style.display = 'none';
            document.body.style.overflow = '';
        }

        function mcrBitCargarEstudiantes(grupoId) {
            var container = document.getElementById('mcrBitEstContainer');
            if (!grupoId) {
                container.innerHTML = '<p style="color:var(--text-secondary);font-size:.85rem;">Selecciona un grupo para ver los estudiantes</p>';
                return;
            }
            container.innerHTML = '<p style="color:var(--text-secondary);font-size:.85rem;">Cargando estudiantes...</p>';
            fetch('bitacoras/get_estudiantes.php?grupo_id=' + grupoId)
                .then(function(r){ return r.json(); })
                .then(function(data){
                    if (data.estudiantes && data.estudiantes.length > 0) {
                        var html = '';
                        data.estudiantes.forEach(function(est) {
                            html += '<div class="bit-student-item">';
                            html += '<div class="bit-student-name">' + htmlEsc(est.nombre) + '</div>';
                            html += '<select name="asistencias[' + est.id + ']" class="bit-att-select">';
                            ['presente','ausente','justificado','tardanza'].forEach(function(v){
                                html += '<option value="' + v + '">' + v.charAt(0).toUpperCase() + v.slice(1) + '</option>';
                            });
                            html += '</select>';
                            html += '<input type="text" name="asistencia_obs[' + est.id + ']" class="bit-obs-input" placeholder="Observación (opcional)">';
                            html += '</div>';
                        });
                        container.innerHTML = html;
                    } else {
                        container.innerHTML = '<p style="color:var(--text-secondary);font-size:.85rem;">No hay estudiantes matriculados en este grupo</p>';
                    }
                })
                .catch(function(){
                    container.innerHTML = '<p style="color:var(--primary-red);font-size:.85rem;">Error al cargar estudiantes</p>';
                });
        }

        function mcrBitMostrarArchivos(files) {
            var filesList = document.getElementById('mcrBitFilesList');
            filesList.innerHTML = '';
            Array.from(files).forEach(function(file, index) {
                filesList.innerHTML += '<div class="bit-file-item"><span class="material-symbols-rounded">image</span><span>' + htmlEsc(file.name) + '</span><input type="text" name="evidencia_desc[' + index + ']" class="bit-obs-input" placeholder="Descripción (opcional)"></div>';
            });
        }

        function submitCrearBitacora() {
            var form = document.getElementById('formCrearBitacora');
            var errEl = document.getElementById('mcrBitFormError');
            var btn   = document.getElementById('mcrBitSubmitBtn');
            errEl.style.display = 'none';
            btn.disabled = true;
            btn.innerHTML = '<span class="material-symbols-rounded" style="animation:mvSpin 1s linear infinite;">refresh</span> Guardando...';

            fetch('bitacoras/crear.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: new FormData(form)
            })
            .then(function(r){ return r.json(); })
            .then(function(res){
                btn.disabled = false;
                btn.innerHTML = '<span class="material-symbols-rounded">save</span> Guardar ';
                if (res.success) {
                    cerrarModalCrearBitacora();
                    mostrarAlertaInst('Bitácora creada correctamente', 'success');
                    setTimeout(function(){ window.location.reload(); }, 1500);
                } else {
                    errEl.innerHTML = '<span class="material-symbols-rounded">error</span><span>' + htmlEsc(res.error || 'Error al crear la bitácora') + '</span>';
                    errEl.style.display = 'flex';
                }
            })
            .catch(function(){
                btn.disabled = false;
                btn.innerHTML = '<span class="material-symbols-rounded">save</span> Guardar ';
                errEl.innerHTML = '<span class="material-symbols-rounded">error</span><span>Error de conexión</span>';
                errEl.style.display = 'flex';
            });
        }

        // ── Modal Editar Bitácora ──────────────────────────────────────

        function abrirModalEditarBitacora(id) {
            var body = document.getElementById('mebBitBody');
            var btn  = document.getElementById('mebBitSubmitBtn');
            body.innerHTML = '<div style="text-align:center;padding:40px;"><span class="material-symbols-rounded" style="font-size:40px;animation:mvSpin 1s linear infinite;color:var(--text-secondary);">refresh</span></div>';
            btn.disabled = true;
            document.getElementById('modalEditarBitacora').style.display = 'flex';
            document.body.style.overflow = 'hidden';

            fetch('bitacoras/get_bitacora.php?id=' + id, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(r){ return r.json(); })
            .then(function(res){
                if (!res.success) {
                    body.innerHTML = '<p style="color:var(--primary-red);padding:20px;">' + htmlEsc(res.error || 'Error al cargar la bitácora') + '</p>';
                    return;
                }
                body.innerHTML = renderEditarBitacoraModal(res);
                // Listener archivos
                var fileInp = document.getElementById('mebEvidenciasInput');
                if (fileInp) fileInp.addEventListener('change', function(){ mebMostrarArchivos(this.files); });
                // Cargar estudiantes pre-rellenados
                mebCargarEstudiantes(res.bitacora.grupo_id, res.asistencias);
                btn.disabled = false;
            })
            .catch(function(){
                body.innerHTML = '<p style="color:var(--primary-red);padding:20px;">Error de conexión</p>';
            });
        }

        function cerrarModalEditarBitacora() {
            document.getElementById('modalEditarBitacora').style.display = 'none';
            document.body.style.overflow = '';
        }

        function renderEditarBitacoraModal(res) {
            var bit = res.bitacora;
            var evidencias = res.evidencias || [];
            var html = '<div class="mci-error" id="mebBitError" style="display:none;"><span class="material-symbols-rounded">error</span><span id="mebBitErrorText"></span></div>';
            html += '<form id="formEditarBitacora" enctype="multipart/form-data">';
            html += '<input type="hidden" name="bitacora_id" value="' + bit.id + '">';

            // Grupo
            html += '<div class="mci-form-group"><label class="mci-label required">Grupo</label>';
            html += '<select name="grupo_id" id="mebGrupoSelect" class="mci-select" onchange="mebCargarEstudiantes(this.value, null)">';
            html += '<option value="">Seleccionar grupo</option>';
            GRUPOS_BITACORA.forEach(function(g) {
                html += '<option value="' + g.id + '"' + (g.id == bit.grupo_id ? ' selected' : '') + '>' + htmlEsc(g.curso_nombre + ' - ' + g.nombre) + '</option>';
            });
            html += '</select></div>';

            // Fecha + Título
            html += '<div class="mci-grid">';
            html += '<div class="mci-form-group"><label class="mci-label required">Fecha de Clase</label>';
            html += '<input type="date" name="fecha_clase" class="mci-input" value="' + htmlEsc(bit.fecha_clase || '') + '" required></div>';
            html += '<div class="mci-form-group"><label class="mci-label required">Título de la Clase</label>';
            html += '<input type="text" name="titulo" class="mci-input" value="' + htmlEsc(bit.titulo || '') + '" required></div>';
            html += '</div>';

            // Horas
            html += '<div class="mci-grid">';
            html += '<div class="mci-form-group"><label class="mci-label required">Hora Inicio</label>';
            html += '<input type="time" name="hora_inicio" class="mci-input" value="' + htmlEsc((bit.hora_inicio || '').slice(0,5)) + '" required></div>';
            html += '<div class="mci-form-group"><label class="mci-label required">Hora Fin</label>';
            html += '<input type="time" name="hora_fin" class="mci-input" value="' + htmlEsc((bit.hora_fin || '').slice(0,5)) + '" required></div>';
            html += '</div>';

            // Temas
            html += '<div class="mci-form-group"><label class="mci-label required">Temas Tratados</label>';
            html += '<input type="text" name="temas_tratados" class="mci-input" value="' + htmlEsc(bit.temas_tratados || '') + '" required>';
            html += '<small style="color:var(--text-secondary);font-size:.8rem;">Separa los temas con comas</small></div>';

            // Descripción
            html += '<div class="mci-form-group"><label class="mci-label required">Descripción de la Clase</label>';
            html += '<textarea name="descripcion_clase" class="mci-textarea" required>' + htmlEsc(bit.descripcion_clase || '') + '</textarea></div>';

            // Observaciones
            html += '<div class="mci-form-group"><label class="mci-label">Observaciones</label>';
            html += '<textarea name="observaciones" class="mci-textarea">' + htmlEsc(bit.observaciones || '') + '</textarea></div>';

            // Compromisos
            html += '<div class="mci-form-group"><label class="mci-label">Compromisos para la Próxima Clase</label>';
            html += '<textarea name="compromisos" class="mci-textarea">' + htmlEsc(bit.compromisos_proxima_clase || '') + '</textarea></div>';

            // Asistencia
            html += '<div class="mci-section-title"><span class="material-symbols-rounded">how_to_reg</span> Registro de Asistencia</div>';
            html += '<div id="mebEstContainer"><p style="color:var(--text-secondary);font-size:.85rem;">Cargando estudiantes...</p></div>';

            // Evidencias existentes
            if (evidencias.length > 0) {
                html += '<div class="mci-section-title"><span class="material-symbols-rounded">collections</span> Evidencias Actuales</div>';
                html += '<div class="meb-evidencias-grid" id="mebEvidenciasGrid">';
                evidencias.forEach(function(ev) {
                    html += '<div class="meb-ev-item" id="mebEv_' + ev.id + '">';
                    html += '<img src="../../../assets/uploads/bitacoras/evidencias/' + htmlEsc(ev.archivo) + '" alt="Evidencia" onerror="this.style.display=\'none\'">';
                    html += '<button type="button" class="meb-ev-delete" onclick="mebEliminarEvidencia(' + ev.id + ')" title="Eliminar">';
                    html += '<span class="material-symbols-rounded" style="font-size:16px;">delete</span></button>';
                    if (ev.descripcion) html += '<div class="meb-ev-caption">' + htmlEsc(ev.descripcion) + '</div>';
                    html += '</div>';
                });
                html += '</div>';
            }

            // Nuevas evidencias
            html += '<div class="mci-section-title"><span class="material-symbols-rounded">add_photo_alternate</span> Agregar Evidencias (Opcional)</div>';
            html += '<div class="bit-upload-zone">';
            html += '<input type="file" name="evidencias[]" id="mebEvidenciasInput" accept="image/*" multiple>';
            html += '<span class="material-symbols-rounded" style="font-size:40px;color:var(--primary-blue);">add_photo_alternate</span>';
            html += '<div style="color:var(--text-primary);font-size:.9rem;margin-top:8px;">Arrastra fotos aquí o haz clic</div>';
            html += '<div style="color:var(--text-secondary);font-size:.8rem;">JPG, PNG, GIF</div>';
            html += '</div>';
            html += '<div id="mebFilesList"></div>';

            html += '</form>';
            return html;
        }

        function mebCargarEstudiantes(grupoId, asistencias) {
            var container = document.getElementById('mebEstContainer');
            if (!container) return;
            if (!grupoId) {
                container.innerHTML = '<p style="color:var(--text-secondary);font-size:.85rem;">Selecciona un grupo</p>';
                return;
            }
            container.innerHTML = '<p style="color:var(--text-secondary);font-size:.85rem;">Cargando estudiantes...</p>';

            var asistMap = {};
            if (asistencias && asistencias.length > 0) {
                asistencias.forEach(function(a){ asistMap[a.estudiante_id] = a; });
            }

            fetch('bitacoras/get_estudiantes.php?grupo_id=' + grupoId)
                .then(function(r){ return r.json(); })
                .then(function(data){
                    if (data.estudiantes && data.estudiantes.length > 0) {
                        var html = '';
                        data.estudiantes.forEach(function(est) {
                            var a      = asistMap[est.id] || {};
                            var estado = a.estado || 'presente';
                            var obs    = a.observacion || '';
                            html += '<div class="bit-student-item">';
                            html += '<div class="bit-student-name">' + htmlEsc(est.nombre) + '</div>';
                            html += '<select name="asistencias[' + est.id + ']" class="bit-att-select">';
                            ['presente','ausente','justificado','tardanza'].forEach(function(v){
                                var label = v.charAt(0).toUpperCase() + v.slice(1);
                                html += '<option value="' + v + '"' + (estado === v ? ' selected' : '') + '>' + label + '</option>';
                            });
                            html += '</select>';
                            html += '<input type="text" name="asistencia_obs[' + est.id + ']" class="bit-obs-input" placeholder="Observación (opcional)" value="' + htmlEsc(obs) + '">';
                            html += '</div>';
                        });
                        container.innerHTML = html;
                    } else {
                        container.innerHTML = '<p style="color:var(--text-secondary);font-size:.85rem;">No hay estudiantes matriculados en este grupo</p>';
                    }
                })
                .catch(function(){
                    container.innerHTML = '<p style="color:var(--primary-red);font-size:.85rem;">Error al cargar estudiantes</p>';
                });
        }

        function mebEliminarEvidencia(evidenciaId) {
            var item = document.getElementById('mebEv_' + evidenciaId);
            if (!item) return;
            item.style.opacity = '0.3';
            if (!document.getElementById('mebDelEv_' + evidenciaId)) {
                var input = document.createElement('input');
                input.type  = 'hidden';
                input.name  = 'eliminar_evidencias[]';
                input.value = evidenciaId;
                input.id    = 'mebDelEv_' + evidenciaId;
                document.getElementById('formEditarBitacora').appendChild(input);
            }
        }

        function mebMostrarArchivos(files) {
            var filesList = document.getElementById('mebFilesList');
            if (!filesList) return;
            filesList.innerHTML = '';
            Array.from(files).forEach(function(file, index) {
                filesList.innerHTML += '<div class="bit-file-item"><span class="material-symbols-rounded">image</span><span>' + htmlEsc(file.name) + '</span><input type="text" name="evidencia_desc[' + index + ']" class="bit-obs-input" placeholder="Descripción (opcional)"></div>';
            });
        }

        function submitEditarBitacora() {
            var form   = document.getElementById('formEditarBitacora');
            if (!form) return;
            var errEl  = document.getElementById('mebBitError');
            var errTxt = document.getElementById('mebBitErrorText');
            var btn    = document.getElementById('mebBitSubmitBtn');
            if (errEl) errEl.style.display = 'none';
            btn.disabled = true;
            btn.innerHTML = '<span class="material-symbols-rounded" style="animation:mvSpin 1s linear infinite;">refresh</span> Guardando...';

            fetch('bitacoras/editar.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: new FormData(form)
            })
            .then(function(r){ return r.json(); })
            .then(function(res){
                btn.disabled = false;
                btn.innerHTML = '<span class="material-symbols-rounded">save</span> Guardar ';
                if (res.success) {
                    cerrarModalEditarBitacora();
                    mostrarAlertaInst('Bitácora actualizada correctamente', 'success');
                    setTimeout(function(){ window.location.reload(); }, 1500);
                } else {
                    if (errEl && errTxt) { errTxt.textContent = res.error || 'Error al guardar'; errEl.style.display = 'flex'; }
                }
            })
            .catch(function(){
                btn.disabled = false;
                btn.innerHTML = '<span class="material-symbols-rounded">save</span> Guardar ';
                if (errEl && errTxt) { errTxt.textContent = 'Error de conexión'; errEl.style.display = 'flex'; }
            });
        }

        // ── Modal Eliminar Bitácora ────────────────────────────────────────
        function abrirModalEliminarBitacora(id, titulo) {
            document.getElementById('melbId').value = id;
            document.getElementById('melbTitulo').textContent = titulo;
            var btn = document.getElementById('melbConfirmBtn');
            btn.disabled = false;
            btn.innerHTML = '<span class="material-symbols-rounded">delete</span> Eliminar';
            document.getElementById('modalEliminarBitacora').style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function cerrarModalEliminarBitacora() {
            document.getElementById('modalEliminarBitacora').style.display = 'none';
            document.body.style.overflow = '';
        }

        function submitEliminarBitacora() {
            var id  = document.getElementById('melbId').value;
            var btn = document.getElementById('melbConfirmBtn');
            btn.disabled = true;
            btn.innerHTML = '<span class="material-symbols-rounded" style="animation:mvSpin 1s linear infinite;">refresh</span> Eliminando...';

            var data = new FormData();
            data.append('bitacora_id', id);

            fetch('bitacoras/eliminar.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: data
            })
            .then(function(r){ return r.json(); })
            .then(function(res){
                cerrarModalEliminarBitacora();
                if (res.success) {
                    mostrarAlertaInst('Bitácora eliminada correctamente', 'success');
                    setTimeout(function(){ window.location.reload(); }, 1500);
                } else {
                    mostrarAlertaInst(res.error || 'Error al eliminar', 'error');
                }
            })
            .catch(function(){
                cerrarModalEliminarBitacora();
                mostrarAlertaInst('Error de conexión', 'error');
            });
        }
    </script>
    <?php endif; ?>
</body>
</html>