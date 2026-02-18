<?php

/**
 * Gestión de Calendario y Horarios - Amimbré (Corregido)
 */
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth_check.php';
require_role('admin');

// 1. Configuración de Fecha Local
date_default_timezone_set('America/Bogota');
$meses = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
$dias_semana_nombres = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];

$mes_actual = date('n');
$anio_actual = date('Y');
$hoy = date('j');

// --- BACKEND: PROCESAR NUEVO HORARIO ---
$mensaje_feedback = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'guardar_horario') {
    try {
        $stmt = $pdo->prepare("INSERT INTO horarios (curso_id, dia_semana, hora_inicio, hora_fin, aula) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $_POST['curso_id'],
            $_POST['dia_semana'],
            $_POST['hora_inicio'],
            $_POST['hora_fin'],
            $_POST['aula']
        ]);
        $mensaje_feedback = "Horario guardado correctamente.";
    } catch (PDOException $e) {
        $mensaje_feedback = "Error: " . $e->getMessage();
    }
}

// 2. Obtener Estadísticas
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM horarios");
    $clases_semana = $stmt->fetch()['total'] ?? 0;

    $dia_hoy_nombre = strtolower($dias_semana_nombres[date('N') - 1]); // Convertido a minúsculas para el ENUM
    // Corrección: el campo en la tabla cursos es 'nombre', no 'nombre_curso'
    $stmt = $pdo->prepare("
        SELECT c.nombre as nombre_curso, h.hora_inicio 
        FROM horarios h
        JOIN grupos g ON h.grupo_id = g.id
        JOIN cursos c ON g.curso_id = c.id
        WHERE h.dia_semana = ? 
        ORDER BY h.hora_inicio ASC LIMIT 1
    ");
    $stmt->execute([$dia_hoy_nombre]);
    $proxima_clase = $stmt->fetch();

    // 3. Obtener todos los horarios para el calendario
    $stmt = $pdo->query("
        SELECT h.dia_semana, h.hora_inicio, c.nombre as nombre_curso, h.aula, g.nombre as nombre_grupo
        FROM horarios h
        JOIN grupos g ON h.grupo_id = g.id
        JOIN cursos c ON g.curso_id = c.id
        WHERE g.estado = 'activo'
    ");
    $eventos_db = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $mapa_eventos = [];
    foreach ($eventos_db as $ev) {
        // Almacenamos usando el nombre del día en minúsculas como clave
        $mapa_eventos[strtolower($ev['dia_semana'])][] = $ev;
    }
} catch (PDOException $e) {
    error_log($e->getMessage());
}

$primer_dia_mes = date('N', strtotime("$anio_actual-$mes_actual-01"));
$dias_en_mes = date('t', strtotime("$anio_actual-$mes_actual-01"));
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Calendario Académico - Amimbré</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0" />
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="../../assets/css/colores.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: "Poppins", sans-serif;
        }

        body {
            min-height: 100vh;
            background: var(--hover-bg);
            color: var(--text-primary);
        }

        .main-content {
            margin-left: 270px;
            padding: 30px;
            transition: margin-left 0.4s ease;
            min-height: 100vh;
        }

        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border-color);
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--dark-bg);
            border-radius: 16px;
            padding: 24px;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-md);
            transition: 0.3s;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }

        .stat-icon.blue {
            background: var(--subtle-blue);
            color: var(--primary-blue);
        }

        .stat-icon.orange {
            background: var(--subtle-orange);
            color: var(--primary-orange);
        }

        .stat-icon.green {
            background: var(--subtle-green);
            color: var(--primary-green);
        }

        /* Calendar Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
        }

        .calendar-card {
            background: var(--dark-bg);
            border-radius: 16px;
            padding: 25px;
            border: 1px solid var(--border-color);
        }

        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 10px;
            margin-top: 20px;
        }

        .day-name {
            text-align: center;
            font-weight: 600;
            color: var(--text-secondary);
            font-size: 0.85rem;
            padding-bottom: 10px;
        }

        .day {
            min-height: 110px;
            background: var(--hover-bg);
            border-radius: 12px;
            padding: 10px;
            border: 1px solid var(--border-color);
        }

        .day.today {
            border: 2px solid var(--primary-blue);
            background: rgba(58, 110, 242, 0.05);
        }

        .day.empty {
            background: transparent;
            border: none;
        }

        .day-number {
            font-weight: 600;
            font-size: 0.9rem;
            color: var(--text-secondary);
        }

        .event-tag {
            font-size: 0.65rem;
            padding: 4px 6px;
            border-radius: 4px;
            margin-top: 4px;
            background: var(--subtle-blue);
            color: var(--primary-blue);
            border-left: 3px solid var(--primary-blue);
            cursor: help;
        }

        /* Estilos del Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(4px);
        }

        .modal-content {
            background: var(--dark-bg);
            margin: 5% auto;
            padding: 30px;
            border-radius: 16px;
            width: 90%;
            max-width: 500px;
            border: 1px solid var(--border-color);
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        .input-form {
            width: 100%;
            padding: 10px;
            border-radius: 8px;
            background: var(--hover-bg);
            border: 1px solid var(--border-color);
            color: white;
            outline: none;
        }

        .input-form:focus {
            border-color: var(--primary-blue);
        }

        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
            }

            .content-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

    <?php include_once '../../includes/header.php'; ?>

    <main class="main-content">
        <div class="dashboard-header">
            <div class="dashboard-title">
                <h1>Calendario Académico</h1>
                <p>Visualización de clases y gestión de aulas</p>
            </div>
            <div class="dashboard-date">
                <span class="material-symbols-rounded" style="vertical-align: middle;">calendar_month</span>
                <?php echo "{$dias_semana_nombres[date('N') - 1]}, " . date('d') . " de {$meses[$mes_actual]}"; ?>
            </div>
        </div>

        <?php if ($mensaje_feedback): ?>
            <div style="padding:15px; background:var(--subtle-green); color:var(--primary-green); border-radius:8px; margin-bottom:20px; border:1px solid var(--primary-green);">
                <i class="fas fa-check-circle"></i> <?php echo $mensaje_feedback; ?>
            </div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-title">Clases Programadas</span>
                    <div class="stat-icon blue"><span class="material-symbols-rounded">menu_book</span></div>
                </div>
                <div class="stat-value"><?php echo $clases_semana; ?> Sesiones</div>
                <div class="stat-change">Total registradas</div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-title">Próxima Clase</span>
                    <div class="stat-icon orange"><span class="material-symbols-rounded">event_upcoming</span></div>
                </div>
                <div class="stat-value" style="font-size: 1.1rem;">
                    <?php echo $proxima_clase ? htmlspecialchars($proxima_clase['nombre_curso']) : 'Sin clases hoy'; ?>
                </div>
                <div class="stat-change">Hoy: <?php echo $proxima_clase ? date('H:i', strtotime($proxima_clase['hora_inicio'])) : '--:--'; ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-title">Mes Actual</span>
                    <div class="stat-icon green"><span class="material-symbols-rounded">calendar_today</span></div>
                </div>
                <div class="stat-value"><?php echo $meses[$mes_actual]; ?></div>
                <div class="stat-change"><?php echo $anio_actual; ?></div>
            </div>
        </div>

        <div class="content-grid">
            <div class="calendar-card">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <h3>Horario Mensual</h3>
                    <button onclick="abrirModal()" style="padding: 8px 15px; background: var(--primary-blue); color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 500;">
                        <i class="fas fa-plus"></i> Nuevo Horario
                    </button>
                </div>

                <div class="calendar-grid">
                    <?php foreach ($dias_semana_nombres as $d): ?>
                        <div class="day-name"><?php echo substr($d, 0, 3); ?></div>
                    <?php endforeach; ?>

                    <?php
                    for ($i = 1; $i < $primer_dia_mes; $i++) echo '<div class="day empty"></div>';

                    for ($dia = 1; $dia <= $dias_en_mes; $dia++) {
                        $timestamp = strtotime("$anio_actual-$mes_actual-$dia");
                        $dia_semana_key = strtolower($dias_semana_nombres[date('N', $timestamp) - 1]);
                        $clase_hoy = ($dia == $hoy) ? 'today' : '';

                        echo "<div class='day $clase_hoy'>";
                        echo "<span class='day-number'>$dia</span>";

                        if (isset($mapa_eventos[$dia_semana_key])) {
                            foreach ($mapa_eventos[$dia_semana_key] as $evento) {
                                echo "<div class='event-tag' title='Grupo: {$evento['nombre_grupo']} | Aula: {$evento['aula']}'>";
                                echo "<b>" . date('H:i', strtotime($evento['hora_inicio'])) . "</b> " . htmlspecialchars($evento['nombre_curso']);
                                echo "</div>";
                            }
                        }
                        echo "</div>";
                    }
                    ?>
                </div>
            </div>

            <div class="calendar-card" style="height: fit-content;">
                <h3>Ayuda</h3>
                <div style="margin-top:15px;">
                    <p style="font-size:0.9rem; color:var(--text-secondary);">
                        <i class="fas fa-info-circle"></i> Los horarios son cíclicos. Se muestran todos los días correspondientes al día de la semana configurado.
                    </p>
                    <br>
                    <div style="display:flex; align-items:center; gap:10px; font-size:0.8rem;">
                        <div style="width:12px; height:12px; border:2px solid var(--primary-blue); border-radius:2px;"></div> Hoy actual
                    </div>
                </div>
            </div>
        </div>
    </main>

    <div id="modalHorario" class="modal">
        <div class="modal-content">
            <div style="display:flex; justify-content:space-between; margin-bottom:20px; align-items:center;">
                <h2 style="font-size:1.3rem; color:var(--primary-blue);">Nuevo Horario</h2>
                <span onclick="cerrarModal()" style="cursor:pointer; font-size:1.5rem;">&times;</span>
            </div>

            <form method="POST">
                <input type="hidden" name="action" value="guardar_horario">

                <div class="form-group">
                    <label>Seleccionar curso Activo</label>
                    <select name="grupo_id" required class="input-form">
                        <?php
                        $cursos = $pdo->query("SELECT id, nombre FROM cursos WHERE estado =  'activo'")->fetchAll();
                        foreach ($cursos as $c) echo "<option value='{$c['id']}'>{$c['nombre']}</option>";
                        ?>
                    </select>
                </div>

                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                    <div class="form-group">
                        <label>Día de la Semana</label>
                        <select name="dia_semana" class="input-form">
                            <option value="lunes">Lunes</option>
                            <option value="martes">Martes</option>
                            <option value="miercoles">Miércoles</option>
                            <option value="jueves">Jueves</option>
                            <option value="viernes">Viernes</option>
                            <option value="sabado">Sábado</option>
                            <option value="domingo">Domingo</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Aula / Salón</label>
                        <input type="text" name="aula" placeholder="Ej: Aula 1" class="input-form">
                    </div>
                </div>

                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                    <div class="form-group">
                        <label>Hora Inicio</label>
                        <input type="time" name="hora_inicio" required class="input-form">
                    </div>
                    <div class="form-group">
                        <label>Hora Fin</label>
                        <input type="time" name="hora_fin" required class="input-form">
                    </div>
                </div>

                <button type="submit" style="width:100%; padding:12px; background:var(--primary-blue); color:white; border:none; border-radius:8px; font-weight:600; cursor:pointer; margin-top:10px;">
                    <i class="fas fa-save"></i> Guardar Horario
                </button>
            </form>
        </div>
    </div>

    <script>
        function abrirModal() {
            document.getElementById('modalHorario').style.display = 'block';
        }

        function cerrarModal() {
            document.getElementById('modalHorario').style.display = 'none';
        }

        // Cerrar modal si se hace clic fuera de él
        window.onclick = function(event) {
            let modal = document.getElementById('modalHorario');
            if (event.target == modal) cerrarModal();
        }
    </script>
</body>

</html>