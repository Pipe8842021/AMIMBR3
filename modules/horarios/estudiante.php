<?php
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth_check.php';
require_role('estudiante');

date_default_timezone_set('America/Bogota');
$meses = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
$dias_semana_nombres = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];

$mes_actual = date('n');
$anio_actual = date('Y');
$hoy = date('j');
$user_id = $_SESSION['user_id'];

try {
    // Solo clases donde el estudiante está matriculado y la matrícula está activa
    $stmt = $pdo->prepare("
        SELECT h.dia_semana, h.hora_inicio, h.hora_fin, c.nombre as nombre_curso, h.aula, g.nombre as nombre_grupo
        FROM horarios h
        JOIN grupos g ON h.grupo_id = g.id
        JOIN cursos c ON g.curso_id = c.id
        JOIN matriculas m ON m.grupo_id = g.id
        WHERE m.estudiante_id = ? AND m.estado = 'activa' AND g.estado = 'activo'
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
    <title>Mi Horario - Amimbré</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="../../assets/css/colores.css">
    <link rel="stylesheet" href="../../assets/css/style-horariosEstudiante.css">
    <style>
        .day {
            cursor: pointer;
            transition: transform 0.15s;
        }

        .day:not(.empty):hover {
            border-color: var(--primary-green);
            transform: translateY(-2px);
        }

        .event-tag-single {
            font-size: 0.7rem;
            padding: 4px 8px;
            border-radius: 6px;
            margin-top: 5px;
            background: var(--subtle-green);
            color: var(--primary-green);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            border-left: 3px solid var(--primary-green);
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

        /* Estilos del Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
        }

        .modal-content {
            background-color: var(--card-bg);
            margin: 10% auto;
            padding: 25px;
            border-radius: 15px;
            width: 90%;
            max-width: 500px;
            box-shadow: var(--shadow-lg);
            position: relative;
        }

        .modal-day-item {
            display: flex;
            align-items: center;
            padding: 12px 15px;
            background: var(--hover-bg);
            border-radius: 10px;
            border-left: 4px solid var(--primary-orange);
            margin-bottom: 10px;
            list-style: none;
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
                <h1>Mi Horario de Clases</h1>
                <p>Consulta tus asignaturas y salones</p>
            </div>
            <div class="dashboard-date"><?php echo $meses[$mes_actual] . " " . $anio_actual; ?></div>
        </div>

        <div class="calendar-card">
            <div class="calendar-grid">
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
    </main>

    <div id="modalDetalleDia" class="modal">
        <div class="modal-content">
            <div style="display:flex; justify-content:space-between; margin-bottom:20px; align-items:center;">
                <div>
                    <h2 style="font-size:1.3rem; color:var(--primary-orange);" id="detalle-titulo">Mis Clases</h2>
                    <p style="font-size:0.85rem; color:var(--text-secondary); margin-top:3px;" id="detalle-subtitulo"></p>
                </div>
                <span onclick="cerrarDetalleDia()" style="cursor:pointer; font-size:1.5rem; color:var(--text-secondary);">&times;</span>
            </div>
            <ul id="detalle-lista" style="padding:0; margin:0;"></ul>
        </div>
    </div>

    <script>
        const todosLosEventos = <?php echo $eventos_json; ?>;

        function fmtHora(h) {
            return h ? h.substring(0, 5) : '--:--';
        }

        function abrirDetalleDia(diaKey, etiqueta) {
            const eventos = todosLosEventos[diaKey] || [];
            document.getElementById('detalle-subtitulo').textContent = etiqueta;
            const lista = document.getElementById('detalle-lista');
            lista.innerHTML = '';

            if (eventos.length === 0) {
                lista.innerHTML = '<li style="color:var(--text-secondary); text-align:center; padding:20px;">No tienes clases este día.</li>';
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
        window.onclick = (e) => {
            if (e.target.className === 'modal') cerrarDetalleDia();
        };
    </script>
</body>

</html>