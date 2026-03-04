<?php
/**
 * Módulo de Matrículas
 * Gestión de estudiantes matriculados, grupos y registro de pagos
 */

require_once '../../../config/session.php';
require_once '../../../config/database.php';
require_once '../../../includes/auth_check.php';

require_role('admin');

try {
    $stmt = $pdo->prepare("SELECT id, nombre, email, rol, estado, foto_perfil FROM usuarios WHERE id = ? AND estado = 'activo'");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) { session_destroy(); header("Location: ../../../auth/login.php"); exit; }
} catch (PDOException $e) { die("Error del sistema."); }

// Parámetros de filtro y búsqueda
$buscar       = trim($_GET['buscar'] ?? '');
$filtro_estado = $_GET['estado'] ?? '';
$filtro_pago   = $_GET['pago'] ?? '';
$pagina        = max(1, (int)($_GET['pagina'] ?? 1));
$por_pagina    = 10;
$offset        = ($pagina - 1) * $por_pagina;

// Construir query con filtros
$where_parts = ["m.estado != 'retirado'"];
$params = [];

if ($buscar !== '') {
    $where_parts[] = "(u.nombre LIKE ? OR u.email LIKE ? OR c.nombre LIKE ? OR u.documento LIKE ?)";
    $like = "%$buscar%";
    $params = array_merge($params, [$like, $like, $like, $like]);
}
if ($filtro_estado !== '') {
    $where_parts[] = "m.estado = ?";
    $params[] = $filtro_estado;
}

$where_sql = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';

// Si hay filtro de pago, lo manejamos después con subquery
$having_sql = '';
if ($filtro_pago === 'al_dia') {
    $having_sql = 'HAVING pagos_pendientes = 0';
} elseif ($filtro_pago === 'pendiente') {
    $having_sql = 'HAVING pagos_pendientes > 0';
}

try {
    // Estadísticas generales
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM matriculas WHERE estado = 'activa'");
    $total_activas = $stmt->fetch()['total'] ?? 0;

    $stmt = $pdo->query("
        SELECT COUNT(DISTINCT m.id) as total
        FROM matriculas m
        LEFT JOIN pagos p ON p.matricula_id = m.id AND p.estado = 'pendiente'
        WHERE m.estado = 'activa'
        GROUP BY m.id
        HAVING COUNT(p.id) = 0
    ");
    $total_al_dia = $stmt->rowCount();

    $stmt = $pdo->query("
        SELECT COUNT(DISTINCT m.id) as total
        FROM matriculas m
        INNER JOIN pagos p ON p.matricula_id = m.id AND p.estado IN ('pendiente','vencido')
        WHERE m.estado = 'activa'
    ");
    $total_con_pendientes = $stmt->fetch()['total'] ?? 0;

    // Query principal de matrículas
    $sql_count = "
        SELECT COUNT(*) 
        FROM (
            SELECT m.id
            FROM matriculas m
            INNER JOIN usuarios u ON m.estudiante_id = u.id
            LEFT JOIN grupos g ON m.grupo_id = g.id
            LEFT JOIN cursos c ON g.curso_id = c.id
            LEFT JOIN pagos p ON p.matricula_id = m.id AND p.estado IN ('pendiente','vencido')
            $where_sql
            GROUP BY m.id
            $having_sql
        ) sub
    ";
    $stmt = $pdo->prepare($sql_count);
    $stmt->execute($params);
    $total_registros = $stmt->fetchColumn();
    $total_paginas = max(1, ceil($total_registros / $por_pagina));
    $pagina = min($pagina, $total_paginas);

    $sql = "
        SELECT 
            m.id,
            m.fecha_matricula,
            m.fecha_inicio,
            m.estado,
            m.observaciones,
            m.preinscripcion_id,
            u.id as estudiante_id,
            u.nombre as estudiante_nombre,
            u.email as estudiante_email,
            u.documento as estudiante_documento,
            u.telefono as estudiante_telefono,
            g.id as grupo_id,
            g.nombre as grupo_nombre,
            g.horario as grupo_horario,
            c.id as curso_id,
            c.nombre as curso_nombre,
            c.nivel as curso_nivel,
            c.precio_mensual,
            prof.nombre as profesor_nombre,
            COUNT(DISTINCT p.id) as pagos_pendientes,
            COUNT(DISTINCT pa.id) as total_pagos
        FROM matriculas m
        INNER JOIN usuarios u ON m.estudiante_id = u.id
        LEFT JOIN grupos g ON m.grupo_id = g.id
        LEFT JOIN cursos c ON g.curso_id = c.id
        LEFT JOIN usuarios prof ON g.profesor_id = prof.id
        LEFT JOIN pagos p ON p.matricula_id = m.id AND p.estado IN ('pendiente','vencido')
        LEFT JOIN pagos pa ON pa.matricula_id = m.id
        $where_sql
        GROUP BY m.id, m.fecha_matricula, m.fecha_inicio, m.estado, m.observaciones,
                 m.preinscripcion_id, u.id, u.nombre, u.email, u.documento, u.telefono,
                 g.id, g.nombre, g.horario, c.id, c.nombre, c.nivel, c.precio_mensual, prof.nombre
        $having_sql
        ORDER BY m.fecha_matricula DESC
        LIMIT $por_pagina OFFSET $offset
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $matriculas = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Error en matrículas: " . $e->getMessage());
    $matriculas = [];
    $total_activas = $total_al_dia = $total_con_pendientes = 0;
    $total_registros = $total_paginas = 0;
}

function badge_estado($estado) {
    $map = [
        'activa'     => ['label' => 'Activa',     'class' => 'badge-success'],
        'suspendida' => ['label' => 'Suspendida', 'class' => 'badge-warning'],
        'graduado'   => ['label' => 'Graduado',   'class' => 'badge-info'],
        'retirado'   => ['label' => 'Retirado',   'class' => 'badge-danger'],
    ];
    return $map[$estado] ?? ['label' => ucfirst($estado), 'class' => 'badge-secondary'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Matrículas - Amimbré</title>
    <link rel="shortcut icon" href="../../../assets/img/3.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0" />
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="../../../assets/css/colores.css">
    <link rel="stylesheet" href="../../../assets/css/style-matriculas.css">
</head>
<body>
    <?php require_once '../../../includes/header.php'; ?>

    <main class="main-content">
        <!-- Header de página -->
        <div class="dashboard-header">
            <div class="dashboard-title">
                <h1>Matrículas</h1>
                <p>Gestiona las matrículas activas y su estado de pagos</p>
            </div>
            <div class="header-actions">
                <a href="nueva.php" class="btn-primary">
                    <span class="material-symbols-rounded">add</span>
                    Nueva Matrícula
                </a>
            </div>
        </div>

        <!-- Tarjetas de estadísticas -->
        <div class="stats-grid">
            <div class="stat-card stat-blue">
                <div class="stat-icon-wrap">
                    <span class="material-symbols-rounded">person</span>
                </div>
                <div class="stat-info">
                    <span class="stat-value"><?php echo $total_activas; ?></span>
                    <span class="stat-label">Matrículas activas</span>
                </div>
            </div>
            <div class="stat-card stat-green">
                <div class="stat-icon-wrap">
                    <span class="material-symbols-rounded">check_circle</span>
                </div>
                <div class="stat-info">
                    <span class="stat-value"><?php echo $total_al_dia; ?></span>
                    <span class="stat-label">Al día en pagos</span>
                </div>
            </div>
            <div class="stat-card stat-yellow">
                <div class="stat-icon-wrap">
                    <span class="material-symbols-rounded">warning</span>
                </div>
                <div class="stat-info">
                    <span class="stat-value"><?php echo $total_con_pendientes; ?></span>
                    <span class="stat-label">Con pagos pendientes</span>
                </div>
            </div>
        </div>

        <!-- Buscador y filtros -->
        <div class="filters-bar">
            <form method="GET" class="filters-form" id="filtrosForm">
                <div class="search-input-wrap">
                    <span class="material-symbols-rounded search-icon">search</span>
                    <input
                        type="text"
                        name="buscar"
                        class="search-input"
                        placeholder="Buscar por nombre, email, documento o curso..."
                        value="<?php echo htmlspecialchars($buscar); ?>"
                        autocomplete="off"
                    >
                    <?php if ($buscar): ?>
                        <button type="button" class="clear-search" onclick="clearSearch()">
                            <span class="material-symbols-rounded">close</span>
                        </button>
                    <?php endif; ?>
                </div>
                <div class="filters-right">
                    <select name="estado" class="filter-select" onchange="this.form.submit()">
                        <option value="">Todos los estados</option>
                        <option value="activa"     <?php echo $filtro_estado === 'activa'     ? 'selected' : ''; ?>>Activa</option>
                        <option value="suspendida" <?php echo $filtro_estado === 'suspendida' ? 'selected' : ''; ?>>Suspendida</option>
                        <option value="graduado"   <?php echo $filtro_estado === 'graduado'   ? 'selected' : ''; ?>>Graduado</option>
                    </select>
                    <select name="pago" class="filter-select" onchange="this.form.submit()">
                        <option value="">Todos los pagos</option>
                        <option value="al_dia"   <?php echo $filtro_pago === 'al_dia'   ? 'selected' : ''; ?>>Al día</option>
                        <option value="pendiente" <?php echo $filtro_pago === 'pendiente' ? 'selected' : ''; ?>>Con pendientes</option>
                    </select>
                    <button type="submit" class="btn-search">
                        <span class="material-symbols-rounded">filter_list</span>
                        Filtrar
                    </button>
                </div>
            </form>
        </div>

        <!-- Lista de matrículas -->
        <div class="matriculas-container">
            <?php if (empty($matriculas)): ?>
                <div class="empty-state">
                    <span class="material-symbols-rounded empty-icon">search_off</span>
                    <h3>No se encontraron matrículas</h3>
                    <p>Intenta ajustar los filtros o realiza una nueva búsqueda</p>
                    <?php if ($buscar || $filtro_estado || $filtro_pago): ?>
                        <a href="index.php" class="btn-secondary">Limpiar filtros</a>
                    <?php else: ?>
                        <a href="nueva.php" class="btn-primary">
                            <span class="material-symbols-rounded">add</span>
                            Crear primera matrícula
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <?php foreach($matriculas as $m): ?>
                    <?php $badge = badge_estado($m['estado']); ?>
                    <div class="matricula-card">
                        <div class="matricula-avatar">
                            <span class="material-symbols-rounded">person</span>
                        </div>
                        <div class="matricula-info">
                            <div class="matricula-top">
                                <div class="matricula-nombre-wrap">
                                    <h3 class="matricula-nombre"><?php echo htmlspecialchars($m['estudiante_nombre']); ?></h3>
                                    <span class="badge <?php echo $badge['class']; ?>"><?php echo $badge['label']; ?></span>
                                    <?php if ($m['pagos_pendientes'] > 0): ?>
                                        <span class="badge badge-warning-soft">
                                            <span class="material-symbols-rounded" style="font-size:14px;">receipt_long</span>
                                            <?php echo $m['pagos_pendientes']; ?> pago<?php echo $m['pagos_pendientes'] > 1 ? 's' : ''; ?> pendiente<?php echo $m['pagos_pendientes'] > 1 ? 's' : ''; ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-success-soft">
                                            <span class="material-symbols-rounded" style="font-size:14px;">check_circle</span>
                                            Al día
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <p class="matricula-email"><?php echo htmlspecialchars($m['estudiante_email']); ?></p>
                            </div>
                            <div class="matricula-meta">
                                <?php if ($m['curso_nombre']): ?>
                                    <span class="meta-item">
                                        <span class="material-symbols-rounded">music_note</span>
                                        <?php echo htmlspecialchars($m['curso_nombre']); ?>
                                        <?php if ($m['grupo_nombre']): ?>
                                            — <?php echo htmlspecialchars($m['grupo_nombre']); ?>
                                        <?php endif; ?>
                                    </span>
                                <?php else: ?>
                                    <span class="meta-item meta-warning">
                                        <span class="material-symbols-rounded">warning</span>
                                        Sin grupo asignado
                                    </span>
                                <?php endif; ?>
                                <span class="meta-item">
                                    <span class="material-symbols-rounded">calendar_today</span>
                                    Desde <?php echo date('d/m/Y', strtotime($m['fecha_matricula'])); ?>
                                </span>
                                <?php if ($m['profesor_nombre']): ?>
                                    <span class="meta-item">
                                        <span class="material-symbols-rounded">school</span>
                                        <?php echo htmlspecialchars($m['profesor_nombre']); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="matricula-actions">
                            <a href="detalle.php?id=<?php echo $m['id']; ?>" class="btn-action btn-view" title="Ver detalles">
                                <span class="material-symbols-rounded">visibility</span>
                                Ver detalles
                            </a>
                            <button
                                class="btn-action btn-danger"
                                title="Cancelar matrícula"
                                onclick="confirmarCancelacion(<?php echo $m['id']; ?>, '<?php echo htmlspecialchars(addslashes($m['estudiante_nombre'])); ?>')"
                            >
                                <span class="material-symbols-rounded">cancel</span>
                                Cancelar
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Paginación -->
        <?php if ($total_paginas > 1): ?>
        <div class="pagination">
            <span class="pag-info">
                Mostrando <?php echo min($offset + 1, $total_registros); ?>–<?php echo min($offset + $por_pagina, $total_registros); ?> de <?php echo $total_registros; ?>
            </span>
            <div class="pag-controls">
                <?php if ($pagina > 1): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $pagina - 1])); ?>" class="pag-btn">
                        <span class="material-symbols-rounded">chevron_left</span>
                    </a>
                <?php endif; ?>
                <?php for ($i = max(1, $pagina - 2); $i <= min($total_paginas, $pagina + 2); $i++): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $i])); ?>"
                       class="pag-btn <?php echo $i === $pagina ? 'active' : ''; ?>">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>
                <?php if ($pagina < $total_paginas): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $pagina + 1])); ?>" class="pag-btn">
                        <span class="material-symbols-rounded">chevron_right</span>
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </main>

    <!-- Modal de confirmación cancelación -->
    <div class="modal-overlay" id="modalCancelacion">
        <div class="modal">
            <div class="modal-icon modal-icon-danger">
                <span class="material-symbols-rounded">cancel</span>
            </div>
            <h3 class="modal-title">Cancelar Matrícula</h3>
            <p class="modal-desc">¿Estás seguro de que deseas cancelar la matrícula de <strong id="nombreEstudiante"></strong>? Esta acción cambiará el estado a retirado.</p>
            <div class="modal-actions">
                <button class="btn-secondary" onclick="cerrarModal()">Cancelar</button>
                <form method="POST" action="acciones.php" id="formCancelacion">
                    <input type="hidden" name="accion" value="cancelar">
                    <input type="hidden" name="matricula_id" id="matriculaIdCancelar">
                    <button type="submit" class="btn-danger-solid">Sí, cancelar matrícula</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        function confirmarCancelacion(id, nombre) {
            document.getElementById('nombreEstudiante').textContent = nombre;
            document.getElementById('matriculaIdCancelar').value = id;
            document.getElementById('modalCancelacion').classList.add('active');
        }
        function cerrarModal() {
            document.getElementById('modalCancelacion').classList.remove('active');
        }
        document.getElementById('modalCancelacion').addEventListener('click', function(e) {
            if (e.target === this) cerrarModal();
        });
        function clearSearch() {
            const form = document.getElementById('filtrosForm');
            form.querySelector('[name="buscar"]').value = '';
            form.submit();
        }
        // Auto-submit búsqueda con debounce
        let searchTimer;
        document.querySelector('.search-input')?.addEventListener('input', function() {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => {
                document.getElementById('filtrosForm').submit();
            }, 600);
        });
    </script>
</body>
</html>