// Script para el menú hamburguesa y funcionalidad responsive

document.addEventListener('DOMContentLoaded', function() {
    // Elementos del DOM
    const hamburgerMenu = document.querySelector('.hamburger-menu');
    const mobileSidebar = document.querySelector('.mobile-sidebar');
    const mobileOverlay = document.querySelector('.mobile-menu-overlay');
    const closeMenuBtn = document.querySelector('.close-menu');
    const mobileNavItems = document.querySelectorAll('.mobile-nav-item');
    const body = document.body;

    // Función para abrir el menú
    function openMenu() {
        hamburgerMenu.classList.add('active');
        mobileSidebar.classList.add('active');
        mobileOverlay.classList.add('active');
        body.style.overflow = 'hidden'; // Prevenir scroll del body
    }

    // Función para cerrar el menú
    function closeMenu() {
        hamburgerMenu.classList.remove('active');
        mobileSidebar.classList.remove('active');
        mobileOverlay.classList.remove('active');
        body.style.overflow = ''; // Restaurar scroll del body
    }

    // Toggle del menú hamburguesa
    if (hamburgerMenu) {
        hamburgerMenu.addEventListener('click', function(e) {
            e.stopPropagation();
            if (mobileSidebar.classList.contains('active')) {
                closeMenu();
            } else {
                openMenu();
            }
        });
    }

    // Cerrar menú con el botón X
    if (closeMenuBtn) {
        closeMenuBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            closeMenu();
        });
    }

    // Cerrar menú al hacer clic en el overlay
    if (mobileOverlay) {
        mobileOverlay.addEventListener('click', function() {
            closeMenu();
        });
    }

    // Cerrar menú al hacer clic en un enlace de navegación
    mobileNavItems.forEach(item => {
        item.addEventListener('click', function() {
            closeMenu();
            // Smooth scroll a la sección
            const targetId = this.getAttribute('href');
            if (targetId.startsWith('#')) {
                const targetSection = document.querySelector(targetId);
                if (targetSection) {
                    setTimeout(() => {
                        targetSection.scrollIntoView({ 
                            behavior: 'smooth',
                            block: 'start'
                        });
                    }, 300);
                }
            }
        });
    });

    // Prevenir propagación de clics dentro del sidebar
    if (mobileSidebar) {
        mobileSidebar.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    }

    // Cerrar menú con la tecla ESC
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && mobileSidebar.classList.contains('active')) {
            closeMenu();
        }
    });

    // Smooth scroll para los enlaces de navegación del header normal
    const navLinks = document.querySelectorAll('.nav-links a[href^="#"]');
    navLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            const targetId = this.getAttribute('href');
            const targetSection = document.querySelector(targetId);
            
            if (targetSection) {
                e.preventDefault();
                targetSection.scrollIntoView({ 
                    behavior: 'smooth',
                    block: 'start'
                });
                
                // Actualizar URL sin reload
                history.pushState(null, null, targetId);
            }
        });
    });

    // Header con efecto al hacer scroll
    const header = document.querySelector('header');
    let lastScroll = 0;

    window.addEventListener('scroll', function() {
        const currentScroll = window.pageYOffset;

        if (currentScroll <= 0) {
            header.style.boxShadow = 'none';
        } else {
            header.style.boxShadow = '0 2px 10px rgba(0, 0, 0, 0.1)';
        }

        lastScroll = currentScroll;
    });

    // Animación de entrada para elementos al hacer scroll
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

    // Observar elementos para animación
    const animatedElements = document.querySelectorAll('.feature-card, .capability-box, .masonry-item');
    animatedElements.forEach(el => {
        el.style.opacity = '0';
        el.style.transform = 'translateY(20px)';
        el.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
        observer.observe(el);
    });

    // Manejo responsive del resize
    let resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            // Si la ventana se agranda más de 768px, cerrar el menú móvil
            if (window.innerWidth > 768 && mobileSidebar.classList.contains('active')) {
                closeMenu();
            }
        }, 250);
    });

    // Toggle del theme (opcional - para futura implementación)
    const themeToggle = document.querySelector('.theme-toggle');
    if (themeToggle) {
        themeToggle.addEventListener('click', function() {
            // Aquí puedes implementar el cambio de tema claro/oscuro
            console.log('Theme toggle clicked');
        });
    }

    // Prevenir zoom en inputs en iOS
    const inputs = document.querySelectorAll('input, textarea, select');
    inputs.forEach(input => {
        input.addEventListener('focus', function() {
            if (window.innerWidth < 768) {
                // Prevenir zoom
            }
        });
    });

    // Lazy loading para imágenes de galería
    if ('IntersectionObserver' in window) {
        const imageObserver = new IntersectionObserver((entries, observer) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const img = entry.target;
                    if (img.dataset.src) {
                        img.src = img.dataset.src;
                        img.removeAttribute('data-src');
                        observer.unobserve(img);
                    }
                }
            });
        });

        const lazyImages = document.querySelectorAll('img[data-src]');
        lazyImages.forEach(img => imageObserver.observe(img));
    }

    // Añadir clase active a los enlaces de navegación según la sección visible
    const sections = document.querySelectorAll('section[id]');
    
    function highlightNavigation() {
        const scrollY = window.pageYOffset;
        
        sections.forEach(section => {
            const sectionHeight = section.offsetHeight;
            const sectionTop = section.offsetTop - 100;
            const sectionId = section.getAttribute('id');
            
            if (scrollY > sectionTop && scrollY <= sectionTop + sectionHeight) {
                // Remover active de todos los enlaces
                document.querySelectorAll('.nav-links a, .mobile-nav-item').forEach(link => {
                    link.classList.remove('active');
                });
                
                // Añadir active al enlace correspondiente
                document.querySelectorAll(`a[href="#${sectionId}"]`).forEach(link => {
                    link.classList.add('active');
                });
            }
        });
    }

    window.addEventListener('scroll', highlightNavigation);

    // Inicializar
    console.log('Menu responsive initialized');
});