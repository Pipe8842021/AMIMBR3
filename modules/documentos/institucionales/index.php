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

// ── Filtros de búsqueda ───────────────────────────────────────────────────────
$search           = isset($_GET['search'])    ? trim($_GET['search'])    : '';
$tipo_filter      = isset($_GET['tipo'])      ? $_GET['tipo']            : 'todos';
$categoria_filter = isset($_GET['categoria']) ? $_GET['categoria']       : 'todas';

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
                <button class="btn-secondary" onclick="window.location.href='bitacoras/crear.php'">
                    <span class="material-symbols-rounded">menu_book</span>
                    Nueva Bitácora
                </button>
                <?php endif; ?>
                <?php if ($rol === 'admin'): ?>
                <button class="btn-primary" onclick="window.location.href='crear.php'">
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

                <select id="categoriaFilter" class="filter-select">
                    <option value="todas" <?php echo $categoria_filter === 'todas' ? 'selected' : ''; ?>>Todas las categorías</option>
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
                                            onclick="window.location.href='bitacoras/ver.php?id=<?php echo $doc['id']; ?>'">
                                        <span class="material-symbols-rounded">visibility</span> Ver
                                    </button>
                                <?php else: ?>
                                    <button class="btn-action"
                                            onclick="window.location.href='ver.php?tipo=<?php echo $doc['tipo']; ?>&id=<?php echo $doc['id']; ?>'">
                                        <span class="material-symbols-rounded">visibility</span> Ver
                                    </button>
                                <?php endif; ?>

                                <?php if ($rol === 'admin'): ?>
                                    <?php $del_url = $doc['tipo'] === 'bitacora'
                                        ? "bitacoras/eliminar.php?id={$doc['id']}"
                                        : "eliminar.php?tipo={$doc['tipo']}&id={$doc['id']}"; ?>
                                    <button class="btn-action-danger"
                                            onclick="if(confirm('¿Eliminar este documento?')) window.location.href='<?php echo $del_url; ?>'">
                                        <span class="material-symbols-rounded">delete</span>
                                    </button>
                                <?php elseif ($rol === 'profesor' && $doc['tipo'] === 'bitacora'): ?>
                                    <!-- Profesor puede eliminar sus propias bitácoras -->
                                    <button class="btn-action-danger"
                                            onclick="if(confirm('¿Eliminar esta bitácora?')) window.location.href='bitacoras/eliminar.php?id=<?php echo $doc['id']; ?>'">
                                        <span class="material-symbols-rounded">delete</span>
                                    </button>
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

    <script>
        function applyFilters() {
            const url = new URL(window.location.href);
            url.searchParams.set('search',    document.getElementById('searchInput').value);
            url.searchParams.set('tipo',      document.getElementById('tipoFilter').value);
            url.searchParams.set('categoria', document.getElementById('categoriaFilter').value);
            window.location.href = url.toString();
        }

        document.getElementById('searchInput').addEventListener('keypress', e => {
            if (e.key === 'Enter') applyFilters();
        });
        document.getElementById('tipoFilter').addEventListener('change', applyFilters);
        document.getElementById('categoriaFilter').addEventListener('change', applyFilters);

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
</body>
</html>