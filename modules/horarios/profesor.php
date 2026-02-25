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
    // Estadísticas propias del profesor
    $stmt = $pdo->prepare("SELECT COUNT(h.id) as total FROM horarios h JOIN grupos g ON h.grupo_id = g.id WHERE g.profesor_id = ?");
    $stmt->execute([$user_id]);
    $clases_semana = $stmt->fetch()['total'] ?? 0;

    $dia_hoy_nombre = strtolower($dias_semana_nombres[date('N') - 1]);
    $stmt = $pdo->prepare("
        SELECT c.nombre as nombre_curso, h.hora_inicio 
        FROM horarios h
        JOIN grupos g ON h.grupo_id = g.id
        JOIN cursos c ON g.curso_id = c.id
        WHERE g.profesor_id = ? AND h.dia_semana = ? 
        ORDER BY h.hora_inicio ASC LIMIT 1
    ");
    $stmt->execute([$user_id, $dia_hoy_nombre]);
    $proxima_clase = $stmt->fetch();

    $stmt = $pdo->prepare("
        SELECT h.dia_semana, h.hora_inicio, c.nombre as nombre_curso, h.aula, g.nombre as nombre_grupo
        FROM horarios h
        JOIN grupos g ON h.grupo_id = g.id
        JOIN cursos c ON g.curso_id = c.id
        WHERE g.profesor_id = ? AND g.estado = 'activo'
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
</head>

<body>
    <?php include_once '../../includes/header.php'; ?>
    <main class="main-content">
        <div class="dashboard-header">
            <div>
                <h1>Mis horarios-Docente</h1>
                <p>Horarios de tus grupos asignados</p>
            </div>
            <div class="dashboard-date"><?php echo date('d') . " de {$meses[$mes_actual]}"; ?></div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header"><span class="stat-title">Mis Clases</span>
                    <div class="stat-icon blue"><i class="fas fa-chalkboard-teacher"></i></div>
                </div>
                <div class="stat-value"><?php echo $clases_semana; ?> Sesiones</div>
            </div>
            <div class="stat-card">
                <div class="stat-header"><span class="stat-title">Siguiente Clase</span>
                    <div class="stat-icon orange"><i class="fas fa-clock"></i></div>
                </div>
                <div class="stat-value"><?php echo $proxima_clase ? date('H:i', strtotime($proxima_clase['hora_inicio'])) : '--:--'; ?></div>
                <div class="stat-change"><?php echo $proxima_clase ? htmlspecialchars($proxima_clase['nombre_curso']) : 'Hoy libre'; ?></div>
            </div>
        </div>

        <div class="content-grid">
            <div class="calendar-card">
                <div class="calendar-grid">
                    <?php foreach ($dias_semana_nombres as $d) echo "<div class='day-name'>" . substr($d, 0, 3) . "</div>"; ?>
                    <?php
                    for ($i = 1; $i < $primer_dia_mes; $i++) echo '<div class="day empty"></div>';
                    for ($dia = 1; $dia <= $dias_en_mes; $dia++) {
           $timestamp = strtotime("$anio_actual-$mes_actual-$dia");
                        $dia_semana_key = strtolower($dias_semana_nombres[date('N', $timestamp) - 1]);
                        $clase_hoy = ($dia == $hoy) ? 'today' : '';
                        echo "<div class='day $clase_hoy'><span class='day-number'>$dia</span>";
                        if (isset($mapa_eventos[$dia_semana_key])) {
                            foreach ($mapa_eventos[$dia_semana_key] as $evento) {
                                echo "<div class='event-tag'><b>" . date('H:i', strtotime($evento['hora_inicio'])) . "</b> " . htmlspecialchars($evento['nombre_grupo']) . "</div>";
                            }
                        }
                        echo "</div>";
                    }
                    ?>
                </div>
            </div>
            <div class="calendar-card" style="height: fit-content;">
                <h3>Información</h3>
                <p style="font-size: 0.85rem; color: var(--text-secondary); margin-top:10px;">
                    Si necesitas cambiar un horario, por favor contacta al administrador del sistema.
                </p>
            </div>
        </div>
    </main>
</body>

</html>