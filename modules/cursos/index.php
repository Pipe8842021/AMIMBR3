<?php

require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth_check.php';

require_any_role(['admin', 'profesor', 'estudiante']);

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

$search           = $es_admin && isset($_GET['search'])    ? trim($_GET['search'])    : '';
$filter_categoria = $es_admin && isset($_GET['categoria']) ? $_GET['categoria']       : 'todos';
$filter_nivel     = $es_admin && isset($_GET['nivel'])     ? $_GET['nivel']           : 'todos';

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

$params = [];

if ($es_admin) {
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.css">
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
    <?php
    if (file_exists('../../includes/header.php')) {
        require_once '../../includes/header.php';
    }
    ?>

    <main class="main-content">
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
                <button type="button" class="btn-nuevo-curso" onclick="abrirModalCrear()">
                    <span class="material-symbols-rounded">add</span>
                    Nuevo Curso
                </button>
                <?php endif; ?>
            </div>
        </div>

        <?php
        $success_msg = '';
        if ($es_admin && isset($_GET['success'])) {
            if ($_GET['success'] === 'curso_creado')      $success_msg = 'Curso creado exitosamente.';
            elseif ($_GET['success'] === 'curso_actualizado') $success_msg = 'Curso actualizado exitosamente.';
        }
        ?>
        <?php if ($success_msg): ?>
        <div class="alert alert-success" id="alertaExito">
            <span class="material-symbols-rounded">check_circle</span>
            <span><?php echo htmlspecialchars($success_msg); ?></span>
        </div>
        <script>setTimeout(function(){const a=document.getElementById('alertaExito');if(a)a.style.display='none';},4000);</script>
        <?php endif; ?>

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
        </div>
        <?php endif; ?>

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

                            <div class="curso-actions">
                                <!-- Ver detalles: todos los roles -->
                                <button onclick="abrirModalVer(<?php echo (int)$curso['id']; ?>)" class="btn-action btn-ver" title="Ver Detalles">
                                    <span class="material-symbols-rounded">visibility</span> Ver
                                </button>

                                <?php if ($es_admin): ?>
                                <button
                                    class="btn-action btn-editar"
                                    title="Editar"
                                    data-curso="<?php echo htmlspecialchars(json_encode([
                                        'id'             => (int)$curso['id'],
                                        'nombre'         => $curso['nombre'],
                                        'descripcion'    => $curso['descripcion'] ?? '',
                                        'duracion_meses' => (int)$curso['duracion_meses'],
                                        'nivel'          => $curso['nivel'],
                                        'cupo_maximo'    => (int)$curso['cupo_maximo'],
                                        'precio_mensual' => (float)$curso['precio_mensual'],
                                        'requisitos'     => $curso['requisitos'] ?? '',
                                        'estado'         => $curso['estado'],
                                        'imagen'         => $curso['imagen'] ?? '',
                                    ]), ENT_QUOTES); ?>"
                                    onclick="abrirModalEditar(this.dataset.curso)">
                                    <span class="material-symbols-rounded">edit</span>
                                </button>
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
                    <button type="button" class="btn-crear-primero" onclick="abrirModalCrear()">
                        <span class="material-symbols-rounded">add</span>
                        Crear Primer Curso
                    </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

    </main>

    <!-- ── Modal Ver Curso (todos los roles) ─────────────── -->
    <div id="modalVerCurso" class="modal">
        <div class="modal-content modal-ver-content">
            <div class="modal-header modal-header-crear">
                <div class="modal-title-group">
                    <h2 id="mv_titulo">Cargando...</h2>
                    <p id="mv_subtitulo">&nbsp;</p>
                </div>
                <button class="modal-close-btn" type="button" onclick="cerrarModalVer()" title="Cerrar">
                    <span class="material-symbols-rounded">close</span>
                </button>
            </div>
            <div id="mv_loading" class="mv-loading">
                <span class="material-symbols-rounded mv-spin">progress_activity</span>
                <p>Cargando detalles...</p>
            </div>
            <div id="mv_content" class="modal-body-scroll" style="display:none">
                <div class="mv-imagen-wrap">
                    <img id="mv_imagen" src="" alt=""
                        onerror="this.onerror=null;this.src='../../assets/img/cursos/musica-default.jpg'">
                </div>
                <div class="mv-top-row">
                    <div class="mv-badges" id="mv_badges"></div>
                    <div class="mv-acciones-admin" id="mv_acciones_admin" style="display:none">
                        <button class="btn-action btn-editar" onclick="mv_abrirEditar()" title="Editar">
                            <span class="material-symbols-rounded">edit</span> Editar
                        </button>
                        <button class="btn-action btn-eliminar" onclick="mv_abrirEliminar()" title="Eliminar">
                            <span class="material-symbols-rounded">delete</span> Eliminar
                        </button>
                    </div>
                </div>
                <p id="mv_descripcion" class="mv-descripcion"></p>
                <div class="mv-info-grid">
                    <div class="mv-info-item">
                        <span class="material-symbols-rounded">schedule</span>
                        <div><span class="mv-info-label">Duración</span><span id="mv_duracion" class="mv-info-val"></span></div>
                    </div>
                    <div class="mv-info-item">
                        <span class="material-symbols-rounded">payments</span>
                        <div><span class="mv-info-label">Precio Mensual</span><span id="mv_precio" class="mv-info-val"></span></div>
                    </div>
                    <div class="mv-info-item">
                        <span class="material-symbols-rounded">groups</span>
                        <div><span class="mv-info-label">Cupo por Grupo</span><span id="mv_cupo" class="mv-info-val"></span></div>
                    </div>
                    <div class="mv-info-item">
                        <span class="material-symbols-rounded">school</span>
                        <div><span class="mv-info-label">Inscritos</span><span id="mv_inscritos" class="mv-info-val"></span></div>
                    </div>
                </div>
                <div id="mv_req_wrap" style="display:none">
                    <div class="mv-section-title">
                        <span class="material-symbols-rounded">checklist</span> Requisitos
                    </div>
                    <p id="mv_requisitos" class="mv-req-text"></p>
                </div>
                <div class="mv-grupos-wrap">
                    <div class="mv-section-header">
                        <div class="mv-section-title">
                            <span class="material-symbols-rounded">group_work</span>
                            <span id="mv_grupos_titulo">Grupos del Curso</span>
                        </div>
                        <a id="mv_btn_nuevo_grupo" href="../grupos/admin.php" class="btn-nuevo-grupo" style="display:none">
                            <span class="material-symbols-rounded">add</span> Nuevo Grupo
                        </a>
                    </div>
                    <div id="mv_grupos_lista"></div>
                </div>
            </div>
        </div>
    </div>

    <script>
    /* ── Modal Ver Curso ─────────────────────────────────── */
    let mv_datos = null;

    function _esc(str) {
        const d = document.createElement('div');
        d.appendChild(document.createTextNode(str || ''));
        return d.innerHTML;
    }

    async function abrirModalVer(id) {
        mv_datos = null;
        document.getElementById('mv_titulo').textContent     = 'Cargando...';
        document.getElementById('mv_subtitulo').innerHTML    = '&nbsp;';
        document.getElementById('mv_loading').style.display  = 'flex';
        document.getElementById('mv_content').style.display  = 'none';
        document.getElementById('modalVerCurso').style.display = 'flex';
        document.body.style.overflow = 'hidden';

        try {
            const res  = await fetch('ver.php?id=' + id + '&ajax=1');
            const data = await res.json();
            mv_datos = data;
            const c = data.curso;

            document.getElementById('mv_titulo').textContent = c.nombre;
            document.getElementById('mv_subtitulo').textContent =
                (parseInt(c.total_grupos) || 0) + ' grupo(s) · ' +
                (parseInt(c.estudiantes_matriculados) || 0) + ' estudiante(s)';

            const img = document.getElementById('mv_imagen');
            img.src = data.imagen_src;
            img.alt = c.nombre;

            const nb = data.nivel_badge;
            document.getElementById('mv_badges').innerHTML =
                `<span class="badge ${_esc(nb.clase)}">${_esc(nb.texto)}</span>` +
                `<span class="badge ${c.estado === 'activo' ? 'estado-activo' : 'estado-inactivo'}">${c.estado === 'activo' ? 'Activo' : 'Inactivo'}</span>`;

            document.getElementById('mv_acciones_admin').style.display = data.es_admin ? 'flex' : 'none';
            document.getElementById('mv_descripcion').textContent = c.descripcion || 'Sin descripción disponible.';
            document.getElementById('mv_duracion').textContent  = c.duracion_meses + ' meses';
            document.getElementById('mv_precio').textContent    = '$' + Number(c.precio_mensual).toLocaleString('es-CO');
            document.getElementById('mv_cupo').textContent      = c.cupo_maximo + ' est.';
            document.getElementById('mv_inscritos').textContent = c.estudiantes_matriculados || 0;

            const reqWrap = document.getElementById('mv_req_wrap');
            if (c.requisitos) {
                document.getElementById('mv_requisitos').textContent = c.requisitos;
                reqWrap.style.display = 'block';
            } else {
                reqWrap.style.display = 'none';
            }

            document.getElementById('mv_grupos_titulo').textContent = data.es_estudiante ? 'Mi Grupo' : 'Grupos del Curso';
            document.getElementById('mv_btn_nuevo_grupo').style.display = data.es_admin ? 'inline-flex' : 'none';

            const lista = document.getElementById('mv_grupos_lista');
            if (data.grupos.length > 0) {
                lista.innerHTML = data.grupos.map(g => `
                    <div class="mv-grupo-card">
                        <div class="mv-gc-main">
                            <div class="mv-gc-info">
                                <div class="mv-gc-top">
                                    <span class="mv-gc-nombre">${_esc(g.nombre)}</span>
                                    <span class="mv-gc-estado ${g.estado === 'activo' ? 'estado-activo' : 'estado-inactivo'}">${g.estado === 'activo' ? 'Activo' : 'Inactivo'}</span>
                                </div>
                                <div class="mv-gc-stats">
                                    <span class="mv-gc-stat"><span class="material-symbols-rounded">person</span><span>${_esc(g.profesor_nombre || 'Sin profesor asignado')}</span></span>
                                    <span class="mv-gc-sep">·</span>
                                    <span class="mv-gc-stat"><span class="material-symbols-rounded">groups</span><span>${g.estudiantes_inscritos}/${g.cupo_maximo} estudiantes</span></span>
                                </div>
                            </div>
                            ${!data.es_estudiante ? `<a href="../grupos/ver.php?id=${g.id}" class="mv-gc-ver">Ver</a>` : ''}
                        </div>
                    </div>`
                ).join('');
            } else {
                lista.innerHTML = `<div class="empty-state">
                    <span class="material-symbols-rounded">${data.es_estudiante ? 'school' : 'group_off'}</span>
                    <p>${data.es_estudiante ? 'No tienes un grupo asignado en este curso' : 'No hay grupos creados para este curso'}</p>
                    ${data.es_admin ? `<a href="../grupos/crear.php?curso_id=${c.id}" class="btn-crear-grupo"><span class="material-symbols-rounded">add</span>Crear Primer Grupo</a>` : ''}
                </div>`;
            }

            document.getElementById('mv_loading').style.display = 'none';
            document.getElementById('mv_content').style.display = 'block';

        } catch (err) {
            console.error(err);
            cerrarModalVer();
        }
    }

    function cerrarModalVer() {
        document.getElementById('modalVerCurso').style.display = 'none';
        document.body.style.overflow = '';
        mv_datos = null;
    }

    function mv_abrirEditar() {
        if (!mv_datos) return;
        const c = mv_datos.curso;
        cerrarModalVer();
        if (typeof abrirModalEditar === 'function') {
            abrirModalEditar(JSON.stringify({
                id: c.id, nombre: c.nombre, descripcion: c.descripcion || '',
                duracion_meses: c.duracion_meses, nivel: c.nivel,
                cupo_maximo: c.cupo_maximo, precio_mensual: c.precio_mensual,
                requisitos: c.requisitos || '', estado: c.estado, imagen: c.imagen || '',
            }));
        }
    }

    function mv_abrirEliminar() {
        if (!mv_datos) return;
        const c = mv_datos.curso;
        cerrarModalVer();
        if (typeof confirmarEliminacion === 'function') {
            confirmarEliminacion(c.id, c.nombre);
        }
    }

    document.getElementById('modalVerCurso').addEventListener('click', function(e) {
        if (e.target === this) cerrarModalVer();
    });
    </script>

    <?php if ($es_admin): ?>
    <!-- ── Modal Crear Curso — Wizard ─────────────────────── -->
    <div id="modalCrearCurso" class="modal">
        <div class="modal-content modal-crear-content">

            <!-- Header -->
            <div class="modal-header modal-header-crear">
                <div class="modal-title-group">
                    <h2>Nuevo Curso</h2>
                    <p id="nc_step_label">Paso 1 de 2 · Información del curso</p>
                </div>
                <button class="modal-close-btn" type="button" onclick="cerrarModalCrear()" title="Cerrar">
                    <span class="material-symbols-rounded">close</span>
                </button>
            </div>

            <!-- Stepper -->
            <div class="nc-stepper">
                <div class="nc-stepper-step nc-active" id="nc_ind_1">
                    <div class="nc-step-circle">
                        <span class="nc-step-num">1</span>
                        <span class="nc-step-check material-symbols-rounded">check</span>
                    </div>
                    <span class="nc-step-lbl">Información</span>
                </div>
                <div class="nc-stepper-line" id="nc_step_line"></div>
                <div class="nc-stepper-step" id="nc_ind_2">
                    <div class="nc-step-circle">
                        <span class="nc-step-num">2</span>
                        <span class="nc-step-check material-symbols-rounded">check</span>
                    </div>
                    <span class="nc-step-lbl">Configuración</span>
                </div>
            </div>

            <!-- Body scrollable -->
            <div class="modal-body-scroll">
                <div class="alert alert-error" id="alertaErrorCrear" style="display:none">
                    <span class="material-symbols-rounded">error</span>
                    <div>
                        <strong>Se encontraron errores:</strong>
                        <ul id="listaErroresCrear"></ul>
                    </div>
                </div>

                <form id="formCrearCurso" method="POST" action="crear.php">

                    <!-- Paso 1: Información -->
                    <div id="ncStep1" class="nc-form-step">
                        <div class="form-group">
                            <label for="nc_nombre">Nombre del Curso <span class="required">*</span></label>
                            <input type="text" id="nc_nombre" name="nombre" placeholder="Ej: Piano Clásico">
                        </div>
                        <div class="form-group">
                            <label for="nc_descripcion">Descripción</label>
                            <textarea id="nc_descripcion" name="descripcion" rows="3" placeholder="Describe el curso, metodología, objetivos..."></textarea>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="nc_nivel">Nivel <span class="required">*</span></label>
                                <select id="nc_nivel" name="nivel">
                                    <option value="basico">Básico</option>
                                    <option value="intermedio">Intermedio</option>
                                    <option value="avanzado">Avanzado</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="nc_estado">Estado <span class="required">*</span></label>
                                <select id="nc_estado" name="estado">
                                    <option value="activo">Activo</option>
                                    <option value="inactivo">Inactivo</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="nc_requisitos">Requisitos</label>
                            <textarea id="nc_requisitos" name="requisitos" rows="2" placeholder="Conocimientos previos, edad mínima, materiales..."></textarea>
                        </div>
                    </div>

                    <!-- Paso 2: Configuración -->
                    <div id="ncStep2" class="nc-form-step" style="display:none">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="nc_duracion">Duración (meses) <span class="required">*</span></label>
                                <input type="number" id="nc_duracion" name="duracion_meses" min="1" max="48" value="12">
                                <small>Entre 1 y 48 meses</small>
                            </div>
                            <div class="form-group">
                                <label for="nc_cupo">Cupo por Grupo <span class="required">*</span></label>
                                <input type="number" id="nc_cupo" name="cupo_maximo" min="1" max="50" value="15">
                                <small>Entre 1 y 50 estudiantes</small>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="nc_precio">Precio Mensual <span class="required">*</span></label>
                            <div class="input-with-prefix">
                                <span class="prefix">$</span>
                                <input type="number" id="nc_precio" name="precio_mensual" min="0" step="1000" placeholder="150000">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Imagen del Curso <small style="font-weight:400;color:var(--text-secondary)">(opcional)</small></label>
                            <div class="file-upload">
                                <input type="file" id="nc_imagen_raw" name="_imagen_raw_unused" accept="image/jpeg,image/png,image/webp" style="position:absolute;opacity:0;width:0;height:0;">
                                <input type="hidden" name="imagen_cropped" id="nc_imagen_cropped">
                                <input type="hidden" name="imagen_ext" id="nc_imagen_ext">
                                <label for="nc_imagen_raw" class="file-upload-label" id="nc_upload_label">
                                    <span class="material-symbols-rounded">crop</span>
                                    <span id="nc_upload_label_text">Seleccionar y recortar imagen</span>
                                </label>
                                <small>JPG, PNG o WEBP · Máx. 5MB · Proporción 2:1</small>
                            </div>
                            <div class="image-preview-cropped" id="nc_preview_cropped">
                                <img id="nc_preview_cropped_img" src="" alt="Imagen recortada">
                            </div>
                        </div>
                    </div>

                </form>
            </div>

            <!-- Footer con navegación por paso -->
            <div class="modal-footer-crear">
                <div id="ncFooter1" class="nc-footer-step">
                    <button type="button" class="btn-cancelar" onclick="cerrarModalCrear()">Cancelar</button>
                    <button type="button" class="btn-primario" onclick="ncIrPaso2()">
                        Continuar
                        <span class="material-symbols-rounded">arrow_forward</span>
                    </button>
                </div>
                <div id="ncFooter2" class="nc-footer-step" style="display:none">
                    <button type="button" class="btn-secundario" onclick="ncIrPaso1()">
                        <span class="material-symbols-rounded">arrow_back</span>
                        Atrás
                    </button>
                    <button type="button" class="btn-primario" id="btnSubmitCrear" onclick="submitCrearCurso()">
                        <span class="material-symbols-rounded">save</span>
                        Crear Curso
                    </button>
                </div>
            </div>

        </div>
    </div>

    <!-- Cropper overlay para el modal de crear -->
    <div class="cropper-modal-overlay" id="ncCropperOverlay">
        <div class="cropper-modal-box">
            <div class="cropper-modal-header">
                <h3>
                    <span class="material-symbols-rounded">crop</span>
                    Recortar imagen del curso
                </h3>
                <button class="cropper-close-btn" id="ncBtnCropClose" type="button">
                    <span class="material-symbols-rounded">close</span>
                </button>
            </div>
            <div class="cropper-canvas-wrap">
                <img id="ncCropImage" src="" alt="Imagen a recortar">
            </div>
            <div class="cropper-modal-footer">
                <span class="cropper-hint">
                    <span class="material-symbols-rounded">info</span>
                    Ajusta el recuadro · Proporción fija 2:1
                </span>
                <div class="cropper-actions">
                    <button type="button" class="btn-crop-cancel" id="ncBtnCropCancel">Cancelar</button>
                    <button type="button" class="btn-crop-confirm" id="ncBtnCropConfirm">
                        <span class="material-symbols-rounded">check</span>
                        Aplicar recorte
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Modal Editar Curso ─────────────────────────────── -->
    <div id="modalEditarCurso" class="modal">
        <div class="modal-content modal-crear-content">

            <div class="modal-header modal-header-crear">
                <div class="modal-title-group">
                    <h2>Editar Curso</h2>
                    <p>Modifica la información del curso</p>
                </div>
                <button class="modal-close-btn" type="button" onclick="cerrarModalEditar()" title="Cerrar">
                    <span class="material-symbols-rounded">close</span>
                </button>
            </div>

            <div class="modal-body-scroll">
                <div class="alert alert-error" id="alertaErrorEditar" style="display:none">
                    <span class="material-symbols-rounded">error</span>
                    <div>
                        <strong>Se encontraron errores:</strong>
                        <ul id="listaErroresEditar"></ul>
                    </div>
                </div>

                <form id="formEditarCurso" method="POST">
                    <input type="hidden" id="ec_id" name="id">

                    <div class="form-group">
                        <label for="ec_nombre">Nombre del Curso <span class="required">*</span></label>
                        <input type="text" id="ec_nombre" name="nombre" placeholder="Ej: Piano Clásico">
                    </div>

                    <div class="form-group">
                        <label for="ec_descripcion">Descripción</label>
                        <textarea id="ec_descripcion" name="descripcion" rows="3" placeholder="Describe el curso, metodología, objetivos..."></textarea>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="ec_nivel">Nivel <span class="required">*</span></label>
                            <select id="ec_nivel" name="nivel">
                                <option value="basico">Básico</option>
                                <option value="intermedio">Intermedio</option>
                                <option value="avanzado">Avanzado</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="ec_estado">Estado <span class="required">*</span></label>
                            <select id="ec_estado" name="estado">
                                <option value="activo">Activo</option>
                                <option value="inactivo">Inactivo</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="ec_requisitos">Requisitos</label>
                        <textarea id="ec_requisitos" name="requisitos" rows="2" placeholder="Conocimientos previos, edad mínima, materiales..."></textarea>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="ec_duracion">Duración (meses) <span class="required">*</span></label>
                            <input type="number" id="ec_duracion" name="duracion_meses" min="1" max="48">
                            <small>Entre 1 y 48 meses</small>
                        </div>
                        <div class="form-group">
                            <label for="ec_cupo">Cupo por Grupo <span class="required">*</span></label>
                            <input type="number" id="ec_cupo" name="cupo_maximo" min="1" max="50">
                            <small>Entre 1 y 50 estudiantes</small>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="ec_precio">Precio Mensual <span class="required">*</span></label>
                        <div class="input-with-prefix">
                            <span class="prefix">$</span>
                            <input type="number" id="ec_precio" name="precio_mensual" min="0" step="1000" placeholder="150000">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Imagen del Curso <small style="font-weight:400;color:var(--text-secondary)">(opcional)</small></label>
                        <div id="ec_current_image_wrap" style="display:none;margin-bottom:10px;">
                            <p style="font-size:0.85rem;color:var(--text-secondary);margin-bottom:6px;">Imagen actual:</p>
                            <img id="ec_current_image" src="" alt="Imagen actual"
                                style="width:100%;max-height:160px;object-fit:cover;border-radius:10px;border:1px solid var(--border-color);">
                        </div>
                        <div class="file-upload">
                            <input type="file" id="ec_imagen_raw" name="_imagen_raw_unused" accept="image/jpeg,image/png,image/webp" style="position:absolute;opacity:0;width:0;height:0;">
                            <input type="hidden" name="imagen_cropped" id="ec_imagen_cropped">
                            <input type="hidden" name="imagen_ext" id="ec_imagen_ext">
                            <label for="ec_imagen_raw" class="file-upload-label" id="ec_upload_label">
                                <span class="material-symbols-rounded">crop</span>
                                <span id="ec_upload_label_text">Seleccionar y recortar imagen</span>
                            </label>
                            <small>JPG, PNG o WEBP · Máx. 5MB · Proporción 2:1</small>
                        </div>
                        <div class="image-preview-cropped" id="ec_preview_cropped">
                            <img id="ec_preview_cropped_img" src="" alt="Nueva imagen recortada">
                        </div>
                    </div>
                </form>
            </div>

            <div class="modal-footer-crear">
                <div class="nc-footer-step">
                    <button type="button" class="btn-cancelar" onclick="cerrarModalEditar()">Cancelar</button>
                    <button type="button" class="btn-primario" id="btnSubmitEditar" onclick="submitEditarCurso()">
                        <span class="material-symbols-rounded">save</span>
                        Guardar Cambios
                    </button>
                </div>
            </div>

        </div>
    </div>

    <!-- Cropper overlay para el modal de editar -->
    <div class="cropper-modal-overlay" id="ecCropperOverlay">
        <div class="cropper-modal-box">
            <div class="cropper-modal-header">
                <h3>
                    <span class="material-symbols-rounded">crop</span>
                    Recortar imagen del curso
                </h3>
                <button class="cropper-close-btn" id="ecBtnCropClose" type="button">
                    <span class="material-symbols-rounded">close</span>
                </button>
            </div>
            <div class="cropper-canvas-wrap">
                <img id="ecCropImage" src="" alt="Imagen a recortar">
            </div>
            <div class="cropper-modal-footer">
                <span class="cropper-hint">
                    <span class="material-symbols-rounded">info</span>
                    Ajusta el recuadro · Proporción fija 2:1
                </span>
                <div class="cropper-actions">
                    <button type="button" class="btn-crop-cancel" id="ecBtnCropCancel">Cancelar</button>
                    <button type="button" class="btn-crop-confirm" id="ecBtnCropConfirm">
                        <span class="material-symbols-rounded">check</span>
                        Aplicar recorte
                    </button>
                </div>
            </div>
        </div>
    </div>

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

    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.js"></script>
    <script>
        /* ── Modal Eliminar ──────────────────────────────────── */
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

        /* ── Modal Crear Curso — Wizard ─────────────────────── */
        function abrirModalCrear() {
            document.getElementById('modalCrearCurso').style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function ncIrPaso2() {
            const campo = document.getElementById('nc_nombre');
            if (!campo.value.trim()) {
                campo.classList.add('nc-field-error');
                campo.focus();
                campo.addEventListener('input', function h() {
                    campo.classList.remove('nc-field-error');
                    campo.removeEventListener('input', h);
                });
                return;
            }
            campo.classList.remove('nc-field-error');

            document.getElementById('ncStep1').style.display = 'none';
            document.getElementById('ncStep2').style.display = 'block';
            document.getElementById('ncFooter1').style.display = 'none';
            document.getElementById('ncFooter2').style.display = 'flex';

            document.getElementById('nc_ind_1').classList.replace('nc-active', 'nc-done');
            document.getElementById('nc_step_line').classList.add('nc-done');
            document.getElementById('nc_ind_2').classList.add('nc-active');
            document.getElementById('nc_step_label').textContent = 'Paso 2 de 2 · Configuración del curso';
            document.querySelector('#modalCrearCurso .modal-body-scroll').scrollTop = 0;
        }

        function ncIrPaso1() {
            document.getElementById('ncStep2').style.display = 'none';
            document.getElementById('ncStep1').style.display = 'block';
            document.getElementById('ncFooter2').style.display = 'none';
            document.getElementById('ncFooter1').style.display = 'flex';

            document.getElementById('nc_ind_2').classList.remove('nc-active');
            document.getElementById('nc_step_line').classList.remove('nc-done');
            document.getElementById('nc_ind_1').classList.replace('nc-done', 'nc-active');
            document.getElementById('nc_step_label').textContent = 'Paso 1 de 2 · Información del curso';
            document.querySelector('#modalCrearCurso .modal-body-scroll').scrollTop = 0;
        }

        function cerrarModalCrear() {
            document.getElementById('modalCrearCurso').style.display = 'none';
            document.body.style.overflow = '';
            document.getElementById('formCrearCurso').reset();
            document.getElementById('alertaErrorCrear').style.display = 'none';
            document.getElementById('nc_preview_cropped').classList.remove('visible');
            document.getElementById('nc_upload_label').classList.remove('has-file');
            document.getElementById('nc_upload_label_text').textContent = 'Seleccionar y recortar imagen';
            document.getElementById('nc_imagen_cropped').value = '';
            document.getElementById('nc_imagen_ext').value = '';
            // Volver al paso 1
            document.getElementById('ncStep2').style.display = 'none';
            document.getElementById('ncStep1').style.display = 'block';
            document.getElementById('ncFooter2').style.display = 'none';
            document.getElementById('ncFooter1').style.display = 'flex';
            document.getElementById('nc_ind_2').classList.remove('nc-active');
            document.getElementById('nc_ind_1').classList.remove('nc-done');
            document.getElementById('nc_ind_1').classList.add('nc-active');
            document.getElementById('nc_step_line').classList.remove('nc-done');
            document.getElementById('nc_step_label').textContent = 'Paso 1 de 2 · Información del curso';
        }

        async function submitCrearCurso() {
            const form = document.getElementById('formCrearCurso');
            const btn  = document.getElementById('btnSubmitCrear');
            btn.disabled = true;
            btn.innerHTML = '<span class="material-symbols-rounded">hourglass_top</span> Guardando...';

            try {
                const res  = await fetch('crear.php', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: new FormData(form)
                });
                const data = await res.json();

                if (data.success) {
                    cerrarModalCrear();
                    window.location.href = 'index.php?success=curso_creado';
                } else {
                    const lista = document.getElementById('listaErroresCrear');
                    lista.innerHTML = data.errores.map(e => `<li>${e}</li>`).join('');
                    document.getElementById('alertaErrorCrear').style.display = 'flex';
                    document.querySelector('#modalCrearCurso .modal-body-scroll').scrollTop = 0;
                }
            } catch (err) {
                console.error(err);
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<span class="material-symbols-rounded">save</span> Crear Curso';
            }
        }

        /* ── Cropper dentro del modal crear ─────────────────── */
        (function () {
            const ASPECT      = 2 / 1;
            const inputRaw    = document.getElementById('nc_imagen_raw');
            const inputCrop   = document.getElementById('nc_imagen_cropped');
            const inputExt    = document.getElementById('nc_imagen_ext');
            const lbl         = document.getElementById('nc_upload_label');
            const lblText     = document.getElementById('nc_upload_label_text');
            const previewBox  = document.getElementById('nc_preview_cropped');
            const previewImg  = document.getElementById('nc_preview_cropped_img');
            const overlay     = document.getElementById('ncCropperOverlay');
            const cropImg     = document.getElementById('ncCropImage');
            let cropper       = null;
            let currentExt    = 'jpg';

            inputRaw.addEventListener('change', function (e) {
                const file = e.target.files[0];
                if (!file) return;
                if (file.size > 5 * 1024 * 1024) { alert('La imagen no puede superar 5MB'); this.value = ''; return; }
                currentExt = file.name.split('.').pop().toLowerCase();
                const url  = URL.createObjectURL(file);
                if (cropper) { cropper.destroy(); cropper = null; }
                cropImg.src = url;
                overlay.classList.add('active');
                cropImg.onload = function () {
                    cropper = new Cropper(cropImg, {
                        aspectRatio: ASPECT, viewMode: 2, dragMode: 'move',
                        autoCropArea: 1, restore: false, guides: true, center: true,
                        highlight: true, cropBoxMovable: true, cropBoxResizable: true,
                        toggleDragModeOnDblclick: false, background: true,
                    });
                };
                this.value = '';
            });

            document.getElementById('ncBtnCropConfirm').addEventListener('click', function () {
                if (!cropper) return;
                const mime = currentExt === 'png' ? 'image/png' : 'image/jpeg';
                const b64  = cropper.getCroppedCanvas({ width: 1200, height: 600 }).toDataURL(mime, 0.92);
                inputCrop.value = b64;
                inputExt.value  = currentExt;
                previewImg.src  = b64;
                previewBox.classList.add('visible');
                lbl.classList.add('has-file');
                lblText.textContent = 'Imagen lista — clic para cambiar';
                closeCropper();
            });

            document.getElementById('ncBtnCropCancel').addEventListener('click', closeCropper);
            document.getElementById('ncBtnCropClose').addEventListener('click', closeCropper);
            overlay.addEventListener('click', function (e) { if (e.target === overlay) closeCropper(); });

            function closeCropper() {
                overlay.classList.remove('active');
                if (cropper) { cropper.destroy(); cropper = null; }
            }
        })();

        /* ── Modal Editar Curso ──────────────────────────────── */
        function abrirModalEditar(json) {
            const c = JSON.parse(json);

            document.getElementById('ec_id').value            = c.id;
            document.getElementById('ec_nombre').value        = c.nombre;
            document.getElementById('ec_descripcion').value   = c.descripcion;
            document.getElementById('ec_duracion').value      = c.duracion_meses;
            document.getElementById('ec_nivel').value         = c.nivel;
            document.getElementById('ec_cupo').value          = c.cupo_maximo;
            document.getElementById('ec_precio').value        = c.precio_mensual;
            document.getElementById('ec_requisitos').value    = c.requisitos;
            document.getElementById('ec_estado').value        = c.estado;

            const imgWrap = document.getElementById('ec_current_image_wrap');
            const imgEl   = document.getElementById('ec_current_image');
            if (c.imagen) {
                imgEl.src = '../../assets/img/cursos/' + c.imagen;
                imgWrap.style.display = 'block';
            } else {
                imgWrap.style.display = 'none';
            }

            document.getElementById('ec_upload_label').classList.remove('has-file');
            document.getElementById('ec_upload_label_text').textContent = c.imagen ? 'Cambiar imagen' : 'Seleccionar imagen';
            document.getElementById('ec_imagen_cropped').value = '';
            document.getElementById('ec_imagen_ext').value     = '';
            document.getElementById('ec_preview_cropped').classList.remove('visible');
            document.getElementById('alertaErrorEditar').style.display = 'none';

            document.getElementById('modalEditarCurso').style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function cerrarModalEditar() {
            document.getElementById('modalEditarCurso').style.display = 'none';
            document.body.style.overflow = '';
        }

        async function submitEditarCurso() {
            const form = document.getElementById('formEditarCurso');
            const btn  = document.getElementById('btnSubmitEditar');
            const id   = document.getElementById('ec_id').value;

            btn.disabled = true;
            btn.innerHTML = '<span class="material-symbols-rounded">hourglass_top</span> Guardando...';

            try {
                const res  = await fetch('editar.php?id=' + id, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: new FormData(form)
                });
                const data = await res.json();

                if (data.success) {
                    cerrarModalEditar();
                    window.location.href = 'index.php?success=curso_actualizado';
                } else {
                    const lista = document.getElementById('listaErroresEditar');
                    lista.innerHTML = data.errores.map(e => `<li>${e}</li>`).join('');
                    document.getElementById('alertaErrorEditar').style.display = 'flex';
                    document.querySelector('#modalEditarCurso .modal-body-scroll').scrollTop = 0;
                }
            } catch (err) {
                console.error(err);
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<span class="material-symbols-rounded">save</span> Guardar Cambios';
            }
        }

        /* ── Cropper del modal editar ────────────────────────── */
        (function () {
            const ASPECT     = 2 / 1;
            const inputRaw   = document.getElementById('ec_imagen_raw');
            const inputCrop  = document.getElementById('ec_imagen_cropped');
            const inputExt   = document.getElementById('ec_imagen_ext');
            const lbl        = document.getElementById('ec_upload_label');
            const lblText    = document.getElementById('ec_upload_label_text');
            const previewBox = document.getElementById('ec_preview_cropped');
            const previewImg = document.getElementById('ec_preview_cropped_img');
            const overlay    = document.getElementById('ecCropperOverlay');
            const cropImg    = document.getElementById('ecCropImage');
            let cropper      = null;
            let currentExt   = 'jpg';

            inputRaw.addEventListener('change', function (e) {
                const file = e.target.files[0];
                if (!file) return;
                if (file.size > 5 * 1024 * 1024) { alert('La imagen no puede superar 5MB'); this.value = ''; return; }
                currentExt = file.name.split('.').pop().toLowerCase();
                const url  = URL.createObjectURL(file);
                if (cropper) { cropper.destroy(); cropper = null; }
                cropImg.src = url;
                overlay.classList.add('active');
                cropImg.onload = function () {
                    cropper = new Cropper(cropImg, {
                        aspectRatio: ASPECT, viewMode: 2, dragMode: 'move',
                        autoCropArea: 1, restore: false, guides: true, center: true,
                        highlight: true, cropBoxMovable: true, cropBoxResizable: true,
                        toggleDragModeOnDblclick: false, background: true,
                    });
                };
                this.value = '';
            });

            document.getElementById('ecBtnCropConfirm').addEventListener('click', function () {
                if (!cropper) return;
                const mime = currentExt === 'png' ? 'image/png' : 'image/jpeg';
                const b64  = cropper.getCroppedCanvas({ width: 1200, height: 600 }).toDataURL(mime, 0.92);
                inputCrop.value = b64;
                inputExt.value  = currentExt;
                previewImg.src  = b64;
                previewBox.classList.add('visible');
                document.getElementById('ec_current_image_wrap').style.display = 'none';
                lbl.classList.add('has-file');
                lblText.textContent = 'Imagen lista — clic para cambiar';
                closeCropperEditar();
            });

            document.getElementById('ecBtnCropCancel').addEventListener('click', closeCropperEditar);
            document.getElementById('ecBtnCropClose').addEventListener('click', closeCropperEditar);
            overlay.addEventListener('click', function (e) { if (e.target === overlay) closeCropperEditar(); });

            function closeCropperEditar() {
                overlay.classList.remove('active');
                if (cropper) { cropper.destroy(); cropper = null; }
            }
        })();

        /* ── Cierre por clic en backdrop (modales admin) ────── */
        window.addEventListener('click', function (event) {
            const t = event.target;
            if (t === document.getElementById('modalEliminar'))    cerrarModal();
            if (t === document.getElementById('modalCrearCurso'))  cerrarModalCrear();
            if (t === document.getElementById('modalEditarCurso')) cerrarModalEditar();
        });
    </script>
    <?php endif; ?>

</body>
</html>