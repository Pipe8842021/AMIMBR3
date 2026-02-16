/**
 * JavaScript para el módulo de Ayuda
 * Maneja la interactividad de búsqueda, filtros, FAQ y navegación
 */

document.addEventListener('DOMContentLoaded', function() {
    // ==================== ELEMENTOS DEL DOM ====================
    const searchInput = document.getElementById('searchInput');
    const categoryChips = document.querySelectorAll('.category-chip');
    const navTabs = document.querySelectorAll('.nav-tab');
    const contentSections = document.querySelectorAll('.content-section');
    const faqItems = document.querySelectorAll('.faq-item');
    const faqQuestions = document.querySelectorAll('.faq-question');

    // ==================== NAVEGACIÓN ENTRE SECCIONES ====================
    navTabs.forEach(tab => {
        tab.addEventListener('click', function() {
            const targetSection = this.dataset.section;
            
            // Remover clase active de todas las pestañas
            navTabs.forEach(t => t.classList.remove('active'));
            
            // Añadir clase active a la pestaña clickeada
            this.classList.add('active');
            
            // Ocultar todas las secciones
            contentSections.forEach(section => {
                section.style.display = 'none';
            });
            
            // Mostrar la sección correspondiente
            const sectionToShow = document.getElementById(`${targetSection}-section`);
            if (sectionToShow) {
                sectionToShow.style.display = 'block';
            }
        });
    });

    // ==================== FILTROS DE CATEGORÍAS ====================
    categoryChips.forEach(chip => {
        chip.addEventListener('click', function() {
            const category = this.dataset.category;
            
            // Remover clase active de todos los chips
            categoryChips.forEach(c => c.classList.remove('active'));
            
            // Añadir clase active al chip clickeado
            this.classList.add('active');
            
            // Filtrar contenido según categoría
            filterByCategory(category);
        });
    });

    function filterByCategory(category) {
        // Aquí puedes implementar la lógica de filtrado
        // Por ahora solo mostramos un mensaje en consola
        console.log('Filtrando por categoría:', category);
        
        // TODO: Implementar filtrado real de videos, preguntas y guías
        if (category === 'todos') {
            // Mostrar todos los elementos
            showAllContent();
        } else {
            // Filtrar por categoría específica
            filterContentByCategory(category);
        }
    }

    function showAllContent() {
        // Mostrar todos los videos
        document.querySelectorAll('.video-card').forEach(card => {
            card.style.display = 'block';
        });
        
        // Mostrar todas las preguntas
        document.querySelectorAll('.faq-item').forEach(item => {
            item.style.display = 'block';
        });
        
        // Mostrar todas las guías
        document.querySelectorAll('.guide-card').forEach(card => {
            card.style.display = 'block';
        });
    }

    function filterContentByCategory(category) {
        // Esta función se puede expandir para filtrar por categorías reales
        // basándose en atributos data-category en los elementos
        console.log('Filtrando contenido para:', category);
    }

    // ==================== BÚSQUEDA ====================
    let searchTimeout;
    
    searchInput.addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase().trim();
        
        // Debounce para evitar búsquedas excesivas
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            performSearch(searchTerm);
        }, 300);
    });

    function performSearch(searchTerm) {
        if (searchTerm === '') {
            showAllContent();
            return;
        }

        // Buscar en títulos de videos
        document.querySelectorAll('.video-card').forEach(card => {
            const title = card.querySelector('.video-title').textContent.toLowerCase();
            const description = card.querySelector('.video-description').textContent.toLowerCase();
            
            if (title.includes(searchTerm) || description.includes(searchTerm)) {
                card.style.display = 'block';
                highlightText(card, searchTerm);
            } else {
                card.style.display = 'none';
            }
        });

        // Buscar en preguntas frecuentes
        document.querySelectorAll('.faq-item').forEach(item => {
            const question = item.querySelector('.faq-text').textContent.toLowerCase();
            const answer = item.querySelector('.faq-answer p').textContent.toLowerCase();
            
            if (question.includes(searchTerm) || answer.includes(searchTerm)) {
                item.style.display = 'block';
                highlightText(item, searchTerm);
            } else {
                item.style.display = 'none';
            }
        });

        // Buscar en guías
        document.querySelectorAll('.guide-card').forEach(card => {
            const title = card.querySelector('.guide-title').textContent.toLowerCase();
            const description = card.querySelector('.guide-description').textContent.toLowerCase();
            
            if (title.includes(searchTerm) || description.includes(searchTerm)) {
                card.style.display = 'block';
                highlightText(card, searchTerm);
            } else {
                card.style.display = 'none';
            }
        });
    }

    function highlightText(element, searchTerm) {
        // Esta función puede expandirse para resaltar visualmente el texto encontrado
        // Por ahora solo registramos en consola
        console.log('Texto encontrado en elemento:', element);
    }

    // ==================== ACORDEÓN FAQ ====================
    faqQuestions.forEach(question => {
        question.addEventListener('click', function() {
            const faqItem = this.closest('.faq-item');
            const isActive = faqItem.classList.contains('active');
            
            // Cerrar todos los otros FAQ items
            faqItems.forEach(item => {
                if (item !== faqItem) {
                    item.classList.remove('active');
                }
            });
            
            // Toggle del FAQ item actual
            if (isActive) {
                faqItem.classList.remove('active');
            } else {
                faqItem.classList.add('active');
            }
        });
    });

    // ==================== REPRODUCIR VIDEOS ====================
    document.querySelectorAll('.video-card').forEach(card => {
        card.addEventListener('click', function() {
            const videoId = this.dataset.videoId;
            playVideo(videoId);
        });
    });

    document.querySelectorAll('.btn-play').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.stopPropagation(); // Evitar que se dispare el click del card
            const videoCard = this.closest('.video-card');
            const videoId = videoCard.dataset.videoId;
            playVideo(videoId);
        });
    });

    function playVideo(videoId) {
        console.log('Reproduciendo video ID:', videoId);
        // TODO: Implementar modal o iframe para reproducir el video
        alert(`Reproduciendo video ID: ${videoId}\n\nAquí se abrirá un reproductor de video.`);
    }

    // ==================== BOTONES DE GUÍAS ====================
    document.querySelectorAll('.btn-guide').forEach(btn => {
        btn.addEventListener('click', function() {
            const guideCard = this.closest('.guide-card');
            const guideTitle = guideCard.querySelector('.guide-title').textContent;
            openGuide(guideTitle);
        });
    });

    function openGuide(guideTitle) {
        console.log('Abriendo guía:', guideTitle);
        // TODO: Implementar navegación a página de guía detallada
        alert(`Abriendo guía: ${guideTitle}\n\nAquí se abrirá la guía detallada.`);
    }

    // ==================== CONTACTAR SOPORTE ====================
    document.querySelectorAll('.btn-contact').forEach(btn => {
        btn.addEventListener('click', function() {
            contactSupport();
        });
    });

    function contactSupport() {
        // TODO: Implementar formulario de contacto o abrir modal
        alert('Contactando al soporte...\n\nAquí se abrirá un formulario de contacto.');
    }

    // ==================== ANIMACIONES AL SCROLL ====================
    const observerOptions = {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
    };

    const observer = new IntersectionObserver(function(entries) {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
            }
        });
    }, observerOptions);

    // Observar tarjetas para animarlas al hacer scroll
    document.querySelectorAll('.video-card, .faq-item, .guide-card').forEach(element => {
        element.style.opacity = '0';
        element.style.transform = 'translateY(20px)';
        element.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
        observer.observe(element);
    });

    // ==================== ATAJOS DE TECLADO ====================
    document.addEventListener('keydown', function(e) {
        // Ctrl/Cmd + K para enfocar la búsqueda
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            searchInput.focus();
        }
        
        // Escape para cerrar FAQs abiertos
        if (e.key === 'Escape') {
            faqItems.forEach(item => {
                item.classList.remove('active');
            });
        }
    });

    // ==================== INICIALIZACIÓN ====================
    console.log('Módulo de Ayuda inicializado correctamente');
    
    // Mostrar la primera sección por defecto
    const firstSection = document.getElementById('videos-section');
    if (firstSection) {
        firstSection.style.display = 'block';
    }
});