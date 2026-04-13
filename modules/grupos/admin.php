<?php

require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth_check.php';
require_role('admin');


$success = null;
$error   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cambiar_estado') {
    $id_grupo     = (int)($_POST['id']           ?? 0);
    $nuevo_estado = $_POST['nuevo_estado']        ?? '';
    $estados_validos = ['planificado','activo','finalizado','cancelado'];

    if ($id_grupo && in_array($nuevo_estado, $estados_validos)) {
        try {
            $pdo->prepare("UPDATE grupos SET estado = ? WHERE id = ?")
                ->execute([$nuevo_estado, $id_grupo]);
            $success = "Estado del grupo actualizado correctamente.";
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $error = "Error al actualizar el estado.";
        }
    }
}


try {
    // Filtros GET
    $filtro_estado = $_GET['estado'] ?? '';
    $filtro_buscar = trim($_GET['buscar'] ?? '');

    $where  = [];
    $params = [];

    if ($filtro_estado) {
        $where[]  = "g.estado = ?";
        $params[] = $filtro_estado;
    }
    if ($filtro_buscar) {
        $where[]  = "(g.nombre LIKE ? OR c.nombre LIKE ? OR u.nombre LIKE ?)";
        $params[] = "%$filtro_buscar%";
        $params[] = "%$filtro_buscar%";
        $params[] = "%$filtro_buscar%";
    }

    $sql_where = $where ? "WHERE " . implode(" AND ", $where) : "";

    $stmt = $pdo->prepare("
        SELECT
            g.*,
            c.nombre AS curso_nombre,
            c.nivel  AS curso_nivel,
            u.nombre AS profesor_nombre
        FROM grupos g
        JOIN cursos c        ON g.curso_id   = c.id
        LEFT JOIN usuarios u ON g.profesor_id = u.id
        $sql_where
        ORDER BY g.id DESC
    ");
    $stmt->execute($params);
    $grupos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Totales por estado para los chips
    $totales = [];
    foreach ($pdo->query("SELECT estado, COUNT(*) AS n FROM grupos GROUP BY estado")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $totales[$r['estado']] = $r['n'];
    }
    $total_general = array_sum($totales);

} catch (PDOException $e) {
    error_log($e->getMessage());
    $grupos = [];
    $totales = [];
    $total_general = 0;
}

$estado_cfg = [
    'planificado' => ['cls' => 'badge-info',    'txt' => 'Planificado', 'icon' => 'schedule'],
    'activo'      => ['cls' => 'badge-success',  'txt' => 'Activo',      'icon' => 'play_circle'],
    'finalizado'  => ['cls' => 'badge-warning',  'txt' => 'Finalizado',  'icon' => 'check_circle'],
    'cancelado'   => ['cls' => 'badge-danger',   'txt' => 'Cancelado',   'icon' => 'cancel'],
];
$nivel_cfg = [
    'basico'     => 'badge-info',
    'intermedio' => 'badge-warning',
    'avanzado'   => 'badge-danger',
];

date_default_timezone_set('America/Bogota');
$dias  = ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'];
$meses = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$fecha_hoy = $dias[date('w')] . ', ' . date('d') . ' de ' . $meses[date('n')] . ' de ' . date('Y');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Grupos – Amimbré</title>
    <link rel="shortcut icon" href="../../assets/img/3.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,1,0">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="../../assets/css/colores.css">
    <link rel="stylesheet" href="../../assets/css/style-grupos.css">
    <script>
        (function(){
            const t = localStorage.getItem('amimbre-theme');
            if (t === 'light') document.documentElement.setAttribute('data-theme','light');
        })();
    </script>
</head>
<body>

<?php require_once '../../includes/header.php'; ?>

<main class="main-content">

    <div class="dashboard-header">
        <div class="dashboard-title">
            <h1>Gestión de Grupos</h1>
            <p>Creación y control de secciones académicas</p>
        </div>
        <a href="crear.php" class="btn-submit">
            <span class="material-symbols-rounded">add_circle</span> Nuevo grupo
        </a>
    </div>

    <?php if ($success): ?>
    <div class="alert alert-success">
        <span class="material-symbols-rounded">check_circle</span>
        <?php echo $success; ?>
    </div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert alert-danger">
        <span class="material-symbols-rounded">error</span>
        <?php echo $error; ?>
    </div>
    <?php endif; ?>

    <div class="modulo-stats">
        <div class="modulo-stat-chip total">
            <span class="material-symbols-rounded">layers</span>
            <div>
                <span class="chip-value"><?php echo $total_general; ?></span>
                <span class="chip-label">Total grupos</span>
            </div>
        </div>
        <?php foreach ($estado_cfg as $est => $cfg): ?>
        <div class="modulo-stat-chip <?php echo $est; ?>">
            <span class="material-symbols-rounded"><?php echo $cfg['icon']; ?></span>
            <div>
                <span class="chip-value"><?php echo $totales[$est] ?? 0; ?></span>
                <span class="chip-label"><?php echo $cfg['txt']; ?>s</span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="card">

        <div class="section-header">
            <div>
                <h3 class="section-title">Grupos Registrados</h3>
                <p class="section-subtitle"><?php echo count($grupos); ?> resultado<?php echo count($grupos) != 1 ? 's' : ''; ?></p>
            </div>
        </div>

        <form method="GET" class="filtros-bar">
            <div class="filtros-buscar">
                <span class="material-symbols-rounded">search</span>
                <input type="text" name="buscar"
                       value="<?php echo htmlspecialchars($filtro_buscar); ?>"
                       placeholder="Buscar por nombre, curso o profesor...">
            </div>
            <select name="estado" onchange="this.form.submit()">
                <option value="">Todos los estados</option>
                <?php foreach ($estado_cfg as $est => $cfg): ?>
                <option value="<?php echo $est; ?>" <?php echo $filtro_estado === $est ? 'selected' : ''; ?>>
                    <?php echo $cfg['txt']; ?>
                </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn-filtrar">
                <span class="material-symbols-rounded">filter_list</span>
            </button>
            <?php if ($filtro_estado || $filtro_buscar): ?>
            <a href="admin.php" class="btn-limpiar" title="Limpiar filtros">
                <span class="material-symbols-rounded">close</span>
            </a>
            <?php endif; ?>
        </form>

        <?php if (count($grupos) > 0): ?>
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Grupo</th>
                        <th>Curso</th>
                        <th>Profesor</th>
                        <th>Horario / Aula</th>
                        <th>Cupos</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($grupos as $g):
                        $est = $estado_cfg[$g['estado']] ?? $estado_cfg['planificado'];
                        $niv = $nivel_cfg[strtolower($g['curso_nivel'])] ?? 'badge-info';
                        $pct = $g['cupo_maximo'] > 0 ? round(($g['cupo_actual'] / $g['cupo_maximo']) * 100) : 0;
                        $bar = $pct >= 90 ? 'bar-danger' : ($pct >= 70 ? 'bar-warning' : 'bar-success');
                    ?>
                    <tr>
                        <td style="color:var(--text-secondary); font-size:0.8rem;">#<?php echo $g['id']; ?></td>

                        <td>
                            <strong><?php echo htmlspecialchars($g['nombre']); ?></strong>
                            <div style="font-size:0.75rem; color:var(--text-secondary); margin-top:2px;">
                                Desde <?php echo date('d/m/Y', strtotime($g['fecha_inicio'])); ?>
                                <?php if ($g['fecha_fin']): ?>
                                · Hasta <?php echo date('d/m/Y', strtotime($g['fecha_fin'])); ?>
                                <?php endif; ?>
                            </div>
                        </td>

                        <td>
                            <?php echo htmlspecialchars($g['curso_nombre']); ?>
                            <div style="margin-top:3px;">
                                <span class="badge <?php echo $niv; ?>"><?php echo ucfirst($g['curso_nivel']); ?></span>
                            </div>
                        </td>

                        <td>
                            <?php echo $g['profesor_nombre']
                                ? htmlspecialchars($g['profesor_nombre'])
                                : '<span style="color:var(--text-secondary);font-size:0.82rem;">Sin asignar</span>'; ?>
                        </td>

                        <td>
                            <div style="font-size:0.85rem;"><?php echo htmlspecialchars($g['horario'] ?: '—'); ?></div>
                            <?php if ($g['aula']): ?>
                            <div style="font-size:0.75rem; color:var(--text-secondary); margin-top:2px;">
                                <span class="material-symbols-rounded" style="font-size:13px; vertical-align:middle;">meeting_room</span>
                                <?php echo htmlspecialchars($g['aula']); ?>
                            </div>
                            <?php endif; ?>
                        </td>

                        <td>
                            <div class="cupo-cell">
                                <span><?php echo $g['cupo_actual']; ?>/<?php echo $g['cupo_maximo']; ?></span>
                                <div class="group-bar" style="width:80px;">
                                    <div class="group-bar-fill <?php echo $bar; ?>" style="width:<?php echo $pct; ?>%"></div>
                                </div>
                            </div>
                        </td>

                        <td>
                            <span class="badge <?php echo $est['cls']; ?>"><?php echo $est['txt']; ?></span>
                        </td>

                        <td>
                            <div class="table-actions">

                    
                                <a href="ver.php?id=<?php echo $g['id']; ?>"
                                   class="tbl-btn view" title="Ver detalle">
                                    <span class="material-symbols-rounded">visibility</span>
                                </a>

                     
                                <a href="editar.php?id=<?php echo $g['id']; ?>"
                                   class="tbl-btn edit" title="Editar grupo">
                                    <span class="material-symbols-rounded">edit</span>
                                </a>

                       
                                <div class="dropdown-estado">
                                    <button type="button" class="tbl-btn estado" title="Cambiar estado">
                                        <span class="material-symbols-rounded">swap_horiz</span>
                                    </button>
                                    <div class="dropdown-estado-menu">
                                        <?php foreach ($estado_cfg as $est_k => $est_v):
                                            if ($est_k === $g['estado']) continue; ?>
                                        <form method="POST" style="margin:0;">
                                            <input type="hidden" name="action"       value="cambiar_estado">
                                            <input type="hidden" name="id"           value="<?php echo $g['id']; ?>">
                                            <input type="hidden" name="nuevo_estado" value="<?php echo $est_k; ?>">
                                            <button type="submit" class="estado-option">
                                                <span class="material-symbols-rounded"><?php echo $est_v['icon']; ?></span>
                                                <?php echo $est_v['txt']; ?>
                                            </button>
                                        </form>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <a href="eliminar.php?id=<?php echo $g['id']; ?>"
                                   class="tbl-btn delete" title="Eliminar grupo">
                                    <span class="material-symbols-rounded">delete</span>
                                </a>

                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php else: ?>
        <div class="empty-state">
            <span class="material-symbols-rounded">layers</span>
            <p>
                <?php echo ($filtro_estado || $filtro_buscar)
                    ? 'No hay grupos con esos filtros.'
                    : 'Aún no hay grupos registrados.'; ?>
            </p>
            <?php if ($filtro_estado || $filtro_buscar): ?>
            <a href="admin.php" style="color:var(--primary-green); font-size:0.85rem; margin-top:8px;">Limpiar filtros</a>
            <?php else: ?>
            <a href="crear.php" class="btn-submit" style="margin-top:12px;">
                <span class="material-symbols-rounded">add_circle</span> Crear primer grupo
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div>

</main>

<script>
// Dropdown cambiar estado
document.querySelectorAll('.dropdown-estado').forEach(dd => {
    const btn  = dd.querySelector('.tbl-btn.estado');
    const menu = dd.querySelector('.dropdown-estado-menu');
    btn.addEventListener('click', e => {
        e.stopPropagation();
        document.querySelectorAll('.dropdown-estado-menu.open').forEach(m => {
            if (m !== menu) m.classList.remove('open');
        });
        menu.classList.toggle('open');
    });
});
document.addEventListener('click', () => {
    document.querySelectorAll('.dropdown-estado-menu.open').forEach(m => m.classList.remove('open'));
});

// Auto-ocultar alertas
setTimeout(() => {
    document.querySelectorAll('.alert').forEach(a => {
        a.style.transition = 'opacity 0.5s ease';
        a.style.opacity = '0';
        setTimeout(() => a.remove(), 500);
    });
}, 4000);
</script>

</body>
</html>