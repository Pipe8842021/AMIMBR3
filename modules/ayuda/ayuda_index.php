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

// Videos tutoriales destacados
$videos_tutoriales = [
    [
        'id' => 1,
        'titulo' => 'Gestión completa de usuarios',
        'descripcion' => 'Aprende a crear, editar y gestionar usuarios del sistema, asignar roles y permisos.',
        'duracion' => '12:30',
        'thumbnail' => '../../assets/img/tutoriales/gestion-usuarios.jpg',
        'categoria' => 'Usuarios'
    ],
    [
        'id' => 2,
        'titulo' => 'Configuración de cursos y asignaciones',
        'descripcion' => 'Tutorial completo para crear cursos, asignar profesores y gestionar inscripciones.',
        'duracion' => '15:48',
        'thumbnail' => '../../assets/img/tutoriales/configuracion-cursos.jpg',
        'categoria' => 'Cursos'
    ]
];

// Preguntas frecuentes con categoría
$preguntas_frecuentes = [
    // General
    [
        'id' => 1, 'categoria' => 'general',
        'pregunta' => '¿Cómo recupero mi sesión si se cierra inesperadamente?',
        'respuesta' => 'Si tu sesión expira, el sistema te redirigirá automáticamente al login. Ingresa tus credenciales nuevamente. Si olvidaste tu contraseña, usa la opción <strong>"¿Olvidaste tu contraseña?"</strong> en la pantalla de inicio.'
    ],
    [
        'id' => 2, 'categoria' => 'general',
        'pregunta' => '¿El sistema funciona en dispositivos móviles?',
        'respuesta' => 'Sí, Amimbré es completamente responsivo y funciona en smartphones y tablets. Para una experiencia óptima recomendamos usar navegadores actualizados como Chrome, Firefox o Safari.'
    ],
    // Mi Cuenta
    [
        'id' => 3, 'categoria' => 'mi-cuenta',
        'pregunta' => '¿Cómo puedo cambiar mi contraseña?',
        'respuesta' => 'Para cambiar tu contraseña, ve a <strong>Configuración > Mi Perfil > Seguridad</strong> y haz clic en "Cambiar contraseña". Deberás ingresar tu contraseña actual y luego la nueva contraseña dos veces para confirmar.'
    ],
    [
        'id' => 4, 'categoria' => 'mi-cuenta',
        'pregunta' => '¿Cómo actualizo mi información personal?',
        'respuesta' => 'Dirígete a <strong>Configuración > Mi Perfil</strong>, donde podrás editar tu nombre, correo electrónico, foto de perfil y otra información relevante. No olvides hacer clic en "Guardar cambios" al terminar.'
    ],
    // Usuarios
    [
        'id' => 5, 'categoria' => 'usuarios',
        'pregunta' => '¿Cómo creo un nuevo usuario en el sistema?',
        'respuesta' => 'Ve a <strong>Usuarios > Crear Usuario</strong>. Completa el formulario con los datos requeridos (nombre, correo, rol, etc.) y haz clic en "Registrar Usuario". El usuario recibirá un correo con sus credenciales de acceso.'
    ],
    [
        'id' => 6, 'categoria' => 'usuarios',
        'pregunta' => '¿Cómo cambio el rol de un usuario?',
        'respuesta' => 'Ve a <strong>Usuarios</strong>, busca el usuario y haz clic en "Editar". Desde el campo "Rol" podrás asignarle administrador, profesor o estudiante. Guarda los cambios para que tomen efecto.'
    ],
    // Cursos
    [
        'id' => 7, 'categoria' => 'cursos',
        'pregunta' => '¿Cómo creo un nuevo curso?',
        'respuesta' => 'Ve a <strong>Cursos > Nuevo Curso</strong>. Completa el nombre, descripción, nivel, duración, cupo máximo y precio mensual. Puedes subir una imagen representativa. Al guardar, el curso quedará disponible para asignar grupos.'
    ],
    [
        'id' => 8, 'categoria' => 'cursos',
        'pregunta' => '¿Cómo asigno un profesor a un curso?',
        'respuesta' => 'En <strong>Cursos > Gestionar Cursos</strong>, selecciona el curso deseado y haz clic en "Editar". En la sección "Asignación de Profesores" podrás seleccionar al profesor del listado disponible.'
    ],
    // Horarios
    [
        'id' => 9, 'categoria' => 'horarios',
        'pregunta' => '¿Cómo configuro el horario de un grupo?',
        'respuesta' => 'En <strong>Horarios</strong> puedes asignar días y horas a cada grupo. Selecciona el grupo, elige los días de la semana y el rango horario. El sistema detectará conflictos de horario automáticamente.'
    ],
    [
        'id' => 10, 'categoria' => 'horarios',
        'pregunta' => '¿Puedo ver todos los horarios en una sola vista?',
        'respuesta' => 'Sí. En <strong>Horarios > Vista General</strong> encontrarás un calendario con todos los grupos activos organizados por día y hora, con filtros por profesor o curso.'
    ],
    // Inscripciones
    [
        'id' => 11, 'categoria' => 'inscripciones',
        'pregunta' => '¿Cómo gestiono las prematrículas pendientes?',
        'respuesta' => 'Accede a <strong>Inscripciones > Prematrículas</strong> donde verás todas las solicitudes pendientes. Puedes revisar los documentos adjuntos y aprobar o rechazar cada prematrícula con un comentario.'
    ],
    [
        'id' => 12, 'categoria' => 'inscripciones',
        'pregunta' => '¿Cómo matriculo manualmente a un estudiante?',
        'respuesta' => 'Ve a <strong>Inscripciones > Nueva Matrícula</strong>. Selecciona el estudiante, el curso y el grupo disponible. Confirma el cupo y el sistema registrará la matrícula de forma inmediata.'
    ],
    // Reportes
    [
        'id' => 13, 'categoria' => 'reportes',
        'pregunta' => '¿Qué tipos de reportes puedo generar?',
        'respuesta' => 'El sistema permite generar reportes de <strong>estudiantes matriculados</strong>, <strong>ingresos por curso</strong>, <strong>asistencia</strong> y <strong>ocupación de grupos</strong>. Todos exportables en PDF o Excel.'
    ],
    [
        'id' => 14, 'categoria' => 'reportes',
        'pregunta' => '¿Cómo exporto un reporte a PDF?',
        'respuesta' => 'En <strong>Reportes</strong>, configura los filtros del reporte que necesitas y haz clic en el botón <strong>"Exportar PDF"</strong> en la parte superior derecha. El archivo se descargará automáticamente.'
    ],
];

// Categorías de ayuda
$categorias = [
    ['id' => 'todos', 'nombre' => 'Todas', 'icono' => 'apps'],
    ['id' => 'general', 'nombre' => 'General', 'icono' => 'info'],
    ['id' => 'mi-cuenta', 'nombre' => 'Mi Cuenta', 'icono' => 'account_circle'],
    ['id' => 'usuarios', 'nombre' => 'Usuarios', 'icono' => 'group'],
    ['id' => 'cursos', 'nombre' => 'Cursos', 'icono' => 'school'],
    ['id' => 'horarios', 'nombre' => 'Horarios', 'icono' => 'schedule'],
    ['id' => 'inscripciones', 'nombre' => 'Inscripciones', 'icono' => 'description'],
    ['id' => 'reportes', 'nombre' => 'Reportes', 'icono' => 'assessment']
];
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
                <p>Recursos y guías personalizados para Administrador</p>
            </div>
            <div class="help-header-actions">
                <button class="btn-secondary" onclick="window.location.href='../dashboard/'">
                    <span class="material-symbols-rounded">arrow_back</span>
                    Volver al Dashboard
                </button>
                <button class="btn-primary" onclick="window.location.href='contenido_administrador.php'">
                    <span class="material-symbols-rounded">book</span>
                    Contenido para Administrador
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
                <div class="guide-card">
                    <div class="guide-icon usuarios">
                        <span class="material-symbols-rounded">group</span>
                    </div>
                    <h3 class="guide-title">Gestión de Usuarios</h3>
                    <p class="guide-description">Guía completa para administrar usuarios, roles y permisos</p>
                    <button class="btn-guide" data-guide="usuarios">
                        Ver guía
                        <span class="material-symbols-rounded">arrow_forward</span>
                    </button>
                </div>

                <div class="guide-card">
                    <div class="guide-icon cursos">
                        <span class="material-symbols-rounded">school</span>
                    </div>
                    <h3 class="guide-title">Configuración de Cursos</h3>
                    <p class="guide-description">Aprende a crear y gestionar cursos, grupos y horarios</p>
                    <button class="btn-guide" data-guide="cursos">
                        Ver guía
                        <span class="material-symbols-rounded">arrow_forward</span>
                    </button>
                </div>

                <div class="guide-card">
                    <div class="guide-icon inscripciones">
                        <span class="material-symbols-rounded">description</span>
                    </div>
                    <h3 class="guide-title">Proceso de Inscripciones</h3>
                    <p class="guide-description">Gestión de prematrículas y matrículas paso a paso</p>
                    <button class="btn-guide" data-guide="inscripciones">
                        Ver guía
                        <span class="material-symbols-rounded">arrow_forward</span>
                    </button>
                </div>

                <div class="guide-card">
                    <div class="guide-icon reportes">
                        <span class="material-symbols-rounded">assessment</span>
                    </div>
                    <h3 class="guide-title">Generación de Reportes</h3>
                    <p class="guide-description">Cómo generar y exportar reportes del sistema</p>
                    <button class="btn-guide" data-guide="reportes">
                        Ver guía
                        <span class="material-symbols-rounded">arrow_forward</span>
                    </button>
                </div>
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
                <button class="btn-contact">
                    <span class="material-symbols-rounded">email</span>
                    Contactar soporte
                </button>
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
            }
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