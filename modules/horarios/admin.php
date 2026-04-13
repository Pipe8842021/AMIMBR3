<?php


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

//  BACKEND: PROCESAR NUEVO HORARIO 
$mensaje_feedback = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'guardar_horario') {
    $grupo_id   = intval($_POST['grupo_id']);
    $dia        = $_POST['dia_semana'];
    $hora_ini   = $_POST['hora_inicio'];
    $hora_fin   = $_POST['hora_fin'];
    $aula       = trim($_POST['aula']);
    $conflictos = [];

    // 1. Conflicto de AULA
    if (!empty($aula)) {
        $stmt = $pdo->prepare("
            SELECT g.nombre as nombre_grupo, c.nombre as nombre_curso
            FROM horarios h
            JOIN grupos g ON h.grupo_id = g.id
            JOIN cursos c ON g.curso_id = c.id
            WHERE h.aula = ?
              AND h.dia_semana = ?
              AND h.hora_inicio < ?   -- la clase existente empieza antes de que termine la nueva
              AND h.hora_fin   > ?    -- la clase existente termina después de que empiece la nueva
        ");
        $stmt->execute([$aula, $dia, $hora_fin, $hora_ini]);
        $conflicto_aula = $stmt->fetch();
        if ($conflicto_aula) {
            $conflictos[] = "El aula '{$aula}' ya está ocupada ese día en ese horario 
                             por el grupo '{$conflicto_aula['nombre_grupo']}' 
                             ({$conflicto_aula['nombre_curso']}).";
        }
    }

    // 2. Conflicto de PROFESOR
    $stmt = $pdo->prepare("SELECT profesor_id FROM grupos WHERE id = ?");
    $stmt->execute([$grupo_id]);
    $profesor_id = $stmt->fetchColumn();

    if ($profesor_id) {
        $stmt = $pdo->prepare("
            SELECT g.nombre as nombre_grupo, c.nombre as nombre_curso
            FROM horarios h
            JOIN grupos g ON h.grupo_id = g.id
            JOIN cursos c ON g.curso_id = c.id
            WHERE g.profesor_id = ?
              AND g.id != ?           -- excluir el mismo grupo
              AND h.dia_semana = ?
              AND h.hora_inicio < ?
              AND h.hora_fin   > ?
        ");
        $stmt->execute([$profesor_id, $grupo_id, $dia, $hora_fin, $hora_ini]);
        $conflicto_prof = $stmt->fetch();
        if ($conflicto_prof) {
            $conflictos[] = "El profesor ya tiene clase ese día en ese horario 
                             con el grupo '{$conflicto_prof['nombre_grupo']}' 
                             ({$conflicto_prof['nombre_curso']}).";
        }
    }

    // 3. Conflicto de ESTUDIANTES (matriculados en el grupo nuevo
    //    que también están en otro grupo con horario solapado)
    $stmt = $pdo->prepare("
        SELECT DISTINCT u.nombre as nombre_estudiante, 
               g2.nombre as nombre_grupo_conflicto,
               c2.nombre as nombre_curso_conflicto
        FROM matriculas m
        JOIN usuarios u ON m.estudiante_id = u.id
        -- buscar otros grupos donde esté el mismo estudiante
        JOIN matriculas m2 ON m2.estudiante_id = m.estudiante_id AND m2.grupo_id != ?
        JOIN grupos g2 ON m2.grupo_id = g2.id
        JOIN cursos c2 ON g2.curso_id = c2.id
        JOIN horarios h2 ON h2.grupo_id = g2.id
        WHERE m.grupo_id = ?
          AND m.estado   = 'activa'
          AND m2.estado  = 'activa'
          AND h2.dia_semana  = ?
          AND h2.hora_inicio < ?
          AND h2.hora_fin    > ?
        LIMIT 5  -- mostrar solo los primeros 5 casos
    ");
    $stmt->execute([$grupo_id, $grupo_id, $dia, $hora_fin, $hora_ini]);
    $conflictos_estudiantes = $stmt->fetchAll();
    foreach ($conflictos_estudiantes as $ce) {
        $conflictos[] = "El estudiante '{$ce['nombre_estudiante']}' ya tiene clase 
                         en '{$ce['nombre_grupo_conflicto']}' 
                         ({$ce['nombre_curso_conflicto']}) en ese mismo horario.";
    }

    // --- Guardar solo si no hay conflictos ---
    if (empty($conflictos)) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO horarios (grupo_id, dia_semana, hora_inicio, hora_fin, aula) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$grupo_id, $dia, $hora_ini, $hora_fin, $aula]);
            $mensaje_feedback = "<span style='color:var(--primary-green)'>
                ✓ Horario guardado correctamente.</span>";
        } catch (PDOException $e) {
            $mensaje_feedback = "<span style='color:#ef4444'>Error al guardar: "
                . $e->getMessage() . "</span>";
        }
    } else {
        // Mostrar todos los conflictos encontrados
        $mensaje_feedback = "<span style='color:#ef4444'>
            <b>No se guardó el horario. Se encontraron los siguientes conflictos:</b><br>"
            . implode('<br>', array_map('htmlspecialchars', $conflictos))
            . "</span>";
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
    <link rel="stylesheet" href="../../assets/css/style-horariosAdmin.css">
    <script>
        (function() {
            const theme = localStorage.getItem('amimbre-theme'); // 'amimbre-theme' es la clave del helper
            if (theme === 'light') {
                document.documentElement.setAttribute('data-theme', 'light');
            }   

        })();
    </script>
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
                    <label>Seleccionar Grupo Activo</label>
                    <select name="grupo_id" required class="input-form">
                        <?php
                        $grupos = $pdo->query("SELECT id, nombre FROM grupos WHERE estado =  'activo'")->fetchAll();
                        foreach ($grupos as $g) echo "<option value='{$g['id']}'>{$g['nombre']}</option>";
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