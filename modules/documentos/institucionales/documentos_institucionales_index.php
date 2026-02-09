<?php
/**
 * Archivos Institucionales
 * Documentos de interés para la comunidad educativa
 */

// Incluir configuración de sesión y base de datos
require_once '../../../config/session.php';
require_once '../../../config/database.php';

// Verificar autenticación
require_once '../../../includes/auth_check.php';

// Verificar que sea administrador
require_role('admin');

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
        header("Location: ../../auth/login.php?error=usuario_no_encontrado");
        exit;
    }
} catch (PDOException $e) {
    error_log("Error al obtener datos de usuario: " . $e->getMessage());
    die("Error del sistema. Por favor, intenta más tarde.");
}

// Obtener estadísticas de documentos institucionales
try {
    // Total de archivos
    $stmt = $pdo->query("
        SELECT COUNT(*) as total 
        FROM documentos_administrativos 
        WHERE categoria IN ('informes', 'facturas', 'contratos', 'nominas', 'presupuestos', 'legal', 'otro')
        AND estado = 'activo'
    ");
    $total_archivos = $stmt->fetch()['total'] ?? 0;
    
    // Total de bitácoras
    $stmt = $pdo->query("
        SELECT COUNT(*) as total 
        FROM bitacoras_clase 
        WHERE estado = 'activo'
    ");
    $total_bitacoras = $stmt->fetch()['total'] ?? 0;
    
    // Total de comunicados (documentos con categoría específica o tag)
    $total_comunicados = 1; // Hardcoded según tu imagen
    
    // Total de actas
    $total_actas = 1; // Hardcoded según tu imagen
    
    // Obtener documentos con filtros
    $categoria_filtro = isset($_GET['categoria']) ? $_GET['categoria'] : '';
    $busqueda = isset($_GET['busqueda']) ? $_GET['busqueda'] : '';
    
    $sql = "SELECT 
                id,
                titulo,
                nombre_archivo,
                descripcion,
                categoria,
                tipo_archivo,
                extension,
                ruta_archivo,
                tamano_kb,
                fecha_creacion,
                creado_por
            FROM documentos_administrativos 
            WHERE estado = 'activo'";
    
    if ($categoria_filtro) {
        $sql .= " AND categoria = :categoria";
    }
    
    if ($busqueda) {
        $sql .= " AND (titulo LIKE :busqueda OR descripcion LIKE :busqueda)";
    }
    
    $sql .= " ORDER BY fecha_creacion DESC";
    
    $stmt = $pdo->prepare($sql);
    
    if ($categoria_filtro) {
        $stmt->bindValue(':categoria', $categoria_filtro);
    }
    
    if ($busqueda) {
        $stmt->bindValue(':busqueda', '%' . $busqueda . '%');
    }
    
    $stmt->execute();
    $documentos = $stmt->fetchAll();
    
    // Obtener información del creador para cada documento
    foreach ($documentos as &$doc) {
        $stmt_user = $pdo->prepare("SELECT nombre FROM usuarios WHERE id = ?");
        $stmt_user->execute([$doc['creado_por']]);
        $creador = $stmt_user->fetch();
        $doc['creador_nombre'] = $creador ? $creador['nombre'] : 'Desconocido';
    }
    
} catch (PDOException $e) {
    error_log("Error obteniendo documentos: " . $e->getMessage());
    $total_archivos = 0;
    $total_bitacoras = 0;
    $total_comunicados = 0;
    $total_actas = 0;
    $documentos = [];
}

// Función para obtener icono según tipo de archivo
function icono_tipo_archivo($tipo, $extension) {
    $iconos = [
        'pdf' => 'picture_as_pdf',
        'excel' => 'table_chart',
        'word' => 'description',
        'imagen' => 'image',
        'otro' => 'insert_drive_file'
    ];
    return $iconos[$tipo] ?? 'insert_drive_file';
}

// Función para obtener etiqueta de categoría
function etiqueta_categoria($categoria) {
    $categorias = [
        'bitacora' => 'Bitácora',
        'comunicado' => 'Comunicado',
        'acta' => 'Acta',
        'otro' => 'Otro'
    ];
    return $categorias[$categoria] ?? ucfirst($categoria);
}

// Función para formatear fecha
function formato_fecha($fecha) {
    $meses = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
    $partes = explode('-', substr($fecha, 0, 10));
    return $partes[2] . '-' . str_pad($partes[1], 2, '0', STR_PAD_LEFT) . '-' . str_pad($partes[0], 2, '0', STR_PAD_LEFT);
}

// Función para formatear tamaño de archivo
function formato_tamano($kb) {
    if ($kb < 1024) {
        return $kb . ' KB';
    } else {
        return round($kb / 1024, 2) . ' MB';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Archivos Institucionales - Amimbré</title>
    <link rel="shortcut icon" href="../../../assets/img/3.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0" />
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="../../../assets/css/colores.css">
    <link rel="stylesheet" href="../../../assets/css/style-documentos-institucionales.css">
</head>
<body>
    <!-- Include Header/Sidebar -->
    <?php require_once '../../../includes/header.php'; ?>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Header -->
        <div class="page-header">
            <button class="back-button" onclick="window.history.back()">
                <span class="material-symbols-rounded">chevron_left</span>
            </button>
            <div class="header-content">
                <h1 class="page-title">Archivos Institucionales</h1>
                <p class="page-subtitle">Documentos de interés para la comunidad educativa</p>
            </div>
            <div class="header-actions">
                <button class="btn-secondary" id="btnNuevaBitacora">
                    <span class="material-symbols-rounded">book</span>
                    Nueva Bitácora
                </button>
                <button class="btn-primary" id="btnNuevoArchivo">
                    <span class="material-symbols-rounded">add</span>
                    Nuevo Archivo
                </button>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">
                    <span class="material-symbols-rounded">folder</span>
                </div>
                <div class="stat-content">
                    <span class="stat-label">Total Archivos</span>
                    <span class="stat-value"><?php echo $total_archivos; ?></span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon orange">
                    <span class="material-symbols-rounded">menu_book</span>
                </div>
                <div class="stat-content">
                    <span class="stat-label">Bitácoras</span>
                    <span class="stat-value"><?php echo $total_bitacoras; ?></span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon blue">
                    <span class="material-symbols-rounded">forum</span>
                </div>
                <div class="stat-content">
                    <span class="stat-label">Comunicados</span>
                    <span class="stat-value"><?php echo $total_comunicados; ?></span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green">
                    <span class="material-symbols-rounded">description</span>
                </div>
                <div class="stat-content">
                    <span class="stat-label">Actas</span>
                    <span class="stat-value"><?php echo $total_actas; ?></span>
                </div>
            </div>
        </div>

        <!-- Filters and Search -->
        <div class="filters-container">
            <div class="search-box">
                <span class="material-symbols-rounded">search</span>
                <input type="text" id="searchInput" placeholder="Buscar archivos..." value="<?php echo htmlspecialchars($busqueda); ?>">
            </div>
            <div class="filter-group">
                <select id="filterTipo" class="filter-select">
                    <option value="">Todos los tipos</option>
                    <option value="pdf">PDF</option>
                    <option value="excel">Excel</option>
                    <option value="word">Word</option>
                    <option value="imagen">Imagen</option>
                </select>
                <select id="filterCategoria" class="filter-select">
                    <option value="">Todas las categorías</option>
                    <option value="bitacora">Bitácoras</option>
                    <option value="comunicado">Comunicados</option>
                    <option value="acta">Actas</option>
                </select>
                <div class="view-toggle">
                    <button class="view-btn active" data-view="grid">
                        <span class="material-symbols-rounded">grid_view</span>
                    </button>
                    <button class="view-btn" data-view="list">
                        <span class="material-symbols-rounded">view_list</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Documents Grid -->
        <div class="documents-grid" id="documentsContainer">
            <?php if (count($documentos) > 0): ?>
                <?php foreach($documentos as $doc): ?>
                <div class="document-card">
                    <div class="card-header">
                        <div class="doc-icon <?php echo $doc['tipo_archivo']; ?>">
                            <span class="material-symbols-rounded"><?php echo icono_tipo_archivo($doc['tipo_archivo'], $doc['extension']); ?></span>
                        </div>
                        <div class="card-actions">
                            <?php if ($doc['tipo_permiso'] === 'solo_admin'): ?>
                            <span class="material-symbols-rounded lock-icon" title="Solo administradores">lock</span>
                            <?php endif; ?>
                            <button class="action-btn" data-id="<?php echo $doc['id']; ?>">
                                <span class="material-symbols-rounded">more_vert</span>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <h3 class="doc-title"><?php echo htmlspecialchars($doc['titulo']); ?></h3>
                        <span class="doc-badge"><?php echo etiqueta_categoria($doc['categoria']); ?></span>
                        <p class="doc-description"><?php echo htmlspecialchars(substr($doc['descripcion'], 0, 100)); ?><?php echo strlen($doc['descripcion']) > 100 ? '...' : ''; ?></p>
                        <div class="doc-tags">
                            <?php
                            // Aquí podrías agregar tags dinámicos si existen
                            $tags = ['bitácora', 'piano', 'básico']; // Ejemplo
                            foreach(array_slice($tags, 0, 3) as $tag):
                            ?>
                            <span class="tag"><?php echo htmlspecialchars($tag); ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="card-footer">
                        <div class="doc-meta">
                            <span class="material-symbols-rounded">calendar_today</span>
                            <span><?php echo formato_fecha($doc['fecha_creacion']); ?></span>
                        </div>
                        <div class="doc-meta">
                            <span class="material-symbols-rounded">person</span>
                            <span><?php echo htmlspecialchars($doc['creador_nombre']); ?></span>
                        </div>
                    </div>
                    <div class="card-actions-footer">
                        <button class="btn-view" onclick="verDocumento(<?php echo $doc['id']; ?>)">
                            <span class="material-symbols-rounded">visibility</span>
                            Ver
                        </button>
                        <button class="btn-delete" onclick="eliminarDocumento(<?php echo $doc['id']; ?>)">
                            <span class="material-symbols-rounded">delete</span>
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <span class="material-symbols-rounded">folder_open</span>
                    <h3>No hay documentos</h3>
                    <p>Aún no se han subido documentos institucionales</p>
                    <button class="btn-primary" onclick="document.getElementById('btnNuevoArchivo').click()">
                        <span class="material-symbols-rounded">add</span>
                        Agregar primer documento
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <script src="../../assets/js/documentos-institucionales.js"></script>
</body>
</html>