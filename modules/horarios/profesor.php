<?php
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth_check.php';
require_role('profesor');

date_default_timezone_set('America/Bogota');
$meses = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
$dias_semana_nombres = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];


$dia_bd_map = [
    1 => 'lunes',
    2 => 'martes',
    3 => 'miercoles',
    4 => 'jueves',
    5 => 'viernes',
    6 => 'sabado',
    7 => 'domingo',
];

$mes_actual  = date('n');
$anio_actual = date('Y');
$hoy         = date('j');
$user_id     = $_SESSION['user_id'];

try {
    $stmt = $pdo->prepare("
        SELECT COUNT(h.id) AS total
        FROM horarios h
        JOIN grupos g ON h.grupo_id = g.id
        WHERE g.profesor_id = ?
    ");
    $stmt->execute([$user_id]);
    $clases_semana = $stmt->fetch()['total'] ?? 0;


    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT g.id) AS total
        FROM grupos g
        WHERE g.profesor_id = ? AND g.estado = 'activo'
    ");
    $stmt->execute([$user_id]);
    $total_grupos = $stmt->fetch()['total'] ?? 0;


    $hoy_key = $dia_bd_map[(int)date('N')];
    $stmt = $pdo->prepare("
        SELECT c.nombre AS nombre_curso, h.hora_inicio, h.aula, g.nombre AS nombre_grupo
        FROM horarios h
        JOIN grupos g ON h.grupo_id = g.id
        JOIN cursos c ON g.curso_id = c.id
        WHERE g.profesor_id = ? AND h.dia_semana = ? AND h.hora_inicio >= CURTIME()
        ORDER BY h.hora_inicio ASC
        LIMIT 1
    ");
    $stmt->execute([$user_id, $hoy_key]);
    $proxima_clase = $stmt->fetch();


    $stmt = $pdo->prepare("
        SELECT h.id, h.dia_semana, h.hora_inicio, h.hora_fin,
               c.nombre AS nombre_curso, h.aula, g.nombre AS nombre_grupo
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
        $mapa_eventos[$ev['dia_semana']][] = $ev;
    }
} catch (PDOException $e) {
    error_log($e->getMessage());
    $clases_semana = 0;
    $total_grupos  = 0;
    $proxima_clase = null;
    $mapa_eventos  = [];
}

$eventos_json   = json_encode($mapa_eventos, JSON_UNESCAPED_UNICODE);
$primer_dia_mes = date('N', strtotime("$anio_actual-$mes_actual-01"));
$dias_en_mes    = date('t',  strtotime("$anio_actual-$mes_actual-01"));

// Hoy para JS
$hoy_dia_key = $dia_bd_map[(int)date('N')];
$hoy_label   = $dias_semana_nombres[date('N') - 1] . ', ' . $hoy . ' de ' . $meses[$mes_actual];
$dias_en_mes = date('t', strtotime("$anio_actual-$mes_actual-01"));

function formatearHora($hora)
{
    if (!$hora) return 'N/A';
    return date("g:i a", strtotime($hora));
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mi Agenda – Amimbré</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="../../assets/css/colores.css">

    <link rel="stylesheet" href="../../assets/css/style-horarios.css">
    <script>
        (function() {
            const t = localStorage.getItem('amimbre-theme');
            document.documentElement.setAttribute('data-theme', t === 'light' ? 'light' : 'dark');
        })();
    </script>
</head>

<body>
    <?php include_once '../../includes/header.php'; ?>

    <main class="main-content">


        <div class="dashboard-header">
            <div class="dashboard-title">
                <h1>Mi Agenda Docente</h1>
                <p>Consulta tus sesiones y aulas asignadas</p>
            </div>
            <div class="dashboard-date">
                <span class="material-symbols-rounded">calendar_month</span>
                <?php echo "{$dias_semana_nombres[date('N') - 1]}, " . date('d') . " de {$meses[$mes_actual]}"; ?>
            </div>
        </div>


        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-title">Mis Clases</span>
                    <div class="stat-icon blue">
                        <span class="material-symbols-rounded">co_present</span>
                    </div>
                </div>
                <div class="stat-value"><?php echo $clases_semana; ?> Sesiones</div>
                <div class="stat-change">Carga semanal total</div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-title">Grupos Activos</span>
                    <div class="stat-icon green">
                        <span class="material-symbols-rounded">groups</span>
                    </div>
                </div>
                <div class="stat-value"><?php echo $total_grupos; ?> Grupos</div>
                <div class="stat-change">A tu cargo</div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-title">Siguiente Clase Hoy</span>
                    <div class="stat-icon orange">
                        <span class="material-symbols-rounded">schedule</span>
                    </div>
                </div>
                <div class="stat-value">
                    <?php echo $proxima_clase ? date('H:i', strtotime($proxima_clase['hora_inicio'])) : '--:--'; ?>
                </div>
                <div class="stat-change">
                    <?php echo $proxima_clase
                        ? htmlspecialchars($proxima_clase['nombre_curso'] . ' · ' . $proxima_clase['nombre_grupo'])
                        : 'No hay más clases hoy'; ?>
                </div>
            </div>
        </div>


        <div class="content-grid">


            <div class="calendar-card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                    <h3>Agenda Mensual</h3>
                    <span style="font-size:0.82rem; color:var(--text-secondary);">
                        <?php echo $meses[$mes_actual] . ' ' . $anio_actual; ?>
                    </span>
                </div>

                <div class="calendar-grid">

                    <?php foreach ($dias_semana_nombres as $d): ?>
                        <div class="day-name"><?php echo mb_substr($d, 0, 3); ?></div>
                    <?php endforeach; ?>

                    <?php for ($i = 1; $i < $primer_dia_mes; $i++): ?>
                        <div class="day empty"></div>
                    <?php endfor; ?>


                    <?php for ($dia = 1; $dia <= $dias_en_mes; $dia++):
                        $ts              = strtotime("$anio_actual-$mes_actual-$dia");
                        $dow             = (int)date('N', $ts);
                        $dia_key         = $dia_bd_map[$dow];
                        $nombre_dia_full = $dias_semana_nombres[$dow - 1];
                        $clase_hoy       = ($dia == $hoy) ? 'today' : '';
                        $eventos_del_dia = $mapa_eventos[$dia_key] ?? [];
                        $total_ev        = count($eventos_del_dia);
                        $dia_label       = "$dia de {$meses[$mes_actual]}";
                    ?>
                        <div class="day <?php echo $clase_hoy; ?> profesor"
                            onclick="abrirDetalleDia('<?php echo $dia_key; ?>','<?php echo "$nombre_dia_full $dia_label"; ?>')"
                            title="<?php echo "$nombre_dia_full $dia_label"; ?>">

                            <span class="day-number"><?php echo $dia; ?></span>

                            <?php if ($total_ev >= 1):
                                $ev = $eventos_del_dia[0]; ?>
                                <div class="event-tag-single orange"
                                    title="<?php echo htmlspecialchars($ev['nombre_grupo'] . ' · ' . $ev['nombre_curso']); ?>">
                                    <b><?php echo date('H:i', strtotime($ev['hora_inicio'])); ?></b>
                                    <?php echo htmlspecialchars($ev['nombre_curso']); ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($total_ev > 1): ?>
                                <span class="event-more-badge">+<?php echo $total_ev - 1; ?> más</span>
                            <?php endif; ?>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>


            <div class="help-card">
                <h3>Información de Soporte</h3>
                <div class="help-items">
                    <div class="help-item">
                        <i class="fas fa-mouse-pointer"></i>
                        <span>Haz clic en un día para ver el detalle completo de grupos y aulas.</span>
                    </div>
                    <div class="help-item">
                        <i class="fas fa-info-circle"></i>
                        <span>Los horarios son fijos y se repiten semanalmente. Si detectas algún error, contacta a Coordinación.</span>
                    </div>
                    <div class="help-item">
                        <i class="fas fa-door-open"></i>
                        <span>El aula aparece como "Por asignar" si aún no ha sido configurada por el administrador.</span>
                    </div>


                    <div class="help-legend" onclick="irHoy()" title="Ver mis clases de hoy"
                        style="border-color: var(--primary-orange);">
                        <div class="legend-box" style="border-color: var(--primary-orange);"></div>
                        <span>Día actual — click para ver hoy</span>
                    </div>
                </div>
            </div>
        </div>

    </main>

    <div id="modalDetalleDia" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h2 id="detalle-titulo" style="color:var(--primary-orange);">Mis Clases</h2>
                    <p id="detalle-subtitulo"></p>
                </div>
                <button class="modal-close" onclick="cerrarDetalleDia()">&times;</button>
            </div>
            <ul class="modal-day-list" id="detalle-lista"></ul>
        </div>
    </div>


    <script>
        const todosLosEventos = <?php echo $eventos_json; ?>;
        const HOY_KEY = '<?php echo $hoy_dia_key; ?>';
        const HOY_LABEL = '<?php echo $hoy_label; ?>';

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
                    li.style.borderLeftColor = 'var(--primary-orange)';
                    li.innerHTML = `
                <div class="modal-day-item-info">
                    <span class="modal-day-item-curso">${ev.nombre_curso}</span>
                    <span class="modal-day-item-meta">
                        <i class="fas fa-clock" style="font-size:11px;"></i> ${fmtHora(ev.hora_inicio)} – ${fmtHora(ev.hora_fin)}
                        &nbsp;|&nbsp;
                        <i class="fas fa-users" style="font-size:11px;"></i> ${ev.nombre_grupo}
                        &nbsp;|&nbsp;
                        <i class="fas fa-door-open" style="font-size:11px;"></i> ${ev.aula || 'Por asignar'}
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


        function irHoy() {
            abrirDetalleDia(HOY_KEY, HOY_LABEL);
        }


        window.addEventListener('click', e => {
            const m = document.getElementById('modalDetalleDia');
            if (e.target === m) m.style.display = 'none';
        });


        (function() {
            const main = document.querySelector('.main-content');
            const EXPANDED = '270px';
            const COLLAPSED = '80px';

            function syncMargin() {
                const collapsed =
                    document.body.classList.contains('sidebar-collapsed') ||
                    document.querySelector('.sidebar')?.classList.contains('collapsed');
                main.style.marginLeft = collapsed ? COLLAPSED : EXPANDED;
            }

            const observer = new MutationObserver(syncMargin);
            observer.observe(document.body, {
                attributes: true,
                attributeFilter: ['class']
            });
            const sidebar = document.querySelector('.sidebar');
            if (sidebar) observer.observe(sidebar, {
                attributes: true,
                attributeFilter: ['class']
            });

            syncMargin();
        })();

        window.onclick = function(e) {
            if (e.target === document.getElementById('modalDetalleDia')) cerrarDetalleDia();
        };

        function formatearHora12h(hora24) {
            if (!hora24) return '--:--';

            let [horas, minutos] = hora24.split(':');
            horas = parseInt(horas);

            const ampm = horas >= 12 ? 'PM' : 'AM';

            horas = horas % 12;
            horas = horas ? horas : 12;

            return `${horas}:${minutos} ${ampm}`;
        }
    </script>
</body>

</html>