<?php
$cfg    = [];
$galeria = [];
try {
    require_once '../config/database.php';
    foreach ($pdo->query("SELECT clave, valor FROM configuracion_pagina")->fetchAll() as $r) {
        $cfg[$r['clave']] = $r['valor'];
    }
    $galeria = $pdo->query(
        "SELECT * FROM galeria_pagina WHERE activo = 1 ORDER BY orden ASC, id ASC"
    )->fetchAll();
} catch (Throwable $e) {}

function pc(string $key, string $fallback = ''): string {
    global $cfg;
    return htmlspecialchars($cfg[$key] ?? $fallback, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to Amimbré!</title>
    <link rel="shortcut icon" href="img/3.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="../assets/css/style-index.css">
    <link rel="stylesheet" href="../assets/css/colores.css">
    <link rel="shortcut icon" href="../assets/img/3.png">
    <link rel="stylesheet" href="../assets/css/style-modales-index.css">
</head>
<body>
    <!-- Header -->
    <header>
        <div class="logo-section">
            <img src="../assets/img/1.jpg" alt="BanaExport Logo">
            <h1>Amimbré</h1>
        </div>

        <nav class="nav-links">
            <li><a href="#inicio">Inicio</a></li>
            <li><a href="#sobre-nosotros">Sobre Nosotros</a></li>
            <li><a href="#disciplinas">Disciplinas</a></li>
            <li><a href="#galeria">Galería</a></li>
            <li><a href="#contacto">Contacto</a></li>
        </nav>

        <div class="header-actions">
            <div class="theme-toggle"></div>
            <a href="../auth/login.php" class="login-btn">Iniciar Sesión</a>
        </div>

        <button class="hamburger-menu" aria-label="Toggle menu">
            <span></span>
            <span></span>
            <span></span>
        </button>
    </header>

    <!-- Mobile Menu Overlay -->
    <div class="mobile-menu-overlay"></div>

    <!-- Mobile Sidebar Menu -->
    <div class="mobile-sidebar">
        <div class="mobile-sidebar-header">
            <div class="logo-section">
                <img src="../assets/img/1.jpg" alt="Amimbré Logo">
                <h2>Amimbré</h2>
            </div>
            <button class="close-menu" aria-label="Close menu">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="mobile-sidebar-content">
            <div class="mobile-user-info">
                <div class="user-avatar">
                    <i class="fas fa-user"></i>
                </div>
                <p class="user-name">Visitante</p>
            </div>

            <nav class="mobile-nav">
                <a href="#inicio" class="mobile-nav-item">
                    <i class="fas fa-home"></i>
                    <span>Inicio</span>
                </a>
                <a href="#sobre-nosotros" class="mobile-nav-item">
                    <i class="fas fa-info-circle"></i>
                    <span>Sobre Nosotros</span>
                </a>
                <a href="#disciplinas" class="mobile-nav-item">
                    <i class="fas fa-music"></i>
                    <span>Disciplinas</span>
                </a>
                <a href="#galeria" class="mobile-nav-item">
                    <i class="fas fa-images"></i>
                    <span>Galería</span>
                </a>
                <a href="#contacto" class="mobile-nav-item">
                    <i class="fas fa-envelope"></i>
                    <span>Contacto</span>
                </a>
            </nav>

            <div class="mobile-theme-section">
                <span class="mobile-theme-label">
                    <i class="fas fa-circle-half-stroke"></i>
                    Cambiar Tema
                </span>
                <div class="theme-toggle" id="mobile-theme-toggle"></div>
            </div>

            <div class="mobile-sidebar-footer">
                <a href="../auth/login.php" class="mobile-login-btn">
                    <i class="fas fa-sign-in-alt"></i>
                    <span>Iniciar Sesión</span>
                </a>
            </div>
        </div>
    </div>

    <section class="hero" id="inicio">
        <div class="hero-content">
            <h1>Formación Artística y Cultural<br>
                <span class="highlight">al Ritmo de Amimbré</span>
            </h1>
            <p>
                En Amimbré, la creatividad se convierte en talento. Formamos artistas en música, danza, teatro, artes plásticas, audiovisuales y literatura, inspirando expresión y amor por la cultura.
            </p>
            <div class="hero-buttons">
                <a href="../auth/login.php" class="cta-button">
                    Acceder al Sistema
                </a>
                <a href="pre-inscripcion.php" class="cta-button">
                    Prematriculate
                </a>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section class="features" id="sobre-nosotros">
        <div class="section-header">
            <h2>Sobre Amimbré</h2>
            <p><?= nl2br(pc('sobre_nosotros',
                'Con años de experiencia impulsando el arte y la cultura, Amimbré se ha consolidado como una institución comprometida con la formación artística integral en disciplinas como la música, la danza, el teatro, las artes plásticas, los audiovisuales y la literatura.' . "\n\n" .
                'Nuestro compromiso con la creatividad, la calidad y la innovación pedagógica nos ha permitido formar generaciones de artistas que expresan su talento y transforman su entorno a través del arte.' . "\n\n" .
                'Trabajamos de la mano con docentes y creadores locales, promoviendo espacios de aprendizaje sostenibles, inclusivos y llenos de inspiración, donde cada estudiante puede desarrollar su esencia artística y compartirla con el mundo.'
            )) ?></p>
        </div>

        <div class="features-grid">
            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-bullseye"></i>
                </div>
                <h3>Misión</h3>
                <p><?= pc('mision', 'Formar artistas integrales mediante procesos pedagógicos y creativos que fortalezcan la sensibilidad, la expresión y el amor por la cultura, impulsando el desarrollo humano a través del arte.') ?></p>
            </div>

            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fa-solid fa-globe"></i>
                </div>
                <h3>Visión</h3>
                <p><?= pc('vision', 'Ser una institución líder en formación artística reconocida por su excelencia, innovación y compromiso con el crecimiento cultural de la comunidad.') ?></p>
            </div>

            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fa-solid fa-users-line"></i>
                </div>
                <h3>Valores</h3>
                <p><?= pc('valores', 'Disciplina, creatividad, respeto y trabajo comunitario. Fomentamos el crecimiento artístico y humano a través de la música, promoviendo la sensibilidad, la cooperación y el compromiso con la cultura local.') ?></p>
            </div>
        </div>
    </section>

    <!-- Capabilities Section -->
    <section class="capabilities" id="disciplinas">
        <h2>Nuestras Disciplinas</h2>
        <p>Explora las diferentes áreas artísticas en las que puedes desarrollar tu talento</p>

        <div class="capabilities-container">
            <div class="capability-box">
                <i class="fa-solid fa-children"></i>
                <h4>Estimulación Musical</h4>
                <p>Programa especial para niñas y niños de 3 a 5 años de edad.</p>
            </div>

            <div class="capability-box">
                <i class="fa-solid fa-music"></i>
                <h4>Iniciación Musical</h4>
                <p>Programa para niñas y niños desde los 6 años de edad.</p>
            </div>

            <div class="capability-box">
                <span class="material-symbols-outlined">air</span>
                <h4>Clarinete y Saxofón</h4>
                <p>Formación técnica y expresiva en estos instrumentos de viento madera.</p>
            </div>

            <div class="capability-box">
                <span class="material-symbols-outlined">air</span>
                <h4>Flauta Traversa</h4>
                <p>Técnica y repertorio para uno de los instrumentos de viento más versátiles.</p>
            </div>

            <div class="capability-box">
                <span class="material-symbols-outlined">air</span>
                <h4>Trompeta</h4>
                <p>Aprendizaje progresivo de este instrumento de viento metal.</p>
            </div>

            <div class="capability-box">
                <i class="fa-solid fa-guitar"></i>
                <h4>Guitarra</h4>
                <p>Formación técnica y expresiva en repertorio clásico y popular.</p>
            </div>

            <div class="capability-box">
                <i class="fa-solid fa-guitar"></i>
                <h4>Bandola y Tiple</h4>
                <p>Instrumentos tradicionales colombianos con arraigo en nuestra cultura.</p>
            </div>

            <div class="capability-box">
                <i class="fa-solid fa-guitar"></i>
                <h4>Ukelele</h4>
                <p>Un instrumento versátil, divertido y accesible para todas las edades.</p>
            </div>

            <div class="capability-box">
                <span class="material-symbols-outlined">piano</span>
                <h4>Piano</h4>
                <p>Aprendizaje progresivo desde fundamentos hasta interpretación avanzada.</p>
            </div>

            <div class="capability-box">
                <i class="fa-solid fa-guitar"></i>
                <h4>Viola y Violín</h4>
                <p>Formación en instrumentos de cuerda frotada con técnica clásica.</p>
            </div>

            <div class="capability-box">
                <span class="material-symbols-outlined">artist</span>
                <h4>Técnica Vocal</h4>
                <p>Desarrollo de una voz sana, afinada y expresiva para diferentes géneros.</p>
            </div>

            <div class="capability-box">
                <span class="material-symbols-outlined">music_note</span>
                <h4>Batería</h4>
                <p>Formación rítmica y técnica en uno de los instrumentos más dinámicos.</p>
            </div>

            <div class="capability-box">
                <span class="material-symbols-outlined">music_note</span>
                <h4>Percusión Folclórica</h4>
                <p>Exploración de ritmos y tradiciones percusivas de nuestra región.</p>
            </div>

            <div class="capability-box">
                <span class="material-symbols-outlined">diversity_3</span>
                <h4>Danza</h4>
                <p>Clases de danza Tradicional, Contemporánea, Baile Popular, Salsa, Merengue y Porro.</p>
            </div>

            <div class="capability-box">
                <span class="material-symbols-outlined">theater_comedy</span>
                <h4>Teatro</h4>
                <p>Expresión corporal, voz y escena para desarrollar creatividad y confianza.</p>
            </div>

            <div class="capability-box">
                <span class="material-symbols-outlined">palette</span>
                <h4>Artes Plásticas</h4>
                <p>Exploración visual y técnica a través del dibujo, la pintura y el color.</p>
            </div>

            <div class="capability-box">
                <span class="material-symbols-outlined">category</span>
                <h4>Cerámica</h4>
                <p>Modelado y creación artística con arcilla para todas las edades.</p>
            </div>
        </div>
    </section>

    <!-- Galería Masonry -->
    <section class="gallery" id="galeria">
        <div class="gallery-header">
            <h2>Galería</h2>
            <p>Explora nuestra colección de actividades y momentos destacados</p>
        </div>

        <div class="masonry-grid">
            <?php if (!empty($galeria)): ?>
                <?php foreach ($galeria as $img): ?>
                <div class="masonry-item">
                    <img src="../assets/uploads/galeria/<?= htmlspecialchars($img['nombre_archivo']) ?>"
                         alt="<?= htmlspecialchars($img['descripcion'] ?? 'Galería') ?>">
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="masonry-item">
                    <img src="../assets/img/G1.jpg" alt="Galería imagen 1">
                </div>
                <div class="masonry-item">
                    <img src="../assets/img/G2.jpg" alt="Galería imagen 2">
                </div>
                <div class="masonry-item">
                    <img src="../assets/img/G3.jpg" alt="Galería imagen 3">
                </div>
                <div class="masonry-item">
                    <img src="../assets/img/G4.jpg" alt="Galería imagen 4">
                </div>
                <div class="masonry-item">
                    <img src="../assets/img/G5.jpg" alt="Galería imagen 5">
                </div>
                <div class="masonry-item">
                    <img src="../assets/img/G6.jpg" alt="Galería imagen 6">
                </div>
                <div class="masonry-item">
                    <img src="../assets/img/G7.jpg" alt="Galería imagen 7">
                </div>
                <div class="masonry-item">
                    <img src="../assets/img/G8.jpg" alt="Galería imagen 8">
                </div>
                <div class="masonry-item">
                    <img src="../assets/img/G9.jpg" alt="Galería imagen 9">
                </div>
                <div class="masonry-item">
                    <img src="../assets/img/G10.jpg" alt="Galería imagen 10">
                </div>
                <div class="masonry-item">
                    <img src="../assets/img/G11.jpg" alt="Galería imagen 11">
                </div>
                <div class="masonry-item">
                    <img src="../assets/img/G12.jpg" alt="Galería imagen 12">
                </div>
                <div class="masonry-item">
                    <img src="../assets/img/G13.jpg" alt="Galería imagen 13">
                </div>
                <div class="masonry-item">
                    <img src="../assets/img/G14.jpg" alt="Galería imagen 14">
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="contact" id="contacto">
        <div class="contact-header">
            <h2>Contáctanos</h2>
            <p>¿Tienes preguntas? Estamos aquí para ayudarte. Escríbenos mediante uno de nuestros medios de contacto y te responderemos lo antes posible.</p>
        </div>
        <div class="contacts">
            <div class="contacts-cards">
                <div class="card-cont" id="direccion">
                    <div class="ic"><span class="material-symbols-outlined">location_on</span></div>
                    <div class="contenido">
                        <h4>Dirección</h4>
                        <p><?= pc('contacto_direccion', 'Carrera 33 N° 35-37, Sector la Alhambra — El Carmen de Viboral') ?></p>
                    </div>
                </div>
                <div class="card-cont" id="telefono">
                    <div class="ic"><span class="material-symbols-outlined">call</span></div>
                    <div class="contenido">
                        <h4>Teléfono</h4>
                        <p><?= pc('contacto_telefono', '312 286 72 97') ?></p>
                    </div>
                </div>
                <div class="card-cont" id="email">
                    <div class="ic"><span class="material-symbols-outlined">mail</span></div>
                    <div class="contenido">
                        <h4>Email</h4>
                        <p><?= pc('contacto_email', 'escuelademusicaamimbre@gmail.com') ?></p>
                    </div>
                </div>
                <div class="card-cont" id="horario">
                    <div class="ic"><span class="material-symbols-outlined">schedule</span></div>
                    <div class="contenido">
                        <h4>Horario de Atención</h4>
                        <p><?= pc('contacto_horario', 'Lunes - Sábado: 7:00 a 5:00') ?></p>
                    </div>
                </div>
            </div>
            <div class="contacts-maps">
                <img
                    src="../assets/img/mapa-amimbre.jpg"
                    alt="Ubicación Escuela de Música Amimbré"
                    class="map-fallback"
                    id="mapFallback"
                >
                <iframe
                    id="contactMap"
                    src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d2358.9853264072804!2d-75.33740089772753!3d6.086895923133506!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x8e46a31fbc405185%3A0x80d6716251316e32!2sEscuela%20de%20m%C3%BAsica%20Amimbr%C3%A9!5e0!3m2!1sen!2sco!4v1770219115929!5m2!1sen!2sco"
                    height="450"
                    style="border:0;width:100%;"
                    allowfullscreen=""
                    loading="lazy"
                    referrerpolicy="no-referrer-when-downgrade"
                ></iframe>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer>
        <div class="footer-container">
            <div class="footer-section">
                <h4>Amimbré</h4>
                <p>
                    Escuela de Música en el Carmen de Viboral - Antioquia
                </p>
            </div>

            <div class="footer-section">
                <h4>Legal</h4>
                <a href="#">Política de Privacidad</a>
                <a href="#">Términos y Condiciones</a>
                <a href="#">Protección de Datos</a>
            </div>

            <div class="footer-section">
                <h4>Soporte</h4>
                <a href="#">Centro de Ayuda</a>
                <a href="#">Capacitación</a>
                <a href="#">Manual de Usuario</a>
            </div>
        </div>

        <div class="footer-bottom">
            © 2025 Amimbré Todos los derechos reservados.<br>
            Cumple con Ley 1581/2012, Ley 594/2000 y normativas DIAN
        </div>
    </footer>

    <script src="../assets/js/script-menuidx.js"></script>
    <script src="../assets/js/script-modales-index.js"></script>
</body>
</html>
