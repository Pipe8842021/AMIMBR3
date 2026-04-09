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
        SELECT h.dia_semana, h.hora_inicio, c.nombre as nombre_curso, h.aula, g.nombre as nombre_grupo
        FROM horarios h
        JOIN grupos g ON h.grupo_id = g.id
        JOIN cursos c ON g.curso_id = c.id
        JOIN matriculas m ON m.grupo_id = g.id
        WHERE m.estudiante_id = ? AND m.estado = 'activa' AND g.estado = 'activo'
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
    <title>Mi Horario - Amimbré</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="../../assets/css/colores.css">
    <link rel="stylesheet" href="../../assets/css/style-horariosEstudiante.css">
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
                    $dia_semana_key = strtolower($dias_semana_nombres[date('N', $timestamp) - 1]);
                    $clase_hoy = ($dia == $hoy) ? 'today' : '';
                    echo "<div class='day $clase_hoy'><span class='day-number'>$dia</span>";
                    if (isset($mapa_eventos[$dia_semana_key])) {
                        foreach ($mapa_eventos[$dia_semana_key] as $evento) {
                            echo "<div class='event-tag' title='Aula: {$evento['aula']}'><b>" . date('H:i', strtotime($evento['hora_inicio'])) . "</b> " . htmlspecialchars($evento['nombre_curso']) . "</div>";
                        }
                    }
                    echo "</div>";
                }
                ?>
            </div>
        </div>
    </main>
</body>

</html>