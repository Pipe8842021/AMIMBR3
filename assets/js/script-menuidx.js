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
        body.style.overflow = 'hidden';
    }

    // Función para cerrar el menú
    function closeMenu() {
        hamburgerMenu.classList.remove('active');
        mobileSidebar.classList.remove('active');
        mobileOverlay.classList.remove('active');
        body.style.overflow = '';
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

    // ── ANIMACIONES AL HACER SCROLL ───────────────────────────────

    const observerOptions = {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
    };

    const observer = new IntersectionObserver(function(entries) {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0) translateX(0) scale(1)';
            }
        });
    }, observerOptions);

    // ── HERO ──────────────────────────────────────────────
    const heroContent = document.querySelector('.hero-content');
    if (heroContent) {
        heroContent.style.opacity = '0';
        heroContent.style.transform = 'translateY(40px)';
        heroContent.style.transition = 'opacity 0.8s ease, transform 0.8s ease';
        observer.observe(heroContent);
    }

    // ── SOBRE NOSOTROS ────────────────────────────────────
    const sectionHeader = document.querySelector('.section-header');
    if (sectionHeader) {
        sectionHeader.style.opacity = '0';
        sectionHeader.style.transform = 'translateY(-20px)';
        sectionHeader.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
        observer.observe(sectionHeader);
    }

    document.querySelectorAll('.feature-card').forEach((el, index) => {
        el.style.opacity = '0';
        el.style.transform = 'translateY(30px)';
        el.style.transition = `opacity 0.6s ease ${index * 0.15}s, transform 0.6s ease ${index * 0.15}s`;
        observer.observe(el);
    });

    // ── DISCIPLINAS ───────────────────────────────────────
    const capabilitiesHeader = document.querySelector('.capabilities h2');
    const capabilitiesSubtitle = document.querySelector('.capabilities > p');

    if (capabilitiesHeader) {
        capabilitiesHeader.style.opacity = '0';
        capabilitiesHeader.style.transform = 'translateY(-20px)';
        capabilitiesHeader.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
        observer.observe(capabilitiesHeader);
    }
    if (capabilitiesSubtitle) {
        capabilitiesSubtitle.style.opacity = '0';
        capabilitiesSubtitle.style.transform = 'translateY(-10px)';
        capabilitiesSubtitle.style.transition = 'opacity 0.6s ease 0.2s, transform 0.6s ease 0.2s';
        observer.observe(capabilitiesSubtitle);
    }

    document.querySelectorAll('.capability-box').forEach((el, index) => {
        el.style.opacity = '0';
        el.style.transform = 'scale(0.9)';
        el.style.transition = `opacity 0.5s ease ${(index % 4) * 0.1}s, transform 0.5s ease ${(index % 4) * 0.1}s`;
        observer.observe(el);
    });

    // ── GALERÍA ───────────────────────────────────────────
    const galleryHeader = document.querySelector('.gallery-header');
    if (galleryHeader) {
        galleryHeader.style.opacity = '0';
        galleryHeader.style.transform = 'translateY(-20px)';
        galleryHeader.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
        observer.observe(galleryHeader);
    }

    document.querySelectorAll('.masonry-item').forEach((el, index) => {
        el.style.opacity = '0';
        el.style.transform = 'scale(0.85)';
        el.style.transition = `opacity 0.5s ease ${(index % 5) * 0.08}s, transform 0.5s ease ${(index % 5) * 0.08}s`;
        observer.observe(el);
    });

    // ── CONTACTO ──────────────────────────────────────────
    const contactHeader = document.querySelector('.contact-header');
    if (contactHeader) {
        contactHeader.style.opacity = '0';
        contactHeader.style.transform = 'translateY(-20px)';
        contactHeader.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
        observer.observe(contactHeader);
    }

    document.querySelectorAll('.card-cont').forEach((el, index) => {
        el.style.opacity = '0';
        el.style.transform = 'translateX(-40px)';
        el.style.transition = `opacity 0.6s ease ${index * 0.15}s, transform 0.6s ease ${index * 0.15}s`;
        observer.observe(el);
    });

    const contactMap = document.querySelector('.contacts-maps');
    if (contactMap) {
        contactMap.style.opacity = '0';
        contactMap.style.transform = 'translateX(40px)';
        contactMap.style.transition = 'opacity 0.7s ease 0.3s, transform 0.7s ease 0.3s';
        observer.observe(contactMap);
    }

    // ── FOOTER ────────────────────────────────────────────
    document.querySelectorAll('.footer-section').forEach((el, index) => {
        el.style.opacity = '0';
        el.style.transform = 'translateY(20px)';
        el.style.transition = `opacity 0.5s ease ${index * 0.1}s, transform 0.5s ease ${index * 0.1}s`;
        observer.observe(el);
    });

    // ── LAZY LOADING IMÁGENES ─────────────────────────────
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

    // ── NAVEGACIÓN ACTIVA AL HACER SCROLL ─────────────────
    const sections = document.querySelectorAll('section[id]');

    function highlightNavigation() {
        const scrollY = window.pageYOffset;

        sections.forEach(section => {
            const sectionHeight = section.offsetHeight;
            const sectionTop = section.offsetTop - 100;
            const sectionId = section.getAttribute('id');

            if (scrollY > sectionTop && scrollY <= sectionTop + sectionHeight) {
                document.querySelectorAll('.nav-links a, .mobile-nav-item').forEach(link => {
                    link.classList.remove('active');
                });
                document.querySelectorAll(`a[href="#${sectionId}"]`).forEach(link => {
                    link.classList.add('active');
                });
            }
        });
    }

    window.addEventListener('scroll', highlightNavigation);

    // ── THEME TOGGLE ──────────────────────────────────────
    const themeToggle = document.querySelector('.theme-toggle');
    if (themeToggle) {
        themeToggle.addEventListener('click', function() {
            console.log('Theme toggle clicked');
        });
    }

    // ── RESIZE ────────────────────────────────────────────
    let resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            if (window.innerWidth > 768 && mobileSidebar.classList.contains('active')) {
                closeMenu();
            }
        }, 250);
    });

    console.log('Menu responsive initialized');
});

// ============================================================
// THEME TOGGLE — Claro / Oscuro
// ============================================================
(function () {
    const toggle = document.querySelector('.theme-toggle');
    const html   = document.documentElement;
    const STORAGE_KEY = 'amimbre-theme';

    // Aplicar tema guardado al cargar
    const savedTheme = localStorage.getItem(STORAGE_KEY);
    if (savedTheme === 'light') {
        html.setAttribute('data-theme', 'light');
    }

    toggle?.addEventListener('click', () => {
        const isLight = html.getAttribute('data-theme') === 'light';

        if (isLight) {
            html.removeAttribute('data-theme');          // → dark (default)
            localStorage.setItem(STORAGE_KEY, 'dark');
        } else {
            html.setAttribute('data-theme', 'light');    // → light
            localStorage.setItem(STORAGE_KEY, 'light');
        }
    });
})();