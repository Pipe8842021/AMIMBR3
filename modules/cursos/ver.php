<?php
/**
 * Ver Detalles del Curso
 * Sistema Amimbré
 */

require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth_check.php';
require_role('admin');

// Obtener ID del curso
$curso_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($curso_id <= 0) {
    header("Location: index.php?error=curso_no_encontrado");
    exit;
}

// Obtener datos del curso
try {
    $stmt = $pdo->prepare("
        SELECT 
            c.*,
            COUNT(DISTINCT g.id) as total_grupos,
            COUNT(DISTINCT CASE WHEN m.estado = 'activa' THEN m.estudiante_id END) as estudiantes_matriculados
        FROM cursos c
        LEFT JOIN grupos g ON c.id = g.curso_id AND g.estado = 'activo'
        LEFT JOIN matriculas m ON g.id = m.grupo_id AND m.estado = 'activa'
        WHERE c.id = ?
        GROUP BY c.id
    ");
    $stmt->execute([$curso_id]);
    $curso = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$curso) {
        header("Location: index.php?error=curso_no_encontrado");
        exit;
    }
    
    // Obtener grupos del curso
    $stmt = $pdo->prepare("
        SELECT 
            g.*,
            u.nombre as profesor_nombre,
            COUNT(DISTINCT m.estudiante_id) as estudiantes_inscritos
        FROM grupos g
        LEFT JOIN usuarios u ON g.profesor_id = u.id
        LEFT JOIN matriculas m ON g.id = m.grupo_id AND m.estado = 'activa'
        WHERE g.curso_id = ?
        GROUP BY g.id
        ORDER BY g.estado DESC, g.nombre
    ");
    $stmt->execute([$curso_id]);
    $grupos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error al obtener curso: " . $e->getMessage());
    header("Location: index.php?error=error_sistema");
    exit;
}

// Función de imagen robusta (idéntica a index.php)
function get_imagen_curso(string $nombre, ?string $imagenBD): string {
    $rutaBase   = '../../assets/img/cursos/';
    $porDefecto = $rutaBase . 'musica-default.jpg';

    if (!empty($imagenBD)) {
        if (strpos($imagenBD, '/') !== false) return htmlspecialchars($imagenBD);
        return htmlspecialchars($rutaBase . $imagenBD);
    }

    $map = [
        'Piano'                       => 'piano.jpg',
        'Piano Clásico'               => 'piano.jpg',
        'Guitarra'                    => 'guitarra.jpg',
        'Guitarra Acústica'           => 'guitarra.jpg',
        'Canto y Técnica Vocal'       => 'canto.jpg',
        'Técnica Vocal y Canto'       => 'canto.jpg',
        'Violín'                      => 'violin.jpg',
        'Violín Clásico'              => 'violin.jpg',
        'Ensambles Musicales'         => 'ensambles.jpg',
        'Ensamble musical'            => 'ensambles.jpg',
        'Iniciación Musical Infantil' => 'iniciacion_infantil.jpg',
        'Instrumentos de Viento'      => 'viento.jpg',
        'Preparación Universitaria'   => 'preparacion_universitaria.jpg',
        'Teoría y Lenguaje Musical'   => 'teoria_lenguaje.jpg',
    ];

    return isset($map[$nombre])
        ? htmlspecialchars($rutaBase . $map[$nombre])
        : htmlspecialchars($porDefecto);
}

// Función para badge de nivel
function get_nivel_badge($nivel) {
    $badges = [
        'basico' => ['texto' => 'Básico', 'clase' => 'nivel-basico'],
        'intermedio' => ['texto' => 'Intermedio', 'clase' => 'nivel-intermedio'],
        'avanzado' => ['texto' => 'Avanzado', 'clase' => 'nivel-avanzado']
    ];
    return $badges[$nivel] ?? ['texto' => 'Sin nivel', 'clase' => ''];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($curso['nombre']); ?> - Amimbré</title>
    <link rel="shortcut icon" href="../../assets/img/3.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0" />
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="../../assets/css/colores.css">
    <link rel="stylesheet" href="../../assets/css/style-cursos-ver.css">
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
    <?php require_once '../../includes/header.php'; ?>

    <main class="main-content">
        <!-- Header -->
        <div class="page-header">
            <div class="header-nav">
                <a href="index.php" class="btn-back">
                    <span class="material-symbols-rounded">arrow_back</span>
                    Volver
                </a>
            </div>
        </div>

        <!-- Curso Details -->
        <div class="curso-detalle">
            <div class="curso-hero">
                <?php $imagen_src = get_imagen_curso($curso['nombre'], $curso['imagen']); ?>
                <div class="hero-imagen">
                    <img 
                        src="<?php echo $imagen_src; ?>" 
                        alt="<?php echo htmlspecialchars($curso['nombre']); ?>"
                        onerror="this.onerror=null; this.src='../../assets/img/cursos/musica-default.jpg';"
                    >
                </div>
                
                <div class="hero-info">
                    <div class="badges">
                        <?php 
                        $nivel_badge = get_nivel_badge($curso['nivel']);
                        ?>
                        <span class="badge <?php echo $nivel_badge['clase']; ?>">
                            <?php echo $nivel_badge['texto']; ?>
                        </span>
                        <span class="badge <?php echo $curso['estado'] === 'activo' ? 'estado-activo' : 'estado-inactivo'; ?>">
                            <?php echo ucfirst($curso['estado']); ?>
                        </span>
                    </div>
                    
                    <h1><?php echo htmlspecialchars($curso['nombre']); ?></h1>
                    
                    <p class="descripcion">
                        <?php echo nl2br(htmlspecialchars($curso['descripcion'] ?? 'Sin descripción disponible')); ?>
                    </p>
                    
                    <div class="acciones-rapidas">
                        <a href="editar.php?id=<?php echo $curso['id']; ?>" class="btn-editar">
                            <span class="material-symbols-rounded">edit</span>
                            Editar Curso
                        </a>
                        <button 
                            onclick="confirmarEliminacion(<?php echo $curso['id']; ?>, '<?php echo htmlspecialchars($curso['nombre'], ENT_QUOTES); ?>')" 
                            class="btn-eliminar">
                            <span class="material-symbols-rounded">delete</span>
                            Eliminar
                        </button>
                    </div>
                </div>
            </div>

            <!-- Información del curso -->
            <div class="info-grid">
                <div class="info-card">
                    <span class="material-symbols-rounded">schedule</span>
                    <div class="info-content">
                        <span class="info-label">Duración</span>
                        <span class="info-value"><?php echo $curso['duracion_meses']; ?> meses</span>
                    </div>
                </div>

                <div class="info-card">
                    <span class="material-symbols-rounded">payments</span>
                    <div class="info-content">
                        <span class="info-label">Precio Mensual</span>
                        <span class="info-value">$<?php echo number_format($curso['precio_mensual'], 0, ',', '.'); ?></span>
                    </div>
                </div>

                <div class="info-card">
                    <span class="material-symbols-rounded">groups</span>
                    <div class="info-content">
                        <span class="info-label">Cupo por Grupo</span>
                        <span class="info-value"><?php echo $curso['cupo_maximo']; ?> estudiantes</span>
                    </div>
                </div>

                <div class="info-card">
                    <span class="material-symbols-rounded">school</span>
                    <div class="info-content">
                        <span class="info-label">Estudiantes Inscritos</span>
                        <span class="info-value"><?php echo $curso['estudiantes_matriculados']; ?></span>
                    </div>
                </div>
            </div>

            <!-- Requisitos -->
            <?php if (!empty($curso['requisitos'])): ?>
            <div class="seccion-detalle">
                <h3><span class="material-symbols-rounded">checklist</span> Requisitos</h3>
                <p><?php echo nl2br(htmlspecialchars($curso['requisitos'])); ?></p>
            </div>
            <?php endif; ?>

            <!-- Grupos del Curso -->
            <div class="seccion-grupos">
                <div class="grupos-header">
                    <h3>
                        <span class="material-symbols-rounded">group_work</span>
                        Grupos del Curso
                    </h3>
                    <a href="../grupos/admin.php" class="btn-nuevo-grupo">
                        <span class="material-symbols-rounded">add</span>
                        Nuevo Grupo
                    </a>
                </div>

                <?php if (count($grupos) > 0): ?>
                <div class="grupos-lista">
                    <?php foreach ($grupos as $grupo): ?>
                    <div class="grupo-card">
                        <div class="grupo-header">
                            <h4><?php echo htmlspecialchars($grupo['nombre']); ?></h4>
                            <span class="badge-estado <?php echo $grupo['estado'] === 'activo' ? 'estado-activo' : 'estado-inactivo'; ?>">
                                <?php echo ucfirst($grupo['estado']); ?>
                            </span>
                        </div>

                        <div class="grupo-info">
                            <div class="info-item">
                                <span class="material-symbols-rounded">person</span>
                                <span><?php echo $grupo['profesor_nombre'] ?? 'Sin profesor'; ?></span>
                            </div>
                            <div class="info-item">
                                <span class="material-symbols-rounded">groups</span>
                                <span><?php echo $grupo['estudiantes_inscritos']; ?>/<?php echo $grupo['cupo_maximo']; ?> estudiantes</span>
                            </div>
                        </div>

                        <div class="grupo-acciones">
                            <a href="../grupos/ver.php?id=<?php echo $grupo['id']; ?>" class="btn-ver">Ver Detalles</a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <span class="material-symbols-rounded">group_off</span>
                    <p>No hay grupos creados para este curso</p>
                    <a href="../grupos/crear.php?curso_id=<?php echo $curso['id']; ?>" class="btn-crear-grupo">
                        <span class="material-symbols-rounded">add</span>
                        Crear Primer Grupo
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- Modal de confirmación -->
    <div id="modalEliminar" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <span class="material-symbols-rounded modal-icon-warning">warning</span>
                <h2>Confirmar Eliminación</h2>
            </div>
            <div class="modal-body">
                <p>¿Estás seguro de que deseas eliminar el curso <strong id="nombreCurso"></strong>?</p>
                <p class="warning-text">Esta acción eliminará todos los grupos asociados y no se puede deshacer.</p>
            </div>
            <div class="modal-footer">
                <button onclick="cerrarModal()" class="btn-cancelar">Cancelar</button>
                <button onclick="eliminarCurso()" class="btn-confirmar-eliminar">Eliminar</button>
            </div>
        </div>
    </div>

    <script>
        let cursoIdEliminar = null;

        function confirmarEliminacion(id, nombre) {
            cursoIdEliminar = id;
            document.getElementById('nombreCurso').textContent = nombre;
            document.getElementById('modalEliminar').style.display = 'flex';
        }

        function cerrarModal() {
            document.getElementById('modalEliminar').style.display = 'none';
            cursoIdEliminar = null;
        }

        function eliminarCurso() {
            if (cursoIdEliminar) {
                window.location.href = `eliminar.php?id=${cursoIdEliminar}`;
            }
        }

        window.onclick = function(event) {
            const modal = document.getElementById('modalEliminar');
            if (event.target === modal) {
                cerrarModal();
            }
        }
    </script>
</body>
</html>