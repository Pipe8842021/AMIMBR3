<?php
/**
 * Ver Detalles de Bitácora
 */

require_once '../../../../config/session.php';
require_once '../../../../config/database.php';
require_once '../../../../includes/auth_check.php';

// Obtener ID de la bitácora
$bitacora_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($bitacora_id === 0) {
    header("Location: ../index.php?error=bitacora_no_encontrada");
    exit;
}

// Obtener datos del usuario actual
try {
    $stmt = $pdo->prepare("SELECT id, nombre, email, rol FROM usuarios WHERE id = ? AND estado = 'activo'");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        session_destroy();
        header("Location: ../../../../auth/login.php");
        exit;
    }
} catch (PDOException $e) {
    die("Error del sistema");
}

try {
    // Obtener bitácora con información relacionada
    $stmt = $pdo->prepare("
        SELECT b.*, 
               c.nombre as curso_nombre,
               g.nombre as grupo_nombre,
               u.nombre as profesor_nombre,
               u.email as profesor_email
        FROM bitacoras b
        INNER JOIN cursos c ON b.curso_id = c.id
        INNER JOIN grupos g ON b.grupo_id = g.id
        INNER JOIN usuarios u ON b.profesor_id = u.id
        WHERE b.id = ? AND b.estado = 'activo'
    ");
    $stmt->execute([$bitacora_id]);
    $bitacora = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$bitacora) {
        header("Location: ../index.php?error=bitacora_no_encontrada");
        exit;
    }
    
    // Obtener asistencias
    $stmt = $pdo->prepare("
        SELECT ba.*, u.nombre as estudiante_nombre
        FROM bitacoras_asistencias ba
        INNER JOIN usuarios u ON ba.estudiante_id = u.id
        WHERE ba.bitacora_id = ?
        ORDER BY u.nombre
    ");
    $stmt->execute([$bitacora_id]);
    $asistencias = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener evidencias
    $stmt = $pdo->prepare("
        SELECT * FROM bitacoras_evidencias
        WHERE bitacora_id = ?
        ORDER BY orden
    ");
    $stmt->execute([$bitacora_id]);
    $evidencias = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calcular estadísticas de asistencia
    $total_estudiantes = count($asistencias);
    $presentes = count(array_filter($asistencias, fn($a) => $a['estado'] === 'presente'));
    $ausentes = count(array_filter($asistencias, fn($a) => $a['estado'] === 'ausente'));
    $justificados = count(array_filter($asistencias, fn($a) => $a['estado'] === 'justificado'));
    $tardanzas = count(array_filter($asistencias, fn($a) => $a['estado'] === 'tardanza'));
    
} catch (PDOException $e) {
    error_log("Error: " . $e->getMessage());
    header("Location: ../index.php?error=error_sistema");
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
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($bitacora['titulo']); ?> - Amimbré</title>
    <link rel="shortcut icon" href="../../../../assets/img/3.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0" />
    <link rel="stylesheet" href="../../../../assets/css/colores.css">
    <link rel="stylesheet" href="../../../../assets/css/style-documentos-institucionales.css">
    <style>
        .details-container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .bitacora-header-card {
            background: var(--dark-bg);
            border-radius: 16px;
            border: 1px solid var(--border-color);
            padding: 32px;
            margin-bottom: 24px;
        }

        .bitacora-title {
            font-size: 2rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 16px;
        }

        .bitacora-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 24px;
            color: var(--text-secondary);
            font-size: 0.95rem;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 8px;
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
            margin-bottom: 20px;
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

        .tags-container {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .tag {
            padding: 6px 16px;
            background: var(--subtle-orange);
            color: var(--primary-orange);
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .attendance-list {
            display: grid;
            gap: 12px;
        }

        .attendance-item {
            background: var(--hover-bg);
            padding: 16px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            display: grid;
            grid-template-columns: 1fr auto auto;
            gap: 16px;
            align-items: center;
        }

        .student-name {
            color: var(--text-primary);
            font-weight: 500;
        }

        .attendance-badge {
            padding: 6px 14px;
            border-radius: 8px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .attendance-badge.presente {
            background: var(--subtle-green);
            color: var(--primary-green);
        }

        .attendance-badge.ausente {
            background: rgba(239, 68, 68, 0.2);
            color: #ef4444;
        }

        .attendance-badge.justificado {
            background: var(--subtle-yellow);
            color: var(--primary-yellow);
        }

        .attendance-badge.tardanza {
            background: var(--subtle-orange);
            color: var(--primary-orange);
        }

        .observation-text {
            color: var(--text-secondary);
            font-size: 0.85rem;
            font-style: italic;
        }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-box {
            text-align: center;
            padding: 16px;
            background: var(--hover-bg);
            border-radius: 10px;
            border: 1px solid var(--border-color);
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 4px;
        }

        .stat-label {
            color: var(--text-secondary);
            font-size: 0.85rem;
        }

        .evidence-gallery {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
        }

        .evidence-item {
            background: var(--hover-bg);
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }

        .evidence-item:hover {
            transform: translateY(-4px);
            border-color: var(--primary-blue);
        }

        .evidence-image {
            width: 100%;
            height: 200px;
            object-fit: cover;
            cursor: pointer;
        }

        .evidence-caption {
            padding: 12px;
            color: var(--text-secondary);
            font-size: 0.85rem;
        }

        .actions-bar {
            display: flex;
            gap: 12px;
            padding: 24px;
            border-top: 1px solid var(--border-color);
            background: var(--hover-bg);
            border-radius: 0 0 16px 16px;
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

        .empty-state-small {
            text-align: center;
            padding: 40px;
            color: var(--text-secondary);
        }

        .empty-state-small span {
            font-size: 48px;
            display: block;
            margin-bottom: 12px;
            opacity: 0.5;
        }

        /* Modal para ver imágenes */
        .modal {
            display: none;
            position: fixed;
            z-index: 9999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.9);
        }

        .modal-content {
            margin: auto;
            display: block;
            max-width: 90%;
            max-height: 90%;
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
        }

        .close-modal {
            position: absolute;
            top: 20px;
            right: 40px;
            color: #fff;
            font-size: 40px;
            font-weight: bold;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <?php require_once '../../../../includes/header.php'; ?>

    <main class="main-content">
        <div class="page-header">
            <div class="header-left">
                <button class="btn-back" onclick="window.location.href='../index.php'">
                    <span class="material-symbols-rounded">arrow_back</span>
                </button>
                <div class="header-info">
                    <h1>Detalles de Bitácora</h1>
                    <p>Información completa de la clase</p>
                </div>
            </div>
        </div>

        <div class="details-container">
            <!-- Header de la Bitácora -->
            <div class="bitacora-header-card">
                <h1 class="bitacora-title"><?php echo htmlspecialchars($bitacora['titulo']); ?></h1>
                <div class="bitacora-meta">
                    <div class="meta-item">
                        <span class="material-symbols-rounded">event</span>
                        <?php echo formatear_fecha_completa($bitacora['fecha_clase']); ?>
                    </div>
                    <div class="meta-item">
                        <span class="material-symbols-rounded">schedule</span>
                        <?php echo date('h:i A', strtotime($bitacora['hora_inicio'])); ?> - 
                        <?php echo date('h:i A', strtotime($bitacora['hora_fin'])); ?>
                    </div>
                    <div class="meta-item">
                        <span class="material-symbols-rounded">school</span>
                        <?php echo htmlspecialchars($bitacora['curso_nombre']); ?>
                    </div>
                    <div class="meta-item">
                        <span class="material-symbols-rounded">group</span>
                        <?php echo htmlspecialchars($bitacora['grupo_nombre']); ?>
                    </div>
                    <div class="meta-item">
                        <span class="material-symbols-rounded">person</span>
                        <?php echo htmlspecialchars($bitacora['profesor_nombre']); ?>
                    </div>
                </div>
            </div>

            <!-- Información General -->
            <div class="section-card">
                <div class="section-header">
                    <span class="material-symbols-rounded" style="font-size: 28px; color: var(--primary-blue);">info</span>
                    <h2 class="section-title">Información General</h2>
                </div>

                <div class="info-grid">
                    <div class="info-box">
                        <div class="info-label">Curso</div>
                        <div class="info-value"><?php echo htmlspecialchars($bitacora['curso_nombre']); ?></div>
                    </div>
                    <div class="info-box">
                        <div class="info-label">Grupo</div>
                        <div class="info-value"><?php echo htmlspecialchars($bitacora['grupo_nombre']); ?></div>
                    </div>
                    <div class="info-box">
                        <div class="info-label">Profesor</div>
                        <div class="info-value"><?php echo htmlspecialchars($bitacora['profesor_nombre']); ?></div>
                    </div>
                    <div class="info-box">
                        <div class="info-label">Registrado</div>
                        <div class="info-value" style="font-size: 0.9rem;">
                            <?php echo tiempo_transcurrido($bitacora['fecha_creacion']); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Temas Tratados -->
            <div class="section-card">
                <div class="section-header">
                    <span class="material-symbols-rounded" style="font-size: 28px; color: var(--primary-orange);">subject</span>
                    <h2 class="section-title">Temas Tratados</h2>
                </div>
                <div class="tags-container">
                    <?php 
                    $temas = explode(',', $bitacora['temas_tratados']);
                    foreach ($temas as $tema): 
                        $tema = trim($tema);
                        if (!empty($tema)):
                    ?>
                    <span class="tag"><?php echo htmlspecialchars($tema); ?></span>
                    <?php 
                        endif;
                    endforeach; 
                    ?>
                </div>
            </div>

            <!-- Descripción de la Clase -->
            <div class="section-card">
                <div class="section-header">
                    <span class="material-symbols-rounded" style="font-size: 28px; color: var(--primary-green);">description</span>
                    <h2 class="section-title">Descripción de la Clase</h2>
                </div>
                <div class="content-box">
                    <?php echo nl2br(htmlspecialchars($bitacora['descripcion_clase'])); ?>
                </div>
            </div>

            <!-- Observaciones -->
            <?php if (!empty($bitacora['observaciones'])): ?>
            <div class="section-card">
                <div class="section-header">
                    <span class="material-symbols-rounded" style="font-size: 28px; color: var(--primary-yellow);">comment</span>
                    <h2 class="section-title">Observaciones</h2>
                </div>
                <div class="content-box">
                    <?php echo nl2br(htmlspecialchars($bitacora['observaciones'])); ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Compromisos -->
            <?php if (!empty($bitacora['compromisos_proxima_clase'])): ?>
            <div class="section-card">
                <div class="section-header">
                    <span class="material-symbols-rounded" style="font-size: 28px; color: var(--primary-blue);">task_alt</span>
                    <h2 class="section-title">Compromisos para la Próxima Clase</h2>
                </div>
                <div class="content-box">
                    <?php echo nl2br(htmlspecialchars($bitacora['compromisos_proxima_clase'])); ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Asistencia -->
            <div class="section-card">
                <div class="section-header">
                    <span class="material-symbols-rounded" style="font-size: 28px; color: var(--primary-green);">how_to_reg</span>
                    <h2 class="section-title">Registro de Asistencia</h2>
                </div>

                <?php if (count($asistencias) > 0): ?>
                    <div class="stats-row">
                        <div class="stat-box">
                            <div class="stat-number" style="color: var(--primary-green);"><?php echo $presentes; ?></div>
                            <div class="stat-label">Presentes</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-number" style="color: #ef4444;"><?php echo $ausentes; ?></div>
                            <div class="stat-label">Ausentes</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-number" style="color: var(--primary-yellow);"><?php echo $justificados; ?></div>
                            <div class="stat-label">Justificados</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-number" style="color: var(--primary-orange);"><?php echo $tardanzas; ?></div>
                            <div class="stat-label">Tardanzas</div>
                        </div>
                    </div>

                    <div class="attendance-list">
                        <?php foreach ($asistencias as $asistencia): ?>
                        <div class="attendance-item">
                            <div class="student-name"><?php echo htmlspecialchars($asistencia['estudiante_nombre']); ?></div>
                            <span class="attendance-badge <?php echo $asistencia['estado']; ?>">
                                <?php echo ucfirst($asistencia['estado']); ?>
                            </span>
                            <?php if (!empty($asistencia['observacion'])): ?>
                            <div class="observation-text"><?php echo htmlspecialchars($asistencia['observacion']); ?></div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state-small">
                        <span class="material-symbols-rounded">group_off</span>
                        <div>No se registró asistencia para esta clase</div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Evidencias Fotográficas -->
            <?php if (count($evidencias) > 0): ?>
            <div class="section-card">
                <div class="section-header">
                    <span class="material-symbols-rounded" style="font-size: 28px; color: var(--primary-orange);">photo_camera</span>
                    <h2 class="section-title">Evidencias Fotográficas</h2>
                </div>

                <div class="evidence-gallery">
                    <?php foreach ($evidencias as $evidencia): ?>
                    <div class="evidence-item">
                        <img src="../../../../assets/uploads/bitacoras/evidencias/<?php echo basename($evidencia['ruta_archivo']); ?>" 
                             alt="Evidencia" 
                             class="evidence-image"
                             onclick="openModal(this.src)">
                        <?php if (!empty($evidencia['descripcion'])): ?>
                        <div class="evidence-caption">
                            <?php echo htmlspecialchars($evidencia['descripcion']); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Acciones -->
            <div class="section-card" style="padding: 0; overflow: hidden;">
                <div class="actions-bar">
                    <?php if ($user['rol'] === 'admin' || $bitacora['profesor_id'] == $user['id']): ?>
                    <button class="btn-edit" onclick="window.location.href='editar.php?id=<?php echo $bitacora_id; ?>'">
                        <span class="material-symbols-rounded">edit</span>
                        Editar
                    </button>
                    <?php endif; ?>
                    <?php if ($user['rol'] === 'admin'): ?>
                    <button class="btn-delete" 
                            onclick="if(confirm('¿Estás seguro de eliminar esta bitácora?')) window.location.href='eliminar.php?id=<?php echo $bitacora_id; ?>'">
                        <span class="material-symbols-rounded">delete</span>
                        Eliminar
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <!-- Modal para ver imágenes -->
    <div id="imageModal" class="modal" onclick="closeModal()">
        <span class="close-modal">&times;</span>
        <img class="modal-content" id="modalImage">
    </div>

    <script>
        function openModal(src) {
            const modal = document.getElementById('imageModal');
            const modalImg = document.getElementById('modalImage');
            modal.style.display = 'block';
            modalImg.src = src;
        }

        function closeModal() {
            document.getElementById('imageModal').style.display = 'none';
        }

        // Cerrar con ESC
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeModal();
        });
    </script>
</body>
</html>