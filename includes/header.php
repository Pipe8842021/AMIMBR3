<?php
if (isset($_GET['action'])) {
    require_once __DIR__ . '/../config/session.php';
    require_once __DIR__ . '/../config/database.php';
    header('Content-Type: application/json');

    if (!is_logged_in()) {
        http_response_code(401);
        echo json_encode([]);
        exit;
    }

    if ($_GET['action'] === 'notificaciones_recientes') {
        $tipo_map = [
            'sistema'        => 'warning',
            'preinscripcion' => 'success',
            'evento'         => 'info',
            'general'        => 'info',
        ];
        function tiempo_transcurrido_header(string $fecha): string {
            $diff = (new DateTime())->diff(new DateTime($fecha));
            if ($diff->days > 0) return "Hace {$diff->days} día" . ($diff->days > 1 ? 's' : '');
            if ($diff->h   > 0) return "Hace {$diff->h} hora"   . ($diff->h   > 1 ? 's' : '');
            if ($diff->i   > 0) return "Hace {$diff->i} minuto"  . ($diff->i   > 1 ? 's' : '');
            return "Hace unos segundos";
        }
        try {
            $stmt = $pdo->prepare("
                SELECT id, tipo, mensaje, enlace, leida, fecha_creacion
                FROM notificaciones
                WHERE usuario_id = ?
                ORDER BY leida ASC, fecha_creacion DESC
                LIMIT 5
            ");
            $stmt->execute([$_SESSION['user_id']]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $result = array_map(function($n) use ($tipo_map) {
                return [
                    'id'                  => (int)$n['id'],
                    'tipo'                => $tipo_map[$n['tipo']] ?? 'info',
                    'mensaje'             => $n['mensaje'],
                    'url'                 => $n['enlace'] ?? null,
                    'leida'               => (int)$n['leida'],
                    'tiempo_transcurrido' => tiempo_transcurrido_header($n['fecha_creacion']),
                ];
            }, $rows);
            echo json_encode($result);
        } catch (PDOException $e) {
            error_log("notificaciones_recientes: " . $e->getMessage());
            http_response_code(500);
            echo json_encode([]);
        }
        exit;
    }

    if ($_GET['action'] === 'marcar_leida') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false]);
            exit;
        }
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID inválido']);
            exit;
        }
        try {
            $stmt = $pdo->prepare("
                UPDATE notificaciones
                SET leida = 1, fecha_lectura = NOW()
                WHERE id = ? AND usuario_id = ?
            ");
            $stmt->execute([$id, $_SESSION['user_id']]);
            echo json_encode(['success' => $stmt->rowCount() > 0]);
        } catch (PDOException $e) {
            error_log("notificacion_marcar_leida: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false]);
        }
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Acción no reconocida']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Responsive Sidebar with Dropdown Menu by AbdulDev</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0" />
    <link rel="stylesheet" href="/assets/css/colores.css">
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
        color: #f8fafc;
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

    .sidebar-nav .header-dropdown-menu {
        height: 0;
        overflow-y: hidden;
        list-style: none;
        padding-left: 15px;
        transition: height 0.4s ease;
    }

    .sidebar.collapsed .header-dropdown-menu {
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

    .sidebar.collapsed .header-dropdown-menu:has(.dropdown-link) {
        padding: 7px 10px 7px 24px;
    }

    .sidebar.sidebar.collapsed .nav-item:hover>.header-dropdown-menu {
        opacity: 1;
        pointer-events: auto;
        transform: translateY(12px);
        transition: all 0.4s ease;
    }

    .sidebar.sidebar.collapsed .nav-item:hover>.header-dropdown-menu:has(.dropdown-link) {
        transform: translateY(10px);
    }

    .header-dropdown-menu .nav-item .nav-link {
        color: var(--text-primary);
        padding: 9px 15px;
    }

    .sidebar.collapsed .header-dropdown-menu .nav-link {
        padding: 7px 15px;
    }

    .header-dropdown-menu .nav-item .nav-link.dropdown-title {
        display: none;
        color: var(--text-primary);
        padding: 9px 15px;
    }

    .header-dropdown-menu:has(.dropdown-link) .nav-item .dropdown-title {
        font-weight: 500;
        padding: 7px 15px;
    }

    .sidebar.collapsed .header-dropdown-menu .nav-item .dropdown-title {
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
            z-index: 9998;
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

    /* ═══════════════════════════════════════════════════════════
       FAB — Botón flotante inferior derecho
       (tema · notificaciones · ayuda)
    ═══════════════════════════════════════════════════════════ */

    /* Contenedor principal */
    .fab-container {
        position: fixed;
        bottom: 28px;
        right: 28px;
        z-index: 9999;
        display: flex;
        flex-direction: column-reverse; /* los items crecen hacia arriba */
        align-items: flex-end;
        gap: 10px;
        pointer-events: none; /* el contenedor nunca bloquea clics */
    }

    /* Botón principal (hamburguesa del FAB) */
    .fab-main {
        width: 52px;
        height: 52px;
        border-radius: 16px;
        background: var(--primary-blue);
        color: #fff;
        border: none;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.35);
        transition: background 0.3s ease, transform 0.3s ease, border-radius 0.3s ease;
        flex-shrink: 0;
        position: relative;
        z-index: 2;
        pointer-events: auto; /* siempre clickeable */
    }

    .fab-main:hover {
        background: var(--primary-green);
        transform: scale(1.07);
    }

    .fab-main .fab-icon-open,
    .fab-main .fab-icon-close {
        position: absolute;
        font-size: 1.5rem;
        transition: opacity 0.25s ease, transform 0.3s ease;
    }

    .fab-main .fab-icon-close {
        opacity: 0;
        transform: rotate(-90deg);
    }

    .fab-container.open .fab-main {
        background: var(--primary-green);
        border-radius: 50%;
    }

    .fab-container.open .fab-main .fab-icon-open {
        opacity: 0;
        transform: rotate(90deg);
    }

    .fab-container.open .fab-main .fab-icon-close {
        opacity: 1;
        transform: rotate(0deg);
    }

    /* Panel de opciones */
    .fab-panel {
        display: flex;
        flex-direction: column;
        gap: 8px;
        align-items: flex-end;
        /* Estado cerrado */
        opacity: 0;
        pointer-events: none;
        transform: translateY(12px) scale(0.95);
        transform-origin: bottom right;
        transition: opacity 0.25s ease, transform 0.28s cubic-bezier(0.34, 1.56, 0.64, 1);
    }

    .fab-container.open .fab-panel {
        opacity: 1;
        pointer-events: auto;
        transform: translateY(0) scale(1);
    }

    /* Cada ítem del panel */
    .fab-item {
        display: flex;
        align-items: center;
        gap: 10px;
        cursor: pointer;
        text-decoration: none;
    }

    /* Etiqueta de texto */
    .fab-label {
        background: var(--dark-bg);
        color: var(--text-primary);
        font-size: 0.78rem;
        font-weight: 600;
        padding: 5px 12px;
        border-radius: 8px;
        border: 1px solid var(--border-color);
        white-space: nowrap;
        box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        transition: background 0.2s ease;
    }

    .fab-item:hover .fab-label {
        background: var(--hover-bg);
    }

    /* Botón circular de cada ítem */
    .fab-btn {
        width: 44px;
        height: 44px;
        border-radius: 13px;
        border: 1px solid var(--border-color);
        background: var(--dark-bg);
        color: var(--text-primary);
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        box-shadow: 0 2px 12px rgba(0,0,0,0.2);
        transition: all 0.22s ease;
        position: relative;
    }

    .fab-btn .material-symbols-rounded {
        font-size: 1.25rem;
    }

    /* Colores por ítem */
    .fab-item--theme .fab-btn:hover {
        background: var(--subtle-yellow);
        border-color: var(--primary-yellow);
        color: var(--primary-yellow);
        transform: translateY(-2px);
    }

    .fab-item--notif .fab-btn:hover {
        background: var(--subtle-orange);
        border-color: var(--primary-orange);
        color: var(--primary-orange);
        transform: translateY(-2px);
    }

    .fab-item--help .fab-btn:hover {
        background: var(--subtle-blue);
        border-color: var(--primary-blue);
        color: var(--primary-blue);
        transform: translateY(-2px);
    }

    /* Badge de notificaciones sobre el botón */
    .fab-notif-badge {
        position: absolute;
        top: -5px;
        right: -5px;
        min-width: 18px;
        height: 18px;
        padding: 0 4px;
        border-radius: 9px;
        background: var(--primary-orange);
        color: #fff;
        font-size: 0.65rem;
        font-weight: 700;
        display: flex;
        align-items: center;
        justify-content: center;
        border: 2px solid var(--dark-bg);
        animation: pulse-notif 2s infinite;
    }

    /* ── Modal de notificaciones ────────────────────────── */
    .fab-notif-modal {
        position: fixed;
        bottom: 92px;
        right: 28px;
        width: 340px;
        max-height: 440px;
        background: var(--dark-bg);
        border: 1px solid var(--border-color);
        border-radius: 16px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.35);
        z-index: 10000;
        display: flex;
        flex-direction: column;
        overflow: hidden;
        /* Estado cerrado */
        opacity: 0;
        pointer-events: none;
        transform: translateY(10px) scale(0.97);
        transform-origin: bottom right;
        transition: opacity 0.25s ease, transform 0.28s cubic-bezier(0.34, 1.56, 0.64, 1);
    }

    .fab-notif-modal.open {
        opacity: 1;
        pointer-events: auto;
        transform: translateY(0) scale(1);
    }

    .fab-notif-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 16px 18px 12px;
        border-bottom: 1px solid var(--border-color);
        flex-shrink: 0;
    }

    .fab-notif-header h4 {
        font-size: 0.95rem;
        font-weight: 700;
        color: var(--text-primary);
    }

    .fab-notif-header a {
        font-size: 0.75rem;
        color: var(--primary-blue);
        text-decoration: none;
        font-weight: 600;
        transition: opacity 0.2s;
    }

    .fab-notif-header a:hover { opacity: 0.75; }

    .fab-notif-list {
        overflow-y: auto;
        flex: 1;
        scrollbar-width: thin;
        scrollbar-color: var(--border-color) transparent;
    }

    .fab-notif-item {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        padding: 13px 18px;
        border-bottom: 1px solid var(--border-color);
        transition: background 0.2s ease;
        cursor: pointer;
    }

    .fab-notif-item:last-child { border-bottom: none; }

    .fab-notif-item:hover { background: var(--hover-bg); }

    .fab-notif-item.unread {
        background: var(--subtle-blue);
        border-left: 3px solid var(--primary-blue);
    }

    .fab-notif-item.unread:hover { background: var(--subtle-blue); filter: brightness(1.05); }

    .fab-notif-icon {
        width: 34px;
        height: 34px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        background: var(--subtle-blue);
        color: var(--primary-blue);
    }

    .fab-notif-icon .material-symbols-rounded { font-size: 1.1rem; }

    .fab-notif-body { flex: 1; min-width: 0; }

    .fab-notif-text {
        font-size: 0.82rem;
        color: var(--text-primary);
        font-weight: 500;
        line-height: 1.4;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .fab-notif-time {
        font-size: 0.72rem;
        color: var(--text-secondary);
        margin-top: 3px;
    }

    .fab-notif-empty {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 8px;
        padding: 36px 20px;
        color: var(--text-secondary);
        text-align: center;
    }

    .fab-notif-empty .material-symbols-rounded {
        font-size: 2.5rem;
        opacity: 0.35;
    }

    .fab-notif-empty p { font-size: 0.85rem; }

    /* Spinner de carga */
    .fab-notif-loading {
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 32px;
        gap: 10px;
        color: var(--text-secondary);
        font-size: 0.85rem;
    }

    .fab-spinner {
        width: 20px;
        height: 20px;
        border: 2px solid var(--border-color);
        border-top-color: var(--primary-blue);
        border-radius: 50%;
        animation: fab-spin 0.7s linear infinite;
        flex-shrink: 0;
    }

    @keyframes fab-spin {
        to { transform: rotate(360deg); }
    }

    /* ── Tooltip de Ayuda ───────────────────────────────── */
    .fab-help-modal {
        position: fixed;
        bottom: 92px;
        right: 28px;
        width: 300px;
        background: var(--dark-bg);
        border: 1px solid var(--border-color);
        border-radius: 16px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.35);
        z-index: 10000;
        padding: 20px;
        opacity: 0;
        pointer-events: none;
        transform: translateY(10px) scale(0.97);
        transform-origin: bottom right;
        transition: opacity 0.25s ease, transform 0.28s cubic-bezier(0.34, 1.56, 0.64, 1);
    }

    .fab-help-modal.open {
        opacity: 1;
        pointer-events: auto;
        transform: translateY(0) scale(1);
    }

    .fab-help-modal h4 {
        font-size: 0.95rem;
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: 14px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .fab-help-modal h4 .material-symbols-rounded {
        font-size: 1.2rem;
        color: var(--primary-blue);
    }

    .fab-help-links {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .fab-help-link {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 12px;
        border-radius: 10px;
        border: 1px solid var(--border-color);
        background: var(--hover-bg);
        color: var(--text-primary);
        text-decoration: none;
        font-size: 0.83rem;
        font-weight: 500;
        transition: all 0.2s ease;
    }

    .fab-help-link .material-symbols-rounded {
        font-size: 1.1rem;
        color: var(--primary-blue);
        flex-shrink: 0;
    }

    .fab-help-link:hover {
        background: var(--subtle-blue);
        border-color: var(--primary-blue);
        color: var(--primary-blue);
        transform: translateX(3px);
    }

    /* ── Responsive FAB ────────────────────────────────── */
    @media (max-width: 480px) {
        .fab-container {
            bottom: 18px;
            right: 18px;
        }

        .fab-notif-modal,
        .fab-help-modal {
            right: 18px;
            width: calc(100vw - 36px);
        }
    }
</style>

<?php
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
                <img src="/assets/img/3.png" alt="Amimbré">
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
                    <a href="/modules/dashboard/" class="nav-link">
                        <span class="material-symbols-rounded">dashboard</span>
                        <span class="nav-label">Menú principal</span>
                    </a>
                    <ul class="header-dropdown-menu">
                        <li class="nav-item"><a class="nav-link dropdown-title">Menú principal</a></li>
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
                    <ul class="header-dropdown-menu">
                        <li class="nav-item"><a class="nav-link dropdown-title">Inscripciones</a></li>
                        <li class="nav-item"><a href="/modules/inscripciones/prematriculas/index.php" class="nav-link dropdown-link">Prematrículas</a></li>
                        <li class="nav-item"><a href="/modules/inscripciones/matriculas/index.php" class="nav-link dropdown-link">Matrículas</a></li>
                    </ul>
                </li>
                <?php endif; ?>

                <?php if (has_any_role(['admin'])): ?>
                <li class="nav-item">
                    <a href="/modules/usuarios/index.php" class="nav-link">
                        <span class="material-symbols-rounded">group</span>
                        <span class="nav-label">Usuarios</span>
                    </a>
                    <ul class="header-dropdown-menu">
                        <li class="nav-item"><a class="nav-link dropdown-title">Usuarios</a></li>
                    </ul>
                </li>
                <?php endif; ?>

                <?php if (has_any_role(['admin', 'profesor', 'estudiante'])): ?>
                <li class="nav-item">
                    <a href="/modules/cursos/index.php" class="nav-link">
                        <span class="material-symbols-rounded">menu_book</span>
                        <span class="nav-label">Cursos</span>
                    </a>
                    <ul class="header-dropdown-menu">
                        <li class="nav-item"><a class="nav-link dropdown-title">Cursos</a></li>
                    </ul>
                </li>
                <?php endif; ?>

                <?php if (has_any_role(['admin', 'profesor', 'estudiante'])): ?>
                <li class="nav-item dropdown-container">
                    <a href="#" class="nav-link dropdown-toggle">
                        <span class="material-symbols-rounded">folder</span>
                        <span class="nav-label">Documentación</span>
                        <span class="dropdown-icon material-symbols-rounded">keyboard_arrow_down</span>
                    </a>
                    <ul class="header-dropdown-menu">
                        <li class="nav-item"><a class="nav-link dropdown-title">Documentación</a></li>
                        <?php if (has_any_role(['admin', 'profesor'])): ?>
                        <li class="nav-item"><a href="/modules/documentos/administrativos/index.php" class="nav-link dropdown-link">Administrativa</a></li>
                        <?php endif; ?>
                        <li class="nav-item"><a href="/modules/documentos/institucionales/index.php" class="nav-link dropdown-link">Institucional</a></li>
                    </ul>
                </li>
                <?php endif; ?>

                <?php if (has_any_role(['admin', 'profesor'])): ?>
                <li class="nav-item">
                    <a href="/modules/grupos/index.php" class="nav-link">
                        <span class="material-symbols-rounded">group_add</span>
                        <span class="nav-label">Grupos</span>
                    </a>
                    <ul class="header-dropdown-menu">
                        <li class="nav-item"><a class="nav-link dropdown-title">Grupos</a></li>
                    </ul>
                </li>
                <?php endif; ?>

                <?php if (has_any_role(['admin', 'profesor', 'estudiante'])): ?>
                <li class="nav-item">
                    <a href="/modules/notificaciones/index.php" class="nav-link">
                        <span class="material-symbols-rounded">notifications</span>
                        <span class="nav-label">Notificaciones</span>
                        <?php if ($badge_sin_leer > 0): ?>
                            <span class="notif-badge">
                                <?php echo $badge_sin_leer > 99 ? '99+' : $badge_sin_leer; ?>
                            </span>
                        <?php endif; ?>
                    </a>
                    <ul class="header-dropdown-menu">
                        <li class="nav-item"><a class="nav-link dropdown-title">Notificaciones</a></li>
                    </ul>
                </li>
                <?php endif; ?>

                <?php if (has_any_role(['admin'])): ?>
                <li class="nav-item">
                    <a href="/modules/reportes/index.php" class="nav-link">
                        <span class="material-symbols-rounded">assessment</span>
                        <span class="nav-label">Reportes</span>
                    </a>
                    <ul class="header-dropdown-menu">
                        <li class="nav-item"><a class="nav-link dropdown-title">Reportes</a></li>
                    </ul>
                </li>
                <?php endif; ?>

                <?php if (has_any_role(['admin', 'profesor', 'estudiante'])): ?>
                <li class="nav-item">
                    <a href="/modules/horarios/index.php" class="nav-link">
                        <span class="material-symbols-rounded">calendar_today</span>
                        <span class="nav-label">Horario</span>
                    </a>
                    <ul class="header-dropdown-menu">
                        <li class="nav-item"><a class="nav-link dropdown-title">Horario</a></li>
                    </ul>
                </li>
                <?php endif; ?>

                <?php if (has_any_role(['admin', 'profesor', 'estudiante'])): ?>
                <li class="nav-item">
                    <a href="/modules/configuracion/configuraciones.php" class="nav-link">
                        <span class="material-symbols-rounded">settings</span>
                        <span class="nav-label">Configuración</span>
                    </a>
                    <ul class="header-dropdown-menu">
                        <li class="nav-item"><a class="nav-link dropdown-title">Configuración</a></li>
                    </ul>
                </li>
                <?php endif; ?>

            </ul>

            <!-- Secondary Bottom Nav -->
            <ul class="nav-list secondary-nav">
                <li class="nav-item">
                    <a href="/modules/ayuda/ayuda_index.php" class="nav-link">
                        <span class="material-symbols-rounded">help</span>
                        <span class="nav-label">Ayuda</span>
                    </a>
                    <ul class="header-dropdown-menu">
                        <li class="nav-item"><a class="nav-link dropdown-title">Ayuda</a></li>
                    </ul>
                </li>
                <li class="nav-item">
                    <a href="/auth/logout.php" class="nav-link">
                        <span class="material-symbols-rounded">logout</span>
                        <span class="nav-label">Cerrar Sesión</span>
                    </a>
                    <ul class="header-dropdown-menu">
                        <li class="nav-item"><a class="nav-link dropdown-title">Cerrar Sesión</a></li>
                    </ul>
                </li>
            </ul>
        </nav>
    </aside>

    <!-- ═══════════════════════════════════════════════════════════
         FAB — Panel flotante inferior derecho
    ═══════════════════════════════════════════════════════════ -->
    <div class="fab-container" id="fabContainer">

        <!-- Botón principal -->
        <button class="fab-main" id="fabMain" aria-label="Herramientas rápidas">
            <span class="material-symbols-rounded fab-icon-open">widgets</span>
            <span class="material-symbols-rounded fab-icon-close">close</span>
        </button>

        <!-- Panel de ítems (se abre hacia arriba) -->
        <div class="fab-panel" id="fabPanel">

            <!-- Ayuda -->
            <a href="/modules/ayuda/ayuda_index.php" class="fab-item fab-item--help">
                <span class="fab-label">Ayuda</span>
                <div class="fab-btn" aria-label="Ayuda">
                    <span class="material-symbols-rounded">help</span>
                </div>
            </a>

            <!-- Notificaciones -->
            <div class="fab-item fab-item--notif" id="fabNotifBtn">
                <span class="fab-label">Notificaciones</span>
                <button class="fab-btn" aria-label="Notificaciones">
                    <span class="material-symbols-rounded">notifications</span>
                    <?php if ($badge_sin_leer > 0): ?>
                        <span class="fab-notif-badge" id="fabBadge">
                            <?php echo $badge_sin_leer > 99 ? '99+' : $badge_sin_leer; ?>
                        </span>
                    <?php endif; ?>
                </button>
            </div>

            <!-- Tema claro / oscuro -->
            <div class="fab-item fab-item--theme" id="fabThemeBtn">
                <span class="fab-label" id="fabThemeLabel">Tema claro</span>
                <button class="fab-btn" aria-label="Cambiar tema">
                    <span class="material-symbols-rounded" id="fabThemeIcon">light_mode</span>
                </button>
            </div>

        </div>
    </div>

    <!-- Modal de notificaciones -->
    <div class="fab-notif-modal" id="fabNotifModal">
        <div class="fab-notif-header">
            <h4>Notificaciones</h4>
            <a href="/modules/notificaciones/index.php">Ver todas</a>
        </div>
        <div class="fab-notif-list" id="fabNotifList">
            <div class="fab-notif-loading">
                <div class="fab-spinner"></div>
                Cargando…
            </div>
        </div>
    </div>

    <script>
        /* ─────────────────────────────────────────────────────────
           FAB — lógica completa
        ───────────────────────────────────────────────────────── */

        const fabContainer  = document.getElementById('fabContainer');
        const fabMain       = document.getElementById('fabMain');
        const fabPanel      = document.getElementById('fabPanel');
        const fabThemeBtn   = document.getElementById('fabThemeBtn');
        const fabThemeIcon  = document.getElementById('fabThemeIcon');
        const fabThemeLabel = document.getElementById('fabThemeLabel');
        const fabNotifBtn   = document.getElementById('fabNotifBtn');
        const fabNotifModal = document.getElementById('fabNotifModal');
        const fabNotifList  = document.getElementById('fabNotifList');

        /* ── 1. Toggle del panel principal ──────────────────── */
        let fabOpen = false;

        function toggleFab(force) {
            fabOpen = force !== undefined ? force : !fabOpen;
            fabContainer.classList.toggle('open', fabOpen);
            // Al cerrar el panel, cerrar también los modales hijos
            if (!fabOpen) {
                closeAllSubModals();
            }
        }

        fabMain.addEventListener('click', (e) => {
            e.stopPropagation();
            toggleFab();
        });

        /* ── 2. Cerrar al hacer clic fuera ──────────────────── */
        document.addEventListener('click', (e) => {
            if (
                !fabContainer.contains(e.target) &&
                !fabNotifModal.contains(e.target) &&
                !fabHelpModal.contains(e.target)
            ) {
                toggleFab(false);
                closeAllSubModals();
            }
        });

        function closeAllSubModals() {
            fabNotifModal.classList.remove('open');
            fabHelpModal.classList.remove('open');
            notifOpen = false;
            helpOpen  = false;
        }

        /* ── 3. Toggle de tema claro / oscuro ───────────────── */
        // Aplicar tema guardado en localStorage al cargar
        (function applyStoredTheme() {
            const stored = localStorage.getItem('amimbre-theme');
            if (stored === 'light') {
                document.documentElement.setAttribute('data-theme', 'light');
            }
            updateThemeUI();
        })();

        function updateThemeUI() {
            const isLight = document.documentElement.getAttribute('data-theme') === 'light';
            fabThemeIcon.textContent  = isLight ? 'dark_mode'   : 'light_mode';
            fabThemeLabel.textContent = isLight ? 'Tema oscuro' : 'Tema claro';
        }

        fabThemeBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            const isLight = document.documentElement.getAttribute('data-theme') === 'light';
            if (isLight) {
                document.documentElement.removeAttribute('data-theme');
                localStorage.setItem('amimbre-theme', 'dark');
            } else {
                document.documentElement.setAttribute('data-theme', 'light');
                localStorage.setItem('amimbre-theme', 'light');
            }
            updateThemeUI();
        });

        /* ── 4. Modal de notificaciones ─────────────────────── */
        let notifOpen    = false;
        let notifLoaded  = false;

        fabNotifBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            notifOpen = !notifOpen;
            fabNotifModal.classList.toggle('open', notifOpen);
            // Cargar notificaciones la primera vez
            if (notifOpen && !notifLoaded) loadNotificaciones();
        });

        // Evitar que clics dentro del modal lo cierren
        fabNotifModal.addEventListener('click', (e) => e.stopPropagation());

        function loadNotificaciones() {
            fetch('/includes/header.php?action=notificaciones_recientes')
                .then(r => r.json())
                .then(data => {
                    notifLoaded = true;
                    renderNotificaciones(data);
                })
                .catch(() => {
                    fabNotifList.innerHTML = `
                        <div class="fab-notif-empty">
                            <span class="material-symbols-rounded">wifi_off</span>
                            <p>No se pudo conectar</p>
                        </div>`;
                });
        }

        function renderNotificaciones(notifs) {
            if (!notifs || notifs.length === 0) {
                fabNotifList.innerHTML = `
                    <div class="fab-notif-empty">
                        <span class="material-symbols-rounded">notifications_off</span>
                        <p>Sin notificaciones nuevas</p>
                    </div>`;
                return;
            }

            const iconMap = {
                'info'    : { icon: 'info',             bg: 'var(--subtle-blue)',   color: 'var(--primary-blue)'   },
                'warning' : { icon: 'warning',           bg: 'var(--subtle-yellow)', color: 'var(--primary-yellow)' },
                'success' : { icon: 'check_circle',      bg: 'var(--subtle-green)',  color: 'var(--primary-green)'  },
                'danger'  : { icon: 'error',             bg: 'var(--subtle-orange)', color: 'var(--primary-orange)' },
            };

            fabNotifList.innerHTML = notifs.map(n => {
                const t   = iconMap[n.tipo] || iconMap['info'];
                const url = n.url ? `href="${n.url}"` : '';
                return `
                <a ${url} class="fab-notif-item ${n.leida == 0 ? 'unread' : ''}"
                   style="text-decoration:none;"
                   data-id="${n.id}">
                    <div class="fab-notif-icon"
                         style="background:${t.bg}; color:${t.color};">
                        <span class="material-symbols-rounded">${t.icon}</span>
                    </div>
                    <div class="fab-notif-body">
                        <div class="fab-notif-text">${escHtml(n.mensaje)}</div>
                        <div class="fab-notif-time">${escHtml(n.tiempo_transcurrido ?? '')}</div>
                    </div>
                </a>`;
            }).join('');

            // Marcar como leída al hacer clic
            fabNotifList.querySelectorAll('.fab-notif-item[data-id]').forEach(el => {
                el.addEventListener('click', () => {
                    const id = el.dataset.id;
                    el.classList.remove('unread');
                    fetch(`/includes/header.php?action=marcar_leida&id=${id}`, { method: 'POST' });
                    // Reducir badge
                    const badge = document.getElementById('fabBadge');
                    if (badge) {
                        let count = parseInt(badge.textContent) || 0;
                        count = Math.max(0, count - 1);
                        badge.textContent = count > 99 ? '99+' : count;
                        if (count === 0) badge.remove();
                    }
                });
            });
        }

        /* ── Utilidad ────────────────────────────────────────── */
        function escHtml(str) {
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        /* ─────────────────────────────────────────────────────────
           Sidebar — lógica original (sin cambios)
        ───────────────────────────────────────────────────────── */
        const toggleDropdown = (dropdown, menu, isOpen) => {
            dropdown.classList.toggle("open", isOpen);
            menu.style.height = isOpen ? `${menu.scrollHeight}px` : 0;
        };

        const closeAllDropdowns = () => {
            document.querySelectorAll(".dropdown-container.open").forEach((openDropdown) => {
                toggleDropdown(openDropdown, openDropdown.querySelector(".header-dropdown-menu"), false);
            });
        };

        document.querySelectorAll(".dropdown-toggle").forEach((dropdownToggle) => {
            dropdownToggle.addEventListener("click", (e) => {
                e.preventDefault();
                const dropdown = dropdownToggle.closest(".dropdown-container");
                const menu = dropdown.querySelector(".header-dropdown-menu");
                const isOpen = dropdown.classList.contains("open");
                closeAllDropdowns();
                toggleDropdown(dropdown, menu, !isOpen);
            });
        });

        function syncMenuButton() {
            if (window.innerWidth > 768) return;
            const collapsed = document.querySelector(".sidebar").classList.contains("collapsed");
            document.querySelector(".sidebar-menu-button").style.display = collapsed ? "flex" : "none";
        }

        document.querySelectorAll(".sidebar-toggler, .sidebar-menu-button").forEach((button) => {
            button.addEventListener("click", () => {
                closeAllDropdowns();
                document.querySelector(".sidebar").classList.toggle("collapsed");
                syncMenuButton();
            });
        });

        if (window.innerWidth <= 1024) document.querySelector(".sidebar").classList.add("collapsed");
        syncMenuButton();
        window.addEventListener("resize", syncMenuButton);
    </script>
</body>

</html>