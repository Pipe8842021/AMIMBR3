<?php
/**
 * Ver Detalles de Documento Institucional
 * (Certificados, Comunicados, Actas)
 */

require_once '../../../config/session.php';
require_once '../../../config/database.php';
require_once '../../../includes/auth_check.php';

// Obtener tipo y ID del documento
$tipo = $_GET['tipo'] ?? '';
$doc_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (empty($tipo) || $doc_id === 0) {
    header("Location: index.php?error=documento_no_encontrado");
    exit;
}

// Obtener datos del usuario actual
try {
    $stmt = $pdo->prepare("SELECT id, nombre, email, rol FROM usuarios WHERE id = ? AND estado = 'activo'");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        session_destroy();
        header("Location: ../../../auth/login.php");
        exit;
    }
} catch (PDOException $e) {
    die("Error del sistema");
}

$documento = null;
$tiene_acceso = false;

try {
    if ($tipo === 'certificado') {
        // CERTIFICADO
        $stmt = $pdo->prepare("
            SELECT cc.*, 
                   e.nombre as estudiante_nombre,
                   e.email as estudiante_email,
                   c.nombre as curso_nombre,
                   g.nombre as grupo_nombre,
                   u.nombre as aprobado_por_nombre
            FROM calificaciones_certificados cc
            INNER JOIN usuarios e ON cc.estudiante_id = e.id
            INNER JOIN cursos c ON cc.curso_id = c.id
            INNER JOIN grupos g ON cc.grupo_id = g.id
            LEFT JOIN usuarios u ON cc.aprobado_por = u.id
            WHERE cc.id = ? AND cc.estado = 'aprobado'
        ");
        $stmt->execute([$doc_id]);
        $documento = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Verificar permisos: admin o el estudiante dueño del certificado
        if ($user['rol'] === 'admin') {
            $tiene_acceso = true;
        } elseif ($user['rol'] === 'estudiante' && $documento && $documento['estudiante_id'] == $user['id']) {
            $tiene_acceso = true;
        } elseif ($user['rol'] === 'profesor') {
            // Los profesores pueden ver todos los certificados
            $tiene_acceso = true;
        } else {
            $tiene_acceso = false;
        }
        
    } elseif ($tipo === 'comunicado') {
        // COMUNICADO
        $stmt = $pdo->prepare("
            SELECT dc.*, u.nombre as publicado_por_nombre
            FROM documentos_comunicados dc
            INNER JOIN usuarios u ON dc.publicado_por = u.id
            WHERE dc.id = ? AND dc.estado = 'activo'
        ");
        $stmt->execute([$doc_id]);
        $documento = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Verificar acceso según dirigido_a
        if ($documento) {
            $dirigido_a = $documento['dirigido_a'] ?? 'todos';
            if ($dirigido_a === 'todos' || $user['rol'] === 'admin') {
                $tiene_acceso = true;
            } elseif ($dirigido_a === 'profesores' && $user['rol'] === 'profesor') {
                $tiene_acceso = true;
            } elseif ($dirigido_a === 'estudiantes' && $user['rol'] === 'estudiante') {
                $tiene_acceso = true;
            }
        }
        
    } elseif ($tipo === 'acta') {
        // ACTA
        $stmt = $pdo->prepare("
            SELECT da.*, u.nombre as creado_por_nombre
            FROM documentos_actas da
            INNER JOIN usuarios u ON da.creado_por = u.id
            WHERE da.id = ? AND da.estado = 'activo'
        ");
        $stmt->execute([$doc_id]);
        $documento = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Verificar acceso según visibilidad
        if ($documento) {
            // Verificar si la columna visibilidad existe
            $visibilidad = isset($documento['visibilidad']) ? $documento['visibilidad'] : 'solo_admin';
            
            if ($user['rol'] === 'admin') {
                $tiene_acceso = true;
            } elseif ($user['rol'] === 'profesor' && in_array($visibilidad, ['admin_profesores', 'todos'])) {
                $tiene_acceso = true;
            } elseif ($user['rol'] === 'estudiante' && $visibilidad === 'todos') {
                $tiene_acceso = true;
            } else {
                $tiene_acceso = false;
            }
        }
    }
    
    if (!$documento) {
        header("Location: index.php?error=documento_no_encontrado");
        exit;
    }
    
    if (!$tiene_acceso) {
        header("Location: index.php?error=sin_permisos");
        exit;
    }
    
} catch (PDOException $e) {
    error_log("Error: " . $e->getMessage());
    header("Location: index.php?error=error_sistema");
    exit;
}

// Función para formatear fecha
function formatear_fecha_completa($fecha) {
    $timestamp = strtotime($fecha);
    $dias = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
    $meses = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
    
    $dia_semana = $dias[date('w', $timestamp)];
    $dia = date('d', $timestamp);
    $mes = $meses[date('n', $timestamp)];
    $anio = date('Y', $timestamp);
    
    return "$dia_semana, $dia de $mes de $anio";
}

// Obtener icono y color según tipo
$iconos = [
    'certificado' => ['icono' => 'workspace_premium', 'color' => 'var(--primary-green)'],
    'comunicado' => ['icono' => 'campaign', 'color' => 'var(--primary-blue)'],
    'acta' => ['icono' => 'assignment', 'color' => 'var(--primary-yellow)']
];

$icono_data = $iconos[$tipo] ?? ['icono' => 'description', 'color' => 'var(--text-secondary)'];

// AJAX: devolver JSON para el modal de ver
$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($is_ajax) {
    header('Content-Type: application/json');

    $data = [
        'success'   => true,
        'tipo'      => $tipo,
        'documento' => $documento,
        'icono'     => $icono_data['icono'],
        'color'     => $icono_data['color'],
    ];

    if ($tipo === 'certificado') {
        $data['titulo_display'] = 'Certificado de ' . ($documento['curso_nombre'] ?? '') . ' – ' . ($documento['estudiante_nombre'] ?? '');
        $data['fecha_display']  = formatear_fecha_completa($documento['fecha_aprobacion']);
        $data['periodo']        = (!empty($documento['fecha_inicio_curso']) && !empty($documento['fecha_fin_curso']))
            ? date('d/m/Y', strtotime($documento['fecha_inicio_curso'])) . ' – ' . date('d/m/Y', strtotime($documento['fecha_fin_curso']))
            : '';
    } elseif ($tipo === 'comunicado') {
        $data['titulo_display'] = $documento['titulo'] ?? 'Sin título';
        $data['fecha_display']  = formatear_fecha_completa($documento['fecha_publicacion']);
    } elseif ($tipo === 'acta') {
        $data['titulo_display']     = $documento['titulo'] ?? 'Sin título';
        $data['fecha_display']      = formatear_fecha_completa($documento['fecha_reunion']);
        $data['tipo_reunion_label'] = ucfirst(str_replace('_', ' ', $documento['tipo_reunion'] ?? ''));
        $vis_map = ['solo_admin' => 'Solo Admin', 'admin_profesores' => 'Admin y Profesores', 'todos' => 'Todos'];
        $data['visibilidad_label']  = $vis_map[$documento['visibilidad'] ?? ''] ?? '';
    }

    echo json_encode($data);
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($documento['titulo'] ?? 'Documento'); ?> - Amimbré</title>
    <link rel="shortcut icon" href="../../../assets/img/3.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0" />
    <link rel="stylesheet" href="../../../assets/css/colores.css">
    <link rel="stylesheet" href="../../../assets/css/style-documentos-institucionales.css">
    <script>
        (function() {
            const theme = localStorage.getItem('amimbre-theme');
            if (theme === 'light') {
                document.documentElement.setAttribute('data-theme', 'light');
            }
        })();
    </script>
    <style>
        .details-container {
            max-width: 1000px;
            margin: 0 auto;
        }

        .document-header-card {
            background: var(--dark-bg);
            border-radius: 16px;
            border: 1px solid var(--border-color);
            padding: 32px;
            margin-bottom: 24px;
            text-align: center;
        }

        .document-icon-large {
            width: 100px;
            height: 100px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 50px;
            margin-bottom: 20px;
        }

        .document-title {
            font-size: 2rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 16px;
        }

        .document-badge {
            display: inline-block;
            padding: 8px 20px;
            border-radius: 10px;
            font-size: 0.9rem;
            font-weight: 500;
            margin-bottom: 16px;
        }

        .section-card {
            background: var(--dark-bg);
            border-radius: 16px;
            border: 1px solid var(--border-color);
            padding: 28px;
            margin-bottom: 24px;
        }

        .section-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--border-color);
        }

        .section-title {
            color: var(--text-primary);
            font-size: 1.3rem;
            font-weight: 600;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }

        .info-box {
            background: var(--hover-bg);
            padding: 16px;
            border-radius: 12px;
            border: 1px solid var(--border-color);
        }

        .info-label {
            color: var(--text-secondary);
            font-size: 0.85rem;
            margin-bottom: 6px;
        }

        .info-value {
            color: var(--text-primary);
            font-size: 1.1rem;
            font-weight: 500;
        }

        .content-box {
            background: var(--hover-bg);
            padding: 20px;
            border-radius: 12px;
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            line-height: 1.8;
            white-space: pre-wrap;
        }

        .actions-bar {
            display: flex;
            gap: 12px;
            padding: 24px;
            background: var(--hover-bg);
            border-radius: 16px;
            border: 1px solid var(--border-color);
            justify-content: center;
        }

        .btn-download {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 14px 28px;
            background: transparent;
            color: var(--primary-orange);
            border: var(--primary-orange) 1px solid;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-download:hover {
            transform: translateY(-2px);
            background-color: var(--subtle-orange);
        }

        .btn-edit {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            background: transparent;
            color: var(--text-primary);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            font-size: 0.95rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-edit:hover {
            background: var(--primary-blue);
            border-color: var(--primary-blue);
            transform: translateY(-2px);
        }

        .btn-delete {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            background: transparent;
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.3);
            border-radius: 10px;
            font-size: 0.95rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-delete:hover {
            background: rgba(239, 68, 68, 0.1);
            border-color: #ef4444;
            transform: translateY(-2px);
        }

        .code-box {
            background: var(--hover-bg);
            padding: 16px;
            border-radius: 10px;
            border: 2px dashed var(--primary-green);
            text-align: center;
        }

        .code-label {
            color: var(--text-secondary);
            font-size: 0.85rem;
            margin-bottom: 8px;
        }

        .code-value {
            color: var(--primary-green);
            font-size: 1.5rem;
            font-weight: 700;
            font-family: monospace;
            letter-spacing: 2px;
        }
    </style>
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
                    <p>Información completa</p>
                </div>
            </div>
        </div>

        <div class="details-container">
            <!-- Header -->
            <div class="document-header-card">
                <div class="document-icon-large" style="background: <?php echo $icono_data['color']; ?>20; color: <?php echo $icono_data['color']; ?>;">
                    <span class="material-symbols-rounded"><?php echo $icono_data['icono']; ?></span>
                </div>
                <h1 class="document-title">
                    <?php 
                    if ($tipo === 'certificado') {
                        echo 'Certificado de ' . htmlspecialchars($documento['curso_nombre']) . ' - ' . htmlspecialchars($documento['estudiante_nombre']);
                    } else {
                        echo htmlspecialchars($documento['titulo'] ?? 'Sin título');
                    }
                    ?>
                </h1>
                <span class="document-badge badge-<?php echo $tipo; ?>">
                    <?php echo ucfirst($tipo); ?>
                </span>
            </div>

            <?php if ($tipo === 'certificado'): ?>
                <!-- CERTIFICADO -->
                <div class="section-card">
                    <div class="section-header">
                        <span class="material-symbols-rounded" style="font-size: 28px; color: var(--primary-green);">info</span>
                        <h2 class="section-title">Información del Certificado</h2>
                    </div>

                    <div class="code-box" style="margin-bottom: 24px;">
                        <div class="code-label">Código del Certificado</div>
                        <div class="code-value"><?php echo htmlspecialchars($documento['codigo_certificado']); ?></div>
                    </div>

                    <div class="info-grid">
                        <div class="info-box">
                            <div class="info-label">Estudiante</div>
                            <div class="info-value"><?php echo htmlspecialchars($documento['estudiante_nombre']); ?></div>
                        </div>

                        <div class="info-box">
                            <div class="info-label">Curso</div>
                            <div class="info-value"><?php echo htmlspecialchars($documento['curso_nombre']); ?></div>
                        </div>

                        <div class="info-box">
                            <div class="info-label">Grupo</div>
                            <div class="info-value"><?php echo htmlspecialchars($documento['grupo_nombre']); ?></div>
                        </div>

                        <div class="info-box">
                            <div class="info-label">Nivel Aprobado</div>
                            <div class="info-value"><?php echo ucfirst($documento['nivel_aprobado']); ?></div>
                        </div>

                        <div class="info-box">
                            <div class="info-label">Calificación Final</div>
                            <div class="info-value" style="color: var(--primary-green);"><?php echo number_format($documento['calificacion_final'], 1); ?></div>
                        </div>

                        <div class="info-box">
                            <div class="info-label">Fecha de Aprobación</div>
                            <div class="info-value" style="font-size: 0.95rem;"><?php echo formatear_fecha_completa($documento['fecha_aprobacion']); ?></div>
                        </div>

                        <?php if (!empty($documento['fecha_inicio_curso']) && !empty($documento['fecha_fin_curso'])): ?>
                        <div class="info-box">
                            <div class="info-label">Periodo del Curso</div>
                            <div class="info-value" style="font-size: 0.9rem;">
                                <?php echo date('d/m/Y', strtotime($documento['fecha_inicio_curso'])); ?> - 
                                <?php echo date('d/m/Y', strtotime($documento['fecha_fin_curso'])); ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($documento['aprobado_por_nombre'])): ?>
                        <div class="info-box">
                            <div class="info-label">Aprobado por</div>
                            <div class="info-value" style="font-size: 0.95rem;"><?php echo htmlspecialchars($documento['aprobado_por_nombre']); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($documento['observaciones'])): ?>
                    <div style="margin-top: 24px;">
                        <div class="info-label" style="margin-bottom: 12px;">Observaciones</div>
                        <div class="content-box">
                            <?php echo nl2br(htmlspecialchars($documento['observaciones'])); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

            <?php elseif ($tipo === 'comunicado'): ?>
                <!-- COMUNICADO -->
                <div class="section-card">
                    <div class="section-header">
                        <span class="material-symbols-rounded" style="font-size: 28px; color: var(--primary-blue);">info</span>
                        <h2 class="section-title">Información del Comunicado</h2>
                    </div>

                    <div class="info-grid">
                        <div class="info-box">
                            <div class="info-label">Categoría</div>
                            <div class="info-value"><?php echo ucfirst($documento['categoria']); ?></div>
                        </div>

                        <div class="info-box">
                            <div class="info-label">Prioridad</div>
                            <div class="info-value" style="color: <?php echo $documento['prioridad'] === 'urgente' ? '#ef4444' : 'var(--text-primary)'; ?>">
                                <?php echo ucfirst($documento['prioridad']); ?>
                            </div>
                        </div>

                        <div class="info-box">
                            <div class="info-label">Dirigido a</div>
                            <div class="info-value"><?php echo ucfirst($documento['dirigido_a']); ?></div>
                        </div>

                        <div class="info-box">
                            <div class="info-label">Fecha de Publicación</div>
                            <div class="info-value" style="font-size: 0.95rem;"><?php echo formatear_fecha_completa($documento['fecha_publicacion']); ?></div>
                        </div>

                        <div class="info-box">
                            <div class="info-label">Publicado por</div>
                            <div class="info-value" style="font-size: 0.95rem;"><?php echo htmlspecialchars($documento['publicado_por_nombre']); ?></div>
                        </div>
                    </div>

                    <?php if (!empty($documento['descripcion'])): ?>
                    <div style="margin-top: 24px;">
                        <div class="info-label" style="margin-bottom: 12px;">Descripción</div>
                        <div class="content-box">
                            <?php echo nl2br(htmlspecialchars($documento['descripcion'])); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

            <?php elseif ($tipo === 'acta'): ?>
                <!-- ACTA -->
                <div class="section-card">
                    <div class="section-header">
                        <span class="material-symbols-rounded" style="font-size: 28px; color: var(--primary-yellow);">info</span>
                        <h2 class="section-title">Información del Acta</h2>
                    </div>

                    <div class="info-grid">
                        <div class="info-box">
                            <div class="info-label">Tipo de Reunión</div>
                            <div class="info-value"><?php echo ucfirst(str_replace('_', ' ', $documento['tipo_reunion'])); ?></div>
                        </div>

                        <div class="info-box">
                            <div class="info-label">Lugar</div>
                            <div class="info-value" style="font-size: 0.95rem;"><?php echo htmlspecialchars($documento['lugar'] ?? 'No especificado'); ?></div>
                        </div>

                        <div class="info-box">
                            <div class="info-label">Fecha de Reunión</div>
                            <div class="info-value" style="font-size: 0.95rem;"><?php echo formatear_fecha_completa($documento['fecha_reunion']); ?></div>
                        </div>

                        <div class="info-box">
                            <div class="info-label">Creado por</div>
                            <div class="info-value" style="font-size: 0.95rem;"><?php echo htmlspecialchars($documento['creado_por_nombre']); ?></div>
                        </div>

                        <?php if (isset($documento['visibilidad'])): ?>
                        <div class="info-box">
                            <div class="info-label">Visibilidad</div>
                            <div class="info-value" style="font-size: 0.9rem;">
                                <?php 
                                $vis = ['solo_admin' => 'Solo Admin', 'admin_profesores' => 'Admin y Profesores', 'todos' => 'Todos'];
                                echo $vis[$documento['visibilidad']] ?? ucfirst($documento['visibilidad']); 
                                ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($documento['descripcion'])): ?>
                    <div style="margin-top: 24px;">
                        <div class="info-label" style="margin-bottom: 12px;">Descripción</div>
                        <div class="content-box">
                            <?php echo nl2br(htmlspecialchars($documento['descripcion'])); ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($documento['asistentes'])): ?>
                    <div style="margin-top: 24px;">
                        <div class="info-label" style="margin-bottom: 12px;">Asistentes</div>
                        <div class="content-box">
                            <?php echo nl2br(htmlspecialchars($documento['asistentes'])); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

            <?php endif; ?>

            <!-- Acciones -->
            <div class="actions-bar">
                <a class="btn-download" href="generar.php?tipo=<?php echo $tipo; ?>&id=<?php echo $doc_id; ?>" target="_blank">
                    <span class="material-symbols-rounded">picture_as_pdf</span>
                    Ver / Descargar PDF
                </a>
                <?php if ($user['rol'] === 'admin'): ?>
                <button class="btn-delete" 
                        onclick="if(confirm('¿Estás seguro de eliminar este documento?')) window.location.href='eliminar.php?tipo=<?php echo $tipo; ?>&id=<?php echo $doc_id; ?>'">
                    <span class="material-symbols-rounded">delete</span>
                    Eliminar
                </button>
                <?php endif; ?>
            </div>
        </div>
    </main>
</body>
</html>