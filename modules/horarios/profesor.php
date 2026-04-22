<?php
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth_check.php';
require_role('profesor');

date_default_timezone_set('America/Bogota');
$meses = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
$dias_semana_nombres = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];

$mes_actual = date('n');
$anio_actual = date('Y');
$hoy = date('j');
$user_id = $_SESSION['user_id'];

try {
    // Estadísticas: Total de sesiones semanales asignadas al profesor
    $stmt = $pdo->prepare("SELECT COUNT(h.id) as total FROM horarios h JOIN grupos g ON h.grupo_id = g.id WHERE g.profesor_id = ?");
    $stmt->execute([$user_id]);
    $clases_semana = $stmt->fetch()['total'] ?? 0;

    // Obtener la siguiente clase del día actual
    $dia_hoy_nombre = strtolower($dias_semana_nombres[date('N') - 1]);
    $stmt = $pdo->prepare("
        SELECT c.nombre as nombre_curso, h.hora_inicio 
        FROM horarios h
        JOIN grupos g ON h.grupo_id = g.id
        JOIN cursos c ON g.curso_id = c.id
        WHERE g.profesor_id = ? AND h.dia_semana = ? AND h.hora_inicio >= CURTIME()
        ORDER BY h.hora_inicio ASC LIMIT 1
    ");
    $stmt->execute([$user_id, $dia_hoy_nombre]);
    $proxima_clase = $stmt->fetch();

    // Obtener todos los horarios del profesor para el mapa del calendario
    $stmt = $pdo->prepare("
        SELECT h.id, h.dia_semana, h.hora_inicio, h.hora_fin, c.nombre as nombre_curso, h.aula, g.nombre as nombre_grupo
        FROM horarios h
        JOIN grupos g ON h.grupo_id = g.id
        JOIN cursos c ON g.curso_id = c.id
        WHERE g.profesor_id = ? AND g.estado = 'activo'
        ORDER BY h.hora_inicio ASC
    ");
    $stmt->execute([$user_id]);
    $eventos_db = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $mapa_eventos = [];
    foreach ($eventos_db as $ev) {
        $mapa_eventos[strtolower($ev['dia_semana'])][] = $ev;
    }
} catch (PDOException $e) {
    error_log($e->getMessage());
}

$eventos_json = json_encode($mapa_eventos, JSON_UNESCAPED_UNICODE);
$primer_dia_mes = date('N', strtotime("$anio_actual-$mes_actual-01"));
$dias_en_mes = date('t', strtotime("$anio_actual-$mes_actual-01"));
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Mi Agenda - Amimbré</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0" />
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="../../assets/css/colores.css">
    <link rel="stylesheet" href="../../assets/css/style-horariosProfe.css">
    <style>
        .day {
            cursor: pointer;
            transition: transform 0.15s;
        }

        .day:not(.empty):hover {
            border-color: var(--primary-orange);
            transform: translateY(-2px);
        }

        .event-tag-single {
            font-size: 0.7rem;
            padding: 4px 8px;
            border-radius: 6px;
            margin-top: 5px;
            background: var(--subtle-orange);
            color: var(--primary-orange);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            border-left: 3px solid var(--primary-orange);
            pointer-events: none;
        }

        .event-more-badge {
            display: inline-block;
            margin-top: 4px;
            font-size: 0.68rem;
            color: var(--text-secondary);
            background: var(--border-color);
            border-radius: 20px;
            padding: 2px 8px;
            font-weight: 500;
            pointer-events: none;
        }

        .modal-day-list {
            list-style: none;
            padding: 0;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .modal-day-item {
            display: flex;
            align-items: center;
            padding: 12px 15px;
            background: var(--hover-bg);
            border-radius: 10px;
            border-left: 4px solid var(--primary-orange);
        }

        .modal-day-item-info {
            display: flex;
            flex-direction: column;
            flex: 1;
        }

        .modal-day-item-curso {
            font-weight: 600;
            font-size: 0.9rem;
            color: var(--text-primary);
        }

        .modal-day-item-meta {
            font-size: 0.8rem;
            color: var(--text-secondary);
        }

        /* Estilos para el Modal */
        .modal {
            display: none;
            /* Oculto por defecto */
            position: fixed;
            z-index: 2000;
            /* Por encima de todo */
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            /* Fondo oscuro semitransparente */
            backdrop-filter: blur(4px);
            /* Efecto de desenfoque al fondo */
            animation: fadeIn 0.3s ease;
        }

        .modal-content {
            background-color: var(--card-bg);
            margin: 10% auto;
            /* 10% desde arriba y centrado */
            padding: 25px;
            border-radius: 15px;
            width: 90%;
            max-width: 500px;
            box-shadow: var(--shadow-lg);
            position: relative;
            animation: slideUp 0.3s ease;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        @keyframes slideUp {
            from {
                transform: translateY(20px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        /* Ajuste del color naranja en los items del modal para que coincida con tu solicitud anterior */
        .modal-day-item {
            display: flex;
            align-items: center;
            padding: 12px 15px;
            background: var(--hover-bg);
            border-radius: 10px;
            border-left: 4px solid var(--primary-orange);
            /* Cambiado a naranja */
        }
    </style>
    <script>
        (function() {
            const theme = localStorage.getItem('amimbre-theme');
            if (theme === 'light') document.documentElement.setAttribute('data-theme', 'light');
        })();
    </script>
</head>

<body>
    <?php include_once '../../includes/header.php'; ?>

    <main class="main-content">
        <div class="dashboard-header">
            <div>
                <h1>Mis Horarios - Docente</h1>
                <p>Consulta tus sesiones y aulas asignadas</p>
            </div>
            <div class="dashboard-date">
                <span class="material-symbols-rounded" style="vertical-align:middle;">calendar_month</span>
                <?php echo date('d') . " de {$meses[$mes_actual]}"; ?>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header"><span class="stat-title">Mis Clases</span>
                    <div class="stat-icon blue"><i class="fas fa-chalkboard-teacher"></i></div>
                </div>
                <div class="stat-value"><?php echo $clases_semana; ?> Sesiones</div>
                <div class="stat-change">Carga semanal total</div>
            </div>
            <div class="stat-card">
                <div class="stat-header"><span class="stat-title">Siguiente Clase</span>
                    <div class="stat-icon orange"><i class="fas fa-clock"></i></div>
                </div>
                <div class="stat-value"><?php echo $proxima_clase ? date('H:i', strtotime($proxima_clase['hora_inicio'])) : '--:--'; ?></div>
                <div class="stat-change"><?php echo $proxima_clase ? htmlspecialchars($proxima_clase['nombre_curso']) : 'No hay más clases hoy'; ?></div>
            </div>
        </div>

        <div class="content-grid">
            <div class="calendar-card">
                <h3>Agenda Mensual</h3>
                <div class="calendar-grid" style="margin-top: 15px;">
                    <?php foreach ($dias_semana_nombres as $d) echo "<div class='day-name'>" . substr($d, 0, 3) . "</div>"; ?>

                    <?php
                    for ($i = 1; $i < $primer_dia_mes; $i++) echo '<div class="day empty"></div>';

                    for ($dia = 1; $dia <= $dias_en_mes; $dia++) {
                        $timestamp = strtotime("$anio_actual-$mes_actual-$dia");
                        $dia_key = strtolower($dias_semana_nombres[date('N', $timestamp) - 1]);
                        $nombre_dia_full = $dias_semana_nombres[date('N', $timestamp) - 1];
                        $clase_hoy = ($dia == $hoy) ? 'today' : '';

                        $eventos_del_dia = $mapa_eventos[$dia_key] ?? [];
                        $total_eventos = count($eventos_del_dia);
                        $dia_label = "$dia de {$meses[$mes_actual]}";

                        echo "<div class='day $clase_hoy' onclick=\"abrirDetalleDia('$dia_key', '$nombre_dia_full $dia_label')\">";
                        echo "<span class='day-number'>$dia</span>";

                        if ($total_eventos >= 1) {
                            $ev = $eventos_del_dia[0];
                            echo "<div class='event-tag-single'><b>" . date('H:i', strtotime($ev['hora_inicio'])) . "</b> " . htmlspecialchars($ev['nombre_curso']) . "</div>";
                        }
                        if ($total_eventos > 1) {
                            echo "<span class='event-more-badge'>+" . ($total_eventos - 1) . " más</span>";
                        }

                        echo "</div>";
                    }
                    ?>
                </div>
            </div>

            <div class="calendar-card" style="height: fit-content;">
                <h3>Información de Soporte</h3>
                <div style="margin-top:15px; display:flex; flex-direction:column; gap:12px;">
                    <p style="font-size:0.9rem; color:var(--text-secondary);">
                        <i class="fas fa-info-circle"></i> Los horarios mostrados son fijos. Si detectas algún error en tu carga académica, contacta a Coordinación.
                    </p>
                    <p style="font-size:0.9rem; color:var(--text-secondary);">
                        <i class="fas fa-mouse-pointer"></i> Haz clic en un día para ver el detalle completo de grupos y aulas.
                    </p>
                </div>
            </div>
        </div>
    </main>

    <div id="modalDetalleDia" class="modal">
        <div class="modal-content">
            <div style="display:flex; justify-content:space-between; margin-bottom:20px; align-items:center;">
                <div>
                    <h2 style="font-size:1.3rem; color:var(--primary-blue);" id="detalle-titulo">Horarios del día</h2>
                    <p style="font-size:0.85rem; color:var(--text-secondary); margin-top:3px;" id="detalle-subtitulo"></p>
                </div>
                <span onclick="cerrarDetalleDia()" style="cursor:pointer; font-size:1.5rem; color:var(--text-secondary);">&times;</span>
            </div>
            <ul class="modal-day-list" id="detalle-lista"></ul>
        </div>
    </div>

    <script>
        const todosLosEventos = <?php echo $eventos_json; ?>;

        function fmtHora(h) {
            return h ? h.substring(0, 5) : '--:--';
        }

        function abrirDetalleDia(diaKey, etiqueta) {
            const eventos = todosLosEventos[diaKey] || [];
            document.getElementById('detalle-titulo').textContent = 'Mis Clases';
            document.getElementById('detalle-subtitulo').textContent = etiqueta;

            const lista = document.getElementById('detalle-lista');
            lista.innerHTML = '';

            if (eventos.length === 0) {
                lista.innerHTML = '<li style="color:var(--text-secondary); font-size:0.9rem; text-align:center; padding:20px 0;">No tienes clases programadas para este día.</li>';
            } else {
                eventos.forEach(ev => {
                    const li = document.createElement('li');
                    li.className = 'modal-day-item';
                    li.innerHTML = `
                        <div class="modal-day-item-info">
                            <span class="modal-day-item-curso">${ev.nombre_curso}</span>
                            <span class="modal-day-item-meta">
                                <i class="fas fa-clock"></i> ${fmtHora(ev.hora_inicio)} – ${fmtHora(ev.hora_fin)}
                                &nbsp;|&nbsp;
                                <i class="fas fa-users"></i> ${ev.nombre_grupo}
                                &nbsp;|&nbsp;
                                <i class="fas fa-door-open"></i> Aula: ${ev.aula || 'Por asignar'}
                            </span>
                        </div>
                    `;
                    lista.appendChild(li);
                });
            }
            document.getElementById('modalDetalleDia').style.display = 'block';
        }

        function cerrarDetalleDia() {
            document.getElementById('modalDetalleDia').style.display = 'none';
        }

        window.onclick = function(e) {
            if (e.target === document.getElementById('modalDetalleDia')) cerrarDetalleDia();
        };
    </script>
</body>

</html>