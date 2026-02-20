<?php
/**
 * Archivos Institucionales
 * Documentos de interés para la comunidad educativa
 */

require_once '../../../config/session.php';
require_once '../../../config/database.php';
require_once '../../../includes/auth_check.php';

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
$tipo_filter = isset($_GET['tipo']) ? $_GET['tipo'] : 'todos';
$categoria_filter = isset($_GET['categoria']) ? $_GET['categoria'] : 'todas';

// Construcción de consulta para bitácoras
$sql_bitacoras = "SELECT b.*, c.nombre as curso_nombre, u.nombre as profesor_nombre,
                  (SELECT COUNT(*) FROM bitacoras_asistencias WHERE bitacora_id = b.id) as total_asistencias,
                  (SELECT COUNT(*) FROM bitacoras_evidencias WHERE bitacora_id = b.id) as total_evidencias
                  FROM bitacoras b
                  INNER JOIN cursos c ON b.curso_id = c.id
                  INNER JOIN usuarios u ON b.profesor_id = u.id
                  WHERE b.estado = 'activo'";

if ($search !== '') {
    $sql_bitacoras .= " AND (b.titulo LIKE :search OR b.temas_tratados LIKE :search)";
}

$sql_bitacoras .= " ORDER BY b.fecha_clase DESC";

try {
    // Obtener bitácoras
    $stmt = $pdo->prepare($sql_bitacoras);
    if ($search !== '') {
        $stmt->bindValue(':search', "%$search%", PDO::PARAM_STR);
    }
    $stmt->execute();
    $bitacoras = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener certificados
    $stmt = $pdo->query("
        SELECT cc.*, 
               e.nombre as estudiante_nombre,
               c.nombre as curso_nombre
        FROM calificaciones_certificados cc
        INNER JOIN usuarios e ON cc.estudiante_id = e.id
        INNER JOIN cursos c ON cc.curso_id = c.id
        WHERE cc.estado = 'aprobado'
        ORDER BY cc.fecha_aprobacion DESC
    ");
    $certificados = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener estadísticas
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM bitacoras WHERE estado = 'activo'");
    $total_bitacoras = $stmt->fetch()['total'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM calificaciones_certificados WHERE estado = 'aprobado'");
    $total_certificados = $stmt->fetch()['total'];
    
    // Calcular totales para estadísticas
    $total_archivos = $total_bitacoras + $total_certificados;
    $total_comunicados = 0; // Placeholder para futuras funcionalidades
    $total_actas = 0; // Placeholder para futuras funcionalidades
    
} catch (PDOException $e) {
    error_log("Error obteniendo documentos: " . $e->getMessage());
    $bitacoras = [];
    $certificados = [];
    $total_archivos = 0;
    $total_bitacoras = 0;
    $total_comunicados = 0;
    $total_actas = 0;
}

// Función para formatear fecha
function formatear_fecha($fecha) {
    $timestamp = strtotime($fecha);
    $meses = ['', 'Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
    return date('d', $timestamp) . ' ' . $meses[date('n', $timestamp)] . ' ' . date('Y', $timestamp);
}

// Combinar documentos según filtro
$documentos = [];

if ($tipo_filter === 'todos' || $tipo_filter === 'bitacora') {
    foreach ($bitacoras as $bitacora) {
        $documentos[] = [
            'tipo' => 'bitacora',
            'id' => $bitacora['id'],
            'titulo' => $bitacora['titulo'],
            'descripcion' => $bitacora['temas_tratados'],
            'categoria' => 'Bitácora',
            'badge_class' => 'bitacora',
            'fecha' => $bitacora['fecha_clase'],
            'autor' => $bitacora['profesor_nombre'],
            'metadata' => [
                'curso' => $bitacora['curso_nombre'],
                'asistencias' => $bitacora['total_asistencias'],
                'evidencias' => $bitacora['total_evidencias']
            ],
            'tags' => explode(',', $bitacora['temas_tratados'])
        ];
    }
}

if ($tipo_filter === 'todos' || $tipo_filter === 'certificado') {
    foreach ($certificados as $cert) {
        $documentos[] = [
            'tipo' => 'certificado',
            'id' => $cert['id'],
            'titulo' => 'Certificado - ' . $cert['curso_nombre'],
            'descripcion' => $cert['estudiante_nombre'],
            'categoria' => 'Certificado',
            'badge_class' => 'certificado',
            'fecha' => $cert['fecha_aprobacion'],
            'autor' => $cert['estudiante_nombre'],
            'metadata' => [
                'nivel' => ucfirst($cert['nivel_aprobado']),
                'calificacion' => $cert['calificacion_final'],
                'codigo' => $cert['codigo_certificado']
            ]
        ];
    }
}

// Ordenar por fecha descendente
usort($documentos, function($a, $b) {
    return strtotime($b['fecha']) - strtotime($a['fecha']);
});
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
                <?php if ($user['rol'] === 'admin' || $user['rol'] === 'profesor'): ?>
                <button class="btn-secondary" onclick="window.location.href='bitacoras/crear.php'">
                    <span class="material-symbols-rounded">menu_book</span>
                    Nueva Bitácora
                </button>
                <?php endif; ?>
                <?php if ($user['rol'] === 'admin'): ?>
                <button class="btn-primary" onclick="window.location.href='crear.php'">
                    <span class="material-symbols-rounded">add</span>
                    Nuevo Archivo
                </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Estadísticas -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background: var(--subtle-blue); color: var(--primary-blue);">
                    <span class="material-symbols-rounded">folder_open</span>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Total Archivos</div>
                    <div class="stat-value"><?php echo $total_archivos; ?></div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: var(--subtle-orange); color: var(--primary-orange);">
                    <span class="material-symbols-rounded">book</span>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Bitácoras</div>
                    <div class="stat-value"><?php echo $total_bitacoras; ?></div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: var(--subtle-blue); color: var(--primary-blue);">
                    <span class="material-symbols-rounded">forum</span>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Comunicados</div>
                    <div class="stat-value"><?php echo $total_comunicados; ?></div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon" style="background: var(--subtle-green); color: var(--primary-green);">
                    <span class="material-symbols-rounded">description</span>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Actas</div>
                    <div class="stat-value"><?php echo $total_actas; ?></div>
                </div>
            </div>
        </div>

        <!-- Filtros y Búsqueda -->
        <div class="filters-container">
            <div class="search-box">
                <span class="material-symbols-rounded">search</span>
                <input type="text" id="searchInput" placeholder="Buscar archivos..." 
                       value="<?php echo htmlspecialchars($search); ?>">
            </div>

            <div class="filter-group">
                <select id="tipoFilter" class="filter-select">
                    <option value="todos" <?php echo $tipo_filter === 'todos' ? 'selected' : ''; ?>>Todos los tipos</option>
                    <option value="bitacora" <?php echo $tipo_filter === 'bitacora' ? 'selected' : ''; ?>>Bitácoras</option>
                    <option value="certificado" <?php echo $tipo_filter === 'certificado' ? 'selected' : ''; ?>>Certificados</option>
                    <option value="comunicado" <?php echo $tipo_filter === 'comunicado' ? 'selected' : ''; ?>>Comunicados</option>
                    <option value="acta" <?php echo $tipo_filter === 'acta' ? 'selected' : ''; ?>>Actas</option>
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

        <!-- Grid de Documentos -->
        <div class="documents-grid" id="documentsGrid">
            <?php if (count($documentos) > 0): ?>
                <?php foreach ($documentos as $doc): ?>
                    <div class="document-card">
                        <!-- Header del documento -->
                        <div class="document-header">
                            <div class="document-icon-wrapper">
                                <div class="document-icon <?php echo $doc['badge_class']; ?>">
                                    <span class="material-symbols-rounded">
                                        <?php echo $doc['tipo'] === 'bitacora' ? 'menu_book' : 'workspace_premium'; ?>
                                    </span>
                                </div>
                                <span class="badge badge-<?php echo $doc['badge_class']; ?>">
                                    <?php echo $doc['categoria']; ?>
                                </span>
                            </div>
                        </div>

                        <!-- Contenido del documento -->
                        <div class="document-body">
                            <h3 class="document-title"><?php echo htmlspecialchars($doc['titulo']); ?></h3>
                            <p class="document-description">
                                <?php 
                                $desc = htmlspecialchars($doc['descripcion']);
                                echo strlen($desc) > 100 ? substr($desc, 0, 100) . '...' : $desc;
                                ?>
                            </p>

                            <?php if ($doc['tipo'] === 'bitacora'): ?>
                            <div class="document-tags">
                                <?php foreach (array_slice($doc['tags'], 0, 3) as $tag): ?>
                                    <?php $tag_clean = trim($tag); ?>
                                    <?php if (!empty($tag_clean)): ?>
                                    <span class="tag"><?php echo htmlspecialchars(substr($tag_clean, 0, 20)); ?></span>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Footer del documento -->
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
                                <button class="btn-action" onclick="window.location.href='<?php echo $doc['tipo']; ?>/ver.php?id=<?php echo $doc['id']; ?>'">
                                    <span class="material-symbols-rounded">visibility</span>
                                    Ver
                                </button>
                                <?php if ($user['rol'] === 'admin'): ?>
                                <button class="btn-action-danger" 
                                        onclick="if(confirm('¿Eliminar este documento?')) window.location.href='<?php echo $doc['tipo']; ?>/eliminar.php?id=<?php echo $doc['id']; ?>'">
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
                    <p>No se encontraron documentos con los filtros seleccionados</p>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <script>
        // Aplicar filtros
        function applyFilters() {
            const search = document.getElementById('searchInput').value;
            const tipo = document.getElementById('tipoFilter').value;
            const categoria = document.getElementById('categoriaFilter').value;
            
            const url = new URL(window.location.href);
            url.searchParams.set('search', search);
            url.searchParams.set('tipo', tipo);
            url.searchParams.set('categoria', categoria);
            
            window.location.href = url.toString();
        }

        // Event listeners
        document.getElementById('searchInput').addEventListener('keypress', (e) => {
            if (e.key === 'Enter') applyFilters();
        });

        document.getElementById('tipoFilter').addEventListener('change', applyFilters);
        document.getElementById('categoriaFilter').addEventListener('change', applyFilters);

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