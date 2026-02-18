<?php
/**
 * Gestión de Cursos - Vista Principal
 * Sistema Amimbré - Control multi-rol (admin / profesor / estudiante)
 */

require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth_check.php';

// Permitir acceso a admin, profesor y estudiante
require_any_role(['admin', 'profesor', 'estudiante']);

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

$rol      = $user['rol'];
$user_id  = (int)$_SESSION['user_id'];

$es_admin     = ($rol === 'admin');
$es_profesor  = ($rol === 'profesor');
$es_estudiante = ($rol === 'estudiante');

// Filtros: solo disponibles para admin
$search           = $es_admin && isset($_GET['search'])    ? trim($_GET['search'])    : '';
$filter_categoria = $es_admin && isset($_GET['categoria']) ? $_GET['categoria']       : 'todos';
$filter_nivel     = $es_admin && isset($_GET['nivel'])     ? $_GET['nivel']           : 'todos';

// ─── Estadísticas (solo admin) ───────────────────────────────────────────────
$total_cursos = $cursos_activos = $estudiantes_inscritos = $grupos_sin_profesor = 0;

if ($es_admin) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM cursos");
        $total_cursos = $stmt->fetch()['total'] ?? 0;

        $stmt = $pdo->query("SELECT COUNT(*) as total FROM cursos WHERE estado = 'activo'");
        $cursos_activos = $stmt->fetch()['total'] ?? 0;

        $stmt = $pdo->query("
            SELECT COUNT(DISTINCT m.estudiante_id) as total 
            FROM matriculas m
            INNER JOIN grupos g ON m.grupo_id = g.id
            WHERE m.estado = 'activa'
        ");
        $estudiantes_inscritos = $stmt->fetch()['total'] ?? 0;

        $stmt = $pdo->query("
            SELECT COUNT(*) as total 
            FROM grupos 
            WHERE profesor_id IS NULL AND estado = 'activo'
        ");
        $grupos_sin_profesor = $stmt->fetch()['total'] ?? 0;

    } catch (PDOException $e) {
        error_log("Error obteniendo estadísticas: " . $e->getMessage());
    }
}

// ─── Consulta de cursos según rol ────────────────────────────────────────────
$params = [];

if ($es_admin) {
    // Todos los cursos con filtros opcionales
    $query = "
        SELECT 
            c.id, c.nombre, c.descripcion, c.duracion_meses, c.nivel,
            c.cupo_maximo, c.precio_mensual, c.estado, c.imagen, c.requisitos,
            COUNT(DISTINCT g.id) as total_grupos,
            COUNT(DISTINCT CASE WHEN m.estado = 'activa' THEN m.estudiante_id END) as estudiantes_matriculados,
            GROUP_CONCAT(DISTINCT u.nombre SEPARATOR ', ') as profesores
        FROM cursos c
        LEFT JOIN grupos g ON c.id = g.curso_id AND g.estado = 'activo'
        LEFT JOIN matriculas m ON g.id = m.grupo_id AND m.estado = 'activa'
        LEFT JOIN usuarios u ON g.profesor_id = u.id
        WHERE 1=1
    ";
    if (!empty($search)) {
        $query .= " AND (c.nombre LIKE ? OR c.descripcion LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    if ($filter_nivel !== 'todos') {
        $query .= " AND c.nivel = ?";
        $params[] = $filter_nivel;
    }
    if ($filter_categoria !== 'todos') {
        $query .= " AND c.estado = ?";
        $params[] = $filter_categoria;
    }
    $query .= " GROUP BY c.id ORDER BY c.nombre ASC";

} elseif ($es_profesor) {
    // Solo cursos donde el profesor tiene grupo activo asignado
    $query = "
        SELECT 
            c.id, c.nombre, c.descripcion, c.duracion_meses, c.nivel,
            c.cupo_maximo, c.precio_mensual, c.estado, c.imagen, c.requisitos,
            COUNT(DISTINCT g.id) as total_grupos,
            COUNT(DISTINCT CASE WHEN m.estado = 'activa' THEN m.estudiante_id END) as estudiantes_matriculados,
            GROUP_CONCAT(DISTINCT u.nombre SEPARATOR ', ') as profesores
        FROM cursos c
        INNER JOIN grupos g ON c.id = g.curso_id AND g.estado = 'activo' AND g.profesor_id = ?
        LEFT JOIN matriculas m ON g.id = m.grupo_id AND m.estado = 'activa'
        LEFT JOIN usuarios u ON g.profesor_id = u.id
        WHERE c.estado = 'activo'
        GROUP BY c.id
        ORDER BY c.nombre ASC
    ";
    $params[] = $user_id;

} elseif ($es_estudiante) {
    // Solo cursos en los que el estudiante tiene matrícula activa
    $query = "
        SELECT 
            c.id, c.nombre, c.descripcion, c.duracion_meses, c.nivel,
            c.cupo_maximo, c.precio_mensual, c.estado, c.imagen, c.requisitos,
            COUNT(DISTINCT g.id) as total_grupos,
            COUNT(DISTINCT CASE WHEN m2.estado = 'activa' THEN m2.estudiante_id END) as estudiantes_matriculados,
            GROUP_CONCAT(DISTINCT u.nombre SEPARATOR ', ') as profesores
        FROM cursos c
        INNER JOIN grupos g ON c.id = g.curso_id AND g.estado = 'activo'
        INNER JOIN matriculas m ON g.id = m.grupo_id AND m.estudiante_id = ? AND m.estado = 'activa'
        LEFT JOIN matriculas m2 ON g.id = m2.grupo_id AND m2.estado = 'activa'
        LEFT JOIN usuarios u ON g.profesor_id = u.id
        WHERE c.estado = 'activo'
        GROUP BY c.id
        ORDER BY c.nombre ASC
    ";
    $params[] = $user_id;
}

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $cursos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error obteniendo cursos: " . $e->getMessage());
    $cursos = [];
}

// ─── Helpers ─────────────────────────────────────────────────────────────────
function get_nivel_badge($nivel) {
    $badges = [
        'basico'     => ['texto' => 'Básico',    'clase' => 'nivel-basico'],
        'intermedio' => ['texto' => 'Intermedio', 'clase' => 'nivel-intermedio'],
        'avanzado'   => ['texto' => 'Avanzado',   'clase' => 'nivel-avanzado'],
    ];
    return $badges[$nivel] ?? ['texto' => 'Sin nivel', 'clase' => ''];
}

function get_estado_badge($estado) {
    return $estado === 'activo' ? 'estado-activo' : 'estado-inactivo';
}

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
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        <?php
        if ($es_admin)       echo 'Gestión de Cursos';
        elseif ($es_profesor) echo 'Mis Cursos';
        else                  echo 'Mis Cursos Matriculados';
        ?> - Amimbré
    </title>
    <link rel="shortcut icon" href="../../assets/img/3.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0" />
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="../../assets/css/colores.css">
    <link rel="stylesheet" href="../../assets/css/style-cursos.css">
</head>
<body>
    <?php
    if (file_exists('../../includes/header.php')) {
        require_once '../../includes/header.php';
    }
    ?>

    <main class="main-content">

        <!-- ── Page Header ──────────────────────────────────────────────── -->
        <div class="page-header">
            <div class="header-content">
                <div class="title-section">
                    <?php if ($es_admin): ?>
                        <h1>Gestión de Cursos</h1>
                        <p>Administra todos los cursos de la escuela</p>
                    <?php elseif ($es_profesor): ?>
                        <h1>Mis Cursos</h1>
                        <p>Cursos en los que estás asignado como profesor</p>
                    <?php else: ?>
                        <h1>Mis Cursos</h1>
                        <p>Cursos en los que estás matriculado</p>
                    <?php endif; ?>
                </div>

                <?php if ($es_admin): ?>
                <a href="crear.php" class="btn-nuevo-curso">
                    <span class="material-symbols-rounded">add</span>
                    Nuevo Curso
                </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── Alerta de éxito ───────────────────────────────────────────── -->
        <?php if ($es_admin && isset($_GET['success']) && $_GET['success'] === 'curso_creado'): ?>
        <div class="alert alert-success" id="alertaExito">
            <span class="material-symbols-rounded">check_circle</span>
            <span>Curso creado exitosamente.</span>
        </div>
        <script>
            setTimeout(function () {
                const a = document.getElementById('alertaExito');
                if (a) a.style.display = 'none';
            }, 4000);
        </script>
        <?php endif; ?>

        <!-- ── Stats Cards (solo admin) ─────────────────────────────────── -->
        <?php if ($es_admin): ?>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon total">
                    <span class="material-symbols-rounded">library_books</span>
                </div>
                <div class="stat-info">
                    <span class="stat-label">Total Cursos</span>
                    <span class="stat-value"><?php echo $total_cursos; ?></span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon activo">
                    <span class="material-symbols-rounded">check_circle</span>
                </div>
                <div class="stat-info">
                    <span class="stat-label">Cursos Activos</span>
                    <span class="stat-value"><?php echo $cursos_activos; ?></span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon estudiantes">
                    <span class="material-symbols-rounded">school</span>
                </div>
                <div class="stat-info">
                    <span class="stat-label">Estudiantes Inscritos</span>
                    <span class="stat-value"><?php echo $estudiantes_inscritos; ?></span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon profesor">
                    <span class="material-symbols-rounded">person_off</span>
                </div>
                <div class="stat-info">
                    <span class="stat-label">Sin Profesor</span>
                    <span class="stat-value"><?php echo $grupos_sin_profesor; ?></span>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ── Filtros (solo admin) ──────────────────────────────────────── -->
        <?php if ($es_admin): ?>
        <div class="filter-section">
            <form method="GET" action="" class="filter-form">
                <div class="search-box">
                    <span class="material-symbols-rounded">search</span>
                    <input
                        type="text"
                        name="search"
                        placeholder="Buscar por nombre, descripción o profesor..."
                        value="<?php echo htmlspecialchars($search); ?>"
                    >
                </div>
                <div class="filter-group">
                    <select name="categoria" id="categoria" onchange="this.form.submit()">
                        <option value="todos"    <?php echo $filter_categoria === 'todos'    ? 'selected' : ''; ?>>Todas las categorías</option>
                        <option value="activo"   <?php echo $filter_categoria === 'activo'   ? 'selected' : ''; ?>>Activos</option>
                        <option value="inactivo" <?php echo $filter_categoria === 'inactivo' ? 'selected' : ''; ?>>Inactivos</option>
                    </select>
                    <select name="nivel" id="nivel" onchange="this.form.submit()">
                        <option value="todos"      <?php echo $filter_nivel === 'todos'      ? 'selected' : ''; ?>>Todos los niveles</option>
                        <option value="basico"     <?php echo $filter_nivel === 'basico'     ? 'selected' : ''; ?>>Básico</option>
                        <option value="intermedio" <?php echo $filter_nivel === 'intermedio' ? 'selected' : ''; ?>>Intermedio</option>
                        <option value="avanzado"   <?php echo $filter_nivel === 'avanzado'   ? 'selected' : ''; ?>>Avanzado</option>
                    </select>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- ── Cursos Grid ───────────────────────────────────────────────── -->
        <div class="cursos-grid">
            <?php if (count($cursos) > 0): ?>
                <?php foreach ($cursos as $curso): ?>
                    <?php
                    $nivel_badge  = get_nivel_badge($curso['nivel']);
                    $estado_clase = get_estado_badge($curso['estado']);
                    $imagen_src   = get_imagen_curso($curso['nombre'], $curso['imagen']);
                    $matriculados = (int)$curso['estudiantes_matriculados'];
                    $capacidad    = (int)$curso['cupo_maximo'] * (int)$curso['total_grupos'];
                    ?>

                    <div class="curso-card">
                        <div class="curso-imagen">
                            <img
                                src="<?php echo $imagen_src; ?>"
                                alt="<?php echo htmlspecialchars($curso['nombre']); ?>"
                                onerror="this.onerror=null; this.src='../../assets/img/cursos/musica-default.jpg';"
                            >
                            <span class="badge-nivel <?php echo $nivel_badge['clase']; ?>">
                                <?php echo $nivel_badge['texto']; ?>
                            </span>
                        </div>

                        <div class="curso-content">
                            <div class="curso-header">
                                <h3 class="curso-titulo"><?php echo htmlspecialchars($curso['nombre']); ?></h3>
                                <?php if ($es_admin): ?>
                                <span class="badge-estado <?php echo $estado_clase; ?>">
                                    <?php echo ucfirst($curso['estado']); ?>
                                </span>
                                <?php endif; ?>
                            </div>

                            <p class="curso-descripcion">
                                <?php
                                $desc = $curso['descripcion'] ?? 'Sin descripción disponible';
                                echo htmlspecialchars(mb_substr($desc, 0, 100)) . (mb_strlen($desc) > 100 ? '...' : '');
                                ?>
                            </p>

                            <div class="curso-info">
                                <div class="info-item">
                                    <span class="material-symbols-rounded">person</span>
                                    <span><?php echo htmlspecialchars($curso['profesores'] ?? 'Sin profesor'); ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="material-symbols-rounded">schedule</span>
                                    <span><?php echo (int)$curso['duracion_meses']; ?> meses</span>
                                </div>
                                <div class="info-item">
                                    <span class="material-symbols-rounded">payments</span>
                                    <span>$<?php echo number_format($curso['precio_mensual'], 0, ',', '.'); ?>/mes</span>
                                </div>
                            </div>

                            <div class="curso-stats">
                                <div class="stat-item">
                                    <span class="material-symbols-rounded">groups</span>
                                    <span><?php echo (int)$curso['total_grupos']; ?> grupos</span>
                                </div>
                                <div class="stat-item">
                                    <span class="material-symbols-rounded">school</span>
                                    <span><?php echo $matriculados; ?>/<?php echo $capacidad; ?></span>
                                </div>
                            </div>

                            <!-- ── Acciones según rol ────────────────────── -->
                            <div class="curso-actions">
                                <!-- Ver detalles: todos los roles -->
                                <a href="ver.php?id=<?php echo $curso['id']; ?>" class="btn-action btn-ver" title="Ver Detalles">
                                    <span class="material-symbols-rounded">visibility</span>
                                    Ver Detalles
                                </a>

                                <!-- Editar y Eliminar: solo admin -->
                                <?php if ($es_admin): ?>
                                <a href="editar.php?id=<?php echo $curso['id']; ?>" class="btn-action btn-editar" title="Editar">
                                    <span class="material-symbols-rounded">edit</span>
                                </a>
                                <button
                                    onclick="confirmarEliminacion(<?php echo (int)$curso['id']; ?>, '<?php echo htmlspecialchars($curso['nombre'], ENT_QUOTES); ?>')"
                                    class="btn-action btn-eliminar"
                                    title="Eliminar">
                                    <span class="material-symbols-rounded">delete</span>
                                </button>
                                <?php endif; ?>
                            </div>

                        </div>
                    </div>
                <?php endforeach; ?>

            <?php else: ?>
                <div class="empty-state">
                    <span class="material-symbols-rounded">
                        <?php echo $es_admin ? 'search_off' : 'school'; ?>
                    </span>
                    <h3>
                        <?php
                        if ($es_admin)       echo 'No se encontraron cursos';
                        elseif ($es_profesor) echo 'No tienes cursos asignados';
                        else                  echo 'No estás matriculado en ningún curso';
                        ?>
                    </h3>
                    <p>
                        <?php
                        if ($es_admin && (!empty($search) || $filter_categoria !== 'todos' || $filter_nivel !== 'todos'))
                            echo 'Intenta ajustar los filtros de búsqueda';
                        elseif ($es_admin)
                            echo 'Comienza creando tu primer curso';
                        elseif ($es_profesor)
                            echo 'Cuando te asignen a un grupo, tus cursos aparecerán aquí';
                        else
                            echo 'Contacta a la administración para inscribirte en un curso';
                        ?>
                    </p>
                    <?php if ($es_admin && empty($search) && $filter_categoria === 'todos' && $filter_nivel === 'todos'): ?>
                    <a href="crear.php" class="btn-crear-primero">
                        <span class="material-symbols-rounded">add</span>
                        Crear Primer Curso
                    </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

    </main>

    <!-- ── Modal Eliminar (solo admin) ──────────────────────────────────── -->
    <?php if ($es_admin): ?>
    <div id="modalEliminar" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <span class="material-symbols-rounded modal-icon-warning">warning</span>
                <h2>Confirmar Eliminación</h2>
            </div>
            <div class="modal-body">
                <p>¿Estás seguro de que deseas eliminar el curso <strong id="nombreCurso"></strong>?</p>
                <p class="warning-text">Esta acción no se puede deshacer y eliminará todos los grupos asociados.</p>
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

        window.addEventListener('click', function (event) {
            const modal = document.getElementById('modalEliminar');
            if (event.target === modal) cerrarModal();
        });
    </script>
    <?php endif; ?>

</body>
</html>