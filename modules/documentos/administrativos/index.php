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
            <button class="btn-primary" onclick="window.location.href='crear.php'">
                <span class="material-symbols-rounded">add</span>
                Nuevo Archivo
            </button>
            <?php endif; ?>
        </div>

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
                <input type="text" id="searchInput" placeholder="Buscar por nombre, descripción o etiqueta..." 
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
                                    <a href="ver.php?id=<?php echo $doc['id']; ?>" class="menu-item">
                                        <span class="material-symbols-rounded">visibility</span>
                                        Ver detalles
                                    </a>
                                    <?php if ($user['rol'] === 'admin'): ?>
                                    <a href="editar.php?id=<?php echo $doc['id']; ?>" class="menu-item">
                                        <span class="material-symbols-rounded">edit</span>
                                        Editar
                                    </a>
                                    <a href="eliminar.php?id=<?php echo $doc['id']; ?>" class="menu-item danger" 
                                       onclick="return confirm('¿Estás seguro de eliminar este documento?')">
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
                    <button class="btn-primary" onclick="window.location.href='crear.php'" style="margin: auto;">
                        <span class="material-symbols-rounded">add</span>
                        Crear primer documento
                    </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>

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