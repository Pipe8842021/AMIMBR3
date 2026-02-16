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

// Preguntas frecuentes
$preguntas_frecuentes = [
    [
        'id' => 1,
        'pregunta' => '¿Cómo puedo cambiar mi contraseña?',
        'respuesta' => 'Para cambiar tu contraseña, ve a <strong>Configuración > Mi Perfil > Seguridad</strong> y haz clic en "Cambiar contraseña". Deberás ingresar tu contraseña actual y luego la nueva contraseña dos veces para confirmar.'
    ],
    [
        'id' => 2,
        'pregunta' => '¿Cómo actualizo mi información personal?',
        'respuesta' => 'Dirígete a <strong>Configuración > Mi Perfil</strong>, donde podrás editar tu nombre, correo electrónico, foto de perfil y otra información relevante. No olvides hacer clic en "Guardar cambios" al terminar.'
    ],
    [
        'id' => 3,
        'pregunta' => '¿Cómo creo un nuevo usuario en el sistema?',
        'respuesta' => 'Ve a <strong>Usuarios > Crear Usuario</strong>. Completa el formulario con los datos requeridos (nombre, correo, rol, etc.) y haz clic en "Registrar Usuario". El usuario recibirá un correo con sus credenciales de acceso.'
    ],
    [
        'id' => 4,
        'pregunta' => '¿Cómo asigno un profesor a un curso?',
        'respuesta' => 'En <strong>Cursos > Gestionar Cursos</strong>, selecciona el curso deseado y haz clic en "Editar". En la sección "Asignación de Profesores" podrás seleccionar al profesor del listado disponible.'
    ],
    [
        'id' => 5,
        'pregunta' => '¿Cómo gestiono las prematrículas pendientes?',
        'respuesta' => 'Accede a <strong>Inscripciones > Prematrículas</strong> donde verás todas las solicitudes pendientes. Puedes revisar los documentos adjuntos y aprobar o rechazar cada prematrícula con un comentario.'
    ]
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

            <div class="faq-container">
                <?php foreach ($preguntas_frecuentes as $faq): ?>
                <div class="faq-item" data-faq-id="<?php echo $faq['id']; ?>">
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
                    <button class="btn-guide">
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
                    <button class="btn-guide">
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
                    <button class="btn-guide">
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
                    <button class="btn-guide">
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

    <script src="../../assets/js/ayuda.js"></script>
</body>
</html>