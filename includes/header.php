<style>
    @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap');

    * {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: "Poppins", sans-serif;
    }

    body {
    min-height: 100vh;
    background: var(--card-bg);
    }

    /* Overlay para mobile */
    .sidebar-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        z-index: 9;
        opacity: 0;
        transition: opacity 0.3s ease;
    }

    .sidebar-overlay.active {
        display: block;
        opacity: 1;
    }

    .sidebar {
    position: fixed;
    top: 0;
    left: 0;
    z-index: 10;
    width: 270px;
    height: 100vh;
    background: var(--dark-bg);
    transition: all 0.4s ease;
    }

    .sidebar.collapsed {
    width: 85px;
    }

    .sidebar .sidebar-header {
    display: flex;
    position: relative;
    padding: 25px 20px;
    align-items: center;
    justify-content: space-between;
    }
    
    .sidebar-header .header-logo {
        display: flex;
        align-items: center;
        gap: 12px;
        flex: 1;
    }

    .sidebar-header .header-logo img {
        width: 45px;
        height: 45px;
        object-fit: cover;
        border-radius: 50%;
        border: 2px solid var(--primary-blue);
        box-shadow: var(--shadow-sm);
        flex-shrink: 0;
    }

    .sidebar-header .sidebar-toggler,
    .sidebar-menu-button {
    position: absolute;
    right: 20px;
    height: 35px;
    width: 35px;
    color: var(--text-primary);
    border: none;
    cursor: pointer;
    display: flex;
    background: var(--primary-blue);
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    transition: 0.4s ease;
    }

    .sidebar.collapsed .sidebar-header .sidebar-toggler {
    transform: translate(-4px, 65px);
    }

    .sidebar-header .sidebar-toggler span,
    .sidebar-menu-button span {
    font-size: 1.75rem;
    transition: 0.4s ease;
    }

    .sidebar.collapsed .sidebar-header .sidebar-toggler span {
    transform: rotate(180deg);
    }

    .sidebar-header .sidebar-toggler:hover {
    background: var(--primary-green);
    }

    .sidebar-nav .nav-list {
    list-style: none;
    display: flex;
    gap: 4px;
    padding: 0 15px;
    flex-direction: column;
    transform: translateY(15px);
    transition: 0.4s ease;
    }

    .sidebar .sidebar-nav .primary-nav {
    overflow-y: auto;
    scrollbar-width: thin;
    padding-bottom: 20px;
    height: calc(100vh - 227px);
    scrollbar-color: transparent transparent;
    }

    .sidebar .sidebar-nav .primary-nav:hover {
    scrollbar-color: var(--primary-blue) transparent;
    }

    .sidebar.collapsed .sidebar-nav .primary-nav {
    overflow: unset;
    transform: translateY(65px);
    }

    .sidebar-nav .nav-item .nav-link {
    color: var(--text-primary);
    display: flex;
    gap: 12px;
    white-space: nowrap;
    border-radius: 8px;
    padding: 11px 15px;
    align-items: center;
    text-decoration: none;
    border: 1px solid var(--dark-bg);
    transition: 0.4s ease;
    }

    .sidebar-nav .nav-item:is(:hover, .open)>.nav-link:not(.dropdown-title) {
    color: var(--primary-blue);
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    }

    .sidebar .nav-link .nav-label {
    transition: opacity 0.3s ease;
    }

    .sidebar.collapsed .nav-link :where(.nav-label, .dropdown-icon) {
    opacity: 0;
    pointer-events: none;
    }

    .sidebar.collapsed .nav-link .dropdown-icon {
    transition: opacity 0.3s 0s ease;
    }

    .sidebar-nav .secondary-nav {
    position: absolute;
    bottom: 35px;
    width: 100%;
    background: var(--dark-bg);
    border-top: 1px solid var(--border-color);
    }

    .sidebar-nav .nav-item {
    position: relative;
    }

    /* Dropdown Stylings Codes */
    .sidebar-nav .dropdown-container .dropdown-icon {
    margin: 0 -4px 0 auto;
    transition: transform 0.4s ease, opacity 0.3s 0.2s ease;
    }

    .sidebar-nav .dropdown-container.open .dropdown-icon {
    transform: rotate(180deg);
    }

    .sidebar-nav .dropdown-menu {
    height: 0;
    overflow-y: hidden;
    list-style: none;
    padding-left: 15px;
    transition: height 0.4s ease;
    }

    .sidebar.collapsed .dropdown-menu {
    position: absolute;
    top: -10px;
    left: 100%;
    opacity: 0;
    height: auto !important;
    padding-right: 10px;
    overflow-y: unset;
    pointer-events: none;
    border-radius: 0 10px 10px 0;
    background: var(--dark-bg);
    transition: 0s;
    }

    .sidebar.collapsed .dropdown-menu:has(.dropdown-link) {
    padding: 7px 10px 7px 24px;
    }

    .sidebar.sidebar.collapsed .nav-item:hover>.dropdown-menu {
    opacity: 1;
    pointer-events: auto;
    transform: translateY(12px);
    transition: all 0.4s ease;
    }

    .sidebar.sidebar.collapsed .nav-item:hover>.dropdown-menu:has(.dropdown-link) {
    transform: translateY(10px);
    }

    .dropdown-menu .nav-item .nav-link {
    color: var(--text-primary);
    padding: 9px 15px;
    }

    .sidebar.collapsed .dropdown-menu .nav-link {
    padding: 7px 15px;
    }

    .dropdown-menu .nav-item .nav-link.dropdown-title {
    display: none;
    color: var(--text-primary);
    padding: 9px 15px;
    }

    .dropdown-menu:has(.dropdown-link) .nav-item .dropdown-title {
    font-weight: 500;
    padding: 7px 15px;
    }

    .sidebar.collapsed .dropdown-menu .nav-item .dropdown-title {
    display: block;
    }

    .sidebar-menu-button {
    display: none;
    }

    /* Responsive codes for small screens */
    @media (max-width: 768px) {
        .sidebar-menu-button {
            position: fixed;
            left: 20px;
            top: 20px;
            height: 40px;
            width: 42px;
            display: flex;
            color: var(--text-primary);
            background: var(--primary-blue);
            transition: all 0.3s ease;
            z-index: 1001;
            border-radius: 8px;
            opacity: 1;
            pointer-events: auto;
        }

        .sidebar-menu-button:hover {
            background-color: var(--primary-green);
        }

        /* Ocultar botón cuando sidebar está abierto */
        .sidebar-menu-button.hidden {
            opacity: 0;
            pointer-events: none;
            transform: scale(0.8);
        }

        /* Sidebar oculto por defecto en móvil */
        .sidebar {
            left: -270px;
        }

        /* Sidebar visible cuando NO tiene clase collapsed */
        .sidebar:not(.collapsed) {
            left: 0;
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.3);
        }

        /* En móvil, collapsed = oculto */
        .sidebar.collapsed {
            width: 270px;
            left: -270px;
        }

        .sidebar.collapsed .sidebar-header .sidebar-toggler {
            transform: none;
        }

        .sidebar.collapsed .sidebar-nav .primary-nav {
            transform: translateY(15px);
        }

        /* Ajustar contenido principal en móvil */
        .main-content {
            margin-left: 0 !important;
            width: 100%;
        }
    }

    /* Para pantallas grandes (Desktop) */
    @media (min-width: 769px) {
        /* Ajustar contenido principal cuando sidebar está expandido */
        .main-content {
            margin-left: 270px;
            transition: margin-left 0.4s ease;
        }

        /* Ajustar contenido principal cuando sidebar está colapsado */
        .sidebar.collapsed ~ .main-content {
            margin-left: 85px;
        }
    }
</style>

<!-- Overlay para cerrar sidebar en móvil -->
<div class="sidebar-overlay"></div>

<!-- Mobile Sidebar Menu Button -->
<button class="sidebar-menu-button">
    <span class="material-symbols-rounded">menu</span>
</button>

<aside class="sidebar collapsed">
    <!-- Sidebar Header -->
    <header class="sidebar-header">
        <div class="header-logo">
            <img src="../../assets/img/3.png" alt="Amimbré">
        </div>
        <button class="sidebar-toggler">
            <span class="material-symbols-rounded">chevron_left</span>
        </button>
    </header>

    <nav class="sidebar-nav">
        <!-- Primary Top Nav -->
        <ul class="nav-list primary-nav">
            <li class="nav-item">
                <a href="#" class="nav-link">
                    <span class="material-symbols-rounded">dashboard</span>
                    <span class="nav-label">Dashboard</span>
                </a>
                <ul class="dropdown-menu">
                    <li class="nav-item"><a class="nav-link dropdown-title">Dashboard</a></li>
                </ul>
            </li>

            <li class="nav-item dropdown-container">
                <a href="#" class="nav-link dropdown-toggle">
                    <span class="material-symbols-rounded">description</span>
                    <span class="nav-label">Inscripciones</span>
                    <span class="dropdown-icon material-symbols-rounded">keyboard_arrow_down</span>
                </a>
                <ul class="dropdown-menu">
                    <li class="nav-item"><a class="nav-link dropdown-title">Inscripciones</a></li>
                    <li class="nav-item"><a href="#" class="nav-link dropdown-link">Prematrículas</a></li>
                    <li class="nav-item"><a href="#" class="nav-link dropdown-link">Matrículas</a></li>
                </ul>
            </li>

            <li class="nav-item">
                <a href="#" class="nav-link">
                    <span class="material-symbols-rounded">group</span>
                    <span class="nav-label">Usuarios</span>
                </a>
                <ul class="dropdown-menu">
                    <li class="nav-item"><a class="nav-link dropdown-title">Usuarios</a></li>
                </ul>
            </li>

            <li class="nav-item">
                <a href="#" class="nav-link">
                    <span class="material-symbols-rounded">menu_book</span>
                    <span class="nav-label">Cursos</span>
                </a>
                <ul class="dropdown-menu">
                    <li class="nav-item"><a class="nav-link dropdown-title">Cursos</a></li>
                </ul>
            </li>

            <li class="nav-item">
                <a href="#" class="nav-link">
                    <span class="material-symbols-rounded">grade</span>
                    <span class="nav-label">Notas</span>
                </a>
                <ul class="dropdown-menu">
                    <li class="nav-item"><a class="nav-link dropdown-title">Notas</a></li>
                </ul>
            </li>

            <li class="nav-item dropdown-container">
                <a href="#" class="nav-link dropdown-toggle">
                    <span class="material-symbols-rounded">folder</span>
                    <span class="nav-label">Documentación</span>
                    <span class="dropdown-icon material-symbols-rounded">keyboard_arrow_down</span>
                </a>
                <ul class="dropdown-menu">
                    <li class="nav-item"><a class="nav-link dropdown-title">Documentación</a></li>
                    <li class="nav-item"><a href="#" class="nav-link dropdown-link">Academica</a></li>
                    <li class="nav-item"><a href="#" class="nav-link dropdown-link">Institucional</a></li>
                </ul>
            </li>

            <li class="nav-item">
                <a href="#" class="nav-link">
                    <span class="material-symbols-rounded">notifications</span>
                    <span class="nav-label">Notificaciones</span>
                </a>
                <ul class="dropdown-menu">
                    <li class="nav-item"><a class="nav-link dropdown-title">Notificaciones</a></li>
                </ul>
            </li>

            <li class="nav-item">
                <a href="#" class="nav-link">
                    <span class="material-symbols-rounded">assessment</span>
                    <span class="nav-label">Reportes</span>
                </a>
                <ul class="dropdown-menu">
                    <li class="nav-item"><a class="nav-link dropdown-title">Reportes</a></li>
                </ul>
            </li>

            <li class="nav-item">
                <a href="#" class="nav-link">
                    <span class="material-symbols-rounded">settings</span>
                    <span class="nav-label">Configuración</span>
                </a>
                <ul class="dropdown-menu">
                    <li class="nav-item"><a class="nav-link dropdown-title">Configuración</a></li>
                </ul>
            </li>
        </ul>

        <!-- Secondary Bottom Nav -->
        <ul class="nav-list secondary-nav">
            <li class="nav-item">
                <a href="#" class="nav-link">
                    <span class="material-symbols-rounded">help</span>
                    <span class="nav-label">Ayuda</span>
                </a>
                <ul class="dropdown-menu">
                    <li class="nav-item"><a class="nav-link dropdown-title">Ayuda</a></li>
                </ul>
            </li>
            <li class="nav-item">
                <a href="#" class="nav-link">
                    <span class="material-symbols-rounded">logout</span>
                    <span class="nav-label">Cerrar Sesión</span>
                </a>
                <ul class="dropdown-menu">
                    <li class="nav-item"><a class="nav-link dropdown-title">Cerrar Sesión</a></li>
                </ul>
            </li>
        </ul>
    </nav>
</aside>

<script>
    // Toggle visibility of a dropdown menu
    const toggleDropdown = (dropdown, menu, isOpen) => {
        dropdown.classList.toggle("open", isOpen);
        menu.style.height = isOpen ? `${menu.scrollHeight}px` : 0;
    };

    // Close all open dropdowns
    const closeAllDropdowns = () => {
        document.querySelectorAll(".dropdown-container.open").forEach((openDropdown) => {
            toggleDropdown(openDropdown, openDropdown.querySelector(".dropdown-menu"), false);
        });
    };

    // Click event to all dropdown toggles
    document.querySelectorAll(".dropdown-toggle").forEach((dropdownToggle) => {
        dropdownToggle.addEventListener("click", (e) => {
            e.preventDefault();

            const dropdown = dropdownToggle.closest(".dropdown-container");
            const menu = dropdown.querySelector(".dropdown-menu");
            const isOpen = dropdown.classList.contains("open");

            closeAllDropdowns();
            toggleDropdown(dropdown, menu, !isOpen);
        });
    });

    const sidebar = document.querySelector(".sidebar");
    const sidebarOverlay = document.querySelector(".sidebar-overlay");
    const menuButton = document.querySelector(".sidebar-menu-button");
    const isMobile = () => window.innerWidth <= 768;

    // Función para toggle del sidebar
    const toggleSidebar = () => {
        sidebar.classList.toggle("collapsed");
        
        // En móvil, mostrar/ocultar overlay y botón
        if (isMobile()) {
            sidebarOverlay.classList.toggle("active");
            menuButton.classList.toggle("hidden");
        }
    };

    // Click event to sidebar toggle buttons
    document.querySelectorAll(".sidebar-toggler, .sidebar-menu-button").forEach((button) => {
        button.addEventListener("click", () => {
            closeAllDropdowns();
            toggleSidebar();
        });
    });

    // Cerrar sidebar al hacer clic en el overlay (solo móvil)
    sidebarOverlay.addEventListener("click", () => {
        if (isMobile() && !sidebar.classList.contains("collapsed")) {
            toggleSidebar();
        }
    });

    // Inicializar estado según tamaño de pantalla
    const initSidebar = () => {
        if (isMobile()) {
            sidebar.classList.add("collapsed");
            sidebarOverlay.classList.remove("active");
            menuButton.classList.remove("hidden");
        } else {
            sidebar.classList.remove("collapsed");
            sidebarOverlay.classList.remove("active");
            menuButton.classList.remove("hidden");
        }
    };

    // Ejecutar al cargar
    initSidebar();

    // Re-inicializar al cambiar tamaño de ventana
    let resizeTimer;
    window.addEventListener("resize", () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => {
            initSidebar();
            closeAllDropdowns();
        }, 250);
    });
</script>