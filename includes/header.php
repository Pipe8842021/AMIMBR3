<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Responsive Sidebar with Dropdown Menu by AbdulDev</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0" />
    <link rel="stylesheet" href="/AMIMBR3/assets/css/colores.css">
</head>
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
        position: relative;
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

    /* Badge de notificaciones sin leer */
    .notif-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 20px;
        height: 20px;
        padding: 0 5px;
        border-radius: 10px;
        background: var(--primary-orange);
        color: #fff;
        font-size: 0.7rem;
        font-weight: 700;
        margin-left: auto;
        flex-shrink: 0;
        animation: pulse-notif 2s infinite;
    }

    .sidebar.collapsed .notif-badge {
        position: absolute;
        top: 6px;
        right: 6px;
        margin-left: 0;
    }

    @keyframes pulse-notif {
        0%, 100% { transform: scale(1);   box-shadow: 0 0 0 0 rgba(249, 115, 22, 0.4); }
        50%       { transform: scale(1.1); box-shadow: 0 0 0 5px rgba(249, 115, 22, 0); }
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
            transition: 0.5s;
        }

        .sidebar-menu-button:hover {
            background-color: var(--primary-green);
        }

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
    }
</style>

<?php
// ──────────────────────────────────────────────────────────────
//  CONTROL DE ROLES
//  Usa has_any_role() definida en config/session.php, que lee
//  $_SESSION['user_rol'] con los valores exactos del enum en BD:
//    'admin'      → acceso total
//    'profesor'   → acceso parcial
//    'estudiante' → acceso básico
//
//  Tabla de permisos:
//  ┌─────────────────┬───────┬──────────┬────────────┐
//  │ Ítem            │ Admin │ Profesor │ Estudiante │
//  ├─────────────────┼───────┼──────────┼────────────┤
//  │ Dashboard       │  ✓   │    ✓     │     ✓      │
//  │ Inscripciones   │  ✓   │    ✗     │     ✗      │
//  │ Usuarios        │  ✓   │    ✗     │     ✗      │  ← solo admin
//  │ Cursos          │  ✓   │    ✓     │     ✓      │
//  │ Doc. Administ.  │  ✓   │    ✓     │     ✗      │  ← no estudiante
//  │ Doc. Institucio.│  ✓   │    ✓     │     ✓      │
//  │ Grupos          │  ✓   │    ✓     │     ✗      │
//  │ Notificaciones  │  ✓   │    ✓     │     ✓      │
//  │ Reportes        │  ✓   │    ✗     │     ✗      │
//  │ Horario         │  ✓   │    ✓     │     ✓      │
//  │ Configuración   │  ✓   │    ✓     │     ✓      │
//  └─────────────────┴───────┴──────────┴────────────┘
// ──────────────────────────────────────────────────────────────

// Badge de notificaciones sin leer
$badge_sin_leer = 0;
if (isset($_SESSION['user_id']) && isset($pdo)) {
    require_once __DIR__ . '/notificaciones_helper.php';
    $badge_sin_leer = NotificacionesHelper::contarSinLeer($pdo, $_SESSION['user_id']);
}
?>

<body>
    <!-- Mobile Sidebar Menu Button -->
    <button class="sidebar-menu-button">
        <span class="material-symbols-rounded">menu</span>
    </button>

    <aside class="sidebar">
        <!-- Sidebar Header -->
        <header class="sidebar-header">
            <div class="header-logo">
                <img src="/AMIMBR3/assets/img/3.png" alt="Amimbré">
            </div>
            <button class="sidebar-toggler">
                <span class="material-symbols-rounded">chevron_left</span>
            </button>
        </header>

        <nav class="sidebar-nav">
            <!-- Primary Nav -->
            <ul class="nav-list primary-nav">

                <?php if (has_any_role(['admin', 'profesor', 'estudiante'])): ?>
                <li class="nav-item">
                    <a href="/AMIMBR3/modules/dashboard/" class="nav-link">
                        <span class="material-symbols-rounded">dashboard</span>
                        <span class="nav-label">Dashboard</span>
                    </a>
                    <ul class="dropdown-menu">
                        <li class="nav-item"><a class="nav-link dropdown-title">Dashboard</a></li>
                    </ul>
                </li>
                <?php endif; ?>

                <?php if (has_any_role(['admin'])): ?>
                <li class="nav-item dropdown-container">
                    <a href="#" class="nav-link dropdown-toggle">
                        <span class="material-symbols-rounded">description</span>
                        <span class="nav-label">Inscripciones</span>
                        <span class="dropdown-icon material-symbols-rounded">keyboard_arrow_down</span>
                    </a>
                    <ul class="dropdown-menu">
                        <li class="nav-item"><a class="nav-link dropdown-title">Inscripciones</a></li>
                        <li class="nav-item"><a href="/AMIMBR3/modules/inscripciones/prematriculas/index.php" class="nav-link dropdown-link">Prematrículas</a></li>
                        <li class="nav-item"><a href="/AMIMBR3/modules/inscripciones/matriculas/index.php" class="nav-link dropdown-link">Matrículas</a></li>
                    </ul>
                </li>
                <?php endif; ?>

                <!-- ✏️ CAMBIO 1: Usuarios — ahora solo visible para admin -->
                <?php if (has_any_role(['admin'])): ?>
                <li class="nav-item">
                    <a href="/AMIMBR3/modules/usuarios/index.php" class="nav-link">
                        <span class="material-symbols-rounded">group</span>
                        <span class="nav-label">Usuarios</span>
                    </a>
                    <ul class="dropdown-menu">
                        <li class="nav-item"><a class="nav-link dropdown-title">Usuarios</a></li>
                    </ul>
                </li>
                <?php endif; ?>

                <?php if (has_any_role(['admin', 'profesor', 'estudiante'])): ?>
                <li class="nav-item">
                    <a href="/AMIMBR3/modules/cursos/index.php" class="nav-link">
                        <span class="material-symbols-rounded">menu_book</span>
                        <span class="nav-label">Cursos</span>
                    </a>
                    <ul class="dropdown-menu">
                        <li class="nav-item"><a class="nav-link dropdown-title">Cursos</a></li>
                    </ul>
                </li>
                <?php endif; ?>

                <!-- ✏️ CAMBIO 2: Documentación — el item Administrativa se oculta para estudiante -->
                <?php if (has_any_role(['admin', 'profesor', 'estudiante'])): ?>
                <li class="nav-item dropdown-container">
                    <a href="#" class="nav-link dropdown-toggle">
                        <span class="material-symbols-rounded">folder</span>
                        <span class="nav-label">Documentación</span>
                        <span class="dropdown-icon material-symbols-rounded">keyboard_arrow_down</span>
                    </a>
                    <ul class="dropdown-menu">
                        <li class="nav-item"><a class="nav-link dropdown-title">Documentación</a></li>
                        <?php if (has_any_role(['admin', 'profesor'])): ?>
                        <li class="nav-item"><a href="/AMIMBR3/modules/documentos/administrativos/index.php" class="nav-link dropdown-link">Administrativa</a></li>
                        <?php endif; ?>
                        <li class="nav-item"><a href="/AMIMBR3/modules/documentos/institucionales/index.php" class="nav-link dropdown-link">Institucional</a></li>
                    </ul>
                </li>
                <?php endif; ?>

                <?php if (has_any_role(['admin', 'profesor'])): ?>
                <li class="nav-item">
                    <a href="/AMIMBR3/modules/grupos/index.php" class="nav-link">
                        <span class="material-symbols-rounded">group_add</span>
                        <span class="nav-label">Grupos</span>
                    </a>
                    <ul class="dropdown-menu">
                        <li class="nav-item"><a class="nav-link dropdown-title">Grupos</a></li>
                    </ul>
                </li>
                <?php endif; ?>

                <?php if (has_any_role(['admin', 'profesor', 'estudiante'])): ?>
                <li class="nav-item">
                    <a href="/AMIMBR3/modules/notificaciones/index.php" class="nav-link">
                        <span class="material-symbols-rounded">notifications</span>
                        <span class="nav-label">Notificaciones</span>
                        <?php if ($badge_sin_leer > 0): ?>
                            <span class="notif-badge">
                                <?php echo $badge_sin_leer > 99 ? '99+' : $badge_sin_leer; ?>
                            </span>
                        <?php endif; ?>
                    </a>
                    <ul class="dropdown-menu">
                        <li class="nav-item"><a class="nav-link dropdown-title">Notificaciones</a></li>
                    </ul>
                </li>
                <?php endif; ?>

                <?php if (has_any_role(['admin'])): ?>
                <li class="nav-item">
                    <a href="/AMIMBR3/modules/reportes/index.php" class="nav-link">
                        <span class="material-symbols-rounded">assessment</span>
                        <span class="nav-label">Reportes</span>
                    </a>
                    <ul class="dropdown-menu">
                        <li class="nav-item"><a class="nav-link dropdown-title">Reportes</a></li>
                    </ul>
                </li>
                <?php endif; ?>

                <?php if (has_any_role(['admin', 'profesor', 'estudiante'])): ?>
                <li class="nav-item">
                    <a href="/AMIMBR3/modules/horarios/index.php" class="nav-link">
                        <span class="material-symbols-rounded">calendar_today</span>
                        <span class="nav-label">Horario</span>
                    </a>
                    <ul class="dropdown-menu">
                        <li class="nav-item"><a class="nav-link dropdown-title">Horario</a></li>
                    </ul>
                </li>
                <?php endif; ?>

                <?php if (has_any_role(['admin', 'profesor', 'estudiante'])): ?>
                <li class="nav-item">
                    <a href="/AMIMBR3/modules/configuracion/configuraciones.php" class="nav-link">
                        <span class="material-symbols-rounded">settings</span>
                        <span class="nav-label">Configuración</span>
                    </a>
                    <ul class="dropdown-menu">
                        <li class="nav-item"><a class="nav-link dropdown-title">Configuración</a></li>
                    </ul>
                </li>
                <?php endif; ?>

            </ul>

            <!-- Secondary Bottom Nav (visible para todos los roles) -->
            <ul class="nav-list secondary-nav">
                <li class="nav-item">
                    <a href="/AMIMBR3/modules/ayuda/ayuda_index.php" class="nav-link">
                        <span class="material-symbols-rounded">help</span>
                        <span class="nav-label">Ayuda</span>
                    </a>
                    <ul class="dropdown-menu">
                        <li class="nav-item"><a class="nav-link dropdown-title">Ayuda</a></li>
                    </ul>
                </li>
                <li class="nav-item">
                    <a href="/AMIMBR3/auth/logout.php" class="nav-link">
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

        // Click event to sidebar toggle buttons
        document.querySelectorAll(".sidebar-toggler, .sidebar-menu-button").forEach((button) => {
            button.addEventListener("click", () => {
                closeAllDropdowns();
                document.querySelector(".sidebar").classList.toggle("collapsed");
            });
        });

        // Default Collapse Sidebar for small screens
        if (window.innerWidth <= 1024) document.querySelector(".sidebar").classList.add("collapsed");
    </script>
</body>

</html>