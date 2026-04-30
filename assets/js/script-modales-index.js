/* =============================================
   script-modals.js — Amimbré
   Modales de disciplinas + Lightbox de galería
   Incluir DESPUÉS de script-menuidx.js en index.html:
   <script src="../assets/js/script-modals.js"></script>
   ============================================= */

(function () {
    'use strict';

    /* ------------------------------------------
       DATOS DE DISCIPLINAS
       Personaliza las descripciones, imágenes,
       info y tags de cada disciplina aquí.
    ------------------------------------------ */
    const disciplines = [
        {
            key: 'estimulacion',
            title: 'Estimulación Musical',
            subtitle: 'Programa Infantil Temprano',
            accent: 'var(--primary-blue)',
            icon: '<i class="fa-solid fa-children"></i>',
            image: '../assets/img/disiplinas/Estimulacion-musical.jpg',
            description: 'Nuestro programa de Estimulación Musical está diseñado especialmente para niñas y niños de 3 a 5 años. A través del juego, la exploración sonora, el movimiento y el canto, potenciamos el desarrollo cognitivo, emocional y sensorial en las etapas más importantes del crecimiento. Cada sesión es un viaje lleno de colores, ritmos y alegría.',
            info: [
                { label: 'Edad', value: '3 a 5 años' },
                { label: 'Intensidad', value: '1 vez por semana' },
                { label: 'Duración', value: '45 min / sesión' },
                { label: 'Modalidad', value: 'Grupal' }
            ],
            tags: ['Juego musical', 'Ritmo', 'Canto', 'Movimiento', 'Desarrollo cognitivo', 'Sensorial']
        },
        {
            key: 'iniciacion',
            title: 'Iniciación Musical',
            subtitle: 'Fundamentos del Arte Musical',
            accent: 'var(--primary-green)',
            icon: '<i class="fa-solid fa-music"></i>',
            image: '../assets/img/disiplinas/Iniciacion-musical.jpg',
            description: 'El programa de Iniciación Musical brinda a niñas y niños desde los 6 años los fundamentos teóricos y prácticos de la música. Aprenden lectura musical, ritmo, entonación y tienen su primer acercamiento a instrumentos. Es la puerta de entrada al mundo artístico de Amimbré.',
            info: [
                { label: 'Edad', value: 'Desde 6 años' },
                { label: 'Intensidad', value: '2 veces por semana' },
                { label: 'Duración', value: '1 hora / sesión' },
                { label: 'Modalidad', value: 'Grupal' }
            ],
            tags: ['Lectura musical', 'Solfeo', 'Ritmo', 'Teoría', 'Instrumentos', 'Canto']
        },
        {
            key: 'clarinete',
            title: 'Clarinete y Saxofón',
            subtitle: 'Viento Madera',
            accent: 'var(--primary-orange)',
            icon: '<span class="material-symbols-outlined">air</span>',
            image: '../assets/img/disiplinas/Clarinete-saxofon.jpg',
            description: 'Domina la técnica y expresividad del clarinete y el saxofón, dos de los instrumentos de viento madera más versátiles de la música occidental y del jazz. Nuestros docentes guían al estudiante desde el control de la embocadura hasta la interpretación de repertorios variados en géneros como clásico, jazz y música popular.',
            info: [
                { label: 'Nivel', value: 'Básico a Avanzado' },
                { label: 'Intensidad', value: '2 veces por semana' },
                { label: 'Duración', value: '1 hora / sesión' },
                { label: 'Modalidad', value: 'Individual o grupal' }
            ],
            tags: ['Técnica de embocadura', 'Jazz', 'Clásico', 'Lectura musical', 'Repertorio', 'Improvisación']
        },
        {
            key: 'flauta',
            title: 'Flauta Traversa',
            subtitle: 'Viento Madera',
            accent: 'var(--primary-blue)',
            icon: '<span class="material-symbols-outlined">air</span>',
            image: '../assets/img/disiplinas/Flauta-traversa.jpg',
            description: 'La flauta traversa es uno de los instrumentos de viento más antiguos y elegantes. En Amimbré aprendes la postura correcta, técnica de respiración diafragmática, digitación y articulación, para luego interpretar piezas del repertorio clásico, contemporáneo y de música tradicional colombiana.',
            info: [
                { label: 'Nivel', value: 'Básico a Avanzado' },
                { label: 'Intensidad', value: '2 veces por semana' },
                { label: 'Duración', value: '1 hora / sesión' },
                { label: 'Modalidad', value: 'Individual' }
            ],
            tags: ['Respiración', 'Técnica', 'Clásico', 'Música colombiana', 'Digitación', 'Articulación']
        },
        {
            key: 'trompeta',
            title: 'Trompeta',
            subtitle: 'Viento Metal',
            accent: 'var(--primary-yellow)',
            icon: '<span class="material-symbols-outlined">air</span>',
            image: '../assets/img/disiplinas/Trompeta.jpg',
            description: 'La trompeta es el rey de los instrumentos de viento metal. Con un aprendizaje progresivo y estructurado, nuestros estudiantes desarrollan una embocadura sólida, control de registro y musicalidad para interpretar desde fanfarrias y música clásica hasta jazz y música popular colombiana.',
            info: [
                { label: 'Nivel', value: 'Básico a Avanzado' },
                { label: 'Intensidad', value: '2 veces por semana' },
                { label: 'Duración', value: '1 hora / sesión' },
                { label: 'Modalidad', value: 'Individual o grupal' }
            ],
            tags: ['Embocadura', 'Registro', 'Jazz', 'Banda', 'Lectura musical', 'Musicalidad']
        },
        {
            key: 'guitarra',
            title: 'Guitarra',
            subtitle: 'Cuerda Pulsada',
            accent: 'var(--primary-green)',
            icon: '<i class="fa-solid fa-guitar"></i>',
            image: '../assets/img/disiplinas/Guitarra.jpg',
            description: 'La guitarra es uno de los instrumentos más populares y versátiles del mundo. En Amimbré desarrollamos la técnica clásica y popular, el rasgueo, el punteo y el acompañamiento armónico en géneros como bolero, rock, vallenato, currulao y música andina colombiana.',
            info: [
                { label: 'Nivel', value: 'Básico a Avanzado' },
                { label: 'Intensidad', value: '2 veces por semana' },
                { label: 'Duración', value: '1 hora / sesión' },
                { label: 'Modalidad', value: 'Individual o grupal' }
            ],
            tags: ['Clásica', 'Popular', 'Rasgueo', 'Punteo', 'Armonía', 'Música andina']
        },
        {
            key: 'bandola',
            title: 'Bandola y Tiple',
            subtitle: 'Instrumentos Tradicionales Colombianos',
            accent: 'var(--primary-orange)',
            icon: '<i class="fa-solid fa-guitar"></i>',
            image: '../assets/img/disiplinas/Bandola-tiple.jpg',
            description: 'La bandola y el tiple son pilares del patrimonio musical colombiano. Junto a la guitarra forman el tradicional trío andino. En Amimbré preservamos y difundimos este legado cultural, enseñando técnica, repertorio tradicional y la identidad sonora de nuestra región.',
            info: [
                { label: 'Nivel', value: 'Básico a Avanzado' },
                { label: 'Intensidad', value: '2 veces por semana' },
                { label: 'Duración', value: '1 hora / sesión' },
                { label: 'Modalidad', value: 'Individual o grupal' }
            ],
            tags: ['Música andina', 'Trío', 'Patrimonio', 'Bambuco', 'Pasillo', 'Identidad cultural']
        },
        {
            key: 'ukelele',
            title: 'Ukelele',
            subtitle: 'Cuerda Pulsada',
            accent: 'var(--primary-blue)',
            icon: '<i class="fa-solid fa-guitar"></i>',
            image: '../assets/img/disiplinas/Ukelele.jpg',
            description: 'El ukelele es divertido, accesible y perfecto para todas las edades. Con su sonido tropical y alegre, es ideal para aprender los fundamentos del ritmo, la armonía y el acompañamiento. Se trabaja repertorio pop, folk y canciones tradicionales latinoamericanas.',
            info: [
                { label: 'Edad', value: 'Todas las edades' },
                { label: 'Intensidad', value: '1-2 veces por semana' },
                { label: 'Duración', value: '45 min - 1 hora' },
                { label: 'Modalidad', value: 'Individual o grupal' }
            ],
            tags: ['Todas las edades', 'Pop', 'Folk', 'Armonía', 'Acompañamiento', 'Divertido']
        },
        {
            key: 'piano',
            title: 'Piano',
            subtitle: 'Teclado',
            accent: 'var(--primary-yellow)',
            icon: '<span class="material-symbols-outlined">piano</span>',
            image: '../assets/img/disiplinas/Piano.jpg',
            description: 'El piano es el instrumento más completo de la música occidental. En Amimbré desarrollamos técnica pianística, lectura a dos claves, independencia de manos, pedagogía progresiva desde los primeros acordes hasta interpretación avanzada de obras clásicas y contemporáneas.',
            info: [
                { label: 'Nivel', value: 'Básico a Avanzado' },
                { label: 'Intensidad', value: '2 veces por semana' },
                { label: 'Duración', value: '1 hora / sesión' },
                { label: 'Modalidad', value: 'Individual' }
            ],
            tags: ['Técnica', 'Lectura bimanual', 'Clásico', 'Contemporáneo', 'Teoría', 'Armonía']
        },
        {
            key: 'viola-violin',
            title: 'Viola y Violín',
            subtitle: 'Cuerda Frotada',
            accent: 'var(--primary-green)',
            icon: '<i class="fa-solid fa-guitar"></i>',
            image: '../assets/img/disiplinas/Violin.jpg',
            description: 'El violín y la viola son instrumentos de cuerda frotada con una profundidad expresiva incomparable. Nuestro programa trabaja la postura, afinación, golpe de arco, técnica de la mano izquierda y repertorio del mundo clásico, folclórico y contemporáneo.',
            info: [
                { label: 'Nivel', value: 'Básico a Avanzado' },
                { label: 'Intensidad', value: '2 veces por semana' },
                { label: 'Duración', value: '1 hora / sesión' },
                { label: 'Modalidad', value: 'Individual' }
            ],
            tags: ['Arco', 'Afinación', 'Clásico', 'Orquesta', 'Postura', 'Técnica']
        },
        {
            key: 'vocal',
            title: 'Técnica Vocal',
            subtitle: 'Canto y Expresión',
            accent: 'var(--primary-orange)',
            icon: '<span class="material-symbols-outlined">artist</span>',
            image: '../assets/img/disiplinas/Tecnica-vocal.jpg',
            description: 'La voz es el instrumento más natural y personal. Nuestro programa de técnica vocal desarrolla la respiración diafragmática, la resonancia, la proyección, la afinación y el fraseo musical en géneros como clásico, popular, lírica y música latinoamericana.',
            info: [
                { label: 'Nivel', value: 'Básico a Avanzado' },
                { label: 'Intensidad', value: '2 veces por semana' },
                { label: 'Duración', value: '1 hora / sesión' },
                { label: 'Modalidad', value: 'Individual' }
            ],
            tags: ['Respiración', 'Resonancia', 'Afinación', 'Lírica', 'Popular', 'Fraseo']
        },
        {
            key: 'bateria',
            title: 'Batería',
            subtitle: 'Percusión Moderna',
            accent: 'var(--primary-blue)',
            icon: '<span class="material-symbols-outlined">music_note</span>',
            image: '../assets/img/disiplinas/Bateria.jpg',
            description: 'La batería es el corazón rítmico de cualquier agrupación musical. Aprende coordinación de las cuatro extremidades, lectura de partituras rítmicas, diferentes géneros (rock, pop, jazz, salsa, cumbia) y el arte de tocar en conjunto con otros músicos.',
            info: [
                { label: 'Nivel', value: 'Básico a Avanzado' },
                { label: 'Intensidad', value: '2 veces por semana' },
                { label: 'Duración', value: '1 hora / sesión' },
                { label: 'Modalidad', value: 'Individual' }
            ],
            tags: ['Coordinación', 'Rock', 'Jazz', 'Salsa', 'Cumbia', 'Lectura rítmica']
        },
        {
            key: 'percusion',
            title: 'Percusión Folclórica',
            subtitle: 'Ritmos y Tradiciones',
            accent: 'var(--primary-yellow)',
            icon: '<span class="material-symbols-outlined">music_note</span>',
            image: '../assets/img/disiplinas/Percusion-folclorica.jpg',
            description: 'Explora la riqueza rítmica del folclor colombiano y latinoamericano a través de instrumentos como la tambora, el cajón, el chucho, la pandereta y el bongó. Una disciplina que celebra nuestra identidad cultural y conecta al estudiante con las raíces musicales de la región.',
            info: [
                { label: 'Nivel', value: 'Básico a Avanzado' },
                { label: 'Intensidad', value: '2 veces por semana' },
                { label: 'Duración', value: '1 hora / sesión' },
                { label: 'Modalidad', value: 'Grupal' }
            ],
            tags: ['Folclor', 'Tambora', 'Cajón', 'Currulao', 'Identidad', 'Latinoamericano']
        },
        {
            key: 'danza',
            title: 'Danza',
            subtitle: 'Movimiento y Expresión Corporal',
            accent: 'var(--primary-green)',
            icon: '<span class="material-symbols-outlined">diversity_3</span>',
            image: '../assets/img/disiplinas/Danza.jpg',
            description: 'El programa de Danza abarca múltiples estilos y tradiciones: Danza Tradicional Colombiana, Contemporánea, Baile Popular, Salsa, Merengue y Porro. Desarrollamos la expresión corporal, el ritmo, la coordinación y la sensibilidad artística en un ambiente creativo e inclusivo.',
            info: [
                { label: 'Estilos', value: 'Tradicional, Salsa, Contemporánea' },
                { label: 'Intensidad', value: '2-3 veces por semana' },
                { label: 'Duración', value: '1-1.5 horas / sesión' },
                { label: 'Modalidad', value: 'Grupal' }
            ],
            tags: ['Salsa', 'Merengue', 'Porro', 'Contemporánea', 'Folclórica', 'Expresión corporal']
        },
        {
            key: 'teatro',
            title: 'Teatro',
            subtitle: 'Arte Escénico',
            accent: 'var(--primary-orange)',
            icon: '<span class="material-symbols-outlined">theater_comedy</span>',
            image: '../assets/img/disiplinas/Teatro.jpg',
            description: 'El teatro transforma y libera. En Amimbré trabajamos la expresión corporal, el manejo de voz, la improvisación, el trabajo en equipo y la construcción de personajes. Formamos artistas escénicos con confianza, creatividad y sensibilidad para comunicar historias que mueven al público.',
            info: [
                { label: 'Nivel', value: 'Básico a Avanzado' },
                { label: 'Intensidad', value: '2 veces por semana' },
                { label: 'Duración', value: '1.5 horas / sesión' },
                { label: 'Modalidad', value: 'Grupal' }
            ],
            tags: ['Improvisación', 'Expresión corporal', 'Voz', 'Personajes', 'Escena', 'Creatividad']
        },
        {
            key: 'artes-plasticas',
            title: 'Artes Plásticas',
            subtitle: 'Artes Visuales',
            accent: 'var(--primary-blue)',
            icon: '<span class="material-symbols-outlined">palette</span>',
            image: '../assets/img/disiplinas/Artes-plasticas.jpg',
            description: 'Las artes plásticas despiertan la mirada creativa del mundo. Exploramos el dibujo, la pintura (acrílico, acuarela, óleo), la composición visual, el color y diferentes movimientos artísticos. El objetivo es que cada estudiante desarrolle su lenguaje visual propio y su sensibilidad estética.',
            info: [
                { label: 'Nivel', value: 'Básico a Avanzado' },
                { label: 'Intensidad', value: '2 veces por semana' },
                { label: 'Duración', value: '1.5 horas / sesión' },
                { label: 'Modalidad', value: 'Grupal' }
            ],
            tags: ['Dibujo', 'Pintura', 'Acrílico', 'Acuarela', 'Color', 'Composición']
        },
        {
            key: 'ceramica',
            title: 'Cerámica',
            subtitle: 'Artes de la Arcilla',
            accent: 'var(--primary-yellow)',
            icon: '<span class="material-symbols-outlined">category</span>',
            image: '../assets/img/disiplinas/Ceramica.jpg',
            description: 'La cerámica es una de las artes más antiguas y satisfactorias de la humanidad. Trabajamos el modelado manual, la técnica de torno, el decorado con engobes y la cocción. Una disciplina meditativa y creativa que conecta al artista con la tierra y la tradición alfarera de nuestra región.',
            info: [
                { label: 'Edad', value: 'Todas las edades' },
                { label: 'Intensidad', value: '2 veces por semana' },
                { label: 'Duración', value: '1.5 horas / sesión' },
                { label: 'Modalidad', value: 'Grupal' }
            ],
            tags: ['Arcilla', 'Modelado', 'Torno', 'Decorado', 'Cocción', 'Alfarería']
        }
    ];

    /* ------------------------------------------
       CREAR ESTRUCTURA HTML DE LOS MODALES
    ------------------------------------------ */
    function createModalHTML() {
        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.id = 'disciplineModalOverlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-label', 'Información de la disciplina');

        overlay.innerHTML = `
            <div class="discipline-modal" id="disciplineModal">
                <div class="discipline-modal-accent" id="modalAccent"></div>
                <button class="modal-close-btn" id="modalCloseBtn" aria-label="Cerrar">&#x2715;</button>
                <img class="discipline-modal-image" id="modalImage" src="" alt="">
                <div class="discipline-modal-body">
                    <div class="discipline-modal-icon" id="modalIcon"></div>
                    <h2 id="modalTitle"></h2>
                    <p class="discipline-modal-subtitle" id="modalSubtitle"></p>
                    <p class="discipline-modal-description" id="modalDescription"></p>
                    <div class="discipline-modal-info" id="modalInfo"></div>
                    <div class="discipline-modal-tags" id="modalTags"></div>
                    <a href="pre-inscripcion.php" class="discipline-modal-cta">
                        <i class="fa-solid fa-file-pen"></i> Prematriculate aqui
                    </a>
                </div>
            </div>
        `;

        document.body.appendChild(overlay);
        return overlay;
    }

    /* ------------------------------------------
       CREAR ESTRUCTURA HTML DEL LIGHTBOX
    ------------------------------------------ */
    function createLightboxHTML() {
        const overlay = document.createElement('div');
        overlay.className = 'lightbox-overlay';
        overlay.id = 'galleryLightbox';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-label', 'Vista de imagen');

        overlay.innerHTML = `
            <button class="lightbox-close" id="lightboxClose" aria-label="Cerrar galería">&#x2715;</button>
            <div class="lightbox-container">
                <div class="lightbox-img-wrapper">
                    <button class="lightbox-btn lightbox-btn-prev" id="lightboxPrev" aria-label="Imagen anterior">&#8249;</button>
                    <img class="lightbox-img" id="lightboxImg" src="" alt="">
                    <button class="lightbox-btn lightbox-btn-next" id="lightboxNext" aria-label="Imagen siguiente">&#8250;</button>
                </div>
                <p class="lightbox-counter" id="lightboxCounter"></p>
                <div class="lightbox-thumbnails" id="lightboxThumbs"></div>
            </div>
        `;

        document.body.appendChild(overlay);
        return overlay;
    }

    /* ------------------------------------------
       LÓGICA MODALES DE DISCIPLINAS
    ------------------------------------------ */
    let modalOverlay, disciplineModal;

    function openDisciplineModal(index) {
        const d = disciplines[index];
        if (!d) return;

        document.getElementById('modalAccent').style.background = d.accent;
        document.getElementById('modalImage').src = d.image;
        document.getElementById('modalImage').alt = d.title;
        document.getElementById('modalIcon').innerHTML = d.icon;
        document.getElementById('modalTitle').textContent = d.title;
        document.getElementById('modalSubtitle').textContent = d.subtitle;
        document.getElementById('modalDescription').textContent = d.description;

        /* Info items */
        const infoContainer = document.getElementById('modalInfo');
        infoContainer.innerHTML = d.info.map(item => `
            <div class="discipline-info-item">
                <span>${item.label}</span>
                <p>${item.value}</p>
            </div>
        `).join('');

        /* Tags */
        const tagsContainer = document.getElementById('modalTags');
        tagsContainer.innerHTML = d.tags.map(tag =>
            `<span class="discipline-tag">${tag}</span>`
        ).join('');

        /* Aplicar color al icono */
        const iconEl = document.getElementById('modalIcon');
        if (iconEl.querySelector('i')) {
            iconEl.querySelector('i').style.color = d.accent;
        }
        if (iconEl.querySelector('.material-symbols-outlined')) {
            iconEl.querySelector('.material-symbols-outlined').style.color = d.accent;
        }

        /* Mostrar */
        modalOverlay.classList.add('active');
        document.body.style.overflow = 'hidden';

        /* Reset scroll del body del modal */
        document.getElementById('disciplineModal').querySelector('.discipline-modal-body').scrollTop = 0;
    }

    function closeDisciplineModal() {
        modalOverlay.classList.remove('active');
        document.body.style.overflow = '';
    }

    /* ------------------------------------------
       LÓGICA LIGHTBOX GALERÍA
    ------------------------------------------ */
    let lightboxOverlay;
    let galleryImages = [];
    let currentImageIndex = 0;

    function buildThumbnails() {
        const container = document.getElementById('lightboxThumbs');
        container.innerHTML = '';
        galleryImages.forEach((img, i) => {
            const thumb = document.createElement('img');
            thumb.className = 'lightbox-thumb';
            thumb.src = img.src;
            thumb.alt = img.alt;
            thumb.addEventListener('click', () => goToImage(i));
            container.appendChild(thumb);
        });
    }

    function updateLightbox(index, animate = false) {
        const imgEl = document.getElementById('lightboxImg');
        const counter = document.getElementById('lightboxCounter');
        const thumbs = document.querySelectorAll('.lightbox-thumb');

        if (animate) {
            imgEl.classList.add('fading');
            setTimeout(() => {
                imgEl.src = galleryImages[index].src;
                imgEl.alt = galleryImages[index].alt;
                imgEl.classList.remove('fading');
            }, 220);
        } else {
            imgEl.src = galleryImages[index].src;
            imgEl.alt = galleryImages[index].alt;
        }

        counter.textContent = `${index + 1} / ${galleryImages.length}`;

        thumbs.forEach((t, i) => {
            t.classList.toggle('active', i === index);
        });

        /* Scroll miniatura activa al centro */
        if (thumbs[index]) {
            thumbs[index].scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
        }

        currentImageIndex = index;
    }

    function goToImage(index) {
        const total = galleryImages.length;
        const newIndex = ((index % total) + total) % total;
        updateLightbox(newIndex, true);
    }

    function openLightbox(index) {
        buildThumbnails();
        updateLightbox(index, false);
        lightboxOverlay.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeLightbox() {
        lightboxOverlay.classList.remove('active');
        document.body.style.overflow = '';
    }

    /* ------------------------------------------
       INICIALIZACIÓN
    ------------------------------------------ */
    function init() {
        /* Crear overlays */
        modalOverlay = createModalHTML();
        lightboxOverlay = createLightboxHTML();

        /* ---- Asociar disciplinas con tarjetas ---- */
        const capabilityBoxes = document.querySelectorAll('.capability-box');
        capabilityBoxes.forEach(function (box, index) {
            box.setAttribute('tabindex', '0');
            box.setAttribute('role', 'button');
            box.setAttribute('aria-label', 'Ver detalle de la disciplina');

            box.addEventListener('click', function () {
                openDisciplineModal(index);
            });

            box.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    openDisciplineModal(index);
                }
            });
        });

        /* ---- Cerrar modal disciplina ---- */
        document.getElementById('modalCloseBtn').addEventListener('click', closeDisciplineModal);
        modalOverlay.addEventListener('click', function (e) {
            if (e.target === modalOverlay) closeDisciplineModal();
        });

        /* ---- Galería: recolectar imágenes ---- */
        const galleryItems = document.querySelectorAll('.masonry-item img');
        galleryImages = Array.from(galleryItems);

        /* Asociar click a cada ítem */
        galleryImages.forEach(function (img, index) {
            img.parentElement.addEventListener('click', function () {
                openLightbox(index);
            });
            img.parentElement.setAttribute('tabindex', '0');
            img.parentElement.setAttribute('role', 'button');
            img.parentElement.setAttribute('aria-label', 'Ver imagen en grande');

            img.parentElement.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    openLightbox(index);
                }
            });
        });

        /* ---- Controles lightbox ---- */
        document.getElementById('lightboxClose').addEventListener('click', closeLightbox);
        lightboxOverlay.addEventListener('click', function (e) {
            if (e.target === lightboxOverlay) closeLightbox();
        });

        document.getElementById('lightboxPrev').addEventListener('click', function (e) {
            e.stopPropagation();
            goToImage(currentImageIndex - 1);
        });

        document.getElementById('lightboxNext').addEventListener('click', function (e) {
            e.stopPropagation();
            goToImage(currentImageIndex + 1);
        });

        /* ---- Teclado global ---- */
        document.addEventListener('keydown', function (e) {
            /* Modal disciplinas */
            if (modalOverlay.classList.contains('active')) {
                if (e.key === 'Escape') closeDisciplineModal();
            }

            /* Lightbox */
            if (lightboxOverlay.classList.contains('active')) {
                if (e.key === 'Escape') closeLightbox();
                if (e.key === 'ArrowLeft') goToImage(currentImageIndex - 1);
                if (e.key === 'ArrowRight') goToImage(currentImageIndex + 1);
            }
        });

        /* ---- Swipe táctil para lightbox ---- */
        let touchStartX = 0;
        let touchStartY = 0;

        lightboxOverlay.addEventListener('touchstart', function (e) {
            touchStartX = e.changedTouches[0].screenX;
            touchStartY = e.changedTouches[0].screenY;
        }, { passive: true });

        lightboxOverlay.addEventListener('touchend', function (e) {
            const deltaX = e.changedTouches[0].screenX - touchStartX;
            const deltaY = e.changedTouches[0].screenY - touchStartY;

            if (Math.abs(deltaX) > Math.abs(deltaY) && Math.abs(deltaX) > 40) {
                if (deltaX < 0) {
                    goToImage(currentImageIndex + 1);
                } else {
                    goToImage(currentImageIndex - 1);
                }
            }
        }, { passive: true });
    }

    /* Ejecutar cuando el DOM esté listo */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();