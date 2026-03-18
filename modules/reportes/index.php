<?php
/**
 * Reportes - Análisis y estadísticas del sistema
 * Amimbré - Escuela de Música
 */

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

try {
    // ── TARJETAS PRINCIPALES ──────────────────────────────────────────────────

    // 1. Total estudiantes activos
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM usuarios WHERE rol='estudiante' AND estado='activo'");
    $total_estudiantes = $stmt->fetch()['total'] ?? 0;

    // Estudiantes nuevos este año
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM usuarios WHERE rol='estudiante' AND estado='activo' AND YEAR(fecha_registro)=?");
    $stmt->execute([$year_selected]);
    $estudiantes_nuevos_anio = $stmt->fetch()['total'] ?? 0;

    // 2. Matrículas activas
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM matriculas WHERE estado='activa'");
    $matriculas_activas = $stmt->fetch()['total'] ?? 0;

    // Matrículas del año seleccionado
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM matriculas WHERE YEAR(fecha_matricula)=?");
    $stmt->execute([$year_selected]);
    $matriculas_anio = $stmt->fetch()['total'] ?? 0;

    // 3. Preinscripciones pendientes
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM preinscripciones WHERE estado='pendiente'");
    $preinscripciones_pendientes = $stmt->fetch()['total'] ?? 0;

    // 4. Grupos activos
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM grupos WHERE estado='activo'");
    $grupos_activos = $stmt->fetch()['total'] ?? 0;

    // ── TAB: INSCRIPCIONES ───────────────────────────────────────────────────

    // Inscripciones por mes (año seleccionado)
    $stmt = $pdo->prepare("
        SELECT 
            MONTH(fecha_preinscripcion) as mes,
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

    // Estados de preinscripciones (totales históricos)
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

    // Nuevos estudiantes por mes (tendencia)
    $stmt = $pdo->prepare("
        SELECT MONTH(fecha_registro) as mes, COUNT(*) as total
        FROM usuarios
        WHERE rol='estudiante' AND estado='activo' AND YEAR(fecha_registro)=?
        GROUP BY MONTH(fecha_registro)
        ORDER BY mes
    ");
    $stmt->execute([$year_selected]);
    $nuevos_por_mes_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── TAB: ACADÉMICO ───────────────────────────────────────────────────────

    // Promedio de calificaciones por curso
    $stmt = $pdo->query("
        SELECT c.nombre as curso, 
               AVG(cal.calificacion) as promedio,
               COUNT(cal.id) as total_evaluaciones
        FROM calificaciones cal
        INNER JOIN matriculas m ON cal.matricula_id = m.id
        INNER JOIN grupos g ON m.grupo_id = g.id
        INNER JOIN cursos c ON g.curso_id = c.id
        GROUP BY c.id, c.nombre
        ORDER BY promedio DESC
    ");
    $promedios_cursos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Asistencia por estado (global)
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

    // Asistencia por grupo (top 8)
    $stmt = $pdo->query("
        SELECT g.nombre as grupo, c.nombre as curso,
               COUNT(CASE WHEN a.estado='presente' THEN 1 END) as presentes,
               COUNT(a.id) as total,
               ROUND(COUNT(CASE WHEN a.estado='presente' THEN 1 END) * 100.0 / NULLIF(COUNT(a.id),0), 1) as porcentaje
        FROM asistencias a
        INNER JOIN matriculas m ON a.matricula_id = m.id
        INNER JOIN grupos g ON m.grupo_id = g.id
        INNER JOIN cursos c ON g.curso_id = c.id
        GROUP BY g.id, g.nombre, c.nombre
        HAVING total > 0
        ORDER BY porcentaje DESC
        LIMIT 8
    ");
    $asistencia_grupos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calificaciones por tipo de evaluación
    $stmt = $pdo->query("
        SELECT tipo_evaluacion, 
               COUNT(*) as cantidad,
               AVG(calificacion) as promedio,
               MIN(calificacion) as minimo,
               MAX(calificacion) as maximo
        FROM calificaciones
        GROUP BY tipo_evaluacion
    ");
    $calificaciones_tipo = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Certificados generados
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total,
            COUNT(CASE WHEN estado='aprobado' THEN 1 END) as aprobados,
            COUNT(CASE WHEN estado='reprobado' THEN 1 END) as reprobados
        FROM calificaciones_certificados
    ");
    $certificados = $stmt->fetch(PDO::FETCH_ASSOC);

    // Bitácoras registradas
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM bitacoras WHERE estado='activo'");
    $total_bitacoras = $stmt->fetch()['total'] ?? 0;

    // ── TAB: CURSOS ──────────────────────────────────────────────────────────

    // Estudiantes por curso
    $stmt = $pdo->query("
        SELECT c.nombre as curso, c.nivel, c.estado as estado_curso,
               COUNT(DISTINCT m.estudiante_id) as estudiantes,
               COUNT(DISTINCT g.id) as grupos,
               c.cupo_maximo,
               ROUND(COUNT(DISTINCT m.estudiante_id) * 100.0 / NULLIF(c.cupo_maximo * COUNT(DISTINCT g.id), 0), 1) as ocupacion
        FROM cursos c
        LEFT JOIN grupos g ON c.id = g.curso_id AND g.estado='activo'
        LEFT JOIN matriculas m ON g.id = m.grupo_id AND m.estado='activa'
        GROUP BY c.id, c.nombre, c.nivel, c.estado, c.cupo_maximo
        ORDER BY estudiantes DESC
    ");
    $estudiantes_por_curso = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Estado de grupos
    $stmt = $pdo->query("
        SELECT estado, COUNT(*) as total FROM grupos GROUP BY estado
    ");
    $estado_grupos_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $estado_grupos = array_column($estado_grupos_raw, 'total', 'estado');

    // Ocupación de grupos activos
    $stmt = $pdo->query("
        SELECT g.nombre, c.nombre as curso, g.cupo_actual, g.cupo_maximo,
               ROUND(g.cupo_actual * 100.0 / NULLIF(g.cupo_maximo,0), 0) as ocupacion_pct
        FROM grupos g
        INNER JOIN cursos c ON g.curso_id = c.id
        WHERE g.estado='activo'
        ORDER BY ocupacion_pct DESC
        LIMIT 10
    ");
    $ocupacion_grupos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── TAB: ACTIVIDAD DEL SISTEMA ───────────────────────────────────────────

    // Accesos por mes (año seleccionado)
    $stmt = $pdo->prepare("
        SELECT MONTH(fecha_acceso) as mes, COUNT(*) as total
        FROM logs_acceso
        WHERE YEAR(fecha_acceso)=?
        GROUP BY MONTH(fecha_acceso)
        ORDER BY mes
    ");
    $stmt->execute([$year_selected]);
    $accesos_mes_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Usuarios más activos (últimos accesos)
    $stmt = $pdo->query("
        SELECT u.nombre, u.rol, u.ultima_conexion,
               COUNT(la.id) as total_accesos
        FROM usuarios u
        LEFT JOIN logs_acceso la ON u.id = la.usuario_id
        WHERE u.estado='activo'
        GROUP BY u.id, u.nombre, u.rol, u.ultima_conexion
        ORDER BY total_accesos DESC
        LIMIT 8
    ");
    $usuarios_activos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Distribución de roles
    $stmt = $pdo->query("SELECT rol, COUNT(*) as total FROM usuarios WHERE estado='activo' GROUP BY rol");
    $roles_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $roles = array_column($roles_raw, 'total', 'rol');

    // Documentos por categoría
    $stmt = $pdo->query("
        SELECT categoria, COUNT(*) as total
        FROM documentos_administrativos
        WHERE estado='activo'
        GROUP BY categoria
        ORDER BY total DESC
    ");
    $docs_categoria = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Error reportes: " . $e->getMessage());
    // Valores por defecto
    $total_estudiantes = $matriculas_activas = $preinscripciones_pendientes = $grupos_activos = 0;
    $estudiantes_nuevos_anio = $matriculas_anio = 0;
    $inscripciones_mes_raw = $nuevos_por_mes_raw = [];
    $estados_preinscripciones = ['pendientes'=>0,'aprobadas'=>0,'rechazadas'=>0,'contactadas'=>0,'total'=>0];
    $total_preinsc = 1;
    $promedios_cursos = $asistencia_grupos = $calificaciones_tipo = [];
    $asistencia_global = ['presentes'=>0,'ausentes'=>0,'justificados'=>0,'tardanzas'=>0,'total'=>0];
    $total_asistencias = 1;
    $certificados = ['total'=>0,'aprobados'=>0,'reprobados'=>0];
    $total_bitacoras = 0;
    $estudiantes_por_curso = $ocupacion_grupos = [];
    $estado_grupos = [];
    $accesos_mes_raw = $usuarios_activos = [];
    $roles = []; $docs_categoria = [];
}

// ── Preparar arrays para JS ──────────────────────────────────────────────────
$meses_labels = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];

// Inscripciones por mes
$inscr_data = ['preinscripciones'=>array_fill(0,12,0),'matriculas'=>array_fill(0,12,0),'rechazadas'=>array_fill(0,12,0)];
foreach ($inscripciones_mes_raw as $d) {
    $i = (int)$d['mes'] - 1;
    $inscr_data['preinscripciones'][$i] = (int)$d['preinscripciones'];
    $inscr_data['matriculas'][$i]       = (int)$d['matriculas'];
    $inscr_data['rechazadas'][$i]       = (int)$d['rechazadas'];
}

// Nuevos estudiantes por mes
$nuevos_data = array_fill(0, 12, 0);
foreach ($nuevos_por_mes_raw as $d) { $nuevos_data[(int)$d['mes']-1] = (int)$d['total']; }

// Accesos por mes
$accesos_data = array_fill(0, 12, 0);
foreach ($accesos_mes_raw as $d) { $accesos_data[(int)$d['mes']-1] = (int)$d['total']; }

// Promedios cursos para JS
$cursos_nombres_js   = array_column($promedios_cursos, 'curso');
$cursos_promedios_js = array_map(fn($x) => round((float)$x['promedio'], 2), $promedios_cursos);

// Asistencia grupos para JS
$grupos_nombres_js  = array_column($asistencia_grupos, 'grupo');
$grupos_asist_js    = array_column($asistencia_grupos, 'porcentaje');

// Estudiantes por curso para JS
$epc_nombres_js     = array_column($estudiantes_por_curso, 'curso');
$epc_valores_js     = array_column($estudiantes_por_curso, 'estudiantes');

// Docs por categoría para JS
$docs_labels_js  = array_column($docs_categoria, 'categoria');
$docs_values_js  = array_column($docs_categoria, 'total');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reportes - Amimbré</title>
    <link rel="shortcut icon" href="../../assets/img/3.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0"/>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="../../assets/css/colores.css">
    <link rel="stylesheet" href="../../assets/css/style-reportes.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script>(function(){const t=localStorage.getItem('amimbre-theme');if(t==='light')document.documentElement.setAttribute('data-theme','light');})();</script>
    <style>
        /* ── RESET / BASE ── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Poppins', sans-serif; background: var(--card-bg); color: var(--text-primary); }

        /* ── LAYOUT ── */
        .main-content { margin-left: 270px; padding: 28px 32px; transition: margin-left 0.4s ease; min-height: 100vh; }
        .sidebar.collapsed ~ .main-content { margin-left: 85px; }
        @media (max-width: 768px) { .main-content { margin-left: 0; padding: 16px; } }

        /* ── HEADER ── */
        .reportes-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 28px; }
        .header-left h1 { font-size: 1.6rem; font-weight: 700; }
        .header-left p  { font-size: 0.85rem; color: var(--text-secondary); margin-top: 2px; }
        .header-right   { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }

        .year-select {
            background: var(--dark-bg); color: var(--text-primary); border: 1px solid var(--border-color);
            border-radius: 8px; padding: 8px 14px; font-size: 0.875rem; cursor: pointer;
            font-family: inherit; transition: border-color 0.2s;
        }
        .year-select:hover { border-color: var(--primary-blue); }

        .btn-action {
            display: flex; align-items: center; gap: 7px;
            padding: 8px 16px; border-radius: 8px; font-size: 0.85rem; font-weight: 500;
            cursor: pointer; border: none; font-family: inherit; transition: opacity 0.2s, transform 0.15s;
        }
        .btn-action:hover { opacity: 0.88; transform: translateY(-1px); }
        .btn-print  { background: var(--dark-bg); color: var(--text-primary); border: 1px solid var(--border-color); }
        .btn-export { background: var(--primary-blue); color: #fff; }

        /* ── STAT CARDS ── */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 28px; }
        .stat-card {
            background: var(--dark-bg); border: 1px solid var(--border-color);
            border-radius: 14px; padding: 20px; position: relative; overflow: hidden; transition: transform 0.2s;
        }
        .stat-card:hover { transform: translateY(-2px); }
        .stat-card::before { content: ''; position: absolute; inset: 0; opacity: 0.06; border-radius: 14px; }
        .stat-card.blue::before   { background: var(--primary-blue); }
        .stat-card.green::before  { background: var(--primary-green); }
        .stat-card.yellow::before { background: var(--primary-yellow); }
        .stat-card.orange::before { background: var(--primary-orange); }

        .stat-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
        .stat-title  { font-size: 0.8rem; color: var(--text-secondary); font-weight: 500; }
        .stat-icon   { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; }
        .stat-card.blue   .stat-icon { background: var(--subtle-blue);   color: var(--primary-blue);   }
        .stat-card.green  .stat-icon { background: var(--subtle-green);  color: var(--primary-green);  }
        .stat-card.yellow .stat-icon { background: var(--subtle-yellow); color: var(--primary-yellow); }
        .stat-card.orange .stat-icon { background: var(--subtle-orange); color: var(--primary-orange); }
        .stat-icon span { font-size: 1.25rem; }

        .stat-value  { font-size: 2rem; font-weight: 700; line-height: 1; margin-bottom: 8px; }
        .stat-change { font-size: 0.78rem; color: var(--text-secondary); display: flex; align-items: center; gap: 4px; }
        .stat-change.positive { color: var(--primary-green); }
        .stat-change.negative { color: var(--primary-red); }
        .stat-change span.material-symbols-rounded { font-size: 0.95rem; }

        /* ── TABS ── */
        .tabs-container { background: var(--dark-bg); border: 1px solid var(--border-color); border-radius: 16px; overflow: hidden; }
        .tabs-nav { display: flex; gap: 4px; padding: 14px 16px 0; border-bottom: 1px solid var(--border-color); overflow-x: auto; }
        .tab-btn {
            display: flex; align-items: center; gap: 7px; padding: 10px 18px;
            border: none; background: transparent; color: var(--text-secondary);
            font-size: 0.875rem; font-weight: 500; cursor: pointer; font-family: inherit;
            border-bottom: 2px solid transparent; border-radius: 8px 8px 0 0;
            transition: color 0.2s, background 0.2s; white-space: nowrap;
        }
        .tab-btn:hover { color: var(--text-primary); background: var(--hover-bg); }
        .tab-btn.active { color: var(--primary-blue); border-bottom-color: var(--primary-blue); background: var(--hover-bg); }
        .tab-btn span.material-symbols-rounded { font-size: 1.1rem; }

        .tab-content { display: none; padding: 24px; }
        .tab-content.active { display: block; }

        /* ── CHARTS GRID ── */
        .charts-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; margin-bottom: 18px; }
        .charts-grid .chart-card.large { grid-column: 1 / -1; }
        @media (max-width: 900px) { .charts-grid { grid-template-columns: 1fr; } .charts-grid .chart-card.large { grid-column: 1; } }

        .chart-card {
            background: var(--card-bg); border: 1px solid var(--border-color);
            border-radius: 12px; padding: 20px;
        }
        .chart-header { margin-bottom: 16px; }
        .chart-header h3 { font-size: 0.95rem; font-weight: 600; display: flex; align-items: center; gap: 7px; }
        .chart-header h3 span { font-size: 1.1rem; color: var(--primary-blue); }
        .chart-header p  { font-size: 0.78rem; color: var(--text-secondary); margin-top: 3px; }
        .chart-container { position: relative; height: 240px; }
        .chart-container.tall { height: 300px; }

        /* ── STATUS CARDS ROW ── */
        .status-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin-bottom: 18px; }
        .status-card {
            background: var(--card-bg); border: 1px solid var(--border-color);
            border-radius: 12px; padding: 16px; display: flex; align-items: center; gap: 14px;
        }
        .status-icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .status-icon span { font-size: 1.3rem; }
        .status-card.success .status-icon { background: var(--subtle-green);  color: var(--primary-green);  }
        .status-card.warning .status-icon { background: var(--subtle-yellow); color: var(--primary-yellow); }
        .status-card.danger  .status-icon { background: var(--subtle-red);    color: var(--primary-red);    }
        .status-card.info    .status-icon { background: var(--subtle-blue);   color: var(--primary-blue);   }
        .status-card.orange  .status-icon { background: var(--subtle-orange); color: var(--primary-orange); }
        .status-value { font-size: 1.5rem; font-weight: 700; line-height: 1; }
        .status-label { font-size: 0.8rem; font-weight: 500; margin-top: 2px; }
        .status-desc  { font-size: 0.72rem; color: var(--text-secondary); margin-top: 2px; }

        /* ── TABLE ── */
        .data-table-wrap { overflow-x: auto; border-radius: 12px; border: 1px solid var(--border-color); margin-bottom: 18px; }
        .data-table { width: 100%; border-collapse: collapse; font-size: 0.82rem; }
        .data-table th {
            background: var(--dark-bg); color: var(--text-secondary); font-weight: 500;
            padding: 11px 16px; text-align: left; border-bottom: 1px solid var(--border-color); white-space: nowrap;
        }
        .data-table td { padding: 11px 16px; border-bottom: 1px solid var(--border-color); vertical-align: middle; }
        .data-table tr:last-child td { border-bottom: none; }
        .data-table tr:hover td { background: var(--hover-bg); }
        .badge {
            display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 0.72rem; font-weight: 600;
        }
        .badge-green  { background: var(--subtle-green);  color: var(--primary-green);  }
        .badge-blue   { background: var(--subtle-blue);   color: var(--primary-blue);   }
        .badge-yellow { background: var(--subtle-yellow); color: #a0a000; }
        .badge-orange { background: var(--subtle-orange); color: var(--primary-orange); }
        .badge-red    { background: var(--subtle-red);    color: var(--primary-red);    }
        .badge-gray   { background: var(--border-color);  color: var(--text-secondary); }

        /* Progress bar */
        .progress-bar-wrap { background: var(--border-color); border-radius: 4px; height: 8px; min-width: 80px; overflow: hidden; }
        .progress-bar-fill { height: 100%; border-radius: 4px; transition: width 0.6s ease; }
        .progress-bar-fill.green  { background: var(--primary-green); }
        .progress-bar-fill.blue   { background: var(--primary-blue); }
        .progress-bar-fill.orange { background: var(--primary-orange); }

        /* ── PDF overlay ── */
        #pdf-overlay {
            display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.7);
            z-index: 9999; align-items: center; justify-content: center; flex-direction: column; gap: 16px;
        }
        #pdf-overlay.show { display: flex; }
        #pdf-overlay p { color: #fff; font-size: 1rem; font-weight: 500; }
        .pdf-spinner { width: 48px; height: 48px; border: 4px solid rgba(255,255,255,0.2); border-top-color: var(--primary-blue); border-radius: 50%; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* ── PRINT ── */
        @media print {
            .sidebar, .reportes-header .header-right, .tabs-nav, #pdf-overlay { display: none !important; }
            .main-content { margin-left: 0 !important; padding: 0 !important; }
            .tab-content { display: block !important; page-break-inside: avoid; }
            .charts-grid { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>
<body>
<?php if (file_exists('../../includes/header.php')) require_once '../../includes/header.php'; ?>

<!-- PDF loading overlay -->
<div id="pdf-overlay">
    <div class="pdf-spinner"></div>
    <p>Generando PDF, por favor espera…</p>
</div>

<main class="main-content" id="main-content">

    <!-- Header -->
    <div class="reportes-header">
        <div class="header-left">
            <h1>Reportes</h1>
            <p>Análisis y estadísticas del sistema</p>
        </div>
        <div class="header-right">
            <select class="year-select" id="yearSelect" onchange="changeYear(this.value)">
                <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                    <option value="<?= $y ?>" <?= $y == $year_selected ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
            <button class="btn-action btn-print" onclick="window.print()">
                <span class="material-symbols-rounded">print</span> Imprimir
            </button>
            <button class="btn-action btn-export" onclick="exportarPDF()">
                <span class="material-symbols-rounded">picture_as_pdf</span> Exportar PDF
            </button>
        </div>
    </div>

    <!-- Stat cards -->
    <div class="stats-grid">
        <div class="stat-card blue">
            <div class="stat-header">
                <span class="stat-title">Total Estudiantes</span>
                <div class="stat-icon"><span class="material-symbols-rounded">school</span></div>
            </div>
            <div class="stat-value"><?= number_format($total_estudiantes) ?></div>
            <div class="stat-change positive">
                <span class="material-symbols-rounded">arrow_upward</span>
                <?= $estudiantes_nuevos_anio ?> nuevos en <?= $year_selected ?>
            </div>
        </div>
        <div class="stat-card green">
            <div class="stat-header">
                <span class="stat-title">Matrículas Activas</span>
                <div class="stat-icon"><span class="material-symbols-rounded">how_to_reg</span></div>
            </div>
            <div class="stat-value"><?= number_format($matriculas_activas) ?></div>
            <div class="stat-change">
                <span class="material-symbols-rounded">event_note</span>
                <?= $matriculas_anio ?> en <?= $year_selected ?>
            </div>
        </div>
        <div class="stat-card orange">
            <div class="stat-header">
                <span class="stat-title">Grupos Activos</span>
                <div class="stat-icon"><span class="material-symbols-rounded">groups</span></div>
            </div>
            <div class="stat-value"><?= number_format($grupos_activos) ?></div>
            <div class="stat-change">
                <span class="material-symbols-rounded">music_note</span>
                En funcionamiento
            </div>
        </div>
        <div class="stat-card yellow">
            <div class="stat-header">
                <span class="stat-title">Preinscripciones</span>
                <div class="stat-icon"><span class="material-symbols-rounded">pending_actions</span></div>
            </div>
            <div class="stat-value"><?= number_format($preinscripciones_pendientes) ?></div>
            <div class="stat-change">
                <span class="material-symbols-rounded">schedule</span>
                Pendientes por revisar
            </div>
        </div>
    </div>

    <!-- Tabs -->
    <div class="tabs-container" id="reporte-tabs">
        <div class="tabs-nav">
            <button class="tab-btn active" data-tab="inscripciones">
                <span class="material-symbols-rounded">description</span> Inscripciones
            </button>
            <button class="tab-btn" data-tab="academico">
                <span class="material-symbols-rounded">school</span> Académico
            </button>
            <button class="tab-btn" data-tab="cursos">
                <span class="material-symbols-rounded">menu_book</span> Cursos
            </button>
            <button class="tab-btn" data-tab="actividad">
                <span class="material-symbols-rounded">monitoring</span> Actividad
            </button>
        </div>

        <!-- ══ TAB: INSCRIPCIONES ══════════════════════════════════════════════ -->
        <div class="tab-content active" id="inscripciones">

            <!-- Estado general -->
            <div class="status-row">
                <div class="status-card success">
                    <div class="status-icon"><span class="material-symbols-rounded">check_circle</span></div>
                    <div>
                        <div class="status-value"><?= $estados_preinscripciones['aprobadas'] ?></div>
                        <div class="status-label">Aprobadas</div>
                        <div class="status-desc"><?= $total_preinsc > 1 ? round($estados_preinscripciones['aprobadas']*100/$total_preinsc).'%' : '0%' ?> del total</div>
                    </div>
                </div>
                <div class="status-card warning">
                    <div class="status-icon"><span class="material-symbols-rounded">schedule</span></div>
                    <div>
                        <div class="status-value"><?= $estados_preinscripciones['pendientes'] ?></div>
                        <div class="status-label">Pendientes</div>
                        <div class="status-desc">Por procesar</div>
                    </div>
                </div>
                <div class="status-card info">
                    <div class="status-icon"><span class="material-symbols-rounded">contact_phone</span></div>
                    <div>
                        <div class="status-value"><?= $estados_preinscripciones['contactadas'] ?></div>
                        <div class="status-label">Contactadas</div>
                        <div class="status-desc">En seguimiento</div>
                    </div>
                </div>
                <div class="status-card danger">
                    <div class="status-icon"><span class="material-symbols-rounded">cancel</span></div>
                    <div>
                        <div class="status-value"><?= $estados_preinscripciones['rechazadas'] ?></div>
                        <div class="status-label">Rechazadas</div>
                        <div class="status-desc"><?= $total_preinsc > 1 ? round($estados_preinscripciones['rechazadas']*100/$total_preinsc).'%' : '0%' ?> del total</div>
                    </div>
                </div>
                <div class="status-card orange">
                    <div class="status-icon"><span class="material-symbols-rounded">summarize</span></div>
                    <div>
                        <div class="status-value"><?= $estados_preinscripciones['total'] ?></div>
                        <div class="status-label">Total histórico</div>
                        <div class="status-desc">Todas las solicitudes</div>
                    </div>
                </div>
            </div>

            <div class="charts-grid">
                <div class="chart-card large">
                    <div class="chart-header">
                        <h3><span class="material-symbols-rounded">bar_chart</span> Inscripciones por Mes — <?= $year_selected ?></h3>
                        <p>Comparativa de preinscripciones, matrículas aprobadas y rechazadas</p>
                    </div>
                    <div class="chart-container"><canvas id="inscripcionesChart"></canvas></div>
                </div>
                <div class="chart-card">
                    <div class="chart-header">
                        <h3><span class="material-symbols-rounded">trending_up</span> Nuevos Estudiantes</h3>
                        <p>Registros de nuevos estudiantes por mes en <?= $year_selected ?></p>
                    </div>
                    <div class="chart-container"><canvas id="nuevosChart"></canvas></div>
                </div>
                <div class="chart-card">
                    <div class="chart-header">
                        <h3><span class="material-symbols-rounded">donut_large</span> Estado de Preinscripciones</h3>
                        <p>Distribución histórica de todos los estados</p>
                    </div>
                    <div class="chart-container"><canvas id="estadosChart"></canvas></div>
                </div>
            </div>
        </div>

        <!-- ══ TAB: ACADÉMICO ═════════════════════════════════════════════════ -->
        <div class="tab-content" id="academico">

            <div class="status-row">
                <div class="status-card info">
                    <div class="status-icon"><span class="material-symbols-rounded">workspace_premium</span></div>
                    <div>
                        <div class="status-value"><?= $certificados['total'] ?></div>
                        <div class="status-label">Certificados</div>
                        <div class="status-desc"><?= $certificados['aprobados'] ?> aprobados</div>
                    </div>
                </div>
                <div class="status-card success">
                    <div class="status-icon"><span class="material-symbols-rounded">assignment</span></div>
                    <div>
                        <div class="status-value"><?= $total_bitacoras ?></div>
                        <div class="status-label">Bitácoras</div>
                        <div class="status-desc">Clases registradas</div>
                    </div>
                </div>
                <div class="status-card orange">
                    <div class="status-icon"><span class="material-symbols-rounded">how_to_reg</span></div>
                    <div>
                        <div class="status-value"><?= number_format($asistencia_global['total']) ?></div>
                        <div class="status-label">Registros asistencia</div>
                        <div class="status-desc"><?= $asistencia_global['total'] > 0 ? round($asistencia_global['presentes']*100/$asistencia_global['total']) : 0 ?>% presencia</div>
                    </div>
                </div>
                <?php
                $total_cal = array_sum(array_column($calificaciones_tipo, 'cantidad'));
                $prom_global = $total_cal > 0 ? round(array_sum(array_map(fn($x)=>$x['promedio']*$x['cantidad'],$calificaciones_tipo)) / $total_cal, 2) : 0;
                ?>
                <div class="status-card <?= $prom_global >= 3.5 ? 'success' : ($prom_global >= 3.0 ? 'warning' : 'danger') ?>">
                    <div class="status-icon"><span class="material-symbols-rounded">grade</span></div>
                    <div>
                        <div class="status-value"><?= number_format($prom_global, 1) ?></div>
                        <div class="status-label">Promedio global</div>
                        <div class="status-desc">Sobre 5.0</div>
                    </div>
                </div>
            </div>

            <div class="charts-grid">
                <?php if (!empty($promedios_cursos)): ?>
                <div class="chart-card">
                    <div class="chart-header">
                        <h3><span class="material-symbols-rounded">timeline</span> Promedio por Curso</h3>
                        <p>Calificación promedio registrada en cada curso</p>
                    </div>
                    <div class="chart-container"><canvas id="promediosChart"></canvas></div>
                </div>
                <?php else: ?>
                <div class="chart-card">
                    <div class="chart-header"><h3><span class="material-symbols-rounded">timeline</span> Promedio por Curso</h3></div>
                    <div style="padding:40px;text-align:center;color:var(--text-secondary);font-size:0.9rem;">No hay calificaciones registradas aún</div>
                </div>
                <?php endif; ?>

                <div class="chart-card">
                    <div class="chart-header">
                        <h3><span class="material-symbols-rounded">analytics</span> Asistencia Global</h3>
                        <p>Distribución de estados de asistencia registrados</p>
                    </div>
                    <div class="chart-container"><canvas id="asistenciaGlobalChart"></canvas></div>
                </div>

                <?php if (!empty($asistencia_grupos)): ?>
                <div class="chart-card large">
                    <div class="chart-header">
                        <h3><span class="material-symbols-rounded">event_available</span> % Asistencia por Grupo</h3>
                        <p>Porcentaje de presencia en cada grupo (top <?= count($asistencia_grupos) ?>)</p>
                    </div>
                    <div class="chart-container tall"><canvas id="asistenciaGruposChart"></canvas></div>
                </div>
                <?php endif; ?>

                <?php if (!empty($calificaciones_tipo)): ?>
                <div class="chart-card">
                    <div class="chart-header">
                        <h3><span class="material-symbols-rounded">quiz</span> Calificaciones por Tipo</h3>
                        <p>Cantidad de evaluaciones registradas por tipo</p>
                    </div>
                    <div class="chart-container"><canvas id="calTipoChart"></canvas></div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ══ TAB: CURSOS ════════════════════════════════════════════════════ -->
        <div class="tab-content" id="cursos">

            <div class="charts-grid">
                <?php if (!empty($estudiantes_por_curso)): ?>
                <div class="chart-card">
                    <div class="chart-header">
                        <h3><span class="material-symbols-rounded">groups</span> Estudiantes por Curso</h3>
                        <p>Matrículas activas en cada curso</p>
                    </div>
                    <div class="chart-container"><canvas id="estudCursoChart"></canvas></div>
                </div>
                <?php endif; ?>

                <div class="chart-card">
                    <div class="chart-header">
                        <h3><span class="material-symbols-rounded">donut_large</span> Estado de Grupos</h3>
                        <p>Distribución de grupos por estado actual</p>
                    </div>
                    <div class="chart-container"><canvas id="estadoGruposChart"></canvas></div>
                </div>
            </div>

            <!-- Tabla ocupación grupos -->
            <?php if (!empty($ocupacion_grupos)): ?>
            <div class="chart-card" style="margin-bottom:18px;">
                <div class="chart-header">
                    <h3><span class="material-symbols-rounded">density_medium</span> Ocupación de Grupos Activos</h3>
                    <p>Cupos utilizados vs disponibles</p>
                </div>
                <div class="data-table-wrap" style="margin-top:16px;border:none;">
                    <table class="data-table">
                        <thead><tr><th>Grupo</th><th>Curso</th><th>Cupo actual</th><th>Cupo máximo</th><th>Ocupación</th></tr></thead>
                        <tbody>
                        <?php foreach ($ocupacion_grupos as $g): ?>
                            <?php $pct = (int)$g['ocupacion_pct']; $color = $pct >= 80 ? 'green' : ($pct >= 50 ? 'blue' : 'orange'); ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($g['nombre']) ?></strong></td>
                                <td><?= htmlspecialchars($g['curso']) ?></td>
                                <td><?= $g['cupo_actual'] ?></td>
                                <td><?= $g['cupo_maximo'] ?></td>
                                <td>
                                    <div style="display:flex;align-items:center;gap:10px;">
                                        <div class="progress-bar-wrap" style="flex:1;">
                                            <div class="progress-bar-fill <?= $color ?>" style="width:<?= $pct ?>%"></div>
                                        </div>
                                        <span style="font-size:0.8rem;font-weight:600;min-width:36px;"><?= $pct ?>%</span>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- Tabla cursos detallada -->
            <div class="data-table-wrap">
                <table class="data-table">
                    <thead><tr><th>Curso</th><th>Nivel</th><th>Estado</th><th>Grupos</th><th>Estudiantes</th></tr></thead>
                    <tbody>
                    <?php foreach ($estudiantes_por_curso as $c): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($c['curso']) ?></strong></td>
                            <td>
                                <span class="badge <?= $c['nivel']=='basico'?'badge-green':($c['nivel']=='intermedio'?'badge-blue':'badge-orange') ?>">
                                    <?= ucfirst($c['nivel']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?= $c['estado_curso']=='activo'?'badge-green':'badge-gray' ?>">
                                    <?= ucfirst($c['estado_curso']) ?>
                                </span>
                            </td>
                            <td><?= $c['grupos'] ?></td>
                            <td><?= $c['estudiantes'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ══ TAB: ACTIVIDAD ═════════════════════════════════════════════════ -->
        <div class="tab-content" id="actividad">

            <div class="status-row">
                <div class="status-card blue">
                    <div class="status-icon"><span class="material-symbols-rounded">people</span></div>
                    <div>
                        <div class="status-value"><?= array_sum($roles) ?></div>
                        <div class="status-label">Usuarios activos</div>
                        <div class="status-desc">Total en el sistema</div>
                    </div>
                </div>
                <div class="status-card success">
                    <div class="status-icon"><span class="material-symbols-rounded">person_4</span></div>
                    <div>
                        <div class="status-value"><?= $roles['profesor'] ?? 0 ?></div>
                        <div class="status-label">Profesores</div>
                        <div class="status-desc">Activos</div>
                    </div>
                </div>
                <div class="status-card info">
                    <div class="status-icon"><span class="material-symbols-rounded">folder_open</span></div>
                    <div>
                        <div class="status-value"><?= array_sum(array_column($docs_categoria, 'total')) ?></div>
                        <div class="status-label">Documentos</div>
                        <div class="status-desc">Administrativos</div>
                    </div>
                </div>
                <div class="status-card orange">
                    <div class="status-icon"><span class="material-symbols-rounded">login</span></div>
                    <div>
                        <div class="status-value"><?= array_sum($accesos_data) ?></div>
                        <div class="status-label">Accesos</div>
                        <div class="status-desc">En <?= $year_selected ?></div>
                    </div>
                </div>
            </div>

            <div class="charts-grid">
                <div class="chart-card large">
                    <div class="chart-header">
                        <h3><span class="material-symbols-rounded">monitoring</span> Accesos al Sistema — <?= $year_selected ?></h3>
                        <p>Número de inicios de sesión por mes</p>
                    </div>
                    <div class="chart-container"><canvas id="accesosChart"></canvas></div>
                </div>

                <div class="chart-card">
                    <div class="chart-header">
                        <h3><span class="material-symbols-rounded">manage_accounts</span> Distribución de Roles</h3>
                        <p>Usuarios activos por tipo de rol</p>
                    </div>
                    <div class="chart-container"><canvas id="rolesChart"></canvas></div>
                </div>

                <?php if (!empty($docs_categoria)): ?>
                <div class="chart-card">
                    <div class="chart-header">
                        <h3><span class="material-symbols-rounded">folder</span> Documentos por Categoría</h3>
                        <p>Distribución de documentos administrativos</p>
                    </div>
                    <div class="chart-container"><canvas id="docsChart"></canvas></div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Tabla usuarios más activos -->
            <?php if (!empty($usuarios_activos)): ?>
            <div class="data-table-wrap">
                <table class="data-table">
                    <thead><tr><th>Usuario</th><th>Rol</th><th>Total accesos</th><th>Última conexión</th></tr></thead>
                    <tbody>
                    <?php foreach ($usuarios_activos as $u): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($u['nombre']) ?></strong></td>
                            <td>
                                <span class="badge <?= $u['rol']=='admin'?'badge-orange':($u['rol']=='profesor'?'badge-blue':'badge-green') ?>">
                                    <?= ucfirst($u['rol']) ?>
                                </span>
                            </td>
                            <td><?= $u['total_accesos'] ?></td>
                            <td style="color:var(--text-secondary);font-size:0.8rem;">
                                <?= $u['ultima_conexion'] ? date('d/m/Y H:i', strtotime($u['ultima_conexion'])) : 'Nunca' ?>
                            </td>
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
// ── Datos desde PHP ────────────────────────────────────────────────────────
const MESES   = <?= json_encode($meses_labels) ?>;
const INSCR   = <?= json_encode($inscr_data) ?>;
const NUEVOS  = <?= json_encode($nuevos_data) ?>;
const ACCESOS = <?= json_encode($accesos_data) ?>;

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
const GRUPOS_ASIST     = <?= json_encode($grupos_asist_js) ?>;
const EPC_NOMBRES      = <?= json_encode($epc_nombres_js) ?>;
const EPC_VALORES      = <?= json_encode($epc_valores_js) ?>;
const CAL_TIPO         = <?= json_encode($calificaciones_tipo) ?>;
const ESTADO_GRUPOS    = <?= json_encode($estado_grupos) ?>;
const ROLES            = <?= json_encode($roles) ?>;
const DOCS_LABELS      = <?= json_encode($docs_labels_js) ?>;
const DOCS_VALUES      = <?= json_encode($docs_values_js) ?>;

// ── Paleta ────────────────────────────────────────────────────────────────
const C = {
    blue:   '#1479b0', green:  '#4ec336', orange: '#ff6d00', yellow: '#e9e93e', red: '#ba2626',
    sBlue:  '#1479b03a', sGreen: '#4ec33633', sOrange: '#ff6f003d', sYellow: '#e9e93e38', sRed: '#ba262646',
    purple: '#8b5cf6', teal: '#14b8a6', pink: '#ec4899'
};
const GRID = '#334155', TICK = '#94a3b8', LEGEND = '#f8fafc';

const baseOpts = (extra = {}) => ({
    responsive: true, maintainAspectRatio: false,
    plugins: {
        legend: { position: 'bottom', labels: { color: LEGEND, padding: 14, font: { size: 12, family: 'Poppins' } } }
    },
    scales: {
        y: { beginAtZero: true, ticks: { color: TICK }, grid: { color: GRID } },
        x: { ticks: { color: TICK }, grid: { color: GRID } }
    },
    ...extra
});

const noScales = () => ({
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { position: 'bottom', labels: { color: LEGEND, padding: 14, font: { size: 12, family: 'Poppins' } } } }
});

// ── Helpers ───────────────────────────────────────────────────────────────
function mkChart(id, config) {
    const el = document.getElementById(id);
    if (!el) return;
    return new Chart(el.getContext('2d'), config);
}

// ── INSCRIPCIONES ─────────────────────────────────────────────────────────
mkChart('inscripcionesChart', {
    type: 'bar',
    data: {
        labels: MESES,
        datasets: [
            { label: 'Preinscripciones', data: INSCR.preinscripciones, backgroundColor: C.blue,   borderRadius: 5 },
            { label: 'Matrículas',       data: INSCR.matriculas,       backgroundColor: C.green,  borderRadius: 5 },
            { label: 'Rechazadas',       data: INSCR.rechazadas,       backgroundColor: C.orange, borderRadius: 5 }
        ]
    },
    options: baseOpts()
});

mkChart('nuevosChart', {
    type: 'line',
    data: {
        labels: MESES,
        datasets: [{ label: 'Nuevos estudiantes', data: NUEVOS, borderColor: C.green, backgroundColor: C.sGreen, fill: true, tension: 0.4, borderWidth: 2, pointRadius: 4, pointBackgroundColor: C.green }]
    },
    options: baseOpts()
});

mkChart('estadosChart', {
    type: 'doughnut',
    data: {
        labels: ['Aprobadas', 'Pendientes', 'Contactadas', 'Rechazadas'],
        datasets: [{ data: [ESTADOS_PREINSC.aprobadas, ESTADOS_PREINSC.pendientes, ESTADOS_PREINSC.contactadas, ESTADOS_PREINSC.rechazadas], backgroundColor: [C.green, C.yellow, C.blue, C.orange], borderWidth: 0 }]
    },
    options: noScales()
});

// ── ACADÉMICO ─────────────────────────────────────────────────────────────
if (CURSOS_NOMBRES.length) {
    mkChart('promediosChart', {
        type: 'bar',
        data: {
            labels: CURSOS_NOMBRES,
            datasets: [{ label: 'Promedio', data: CURSOS_PROMEDIOS, backgroundColor: [C.blue,C.green,C.orange,C.yellow,C.purple,C.teal,C.pink,C.red], borderRadius: 6 }]
        },
        options: { ...baseOpts(), scales: { ...baseOpts().scales, y: { ...baseOpts().scales.y, max: 5, ticks: { color: TICK } } } }
    });
}

mkChart('asistenciaGlobalChart', {
    type: 'doughnut',
    data: {
        labels: ['Presente', 'Ausente', 'Justificado', 'Tardanza'],
        datasets: [{ data: [ASIST_GLOBAL.presentes, ASIST_GLOBAL.ausentes, ASIST_GLOBAL.justificados, ASIST_GLOBAL.tardanzas], backgroundColor: [C.green, C.red, C.blue, C.yellow], borderWidth: 0 }]
    },
    options: noScales()
});

if (GRUPOS_NOMBRES.length) {
    mkChart('asistenciaGruposChart', {
        type: 'bar',
        data: {
            labels: GRUPOS_NOMBRES,
            datasets: [{ label: '% Asistencia', data: GRUPOS_ASIST, backgroundColor: GRUPOS_ASIST.map(v => v >= 80 ? C.green : v >= 60 ? C.blue : C.orange), borderRadius: 5 }]
        },
        options: { ...baseOpts(), indexAxis: 'y', scales: { x: { beginAtZero: true, max: 100, ticks: { color: TICK, callback: v => v + '%' }, grid: { color: GRID } }, y: { ticks: { color: TICK }, grid: { color: GRID } } } }
    });
}

if (CAL_TIPO.length) {
    mkChart('calTipoChart', {
        type: 'pie',
        data: {
            labels: CAL_TIPO.map(t => t.tipo_evaluacion.charAt(0).toUpperCase() + t.tipo_evaluacion.slice(1)),
            datasets: [{ data: CAL_TIPO.map(t => t.cantidad), backgroundColor: [C.blue, C.green, C.orange, C.yellow], borderWidth: 0 }]
        },
        options: noScales()
    });
}

// ── CURSOS ────────────────────────────────────────────────────────────────
if (EPC_NOMBRES.length) {
    mkChart('estudCursoChart', {
        type: 'bar',
        data: {
            labels: EPC_NOMBRES,
            datasets: [{ label: 'Estudiantes', data: EPC_VALORES, backgroundColor: C.blue, borderRadius: 6 }]
        },
        options: baseOpts()
    });
}

const eGLabels = Object.keys(ESTADO_GRUPOS).map(k => k.charAt(0).toUpperCase() + k.slice(1));
const eGValues = Object.values(ESTADO_GRUPOS);
mkChart('estadoGruposChart', {
    type: 'doughnut',
    data: {
        labels: eGLabels.length ? eGLabels : ['Sin grupos'],
        datasets: [{ data: eGValues.length ? eGValues : [0], backgroundColor: [C.green, C.blue, C.orange, C.red], borderWidth: 0 }]
    },
    options: noScales()
});

// ── ACTIVIDAD ─────────────────────────────────────────────────────────────
mkChart('accesosChart', {
    type: 'line',
    data: {
        labels: MESES,
        datasets: [{ label: 'Accesos', data: ACCESOS, borderColor: C.blue, backgroundColor: C.sBlue, fill: true, tension: 0.4, borderWidth: 2, pointRadius: 4, pointBackgroundColor: C.blue }]
    },
    options: baseOpts()
});

const rolLabels = Object.keys(ROLES).map(r => r.charAt(0).toUpperCase() + r.slice(1));
const rolValues = Object.values(ROLES);
mkChart('rolesChart', {
    type: 'doughnut',
    data: {
        labels: rolLabels.length ? rolLabels : ['Sin usuarios'],
        datasets: [{ data: rolValues.length ? rolValues : [0], backgroundColor: [C.orange, C.blue, C.green], borderWidth: 0 }]
    },
    options: noScales()
});

if (DOCS_LABELS.length) {
    mkChart('docsChart', {
        type: 'bar',
        data: {
            labels: DOCS_LABELS.map(l => l.charAt(0).toUpperCase() + l.slice(1)),
            datasets: [{ label: 'Documentos', data: DOCS_VALUES, backgroundColor: C.teal, borderRadius: 5 }]
        },
        options: baseOpts()
    });
}

// ── TABS ──────────────────────────────────────────────────────────────────
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById(btn.dataset.tab).classList.add('active');
    });
});

// ── AÑO ───────────────────────────────────────────────────────────────────
function changeYear(y) { window.location.href = '?year=' + y; }

// ── EXPORT PDF ────────────────────────────────────────────────────────────
async function exportarPDF() {
    const overlay = document.getElementById('pdf-overlay');
    overlay.classList.add('show');

    await new Promise(r => setTimeout(r, 100));

    try {
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });
        const W = doc.internal.pageSize.getWidth();
        const H = doc.internal.pageSize.getHeight();

        // Función para capturar sección
        const capturar = async (elementId, titulo, showAll = false) => {
            // Activar tab si es necesario
            if (elementId !== 'main-content') {
                document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                const tabId = elementId;
                const btn = document.querySelector(`[data-tab="${tabId}"]`);
                if (btn) btn.classList.add('active');
                const content = document.getElementById(tabId);
                if (content) content.classList.add('active');
                await new Promise(r => setTimeout(r, 400));
            }

            const el = document.getElementById(elementId === 'main-content' ? 'main-content' : elementId) 
                        || document.querySelector('.stats-grid');
            if (!el) return;

            const canvas = await html2canvas(el, { scale: 1.5, useCORS: true, backgroundColor: '#15181b', logging: false });
            const img = canvas.toDataURL('image/jpeg', 0.85);
            return img;
        };

        const anio = document.getElementById('yearSelect').value;

        // ── Página 1: Portada + stats ──
        doc.setFillColor(4, 12, 19);
        doc.rect(0, 0, W, H, 'F');

        // Header portada
        doc.setFillColor(20, 121, 176);
        doc.rect(0, 0, W, 22, 'F');
        doc.setTextColor(255, 255, 255);
        doc.setFontSize(16); doc.setFont('helvetica', 'bold');
        doc.text('AMIMBRÉ — Escuela de Música', 14, 14);
        doc.setFontSize(10); doc.setFont('helvetica', 'normal');
        doc.text(`Reporte Anual ${anio}`, W - 14, 14, { align: 'right' });

        // Stats cards rápidas
        const stats = [
            { label: 'Estudiantes',  value: '<?= $total_estudiantes ?>', color: [20,121,176] },
            { label: 'Matrículas',   value: '<?= $matriculas_activas ?>', color: [78,195,54] },
            { label: 'Grupos activos',value: '<?= $grupos_activos ?>',  color: [255,109,0] },
            { label: 'Preinscripciones',value:'<?= $preinscripciones_pendientes ?>',color:[233,233,62]}
        ];
        const cardW = (W - 28 - 9) / 4;
        stats.forEach((s, i) => {
            const x = 14 + i * (cardW + 3);
            doc.setFillColor(21, 24, 27);
            doc.roundedRect(x, 28, cardW, 28, 3, 3, 'F');
            doc.setFillColor(...s.color);
            doc.roundedRect(x, 28, 4, 28, 2, 2, 'F');
            doc.setTextColor(148, 163, 184);
            doc.setFontSize(8); doc.setFont('helvetica', 'normal');
            doc.text(s.label, x + 8, 38);
            doc.setTextColor(248, 250, 252);
            doc.setFontSize(18); doc.setFont('helvetica', 'bold');
            doc.text(s.value, x + 8, 50);
        });

        // Capturar tab inscripciones
        const imgInscr = await capturar('inscripciones', 'Inscripciones');
        if (imgInscr) {
            doc.addPage();
            doc.setFillColor(4, 12, 19); doc.rect(0,0,W,H,'F');
            doc.setFillColor(20,121,176); doc.rect(0,0,W,12,'F');
            doc.setTextColor(255,255,255); doc.setFontSize(11); doc.setFont('helvetica','bold');
            doc.text('Inscripciones', 14, 8);
            doc.addImage(imgInscr, 'JPEG', 14, 16, W-28, H-22);
        }

        // Capturar tab académico
        const imgAcad = await capturar('academico', 'Académico');
        if (imgAcad) {
            doc.addPage();
            doc.setFillColor(4, 12, 19); doc.rect(0,0,W,H,'F');
            doc.setFillColor(78,195,54); doc.rect(0,0,W,12,'F');
            doc.setTextColor(4,12,19); doc.setFontSize(11); doc.setFont('helvetica','bold');
            doc.text('Académico', 14, 8);
            doc.addImage(imgAcad, 'JPEG', 14, 16, W-28, H-22);
        }

        // Capturar tab cursos
        const imgCursos = await capturar('cursos', 'Cursos');
        if (imgCursos) {
            doc.addPage();
            doc.setFillColor(4, 12, 19); doc.rect(0,0,W,H,'F');
            doc.setFillColor(255,109,0); doc.rect(0,0,W,12,'F');
            doc.setTextColor(255,255,255); doc.setFontSize(11); doc.setFont('helvetica','bold');
            doc.text('Cursos', 14, 8);
            doc.addImage(imgCursos, 'JPEG', 14, 16, W-28, H-22);
        }

        // Capturar tab actividad
        const imgActiv = await capturar('actividad', 'Actividad');
        if (imgActiv) {
            doc.addPage();
            doc.setFillColor(4, 12, 19); doc.rect(0,0,W,H,'F');
            doc.setFillColor(139,92,246); doc.rect(0,0,W,12,'F');
            doc.setTextColor(255,255,255); doc.setFontSize(11); doc.setFont('helvetica','bold');
            doc.text('Actividad del Sistema', 14, 8);
            doc.addImage(imgActiv, 'JPEG', 14, 16, W-28, H-22);
        }

        // Pie de página en todas las páginas
        const totalPages = doc.internal.getNumberOfPages();
        for (let p = 1; p <= totalPages; p++) {
            doc.setPage(p);
            doc.setFontSize(7); doc.setTextColor(100,116,139);
            doc.setFont('helvetica', 'normal');
            doc.text(`Generado el ${new Date().toLocaleDateString('es-CO', {day:'2-digit',month:'long',year:'numeric'})} — Amimbré Sistema de Gestión`, 14, H - 5);
            doc.text(`Página ${p} de ${totalPages}`, W - 14, H - 5, { align: 'right' });
        }

        doc.save(`Reporte_Amimbre_${anio}.pdf`);

        // Restaurar tab inicial
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        document.querySelector('[data-tab="inscripciones"]').classList.add('active');
        document.getElementById('inscripciones').classList.add('active');

    } catch (err) {
        console.error('Error generando PDF:', err);
        alert('Ocurrió un error al generar el PDF. Revisa la consola para más detalles.');
    } finally {
        overlay.classList.remove('show');
    }
}
</script>
</body>
</html>