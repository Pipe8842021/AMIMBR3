<?php

require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth_check.php';
require_role('admin');

try {
    $stmt = $pdo->prepare("SELECT id, nombre, email, rol, estado, foto_perfil FROM usuarios WHERE id = ? AND estado = 'activo'");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) { session_destroy(); header("Location: ../../auth/login.php?error=usuario_no_encontrado"); exit; }
} catch (PDOException $e) {
    error_log("Error usuario: " . $e->getMessage());
    die("Error del sistema.");
}

$year_selected = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
$month_selected = isset($_GET['month']) ? (int)$_GET['month'] : 0;

try {
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM usuarios WHERE rol='estudiante' AND estado='activo'");
    $total_estudiantes = $stmt->fetch()['total'] ?? 0;

    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM usuarios WHERE rol='estudiante' AND estado='activo' AND YEAR(fecha_registro)=?");
    $stmt->execute([$year_selected]);
    $estudiantes_nuevos_anio = $stmt->fetch()['total'] ?? 0;

    $stmt = $pdo->query("SELECT COUNT(*) as total FROM matriculas WHERE estado='activa'");
    $matriculas_activas = $stmt->fetch()['total'] ?? 0;

    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM matriculas WHERE YEAR(fecha_matricula)=?");
    $stmt->execute([$year_selected]);
    $matriculas_anio = $stmt->fetch()['total'] ?? 0;

    $stmt = $pdo->query("SELECT COUNT(*) as total FROM preinscripciones WHERE estado='pendiente'");
    $preinscripciones_pendientes = $stmt->fetch()['total'] ?? 0;

    $stmt = $pdo->query("SELECT COUNT(*) as total FROM grupos WHERE estado='activo'");
    $grupos_activos = $stmt->fetch()['total'] ?? 0;

    $stmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN estado='pagado' THEN monto ELSE 0 END) as ingresos_confirmados,
            SUM(CASE WHEN estado='pendiente' THEN monto ELSE 0 END) as ingresos_pendientes,
            SUM(CASE WHEN estado='vencido' THEN monto ELSE 0 END) as ingresos_vencidos,
            SUM(CASE WHEN estado='anulado' THEN monto ELSE 0 END) as anulados,
            SUM(monto) as total_facturado,
            COUNT(*) as total_pagos,
            COUNT(CASE WHEN estado='pagado' THEN 1 END) as pagos_ok,
            COUNT(CASE WHEN estado='vencido' THEN 1 END) as pagos_vencidos
        FROM pagos
        WHERE YEAR(fecha_registro) = ?
    ");
    $stmt->execute([$year_selected]);
    $financiero_anio = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT MONTH(fecha_registro) as mes,
               SUM(CASE WHEN estado='pagado' THEN monto ELSE 0 END) as confirmados,
               SUM(CASE WHEN estado='vencido' THEN monto ELSE 0 END) as vencidos,
               SUM(CASE WHEN estado='pendiente' THEN monto ELSE 0 END) as pendientes,
               COUNT(*) as total_cobros
        FROM pagos
        WHERE YEAR(fecha_registro) = ?
        GROUP BY MONTH(fecha_registro)
        ORDER BY mes
    ");
    $stmt->execute([$year_selected]);
    $ingresos_mes_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT c.nombre as curso,
               SUM(CASE WHEN p.estado='pagado' THEN p.monto ELSE 0 END) as pagado,
               SUM(CASE WHEN p.estado='vencido' THEN p.monto ELSE 0 END) as vencido,
               COUNT(p.id) as total_cobros,
               COUNT(CASE WHEN p.estado='pagado' THEN 1 END) as cobros_ok
        FROM pagos p
        INNER JOIN matriculas m ON p.matricula_id = m.id
        INNER JOIN grupos g ON m.grupo_id = g.id
        INNER JOIN cursos c ON g.curso_id = c.id
        WHERE YEAR(p.fecha_registro) = ?
        GROUP BY c.id, c.nombre
        ORDER BY pagado DESC
    ");
    $stmt->execute([$year_selected]);
    $ingresos_curso = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("
        SELECT u.nombre as estudiante, u.email,
               COUNT(p.id) as pagos_vencidos,
               SUM(p.monto) as deuda_total,
               MIN(p.fecha_vencimiento) as vencimiento_mas_antiguo
        FROM pagos p
        INNER JOIN usuarios u ON p.estudiante_id = u.id
        WHERE p.estado = 'vencido'
        GROUP BY p.estudiante_id, u.nombre, u.email
        ORDER BY deuda_total DESC
        LIMIT 10
    ");
    $deudores = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("
        SELECT metodo_pago, COUNT(*) as cantidad, SUM(monto) as total
        FROM pagos WHERE estado='pagado'
        GROUP BY metodo_pago
    ");
    $metodos_pago = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT MONTH(fecha_preinscripcion) as mes,
            COUNT(CASE WHEN estado='pendiente' THEN 1 END) as preinscripciones,
            COUNT(CASE WHEN estado='matriculado' THEN 1 END) as matriculas,
            COUNT(CASE WHEN estado='rechazado' THEN 1 END) as rechazadas
        FROM preinscripciones
        WHERE YEAR(fecha_preinscripcion)=?
        GROUP BY MONTH(fecha_preinscripcion)
        ORDER BY mes
    ");
    $stmt->execute([$year_selected]);
    $inscripciones_mes_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("
        SELECT 
            COUNT(CASE WHEN estado='pendiente' THEN 1 END) as pendientes,
            COUNT(CASE WHEN estado='matriculado' THEN 1 END) as aprobadas,
            COUNT(CASE WHEN estado='rechazado' THEN 1 END) as rechazadas,
            COUNT(CASE WHEN estado='contactado' THEN 1 END) as contactadas,
            COUNT(*) as total
        FROM preinscripciones
    ");
    $estados_preinscripciones = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_preinsc = $estados_preinscripciones['total'] ?: 1;

    $stmt = $pdo->query("
        SELECT programa,
               COUNT(*) as solicitudes,
               COUNT(CASE WHEN estado='matriculado' THEN 1 END) as convertidas,
               ROUND(COUNT(CASE WHEN estado='matriculado' THEN 1 END)*100.0/COUNT(*),1) as tasa
        FROM preinscripciones
        GROUP BY programa
        ORDER BY solicitudes DESC
        LIMIT 8
    ");
    $conversion_programa = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("
        SELECT COALESCE(municipio,'No registra') as municipio, COUNT(*) as total
        FROM preinscripciones
        GROUP BY municipio
        ORDER BY total DESC
        LIMIT 6
    ");
    $preinscr_municipio = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT MONTH(fecha_registro) as mes, COUNT(*) as total
        FROM usuarios
        WHERE rol='estudiante' AND estado='activo' AND YEAR(fecha_registro)=?
        GROUP BY MONTH(fecha_registro)
        ORDER BY mes
    ");
    $stmt->execute([$year_selected]);
    $nuevos_por_mes_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("
        SELECT c.nombre as curso, 
               AVG(cal.calificacion) as promedio,
               COUNT(cal.id) as total_evaluaciones,
               MIN(cal.calificacion) as minimo,
               MAX(cal.calificacion) as maximo
        FROM calificaciones cal
        INNER JOIN matriculas m ON cal.matricula_id = m.id
        INNER JOIN grupos g ON m.grupo_id = g.id
        INNER JOIN cursos c ON g.curso_id = c.id
        GROUP BY c.id, c.nombre
        ORDER BY promedio DESC
    ");
    $promedios_cursos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("
        SELECT u.nombre as estudiante,
               c.nombre as curso,
               AVG(cal.calificacion) as promedio,
               COUNT(cal.id) as evaluaciones,
               COUNT(CASE WHEN a.estado='presente' THEN 1 END) as presencias,
               COUNT(CASE WHEN a.estado='ausente' THEN 1 END) as ausencias,
               COUNT(a.id) as total_clases
        FROM calificaciones cal
        INNER JOIN matriculas m ON cal.matricula_id = m.id
        INNER JOIN usuarios u ON m.estudiante_id = u.id
        INNER JOIN grupos g ON m.grupo_id = g.id
        INNER JOIN cursos c ON g.curso_id = c.id
        LEFT JOIN asistencias a ON a.matricula_id = m.id
        GROUP BY m.id, u.nombre, c.nombre
        ORDER BY promedio DESC
        LIMIT 12
    ");
    $rendimiento_estudiantes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("
        SELECT 
            COUNT(CASE WHEN estado='presente' THEN 1 END) as presentes,
            COUNT(CASE WHEN estado='ausente' THEN 1 END) as ausentes,
            COUNT(CASE WHEN estado='justificado' THEN 1 END) as justificados,
            COUNT(CASE WHEN estado='tardanza' THEN 1 END) as tardanzas,
            COUNT(*) as total
        FROM asistencias
    ");
    $asistencia_global = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_asistencias = $asistencia_global['total'] ?: 1;

    $stmt = $pdo->query("
        SELECT g.nombre as grupo, c.nombre as curso,
               u.nombre as profesor,
               COUNT(CASE WHEN a.estado='presente' THEN 1 END) as presentes,
               COUNT(a.id) as total,
               ROUND(COUNT(CASE WHEN a.estado='presente' THEN 1 END)*100.0/NULLIF(COUNT(a.id),0),1) as porcentaje
        FROM asistencias a
        INNER JOIN matriculas m ON a.matricula_id = m.id
        INNER JOIN grupos g ON m.grupo_id = g.id
        INNER JOIN cursos c ON g.curso_id = c.id
        LEFT JOIN usuarios u ON g.profesor_id = u.id
        GROUP BY g.id, g.nombre, c.nombre, u.nombre
        HAVING total > 0
        ORDER BY porcentaje DESC
    ");
    $asistencia_grupos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("
        SELECT cc.*, u.nombre as estudiante, c.nombre as curso, u2.nombre as aprobado_nombre
        FROM calificaciones_certificados cc
        INNER JOIN usuarios u ON cc.estudiante_id = u.id
        INNER JOIN cursos c ON cc.curso_id = c.id
        LEFT JOIN usuarios u2 ON cc.aprobado_por = u2.id
        ORDER BY cc.fecha_generacion DESC
        LIMIT 10
    ");
    $certificados_lista = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("SELECT COUNT(*) as t, COUNT(CASE WHEN estado='aprobado' THEN 1 END) as a, COUNT(CASE WHEN estado='reprobado' THEN 1 END) as r FROM calificaciones_certificados");
    $cert_stats = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("
        SELECT u.nombre as profesor, COUNT(b.id) as total_bitacoras,
               MAX(b.fecha_clase) as ultima_clase
        FROM bitacoras b
        INNER JOIN usuarios u ON b.profesor_id = u.id
        WHERE b.estado = 'activo'
        GROUP BY b.profesor_id, u.nombre
        ORDER BY total_bitacoras DESC
    ");
    $bitacoras_profesor = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("
        SELECT c.nombre as curso, c.nivel, c.estado as estado_curso,
               c.precio_mensual,
               COUNT(DISTINCT m.estudiante_id) as estudiantes,
               COUNT(DISTINCT g.id) as grupos,
               c.cupo_maximo,
               ROUND(COUNT(DISTINCT m.estudiante_id)*100.0/NULLIF(c.cupo_maximo*COUNT(DISTINCT g.id),0),1) as ocupacion
        FROM cursos c
        LEFT JOIN grupos g ON c.id = g.curso_id AND g.estado='activo'
        LEFT JOIN matriculas m ON g.id = m.grupo_id AND m.estado='activa'
        GROUP BY c.id, c.nombre, c.nivel, c.estado, c.cupo_maximo, c.precio_mensual
        ORDER BY estudiantes DESC
    ");
    $estudiantes_por_curso = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("SELECT estado, COUNT(*) as total FROM grupos GROUP BY estado");
    $estado_grupos_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $estado_grupos = array_column($estado_grupos_raw, 'total', 'estado');

    $stmt = $pdo->query("
        SELECT g.nombre, c.nombre as curso, u.nombre as profesor,
               g.cupo_actual, g.cupo_maximo,
               ROUND(g.cupo_actual*100.0/NULLIF(g.cupo_maximo,0),0) as ocupacion_pct,
               COUNT(b.id) as total_bitacoras
        FROM grupos g
        INNER JOIN cursos c ON g.curso_id = c.id
        LEFT JOIN usuarios u ON g.profesor_id = u.id
        LEFT JOIN bitacoras b ON g.id = b.grupo_id AND b.estado='activo'
        WHERE g.estado='activo'
        GROUP BY g.id, g.nombre, c.nombre, u.nombre, g.cupo_actual, g.cupo_maximo
        ORDER BY ocupacion_pct DESC
    ");
    $ocupacion_grupos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT MONTH(fecha_acceso) as mes, COUNT(*) as total
        FROM logs_acceso
        WHERE YEAR(fecha_acceso)=?
        GROUP BY MONTH(fecha_acceso)
        ORDER BY mes
    ");
    $stmt->execute([$year_selected]);
    $accesos_mes_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("SELECT rol, COUNT(*) as total FROM usuarios WHERE estado='activo' GROUP BY rol");
    $roles_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $roles = array_column($roles_raw, 'total', 'rol');

    $stmt = $pdo->query("
        SELECT u.nombre, u.email,
               COUNT(DISTINCT g.id) as grupos_activos,
               COUNT(DISTINCT m.estudiante_id) as estudiantes_total,
               COUNT(DISTINCT b.id) as bitacoras
        FROM usuarios u
        LEFT JOIN grupos g ON g.profesor_id = u.id AND g.estado='activo'
        LEFT JOIN matriculas m ON m.grupo_id = g.id AND m.estado='activa'
        LEFT JOIN bitacoras b ON b.profesor_id = u.id AND b.estado='activo'
        WHERE u.rol = 'profesor' AND u.estado = 'activo'
        GROUP BY u.id, u.nombre, u.email
        ORDER BY estudiantes_total DESC
    ");
    $profesores_detalle = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Error reportes: " . $e->getMessage());
    $total_estudiantes = $matriculas_activas = $preinscripciones_pendientes = $grupos_activos = 0;
    $estudiantes_nuevos_anio = $matriculas_anio = 0;
    $financiero_anio = ['ingresos_confirmados'=>0,'ingresos_pendientes'=>0,'ingresos_vencidos'=>0,'total_facturado'=>0,'pagos_ok'=>0,'pagos_vencidos'=>0,'total_pagos'=>0,'anulados'=>0];
    $ingresos_mes_raw = $ingresos_curso = $deudores = $metodos_pago = [];
    $inscripciones_mes_raw = $nuevos_por_mes_raw = $conversion_programa = $preinscr_municipio = [];
    $estados_preinscripciones = ['pendientes'=>0,'aprobadas'=>0,'rechazadas'=>0,'contactadas'=>0,'total'=>0];
    $total_preinsc = 1;
    $promedios_cursos = $rendimiento_estudiantes = $asistencia_grupos = $certificados_lista = $bitacoras_profesor = [];
    $asistencia_global = ['presentes'=>0,'ausentes'=>0,'justificados'=>0,'tardanzas'=>0,'total'=>0];
    $total_asistencias = 1;
    $cert_stats = ['t'=>0,'a'=>0,'r'=>0];
    $estudiantes_por_curso = $ocupacion_grupos = [];
    $estado_grupos = [];
    $accesos_mes_raw = $roles = $profesores_detalle = [];
}

$meses_labels = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];

$inscr_data = ['preinscripciones'=>array_fill(0,12,0),'matriculas'=>array_fill(0,12,0),'rechazadas'=>array_fill(0,12,0)];
foreach ($inscripciones_mes_raw as $d) {
    $i = (int)$d['mes'] - 1;
    $inscr_data['preinscripciones'][$i] = (int)$d['preinscripciones'];
    $inscr_data['matriculas'][$i]       = (int)$d['matriculas'];
    $inscr_data['rechazadas'][$i]       = (int)$d['rechazadas'];
}

$nuevos_data = array_fill(0, 12, 0);
foreach ($nuevos_por_mes_raw as $d) { $nuevos_data[(int)$d['mes']-1] = (int)$d['total']; }

$accesos_data = array_fill(0, 12, 0);
foreach ($accesos_mes_raw as $d) { $accesos_data[(int)$d['mes']-1] = (int)$d['total']; }

$ingresos_confirmados_mes = array_fill(0, 12, 0);
$ingresos_vencidos_mes    = array_fill(0, 12, 0);
$ingresos_pendientes_mes  = array_fill(0, 12, 0);
foreach ($ingresos_mes_raw as $d) {
    $i = (int)$d['mes'] - 1;
    $ingresos_confirmados_mes[$i] = (float)$d['confirmados'];
    $ingresos_vencidos_mes[$i]    = (float)$d['vencidos'];
    $ingresos_pendientes_mes[$i]  = (float)$d['pendientes'];
}

$cursos_nombres_js   = array_column($promedios_cursos, 'curso');
$cursos_promedios_js = array_map(fn($x) => round((float)$x['promedio'], 2), $promedios_cursos);
$grupos_nombres_js   = array_column($asistencia_grupos, 'grupo');
$grupos_asist_js     = array_column($asistencia_grupos, 'porcentaje');
$epc_nombres_js      = array_column($estudiantes_por_curso, 'curso');
$epc_valores_js      = array_column($estudiantes_por_curso, 'estudiantes');

$conv_prog_labels  = array_column($conversion_programa, 'programa');
$conv_prog_tasa    = array_column($conversion_programa, 'tasa');
$municipio_labels  = array_column($preinscr_municipio, 'municipio');
$municipio_values  = array_column($preinscr_municipio, 'total');

$ingreso_curso_labels  = array_column($ingresos_curso, 'curso');
$ingreso_curso_pagado  = array_map(fn($x) => (float)$x['pagado'], $ingresos_curso);
$ingreso_curso_vencido = array_map(fn($x) => (float)$x['vencido'], $ingresos_curso);

$metodo_labels = array_column($metodos_pago, 'metodo_pago');
$metodo_values = array_map(fn($x) => (float)$x['total'], $metodos_pago);
$metodo_counts = array_map(fn($x) => (int)$x['cantidad'], $metodos_pago);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reportes — Amimbré</title>
    <link rel="shortcut icon" href="../../assets/img/3.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0"/>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="../../assets/css/colores.css">
    <link rel="stylesheet" href="../../assets/css/style-reportes.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0/dist/chartjs-plugin-datalabels.min.js"></script>
    <script>(function(){const t=localStorage.getItem('amimbre-theme');if(t==='light')document.documentElement.setAttribute('data-theme','light');})();</script>
</head>
<body>
<?php if (file_exists('../../includes/header.php')) require_once '../../includes/header.php'; ?>

<div id="pdf-overlay">
    <div class="pdf-spinner"></div>
    <p id="pdf-msg">Generando reporte…</p>
    <small id="pdf-sub">Por favor espera, esto puede tomar unos segundos</small>
</div>

<main class="main-content" id="main-content">

    <!-- Header -->
    <div class="page-header">
        <div class="header-left">
            <button class="btn-back" onclick="window.history.back()">
                <span class="material-symbols-rounded">arrow_back</span>
            </button>
            <div>
                <h1>Reportes</h1>
                <p>Análisis, estadísticas y reportes descargables del sistema</p>
            </div>
        </div>
        <div class="header-controls">
            <select class="year-select" id="yearSelect" onchange="changeYear(this.value)">
                <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                    <option value="<?= $y ?>" <?= $y == $year_selected ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
            <button class="btn btn-ghost" onclick="window.print()">
                <span class="material-symbols-rounded">print</span> Imprimir
            </button>
            <button class="btn btn-blue" onclick="exportarPDFGeneral()">
                <span class="material-symbols-rounded">picture_as_pdf</span> PDF General
            </button>
        </div>
    </div>

    <!-- KPI Cards principales -->
    <div class="stats-grid">
        <div class="stat-card blue">
            <div class="stat-header">
                <span class="stat-title">Estudiantes Activos</span>
                <div class="stat-icon"><span class="material-symbols-rounded">school</span></div>
            </div>
            <div class="stat-value"><?= number_format($total_estudiantes) ?></div>
            <div class="stat-change pos"><span class="material-symbols-rounded">arrow_upward</span><?= $estudiantes_nuevos_anio ?> nuevos en <?= $year_selected ?></div>
        </div>
        <div class="stat-card green">
            <div class="stat-header">
                <span class="stat-title">Ingresos Confirmados</span>
                <div class="stat-icon"><span class="material-symbols-rounded">payments</span></div>
            </div>
            <div class="stat-value">$<?= number_format((float)($financiero_anio['ingresos_confirmados']??0), 0, ',', '.') ?></div>
            <div class="stat-change pos"><span class="material-symbols-rounded">check_circle</span><?= $financiero_anio['pagos_ok']??0 ?> pagos recibidos en <?= $year_selected ?></div>
        </div>
        <div class="stat-card red">
            <div class="stat-header">
                <span class="stat-title">Cartera Vencida</span>
                <div class="stat-icon"><span class="material-symbols-rounded">warning</span></div>
            </div>
            <div class="stat-value">$<?= number_format((float)($financiero_anio['ingresos_vencidos']??0), 0, ',', '.') ?></div>
            <div class="stat-change neg"><span class="material-symbols-rounded">error</span><?= $financiero_anio['pagos_vencidos']??0 ?> pagos vencidos</div>
        </div>
        <div class="stat-card orange">
            <div class="stat-header">
                <span class="stat-title">Grupos Activos</span>
                <div class="stat-icon"><span class="material-symbols-rounded">groups</span></div>
            </div>
            <div class="stat-value"><?= number_format($grupos_activos) ?></div>
            <div class="stat-change"><span class="material-symbols-rounded">music_note</span>Matrículas activas: <?= $matriculas_activas ?></div>
        </div>
        <div class="stat-card yellow">
            <div class="stat-header">
                <span class="stat-title">Preinscripciones Pend.</span>
                <div class="stat-icon"><span class="material-symbols-rounded">pending_actions</span></div>
            </div>
            <div class="stat-value"><?= number_format($preinscripciones_pendientes) ?></div>
            <div class="stat-change"><span class="material-symbols-rounded">schedule</span>Por revisar y procesar</div>
        </div>
    </div>

    <!-- TABS -->
    <div class="tabs-container" id="reporte-tabs">
        <div class="tabs-nav">
            <button class="tab-btn active" data-tab="tab-descargables">
                <span class="material-symbols-rounded">download</span> Reportes
            </button>
            <button class="tab-btn" data-tab="tab-financiero">
                <span class="material-symbols-rounded">payments</span> Financiero
            </button>
            <button class="tab-btn" data-tab="tab-inscripciones">
                <span class="material-symbols-rounded">description</span> Inscripciones
            </button>
            <button class="tab-btn" data-tab="tab-academico">
                <span class="material-symbols-rounded">school</span> Académico
            </button>
            <button class="tab-btn" data-tab="tab-cursos">
                <span class="material-symbols-rounded">menu_book</span> Cursos
            </button>
            <button class="tab-btn" data-tab="tab-actividad">
                <span class="material-symbols-rounded">monitoring</span> Actividad
            </button>
        </div>

        <!-- TAB 1: REPORTES DESCARGABLES -->
        <div class="tab-content active" id="tab-descargables">
            <div class="alert-info">
                <span class="material-symbols-rounded">info</span>
                Genera y descarga reportes específicos en PDF con datos actualizados al instante. Los PDFs se generan en modo claro para mejor legibilidad al imprimir.
            </div>
            <div class="reportes-descargables">
                <div class="reporte-card">
                    <div class="reporte-card-header">
                        <div class="reporte-card-icon green"><span class="material-symbols-rounded">account_balance_wallet</span></div>
                        <div>
                            <div class="reporte-card-title">Reporte Financiero</div>
                            <div style="font-size:.75rem;color:var(--text-secondary)"><?= $year_selected ?></div>
                        </div>
                    </div>
                    <div class="reporte-card-desc">Ingresos confirmados, cartera vencida, pagos pendientes, desglose por curso y métodos de pago. Incluye listado de estudiantes con deuda.</div>
                    <div class="reporte-card-actions">
                        <button class="btn btn-green btn-sm" onclick="descargarReporte('financiero')">
                            <span class="material-symbols-rounded">picture_as_pdf</span> Descargar PDF
                        </button>
                        <button class="btn btn-ghost btn-sm" onclick="switchTab('tab-financiero')">
                            <span class="material-symbols-rounded">bar_chart</span> Ver gráficas
                        </button>
                    </div>
                </div>
                <div class="reporte-card">
                    <div class="reporte-card-header">
                        <div class="reporte-card-icon blue"><span class="material-symbols-rounded">grade</span></div>
                        <div>
                            <div class="reporte-card-title">Rendimiento Académico</div>
                            <div style="font-size:.75rem;color:var(--text-secondary)">Calificaciones y asistencia</div>
                        </div>
                    </div>
                    <div class="reporte-card-desc">Promedios por curso, rendimiento individual de cada estudiante, porcentajes de asistencia y certificados generados.</div>
                    <div class="reporte-card-actions">
                        <button class="btn btn-blue btn-sm" onclick="descargarReporte('academico')">
                            <span class="material-symbols-rounded">picture_as_pdf</span> Descargar PDF
                        </button>
                        <button class="btn btn-ghost btn-sm" onclick="switchTab('tab-academico')">
                            <span class="material-symbols-rounded">bar_chart</span> Ver gráficas
                        </button>
                    </div>
                </div>
                <div class="reporte-card">
                    <div class="reporte-card-header">
                        <div class="reporte-card-icon orange"><span class="material-symbols-rounded">how_to_reg</span></div>
                        <div>
                            <div class="reporte-card-title">Inscripciones y Matrículas</div>
                            <div style="font-size:.75rem;color:var(--text-secondary)"><?= $year_selected ?></div>
                        </div>
                    </div>
                    <div class="reporte-card-desc">Flujo de preinscripciones por mes, tasas de conversión por programa, procedencia geográfica y estado de solicitudes.</div>
                    <div class="reporte-card-actions">
                        <button class="btn btn-orange btn-sm" onclick="descargarReporte('inscripciones')">
                            <span class="material-symbols-rounded">picture_as_pdf</span> Descargar PDF
                        </button>
                        <button class="btn btn-ghost btn-sm" onclick="switchTab('tab-inscripciones')">
                            <span class="material-symbols-rounded">bar_chart</span> Ver gráficas
                        </button>
                    </div>
                </div>
                <div class="reporte-card">
                    <div class="reporte-card-header">
                        <div class="reporte-card-icon red"><span class="material-symbols-rounded">running_with_errors</span></div>
                        <div>
                            <div class="reporte-card-title">Cartera Vencida</div>
                            <div style="font-size:.75rem;color:var(--text-secondary)">Cobranza y seguimiento</div>
                        </div>
                    </div>
                    <div class="reporte-card-desc">Listado completo de estudiantes con pagos vencidos, monto adeudado, fecha de vencimiento y datos de contacto para gestión de cobro.</div>
                    <div class="reporte-card-actions">
                        <button class="btn btn-sm" style="background:var(--primary-red);color:#fff" onclick="descargarReporte('cartera')">
                            <span class="material-symbols-rounded">picture_as_pdf</span> Descargar PDF
                        </button>
                    </div>
                </div>
                <div class="reporte-card">
                    <div class="reporte-card-header">
                        <div class="reporte-card-icon teal"><span class="material-symbols-rounded">event_available</span></div>
                        <div>
                            <div class="reporte-card-title">Control de Asistencia</div>
                            <div style="font-size:.75rem;color:var(--text-secondary)">Por grupo y estudiante</div>
                        </div>
                    </div>
                    <div class="reporte-card-desc">Porcentaje de asistencia por grupo, estudiantes con alta inasistencia, registros de bitácoras por profesor y compromisos de clase.</div>
                    <div class="reporte-card-actions">
                        <button class="btn btn-sm" style="background:#14b8a6;color:#fff" onclick="descargarReporte('asistencia')">
                            <span class="material-symbols-rounded">picture_as_pdf</span> Descargar PDF
                        </button>
                        <button class="btn btn-ghost btn-sm" onclick="switchTab('tab-academico')">
                            <span class="material-symbols-rounded">bar_chart</span> Ver datos
                        </button>
                    </div>
                </div>
                <div class="reporte-card">
                    <div class="reporte-card-header">
                        <div class="reporte-card-icon yellow"><span class="material-symbols-rounded">library_music</span></div>
                        <div>
                            <div class="reporte-card-title">Cursos y Ocupación</div>
                            <div style="font-size:.75rem;color:var(--text-secondary)">Grupos y disponibilidad</div>
                        </div>
                    </div>
                    <div class="reporte-card-desc">Capacidad y ocupación de cada grupo activo, ingresos proyectados por curso, nivel de estudiantes y detalle de profesores asignados.</div>
                    <div class="reporte-card-actions">
                        <button class="btn btn-sm" style="background:var(--primary-yellow);color:#000" onclick="descargarReporte('cursos')">
                            <span class="material-symbols-rounded">picture_as_pdf</span> Descargar PDF
                        </button>
                        <button class="btn btn-ghost btn-sm" onclick="switchTab('tab-cursos')">
                            <span class="material-symbols-rounded">bar_chart</span> Ver datos
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB 2: FINANCIERO -->
        <div class="tab-content" id="tab-financiero">
            <div class="kpi-row">
                <div class="kpi-card success">
                    <div class="kpi-icon"><span class="material-symbols-rounded">paid</span></div>
                    <div>
                        <div class="kpi-value">$<?= number_format((float)($financiero_anio['ingresos_confirmados']??0),0,',','.') ?></div>
                        <div class="kpi-label">Confirmados</div>
                        <div class="kpi-desc"><?= $financiero_anio['pagos_ok']??0 ?> pagos</div>
                    </div>
                </div>
                <div class="kpi-card danger">
                    <div class="kpi-icon"><span class="material-symbols-rounded">warning</span></div>
                    <div>
                        <div class="kpi-value">$<?= number_format((float)($financiero_anio['ingresos_vencidos']??0),0,',','.') ?></div>
                        <div class="kpi-label">Vencidos</div>
                        <div class="kpi-desc"><?= $financiero_anio['pagos_vencidos']??0 ?> sin pagar</div>
                    </div>
                </div>
                <div class="kpi-card warning">
                    <div class="kpi-icon"><span class="material-symbols-rounded">hourglass_top</span></div>
                    <div>
                        <div class="kpi-value">$<?= number_format((float)($financiero_anio['ingresos_pendientes']??0),0,',','.') ?></div>
                        <div class="kpi-label">Pendientes</div>
                        <div class="kpi-desc">Por vencer</div>
                    </div>
                </div>
                <div class="kpi-card info">
                    <div class="kpi-icon"><span class="material-symbols-rounded">receipt_long</span></div>
                    <div>
                        <div class="kpi-value">$<?= number_format((float)($financiero_anio['total_facturado']??0),0,',','.') ?></div>
                        <div class="kpi-label">Total facturado</div>
                        <div class="kpi-desc"><?= $financiero_anio['total_pagos']??0 ?> cobros</div>
                    </div>
                </div>
                <?php
                $tasa_cobro = (($financiero_anio['total_facturado']??0) > 0)
                    ? round(($financiero_anio['ingresos_confirmados']??0)*100/($financiero_anio['total_facturado']??1),1)
                    : 0;
                ?>
                <div class="kpi-card <?= $tasa_cobro >= 70 ? 'success' : ($tasa_cobro >= 40 ? 'warning' : 'danger') ?>">
                    <div class="kpi-icon"><span class="material-symbols-rounded">percent</span></div>
                    <div>
                        <div class="kpi-value"><?= $tasa_cobro ?>%</div>
                        <div class="kpi-label">Tasa de cobro</div>
                        <div class="kpi-desc">Efectividad</div>
                    </div>
                </div>
            </div>

            <div class="charts-grid">
                <div class="chart-card full">
                    <div class="chart-header">
                        <div class="chart-header-left">
                            <h3><span class="material-symbols-rounded">area_chart</span> Ingresos por Mes — <?= $year_selected ?></h3>
                            <p>Comparativa mensual en COP de pagos confirmados, vencidos y pendientes</p>
                        </div>
                        <button class="btn btn-green btn-sm" onclick="descargarReporte('financiero')">
                            <span class="material-symbols-rounded">download</span> PDF
                        </button>
                    </div>
                    <div class="chart-container"><canvas id="ingresosChart"></canvas></div>
                    <div class="chart-legend">
                        <div class="legend-item"><div class="legend-dot" style="background:#22c55e"></div><strong>Pagos confirmados</strong> — dinero efectivamente recibido</div>
                        <div class="legend-item"><div class="legend-dot" style="background:#ef4444"></div><strong>Cartera vencida</strong> — pagos que no se realizaron a tiempo</div>
                        <div class="legend-item"><div class="legend-dot" style="background:#eab308"></div><strong>Pendientes</strong> — pagos aún dentro del plazo</div>
                    </div>
                </div>

                <?php if (!empty($ingresos_curso)): ?>
                <div class="chart-card">
                    <div class="chart-header">
                        <div class="chart-header-left">
                            <h3><span class="material-symbols-rounded">library_music</span> Ingresos por Curso</h3>
                            <p>Monto pagado y vencido por programa en <?= $year_selected ?></p>
                        </div>
                    </div>
                    <div class="chart-container"><canvas id="ingresosCursoChart"></canvas></div>
                    <div class="chart-legend">
                        <div class="legend-item"><div class="legend-dot" style="background:#22c55e"></div><strong>Pagado</strong> — ingresos confirmados por curso</div>
                        <div class="legend-item"><div class="legend-dot" style="background:#ef4444"></div><strong>Vencido</strong> — deuda acumulada por curso</div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($metodos_pago)): ?>
                <div class="chart-card">
                    <div class="chart-header">
                        <div class="chart-header-left">
                            <h3><span class="material-symbols-rounded">credit_card</span> Métodos de Pago</h3>
                            <p>Distribución de pagos recibidos por canal — % del total y monto COP</p>
                        </div>
                    </div>
                    <div class="chart-container"><canvas id="metodosChart"></canvas></div>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($deudores)): ?>
            <h3 class="section-title"><span class="material-symbols-rounded">person_alert</span> Estudiantes con Cartera Vencida</h3>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>#</th><th>Estudiante</th><th>Email</th><th>Pagos vencidos</th><th>Deuda total</th><th>Vencimiento más antiguo</th></tr></thead>
                    <tbody>
                    <?php foreach ($deudores as $i => $d): ?>
                        <tr>
                            <td><?= $i+1 ?></td>
                            <td><strong><?= htmlspecialchars($d['estudiante']) ?></strong></td>
                            <td style="color:var(--text-secondary)"><?= htmlspecialchars($d['email']) ?></td>
                            <td><span class="badge badge-red"><?= $d['pagos_vencidos'] ?> pago(s)</span></td>
                            <td><strong style="color:var(--primary-red)">$<?= number_format((float)$d['deuda_total'],0,',','.') ?></strong></td>
                            <td style="font-size:.8rem;color:var(--text-secondary)"><?= $d['vencimiento_mas_antiguo'] ? date('d/m/Y', strtotime($d['vencimiento_mas_antiguo'])) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- TAB 3: INSCRIPCIONES -->
        <div class="tab-content" id="tab-inscripciones">
            <div class="kpi-row">
                <div class="kpi-card success">
                    <div class="kpi-icon"><span class="material-symbols-rounded">check_circle</span></div>
                    <div>
                        <div class="kpi-value"><?= $estados_preinscripciones['aprobadas'] ?></div>
                        <div class="kpi-label">Aprobadas</div>
                        <div class="kpi-desc"><?= $total_preinsc > 1 ? round($estados_preinscripciones['aprobadas']*100/$total_preinsc) : 0 ?>% del total</div>
                    </div>
                </div>
                <div class="kpi-card warning">
                    <div class="kpi-icon"><span class="material-symbols-rounded">schedule</span></div>
                    <div>
                        <div class="kpi-value"><?= $estados_preinscripciones['pendientes'] ?></div>
                        <div class="kpi-label">Pendientes</div>
                        <div class="kpi-desc">Por procesar</div>
                    </div>
                </div>
                <div class="kpi-card info">
                    <div class="kpi-icon"><span class="material-symbols-rounded">contact_phone</span></div>
                    <div>
                        <div class="kpi-value"><?= $estados_preinscripciones['contactadas'] ?></div>
                        <div class="kpi-label">Contactadas</div>
                        <div class="kpi-desc">En seguimiento</div>
                    </div>
                </div>
                <div class="kpi-card danger">
                    <div class="kpi-icon"><span class="material-symbols-rounded">cancel</span></div>
                    <div>
                        <div class="kpi-value"><?= $estados_preinscripciones['rechazadas'] ?></div>
                        <div class="kpi-label">Rechazadas</div>
                        <div class="kpi-desc"><?= $total_preinsc > 1 ? round($estados_preinscripciones['rechazadas']*100/$total_preinsc) : 0 ?>% del total</div>
                    </div>
                </div>
                <div class="kpi-card orange">
                    <div class="kpi-icon"><span class="material-symbols-rounded">summarize</span></div>
                    <div>
                        <div class="kpi-value"><?= $estados_preinscripciones['total'] ?></div>
                        <div class="kpi-label">Total histórico</div>
                        <div class="kpi-desc">Todas las solicitudes</div>
                    </div>
                </div>
            </div>

            <div class="charts-grid">
                <div class="chart-card full">
                    <div class="chart-header">
                        <div class="chart-header-left">
                            <h3><span class="material-symbols-rounded">bar_chart</span> Inscripciones por Mes — <?= $year_selected ?></h3>
                            <p>Flujo mensual de solicitudes, aprobaciones y rechazos</p>
                        </div>
                        <button class="btn btn-orange btn-sm" onclick="descargarReporte('inscripciones')">
                            <span class="material-symbols-rounded">download</span> PDF
                        </button>
                    </div>
                    <div class="chart-container"><canvas id="inscripcionesChart"></canvas></div>
                    <div class="chart-legend">
                        <div class="legend-item"><div class="legend-dot" style="background:#3b82f6"></div><strong>Preinscripciones</strong> — solicitudes recibidas en el mes</div>
                        <div class="legend-item"><div class="legend-dot" style="background:#22c55e"></div><strong>Matrículas</strong> — preinscripciones que se convirtieron en matrícula</div>
                        <div class="legend-item"><div class="legend-dot" style="background:#f97316"></div><strong>Rechazadas</strong> — solicitudes no aceptadas</div>
                    </div>
                </div>

                <?php if (!empty($conversion_programa)): ?>
                <div class="chart-card">
                    <div class="chart-header">
                        <div class="chart-header-left">
                            <h3><span class="material-symbols-rounded">conversion_path</span> Tasa de Conversión por Programa</h3>
                            <p>% de preinscripciones que se convirtieron en matrícula activa</p>
                        </div>
                    </div>
                    <div class="chart-container"><canvas id="conversionChart"></canvas></div>
                    <div class="chart-legend">
                        <div class="legend-item"><div class="legend-dot" style="background:#22c55e"></div><strong>Verde</strong> ≥ 70% conversión — rendimiento alto</div>
                        <div class="legend-item"><div class="legend-dot" style="background:#3b82f6"></div><strong>Azul</strong> 40–69% — conversión media</div>
                        <div class="legend-item"><div class="legend-dot" style="background:#f97316"></div><strong>Naranja</strong> &lt; 40% — requiere atención</div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="chart-card">
                    <div class="chart-header">
                        <div class="chart-header-left">
                            <h3><span class="material-symbols-rounded">donut_large</span> Estado General de Preinscripciones</h3>
                            <p>Distribución histórica de todas las solicitudes recibidas — con porcentaje y cantidad</p>
                        </div>
                    </div>
                    <div class="chart-container"><canvas id="estadosChart"></canvas></div>
                </div>

                <?php if (!empty($preinscr_municipio)): ?>
                <div class="chart-card">
                    <div class="chart-header">
                        <div class="chart-header-left">
                            <h3><span class="material-symbols-rounded">location_on</span> Procedencia Geográfica</h3>
                            <p>Municipio de residencia declarado en la preinscripción</p>
                        </div>
                    </div>
                    <div class="chart-container"><canvas id="municipioChart"></canvas></div>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($conversion_programa)): ?>
            <h3 class="section-title"><span class="material-symbols-rounded">conversion_path</span> Conversión por Programa</h3>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Programa</th><th>Solicitudes</th><th>Convertidas</th><th>Tasa de conversión</th></tr></thead>
                    <tbody>
                    <?php foreach ($conversion_programa as $cp): ?>
                        <?php $tc = (float)$cp['tasa']; $ccolor = $tc >= 70 ? 'green' : ($tc >= 40 ? 'blue' : 'orange'); ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($cp['programa']) ?></strong></td>
                            <td><?= $cp['solicitudes'] ?></td>
                            <td><?= $cp['convertidas'] ?></td>
                            <td>
                                <div style="display:flex;align-items:center;gap:10px;">
                                    <div class="progress-wrap" style="flex:1"><div class="progress-fill <?= $ccolor ?>" style="width:<?= $tc ?>%"></div></div>
                                    <span style="font-size:.8rem;font-weight:600;min-width:40px;"><?= $tc ?>%</span>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- TAB 4: ACADÉMICO -->
        <div class="tab-content" id="tab-academico">
            <div class="kpi-row">
                <div class="kpi-card info">
                    <div class="kpi-icon"><span class="material-symbols-rounded">workspace_premium</span></div>
                    <div>
                        <div class="kpi-value"><?= $cert_stats['t']??0 ?></div>
                        <div class="kpi-label">Certificados</div>
                        <div class="kpi-desc"><?= $cert_stats['a']??0 ?> aprobados</div>
                    </div>
                </div>
                <div class="kpi-card success">
                    <div class="kpi-icon"><span class="material-symbols-rounded">assignment</span></div>
                    <div>
                        <?php $tot_bit = array_sum(array_column($bitacoras_profesor,'total_bitacoras')); ?>
                        <div class="kpi-value"><?= $tot_bit ?></div>
                        <div class="kpi-label">Bitácoras</div>
                        <div class="kpi-desc">Clases registradas</div>
                    </div>
                </div>
                <div class="kpi-card orange">
                    <div class="kpi-icon"><span class="material-symbols-rounded">how_to_reg</span></div>
                    <div>
                        <div class="kpi-value"><?= number_format($asistencia_global['total']) ?></div>
                        <div class="kpi-label">Reg. asistencia</div>
                        <div class="kpi-desc"><?= $asistencia_global['total']>0 ? round($asistencia_global['presentes']*100/$asistencia_global['total']) : 0 ?>% presencia</div>
                    </div>
                </div>
                <?php
                $total_cal_all = array_sum(array_column($promedios_cursos,'total_evaluaciones'));
                $prom_gl = $total_cal_all > 0
                    ? array_sum(array_map(fn($x)=>$x['promedio']*$x['total_evaluaciones'],$promedios_cursos)) / $total_cal_all
                    : 0;
                ?>
                <div class="kpi-card <?= $prom_gl >= 3.5 ? 'success' : ($prom_gl >= 3.0 ? 'warning' : 'danger') ?>">
                    <div class="kpi-icon"><span class="material-symbols-rounded">grade</span></div>
                    <div>
                        <div class="kpi-value"><?= number_format($prom_gl,1) ?></div>
                        <div class="kpi-label">Promedio global</div>
                        <div class="kpi-desc">Sobre 5.0</div>
                    </div>
                </div>
            </div>

            <div class="charts-grid">
                <?php if (!empty($promedios_cursos)): ?>
                <div class="chart-card">
                    <div class="chart-header">
                        <div class="chart-header-left">
                            <h3><span class="material-symbols-rounded">timeline</span> Promedio por Curso</h3>
                            <p>Calificación promedio (escala 0–5) con mínimo y máximo de cada programa</p>
                        </div>
                        <button class="btn btn-blue btn-sm" onclick="descargarReporte('academico')">
                            <span class="material-symbols-rounded">download</span> PDF
                        </button>
                    </div>
                    <div class="chart-container"><canvas id="promediosChart"></canvas></div>
                    <div class="chart-legend">
                        <div class="legend-item"><div class="legend-dot" style="background:#22c55e"></div><strong>Verde</strong> — promedio ≥ 4.0 (rendimiento alto)</div>
                        <div class="legend-item"><div class="legend-dot" style="background:#3b82f6"></div><strong>Azul</strong> — promedio 3.0–3.9 (rendimiento medio)</div>
                        <div class="legend-item"><div class="legend-dot" style="background:#f97316"></div><strong>Naranja</strong> — promedio &lt; 3.0 (requiere atención)</div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="chart-card">
                    <div class="chart-header">
                        <div class="chart-header-left">
                            <h3><span class="material-symbols-rounded">analytics</span> Asistencia Global</h3>
                            <p>Distribución de estados de asistencia de todos los grupos — % y cantidad de registros</p>
                        </div>
                    </div>
                    <div class="chart-container"><canvas id="asistenciaGlobalChart"></canvas></div>
                </div>

                <?php if (!empty($asistencia_grupos)): ?>
                <div class="chart-card full">
                    <div class="chart-header">
                        <div class="chart-header-left">
                            <h3><span class="material-symbols-rounded">event_available</span> % Asistencia por Grupo</h3>
                            <p>Porcentaje de clases con presencia registrada por cada grupo activo</p>
                        </div>
                        <button class="btn btn-sm" style="background:#14b8a6;color:#fff" onclick="descargarReporte('asistencia')">
                            <span class="material-symbols-rounded">download</span> PDF
                        </button>
                    </div>
                    <div class="chart-container tall"><canvas id="asistenciaGruposChart"></canvas></div>
                    <div class="chart-legend">
                        <div class="legend-item"><div class="legend-dot" style="background:#22c55e"></div><strong>Verde</strong> — asistencia ≥ 80% (óptimo)</div>
                        <div class="legend-item"><div class="legend-dot" style="background:#3b82f6"></div><strong>Azul</strong> — asistencia 60–79% (aceptable)</div>
                        <div class="legend-item"><div class="legend-dot" style="background:#f97316"></div><strong>Naranja</strong> — asistencia &lt; 60% (preocupante)</div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($rendimiento_estudiantes)): ?>
            <h3 class="section-title"><span class="material-symbols-rounded">person_search</span> Rendimiento Individual por Estudiante</h3>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Estudiante</th><th>Curso</th><th>Promedio</th><th>Evaluaciones</th><th>Asistencia</th><th>Estado</th></tr></thead>
                    <tbody>
                    <?php foreach ($rendimiento_estudiantes as $re): ?>
                        <?php
                        $prom = round((float)$re['promedio'],2);
                        $prom_color = $prom >= 3.5 ? 'badge-green' : ($prom >= 3.0 ? 'badge-yellow' : 'badge-red');
                        $asist_pct = $re['total_clases'] > 0 ? round($re['presencias']*100/$re['total_clases']) : 0;
                        $asist_color = $asist_pct >= 80 ? 'green' : ($asist_pct >= 60 ? 'blue' : 'orange');
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($re['estudiante']) ?></strong></td>
                            <td><?= htmlspecialchars($re['curso']) ?></td>
                            <td><span class="badge <?= $prom_color ?>"><?= $prom ?>/5.0</span></td>
                            <td><?= $re['evaluaciones'] ?></td>
                            <td>
                                <?php if ($re['total_clases'] > 0): ?>
                                <div style="display:flex;align-items:center;gap:8px">
                                    <div class="progress-wrap" style="width:60px"><div class="progress-fill <?= $asist_color ?>" style="width:<?= $asist_pct ?>%"></div></div>
                                    <span style="font-size:.78rem"><?= $asist_pct ?>%</span>
                                </div>
                                <?php else: ?><span style="color:var(--text-secondary);font-size:.8rem">Sin registros</span><?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?= $prom >= 3.0 ? 'badge-green' : 'badge-red' ?>"><?= $prom >= 3.0 ? 'Aprobando' : 'En riesgo' ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php if (!empty($certificados_lista)): ?>
            <h3 class="section-title"><span class="material-symbols-rounded">workspace_premium</span> Últimos Certificados Generados</h3>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Código</th><th>Estudiante</th><th>Curso</th><th>Nivel</th><th>Nota final</th><th>Estado</th><th>Fecha</th></tr></thead>
                    <tbody>
                    <?php foreach ($certificados_lista as $cert): ?>
                        <tr>
                            <td style="font-size:.75rem;font-family:monospace;color:var(--primary-blue)"><?= htmlspecialchars($cert['codigo_certificado']) ?></td>
                            <td><strong><?= htmlspecialchars($cert['estudiante']) ?></strong></td>
                            <td><?= htmlspecialchars($cert['curso']) ?></td>
                            <td><span class="badge <?= $cert['nivel_aprobado']=='basico'?'badge-green':($cert['nivel_aprobado']=='intermedio'?'badge-blue':'badge-orange') ?>"><?= ucfirst($cert['nivel_aprobado']) ?></span></td>
                            <td><?= number_format((float)$cert['calificacion_final'],2) ?>/5.0</td>
                            <td><span class="badge <?= $cert['estado']=='aprobado'?'badge-green':'badge-red' ?>"><?= ucfirst($cert['estado']) ?></span></td>
                            <td style="font-size:.8rem;color:var(--text-secondary)"><?= date('d/m/Y', strtotime($cert['fecha_generacion'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php if (!empty($bitacoras_profesor)): ?>
            <h3 class="section-title"><span class="material-symbols-rounded">book</span> Bitácoras por Profesor</h3>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Profesor</th><th>Bitácoras registradas</th><th>Última clase</th></tr></thead>
                    <tbody>
                    <?php foreach ($bitacoras_profesor as $bp): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($bp['profesor']) ?></strong></td>
                            <td><span class="badge badge-blue"><?= $bp['total_bitacoras'] ?></span></td>
                            <td style="font-size:.8rem;color:var(--text-secondary)"><?= $bp['ultima_clase'] ? date('d/m/Y', strtotime($bp['ultima_clase'])) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- TAB 5: CURSOS -->
        <div class="tab-content" id="tab-cursos">
            <div class="charts-grid">
                <?php if (!empty($estudiantes_por_curso)): ?>
                <div class="chart-card">
                    <div class="chart-header">
                        <div class="chart-header-left">
                            <h3><span class="material-symbols-rounded">groups</span> Estudiantes por Curso</h3>
                            <p>Número de matrículas activas en cada programa — con cantidad exacta</p>
                        </div>
                        <button class="btn btn-sm" style="background:var(--primary-yellow);color:#000" onclick="descargarReporte('cursos')">
                            <span class="material-symbols-rounded">download</span> PDF
                        </button>
                    </div>
                    <div class="chart-container"><canvas id="estudCursoChart"></canvas></div>
                </div>
                <?php endif; ?>
                <div class="chart-card">
                    <div class="chart-header">
                        <div class="chart-header-left">
                            <h3><span class="material-symbols-rounded">donut_large</span> Estado de Grupos</h3>
                            <p>Distribución de grupos por estado actual — % y cantidad</p>
                        </div>
                    </div>
                    <div class="chart-container"><canvas id="estadoGruposChart"></canvas></div>
                </div>
            </div>

            <?php if (!empty($ocupacion_grupos)): ?>
            <h3 class="section-title"><span class="material-symbols-rounded">density_medium</span> Ocupación de Grupos Activos</h3>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Grupo</th><th>Curso</th><th>Profesor</th><th>Cupo actual</th><th>Cupo máximo</th><th>Bitácoras</th><th>Ocupación</th></tr></thead>
                    <tbody>
                    <?php foreach ($ocupacion_grupos as $g): ?>
                        <?php $pct = (int)$g['ocupacion_pct']; $col = $pct >= 80 ? 'green' : ($pct >= 50 ? 'blue' : 'orange'); ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($g['nombre']) ?></strong></td>
                            <td><?= htmlspecialchars($g['curso']) ?></td>
                            <td><?= htmlspecialchars($g['profesor'] ?? '—') ?></td>
                            <td><?= $g['cupo_actual'] ?></td>
                            <td><?= $g['cupo_maximo'] ?></td>
                            <td><span class="badge badge-blue"><?= $g['total_bitacoras'] ?></span></td>
                            <td>
                                <div style="display:flex;align-items:center;gap:10px">
                                    <div class="progress-wrap" style="flex:1"><div class="progress-fill <?= $col ?>" style="width:<?= $pct ?>%"></div></div>
                                    <span style="font-size:.8rem;font-weight:600;min-width:36px"><?= $pct ?>%</span>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <h3 class="section-title"><span class="material-symbols-rounded">menu_book</span> Detalle de Cursos</h3>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Curso</th><th>Nivel</th><th>Estado</th><th>Grupos</th><th>Estudiantes</th><th>Precio/mes</th><th>Ingreso proyectado</th></tr></thead>
                    <tbody>
                    <?php foreach ($estudiantes_por_curso as $c): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($c['curso']) ?></strong></td>
                            <td><span class="badge <?= $c['nivel']=='basico'?'badge-green':($c['nivel']=='intermedio'?'badge-blue':'badge-orange') ?>"><?= ucfirst($c['nivel']) ?></span></td>
                            <td><span class="badge <?= $c['estado_curso']=='activo'?'badge-green':'badge-gray' ?>"><?= ucfirst($c['estado_curso']) ?></span></td>
                            <td><?= $c['grupos'] ?></td>
                            <td><?= $c['estudiantes'] ?></td>
                            <td>$<?= number_format((float)$c['precio_mensual'],0,',','.') ?></td>
                            <td style="color:var(--primary-green);font-weight:600">$<?= number_format((float)$c['precio_mensual']*(int)$c['estudiantes'],0,',','.') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- TAB 6: ACTIVIDAD -->
        <div class="tab-content" id="tab-actividad">
            <div class="kpi-row">
                <div class="kpi-card info">
                    <div class="kpi-icon"><span class="material-symbols-rounded">people</span></div>
                    <div>
                        <div class="kpi-value"><?= array_sum($roles) ?></div>
                        <div class="kpi-label">Usuarios activos</div>
                        <div class="kpi-desc">Total en el sistema</div>
                    </div>
                </div>
                <div class="kpi-card success">
                    <div class="kpi-icon"><span class="material-symbols-rounded">person_4</span></div>
                    <div>
                        <div class="kpi-value"><?= $roles['profesor'] ?? 0 ?></div>
                        <div class="kpi-label">Profesores</div>
                        <div class="kpi-desc">Activos</div>
                    </div>
                </div>
                <div class="kpi-card orange">
                    <div class="kpi-icon"><span class="material-symbols-rounded">login</span></div>
                    <div>
                        <div class="kpi-value"><?= array_sum($accesos_data) ?></div>
                        <div class="kpi-label">Accesos</div>
                        <div class="kpi-desc">En <?= $year_selected ?></div>
                    </div>
                </div>
            </div>

            <div class="charts-grid">
                <div class="chart-card full">
                    <div class="chart-header">
                        <div class="chart-header-left">
                            <h3><span class="material-symbols-rounded">monitoring</span> Accesos al Sistema — <?= $year_selected ?></h3>
                            <p>Cantidad de inicios de sesión registrados por mes en el año</p>
                        </div>
                    </div>
                    <div class="chart-container"><canvas id="accesosChart"></canvas></div>
                </div>
                <div class="chart-card">
                    <div class="chart-header">
                        <div class="chart-header-left">
                            <h3><span class="material-symbols-rounded">manage_accounts</span> Distribución de Roles</h3>
                            <p>Usuarios activos clasificados por tipo de rol en el sistema</p>
                        </div>
                    </div>
                    <div class="chart-container"><canvas id="rolesChart"></canvas></div>
                </div>
                <div class="chart-card">
                    <div class="chart-header">
                        <div class="chart-header-left">
                            <h3><span class="material-symbols-rounded">trending_up</span> Nuevos Estudiantes <?= $year_selected ?></h3>
                            <p>Registros de nuevos estudiantes activos por mes</p>
                        </div>
                    </div>
                    <div class="chart-container"><canvas id="nuevosChart"></canvas></div>
                </div>
            </div>

            <?php if (!empty($profesores_detalle)): ?>
            <h3 class="section-title"><span class="material-symbols-rounded">person_4</span> Actividad de Profesores</h3>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Profesor</th><th>Email</th><th>Grupos activos</th><th>Estudiantes</th><th>Bitácoras</th></tr></thead>
                    <tbody>
                    <?php foreach ($profesores_detalle as $pf): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($pf['nombre']) ?></strong></td>
                            <td style="color:var(--text-secondary);font-size:.8rem"><?= htmlspecialchars($pf['email']) ?></td>
                            <td><?= $pf['grupos_activos'] ?></td>
                            <td><span class="badge badge-blue"><?= $pf['estudiantes_total'] ?></span></td>
                            <td><span class="badge badge-green"><?= $pf['bitacoras'] ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

    </div><!-- /tabs-container -->
</main>

<script>
// ── PHP → JS
const YEAR    = <?= $year_selected ?>;
const MESES   = <?= json_encode($meses_labels) ?>;
const INSCR   = <?= json_encode($inscr_data) ?>;
const NUEVOS  = <?= json_encode($nuevos_data) ?>;
const ACCESOS = <?= json_encode($accesos_data) ?>;

const INGRESOS_CONF = <?= json_encode($ingresos_confirmados_mes) ?>;
const INGRESOS_VENC = <?= json_encode($ingresos_vencidos_mes) ?>;
const INGRESOS_PEND = <?= json_encode($ingresos_pendientes_mes) ?>;

const ESTADOS_PREINSC = {
    aprobadas:  <?= (int)$estados_preinscripciones['aprobadas'] ?>,
    pendientes: <?= (int)$estados_preinscripciones['pendientes'] ?>,
    contactadas:<?= (int)$estados_preinscripciones['contactadas'] ?>,
    rechazadas: <?= (int)$estados_preinscripciones['rechazadas'] ?>
};
const ASIST_GLOBAL = {
    presentes:   <?= (int)$asistencia_global['presentes'] ?>,
    ausentes:    <?= (int)$asistencia_global['ausentes'] ?>,
    justificados:<?= (int)$asistencia_global['justificados'] ?>,
    tardanzas:   <?= (int)$asistencia_global['tardanzas'] ?>
};
const CURSOS_NOMBRES   = <?= json_encode($cursos_nombres_js) ?>;
const CURSOS_PROMEDIOS = <?= json_encode($cursos_promedios_js) ?>;
const GRUPOS_NOMBRES   = <?= json_encode($grupos_nombres_js) ?>;
const GRUPOS_ASIST     = <?= json_encode(array_map('floatval', $grupos_asist_js)) ?>;
const EPC_NOMBRES      = <?= json_encode($epc_nombres_js) ?>;
const EPC_VALORES      = <?= json_encode($epc_valores_js) ?>;
const ESTADO_GRUPOS    = <?= json_encode($estado_grupos) ?>;
const ROLES            = <?= json_encode($roles) ?>;
const CONV_LABELS      = <?= json_encode($conv_prog_labels) ?>;
const CONV_TASAS       = <?= json_encode(array_map('floatval', $conv_prog_tasa)) ?>;
const MUN_LABELS       = <?= json_encode($municipio_labels) ?>;
const MUN_VALUES       = <?= json_encode(array_map('intval', $municipio_values)) ?>;
const ING_CURSO_LABELS = <?= json_encode($ingreso_curso_labels) ?>;
const ING_CURSO_PAG    = <?= json_encode($ingreso_curso_pagado) ?>;
const ING_CURSO_VENC   = <?= json_encode($ingreso_curso_vencido) ?>;
const METODO_LABELS    = <?= json_encode($metodo_labels) ?>;
const METODO_VALUES    = <?= json_encode($metodo_values) ?>;
const METODO_COUNTS    = <?= json_encode($metodo_counts) ?>;

const DEUDORES         = <?= json_encode($deudores) ?>;
const ASIST_GRUPOS_TBL = <?= json_encode($asistencia_grupos) ?>;
const REND_ESTUDIANTES = <?= json_encode($rendimiento_estudiantes) ?>;
const CONV_PROGRAMA    = <?= json_encode($conversion_programa) ?>;
const OCUPACION_GRUPOS = <?= json_encode($ocupacion_grupos) ?>;
const CURSOS_DETALLE   = <?= json_encode($estudiantes_por_curso) ?>;
const FINANCIERO       = {
    confirmados: <?= (float)($financiero_anio['ingresos_confirmados']??0) ?>,
    vencidos:    <?= (float)($financiero_anio['ingresos_vencidos']??0) ?>,
    pendientes:  <?= (float)($financiero_anio['ingresos_pendientes']??0) ?>,
    facturado:   <?= (float)($financiero_anio['total_facturado']??0) ?>,
    pagos_ok:    <?= (int)($financiero_anio['pagos_ok']??0) ?>,
    pagos_venc:  <?= (int)($financiero_anio['pagos_vencidos']??0) ?>,
    total_pagos: <?= (int)($financiero_anio['total_pagos']??0) ?>
};
const NOMBRE_ESCUELA = 'Amimbré — Escuela de Música';

// ── Registrar plugin datalabels globalmente ──
Chart.register(ChartDataLabels);

// ── Paleta ──
const C = {
    blue:'#3b82f6', green:'#22c55e', orange:'#f97316', yellow:'#eab308', red:'#ef4444',
    sBlue:'rgba(59,130,246,0.15)', sGreen:'rgba(34,197,94,0.15)',
    teal:'#14b8a6', purple:'#8b5cf6', sTeal:'rgba(20,184,166,0.15)'
};
const GRID='#334155', TICK='#94a3b8', LEG='#f8fafc';

// ── Helpers de formato ──
const fmtCOP = v => '$' + Number(v).toLocaleString('es-CO');
const fmtPct = v => v + '%';

// ── Opciones base para gráficas de barras/línea ──
const baseOpts = (extraScales={}) => ({
    responsive: true,
    maintainAspectRatio: false,
    animation: false,
    plugins: {
        legend: {
            position: 'bottom',
            labels: { color: LEG, padding: 14, font: { size: 11, family: 'Poppins' }, usePointStyle: true, pointStyleWidth: 10 }
        },
        datalabels: { display: false }, // desactivar en barras por defecto, activar caso a caso
        tooltip: {
            backgroundColor: '#1e293b',
            titleColor: '#f8fafc',
            bodyColor: '#94a3b8',
            borderColor: '#334155',
            borderWidth: 1,
            padding: 10,
            callbacks: {}
        }
    },
    scales: {
        y: { beginAtZero: true, ticks: { color: TICK }, grid: { color: GRID } },
        x: { ticks: { color: TICK }, grid: { color: GRID } },
        ...extraScales
    }
});

// ── Opciones para donut/pie con datalabels ──
const pieOpts = (totalFn) => ({
    responsive: true,
    maintainAspectRatio: false,
    animation: false,
    plugins: {
        legend: {
            position: 'bottom',
            labels: { color: LEG, padding: 12, font: { size: 11, family: 'Poppins' }, usePointStyle: true }
        },
        datalabels: {
            color: '#fff',
            font: { weight: 'bold', size: 11, family: 'Poppins' },
            formatter: (value, ctx) => {
                const total = totalFn ? totalFn(ctx) : ctx.dataset.data.reduce((a, b) => a + b, 0);
                if (!total || value === 0) return '';
                const pct = Math.round(value * 100 / total);
                return pct < 5 ? '' : `${value}\n${pct}%`;
            },
            textAlign: 'center',
            display: ctx => {
                const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                return total > 0 && ctx.dataset.data[ctx.dataIndex] > 0;
            }
        },
        tooltip: {
            backgroundColor: '#1e293b',
            titleColor: '#f8fafc',
            bodyColor: '#94a3b8',
            borderColor: '#334155',
            borderWidth: 1,
            padding: 10,
            callbacks: {
                label: ctx => {
                    const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                    const pct = total > 0 ? Math.round(ctx.parsed * 100 / total) : 0;
                    return ` ${ctx.label}: ${ctx.parsed.toLocaleString('es-CO')} (${pct}%)`;
                }
            }
        }
    }
});

// ── Registro global de charts ──
const chartRegistry = {};

function mkChart(id, cfg) {
    const el = document.getElementById(id);
    if (!el) return null;
    const instance = new Chart(el.getContext('2d'), cfg);
    chartRegistry[id] = instance;
    return instance;
}

// ── Captura de chart aunque esté en tab oculto ──
async function captureChart(id) {
    const el = document.getElementById(id);
    if (!el) return null;
    const hidden = [];
    let node = el.parentElement;
    while (node && node !== document.body) {
        if (getComputedStyle(node).display === 'none') {
            hidden.push({ node, prev: node.getAttribute('style') || '' });
            node.style.setProperty('display', 'block', 'important');
            node.style.setProperty('position', 'fixed', 'important');
            node.style.setProperty('top', '-99999px', 'important');
            node.style.setProperty('left', '-99999px', 'important');
            node.style.setProperty('visibility', 'hidden', 'important');
            node.style.setProperty('pointer-events', 'none', 'important');
        }
        node = node.parentElement;
    }
    const instance = chartRegistry[id];
    if (instance) { instance.resize(); instance.update('none'); }
    await new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r)));
    const dataUrl = el.toDataURL('image/png');
    hidden.reverse().forEach(({ node, prev }) => node.setAttribute('style', prev));
    return dataUrl;
}

// ══════════════════════════════════════════════
//  GRÁFICAS DE PANTALLA (MODO OSCURO)
// ══════════════════════════════════════════════

// ── FINANCIERO: Ingresos por mes (barras apiladas con datalabels en hover) ──
mkChart('ingresosChart', {
    type: 'bar',
    data: {
        labels: MESES,
        datasets: [
            { label: ' Pagos confirmados', data: INGRESOS_CONF, backgroundColor: C.green, borderRadius: 4 },
            { label: ' Cartera vencida',    data: INGRESOS_VENC, backgroundColor: C.red,   borderRadius: 4 },
            { label: ' Pendientes',         data: INGRESOS_PEND, backgroundColor: C.yellow, borderRadius: 4 }
        ]
    },
    options: {
        ...baseOpts(),
        plugins: {
            ...baseOpts().plugins,
            datalabels: { display: false },
            tooltip: {
                ...baseOpts().plugins.tooltip,
                callbacks: {
                    label: ctx => ` ${ctx.dataset.label.replace(/^.{2}/,'').trim()}: ${fmtCOP(ctx.parsed.y)}`
                }
            }
        }
    }
});

// ── Ingresos por curso ──
if (ING_CURSO_LABELS.length) {
    mkChart('ingresosCursoChart', {
        type: 'bar',
        data: {
            labels: ING_CURSO_LABELS,
            datasets: [
                { label: ' Pagado', data: ING_CURSO_PAG,  backgroundColor: C.green, borderRadius: 4 },
                { label: ' Vencido',data: ING_CURSO_VENC, backgroundColor: C.red,   borderRadius: 4 }
            ]
        },
        options: {
            ...baseOpts(),
            plugins: {
                ...baseOpts().plugins,
                datalabels: {
                    display: ctx => ctx.dataset.data[ctx.dataIndex] > 0,
                    color: '#fff',
                    font: { size: 9, weight: 'bold' },
                    anchor: 'end', align: 'end',
                    formatter: v => fmtCOP(v)
                },
                tooltip: {
                    ...baseOpts().plugins.tooltip,
                    callbacks: { label: ctx => ` ${ctx.dataset.label}: ${fmtCOP(ctx.parsed.y)}` }
                }
            }
        }
    });
}

// ── Métodos de pago (donut con % y monto) ──
if (METODO_LABELS.length) {
    const totalMetodos = METODO_VALUES.reduce((a, b) => a + b, 0);
    mkChart('metodosChart', {
        type: 'doughnut',
        data: {
            labels: METODO_LABELS.map(m => m.charAt(0).toUpperCase() + m.slice(1)),
            datasets: [{ data: METODO_VALUES, backgroundColor: [C.green, C.blue, C.orange, C.teal, C.purple], borderWidth: 2, borderColor: '#1e293b' }]
        },
        options: {
            ...pieOpts(),
            plugins: {
                ...pieOpts().plugins,
                datalabels: {
                    color: '#fff',
                    font: { weight: 'bold', size: 10 },
                    formatter: (value, ctx) => {
                        const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                        const pct = total > 0 ? Math.round(value * 100 / total) : 0;
                        if (pct < 5) return '';
                        return `${pct}%\n${fmtCOP(value)}`;
                    },
                    textAlign: 'center'
                },
                tooltip: {
                    ...pieOpts().plugins.tooltip,
                    callbacks: {
                        label: ctx => {
                            const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                            const pct = total > 0 ? Math.round(ctx.parsed * 100 / total) : 0;
                            const transacciones = METODO_COUNTS[ctx.dataIndex] || 0;
                            return [
                                ` Monto: ${fmtCOP(ctx.parsed)}`,
                                ` Participación: ${pct}%`,
                                ` Transacciones: ${transacciones}`
                            ];
                        }
                    }
                }
            }
        }
    });
}

// ── INSCRIPCIONES: por mes ──
mkChart('inscripcionesChart', {
    type: 'bar',
    data: {
        labels: MESES,
        datasets: [
            { label: ' Preinscripciones recibidas', data: INSCR.preinscripciones, backgroundColor: C.blue,   borderRadius: 4 },
            { label: ' Matrículas aprobadas',        data: INSCR.matriculas,       backgroundColor: C.green,  borderRadius: 4 },
            { label: ' Rechazadas',                  data: INSCR.rechazadas,       backgroundColor: C.orange, borderRadius: 4 }
        ]
    },
    options: {
        ...baseOpts(),
        plugins: {
            ...baseOpts().plugins,
            datalabels: {
                display: ctx => ctx.dataset.data[ctx.dataIndex] > 0,
                color: '#fff',
                font: { size: 9, weight: 'bold' },
                anchor: 'end', align: 'end',
                formatter: v => v > 0 ? v : ''
            },
            tooltip: { ...baseOpts().plugins.tooltip }
        }
    }
});

// ── Conversión por programa ──
if (CONV_LABELS.length) {
    mkChart('conversionChart', {
        type: 'bar',
        data: {
            labels: CONV_LABELS,
            datasets: [{
                label: '% Tasa de conversión',
                data: CONV_TASAS,
                backgroundColor: CONV_TASAS.map(v => v >= 70 ? C.green : v >= 40 ? C.blue : C.orange),
                borderRadius: 4
            }]
        },
        options: {
            ...baseOpts({ y: { beginAtZero: true, max: 100, ticks: { color: TICK, callback: v => v + '%' }, grid: { color: GRID } } }),
            plugins: {
                ...baseOpts().plugins,
                datalabels: {
                    display: true,
                    color: '#fff',
                    font: { size: 10, weight: 'bold' },
                    anchor: 'end', align: 'end',
                    formatter: v => v + '%'
                },
                legend: { display: false },
                tooltip: {
                    ...baseOpts().plugins.tooltip,
                    callbacks: {
                        label: ctx => {
                            const d = CONV_PROGRAMA[ctx.dataIndex];
                            return d ? [` Conversión: ${ctx.parsed.y}%`, ` Solicitudes: ${d.solicitudes}`, ` Convertidas: ${d.convertidas}`] : ` ${ctx.parsed.y}%`;
                        }
                    }
                }
            }
        }
    });
}

// ── Estado general preinscripciones (donut) ──
const totalPreinsc = ESTADOS_PREINSC.aprobadas + ESTADOS_PREINSC.pendientes + ESTADOS_PREINSC.contactadas + ESTADOS_PREINSC.rechazadas;
mkChart('estadosChart', {
    type: 'doughnut',
    data: {
        labels: [' Aprobadas / Matriculadas', ' Pendientes de revisión', ' Contactadas (en seguimiento)', ' Rechazadas'],
        datasets: [{
            data: [ESTADOS_PREINSC.aprobadas, ESTADOS_PREINSC.pendientes, ESTADOS_PREINSC.contactadas, ESTADOS_PREINSC.rechazadas],
            backgroundColor: [C.green, C.yellow, C.blue, C.orange],
            borderWidth: 2, borderColor: '#1e293b'
        }]
    },
    options: pieOpts()
});

// ── Procedencia por municipio (pie) ──
if (MUN_LABELS.length) {
    mkChart('municipioChart', {
        type: 'pie',
        data: {
            labels: MUN_LABELS,
            datasets: [{
                data: MUN_VALUES,
                backgroundColor: [C.blue, C.green, C.orange, C.yellow, C.teal, C.purple],
                borderWidth: 2, borderColor: '#1e293b'
            }]
        },
        options: pieOpts()
    });
}

// ── ACADÉMICO: Promedios por curso ──
if (CURSOS_NOMBRES.length) {
    mkChart('promediosChart', {
        type: 'bar',
        data: {
            labels: CURSOS_NOMBRES,
            datasets: [{
                label: 'Promedio (escala 0–5)',
                data: CURSOS_PROMEDIOS,
                backgroundColor: CURSOS_PROMEDIOS.map(v => v >= 4.0 ? C.green : v >= 3.0 ? C.blue : C.orange),
                borderRadius: 6
            }]
        },
        options: {
            ...baseOpts({ y: { beginAtZero: true, max: 5, ticks: { color: TICK }, grid: { color: GRID } } }),
            plugins: {
                ...baseOpts().plugins,
                legend: { display: false },
                datalabels: {
                    display: true,
                    color: '#fff',
                    font: { size: 10, weight: 'bold' },
                    anchor: 'end', align: 'end',
                    formatter: v => v.toFixed(1)
                },
                tooltip: {
                    ...baseOpts().plugins.tooltip,
                    callbacks: {
                        label: ctx => {
                            const d = CURSOS_PROMEDIOS[ctx.dataIndex];
                            const estado = d >= 4.0 ? 'Alto' : d >= 3.0 ? 'Medio' : 'Bajo';
                            return [` Promedio: ${ctx.parsed.y}/5.0`, ` Rendimiento: ${estado}`];
                        }
                    }
                }
            }
        }
    });
}

// ── Asistencia global (donut) ──
const totalAsist = ASIST_GLOBAL.presentes + ASIST_GLOBAL.ausentes + ASIST_GLOBAL.justificados + ASIST_GLOBAL.tardanzas;
mkChart('asistenciaGlobalChart', {
    type: 'doughnut',
    data: {
        labels: [' Presente', ' Ausente', ' Justificado', ' Tardanza'],
        datasets: [{
            data: [ASIST_GLOBAL.presentes, ASIST_GLOBAL.ausentes, ASIST_GLOBAL.justificados, ASIST_GLOBAL.tardanzas],
            backgroundColor: [C.green, C.red, C.blue, C.yellow],
            borderWidth: 2, borderColor: '#1e293b'
        }]
    },
    options: pieOpts()
});

// ── Asistencia por grupo (barras horizontales) ──
if (GRUPOS_NOMBRES.length) {
    mkChart('asistenciaGruposChart', {
        type: 'bar',
        data: {
            labels: GRUPOS_NOMBRES,
            datasets: [{
                label: '% de asistencia (presencia en clases)',
                data: GRUPOS_ASIST,
                backgroundColor: GRUPOS_ASIST.map(v => v >= 80 ? C.green : v >= 60 ? C.blue : C.orange),
                borderRadius: 4
            }]
        },
        options: {
            ...baseOpts(),
            indexAxis: 'y',
            plugins: {
                ...baseOpts().plugins,
                legend: { display: false },
                datalabels: {
                    display: true,
                    color: '#fff',
                    font: { size: 10, weight: 'bold' },
                    anchor: 'end', align: 'end',
                    formatter: v => v + '%'
                },
                tooltip: {
                    ...baseOpts().plugins.tooltip,
                    callbacks: {
                        label: ctx => {
                            const d = ASIST_GRUPOS_TBL[ctx.dataIndex];
                            if (!d) return ` ${ctx.parsed.x}%`;
                            return [` Asistencia: ${ctx.parsed.x}%`, ` Presentes: ${d.presentes} / ${d.total} clases`, ` Profesor: ${d.profesor || '—'}`];
                        }
                    }
                }
            },
            scales: {
                x: { beginAtZero: true, max: 100, ticks: { color: TICK, callback: v => v + '%' }, grid: { color: GRID } },
                y: { ticks: { color: TICK }, grid: { color: GRID } }
            }
        }
    });
}

// ── CURSOS: Estudiantes por curso ──
if (EPC_NOMBRES.length) {
    mkChart('estudCursoChart', {
        type: 'bar',
        data: {
            labels: EPC_NOMBRES,
            datasets: [{
                label: 'Estudiantes matriculados',
                data: EPC_VALORES,
                backgroundColor: C.blue,
                borderRadius: 6
            }]
        },
        options: {
            ...baseOpts(),
            plugins: {
                ...baseOpts().plugins,
                legend: { display: false },
                datalabels: {
                    display: true,
                    color: '#fff',
                    font: { size: 11, weight: 'bold' },
                    anchor: 'end', align: 'end',
                    formatter: v => v
                },
                tooltip: {
                    ...baseOpts().plugins.tooltip,
                    callbacks: {
                        label: ctx => {
                            const d = CURSOS_DETALLE[ctx.dataIndex];
                            if (!d) return ` ${ctx.parsed.y} estudiantes`;
                            return [` Estudiantes: ${ctx.parsed.y}`, ` Grupos: ${d.grupos}`, ` Precio/mes: ${fmtCOP(d.precio_mensual)}`];
                        }
                    }
                }
            }
        }
    });
}

// ── Estado grupos (donut) ──
const eGL = Object.keys(ESTADO_GRUPOS).map(k => k.charAt(0).toUpperCase() + k.slice(1));
const eGV = Object.values(ESTADO_GRUPOS);
mkChart('estadoGruposChart', {
    type: 'doughnut',
    data: {
        labels: eGL.length ? eGL : ['Sin grupos'],
        datasets: [{ data: eGV.length ? eGV : [0], backgroundColor: [C.green, C.blue, C.orange, C.red], borderWidth: 2, borderColor: '#1e293b' }]
    },
    options: pieOpts()
});

// ── ACTIVIDAD: Accesos ──
mkChart('accesosChart', {
    type: 'line',
    data: {
        labels: MESES,
        datasets: [{
            label: 'Inicios de sesión',
            data: ACCESOS,
            borderColor: C.blue, backgroundColor: C.sBlue,
            fill: true, tension: 0.4, borderWidth: 2, pointRadius: 5, pointBackgroundColor: C.blue
        }]
    },
    options: {
        ...baseOpts(),
        plugins: {
            ...baseOpts().plugins,
            datalabels: {
                display: ctx => ctx.dataset.data[ctx.dataIndex] > 0,
                color: '#fff', backgroundColor: C.blue,
                borderRadius: 4, padding: 3,
                font: { size: 9, weight: 'bold' },
                formatter: v => v
            }
        }
    }
});

// ── Roles (donut) ──
const rL = Object.keys(ROLES).map(r => ({ admin: ' Administrador', profesor: ' Profesor', estudiante: ' Estudiante' }[r] || r.charAt(0).toUpperCase() + r.slice(1)));
const rV = Object.values(ROLES);
mkChart('rolesChart', {
    type: 'doughnut',
    data: {
        labels: rL.length ? rL : ['Sin usuarios'],
        datasets: [{ data: rV.length ? rV : [0], backgroundColor: [C.orange, C.blue, C.green], borderWidth: 2, borderColor: '#1e293b' }]
    },
    options: pieOpts()
});

// ── Nuevos estudiantes ──
mkChart('nuevosChart', {
    type: 'line',
    data: {
        labels: MESES,
        datasets: [{
            label: 'Nuevos estudiantes registrados',
            data: NUEVOS,
            borderColor: C.green, backgroundColor: C.sGreen,
            fill: true, tension: 0.4, borderWidth: 2, pointRadius: 5, pointBackgroundColor: C.green
        }]
    },
    options: {
        ...baseOpts(),
        plugins: {
            ...baseOpts().plugins,
            datalabels: {
                display: ctx => ctx.dataset.data[ctx.dataIndex] > 0,
                color: '#fff', backgroundColor: C.green,
                borderRadius: 4, padding: 3,
                font: { size: 9, weight: 'bold' },
                formatter: v => v
            }
        }
    }
});

// ══════════════════════════════════════════════
//  TABS
// ══════════════════════════════════════════════
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById(btn.dataset.tab).classList.add('active');
    });
});

function switchTab(id) {
    const btn = document.querySelector(`[data-tab="${id}"]`);
    if (btn) btn.click();
}

function changeYear(y) { window.location.href = '?year=' + y; }

// ══════════════════════════════════════════════
//  PDF HELPERS (MODO CLARO)
// ══════════════════════════════════════════════

// Paleta CLARO para PDF
const PDF = {
    bgPage:    [255, 255, 255],   // fondo blanco
    bgHeader:  [20,  121, 176],   // azul header
    bgCard:    [248, 250, 252],   // gris muy claro
    bgRow:     [241, 245, 249],   // gris alternado
    bgRowAlt:  [255, 255, 255],
    border:    [203, 213, 225],   // borde gris
    text:      [15,  23,  42],    // texto oscuro
    textSub:   [71,  85,  105],   // texto secundario
    textHead:  [255, 255, 255],   // texto en header azul
    green:     [22,  163, 74],
    red:       [220, 38,  38],
    orange:    [234, 88,  12],
    yellow:    [161, 98,  7],
    blue:      [29,  78,  216],
};

function showOverlay(msg, sub) {
    document.getElementById('pdf-msg').textContent = msg || 'Generando reporte…';
    document.getElementById('pdf-sub').textContent = sub || 'Por favor espera';
    document.getElementById('pdf-overlay').classList.add('show');
}
function hideOverlay() { document.getElementById('pdf-overlay').classList.remove('show'); }

function pdfHeader(doc, titulo, color) {
    const W = doc.internal.pageSize.getWidth();
    doc.setFillColor(...color);
    doc.rect(0, 0, W, 22, 'F');
    // Logo / nombre escuela
    doc.setTextColor(255, 255, 255);
    doc.setFontSize(13); doc.setFont('helvetica', 'bold');
    doc.text(NOMBRE_ESCUELA, 14, 14);
    // Título reporte
    doc.setFontSize(9); doc.setFont('helvetica', 'normal');
    doc.text(titulo, W - 14, 14, { align: 'right' });
    // Fecha
    doc.setFontSize(7.5);
    doc.text('Generado: ' + new Date().toLocaleDateString('es-CO', { day: '2-digit', month: 'long', year: 'numeric' }), W - 14, 19.5, { align: 'right' });
    // Separador
    doc.setDrawColor(...PDF.border);
    doc.setLineWidth(0.3);
    doc.line(0, 22, W, 22);
}

function pdfFooter(doc) {
    const W = doc.internal.pageSize.getWidth();
    const H = doc.internal.pageSize.getHeight();
    const n = doc.internal.getNumberOfPages();
    for (let p = 1; p <= n; p++) {
        doc.setPage(p);
        doc.setFillColor(...PDF.bgCard);
        doc.rect(0, H - 12, W, 12, 'F');
        doc.setDrawColor(...PDF.border);
        doc.setLineWidth(0.3);
        doc.line(0, H - 12, W, H - 12);
        doc.setFontSize(7); doc.setTextColor(...PDF.textSub); doc.setFont('helvetica', 'normal');
        doc.text(`${NOMBRE_ESCUELA} — Reporte ${YEAR}`, 14, H - 4);
        doc.text(`Página ${p} de ${n}`, W - 14, H - 4, { align: 'right' });
    }
}

// KPI box en modo claro
function kpiBox(doc, x, y, w, h, label, value, accent) {
    // Fondo
    doc.setFillColor(...PDF.bgCard);
    doc.roundedRect(x, y, w, h, 2, 2, 'F');
    // Borde izquierdo de color
    doc.setFillColor(...accent);
    doc.roundedRect(x, y, 2.5, h, 1, 1, 'F');
    // Borde exterior sutil
    doc.setDrawColor(...PDF.border);
    doc.setLineWidth(0.2);
    doc.roundedRect(x, y, w, h, 2, 2, 'S');
    // Label
    doc.setTextColor(...PDF.textSub);
    doc.setFontSize(7); doc.setFont('helvetica', 'normal');
    doc.text(label, x + 6, y + 7.5);
    // Valor
    doc.setTextColor(...PDF.text);
    doc.setFontSize(11); doc.setFont('helvetica', 'bold');
    doc.text(String(value), x + 6, y + 16);
}

// Tabla en modo claro
function pdfTable(doc, head, body, startY, headerColor) {
    doc.autoTable({
        head: [head],
        body: body,
        startY: startY,
        styles: {
            fontSize: 8,
            cellPadding: { top: 4, right: 8, bottom: 4, left: 8 },
            textColor: PDF.text,
            fillColor: PDF.bgRowAlt,
            lineColor: PDF.border,
            lineWidth: 0.2,
            font: 'helvetica'
        },
        headStyles: {
            fillColor: headerColor || PDF.bgHeader,
            textColor: [255, 255, 255],
            fontStyle: 'bold',
            fontSize: 8.5
        },
        alternateRowStyles: { fillColor: PDF.bgRow },
        margin: { left: 14, right: 14 },
        tableLineColor: PDF.border,
        tableLineWidth: 0.2
    });
}

function fmtCOPPdf(n) { return '$' + Number(n).toLocaleString('es-CO'); }

// ══════════════════════════════════════════════
//  REPORTES DESCARGABLES EN MODO CLARO
// ══════════════════════════════════════════════

async function descargarReporte(tipo) {
    showOverlay('Preparando reporte…', 'Generando PDF en modo claro');
    await new Promise(r => setTimeout(r, 400));
    try {
        const { jsPDF } = window.jspdf;
        if      (tipo === 'financiero')   await reporteFinanciero(jsPDF);
        else if (tipo === 'academico')    await reporteAcademico(jsPDF);
        else if (tipo === 'inscripciones')await reporteInscripciones(jsPDF);
        else if (tipo === 'cartera')      await reporteCartera(jsPDF);
        else if (tipo === 'asistencia')   await reporteAsistencia(jsPDF);
        else if (tipo === 'cursos')       await reporteCursos(jsPDF);
    } catch (e) {
        console.error(e);
        alert('Error generando el reporte. Ver consola.');
    } finally {
        hideOverlay();
    }
}

// ── REPORTE FINANCIERO (CLARO) ──
async function reporteFinanciero(jsPDF) {
    const doc = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });
    const W = doc.internal.pageSize.getWidth();

    // Fondo blanco
    doc.setFillColor(...PDF.bgPage);
    doc.rect(0, 0, W, 297, 'F');
    pdfHeader(doc, `Reporte Financiero — ${YEAR}`, PDF.bgHeader);

    const tasa = FINANCIERO.facturado > 0 ? Math.round(FINANCIERO.confirmados * 100 / FINANCIERO.facturado) : 0;

    // KPIs
    const kpis = [
        { l: 'Ingresos Confirmados', v: fmtCOPPdf(FINANCIERO.confirmados), c: PDF.green },
        { l: 'Cartera Vencida',      v: fmtCOPPdf(FINANCIERO.vencidos),    c: PDF.red },
        { l: 'Pendientes por vencer',v: fmtCOPPdf(FINANCIERO.pendientes),  c: [161, 98, 7] },
        { l: 'Total facturado',      v: fmtCOPPdf(FINANCIERO.facturado),   c: PDF.blue },
        { l: 'Tasa de cobro',        v: tasa + '%',                         c: [109, 40, 217] },
        { l: 'Pagos recibidos',      v: FINANCIERO.pagos_ok + ' pagos',    c: [13, 148, 136] },
    ];
    const bw = (W - 28 - 10) / 3, bh = 26;
    kpis.forEach((k, i) => {
        const col = i % 3, row = Math.floor(i / 3);
        kpiBox(doc, 14 + col * (bw + 5), 28 + row * (bh + 5), bw, bh, k.l, k.v, k.c);
    });

    // Gráfico ingresos por mes
    const imgFin = await captureChart('ingresosChart');
    if (imgFin) {
        // Título sección
        doc.setTextColor(...PDF.text);
        doc.setFontSize(10); doc.setFont('helvetica', 'bold');
        doc.text('Ingresos por mes', 14, 98);
        // Leyenda
        doc.setFontSize(7.5); doc.setFont('helvetica', 'normal');
        const leyFin = [
            { color: PDF.green,  label: 'Confirmados' },
            { color: PDF.red,    label: 'Vencidos' },
            { color: [161,98,7], label: 'Pendientes' }
        ];
        leyFin.forEach((l, i) => {
            doc.setFillColor(...l.color);
            doc.roundedRect(14 + i * 45, 101, 4, 4, 1, 1, 'F');
            doc.setTextColor(...PDF.textSub);
            doc.text(l.label, 20 + i * 45, 104.5);
        });
        doc.addImage(imgFin, 'PNG', 14, 107, W - 28, 60);
    }

    // Ingresos por curso
    if (ING_CURSO_LABELS.length) {
        doc.addPage();
        doc.setFillColor(...PDF.bgPage); doc.rect(0, 0, W, 297, 'F');
        pdfHeader(doc, `Ingresos por Curso — ${YEAR}`, PDF.bgHeader);

        doc.setTextColor(...PDF.text);
        doc.setFontSize(10); doc.setFont('helvetica', 'bold');
        doc.text('Detalle de ingresos y cartera vencida por programa', 14, 30);

        pdfTable(doc,
            ['Curso', 'Pagado (COP)', 'Vencido (COP)', 'Total cobros', 'Cobros OK'],
            <?= json_encode(array_map(fn($r) => [
                $r['curso'],
                '$'.number_format((float)$r['pagado'],0,',','.'),
                '$'.number_format((float)$r['vencido'],0,',','.'),
                $r['total_cobros'],
                $r['cobros_ok']
            ], $ingresos_curso)) ?>,
            36, PDF.bgHeader
        );
    }

    // Deudores
    if (DEUDORES.length) {
        doc.addPage();
        doc.setFillColor(...PDF.bgPage); doc.rect(0, 0, W, 297, 'F');
        pdfHeader(doc, 'Estudiantes con Cartera Vencida', [180, 30, 30]);

        const totalDeuda = DEUDORES.reduce((s, d) => s + Number(d.deuda_total), 0);
        kpiBox(doc, 14, 28, (W - 28) / 2 - 4, 26, 'Total deuda acumulada', fmtCOPPdf(totalDeuda), PDF.red);
        kpiBox(doc, 14 + (W - 28) / 2 + 4, 28, (W - 28) / 2 - 4, 26, 'Estudiantes en mora', DEUDORES.length + ' estudiante(s)', PDF.orange);

        pdfTable(doc,
            ['#', 'Estudiante', 'Email', 'Pagos vencidos', 'Deuda total', 'Vencimiento antiguo'],
            DEUDORES.map((d, i) => [i + 1, d.estudiante, d.email, d.pagos_vencidos + ' pago(s)', fmtCOPPdf(d.deuda_total), d.vencimiento_mas_antiguo || '—']),
            60, [180, 30, 30]
        );
    }

    pdfFooter(doc);
    doc.save(`Reporte_Financiero_Amimbre_${YEAR}.pdf`);
}

// ── REPORTE ACADÉMICO (CLARO) ──
async function reporteAcademico(jsPDF) {
    const doc = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });
    const W = doc.internal.pageSize.getWidth();
    doc.setFillColor(...PDF.bgPage); doc.rect(0, 0, W, 297, 'F');
    pdfHeader(doc, 'Rendimiento Académico', PDF.bgHeader);

    // Gráfico promedios
    const imgProm = await captureChart('promediosChart');
    if (imgProm) {
        doc.setTextColor(...PDF.text);
        doc.setFontSize(10); doc.setFont('helvetica', 'bold');
        doc.text('Promedio por curso (escala 0–5)', 14, 30);
        const leyProm = [
            { color: PDF.green,  label: '≥ 4.0 Alto' },
            { color: PDF.blue,   label: '3.0–3.9 Medio' },
            { color: PDF.orange, label: '< 3.0 Bajo' }
        ];
        doc.setFontSize(7.5); doc.setFont('helvetica', 'normal');
        leyProm.forEach((l, i) => {
            doc.setFillColor(...l.color);
            doc.roundedRect(14 + i * 45, 33, 4, 4, 1, 1, 'F');
            doc.setTextColor(...PDF.textSub);
            doc.text(l.label, 20 + i * 45, 36.5);
        });
        doc.addImage(imgProm, 'PNG', 14, 40, W - 28, 55);
    }

    // Gráfico asistencia global
    const imgAsist = await captureChart('asistenciaGlobalChart');
    if (imgAsist) {
        doc.setTextColor(...PDF.text);
        doc.setFontSize(10); doc.setFont('helvetica', 'bold');
        doc.text('Asistencia global — distribución de estados', 14, 102);
        const leyAsist = [
            { color: PDF.green,  label: 'Presente' },
            { color: PDF.red,    label: 'Ausente' },
            { color: PDF.blue,   label: 'Justificado' },
            { color: [161,98,7], label: 'Tardanza' }
        ];
        doc.setFontSize(7.5); doc.setFont('helvetica', 'normal');
        leyAsist.forEach((l, i) => {
            doc.setFillColor(...l.color);
            doc.roundedRect(14 + i * 42, 105, 4, 4, 1, 1, 'F');
            doc.setTextColor(...PDF.textSub);
            doc.text(l.label, 20 + i * 42, 108.5);
        });
        doc.addImage(imgAsist, 'PNG', (W - 75) / 2, 112, 75, 55);
    }

    // Tabla promedios
    if (CURSOS_NOMBRES.length) {
        doc.addPage();
        doc.setFillColor(...PDF.bgPage); doc.rect(0, 0, W, 297, 'F');
        pdfHeader(doc, 'Promedios por Curso', PDF.bgHeader);
        doc.setTextColor(...PDF.text);
        doc.setFontSize(10); doc.setFont('helvetica', 'bold');
        doc.text('Estadísticas de calificaciones por programa', 14, 30);
        pdfTable(doc,
            ['Curso', 'Promedio', 'Evaluaciones', 'Mínimo', 'Máximo', 'Estado'],
            <?= json_encode(array_map(fn($r) => [
                $r['curso'],
                round((float)$r['promedio'],2).'/5.0',
                $r['total_evaluaciones'],
                round((float)$r['minimo'],2),
                round((float)$r['maximo'],2),
                (float)$r['promedio'] >= 4.0 ? 'Alto' : ((float)$r['promedio'] >= 3.0 ? 'Medio' : 'Bajo')
            ], $promedios_cursos)) ?>,
            36, PDF.bgHeader
        );
    }

    // Rendimiento individual
    if (REND_ESTUDIANTES.length) {
        doc.addPage();
        doc.setFillColor(...PDF.bgPage); doc.rect(0, 0, W, 297, 'F');
        pdfHeader(doc, 'Rendimiento Individual de Estudiantes', [22, 163, 74]);
        doc.setTextColor(...PDF.text);
        doc.setFontSize(10); doc.setFont('helvetica', 'bold');
        doc.text('Promedio, evaluaciones y asistencia por estudiante', 14, 30);
        pdfTable(doc,
            ['Estudiante', 'Curso', 'Promedio', 'Evaluaciones', '% Asistencia', 'Estado'],
            REND_ESTUDIANTES.map(r => {
                const p = Number(r.promedio).toFixed(2);
                const aP = r.total_clases > 0 ? Math.round(r.presencias * 100 / r.total_clases) : 0;
                return [r.estudiante, r.curso, p + '/5.0', r.evaluaciones, aP + '%', Number(p) >= 3 ? 'Aprobando' : 'En riesgo'];
            }),
            36, [22, 163, 74]
        );
    }

    pdfFooter(doc);
    doc.save(`Reporte_Academico_Amimbre.pdf`);
}

// ── REPORTE INSCRIPCIONES (CLARO) ──
async function reporteInscripciones(jsPDF) {
    const doc = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });
    const W = doc.internal.pageSize.getWidth();
    doc.setFillColor(...PDF.bgPage); doc.rect(0, 0, W, 297, 'F');
    pdfHeader(doc, `Inscripciones y Matrículas — ${YEAR}`, [200, 80, 0]);

    const bw = (W - 28 - 9) / 4, bh = 24;
    [
        { l: 'Aprobadas / Matriculadas', v: ESTADOS_PREINSC.aprobadas,   c: PDF.green },
        { l: 'Pendientes de revisión',   v: ESTADOS_PREINSC.pendientes,  c: [161, 98, 7] },
        { l: 'Contactadas',              v: ESTADOS_PREINSC.contactadas, c: PDF.blue },
        { l: 'Rechazadas',               v: ESTADOS_PREINSC.rechazadas,  c: PDF.red },
    ].forEach((k, i) => kpiBox(doc, 14 + i * (bw + 3), 28, bw, bh, k.l, k.v, k.c));

    // Gráfico inscripciones por mes
    const imgInscr = await captureChart('inscripcionesChart');
    if (imgInscr) {
        doc.setTextColor(...PDF.text);
        doc.setFontSize(10); doc.setFont('helvetica', 'bold');
        doc.text('Inscripciones por mes', 14, 59);
        const ley = [
            { color: PDF.blue,   label: 'Preinscripciones recibidas' },
            { color: PDF.green,  label: 'Matrículas aprobadas' },
            { color: PDF.orange, label: 'Rechazadas' }
        ];
        doc.setFontSize(7.5); doc.setFont('helvetica', 'normal');
        ley.forEach((l, i) => {
            doc.setFillColor(...l.color);
            doc.roundedRect(14 + i * 58, 62, 4, 4, 1, 1, 'F');
            doc.setTextColor(...PDF.textSub);
            doc.text(l.label, 20 + i * 58, 65.5);
        });
        doc.addImage(imgInscr, 'PNG', 14, 70, W - 28, 60);
    }

    // Conversión por programa
    if (CONV_PROGRAMA.length) {
        doc.addPage();
        doc.setFillColor(...PDF.bgPage); doc.rect(0, 0, W, 297, 'F');
        pdfHeader(doc, 'Tasas de Conversión por Programa', [200, 80, 0]);
        doc.setTextColor(...PDF.text);
        doc.setFontSize(10); doc.setFont('helvetica', 'bold');
        doc.text('Porcentaje de preinscripciones convertidas a matrículas', 14, 30);

        // Barra de referencia de colores
        doc.setFontSize(7.5); doc.setFont('helvetica', 'normal');
        [
            { color: PDF.green,  label: '≥ 70% conversión alta' },
            { color: PDF.blue,   label: '40–69% conversión media' },
            { color: PDF.orange, label: '< 40% requiere atención' }
        ].forEach((l, i) => {
            doc.setFillColor(...l.color);
            doc.roundedRect(14 + i * 58, 33, 4, 4, 1, 1, 'F');
            doc.setTextColor(...PDF.textSub);
            doc.text(l.label, 20 + i * 58, 36.5);
        });

        pdfTable(doc,
            ['Programa', 'Solicitudes recibidas', 'Convertidas a matrícula', 'Tasa de conversión'],
            CONV_PROGRAMA.map(r => [r.programa, r.solicitudes, r.convertidas, r.tasa + '%']),
            42, [200, 80, 0]
        );
    }

    pdfFooter(doc);
    doc.save(`Reporte_Inscripciones_Amimbre_${YEAR}.pdf`);
}

// ── REPORTE CARTERA VENCIDA (CLARO) ──
async function reporteCartera(jsPDF) {
    const doc = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });
    const W = doc.internal.pageSize.getWidth();
    doc.setFillColor(...PDF.bgPage); doc.rect(0, 0, W, 297, 'F');
    pdfHeader(doc, 'Reporte de Cartera Vencida', [180, 30, 30]);

    const totalDeuda = DEUDORES.reduce((s, d) => s + Number(d.deuda_total), 0);
    kpiBox(doc, 14, 28, (W - 28) / 2 - 4, 26, 'Total cartera vencida', fmtCOPPdf(totalDeuda), PDF.red);
    kpiBox(doc, 14 + (W - 28) / 2 + 4, 28, (W - 28) / 2 - 4, 26, 'Estudiantes en mora', DEUDORES.length + ' estudiante(s)', PDF.orange);

    if (DEUDORES.length) {
        doc.setTextColor(...PDF.text);
        doc.setFontSize(10); doc.setFont('helvetica', 'bold');
        doc.text('Listado de estudiantes con deuda — ordenado por monto mayor', 14, 61);
        pdfTable(doc,
            ['#', 'Nombre estudiante', 'Correo electrónico', 'Pagos vencidos', 'Deuda total (COP)', 'Vcto. más antiguo'],
            DEUDORES.map((d, i) => [i + 1, d.estudiante, d.email, d.pagos_vencidos + ' pago(s)', fmtCOPPdf(d.deuda_total), d.vencimiento_mas_antiguo || '—']),
            67, [180, 30, 30]
        );
    } else {
        doc.setFillColor(220, 252, 231);
        doc.roundedRect(14, 62, W - 28, 20, 3, 3, 'F');
        doc.setTextColor(...PDF.green);
        doc.setFontSize(11); doc.setFont('helvetica', 'bold');
        doc.text('¡No hay cartera vencida registrada actualmente!', W / 2, 75, { align: 'center' });
    }

    pdfFooter(doc);
    doc.save(`Cartera_Vencida_Amimbre.pdf`);
}

// ── REPORTE ASISTENCIA (CLARO) ──
async function reporteAsistencia(jsPDF) {
    const doc = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });
    const W = doc.internal.pageSize.getWidth();
    doc.setFillColor(...PDF.bgPage); doc.rect(0, 0, W, 297, 'F');
    pdfHeader(doc, 'Control de Asistencia', [13, 148, 136]);

    const asistData = <?= json_encode($asistencia_global) ?>;
    const total = asistData.total || 1;
    const bw = (W - 28 - 9) / 4, bh = 24;
    [
        { l: 'Presentes',    v: asistData.presentes + ' (' + Math.round(asistData.presentes * 100 / total) + '%)',    c: PDF.green },
        { l: 'Ausentes',     v: asistData.ausentes + ' (' + Math.round(asistData.ausentes * 100 / total) + '%)',      c: PDF.red },
        { l: 'Justificados', v: asistData.justificados + ' (' + Math.round(asistData.justificados * 100 / total) + '%)', c: PDF.blue },
        { l: 'Tardanzas',    v: asistData.tardanzas + ' (' + Math.round(asistData.tardanzas * 100 / total) + '%)',    c: [161, 98, 7] },
    ].forEach((k, i) => kpiBox(doc, 14 + i * (bw + 3), 28, bw, bh, k.l, k.v, k.c));

    const imgGrupos = await captureChart('asistenciaGruposChart');
    if (imgGrupos) {
        doc.setTextColor(...PDF.text);
        doc.setFontSize(10); doc.setFont('helvetica', 'bold');
        doc.text('Porcentaje de asistencia por grupo', 14, 61);
        const ley = [
            { color: PDF.green,  label: '≥ 80% Óptimo' },
            { color: PDF.blue,   label: '60–79% Aceptable' },
            { color: PDF.orange, label: '< 60% Preocupante' }
        ];
        doc.setFontSize(7.5); doc.setFont('helvetica', 'normal');
        ley.forEach((l, i) => {
            doc.setFillColor(...l.color);
            doc.roundedRect(14 + i * 48, 64, 4, 4, 1, 1, 'F');
            doc.setTextColor(...PDF.textSub);
            doc.text(l.label, 20 + i * 48, 67.5);
        });
        doc.addImage(imgGrupos, 'PNG', 14, 72, W - 28, 65);
    }

    if (ASIST_GRUPOS_TBL.length) {
        doc.addPage();
        doc.setFillColor(...PDF.bgPage); doc.rect(0, 0, W, 297, 'F');
        pdfHeader(doc, 'Asistencia Detallada por Grupo', [13, 148, 136]);
        doc.setTextColor(...PDF.text);
        doc.setFontSize(10); doc.setFont('helvetica', 'bold');
        doc.text('Registro de asistencia por grupo activo', 14, 30);
        pdfTable(doc,
            ['Grupo', 'Curso', 'Profesor', 'Presentes', 'Total clases', '% Asistencia'],
            ASIST_GRUPOS_TBL.map(r => [r.grupo, r.curso, r.profesor || '—', r.presentes, r.total, r.porcentaje + '%']),
            36, [13, 148, 136]
        );
    }

    pdfFooter(doc);
    doc.save(`Control_Asistencia_Amimbre.pdf`);
}

// ── REPORTE CURSOS (CLARO) ──
async function reporteCursos(jsPDF) {
    const doc = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });
    const W = doc.internal.pageSize.getWidth();
    doc.setFillColor(...PDF.bgPage); doc.rect(0, 0, W, 297, 'F');
    pdfHeader(doc, 'Cursos y Ocupación de Grupos', [120, 100, 0]);

    const imgEstud = await captureChart('estudCursoChart');
    if (imgEstud) {
        doc.setTextColor(...PDF.text);
        doc.setFontSize(10); doc.setFont('helvetica', 'bold');
        doc.text('Estudiantes matriculados por programa', 14, 30);
        doc.addImage(imgEstud, 'PNG', 14, 35, W - 28, 60);
    }

    if (CURSOS_DETALLE.length) {
        doc.addPage();
        doc.setFillColor(...PDF.bgPage); doc.rect(0, 0, W, 297, 'F');
        pdfHeader(doc, 'Detalle de Cursos', [120, 100, 0]);
        doc.setTextColor(...PDF.text);
        doc.setFontSize(10); doc.setFont('helvetica', 'bold');
        doc.text('Información completa de cada programa activo', 14, 30);
        pdfTable(doc,
            ['Curso', 'Nivel', 'Estado', 'Grupos', 'Estudiantes', 'Precio/mes', 'Ingreso proyectado'],
            CURSOS_DETALLE.map(r => [r.curso, r.nivel, r.estado_curso, r.grupos, r.estudiantes, fmtCOPPdf(r.precio_mensual), fmtCOPPdf(r.precio_mensual * r.estudiantes)]),
            36, [120, 100, 0]
        );
    }

    if (OCUPACION_GRUPOS.length) {
        doc.addPage();
        doc.setFillColor(...PDF.bgPage); doc.rect(0, 0, W, 297, 'F');
        pdfHeader(doc, 'Ocupación de Grupos Activos', [120, 100, 0]);
        doc.setTextColor(...PDF.text);
        doc.setFontSize(10); doc.setFont('helvetica', 'bold');
        doc.text('Capacidad y ocupación actual de cada grupo', 14, 30);
        pdfTable(doc,
            ['Grupo', 'Curso', 'Profesor', 'Cupo actual', 'Cupo máx.', 'Bitácoras', '% Ocupación'],
            OCUPACION_GRUPOS.map(r => [r.nombre, r.curso, r.profesor || '—', r.cupo_actual, r.cupo_maximo, r.total_bitacoras, r.ocupacion_pct + '%']),
            36, [120, 100, 0]
        );
    }

    pdfFooter(doc);
    doc.save(`Reporte_Cursos_Amimbre.pdf`);
}

// ══════════════════════════════════════════════
//  PDF GENERAL (modo claro, captura por secciones)
// ══════════════════════════════════════════════
async function exportarPDFGeneral() {
    showOverlay('Generando PDF general…', 'Capturando todas las secciones en modo claro');
    await new Promise(r => setTimeout(r, 200));
    try {
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });
        const W = doc.internal.pageSize.getWidth();
        const H = doc.internal.pageSize.getHeight();

        // Portada en modo claro
        doc.setFillColor(...PDF.bgPage);
        doc.rect(0, 0, W, H, 'F');
        pdfHeader(doc, `Reporte General — ${YEAR}`, PDF.bgHeader);

        const kpisG = [
            { l: 'Estudiantes activos',    v: <?= $total_estudiantes ?>,                                                       c: PDF.blue },
            { l: 'Ingresos confirmados',   v: fmtCOPPdf(<?= (float)($financiero_anio['ingresos_confirmados']??0) ?>),          c: PDF.green },
            { l: 'Cartera vencida',        v: fmtCOPPdf(<?= (float)($financiero_anio['ingresos_vencidos']??0) ?>),             c: PDF.red },
            { l: 'Matrículas activas',     v: <?= $matriculas_activas ?>,                                                      c: PDF.orange },
            { l: 'Grupos activos',         v: <?= $grupos_activos ?>,                                                          c: [109, 40, 217] },
            { l: 'Preinscripciones pend.', v: <?= $preinscripciones_pendientes ?>,                                             c: [161, 98, 7] },
        ];
        const bw = (W - 28 - 10) / 3, bh = 26;
        kpisG.forEach((k, i) => kpiBox(doc, 14 + (i % 3) * (bw + 5), 28 + Math.floor(i / 3) * (bh + 5), bw, bh, k.l, k.v, k.c));

        // Secciones
        const tabs = [
            { id: 'tab-inscripciones', titulo: 'Inscripciones y Matrículas',  color: [200, 80, 0] },
            { id: 'tab-financiero',    titulo: 'Análisis Financiero',          color: PDF.bgHeader },
            { id: 'tab-academico',     titulo: 'Rendimiento Académico',        color: [22, 163, 74] },
            { id: 'tab-cursos',        titulo: 'Cursos y Grupos',              color: [120, 100, 0] },
            { id: 'tab-actividad',     titulo: 'Actividad del Sistema',        color: [109, 40, 217] },
        ];

        for (const t of tabs) {
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            document.getElementById(t.id).classList.add('active');
            await new Promise(r => setTimeout(r, 400));
            const el = document.getElementById(t.id);
            const canvas = await html2canvas(el, { scale: 1.2, useCORS: true, backgroundColor: '#ffffff', logging: false });
            const img = canvas.toDataURL('image/jpeg', 0.85);
            doc.addPage();
            doc.setFillColor(...PDF.bgPage);
            doc.rect(0, 0, W, H, 'F');
            // Header de sección
            doc.setFillColor(...t.color);
            doc.rect(0, 0, W, 14, 'F');
            doc.setTextColor(255, 255, 255);
            doc.setFontSize(11); doc.setFont('helvetica', 'bold');
            doc.text(t.titulo, 14, 9.5);
            doc.setFontSize(8); doc.setFont('helvetica', 'normal');
            doc.text(NOMBRE_ESCUELA + ' — ' + YEAR, W - 14, 9.5, { align: 'right' });
            doc.addImage(img, 'JPEG', 14, 17, W - 28, H - 22);
        }

        // Restaurar primer tab
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        document.querySelector('.tab-btn').click();

        pdfFooter(doc);
        doc.save(`Reporte_General_Amimbre_${YEAR}.pdf`);
    } catch (e) {
        console.error(e);
        alert('Error al generar el PDF general.');
    } finally {
        hideOverlay();
    }
}

// ══════════════════════════════════════════════
//  TABLE HORIZONTAL SCROLL INDICATORS
// ══════════════════════════════════════════════
(function () {
    function initTableHints() {
        document.querySelectorAll('.table-wrap').forEach(wrap => {
            // Skip if already wrapped
            if (wrap.parentElement.classList.contains('table-scroll-outer')) return;

            const outer = document.createElement('div');
            outer.className = 'table-scroll-outer';
            wrap.parentNode.insertBefore(outer, wrap);
            outer.appendChild(wrap);

            // Pill badge
            const badge = document.createElement('div');
            badge.className = 'table-scroll-badge';
            badge.innerHTML = '<span class="material-symbols-rounded">keyboard_double_arrow_right</span><span>Deslizar</span>';
            outer.appendChild(badge);

            const check = () => {
                const overflows = wrap.scrollWidth > wrap.clientWidth + 2;
                const atEnd    = wrap.scrollLeft >= wrap.scrollWidth - wrap.clientWidth - 4;
                outer.classList.toggle('has-overflow', overflows && !atEnd);
            };
            check();
            wrap.addEventListener('scroll', check, { passive: true });
            window.addEventListener('resize', check, { passive: true });
        });
    }

    initTableHints();

    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', () => requestAnimationFrame(initTableHints));
    });
})();

// ══════════════════════════════════════════════
//  SCROLL REVEAL (IntersectionObserver)
// ══════════════════════════════════════════════
(function () {
    const SELECTORS = '.stat-card,.chart-card,.kpi-card,.table-scroll-outer,.reporte-card,.section-title,.alert-info';

    function attachObserver(root) {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.07, rootMargin: '0px 0px -20px 0px' });

        root.querySelectorAll(SELECTORS).forEach(el => {
            if (!el.classList.contains('animate-on-scroll')) {
                el.classList.add('animate-on-scroll');
            }
            observer.observe(el);
        });
        return observer;
    }

    // Animar elementos visibles al cargar la página
    attachObserver(document);

    // Re-animar nuevos elementos al cambiar de tab
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const tabId = btn.dataset.tab;
            const tab = document.getElementById(tabId);
            if (!tab) return;
            requestAnimationFrame(() => attachObserver(tab));
        });
    });
})();
</script>
</body>
</html>