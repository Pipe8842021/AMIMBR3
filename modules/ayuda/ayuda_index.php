<?php
/**
 * Centro de Ayuda - Módulo de Ayuda
 * Recursos y guías personalizados para administradores
 */

// Incluir configuración de sesión y base de datos
require_once '../../config/session.php';
require_once '../../config/database.php';

// Verificar autenticación
require_once '../../includes/auth_check.php';


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

// Detectar rol
$rol          = $user['rol'] ?? '';
$es_admin     = ($rol === 'admin');
$es_profesor  = ($rol === 'profesor');
$es_estudiante= ($rol === 'estudiante');

// ── Videos según rol ──────────────────────────────────────────
$todos_los_videos = [
    // Admin
    ['id'=>1,'roles'=>['admin'],
     'titulo'=>'Gestión completa de usuarios',
     'descripcion'=>'Aprende a crear, editar y gestionar usuarios del sistema, asignar roles y permisos.',
     'duracion'=>'12:30','categoria'=>'Usuarios'],
    ['id'=>2,'roles'=>['admin'],
     'titulo'=>'Configuración de cursos y asignaciones',
     'descripcion'=>'Tutorial completo para crear cursos, asignar profesores y gestionar inscripciones.',
     'duracion'=>'15:48','categoria'=>'Cursos'],
    // Profesor
    ['id'=>3,'roles'=>['profesor'],
     'titulo'=>'Cómo gestionar tu lista de estudiantes',
     'descripcion'=>'Consulta los estudiantes inscritos en tus grupos y revisa su información.',
     'duracion'=>'5:20','categoria'=>'Grupos'],
    ['id'=>4,'roles'=>['profesor'],
     'titulo'=>'Cómo consultar y gestionar tus horarios',
     'descripcion'=>'Revisa los días y horas de tus clases y cómo reportar cambios.',
     'duracion'=>'4:50','categoria'=>'Horarios'],
    // Estudiante
    ['id'=>5,'roles'=>['estudiante'],
     'titulo'=>'Cómo consultar tu horario de clases',
     'descripcion'=>'Aprende a revisar tus clases, grupos y días de la semana en el módulo de Horario.',
     'duracion'=>'4:15','categoria'=>'Horario'],
    ['id'=>6,'roles'=>['estudiante'],
     'titulo'=>'Cómo ver tus cursos matriculados',
     'descripcion'=>'Consulta los detalles de cada curso en el que estás inscrito, tu grupo y tu profesor.',
     'duracion'=>'3:40','categoria'=>'Cursos'],
];
$videos_tutoriales = array_values(array_filter(
    $todos_los_videos,
    fn($v) => in_array($rol, $v['roles'])
));

// ── Todas las preguntas con roles permitidos ──────────────────
$todas_las_preguntas = [
    // General — todos
    ['id'=>1,'categoria'=>'general','roles'=>['admin','profesor','estudiante'],
     'pregunta'=>'¿Cómo recupero mi sesión si se cierra inesperadamente?',
     'respuesta'=>'Si tu sesión expira, el sistema te redirigirá al login. Ingresa tus credenciales nuevamente. Si olvidaste tu contraseña, usa <strong>"¿Olvidaste tu contraseña?"</strong> en la pantalla de inicio.'],
    ['id'=>2,'categoria'=>'general','roles'=>['admin','profesor','estudiante'],
     'pregunta'=>'¿El sistema funciona en dispositivos móviles?',
     'respuesta'=>'Sí, Amimbré es completamente responsivo y funciona en smartphones y tablets. Recomendamos usar Chrome, Firefox o Safari actualizados.'],

    // Mi Cuenta — todos
    ['id'=>3,'categoria'=>'mi-cuenta','roles'=>['admin','profesor','estudiante'],
     'pregunta'=>'¿Cómo puedo cambiar mi contraseña?',
     'respuesta'=>'Ve a <strong>Configuración &gt; Mi Perfil &gt; Seguridad</strong> y haz clic en "Cambiar contraseña". Ingresa tu contraseña actual y la nueva dos veces para confirmar.'],
    ['id'=>4,'categoria'=>'mi-cuenta','roles'=>['admin','profesor','estudiante'],
     'pregunta'=>'¿Cómo actualizo mi información personal?',
     'respuesta'=>'Dirígete a <strong>Configuración &gt; Mi Perfil</strong>, edita tu nombre, correo y foto de perfil. No olvides hacer clic en "Guardar cambios" al terminar.'],

    // Usuarios — solo admin
    ['id'=>5,'categoria'=>'usuarios','roles'=>['admin'],
     'pregunta'=>'¿Cómo creo un nuevo usuario en el sistema?',
     'respuesta'=>'Ve a <strong>Usuarios &gt; Crear Usuario</strong>. Completa nombre, correo y rol, luego haz clic en "Registrar Usuario". El usuario recibirá sus credenciales por correo.'],
    ['id'=>6,'categoria'=>'usuarios','roles'=>['admin'],
     'pregunta'=>'¿Cómo cambio el rol de un usuario?',
     'respuesta'=>'Ve a <strong>Usuarios</strong>, busca el usuario y haz clic en "Editar". Desde el campo "Rol" asígnale administrador, profesor o estudiante y guarda los cambios.'],
    ['id'=>7,'categoria'=>'usuarios','roles'=>['admin'],
     'pregunta'=>'¿Cómo desactivo un usuario sin eliminarlo?',
     'respuesta'=>'En <strong>Usuarios</strong>, edita el usuario y cambia el campo "Estado" a <strong>Inactivo</strong>. Sus datos se conservarán pero no podrá iniciar sesión.'],

    // Cursos — admin y profesor (distintas preguntas)
    ['id'=>8,'categoria'=>'cursos','roles'=>['admin'],
     'pregunta'=>'¿Cómo creo un nuevo curso?',
     'respuesta'=>'Ve a <strong>Cursos &gt; Nuevo Curso</strong>. Completa nombre, descripción, nivel, duración, cupo y precio. Al guardar quedará disponible para asignar grupos.'],
    ['id'=>9,'categoria'=>'cursos','roles'=>['admin'],
     'pregunta'=>'¿Cómo asigno un profesor a un curso?',
     'respuesta'=>'En <strong>Cursos &gt; Gestionar Cursos</strong>, selecciona el curso y haz clic en "Editar". En "Asignación de Profesores" selecciona al profesor del listado disponible.'],
    ['id'=>10,'categoria'=>'cursos','roles'=>['profesor'],
     'pregunta'=>'¿Dónde veo los cursos que tengo asignados?',
     'respuesta'=>'En el menú lateral ve a <strong>Cursos</strong>. Verás únicamente los cursos en los que tienes grupos activos asignados.'],
    ['id'=>11,'categoria'=>'cursos','roles'=>['profesor'],
     'pregunta'=>'¿Puedo ver los detalles de un curso que imparto?',
     'respuesta'=>'Sí. Desde <strong>Cursos</strong> haz clic en "Ver Detalles" para consultar descripción, duración, precio y los grupos asociados al curso.'],
    ['id'=>12,'categoria'=>'cursos','roles'=>['estudiante'],
     'pregunta'=>'¿Dónde veo los cursos en los que estoy matriculado?',
     'respuesta'=>'En el menú lateral ve a <strong>Cursos</strong>. Ahí encontrarás todos los cursos activos en los que tienes matrícula vigente.'],
    ['id'=>13,'categoria'=>'cursos','roles'=>['estudiante'],
     'pregunta'=>'¿Cómo veo los detalles de mi curso?',
     'respuesta'=>'Desde <strong>Cursos</strong> haz clic en "Ver Detalles". Verás descripción, duración, precio, tu grupo y tu profesor asignado.'],

    // Horarios — admin y profesor
    ['id'=>14,'categoria'=>'horarios','roles'=>['admin'],
     'pregunta'=>'¿Cómo configuro el horario de un grupo?',
     'respuesta'=>'En <strong>Horarios</strong> selecciona el grupo, elige días y rango horario. El sistema detectará conflictos automáticamente.'],
    ['id'=>15,'categoria'=>'horarios','roles'=>['admin'],
     'pregunta'=>'¿Puedo ver todos los horarios en una sola vista?',
     'respuesta'=>'Sí. En <strong>Horarios &gt; Vista General</strong> verás un calendario con todos los grupos activos, con filtros por profesor o curso.'],
    ['id'=>16,'categoria'=>'horarios','roles'=>['profesor'],
     'pregunta'=>'¿Cómo consulto el horario de mis clases?',
     'respuesta'=>'Ve a <strong>Horarios</strong> en el menú lateral. Verás los días y horas de cada grupo que tienes asignado.'],
    ['id'=>17,'categoria'=>'horarios','roles'=>['profesor'],
     'pregunta'=>'¿Qué hago si hay un conflicto de horario en mi grupo?',
     'respuesta'=>'Contacta a la administración. El sistema detecta conflictos automáticamente e impedirá guardar horarios superpuestos.'],
    ['id'=>18,'categoria'=>'horarios','roles'=>['estudiante'],
     'pregunta'=>'¿Cómo consulto mi horario de clases?',
     'respuesta'=>'Ve al módulo <strong>Horario</strong> en el menú lateral. Verás los días y horas de tus clases con el grupo y el profesor asignado.'],
    ['id'=>19,'categoria'=>'horarios','roles'=>['estudiante'],
     'pregunta'=>'¿Qué hago si mi horario tiene un error?',
     'respuesta'=>'Contacta a la administración de la escuela usando el botón <strong>"Contactar soporte"</strong> al final de esta página.'],

    // Inscripciones — solo admin
    ['id'=>20,'categoria'=>'inscripciones','roles'=>['admin'],
     'pregunta'=>'¿Cómo gestiono las prematrículas pendientes?',
     'respuesta'=>'Accede a <strong>Inscripciones &gt; Prematrículas</strong>. Revisa los documentos adjuntos y aprueba o rechaza cada solicitud con un comentario.'],
    ['id'=>21,'categoria'=>'inscripciones','roles'=>['admin'],
     'pregunta'=>'¿Cómo matriculo manualmente a un estudiante?',
     'respuesta'=>'Ve a <strong>Inscripciones &gt; Nueva Matrícula</strong>. Selecciona el estudiante, curso y grupo disponible. El sistema registrará la matrícula de inmediato.'],

    // Mi Grupo — solo estudiante
    ['id'=>22,'categoria'=>'mi-grupo','roles'=>['estudiante'],
     'pregunta'=>'¿Cómo sé en qué grupo estoy?',
     'respuesta'=>'Desde <strong>Cursos &gt; Ver Detalles</strong> verás la sección "Mi Grupo" con el nombre del grupo, el profesor y el horario asignado.'],
    ['id'=>23,'categoria'=>'mi-grupo','roles'=>['estudiante'],
     'pregunta'=>'¿Puedo cambiar de grupo?',
     'respuesta'=>'Los cambios de grupo los gestiona la administración. Comunícate con la escuela indicando el motivo y evaluarán la disponibilidad de cupos.'],

    // Mis Grupos — solo profesor
    ['id'=>24,'categoria'=>'mis-grupos','roles'=>['profesor'],
     'pregunta'=>'¿Cómo veo los estudiantes de mi grupo?',
     'respuesta'=>'Ve a <strong>Grupos</strong> y selecciona el grupo. Verás el listado completo de estudiantes matriculados con su información de contacto.'],
    ['id'=>25,'categoria'=>'mis-grupos','roles'=>['profesor'],
     'pregunta'=>'¿Qué información puedo ver de mis grupos?',
     'respuesta'=>'Puedes ver el nombre del grupo, curso, horario, cupo, fecha de inicio y el listado de estudiantes inscritos activos.'],

    // Reportes — solo admin
    ['id'=>26,'categoria'=>'reportes','roles'=>['admin'],
     'pregunta'=>'¿Qué tipos de reportes puedo generar?',
     'respuesta'=>'Puedes generar reportes de <strong>estudiantes matriculados</strong>, <strong>ingresos por curso</strong>, <strong>asistencia</strong> y <strong>ocupación de grupos</strong>. Exportables en PDF o Excel.'],
    ['id'=>27,'categoria'=>'reportes','roles'=>['admin'],
     'pregunta'=>'¿Cómo exporto un reporte a PDF?',
     'respuesta'=>'En <strong>Reportes</strong>, configura los filtros y haz clic en <strong>"Exportar PDF"</strong> en la parte superior derecha. El archivo se descargará automáticamente.'],
];

// Filtrar preguntas según el rol actual
$preguntas_frecuentes = array_values(array_filter(
    $todas_las_preguntas,
    fn($p) => in_array($rol, $p['roles'])
));

// ── Categorías según rol ──────────────────────────────────────
$todas_las_categorias = [
    ['id'=>'todos',       'nombre'=>'Todas',       'icono'=>'apps',           'roles'=>['admin','profesor','estudiante']],
    ['id'=>'general',     'nombre'=>'General',     'icono'=>'info',           'roles'=>['admin','profesor','estudiante']],
    ['id'=>'mi-cuenta',   'nombre'=>'Mi Cuenta',   'icono'=>'account_circle', 'roles'=>['admin','profesor','estudiante']],
    ['id'=>'usuarios',    'nombre'=>'Usuarios',    'icono'=>'group',          'roles'=>['admin']],
    ['id'=>'cursos',      'nombre'=>'Cursos',      'icono'=>'school',         'roles'=>['admin','profesor','estudiante']],
    ['id'=>'horarios',    'nombre'=>'Horarios',    'icono'=>'schedule',       'roles'=>['admin','profesor','estudiante']],
    ['id'=>'inscripciones','nombre'=>'Inscripciones','icono'=>'description',  'roles'=>['admin']],
    ['id'=>'reportes',    'nombre'=>'Reportes',    'icono'=>'assessment',     'roles'=>['admin']],
    ['id'=>'mis-grupos',  'nombre'=>'Mis Grupos',  'icono'=>'group_work',     'roles'=>['profesor']],
    ['id'=>'mi-grupo',    'nombre'=>'Mi Grupo',    'icono'=>'group',          'roles'=>['estudiante']],
];
$categorias = array_values(array_filter(
    $todas_las_categorias,
    fn($c) => in_array($rol, $c['roles'])
));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Centro de Ayuda - Amimbré</title>
    <link rel="shortcut icon" href="../../assets/img/3.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0" />
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="../../assets/css/colores.css">
    <link rel="stylesheet" href="../../assets/css/style-ayuda.css">
</head>
<body>
    <!-- Include Header/Sidebar -->
    <?php 
    if (file_exists('../../includes/header.php')) {
        require_once '../../includes/header.php'; 
    } else {
        echo '<p style="padding: 20px; background: #fff3cd; color: #856404;">Advertencia: El archivo header.php no existe</p>';
    }
    ?>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Header -->
        <div class="help-header">
            <div class="help-header-content">
                <h1>Centro de Ayuda</h1>
                <p>
                    <?php
                    if ($es_admin)      echo 'Recursos y guías para Administradores';
                    elseif ($es_profesor) echo 'Recursos y guías para Profesores';
                    else                 echo 'Recursos y guías para Estudiantes';
                    ?>
                </p>
            </div>
            <div class="help-header-actions">
                <button class="btn-secondary" onclick="window.location.href='../dashboard/'">
                    <span class="material-symbols-rounded">arrow_back</span>
                    Volver al Dashboard
                </button>
            </div>
        </div>

        <!-- Search Bar -->
        <div class="search-container">
            <div class="search-box">
                <span class="material-symbols-rounded search-icon">search</span>
                <input 
                    type="text" 
                    id="searchInput" 
                    class="search-input" 
                    placeholder="Buscar en preguntas, videos y guías..."
                    autocomplete="off"
                >
            </div>
        </div>

        <!-- Filter Categories -->
        <div class="categories-container">
            <div class="categories-scroll">
                <?php foreach ($categorias as $categoria): ?>
                <button 
                    class="category-chip <?php echo $categoria['id'] === 'todos' ? 'active' : ''; ?>" 
                    data-category="<?php echo $categoria['id']; ?>"
                >
                    <span class="material-symbols-rounded"><?php echo $categoria['icono']; ?></span>
                    <?php echo $categoria['nombre']; ?>
                </button>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Quick Links Navigation -->
        <div class="quick-nav-container">
            <button class="nav-tab active" data-section="videos">
                <span class="material-symbols-rounded">smart_display</span>
                Video Tutoriales
            </button>
            <button class="nav-tab" data-section="preguntas">
                <span class="material-symbols-rounded">help</span>
                Preguntas Frecuentes
            </button>
            <button class="nav-tab" data-section="guias">
                <span class="material-symbols-rounded">menu_book</span>
                Guías Visuales
            </button>
        </div>

        <!-- Videos Destacados Section -->
        <section id="videos-section" class="content-section">
            <div class="section-header">
                <div>
                    <h2 class="section-title">
                        <span class="material-symbols-rounded">movie</span>
                        Videos Destacados para Ti
                    </h2>
                    <p class="section-subtitle">Tutoriales paso a paso sobre las funcionalidades principales</p>
                </div>
            </div>

            <div class="videos-grid">
                <?php foreach ($videos_tutoriales as $video): ?>
                <div class="video-card" data-video-id="<?php echo $video['id']; ?>">
                    <div class="video-thumbnail">
                        <div class="thumbnail-placeholder">
                            <span class="material-symbols-rounded">play_circle</span>
                        </div>
                        <div class="video-duration"><?php echo $video['duracion']; ?></div>
                    </div>
                    <div class="video-content">
                        <h3 class="video-title"><?php echo htmlspecialchars($video['titulo']); ?></h3>
                        <p class="video-description"><?php echo htmlspecialchars($video['descripcion']); ?></p>
                        <div class="video-footer">
                            <span class="video-category"><?php echo $video['categoria']; ?></span>
                            <button class="btn-play">
                                <span class="material-symbols-rounded">play_arrow</span>
                                Ver tutorial
                            </button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- Preguntas Frecuentes Section -->
        <section id="preguntas-section" class="content-section">
            <div class="section-header">
                <div>
                    <h2 class="section-title">
                        <span class="material-symbols-rounded">contact_support</span>
                        Preguntas Frecuentes
                    </h2>
                    <p class="section-subtitle"><?php echo count($preguntas_frecuentes); ?> preguntas encontradas</p>
                </div>
            </div>

            <div class="faq-container" id="faq-container">
                <?php foreach ($preguntas_frecuentes as $faq): ?>
                <div class="faq-item"
                     data-faq-id="<?php echo $faq['id']; ?>"
                     data-category="<?php echo $faq['categoria']; ?>">
                    <button class="faq-question">
                        <span class="faq-icon">
                            <span class="material-symbols-rounded">help_outline</span>
                        </span>
                        <span class="faq-text"><?php echo htmlspecialchars($faq['pregunta']); ?></span>
                        <span class="material-symbols-rounded faq-arrow">expand_more</span>
                    </button>
                    <div class="faq-answer">
                        <p><?php echo $faq['respuesta']; ?></p>
                    </div>
                </div>
                <?php endforeach; ?>

                <div class="faq-empty" id="faq-empty" style="display:none;">
                    <span class="material-symbols-rounded" style="font-size:3rem;color:var(--text-secondary);opacity:0.4;">search_off</span>
                    <p style="color:var(--text-secondary);margin-top:12px;font-size:0.95rem;">No hay preguntas para esta categoría.</p>
                </div>
            </div>
        </section>

        <!-- Guías Visuales Section -->
        <section id="guias-section" class="content-section" style="display: none;">
            <div class="section-header">
                <div>
                    <h2 class="section-title">
                        <span class="material-symbols-rounded">collections_bookmark</span>
                        Guías Visuales
                    </h2>
                    <p class="section-subtitle">Documentación visual con capturas de pantalla</p>
                </div>
            </div>

            <div class="guides-grid">
                <?php
                $todas_las_guias = [
                    // Admin
                    ['key'=>'usuarios',     'roles'=>['admin'],              'clase'=>'usuarios',     'icono'=>'group',       'titulo'=>'Gestión de Usuarios',      'descripcion'=>'Guía completa para administrar usuarios, roles y permisos'],
                    ['key'=>'cursos',       'roles'=>['admin'],              'clase'=>'cursos',       'icono'=>'school',      'titulo'=>'Configuración de Cursos',  'descripcion'=>'Aprende a crear y gestionar cursos, grupos y horarios'],
                    ['key'=>'inscripciones','roles'=>['admin'],              'clase'=>'inscripciones','icono'=>'description', 'titulo'=>'Proceso de Inscripciones', 'descripcion'=>'Gestión de prematrículas y matrículas paso a paso'],
                    ['key'=>'reportes',     'roles'=>['admin'],              'clase'=>'reportes',     'icono'=>'assessment',  'titulo'=>'Generación de Reportes',   'descripcion'=>'Cómo generar y exportar reportes del sistema'],
                    // Profesor
                    ['key'=>'mis-grupos-guia','roles'=>['profesor'],         'clase'=>'cursos',       'icono'=>'group_work',  'titulo'=>'Gestionar mis grupos',     'descripcion'=>'Cómo consultar estudiantes y detalles de tus grupos asignados'],
                    ['key'=>'horario-prof', 'roles'=>['profesor'],           'clase'=>'inscripciones','icono'=>'schedule',    'titulo'=>'Consultar mis horarios',   'descripcion'=>'Cómo revisar los horarios de tus clases asignadas'],
                    // Estudiante
                    ['key'=>'ver-cursos',   'roles'=>['estudiante'],         'clase'=>'cursos',       'icono'=>'school',      'titulo'=>'Ver mis cursos',           'descripcion'=>'Cómo acceder y revisar los cursos en los que estás matriculado'],
                    ['key'=>'ver-horario',  'roles'=>['estudiante'],         'clase'=>'inscripciones','icono'=>'schedule',    'titulo'=>'Consultar mi horario',     'descripcion'=>'Cómo revisar los días y horas de tus clases'],
                    ['key'=>'mi-perfil',    'roles'=>['admin','profesor','estudiante'],'clase'=>'usuarios','icono'=>'account_circle','titulo'=>'Gestionar mi perfil','descripcion'=>'Cómo actualizar tu información personal y contraseña'],
                ];
                foreach ($todas_las_guias as $g):
                    if (!in_array($rol, $g['roles'])) continue;
                ?>
                <div class="guide-card">
                    <div class="guide-icon <?php echo $g['clase']; ?>">
                        <span class="material-symbols-rounded"><?php echo $g['icono']; ?></span>
                    </div>
                    <h3 class="guide-title"><?php echo $g['titulo']; ?></h3>
                    <p class="guide-description"><?php echo $g['descripcion']; ?></p>
                    <button class="btn-guide" data-guide="<?php echo $g['key']; ?>">
                        Ver guía
                        <span class="material-symbols-rounded">arrow_forward</span>
                    </button>
                </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- Support Contact Section -->
        <div class="support-card">
            <div class="support-content">
                <div class="support-icon">
                    <span class="material-symbols-rounded">headset_mic</span>
                </div>
                <div class="support-text">
                    <h3>¿Necesitas más ayuda?</h3>
                    <p>Nuestro equipo de soporte está disponible para ayudarte</p>
                </div>
            </div>
            <div class="support-actions">
                <a 
                    href="https://wa.me/573001234567?text=Hola,%20necesito%20ayuda%20con%20el%20sistema%20Amimbré" 
                    target="_blank" 
                    rel="noopener noreferrer"
                    class="btn-contact"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
                    </svg>
                    Contactar soporte
                </a>
            </div>
        </div>
    </main>

    <!-- Modal Guías Visuales -->
    <div class="guia-modal-overlay" id="guiaModalOverlay">
        <div class="guia-modal-box">
            <div class="guia-modal-header">
                <div class="guia-modal-title-wrap">
                    <span class="guia-modal-icon" id="guiaModalIcon">
                        <span class="material-symbols-rounded" id="guiaModalIconSymbol">menu_book</span>
                    </span>
                    <div>
                        <h2 id="guiaModalTitle">Guía</h2>
                        <p id="guiaModalSubtitle" class="guia-modal-subtitle"></p>
                    </div>
                </div>
                <button class="guia-modal-close" id="guiaModalClose">
                    <span class="material-symbols-rounded">close</span>
                </button>
            </div>
            <div class="guia-modal-body" id="guiaModalBody"></div>
        </div>
    </div>

    <script>
    (function () {

        const chips        = document.querySelectorAll('.category-chip');
        const faqItems     = document.querySelectorAll('.faq-item[data-category]');
        const faqEmpty     = document.getElementById('faq-empty');
        const faqSubtitle  = document.querySelector('#preguntas-section .section-subtitle');
        const navTabs      = document.querySelectorAll('.nav-tab');
        const sections     = {
            videos    : document.getElementById('videos-section'),
            preguntas : document.getElementById('preguntas-section'),
            guias     : document.getElementById('guias-section'),
        };

        // ── Estado inicial: solo videos visible ────────────────────
        sections.videos.style.display    = '';
        sections.preguntas.style.display = 'none';
        sections.guias.style.display     = 'none';

        // ── Filtrar FAQ por categoría ──────────────────────────────
        function filterFAQ(cat) {
            let visibles = 0;
            faqItems.forEach(item => {
                const match = cat === 'todos' || item.dataset.category === cat;
                item.style.display = match ? '' : 'none';
                if (!match && item.classList.contains('active')) {
                    item.classList.remove('active');
                    item.querySelector('.faq-answer').style.maxHeight = '0';
                }
                if (match) visibles++;
            });
            if (faqEmpty) faqEmpty.style.display = visibles === 0 ? 'flex' : 'none';
            if (faqSubtitle) {
                faqSubtitle.textContent = visibles === 1
                    ? '1 pregunta encontrada'
                    : `${visibles} preguntas encontradas`;
            }
        }

        // ── Chips de categoría ─────────────────────────────────────
        chips.forEach(chip => {
            chip.addEventListener('click', function () {
                chips.forEach(c => c.classList.remove('active'));
                this.classList.add('active');
                const cat = this.dataset.category;

                if (cat === 'todos') {
                    // "Todas": mostrar solo videos (tal como está)
                    Object.keys(sections).forEach(k =>
                        sections[k].style.display = k === 'videos' ? '' : 'none'
                    );
                    navTabs.forEach(t => t.classList.remove('active'));
                    document.querySelector('[data-section="videos"]').classList.add('active');
                } else {
                    // Filtro específico: ir a preguntas filtradas
                    Object.keys(sections).forEach(k =>
                        sections[k].style.display = k === 'preguntas' ? '' : 'none'
                    );
                    navTabs.forEach(t => t.classList.remove('active'));
                    document.querySelector('[data-section="preguntas"]').classList.add('active');
                    filterFAQ(cat);
                }
            });
        });

        // ── Tabs de navegación ─────────────────────────────────────
        navTabs.forEach(tab => {
            tab.addEventListener('click', function () {
                navTabs.forEach(t => t.classList.remove('active'));
                this.classList.add('active');
                const target = this.dataset.section;
                Object.keys(sections).forEach(k =>
                    sections[k].style.display = k === target ? '' : 'none'
                );
                if (target === 'preguntas') {
                    const activeChip = document.querySelector('.category-chip.active');
                    filterFAQ(activeChip ? activeChip.dataset.category : 'todos');
                }
                if (target !== 'preguntas') {
                    chips.forEach(c => c.classList.remove('active'));
                    document.querySelector('[data-category="todos"]').classList.add('active');
                }
            });
        });

        // ── Acordeón FAQ ───────────────────────────────────────────
        document.querySelectorAll('.faq-question').forEach(btn => {
            btn.addEventListener('click', function () {
                const item   = this.closest('.faq-item');
                const answer = item.querySelector('.faq-answer');
                const isOpen = item.classList.contains('active');
                document.querySelectorAll('.faq-item.active').forEach(open => {
                    open.classList.remove('active');
                    open.querySelector('.faq-answer').style.maxHeight = '0';
                });
                if (!isOpen) {
                    item.classList.add('active');
                    answer.style.maxHeight = answer.scrollHeight + 'px';
                }
            });
        });

        // ── Búsqueda en tiempo real ────────────────────────────────
        const searchInput = document.getElementById('searchInput');
        searchInput && searchInput.addEventListener('input', function () {
            const q = this.value.trim().toLowerCase();
            if (!q) {
                const activeChip = document.querySelector('.category-chip.active');
                if (!activeChip || activeChip.dataset.category === 'todos') {
                    Object.keys(sections).forEach(k =>
                        sections[k].style.display = k === 'videos' ? '' : 'none'
                    );
                    navTabs.forEach(t => t.classList.remove('active'));
                    document.querySelector('[data-section="videos"]').classList.add('active');
                } else {
                    filterFAQ(activeChip.dataset.category);
                }
                return;
            }
            Object.keys(sections).forEach(k =>
                sections[k].style.display = k === 'preguntas' ? '' : 'none'
            );
            navTabs.forEach(t => t.classList.remove('active'));
            document.querySelector('[data-section="preguntas"]').classList.add('active');
            let visibles = 0;
            faqItems.forEach(item => {
                const texto = (item.querySelector('.faq-text').textContent +
                               item.querySelector('.faq-answer p').textContent).toLowerCase();
                const match = texto.includes(q);
                item.style.display = match ? '' : 'none';
                if (match) visibles++;
            });
            if (faqEmpty) faqEmpty.style.display = visibles === 0 ? 'flex' : 'none';
            if (faqSubtitle) {
                faqSubtitle.textContent = visibles === 1
                    ? '1 pregunta encontrada'
                    : `${visibles} preguntas encontradas`;
            }
        });

        // ── Modal Guías Visuales ───────────────────────────────────
        const guiasData = {
            // ── Admin ──────────────────────────────────────────────
            usuarios: {
                titulo: 'Gestión de Usuarios', subtitulo: 'Crea, edita y administra todos los usuarios del sistema',
                icono: 'group', color: 'green',
                pasos: [
                    { num:1, icono:'menu',      titulo:'Accede al módulo',    desc:'Ve a <strong>Usuarios</strong> en el menú lateral izquierdo.' },
                    { num:2, icono:'person_add', titulo:'Crear usuario',       desc:'Haz clic en <strong>"Crear Usuario"</strong> e ingresa nombre, correo y contraseña temporal.' },
                    { num:3, icono:'badge',      titulo:'Asignar rol',         desc:'Selecciona: <strong>Administrador, Profesor o Estudiante</strong>.' },
                    { num:4, icono:'toggle_on',  titulo:'Estado del usuario',  desc:'Define si el usuario quedará <strong>Activo o Inactivo</strong>.' },
                    { num:5, icono:'save',       titulo:'Guardar y notificar', desc:'El usuario recibirá sus <strong>credenciales por correo</strong> automáticamente.' },
                    { num:6, icono:'edit',       titulo:'Editar o desactivar', desc:'Desde el listado puedes <strong>editar, cambiar rol o desactivar</strong> en cualquier momento.' },
                ]
            },
            cursos: {
                titulo: 'Configuración de Cursos', subtitulo: 'Crea cursos, asigna profesores y gestiona grupos',
                icono: 'school', color: 'blue',
                pasos: [
                    { num:1, icono:'add_circle',  titulo:'Nuevo curso',         desc:'Ve a <strong>Cursos &gt; Nuevo Curso</strong> y completa nombre, descripción y nivel.' },
                    { num:2, icono:'payments',    titulo:'Detalles económicos',  desc:'Define <strong>duración, cupo máximo y precio mensual</strong>.' },
                    { num:3, icono:'image',       titulo:'Imagen del curso',     desc:'Sube una imagen; el sistema la recortará en <strong>proporción 2:1</strong>.' },
                    { num:4, icono:'group_work',  titulo:'Crear grupos',         desc:'Dentro del curso crea <strong>grupos</strong> con su propio cupo.' },
                    { num:5, icono:'person',      titulo:'Asignar profesor',     desc:'Selecciona el <strong>profesor disponible</strong> para cada grupo.' },
                    { num:6, icono:'schedule',    titulo:'Definir horario',      desc:'Ve a <strong>Horarios</strong> y asigna días y horas. El sistema detecta <strong>conflictos automáticamente</strong>.' },
                ]
            },
            inscripciones: {
                titulo: 'Proceso de Inscripciones', subtitulo: 'Gestiona prematrículas y matrículas paso a paso',
                icono: 'description', color: 'orange',
                pasos: [
                    { num:1, icono:'inbox',        titulo:'Revisar solicitudes',  desc:'Accede a <strong>Inscripciones &gt; Prematrículas</strong> para ver solicitudes pendientes.' },
                    { num:2, icono:'folder_open',  titulo:'Verificar documentos', desc:'Revisa los <strong>documentos adjuntos</strong> de cada solicitud.' },
                    { num:3, icono:'check_circle', titulo:'Aprobar o rechazar',   desc:'<strong>Aprueba</strong> si cumple los requisitos o <strong>rechaza</strong> con un comentario.' },
                    { num:4, icono:'group_add',    titulo:'Asignar grupo',        desc:'Al aprobar, selecciona el <strong>curso, grupo y cupo disponible</strong>.' },
                    { num:5, icono:'assignment_turned_in', titulo:'Formalizar matrícula', desc:'El estudiante queda <strong>registrado activo</strong> en el sistema.' },
                    { num:6, icono:'edit_note',    titulo:'Matrícula manual',     desc:'También puedes ir a <strong>Inscripciones &gt; Nueva Matrícula</strong> sin prematrícula.' },
                ]
            },
            reportes: {
                titulo: 'Generación de Reportes', subtitulo: 'Genera y exporta reportes en PDF o Excel',
                icono: 'assessment', color: 'yellow',
                pasos: [
                    { num:1, icono:'bar_chart',      titulo:'Acceder a Reportes',    desc:'Ve al módulo <strong>Reportes</strong> desde el menú lateral.' },
                    { num:2, icono:'list_alt',        titulo:'Elegir tipo de reporte',desc:'Selecciona: <strong>Matrículas, Ingresos, Asistencia u Ocupación</strong>.' },
                    { num:3, icono:'filter_alt',      titulo:'Aplicar filtros',       desc:'Filtra por <strong>fechas, curso o grupo</strong> para acotar los datos.' },
                    { num:4, icono:'preview',         titulo:'Vista previa',          desc:'Revisa el <strong>resumen en pantalla</strong> antes de exportar.' },
                    { num:5, icono:'picture_as_pdf',  titulo:'Exportar PDF',          desc:'Haz clic en <strong>"Exportar PDF"</strong> para descargar en formato imprimible.' },
                    { num:6, icono:'table_chart',     titulo:'Exportar Excel',        desc:'Usa <strong>"Exportar Excel"</strong> para obtener datos editables.' },
                ]
            },
            // ── Profesor ───────────────────────────────────────────
            'mis-grupos-guia': {
                titulo: 'Gestionar mis grupos', subtitulo: 'Consulta estudiantes y detalles de tus grupos asignados',
                icono: 'group_work', color: 'blue',
                pasos: [
                    { num:1, icono:'menu',        titulo:'Ve a Grupos',           desc:'Haz clic en <strong>Grupos</strong> en el menú lateral.' },
                    { num:2, icono:'list',         titulo:'Ver mis grupos',        desc:'Verás únicamente los grupos en los que estás asignado como profesor.' },
                    { num:3, icono:'visibility',   titulo:'Ver Detalles',          desc:'Haz clic en un grupo para ver el <strong>listado completo de estudiantes</strong>.' },
                    { num:4, icono:'schedule',     titulo:'Revisar horario',       desc:'En los detalles del grupo encontrarás el <strong>horario y fecha de inicio</strong>.' },
                ]
            },
            'horario-prof': {
                titulo: 'Consultar mis horarios', subtitulo: 'Revisa los horarios de tus clases asignadas',
                icono: 'schedule', color: 'green',
                pasos: [
                    { num:1, icono:'menu',          titulo:'Accede a Horarios',    desc:'Ve al módulo <strong>Horario</strong> en el menú lateral.' },
                    { num:2, icono:'calendar_month','titulo':'Vista de tus clases', desc:'Verás un calendario con tus clases organizadas por <strong>día y hora</strong>.' },
                    { num:3, icono:'info',           titulo:'Detalle de clase',     desc:'Cada bloque muestra el <strong>grupo, curso y aula</strong> asignada.' },
                    { num:4, icono:'warning',        titulo:'Conflictos',           desc:'Si hay un problema de horario, contacta a la <strong>administración</strong>.' },
                ]
            },
            // ── Estudiante ─────────────────────────────────────────
            'ver-cursos': {
                titulo: 'Ver mis cursos', subtitulo: 'Cómo acceder y revisar los cursos matriculados',
                icono: 'school', color: 'blue',
                pasos: [
                    { num:1, icono:'menu',        titulo:'Abre el menú',          desc:'Haz clic en <strong>Cursos</strong> en el menú lateral izquierdo.' },
                    { num:2, icono:'grid_view',    titulo:'Ve tus cursos',         desc:'Verás las tarjetas de todos los cursos con <strong>matrícula activa</strong>.' },
                    { num:3, icono:'visibility',   titulo:'Ver Detalles',          desc:'Haz clic en <strong>"Ver Detalles"</strong> para ver descripción, duración y precio.' },
                    { num:4, icono:'group_work',   titulo:'Tu grupo',              desc:'En "Mi Grupo" verás tu <strong>grupo asignado y el profesor</strong>.' },
                ]
            },
            'ver-horario': {
                titulo: 'Consultar mi horario', subtitulo: 'Cómo revisar los días y horas de tus clases',
                icono: 'schedule', color: 'green',
                pasos: [
                    { num:1, icono:'menu',          titulo:'Accede a Horario',     desc:'Ve a <strong>Horario</strong> en el menú lateral.' },
                    { num:2, icono:'calendar_month','titulo':'Vista semanal',      desc:'Verás un <strong>calendario con tus clases</strong> por día y hora.' },
                    { num:3, icono:'info',           titulo:'Detalle de clase',    desc:'Cada bloque muestra el <strong>curso, grupo y profesor</strong>.' },
                    { num:4, icono:'notifications',  titulo:'Avisos de cambios',   desc:'Recibirás notificaciones si hay <strong>cambios de horario</strong>.' },
                ]
            },
            // ── Todos los roles ────────────────────────────────────
            'mi-perfil': {
                titulo: 'Gestionar mi perfil', subtitulo: 'Actualiza tu información personal y contraseña',
                icono: 'account_circle', color: 'orange',
                pasos: [
                    { num:1, icono:'settings',   titulo:'Ir a Configuración',    desc:'Haz clic en <strong>Configuración</strong> en el menú lateral.' },
                    { num:2, icono:'badge',       titulo:'Mi Perfil',             desc:'Selecciona <strong>"Mi Perfil"</strong> para editar nombre, correo y foto.' },
                    { num:3, icono:'lock',        titulo:'Cambiar contraseña',    desc:'En la pestaña <strong>"Seguridad"</strong> actualiza tu contraseña.' },
                    { num:4, icono:'save',        titulo:'Guardar cambios',       desc:'Haz clic en <strong>"Guardar cambios"</strong> al terminar.' },
                ]
            },
        };

        const colorMap = {
            green : { bg:'var(--subtle-green)',  fg:'var(--primary-green)' },
            blue  : { bg:'var(--subtle-blue)',   fg:'var(--primary-blue)' },
            orange: { bg:'var(--subtle-orange)', fg:'var(--primary-orange)' },
            yellow: { bg:'var(--subtle-yellow)', fg:'var(--primary-yellow)' },
        };

        const overlay      = document.getElementById('guiaModalOverlay');
        const modalBody    = document.getElementById('guiaModalBody');
        const modalTitle   = document.getElementById('guiaModalTitle');
        const modalSub     = document.getElementById('guiaModalSubtitle');
        const modalIcon    = document.getElementById('guiaModalIcon');
        const modalIconSym = document.getElementById('guiaModalIconSymbol');
        const closeBtn     = document.getElementById('guiaModalClose');

        function openGuia(key) {
            const g = guiasData[key]; if (!g) return;
            const c = colorMap[g.color] || colorMap.blue;
            modalTitle.textContent      = g.titulo;
            modalSub.textContent        = g.subtitulo;
            modalIconSym.textContent    = g.icono;
            modalIcon.style.background  = c.bg;
            modalIcon.style.color       = c.fg;
            modalBody.innerHTML = g.pasos.map(p => `
                <div class="guia-paso">
                    <div class="guia-paso-num" style="background:${c.bg};color:${c.fg};">${p.num}</div>
                    <div class="guia-paso-content">
                        <div class="guia-paso-header">
                            <span class="material-symbols-rounded guia-paso-icono" style="color:${c.fg};">${p.icono}</span>
                            <strong class="guia-paso-titulo">${p.titulo}</strong>
                        </div>
                        <p class="guia-paso-desc">${p.desc}</p>
                    </div>
                </div>`).join('');
            overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeGuia() {
            overlay.classList.remove('active');
            document.body.style.overflow = '';
        }

        document.querySelectorAll('.btn-guide[data-guide]').forEach(btn =>
            btn.addEventListener('click', () => openGuia(btn.dataset.guide))
        );
        closeBtn.addEventListener('click', closeGuia);
        overlay.addEventListener('click', e => { if (e.target === overlay) closeGuia(); });
        document.addEventListener('keydown', e => { if (e.key === 'Escape') closeGuia(); });

    })();
    </script>
</body>
</html>