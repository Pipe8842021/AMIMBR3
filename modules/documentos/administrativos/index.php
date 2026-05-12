<?php
/**
 * Archivos Administrativos
 * Gestión de documentos confidenciales y administrativos
 */

// Incluir configuración de sesión y base de datos
require_once '../../../config/session.php';
require_once '../../../config/database.php';

// Verificar autenticación
require_once '../../../includes/auth_check.php';

// Verificar que no sea estudiante
if ($_SESSION['user_rol'] === 'estudiante') {
    header("Location: ../../../modules/dashboard/");
    exit;
}

// Obtener datos del usuario actual
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

// Obtener filtros de búsqueda
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$categoria_filter = isset($_GET['categoria']) ? $_GET['categoria'] : 'todos';
$tipo_filter = isset($_GET['tipo']) ? $_GET['tipo'] : 'todas';
$fecha_filter = isset($_GET['fecha']) ? $_GET['fecha'] : 'todos';

// Construir consulta base según el rol del usuario
$sql = "SELECT da.*, u.nombre as subido_por_nombre 
        FROM documentos_administrativos da
        LEFT JOIN usuarios u ON da.subido_por = u.id
        WHERE da.estado = 'activo'";

// Aplicar filtros de visibilidad según el rol
if ($user['rol'] === 'profesor') {
    $sql .= " AND (da.visibilidad IN ('profesores', 'todos_excepto_estudiantes') 
              OR (da.visibilidad = 'profesor_especifico' AND da.profesor_especifico_id = :user_id))";
}

// Aplicar filtro de búsqueda
if ($search !== '') {
    $sql .= " AND (da.titulo LIKE :search OR da.descripcion LIKE :search)";
}

// Aplicar filtro de categoría
if ($categoria_filter !== 'todos') {
    $sql .= " AND da.categoria = :categoria";
}

// Aplicar filtro de tipo de archivo
if ($tipo_filter !== 'todas') {
    $sql .= " AND da.tipo_archivo = :tipo";
}

// Aplicar filtro de fecha
switch ($fecha_filter) {
    case 'hoy':
        $sql .= " AND DATE(da.fecha_creacion) = CURDATE()";
        break;
    case 'semana':
        $sql .= " AND YEARWEEK(da.fecha_creacion) = YEARWEEK(NOW())";
        break;
    case 'mes':
        $sql .= " AND MONTH(da.fecha_creacion) = MONTH(NOW()) AND YEAR(da.fecha_creacion) = YEAR(NOW())";
        break;
}

$sql .= " ORDER BY da.fecha_creacion DESC";

try {
    $stmt = $pdo->prepare($sql);
    
    // Bind parámetros
    if ($user['rol'] === 'profesor') {
        $stmt->bindValue(':user_id', $user['id'], PDO::PARAM_INT);
    }
    if ($search !== '') {
        $stmt->bindValue(':search', "%$search%", PDO::PARAM_STR);
    }
    if ($categoria_filter !== 'todos') {
        $stmt->bindValue(':categoria', $categoria_filter, PDO::PARAM_STR);
    }
    if ($tipo_filter !== 'todas') {
        $stmt->bindValue(':tipo', $tipo_filter, PDO::PARAM_STR);
    }
    
    $stmt->execute();
    $documentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener estadísticas
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM documentos_administrativos WHERE estado = 'activo'");
    $stmt->execute();
    $total_archivos = $stmt->fetch()['total'];
    
    // Contar confidenciales (solo admin y profesores específicos)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total 
        FROM documentos_administrativos 
        WHERE estado = 'activo' 
        AND visibilidad IN ('solo_admin', 'profesor_especifico')
    ");
    $stmt->execute();
    $confidenciales = $stmt->fetch()['total'];
    
    // Contar por tipo de archivo
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total 
        FROM documentos_administrativos 
        WHERE estado = 'activo' AND tipo_archivo = 'pdf'
    ");
    $stmt->execute();
    $documentos_pdf = $stmt->fetch()['total'];
    
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total 
        FROM documentos_administrativos 
        WHERE estado = 'activo' AND tipo_archivo = 'excel'
    ");
    $stmt->execute();
    $hojas_calculo = $stmt->fetch()['total'];
    
} catch (PDOException $e) {
    error_log("Error obteniendo documentos: " . $e->getMessage());
    $documentos = [];
    $total_archivos = 0;
    $confidenciales = 0;
    $documentos_pdf = 0;
    $hojas_calculo = 0;
}

// Obtener lista de profesores (para el modal de crear)
$profesores = [];
if ($user['rol'] === 'admin') {
    try {
        $stmt = $pdo->query("SELECT id, nombre FROM usuarios WHERE rol = 'profesor' AND estado = 'activo' ORDER BY nombre");
        $profesores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $profesores = [];
    }
}

// Mensaje de éxito tras una acción
$success_msg = '';
if (isset($_GET['success'])) {
    $mensajes = [
        'documento_creado'      => 'Documento creado exitosamente.',
        'documento_actualizado' => 'Documento actualizado exitosamente.',
        'documento_eliminado'   => 'Documento eliminado exitosamente.',
    ];
    $success_msg = $mensajes[$_GET['success']] ?? '';
}

// Función para obtener icono según tipo de archivo
function icono_archivo($tipo) {
    $iconos = [
        'pdf' => ['icono' => 'picture_as_pdf', 'color' => '#ff6d00'],
        'excel' => ['icono' => 'table_chart', 'color' => '#4ec336'],
        'word' => ['icono' => 'description', 'color' => '#1479b0'],
        'imagen' => ['icono' => 'image', 'color' => '#e9e93e'],
        'otro' => ['icono' => 'insert_drive_file', 'color' => '#94a3b8']
    ];
    return $iconos[$tipo] ?? $iconos['otro'];
}

// Función para obtener badge de categoría
function badge_categoria($categoria) {
    $badges = [
        'informes' => ['texto' => 'Informe', 'clase' => 'info'],
        'facturas' => ['texto' => 'Factura', 'clase' => 'success'],
        'contratos' => ['texto' => 'Contrato', 'clase' => 'primary'],
        'nominas' => ['texto' => 'Nómina', 'clase' => 'warning'],
        'presupuestos' => ['texto' => 'Presupuesto', 'clase' => 'secondary'],
        'legal' => ['texto' => 'Legal', 'clase' => 'danger'],
        'otro' => ['texto' => 'Otro', 'clase' => 'muted']
    ];
    return $badges[$categoria] ?? $badges['otro'];
}

// Función para formatear tamaño de archivo
function formatear_tamano($bytes) {
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' B';
    }
}

// Función para formatear fecha
function formatear_fecha($fecha) {
    $timestamp = strtotime($fecha);
    $hoy = strtotime('today');
    $ayer = strtotime('yesterday');
    
    if ($timestamp >= $hoy) {
        return 'Hoy';
    } elseif ($timestamp >= $ayer) {
        return 'Ayer';
    } else {
        $meses = ['', 'Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
        return date('d', $timestamp) . ' ' . $meses[date('n', $timestamp)] . ' ' . date('Y', $timestamp);
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Archivos Administrativos - Amimbré</title>
    <link rel="shortcut icon" href="../../../assets/img/3.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0" />
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="../../../assets/css/colores.css">
    <link rel="stylesheet" href="../../../assets/css/style-documentos-administrativos.css">
    <script>
        (function() {
            const theme = localStorage.getItem('amimbre-theme');
            if (theme === 'light') {
                document.documentElement.setAttribute('data-theme', 'light');
            }
        })();
    </script>
</head>
<body>
    <!-- Include Header/Sidebar -->
    <?php require_once '../../../includes/header.php'; ?>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Header -->
        <div class="page-header">
            <div class="header-left">
                <button class="btn-back" onclick="window.history.back()">
                    <span class="material-symbols-rounded">arrow_back</span>
                </button>
                <div class="header-info">
                    <h1>Archivos Administrativos</h1>
                    <p>Gestiona documentos confidenciales y administrativos</p>
                </div>
            </div>
            <?php if ($user['rol'] === 'admin'): ?>
            <button class="btn-primary" onclick="abrirModalCrear()">
                <span class="material-symbols-rounded">add</span>
                Nuevo Archivo
            </button>
            <?php endif; ?>
        </div>

        <?php if ($success_msg): ?>
        <div class="alert alert-success" id="alertaExito" style="margin-bottom:24px">
            <span class="material-symbols-rounded">check_circle</span>
            <span><?php echo htmlspecialchars($success_msg); ?></span>
            <button onclick="this.parentElement.style.display='none'" style="margin-left:auto;background:none;border:none;cursor:pointer;color:inherit;display:flex;align-items:center">
                <span class="material-symbols-rounded" style="font-size:20px">close</span>
            </button>
        </div>
        <script>setTimeout(function(){var a=document.getElementById('alertaExito');if(a)a.style.display='none';},4000);</script>
        <?php endif; ?>

        <!-- Estadísticas -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background: var(--subtle-blue); color: var(--primary-blue);">
                    <span class="material-symbols-rounded">folder</span>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Total Archivos</div>
                    <div class="stat-value"><?php echo $total_archivos; ?></div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: var(--subtle-orange); color: var(--primary-orange);">
                    <span class="material-symbols-rounded">lock</span>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Confidenciales</div>
                    <div class="stat-value"><?php echo $confidenciales; ?></div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: var(--subtle-yellow); color: var(--primary-yellow);">
                    <span class="material-symbols-rounded">picture_as_pdf</span>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Documentos PDF</div>
                    <div class="stat-value"><?php echo $documentos_pdf; ?></div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: var(--subtle-green); color: var(--primary-green);">
                    <span class="material-symbols-rounded">table_chart</span>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Hojas de Cálculo</div>
                    <div class="stat-value"><?php echo $hojas_calculo; ?></div>
                </div>
            </div>
        </div>

        <!-- Filtros y Búsqueda -->
        <div class="filters-container">
            <div class="search-box">
                <span class="material-symbols-rounded">search</span>
                <input type="text" id="searchInput" placeholder="Buscar documentos..."
                       value="<?php echo htmlspecialchars($search); ?>">
            </div>

            <div class="filter-group">
                <select id="categoriaFilter" class="filter-select">
                    <option value="todos" <?php echo $categoria_filter === 'todos' ? 'selected' : ''; ?>>Todos los tipos</option>
                    <option value="informes" <?php echo $categoria_filter === 'informes' ? 'selected' : ''; ?>>Informes</option>
                    <option value="facturas" <?php echo $categoria_filter === 'facturas' ? 'selected' : ''; ?>>Facturas</option>
                    <option value="contratos" <?php echo $categoria_filter === 'contratos' ? 'selected' : ''; ?>>Contratos</option>
                    <option value="nominas" <?php echo $categoria_filter === 'nominas' ? 'selected' : ''; ?>>Nóminas</option>
                    <option value="presupuestos" <?php echo $categoria_filter === 'presupuestos' ? 'selected' : ''; ?>>Presupuestos</option>
                    <option value="legal" <?php echo $categoria_filter === 'legal' ? 'selected' : ''; ?>>Legal</option>
                    <option value="otro" <?php echo $categoria_filter === 'otro' ? 'selected' : ''; ?>>Otro</option>
                </select>

                <select id="tipoFilter" class="filter-select">
                    <option value="todas" <?php echo $tipo_filter === 'todas' ? 'selected' : ''; ?>>Todas</option>
                    <option value="pdf" <?php echo $tipo_filter === 'pdf' ? 'selected' : ''; ?>>PDF</option>
                    <option value="excel" <?php echo $tipo_filter === 'excel' ? 'selected' : ''; ?>>Excel</option>
                    <option value="word" <?php echo $tipo_filter === 'word' ? 'selected' : ''; ?>>Word</option>
                    <option value="imagen" <?php echo $tipo_filter === 'imagen' ? 'selected' : ''; ?>>Imagen</option>
                </select>

                <select id="fechaFilter" class="filter-select">
                    <option value="todos" <?php echo $fecha_filter === 'todos' ? 'selected' : ''; ?>>Todos</option>
                    <option value="hoy" <?php echo $fecha_filter === 'hoy' ? 'selected' : ''; ?>>Hoy</option>
                    <option value="semana" <?php echo $fecha_filter === 'semana' ? 'selected' : ''; ?>>Esta semana</option>
                    <option value="mes" <?php echo $fecha_filter === 'mes' ? 'selected' : ''; ?>>Este mes</option>
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

        <!-- Grid de Documentos -->
        <div class="documents-grid" id="documentsGrid">
            <?php if (count($documentos) > 0): ?>
                <?php foreach ($documentos as $doc): ?>
                    <?php 
                    $icono = icono_archivo($doc['tipo_archivo']);
                    $badge = badge_categoria($doc['categoria']);
                    $es_confidencial = in_array($doc['visibilidad'], ['solo_admin', 'profesor_especifico']);
                    ?>
                    <div class="document-card">
                        <!-- Header del documento -->
                        <div class="document-header">
                            <div class="document-icon" style="background: <?php echo $icono['color']; ?>20; color: <?php echo $icono['color']; ?>;">
                                <span class="material-symbols-rounded"><?php echo $icono['icono']; ?></span>
                            </div>
                            <?php if ($es_confidencial): ?>
                            <span class="badge-confidencial">
                                <span class="material-symbols-rounded">lock</span>
                            </span>
                            <?php endif; ?>
                            <div class="document-actions">
                                <button class="btn-icon" onclick="toggleMenu(event, <?php echo $doc['id']; ?>)">
                                    <span class="material-symbols-rounded">more_vert</span>
                                </button>
                                <div class="action-menu" id="menu-<?php echo $doc['id']; ?>">
                                    <a href="descargar.php?id=<?php echo $doc['id']; ?>" class="menu-item">
                                        <span class="material-symbols-rounded">download</span>
                                        Descargar
                                    </a>
                                    <a href="#" class="menu-item"
                                       onclick="abrirModalVer(<?php echo $doc['id']; ?>); return false;">
                                        <span class="material-symbols-rounded">visibility</span>
                                        Ver detalles
                                    </a>
                                    <?php if ($user['rol'] === 'admin'): ?>
                                    <a href="#" class="menu-item"
                                       data-doc="<?php echo htmlspecialchars(json_encode([
                                           'id'                   => $doc['id'],
                                           'titulo'               => $doc['titulo'],
                                           'categoria'            => $doc['categoria'],
                                           'descripcion'          => $doc['descripcion'] ?? '',
                                           'visibilidad'          => $doc['visibilidad'],
                                           'profesor_especifico_id' => $doc['profesor_especifico_id'],
                                           'nombre_archivo'       => $doc['nombre_archivo'],
                                           'tipo_archivo'         => $doc['tipo_archivo'],
                                           'tamanio_archivo'      => $doc['tamanio_archivo'] ?? 0,
                                       ]), ENT_QUOTES); ?>"
                                       onclick="abrirModalEditar(JSON.parse(this.dataset.doc)); return false;">
                                        <span class="material-symbols-rounded">edit</span>
                                        Editar
                                    </a>
                                    <a href="#" class="menu-item danger"
                                       onclick="abrirModalEliminar(<?php echo $doc['id']; ?>, '<?php echo htmlspecialchars(addslashes($doc['titulo']), ENT_QUOTES); ?>'); return false;">
                                        <span class="material-symbols-rounded">delete</span>
                                        Eliminar
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Contenido del documento -->
                        <div class="document-body">
                            <h3 class="document-title"><?php echo htmlspecialchars($doc['titulo']); ?></h3>
                            <p class="document-description">
                                <?php 
                                $desc = htmlspecialchars($doc['descripcion'] ?? 'Sin descripción');
                                echo strlen($desc) > 100 ? substr($desc, 0, 100) . '...' : $desc;
                                ?>
                            </p>
                        </div>

                        <!-- Footer del documento -->
                        <div class="document-footer">
                            <div class="document-meta">
                                <span class="badge badge-<?php echo $badge['clase']; ?>">
                                    <?php echo $badge['texto']; ?>
                                </span>
                                <span class="meta-item">
                                    <?php echo formatear_tamano($doc['tamanio_archivo'] ?? 0); ?>
                                </span>
                            </div>
                            <div class="document-info">
                                <span class="info-item">
                                    <span class="material-symbols-rounded">event</span>
                                    <?php echo formatear_fecha($doc['fecha_creacion']); ?>
                                </span>
                                <span class="info-item">
                                    <span class="material-symbols-rounded">person</span>
                                    <?php echo htmlspecialchars($doc['subido_por_nombre']); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <span class="material-symbols-rounded">folder_off</span>
                    <h3>No hay documentos</h3>
                    <p>No se encontraron documentos con los filtros seleccionados</p>
                    <?php if ($user['rol'] === 'admin'): ?>
                    <button class="btn-primary" onclick="abrirModalCrear()" style="margin: auto;" id="newDocBtn">
                        <span class="material-symbols-rounded">add</span>
                        Crear primer documento
                    </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <?php if ($user['rol'] === 'admin'): ?>
    <!-- Modal Ver Documento Administrativo -->
    <div id="modalVerDoc" class="modal">
        <div class="modal-content modal-ver-doc-content">

            <!-- Header -->
            <div class="modal-header-crear">
                <div style="display:flex;align-items:center;gap:14px;min-width:0">
                    <div id="mvd_icono" style="width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:24px;flex-shrink:0"></div>
                    <div style="min-width:0">
                        <h2 id="mvd_titulo" style="font-size:1.1rem;margin:0 0 3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"></h2>
                        <p id="mvd_subtitulo" style="font-size:0.83rem;color:var(--text-secondary);margin:0"></p>
                    </div>
                </div>
                <button class="modal-close-btn" type="button" onclick="cerrarModalVer()" title="Cerrar">
                    <span class="material-symbols-rounded">close</span>
                </button>
            </div>

            <!-- Loading -->
            <div id="mvd_loading" style="display:flex;flex-direction:column;align-items:center;justify-content:center;padding:60px 28px;gap:14px;color:var(--text-secondary)">
                <span class="material-symbols-rounded" style="font-size:2.5rem;color:var(--primary-blue);animation:mvSpin 1s linear infinite">progress_activity</span>
                <span style="font-size:0.9rem">Cargando documento...</span>
            </div>

            <!-- Contenido -->
            <div id="mvd_content" class="modal-body-scroll" style="display:none">

                <!-- Grid de info -->
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;margin-bottom:20px">
                    <div class="info-item-detail">
                        <div class="info-label">Categoría</div>
                        <div class="info-value" id="mvd_categoria"></div>
                    </div>
                    <div class="info-item-detail">
                        <div class="info-label">Tipo de Archivo</div>
                        <div class="info-value" id="mvd_tipo" style="text-transform:uppercase"></div>
                    </div>
                    <div class="info-item-detail">
                        <div class="info-label">Tamaño</div>
                        <div class="info-value" id="mvd_tamano"></div>
                    </div>
                    <div class="info-item-detail">
                        <div class="info-label">Visibilidad</div>
                        <div class="info-value" id="mvd_visibilidad"></div>
                    </div>
                    <div class="info-item-detail">
                        <div class="info-label">Subido por</div>
                        <div class="info-value" id="mvd_subido_por"></div>
                    </div>
                    <div class="info-item-detail">
                        <div class="info-label">Fecha de creación</div>
                        <div class="info-value" id="mvd_fecha" style="font-size:0.88rem"></div>
                    </div>
                </div>

                <!-- Descripción -->
                <div id="mvd_descripcion_wrap" style="margin-bottom:20px;display:none">
                    <div class="section-title-detail" style="margin-bottom:10px">
                        <span class="material-symbols-rounded">description</span>
                        Descripción
                    </div>
                    <div class="description-box" id="mvd_descripcion"></div>
                </div>

                <!-- Acceso específico -->
                <div id="mvd_profesor_wrap" style="margin-bottom:20px;display:none">
                    <div class="section-title-detail" style="margin-bottom:10px">
                        <span class="material-symbols-rounded">person</span>
                        Acceso Específico
                    </div>
                    <div class="info-item-detail">
                        <div class="info-label">Profesor Autorizado</div>
                        <div class="info-value" id="mvd_profesor"></div>
                    </div>
                </div>

                <!-- Historial -->
                <div class="section-title-detail" style="margin-bottom:10px">
                    <span class="material-symbols-rounded">history</span>
                    Historial de Accesos
                </div>
                <div id="mvd_historial"></div>

            </div>

            <!-- Footer -->
            <div id="mvd_footer" class="modal-footer-crear" style="display:none">
                <div style="display:flex;gap:10px;width:100%;align-items:center;flex-wrap:wrap">
                    <a id="mvd_btn_descargar" href="#"
                       style="display:flex;align-items:center;gap:8px;padding:10px 20px;border-radius:8px;font-family:'Poppins',sans-serif;font-size:0.9rem;font-weight:600;cursor:pointer;background:var(--subtle-green);color:var(--primary-green);border:none;text-decoration:none;transition:all 0.25s ease"
                       onmouseover="this.style.background='var(--primary-green)';this.style.color='white'"
                       onmouseout="this.style.background='var(--subtle-green)';this.style.color='var(--primary-green)'">
                        <span class="material-symbols-rounded">download</span>
                        Descargar
                    </a>
                    <div style="margin-left:auto;display:flex;gap:10px">
                        <button id="mvd_btn_editar" type="button" onclick="verAEditar()">
                            <span class="material-symbols-rounded">edit</span>
                            Editar
                        </button>
                        <button id="mvd_btn_eliminar" type="button" onclick="verAEliminar()">
                            <span class="material-symbols-rounded">delete</span>
                            Eliminar
                        </button>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- Modal Crear Documento Administrativo -->
    <div id="modalCrearDoc" class="modal">
        <div class="modal-content">

            <!-- Header -->
            <div class="modal-header-crear">
                <div>
                    <h2>Nuevo Documento Administrativo</h2>
                    <p>Sube y configura un nuevo archivo</p>
                </div>
                <button class="modal-close-btn" type="button" onclick="cerrarModalCrear()" title="Cerrar">
                    <span class="material-symbols-rounded">close</span>
                </button>
            </div>

            <!-- Body -->
            <div class="modal-body-scroll">
                <div class="alert alert-error" id="alertaErrorCrear" style="display:none">
                    <span class="material-symbols-rounded">error</span>
                    <span id="mensajeErrorCrear"></span>
                </div>

                <form id="formCrearDoc" method="POST" action="crear.php" enctype="multipart/form-data">

                    <div class="form-group">
                        <label class="form-label required">Título del Documento</label>
                        <input type="text" name="titulo" class="form-input"
                               placeholder="Ej: Informe Financiero Q4 2024" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Categoría</label>
                        <select name="categoria" class="form-select" required>
                            <option value="">Seleccionar categoría</option>
                            <option value="informes">Informe</option>
                            <option value="facturas">Factura</option>
                            <option value="contratos">Contrato</option>
                            <option value="nominas">Nómina</option>
                            <option value="presupuestos">Presupuesto</option>
                            <option value="legal">Legal</option>
                            <option value="otro">Otro</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Descripción</label>
                        <textarea name="descripcion" class="form-textarea"
                                  placeholder="Describe el contenido del documento..."></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Visibilidad</label>
                        <select name="visibilidad" id="modalVisibilidadSelect" class="form-select" required>
                            <option value="">Seleccionar visibilidad</option>
                            <option value="solo_admin">Solo Administradores</option>
                            <option value="profesores">Todos los Profesores</option>
                            <option value="profesor_especifico">Profesor Específico</option>
                            <option value="todos_excepto_estudiantes">Todos excepto Estudiantes</option>
                        </select>
                    </div>

                    <div class="form-group" id="modalProfesorField" style="display:none">
                        <label class="form-label required">Seleccionar Profesor</label>
                        <select name="profesor_especifico_id" class="form-select">
                            <option value="">Seleccionar profesor</option>
                            <?php foreach ($profesores as $profesor): ?>
                            <option value="<?php echo $profesor['id']; ?>">
                                <?php echo htmlspecialchars($profesor['nombre']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Archivo</label>
                        <div class="file-upload" id="modalFileUpload">
                            <input type="file" name="archivo" id="modalArchivoInput" required
                                   accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.jpg,.jpeg,.png">
                            <span class="material-symbols-rounded file-upload-icon">cloud_upload</span>
                            <div class="file-upload-text">Arrastra y suelta o haz clic para seleccionar</div>
                            <div class="file-upload-hint">PDF, Word, Excel o Imágenes (máx. 10MB)</div>
                        </div>
                        <div id="modalFileName" style="margin-top:12px; color:var(--primary-green); display:none;"></div>
                    </div>

                </form>
            </div>

            <!-- Footer -->
            <div class="modal-footer-crear">
                <div style="display:flex; justify-content:flex-end; gap:10px; width:100%;">
                    <button type="button" class="btn-cancelar" onclick="cerrarModalCrear()">Cancelar</button>
                    <button type="button" class="btn-primario" id="btnSubmitDoc" onclick="submitCrearDoc()">
                        <span class="material-symbols-rounded">upload</span>
                        Subir Documento
                    </button>
                </div>
            </div>

        </div>
    </div>
    <?php endif; ?>

    <?php if ($user['rol'] === 'admin'): ?>
    <!-- Modal Editar Documento Administrativo -->
    <div id="modalEditarDoc" class="modal">
        <div class="modal-content">

            <!-- Header -->
            <div class="modal-header-crear">
                <div>
                    <h2>Editar Documento</h2>
                    <p>Modifica la información del archivo</p>
                </div>
                <button class="modal-close-btn" type="button" onclick="cerrarModalEditar()" title="Cerrar">
                    <span class="material-symbols-rounded">close</span>
                </button>
            </div>

            <!-- Body -->
            <div class="modal-body-scroll">
                <div class="alert alert-error" id="alertaErrorEditar" style="display:none">
                    <span class="material-symbols-rounded">error</span>
                    <span id="mensajeErrorEditar"></span>
                </div>

                <form id="formEditarDoc" method="POST" action="editar.php" enctype="multipart/form-data">
                    <input type="hidden" name="doc_id" id="editDocId">

                    <div class="form-group">
                        <label class="form-label required">Título del Documento</label>
                        <input type="text" name="titulo" id="editTitulo" class="form-input"
                               placeholder="Ej: Informe Financiero Q4 2024" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Categoría</label>
                        <select name="categoria" id="editCategoria" class="form-select" required>
                            <option value="">Seleccionar categoría</option>
                            <option value="informes">Informe</option>
                            <option value="facturas">Factura</option>
                            <option value="contratos">Contrato</option>
                            <option value="nominas">Nómina</option>
                            <option value="presupuestos">Presupuesto</option>
                            <option value="legal">Legal</option>
                            <option value="otro">Otro</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Descripción</label>
                        <textarea name="descripcion" id="editDescripcion" class="form-textarea"
                                  placeholder="Describe el contenido del documento..."></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Visibilidad</label>
                        <select name="visibilidad" id="editVisibilidad" class="form-select" required>
                            <option value="">Seleccionar visibilidad</option>
                            <option value="solo_admin">Solo Administradores</option>
                            <option value="profesores">Todos los Profesores</option>
                            <option value="profesor_especifico">Profesor Específico</option>
                            <option value="todos_excepto_estudiantes">Todos excepto Estudiantes</option>
                        </select>
                    </div>

                    <div class="form-group" id="editProfesorField" style="display:none">
                        <label class="form-label required">Seleccionar Profesor</label>
                        <select name="profesor_especifico_id" id="editProfesorId" class="form-select">
                            <option value="">Seleccionar profesor</option>
                            <?php foreach ($profesores as $profesor): ?>
                            <option value="<?php echo $profesor['id']; ?>">
                                <?php echo htmlspecialchars($profesor['nombre']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Reemplazar Archivo <small style="font-weight:400;color:var(--text-secondary)">(opcional)</small></label>

                        <div class="current-file-info">
                            <div class="current-file-name" id="editCurrentFileName"></div>
                            <div class="current-file-meta" id="editCurrentFileMeta"></div>
                        </div>

                        <div class="form-note">
                            <span class="material-symbols-rounded" style="font-size:20px">info</span>
                            <div>Si no seleccionas un archivo nuevo, se conserva el actual.</div>
                        </div>

                        <div class="file-upload" id="editFileUpload">
                            <input type="file" name="archivo" id="editArchivoInput"
                                   accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.jpg,.jpeg,.png">
                            <span class="material-symbols-rounded file-upload-icon">cloud_upload</span>
                            <div class="file-upload-text">Arrastra y suelta o haz clic para seleccionar</div>
                            <div class="file-upload-hint">PDF, Word, Excel o Imágenes (máx. 10MB)</div>
                        </div>
                        <div id="editFileName" style="margin-top:12px; color:var(--primary-green); display:none;"></div>
                    </div>

                </form>
            </div>

            <!-- Footer -->
            <div class="modal-footer-crear">
                <div style="display:flex; justify-content:flex-end; gap:10px; width:100%;">
                    <button type="button" class="btn-cancelar" onclick="cerrarModalEditar()">Cancelar</button>
                    <button type="button" class="btn-primario" id="btnSubmitEditar" onclick="submitEditarDoc()">
                        <span class="material-symbols-rounded">save</span>
                        Guardar Cambios
                    </button>
                </div>
            </div>

        </div>
    </div>
    <?php endif; ?>

    <!-- Modal Eliminar Documento Administrativo -->
    <?php if ($user['rol'] === 'admin'): ?>
    <div id="modalEliminarDoc" class="modal">
        <div class="modal-content" style="max-width:440px">

            <div class="modal-header-crear">
                <div style="display:flex;align-items:center;gap:12px">
                    <div style="width:42px;height:42px;border-radius:10px;background:rgba(239,68,68,0.15);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                        <span class="material-symbols-rounded" style="color:#ef4444;font-size:22px">delete</span>
                    </div>
                    <div>
                        <h2>Eliminar Documento</h2>
                        <p>Esta acción no se puede deshacer</p>
                    </div>
                </div>
                <button class="modal-close-btn" type="button" onclick="cerrarModalEliminar()" title="Cerrar">
                    <span class="material-symbols-rounded">close</span>
                </button>
            </div>

            <div class="modal-body-scroll" style="padding:24px 28px">
                <p style="color:var(--text-primary);font-size:0.95rem;line-height:1.6;margin-bottom:8px">
                    ¿Estás seguro de que quieres eliminar el documento
                    <strong id="eliminarDocTitulo" style="color:var(--text-primary)"></strong>?
                </p>
                <p style="color:var(--text-secondary);font-size:0.85rem">
                    El documento quedará desactivado y dejará de aparecer en la lista.
                </p>

                <div class="alert alert-error" id="alertaErrorEliminar" style="display:none;margin-top:16px;margin-bottom:0">
                    <span class="material-symbols-rounded">error</span>
                    <span id="mensajeErrorEliminar"></span>
                </div>
            </div>

            <div class="modal-footer-crear">
                <div style="display:flex;justify-content:flex-end;gap:10px;width:100%">
                    <button type="button" class="btn-cancelar" onclick="cerrarModalEliminar()">Cancelar</button>
                    <button type="button" id="btnConfirmarEliminar" onclick="submitEliminarDoc()">
                        <span class="material-symbols-rounded">delete</span>
                        Eliminar
                    </button>
                </div>
            </div>

        </div>
    </div>
    <?php endif; ?>

    <script>
        // Función para toggle del menú de acciones
        function toggleMenu(event, docId) {
            event.stopPropagation();
            const menu = document.getElementById(`menu-${docId}`);
            
            // Cerrar todos los menús abiertos
            document.querySelectorAll('.action-menu').forEach(m => {
                if (m !== menu) m.classList.remove('active');
            });
            
            menu.classList.toggle('active');
        }

        // Cerrar menús al hacer click fuera
        document.addEventListener('click', () => {
            document.querySelectorAll('.action-menu').forEach(m => m.classList.remove('active'));
        });

        // Aplicar filtros
        function applyFilters() {
            const search = document.getElementById('searchInput').value;
            const categoria = document.getElementById('categoriaFilter').value;
            const tipo = document.getElementById('tipoFilter').value;
            const fecha = document.getElementById('fechaFilter').value;
            
            const url = new URL(window.location.href);
            url.searchParams.set('search', search);
            url.searchParams.set('categoria', categoria);
            url.searchParams.set('tipo', tipo);
            url.searchParams.set('fecha', fecha);
            
            window.location.href = url.toString();
        }

        // Event listeners para filtros
        document.getElementById('searchInput').addEventListener('keypress', (e) => {
            if (e.key === 'Enter') applyFilters();
        });

        document.getElementById('categoriaFilter').addEventListener('change', applyFilters);
        document.getElementById('tipoFilter').addEventListener('change', applyFilters);
        document.getElementById('fechaFilter').addEventListener('change', applyFilters);

        // ── Modal Ver Documento ──────────────────────────────
        let _verDocData = null;

        async function abrirModalVer(docId) {
            _verDocData = null;
            document.getElementById('mvd_loading').style.display  = 'flex';
            document.getElementById('mvd_content').style.display  = 'none';
            document.getElementById('mvd_footer').style.display   = 'none';
            document.getElementById('mvd_titulo').textContent     = '';
            document.getElementById('mvd_subtitulo').textContent  = '';
            document.getElementById('mvd_icono').innerHTML        = '';
            document.getElementById('modalVerDoc').style.display  = 'flex';

            try {
                const res  = await fetch(`ver.php?id=${docId}`, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                const data = await res.json();
                if (!data.success) { cerrarModalVer(); return; }

                const doc = data.documento;
                _verDocData = doc;

                // Header
                const ico = document.getElementById('mvd_icono');
                ico.style.background = doc.icono.color + '20';
                ico.style.color      = doc.icono.color;
                ico.innerHTML        = `<span class="material-symbols-rounded">${doc.icono.icono}</span>`;
                document.getElementById('mvd_titulo').textContent    = doc.titulo;
                document.getElementById('mvd_subtitulo').textContent =
                    `Subido por ${doc.subido_por_nombre} · ${mvdBytes(doc.tamanio_archivo)}`;

                // Grid de info
                document.getElementById('mvd_categoria').textContent  = doc.categoria_label;
                document.getElementById('mvd_tipo').textContent       = doc.tipo_archivo;
                document.getElementById('mvd_tamano').textContent     = mvdBytes(doc.tamanio_archivo);
                document.getElementById('mvd_subido_por').textContent = doc.subido_por_nombre;
                document.getElementById('mvd_fecha').textContent      = mvdFecha(doc.fecha_creacion);

                const visClases = {
                    solo_admin: 'admin', profesores: 'profesores',
                    profesor_especifico: 'especifico', todos_excepto_estudiantes: 'todos'
                };
                const visIcon = doc.visibilidad === 'solo_admin' ? 'lock' : 'visibility';
                document.getElementById('mvd_visibilidad').innerHTML =
                    `<span class="badge-visibility ${visClases[doc.visibilidad] || ''}">
                        <span class="material-symbols-rounded" style="font-size:15px">${visIcon}</span>
                        ${doc.visibilidad_label}
                    </span>`;

                // Descripción
                const descWrap = document.getElementById('mvd_descripcion_wrap');
                if (doc.descripcion) {
                    document.getElementById('mvd_descripcion').textContent = doc.descripcion;
                    descWrap.style.display = 'block';
                } else {
                    descWrap.style.display = 'none';
                }

                // Profesor específico
                const profWrap = document.getElementById('mvd_profesor_wrap');
                if (doc.visibilidad === 'profesor_especifico' && doc.profesor_nombre) {
                    document.getElementById('mvd_profesor').textContent =
                        doc.profesor_nombre + (doc.profesor_email ? ` (${doc.profesor_email})` : '');
                    profWrap.style.display = 'block';
                } else {
                    profWrap.style.display = 'none';
                }

                // Historial
                const hist = document.getElementById('mvd_historial');
                if (data.historial.length > 0) {
                    hist.className = 'access-timeline';
                    hist.innerHTML = data.historial.map(h => `
                        <div class="timeline-item">
                            <div class="timeline-icon ${h.accion === 'descarga' ? 'download' : 'view'}">
                                <span class="material-symbols-rounded">${h.accion === 'descarga' ? 'download' : 'visibility'}</span>
                            </div>
                            <div class="timeline-content">
                                <div class="timeline-action">
                                    ${h.usuario_nombre}
                                    ${h.accion === 'descarga' ? 'descargó' : 'visualizó'} el documento
                                    <span style="color:var(--text-secondary);font-weight:400">(${h.usuario_rol.charAt(0).toUpperCase()+h.usuario_rol.slice(1)})</span>
                                </div>
                                <div class="timeline-time">${mvdTiempo(h.fecha_acceso)}</div>
                            </div>
                        </div>`).join('');
                } else {
                    hist.className = '';
                    hist.innerHTML = `<div class="empty-timeline">
                        <span class="material-symbols-rounded">history</span>
                        <div>No hay historial de accesos aún</div>
                    </div>`;
                }

                // Botones footer
                document.getElementById('mvd_btn_descargar').href = `descargar.php?id=${doc.id}`;
                const esAdmin = <?php echo $user['rol'] === 'admin' ? 'true' : 'false'; ?>;
                document.getElementById('mvd_btn_editar').style.display  = esAdmin ? 'flex' : 'none';
                document.getElementById('mvd_btn_eliminar').style.display = esAdmin ? 'flex' : 'none';

                document.getElementById('mvd_loading').style.display = 'none';
                document.getElementById('mvd_content').style.display = 'block';
                document.getElementById('mvd_footer').style.display  = 'block';

            } catch (err) {
                cerrarModalVer();
            }
        }

        function cerrarModalVer() {
            document.getElementById('modalVerDoc').style.display = 'none';
            _verDocData = null;
        }

        function verAEditar() {
            if (!_verDocData) return;
            const doc = _verDocData;
            cerrarModalVer();
            abrirModalEditar(doc);
        }

        function verAEliminar() {
            if (!_verDocData) return;
            const id     = _verDocData.id;
            const titulo = _verDocData.titulo;
            cerrarModalVer();
            abrirModalEliminar(id, titulo);
        }

        document.getElementById('modalVerDoc').addEventListener('click', function(e) {
            if (e.target === this) cerrarModalVer();
        });

        // Helpers del modal ver
        function mvdBytes(b) {
            if (b >= 1048576) return (b / 1048576).toFixed(2) + ' MB';
            if (b >= 1024)    return (b / 1024).toFixed(2)    + ' KB';
            return b + ' B';
        }
        function mvdFecha(f) {
            const meses = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
            const d = new Date(f);
            return `${d.getDate()} de ${meses[d.getMonth()]} de ${d.getFullYear()}`;
        }
        function mvdTiempo(f) {
            const diff = Math.floor((new Date() - new Date(f)) / 1000);
            if (diff < 60)    return 'Hace unos segundos';
            if (diff < 3600)  return `Hace ${Math.floor(diff/60)} minuto${Math.floor(diff/60)>1?'s':''}`;
            if (diff < 86400) return `Hace ${Math.floor(diff/3600)} hora${Math.floor(diff/3600)>1?'s':''}`;
            return `Hace ${Math.floor(diff/86400)} día${Math.floor(diff/86400)>1?'s':''}`;
        }

        // ── Modal Crear Documento ────────────────────────────
        <?php if ($user['rol'] === 'admin'): ?>
        function abrirModalCrear() {
            document.getElementById('modalCrearDoc').style.display = 'flex';
            document.getElementById('formCrearDoc').reset();
            document.getElementById('alertaErrorCrear').style.display = 'none';
            document.getElementById('modalFileName').style.display = 'none';
            document.getElementById('modalProfesorField').style.display = 'none';
        }

        function cerrarModalCrear() {
            document.getElementById('modalCrearDoc').style.display = 'none';
        }

        document.getElementById('modalCrearDoc').addEventListener('click', function(e) {
            if (e.target === this) cerrarModalCrear();
        });

        document.getElementById('modalVisibilidadSelect').addEventListener('change', function() {
            document.getElementById('modalProfesorField').style.display =
                this.value === 'profesor_especifico' ? 'block' : 'none';
        });

        document.getElementById('modalArchivoInput').addEventListener('change', function() {
            const fileNameEl = document.getElementById('modalFileName');
            if (this.files.length > 0) {
                fileNameEl.textContent = '✓ ' + this.files[0].name;
                fileNameEl.style.display = 'block';
            } else {
                fileNameEl.style.display = 'none';
            }
        });

        const modalFileUpload = document.getElementById('modalFileUpload');
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(ev => {
            modalFileUpload.addEventListener(ev, e => { e.preventDefault(); e.stopPropagation(); });
        });
        ['dragenter', 'dragover'].forEach(ev => {
            modalFileUpload.addEventListener(ev, () => {
                modalFileUpload.style.borderColor = 'var(--primary-blue)';
                modalFileUpload.style.background = 'var(--dark-bg)';
            });
        });
        ['dragleave', 'drop'].forEach(ev => {
            modalFileUpload.addEventListener(ev, () => {
                modalFileUpload.style.borderColor = 'var(--border-color)';
                modalFileUpload.style.background = 'var(--hover-bg)';
            });
        });
        modalFileUpload.addEventListener('drop', e => {
            document.getElementById('modalArchivoInput').files = e.dataTransfer.files;
            document.getElementById('modalArchivoInput').dispatchEvent(new Event('change'));
        });

        async function submitCrearDoc() {
            const form = document.getElementById('formCrearDoc');
            const btn  = document.getElementById('btnSubmitDoc');
            btn.disabled = true;
            btn.innerHTML = '<span class="material-symbols-rounded">hourglass_top</span> Subiendo...';

            try {
                const res  = await fetch('crear.php', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: new FormData(form)
                });
                const data = await res.json();

                if (data.success) {
                    cerrarModalCrear();
                    window.location.href = 'index.php?success=documento_creado';
                } else {
                    document.getElementById('mensajeErrorCrear').textContent = data.error;
                    document.getElementById('alertaErrorCrear').style.display = 'flex';
                }
            } catch (err) {
                document.getElementById('mensajeErrorCrear').textContent = 'Error de conexión. Intenta nuevamente.';
                document.getElementById('alertaErrorCrear').style.display = 'flex';
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<span class="material-symbols-rounded">upload</span> Subir Documento';
            }
        }
        // ── Modal Editar Documento ───────────────────────────
        function abrirModalEditar(doc) {
            document.getElementById('editDocId').value        = doc.id;
            document.getElementById('editTitulo').value       = doc.titulo;
            document.getElementById('editCategoria').value    = doc.categoria;
            document.getElementById('editDescripcion').value  = doc.descripcion;
            document.getElementById('editVisibilidad').value  = doc.visibilidad;

            const profesorField = document.getElementById('editProfesorField');
            if (doc.visibilidad === 'profesor_especifico') {
                profesorField.style.display = 'block';
                document.getElementById('editProfesorId').value = doc.profesor_especifico_id || '';
            } else {
                profesorField.style.display = 'none';
            }

            // Archivo actual
            const bytes = doc.tamanio_archivo;
            const size  = bytes >= 1048576 ? (bytes / 1048576).toFixed(2) + ' MB'
                        : bytes >= 1024    ? (bytes / 1024).toFixed(2)    + ' KB'
                        : bytes + ' B';
            document.getElementById('editCurrentFileName').innerHTML =
                `<span class="material-symbols-rounded" style="vertical-align:middle;font-size:18px">insert_drive_file</span> Archivo actual: ${doc.nombre_archivo}`;
            document.getElementById('editCurrentFileMeta').textContent =
                `Tipo: ${doc.tipo_archivo.toUpperCase()} · Tamaño: ${size}`;

            // Reset input archivo y error
            document.getElementById('editArchivoInput').value    = '';
            document.getElementById('editFileName').style.display = 'none';
            document.getElementById('alertaErrorEditar').style.display = 'none';

            document.getElementById('modalEditarDoc').style.display = 'flex';
        }

        function cerrarModalEditar() {
            document.getElementById('modalEditarDoc').style.display = 'none';
        }

        document.getElementById('modalEditarDoc').addEventListener('click', function(e) {
            if (e.target === this) cerrarModalEditar();
        });

        document.getElementById('editVisibilidad').addEventListener('change', function() {
            document.getElementById('editProfesorField').style.display =
                this.value === 'profesor_especifico' ? 'block' : 'none';
        });

        document.getElementById('editArchivoInput').addEventListener('change', function() {
            const el = document.getElementById('editFileName');
            if (this.files.length > 0) {
                el.textContent    = '✓ Nuevo archivo: ' + this.files[0].name;
                el.style.display  = 'block';
            } else {
                el.style.display = 'none';
            }
        });

        const editFileUpload = document.getElementById('editFileUpload');
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(ev => {
            editFileUpload.addEventListener(ev, e => { e.preventDefault(); e.stopPropagation(); });
        });
        ['dragenter', 'dragover'].forEach(ev => {
            editFileUpload.addEventListener(ev, () => {
                editFileUpload.style.borderColor = 'var(--primary-blue)';
                editFileUpload.style.background  = 'var(--dark-bg)';
            });
        });
        ['dragleave', 'drop'].forEach(ev => {
            editFileUpload.addEventListener(ev, () => {
                editFileUpload.style.borderColor = 'var(--border-color)';
                editFileUpload.style.background  = 'var(--hover-bg)';
            });
        });
        editFileUpload.addEventListener('drop', e => {
            document.getElementById('editArchivoInput').files = e.dataTransfer.files;
            document.getElementById('editArchivoInput').dispatchEvent(new Event('change'));
        });

        async function submitEditarDoc() {
            const form = document.getElementById('formEditarDoc');
            const btn  = document.getElementById('btnSubmitEditar');
            btn.disabled = true;
            btn.innerHTML = '<span class="material-symbols-rounded">hourglass_top</span> Guardando...';

            try {
                const res  = await fetch('editar.php', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: new FormData(form)
                });
                const data = await res.json();

                if (data.success) {
                    cerrarModalEditar();
                    window.location.href = 'index.php?success=documento_actualizado';
                } else {
                    document.getElementById('mensajeErrorEditar').textContent = data.error;
                    document.getElementById('alertaErrorEditar').style.display = 'flex';
                }
            } catch (err) {
                document.getElementById('mensajeErrorEditar').textContent = 'Error de conexión. Intenta nuevamente.';
                document.getElementById('alertaErrorEditar').style.display = 'flex';
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<span class="material-symbols-rounded">save</span> Guardar Cambios';
            }
        }

        // ── Modal Eliminar Documento ─────────────────────────
        let _eliminarDocId = null;

        function abrirModalEliminar(id, titulo) {
            _eliminarDocId = id;
            document.getElementById('eliminarDocTitulo').textContent = '"' + titulo + '"';
            document.getElementById('alertaErrorEliminar').style.display = 'none';
            document.getElementById('modalEliminarDoc').style.display = 'flex';
        }

        function cerrarModalEliminar() {
            _eliminarDocId = null;
            document.getElementById('modalEliminarDoc').style.display = 'none';
        }

        document.getElementById('modalEliminarDoc').addEventListener('click', function(e) {
            if (e.target === this) cerrarModalEliminar();
        });

        async function submitEliminarDoc() {
            if (!_eliminarDocId) return;
            const btn = document.getElementById('btnConfirmarEliminar');
            btn.disabled = true;
            btn.innerHTML = '<span class="material-symbols-rounded">hourglass_top</span> Eliminando...';

            try {
                const form = new FormData();
                form.append('doc_id', _eliminarDocId);

                const res  = await fetch('eliminar.php', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: form
                });
                const data = await res.json();

                if (data.success) {
                    cerrarModalEliminar();
                    window.location.href = 'index.php?success=documento_eliminado';
                } else {
                    document.getElementById('mensajeErrorEliminar').textContent = data.error;
                    document.getElementById('alertaErrorEliminar').style.display = 'flex';
                }
            } catch (err) {
                document.getElementById('mensajeErrorEliminar').textContent = 'Error de conexión. Intenta nuevamente.';
                document.getElementById('alertaErrorEliminar').style.display = 'flex';
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<span class="material-symbols-rounded">delete</span> Eliminar';
            }
        }
        <?php endif; ?>

        // Toggle vista grid/list
        document.querySelectorAll('.view-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.view-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                
                const grid = document.getElementById('documentsGrid');
                if (btn.dataset.view === 'list') {
                    grid.classList.add('list-view');
                } else {
                    grid.classList.remove('list-view');
                }
            });
        });
    </script>
</body>
</html>