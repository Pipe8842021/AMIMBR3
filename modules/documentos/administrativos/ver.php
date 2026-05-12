<?php
/**
 * Ver Detalles del Documento Administrativo
 */

require_once '../../../config/session.php';
require_once '../../../config/database.php';
require_once '../../../includes/auth_check.php';

// Verificar que no sea estudiante
if ($_SESSION['user_rol'] === 'estudiante') {
    header("Location: ../../../modules/dashboard/");
    exit;
}

// Obtener ID del documento
$doc_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($doc_id === 0) {
    header("Location: index.php?error=documento_no_encontrado");
    exit;
}

try {
    // Obtener documento con información del usuario que lo subió
    $stmt = $pdo->prepare("
        SELECT 
            da.*,
            u.nombre as subido_por_nombre,
            u.email as subido_por_email,
            p.nombre as profesor_nombre,
            p.email as profesor_email
        FROM documentos_administrativos da
        LEFT JOIN usuarios u ON da.subido_por = u.id
        LEFT JOIN usuarios p ON da.profesor_especifico_id = p.id
        WHERE da.id = ? AND da.estado = 'activo'
    ");
    $stmt->execute([$doc_id]);
    $documento = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$documento) {
        header("Location: index.php?error=documento_no_encontrado");
        exit;
    }
    
    // Verificar permisos de acceso
    $user_id = $_SESSION['user_id'];
    $user_role = $_SESSION['user_rol'];
    
    $tiene_acceso = false;
    
    if ($user_role === 'admin') {
        $tiene_acceso = true;
    } elseif ($user_role === 'profesor') {
        if ($documento['visibilidad'] === 'profesores' || 
            $documento['visibilidad'] === 'todos_excepto_estudiantes') {
            $tiene_acceso = true;
        } elseif ($documento['visibilidad'] === 'profesor_especifico' && 
                  $documento['profesor_especifico_id'] == $user_id) {
            $tiene_acceso = true;
        }
    }
    
    if (!$tiene_acceso) {
        header("Location: index.php?error=sin_permisos");
        exit;
    }
    
    // Registrar visualización
    try {
        $stmt = $pdo->prepare("
            INSERT INTO documentos_accesos (documento_id, usuario_id, accion, ip_address)
            VALUES (?, ?, 'visualizacion', ?)
        ");
        $stmt->execute([$doc_id, $user_id, $_SERVER['REMOTE_ADDR']]);
    } catch (PDOException $e) {
        error_log("Error al registrar visualización: " . $e->getMessage());
    }
    
    // Obtener historial de accesos (últimos 10)
    $stmt = $pdo->prepare("
        SELECT 
            da.accion,
            da.fecha_acceso,
            u.nombre as usuario_nombre,
            u.rol as usuario_rol
        FROM documentos_accesos da
        INNER JOIN usuarios u ON da.usuario_id = u.id
        WHERE da.documento_id = ?
        ORDER BY da.fecha_acceso DESC
        LIMIT 10
    ");
    $stmt->execute([$doc_id]);
    $historial_accesos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error al obtener documento: " . $e->getMessage());
    header("Location: index.php?error=error_sistema");
    exit;
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

// Función para formatear tamaño
function formatear_tamano($bytes) {
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' B';
    }
}

// Función para formatear fecha y hora
function formatear_fecha_hora($fecha) {
    $timestamp = strtotime($fecha);
    $meses = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
    return date('d', $timestamp) . ' de ' . $meses[date('n', $timestamp)] . ' de ' . date('Y', $timestamp) . ' a las ' . date('h:i A', $timestamp);
}

// Función para tiempo transcurrido
function tiempo_transcurrido($fecha) {
    $ahora = new DateTime();
    $tiempo = new DateTime($fecha);
    $diferencia = $ahora->diff($tiempo);
    
    if ($diferencia->days > 0) {
        return "Hace " . $diferencia->days . " día" . ($diferencia->days > 1 ? "s" : "");
    } elseif ($diferencia->h > 0) {
        return "Hace " . $diferencia->h . " hora" . ($diferencia->h > 1 ? "s" : "");
    } elseif ($diferencia->i > 0) {
        return "Hace " . $diferencia->i . " minuto" . ($diferencia->i > 1 ? "s" : "");
    } else {
        return "Hace unos segundos";
    }
}

$icono = icono_archivo($documento['tipo_archivo']);

// ── Respuesta AJAX ──────────────────────────────────────────────
$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($is_ajax) {
    $categorias_labels = [
        'informes'     => 'Informe',
        'facturas'     => 'Factura',
        'contratos'    => 'Contrato',
        'nominas'      => 'Nómina',
        'presupuestos' => 'Presupuesto',
        'legal'        => 'Legal',
        'otro'         => 'Otro',
    ];
    $visibilidad_labels = [
        'solo_admin'               => 'Solo Administradores',
        'profesores'               => 'Todos los Profesores',
        'profesor_especifico'      => 'Profesor Específico',
        'todos_excepto_estudiantes' => 'Todos excepto Estudiantes',
    ];

    header('Content-Type: application/json');
    echo json_encode([
        'success'   => true,
        'documento' => [
            'id'                     => $documento['id'],
            'titulo'                 => $documento['titulo'],
            'categoria'              => $documento['categoria'],
            'categoria_label'        => $categorias_labels[$documento['categoria']] ?? 'N/A',
            'descripcion'            => $documento['descripcion'] ?? '',
            'tipo_archivo'           => $documento['tipo_archivo'],
            'nombre_archivo'         => $documento['nombre_archivo'],
            'tamanio_archivo'        => $documento['tamanio_archivo'] ?? 0,
            'visibilidad'            => $documento['visibilidad'],
            'visibilidad_label'      => $visibilidad_labels[$documento['visibilidad']] ?? '',
            'profesor_especifico_id' => $documento['profesor_especifico_id'],
            'profesor_nombre'        => $documento['profesor_nombre'] ?? null,
            'profesor_email'         => $documento['profesor_email'] ?? null,
            'subido_por_nombre'      => $documento['subido_por_nombre'],
            'fecha_creacion'         => $documento['fecha_creacion'],
            'fecha_modificacion'     => $documento['fecha_modificacion'] ?? null,
            'icono'                  => $icono,
        ],
        'historial' => $historial_accesos,
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($documento['titulo']); ?> - Amimbré</title>
    <link rel="shortcut icon" href="../../../assets/img/3.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0" />
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
    <?php require_once '../../../includes/header.php'; ?>

    <main class="main-content">
        <div class="page-header">
            <div class="header-left">
                <button class="btn-back" onclick="window.location.href='index.php'">
                    <span class="material-symbols-rounded">arrow_back</span>
                </button>
                <div class="header-info">
                    <h1>Detalles del Documento</h1>
                    <p>Información completa y historial de accesos</p>
                </div>
            </div>
        </div>

        <div class="details-container">
            <div class="document-detail-card">
                <!-- Header del documento -->
                <div class="document-detail-header">
                    <div class="document-detail-icon" style="background: <?php echo $icono['color']; ?>20; color: <?php echo $icono['color']; ?>;">
                        <span class="material-symbols-rounded"><?php echo $icono['icono']; ?></span>
                    </div>
                    <h1 class="document-detail-title"><?php echo htmlspecialchars($documento['titulo']); ?></h1>
                    <div class="document-detail-meta">
                        <div class="meta-item-detail">
                            <span class="material-symbols-rounded">event</span>
                            Subido <?php echo tiempo_transcurrido($documento['fecha_creacion']); ?>
                        </div>
                        <div class="meta-item-detail">
                            <span class="material-symbols-rounded">person</span>
                            <?php echo htmlspecialchars($documento['subido_por_nombre']); ?>
                        </div>
                        <div class="meta-item-detail">
                            <span class="material-symbols-rounded">storage</span>
                            <?php echo formatear_tamano($documento['tamanio_archivo'] ?? 0); ?>
                        </div>
                    </div>
                </div>

                <!-- Cuerpo del documento -->
                <div class="document-detail-body">
                    <!-- Información General -->
                    <div class="detail-section">
                        <h2 class="section-title-detail">
                            <span class="material-symbols-rounded">info</span>
                            Información General
                        </h2>
                        <div class="info-grid">
                            <div class="info-item">
                                <div class="info-label">Categoría</div>
                                <div class="info-value">
                                    <?php 
                                    $categorias = [
                                        'informes' => 'Informe',
                                        'facturas' => 'Factura',
                                        'contratos' => 'Contrato',
                                        'nominas' => 'Nómina',
                                        'presupuestos' => 'Presupuesto',
                                        'legal' => 'Legal',
                                        'otro' => 'Otro'
                                    ];
                                    echo $categorias[$documento['categoria']] ?? 'N/A';
                                    ?>
                                </div>
                            </div>

                            <div class="info-item">
                                <div class="info-label">Tipo de Archivo</div>
                                <div class="info-value" style="text-transform: uppercase;">
                                    <?php echo htmlspecialchars($documento['tipo_archivo']); ?>
                                </div>
                            </div>

                            <div class="info-item">
                                <div class="info-label">Nombre del Archivo</div>
                                <div class="info-value" style="font-size: 0.9rem;">
                                    <?php echo htmlspecialchars($documento['nombre_archivo']); ?>
                                </div>
                            </div>

                            <div class="info-item">
                                <div class="info-label">Fecha de Creación</div>
                                <div class="info-value" style="font-size: 0.9rem;">
                                    <?php echo formatear_fecha_hora($documento['fecha_creacion']); ?>
                                </div>
                            </div>

                            <?php if ($documento['fecha_modificacion']): ?>
                            <div class="info-item">
                                <div class="info-label">Última Modificación</div>
                                <div class="info-value" style="font-size: 0.9rem;">
                                    <?php echo formatear_fecha_hora($documento['fecha_modificacion']); ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <div class="info-item">
                                <div class="info-label">Visibilidad</div>
                                <div class="info-value">
                                    <?php
                                    $visibilidad_labels = [
                                        'solo_admin' => 'Solo Adminis',
                                        'profesores' => 'Todos los Profesores',
                                        'profesor_especifico' => 'Profesor Específico',
                                        'todos_excepto_estudiantes' => 'Todos excepto Estudiantes'
                                    ];
                                    $vis_class = [
                                        'solo_admin' => 'admin',
                                        'profesores' => 'profesores',
                                        'profesor_especifico' => 'especifico',
                                        'todos_excepto_estudiantes' => 'todos'
                                    ];
                                    ?>
                                    <span class="badge-visibility <?php echo $vis_class[$documento['visibilidad']]; ?>">
                                        <span class="material-symbols-rounded" style="font-size: 16px;">
                                            <?php echo $documento['visibilidad'] === 'solo_admin' ? 'lock' : 'visibility'; ?>
                                        </span>
                                        <?php echo $visibilidad_labels[$documento['visibilidad']]; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Descripción -->
                    <?php if ($documento['descripcion']): ?>
                    <div class="detail-section">
                        <h2 class="section-title-detail">
                            <span class="material-symbols-rounded">description</span>
                            Descripción
                        </h2>
                        <div class="description-box">
                            <?php echo nl2br(htmlspecialchars($documento['descripcion'])); ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Permisos Específicos -->
                    <?php if ($documento['visibilidad'] === 'profesor_especifico' && $documento['profesor_nombre']): ?>
                    <div class="detail-section">
                        <h2 class="section-title-detail">
                            <span class="material-symbols-rounded">person</span>
                            Acceso Específico
                        </h2>
                        <div class="info-item">
                            <div class="info-label">Profesor Autorizado</div>
                            <div class="info-value">
                                <?php echo htmlspecialchars($documento['profesor_nombre']); ?>
                                <?php if ($documento['profesor_email']): ?>
                                    <span style="color: var(--text-secondary); font-size: 0.9rem; font-weight: 400;">
                                        (<?php echo htmlspecialchars($documento['profesor_email']); ?>)
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Historial de Accesos -->
                    <div class="detail-section">
                        <h2 class="section-title-detail">
                            <span class="material-symbols-rounded">history</span>
                            Historial de Accesos
                        </h2>
                        <?php if (count($historial_accesos) > 0): ?>
                        <div class="access-timeline">
                            <?php foreach ($historial_accesos as $acceso): ?>
                            <div class="timeline-item">
                                <div class="timeline-icon <?php echo $acceso['accion'] === 'descarga' ? 'download' : 'view'; ?>">
                                    <span class="material-symbols-rounded">
                                        <?php echo $acceso['accion'] === 'descarga' ? 'download' : 'visibility'; ?>
                                    </span>
                                </div>
                                <div class="timeline-content">
                                    <div class="timeline-action">
                                        <?php echo htmlspecialchars($acceso['usuario_nombre']); ?> 
                                        <?php echo $acceso['accion'] === 'descarga' ? 'descargó' : 'visualizó'; ?> el documento
                                        <span style="color: var(--text-secondary); font-weight: 400;">
                                            (<?php echo ucfirst($acceso['usuario_rol']); ?>)
                                        </span>
                                    </div>
                                    <div class="timeline-time">
                                        <?php echo tiempo_transcurrido($acceso['fecha_acceso']); ?> - 
                                        <?php echo formatear_fecha_hora($acceso['fecha_acceso']); ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="empty-timeline">
                            <span class="material-symbols-rounded">history</span>
                            <div>No hay historial de accesos aún</div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Barra de acciones -->
                <div class="actions-bar">
                    <button class="btn-primary" onclick="window.location.href='descargar.php?id=<?php echo $doc_id; ?>'">
                        <span class="material-symbols-rounded">download</span>
                        Descargar
                    </button>
                    <?php if ($user_role === 'admin'): ?>
                    <button class="btn-edit" onclick="window.location.href='editar.php?id=<?php echo $doc_id; ?>'">
                        <span class="material-symbols-rounded">edit</span>
                        Editar
                    </button>
                    <button class="btn-delete" 
                            onclick="if(confirm('¿Estás seguro de eliminar este documento?')) window.location.href='eliminar.php?id=<?php echo $doc_id; ?>'">
                        <span class="material-symbols-rounded">delete</span>
                        Eliminar
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</body>
</html>