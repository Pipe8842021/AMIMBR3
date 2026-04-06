<?php
/**
 * Reportes - Análisis y estadísticas del sistema
 * Amimbré - Escuela de Música
 * Versión mejorada con reportes específicos descargables
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
$month_selected = isset($_GET['month']) ? (int)$_GET['month'] : 0; // 0 = todos

// ── Manejo de descarga de reportes específicos ────────────────────────────
$reporte_tipo = $_GET['reporte'] ?? '';

if ($reporte_tipo && isset($_GET['download'])) {
    // Los reportes de descarga se manejarán vía JS con jsPDF + datos del DOM
    // Aquí solo procesamos si fuera CSV puro (sin layout)
}

try {
    // ── TARJETAS PRINCIPALES ──────────────────────────────────────────────────
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

    // ── FINANCIERO ────────────────────────────────────────────────────────────
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

    // Ingresos por mes
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

    // Ingresos por curso
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

    // Pagos vencidos por estudiante
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

    // Métodos de pago
    $stmt = $pdo->query("
        SELECT metodo_pago, COUNT(*) as cantidad, SUM(monto) as total
        FROM pagos WHERE estado='pagado'
        GROUP BY metodo_pago
    ");
    $metodos_pago = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── INSCRIPCIONES ────────────────────────────────────────────────────────
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

    // Tasa de conversión por programa
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

    // Preinscripciones por municipio
    $stmt = $pdo->query("
        SELECT COALESCE(municipio,'No registra') as municipio, COUNT(*) as total
        FROM preinscripciones
        GROUP BY municipio
        ORDER BY total DESC
        LIMIT 6
    ");
    $preinscr_municipio = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Nuevos estudiantes por mes
    $stmt = $pdo->prepare("
        SELECT MONTH(fecha_registro) as mes, COUNT(*) as total
        FROM usuarios
        WHERE rol='estudiante' AND estado='activo' AND YEAR(fecha_registro)=?
        GROUP BY MONTH(fecha_registro)
        ORDER BY mes
    ");
    $stmt->execute([$year_selected]);
    $nuevos_por_mes_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── ACADÉMICO ─────────────────────────────────────────────────────────────
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

    // Rendimiento por estudiante
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

    // Asistencia global
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

    // Asistencia por grupo
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

    // Certificados
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

    // Bitácoras por profesor
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

    // ── CURSOS ────────────────────────────────────────────────────────────────
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

    // ── ACTIVIDAD ─────────────────────────────────────────────────────────────
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

    // Profesores con sus grupos
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

// ── Preparar datos para JS ─────────────────────────────────────────────────
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
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
    <script>(function(){const t=localStorage.getItem('amimbre-theme');if(t==='light')document.documentElement.setAttribute('data-theme','light');})();</script>
    <style>
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
        body{font-family:'Poppins',sans-serif;background:var(--card-bg);color:var(--text-primary)}

        /* Layout */
        .main-content{margin-left:270px;padding:28px 32px;transition:margin-left .4s ease;min-height:100vh}
        .sidebar.collapsed~.main-content{margin-left:85px}
        @media(max-width:768px){.main-content{margin-left:0;padding:16px}}

        /* Header */
        .page-header{display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:28px}
        .header-left h1{font-size:1.6rem;font-weight:700}
        .header-left p{font-size:.85rem;color:var(--text-secondary);margin-top:2px}
        .header-controls{display:flex;align-items:center;gap:10px;flex-wrap:wrap}

        .year-select{background:var(--dark-bg);color:var(--text-primary);border:1px solid var(--border-color);border-radius:8px;padding:8px 14px;font-size:.875rem;cursor:pointer;font-family:inherit}
        .year-select:hover{border-color:var(--primary-blue)}

        .btn{display:inline-flex;align-items:center;gap:7px;padding:8px 16px;border-radius:8px;font-size:.85rem;font-weight:500;cursor:pointer;border:none;font-family:inherit;transition:opacity .2s,transform .15s;text-decoration:none;white-space:nowrap}
        .btn:hover{opacity:.88;transform:translateY(-1px)}
        .btn-ghost{background:var(--dark-bg);color:var(--text-primary);border:1px solid var(--border-color)}
        .btn-blue{background:var(--primary-blue);color:#fff}
        .btn-green{background:var(--primary-green);color:#fff}
        .btn-orange{background:var(--primary-orange);color:#fff}
        .btn-sm{padding:6px 12px;font-size:.78rem}

        /* Stat Cards */
        .stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:28px}
        .stat-card{background:var(--dark-bg);border:1px solid var(--border-color);border-radius:14px;padding:20px;position:relative;overflow:hidden;transition:transform .2s}
        .stat-card:hover{transform:translateY(-2px)}
        .stat-card::before{content:'';position:absolute;inset:0;opacity:.06;border-radius:14px}
        .stat-card.blue::before{background:var(--primary-blue)}
        .stat-card.green::before{background:var(--primary-green)}
        .stat-card.orange::before{background:var(--primary-orange)}
        .stat-card.yellow::before{background:var(--primary-yellow)}
        .stat-card.red::before{background:var(--primary-red)}
        .stat-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px}
        .stat-title{font-size:.8rem;color:var(--text-secondary);font-weight:500}
        .stat-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center}
        .stat-card.blue .stat-icon{background:var(--subtle-blue);color:var(--primary-blue)}
        .stat-card.green .stat-icon{background:var(--subtle-green);color:var(--primary-green)}
        .stat-card.orange .stat-icon{background:var(--subtle-orange);color:var(--primary-orange)}
        .stat-card.yellow .stat-icon{background:var(--subtle-yellow);color:var(--primary-yellow)}
        .stat-card.red .stat-icon{background:var(--subtle-red);color:var(--primary-red)}
        .stat-icon span{font-size:1.25rem}
        .stat-value{font-size:2rem;font-weight:700;line-height:1;margin-bottom:8px}
        .stat-change{font-size:.78rem;color:var(--text-secondary);display:flex;align-items:center;gap:4px}
        .stat-change.pos{color:var(--primary-green)}
        .stat-change.neg{color:var(--primary-red)}
        .stat-change span.material-symbols-rounded{font-size:.95rem}

        /* Tabs */
        .tabs-container{background:var(--dark-bg);border:1px solid var(--border-color);border-radius:16px;overflow:hidden}
        .tabs-nav{display:flex;gap:4px;padding:14px 16px 0;border-bottom:1px solid var(--border-color);overflow-x:auto}
        .tab-btn{display:flex;align-items:center;gap:7px;padding:10px 18px;border:none;background:transparent;color:var(--text-secondary);font-size:.875rem;font-weight:500;cursor:pointer;font-family:inherit;border-bottom:2px solid transparent;border-radius:8px 8px 0 0;transition:color .2s,background .2s;white-space:nowrap}
        .tab-btn:hover{color:var(--text-primary);background:var(--hover-bg)}
        .tab-btn.active{color:var(--primary-blue);border-bottom-color:var(--primary-blue);background:var(--hover-bg)}
        .tab-btn span.material-symbols-rounded{font-size:1.1rem}
        .tab-content{display:none;padding:24px}
        .tab-content.active{display:block}

        /* Charts grid */
        .charts-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:18px}
        .charts-grid .chart-card.full{grid-column:1/-1}
        @media(max-width:900px){.charts-grid{grid-template-columns:1fr}.charts-grid .chart-card.full{grid-column:1}}

        .chart-card{background:var(--card-bg);border:1px solid var(--border-color);border-radius:12px;padding:20px}
        .chart-header{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:8px}
        .chart-header-left h3{font-size:.95rem;font-weight:600;display:flex;align-items:center;gap:7px}
        .chart-header-left h3 span{font-size:1.1rem;color:var(--primary-blue)}
        .chart-header-left p{font-size:.78rem;color:var(--text-secondary);margin-top:3px}
        .chart-container{position:relative;height:240px}
        .chart-container.tall{height:300px}
        .chart-container.short{height:180px}

        /* Section separators */
        .section-title{font-size:1rem;font-weight:600;margin-bottom:14px;display:flex;align-items:center;gap:8px;color:var(--text-primary)}
        .section-title span{font-size:1.1rem;color:var(--primary-blue)}

        /* Status row */
        .kpi-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:18px}
        .kpi-card{background:var(--card-bg);border:1px solid var(--border-color);border-radius:12px;padding:16px;display:flex;align-items:center;gap:14px}
        .kpi-icon{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
        .kpi-icon span{font-size:1.3rem}
        .kpi-card.success .kpi-icon{background:var(--subtle-green);color:var(--primary-green)}
        .kpi-card.warning .kpi-icon{background:var(--subtle-yellow);color:var(--primary-yellow)}
        .kpi-card.danger  .kpi-icon{background:var(--subtle-red);color:var(--primary-red)}
        .kpi-card.info    .kpi-icon{background:var(--subtle-blue);color:var(--primary-blue)}
        .kpi-card.orange  .kpi-icon{background:var(--subtle-orange);color:var(--primary-orange)}
        .kpi-value{font-size:1.5rem;font-weight:700;line-height:1}
        .kpi-label{font-size:.8rem;font-weight:500;margin-top:2px}
        .kpi-desc{font-size:.72rem;color:var(--text-secondary);margin-top:2px}

        /* Tables */
        .table-wrap{overflow-x:auto;border-radius:12px;border:1px solid var(--border-color);margin-bottom:18px}
        .data-table{width:100%;border-collapse:collapse;font-size:.82rem}
        .data-table th{background:var(--dark-bg);color:var(--text-secondary);font-weight:500;padding:11px 16px;text-align:left;border-bottom:1px solid var(--border-color);white-space:nowrap}
        .data-table td{padding:11px 16px;border-bottom:1px solid var(--border-color);vertical-align:middle}
        .data-table tr:last-child td{border-bottom:none}
        .data-table tr:hover td{background:var(--hover-bg)}

        /* Badges */
        .badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:600}
        .badge-green{background:var(--subtle-green);color:var(--primary-green)}
        .badge-blue{background:var(--subtle-blue);color:var(--primary-blue)}
        .badge-yellow{background:var(--subtle-yellow);color:#a0a000}
        .badge-orange{background:var(--subtle-orange);color:var(--primary-orange)}
        .badge-red{background:var(--subtle-red);color:var(--primary-red)}
        .badge-gray{background:var(--border-color);color:var(--text-secondary)}

        /* Progress */
        .progress-wrap{background:var(--border-color);border-radius:4px;height:8px;min-width:80px;overflow:hidden}
        .progress-fill{height:100%;border-radius:4px;transition:width .6s ease}
        .progress-fill.green{background:var(--primary-green)}
        .progress-fill.blue{background:var(--primary-blue)}
        .progress-fill.orange{background:var(--primary-orange)}
        .progress-fill.red{background:var(--primary-red)}

        /* ── Reporte Card (Descargables) ── */
        .reportes-descargables{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;margin-bottom:28px}
        .reporte-card{background:var(--dark-bg);border:1px solid var(--border-color);border-radius:14px;padding:20px;display:flex;flex-direction:column;gap:14px;transition:transform .2s,border-color .2s}
        .reporte-card:hover{transform:translateY(-2px);border-color:var(--primary-blue)}
        .reporte-card-header{display:flex;align-items:center;gap:12px}
        .reporte-card-icon{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
        .reporte-card-icon span{font-size:1.5rem}
        .reporte-card-icon.blue{background:var(--subtle-blue);color:var(--primary-blue)}
        .reporte-card-icon.green{background:var(--subtle-green);color:var(--primary-green)}
        .reporte-card-icon.orange{background:var(--subtle-orange);color:var(--primary-orange)}
        .reporte-card-icon.red{background:var(--subtle-red);color:var(--primary-red)}
        .reporte-card-icon.yellow{background:var(--subtle-yellow);color:var(--primary-yellow)}
        .reporte-card-icon.teal{background:#14b8a620;color:#14b8a6}
        .reporte-card-title{font-size:.95rem;font-weight:600}
        .reporte-card-desc{font-size:.8rem;color:var(--text-secondary);line-height:1.5}
        .reporte-card-actions{display:flex;gap:8px;flex-wrap:wrap}

        /* PDF overlay */
        #pdf-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:9999;align-items:center;justify-content:center;flex-direction:column;gap:16px}
        #pdf-overlay.show{display:flex}
        #pdf-overlay p{color:#fff;font-size:1rem;font-weight:500}
        #pdf-overlay small{color:rgba(255,255,255,.6);font-size:.8rem}
        .pdf-spinner{width:48px;height:48px;border:4px solid rgba(255,255,255,.2);border-top-color:var(--primary-blue);border-radius:50%;animation:spin .8s linear infinite}
        @keyframes spin{to{transform:rotate(360deg)}}

        /* Divider */
        .divider{height:1px;background:var(--border-color);margin:18px 0}

        /* Alert info */
        .alert-info{background:var(--subtle-blue);border:1px solid var(--primary-blue);border-radius:10px;padding:12px 16px;font-size:.82rem;display:flex;align-items:center;gap:10px;margin-bottom:16px}
        .alert-info span.material-symbols-rounded{color:var(--primary-blue);font-size:1.1rem}

        @media print{
            .sidebar,.page-header .header-controls,.tabs-nav,#pdf-overlay{display:none!important}
            .main-content{margin-left:0!important;padding:0!important}
            .tab-content{display:block!important;page-break-inside:avoid}
        }
    </style>
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
            <h1>Reportes</h1>
            <p>Análisis, estadísticas y reportes descargables del sistema</p>
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

    <!-- ══ TABS ══ -->
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

        <!-- ══════════════════════════════════════════════════════════════════
             TAB 1: REPORTES DESCARGABLES
        ══════════════════════════════════════════════════════════════════ -->
        <div class="tab-content active" id="tab-descargables">
            <div class="alert-info">
                <span class="material-symbols-rounded">info</span>
                Genera y descarga reportes específicos en PDF con datos actualizados al instante. Cada reporte incluye tablas, gráficas y análisis relevantes.
            </div>

            <div class="reportes-descargables">

                <!-- Reporte Financiero -->
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

                <!-- Reporte Académico -->
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

                <!-- Reporte Inscripciones -->
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

                <!-- Reporte Cartera Vencida -->
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

                <!-- Reporte Asistencia -->
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

                <!-- Reporte Cursos y Grupos -->
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

            </div><!-- /reportes-descargables -->
        </div>

        <!-- ══════════════════════════════════════════════════════════════════
             TAB 2: FINANCIERO
        ══════════════════════════════════════════════════════════════════ -->
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
                            <p>Comparativa de ingresos confirmados, vencidos y pendientes (COP)</p>
                        </div>
                        <button class="btn btn-green btn-sm" onclick="descargarReporte('financiero')">
                            <span class="material-symbols-rounded">download</span> PDF
                        </button>
                    </div>
                    <div class="chart-container"><canvas id="ingresosChart"></canvas></div>
                </div>

                <?php if (!empty($ingresos_curso)): ?>
                <div class="chart-card">
                    <div class="chart-header">
                        <div class="chart-header-left">
                            <h3><span class="material-symbols-rounded">library_music</span> Ingresos por Curso</h3>
                            <p>Pagado vs vencido por programa</p>
                        </div>
                    </div>
                    <div class="chart-container"><canvas id="ingresosCursoChart"></canvas></div>
                </div>
                <?php endif; ?>

                <?php if (!empty($metodos_pago)): ?>
                <div class="chart-card">
                    <div class="chart-header">
                        <div class="chart-header-left">
                            <h3><span class="material-symbols-rounded">credit_card</span> Métodos de Pago</h3>
                            <p>Distribución de pagos recibidos</p>
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

        <!-- ══════════════════════════════════════════════════════════════════
             TAB 3: INSCRIPCIONES
        ══════════════════════════════════════════════════════════════════ -->
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
                            <p>Preinscripciones, matrículas aprobadas y rechazadas</p>
                        </div>
                        <button class="btn btn-orange btn-sm" onclick="descargarReporte('inscripciones')">
                            <span class="material-symbols-rounded">download</span> PDF
                        </button>
                    </div>
                    <div class="chart-container"><canvas id="inscripcionesChart"></canvas></div>
                </div>

                <?php if (!empty($conversion_programa)): ?>
                <div class="chart-card">
                    <div class="chart-header">
                        <div class="chart-header-left">
                            <h3><span class="material-symbols-rounded">conversion_path</span> Tasa de Conversión por Programa</h3>
                            <p>% de preinscripciones que se matricularon</p>
                        </div>
                    </div>
                    <div class="chart-container"><canvas id="conversionChart"></canvas></div>
                </div>
                <?php endif; ?>

                <div class="chart-card">
                    <div class="chart-header">
                        <div class="chart-header-left">
                            <h3><span class="material-symbols-rounded">donut_large</span> Estado General</h3>
                            <p>Distribución histórica de preinscripciones</p>
                        </div>
                    </div>
                    <div class="chart-container"><canvas id="estadosChart"></canvas></div>
                </div>

                <?php if (!empty($preinscr_municipio)): ?>
                <div class="chart-card">
                    <div class="chart-header">
                        <div class="chart-header-left">
                            <h3><span class="material-symbols-rounded">location_on</span> Procedencia Geográfica</h3>
                            <p>Solicitudes por municipio de residencia</p>
                        </div>
                    </div>
                    <div class="chart-container"><canvas id="municipioChart"></canvas></div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Tabla conversión por programa -->
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

        <!-- ══════════════════════════════════════════════════════════════════
             TAB 4: ACADÉMICO
        ══════════════════════════════════════════════════════════════════ -->
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
                            <p>Calificación promedio en cada curso</p>
                        </div>
                        <button class="btn btn-blue btn-sm" onclick="descargarReporte('academico')">
                            <span class="material-symbols-rounded">download</span> PDF
                        </button>
                    </div>
                    <div class="chart-container"><canvas id="promediosChart"></canvas></div>
                </div>
                <?php endif; ?>

                <div class="chart-card">
                    <div class="chart-header">
                        <div class="chart-header-left">
                            <h3><span class="material-symbols-rounded">analytics</span> Asistencia Global</h3>
                            <p>Distribución de estados de asistencia</p>
                        </div>
                    </div>
                    <div class="chart-container"><canvas id="asistenciaGlobalChart"></canvas></div>
                </div>

                <?php if (!empty($asistencia_grupos)): ?>
                <div class="chart-card full">
                    <div class="chart-header">
                        <div class="chart-header-left">
                            <h3><span class="material-symbols-rounded">event_available</span> % Asistencia por Grupo</h3>
                            <p>Porcentaje de presencia registrado por grupo</p>
                        </div>
                        <button class="btn btn-sm" style="background:#14b8a6;color:#fff" onclick="descargarReporte('asistencia')">
                            <span class="material-symbols-rounded">download</span> PDF
                        </button>
                    </div>
                    <div class="chart-container tall"><canvas id="asistenciaGruposChart"></canvas></div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Rendimiento estudiantes -->
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

            <!-- Certificados -->
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

            <!-- Bitácoras por profesor -->
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

        <!-- ══════════════════════════════════════════════════════════════════
             TAB 5: CURSOS
        ══════════════════════════════════════════════════════════════════ -->
        <div class="tab-content" id="tab-cursos">
            <div class="charts-grid">
                <?php if (!empty($estudiantes_por_curso)): ?>
                <div class="chart-card">
                    <div class="chart-header">
                        <div class="chart-header-left">
                            <h3><span class="material-symbols-rounded">groups</span> Estudiantes por Curso</h3>
                            <p>Matrículas activas por programa</p>
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
                            <p>Grupos por estado actual</p>
                        </div>
                    </div>
                    <div class="chart-container"><canvas id="estadoGruposChart"></canvas></div>
                </div>
            </div>

            <!-- Tabla grupos con ocupación -->
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

            <!-- Tabla cursos detalle -->
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

        <!-- ══════════════════════════════════════════════════════════════════
             TAB 6: ACTIVIDAD
        ══════════════════════════════════════════════════════════════════ -->
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
                            <p>Inicios de sesión por mes</p>
                        </div>
                    </div>
                    <div class="chart-container"><canvas id="accesosChart"></canvas></div>
                </div>
                <div class="chart-card">
                    <div class="chart-header">
                        <div class="chart-header-left">
                            <h3><span class="material-symbols-rounded">manage_accounts</span> Distribución de Roles</h3>
                            <p>Usuarios activos por tipo</p>
                        </div>
                    </div>
                    <div class="chart-container"><canvas id="rolesChart"></canvas></div>
                </div>
                <div class="chart-card">
                    <div class="chart-header">
                        <div class="chart-header-left">
                            <h3><span class="material-symbols-rounded">trending_up</span> Nuevos Estudiantes <?= $year_selected ?></h3>
                            <p>Registros de estudiantes por mes</p>
                        </div>
                    </div>
                    <div class="chart-container"><canvas id="nuevosChart"></canvas></div>
                </div>
            </div>

            <!-- Profesores detalle -->
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
// ── PHP → JS ──────────────────────────────────────────────────────────────
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

// Datos tablas para PDF
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

// ── Paleta ────────────────────────────────────────────────────────────────
const C = {
    blue:'#1479b0', green:'#4ec336', orange:'#ff6d00', yellow:'#e9e93e', red:'#ba2626',
    sBlue:'#1479b03a', sGreen:'#4ec33633', sOrange:'#ff6f003d', sYellow:'#e9e93e38', sRed:'#ba262646',
    teal:'#14b8a6', purple:'#8b5cf6', sTeal:'#14b8a620'
};
const GRID='#334155', TICK='#94a3b8', LEG='#f8fafc';

const baseOpts = (extra={}) => ({
    responsive:true, maintainAspectRatio:false,
    plugins:{ legend:{position:'bottom',labels:{color:LEG,padding:14,font:{size:11,family:'Poppins'}}} },
    scales:{
        y:{beginAtZero:true,ticks:{color:TICK},grid:{color:GRID}},
        x:{ticks:{color:TICK},grid:{color:GRID}}
    }, ...extra
});
const noScales = () => ({
    responsive:true, maintainAspectRatio:false,
    plugins:{legend:{position:'bottom',labels:{color:LEG,padding:12,font:{size:11,family:'Poppins'}}}}
});

function mkChart(id, cfg) {
    const el = document.getElementById(id);
    if (!el) return null;
    return new Chart(el.getContext('2d'), cfg);
}

// ── Gráficas ──────────────────────────────────────────────────────────────

// Financiero — ingresos por mes
mkChart('ingresosChart', {
    type:'bar',
    data:{
        labels:MESES,
        datasets:[
            {label:'Confirmados', data:INGRESOS_CONF, backgroundColor:C.green, borderRadius:5},
            {label:'Vencidos',    data:INGRESOS_VENC, backgroundColor:C.red,   borderRadius:5},
            {label:'Pendientes',  data:INGRESOS_PEND, backgroundColor:C.yellow,borderRadius:5}
        ]
    },
    options: baseOpts()
});

if (ING_CURSO_LABELS.length) {
    mkChart('ingresosCursoChart', {
        type:'bar',
        data:{
            labels:ING_CURSO_LABELS,
            datasets:[
                {label:'Pagado', data:ING_CURSO_PAG,  backgroundColor:C.green,  borderRadius:5},
                {label:'Vencido',data:ING_CURSO_VENC, backgroundColor:C.red,    borderRadius:5}
            ]
        },
        options:baseOpts()
    });
}

if (METODO_LABELS.length) {
    mkChart('metodosChart', {
        type:'doughnut',
        data:{
            labels:METODO_LABELS.map(m=>m.charAt(0).toUpperCase()+m.slice(1)),
            datasets:[{data:METODO_VALUES, backgroundColor:[C.green,C.blue,C.orange,C.teal], borderWidth:0}]
        },
        options:noScales()
    });
}

// Inscripciones
mkChart('inscripcionesChart', {
    type:'bar',
    data:{
        labels:MESES,
        datasets:[
            {label:'Preinscripciones', data:INSCR.preinscripciones, backgroundColor:C.blue,   borderRadius:5},
            {label:'Matrículas',       data:INSCR.matriculas,       backgroundColor:C.green,  borderRadius:5},
            {label:'Rechazadas',       data:INSCR.rechazadas,       backgroundColor:C.orange, borderRadius:5}
        ]
    },
    options:baseOpts()
});

if (CONV_LABELS.length) {
    mkChart('conversionChart', {
        type:'bar',
        data:{
            labels:CONV_LABELS,
            datasets:[{label:'Tasa de conversión (%)', data:CONV_TASAS, backgroundColor:CONV_TASAS.map(v=>v>=70?C.green:v>=40?C.blue:C.orange), borderRadius:5}]
        },
        options:{...baseOpts(), scales:{...baseOpts().scales, y:{beginAtZero:true,max:100,ticks:{color:TICK,callback:v=>v+'%'},grid:{color:GRID}}}}
    });
}

mkChart('estadosChart', {
    type:'doughnut',
    data:{
        labels:['Aprobadas','Pendientes','Contactadas','Rechazadas'],
        datasets:[{data:[ESTADOS_PREINSC.aprobadas,ESTADOS_PREINSC.pendientes,ESTADOS_PREINSC.contactadas,ESTADOS_PREINSC.rechazadas], backgroundColor:[C.green,C.yellow,C.blue,C.orange], borderWidth:0}]
    },
    options:noScales()
});

if (MUN_LABELS.length) {
    mkChart('municipioChart', {
        type:'pie',
        data:{
            labels:MUN_LABELS,
            datasets:[{data:MUN_VALUES, backgroundColor:[C.blue,C.green,C.orange,C.yellow,C.teal,C.purple], borderWidth:0}]
        },
        options:noScales()
    });
}

// Académico
if (CURSOS_NOMBRES.length) {
    mkChart('promediosChart', {
        type:'bar',
        data:{
            labels:CURSOS_NOMBRES,
            datasets:[{label:'Promedio', data:CURSOS_PROMEDIOS, backgroundColor:[C.blue,C.green,C.orange,C.yellow,C.purple,C.teal,C.red], borderRadius:6}]
        },
        options:{...baseOpts(), scales:{y:{beginAtZero:true,max:5,ticks:{color:TICK},grid:{color:GRID}},x:{ticks:{color:TICK},grid:{color:GRID}}}}
    });
}

mkChart('asistenciaGlobalChart', {
    type:'doughnut',
    data:{
        labels:['Presente','Ausente','Justificado','Tardanza'],
        datasets:[{data:[ASIST_GLOBAL.presentes,ASIST_GLOBAL.ausentes,ASIST_GLOBAL.justificados,ASIST_GLOBAL.tardanzas], backgroundColor:[C.green,C.red,C.blue,C.yellow], borderWidth:0}]
    },
    options:noScales()
});

if (GRUPOS_NOMBRES.length) {
    mkChart('asistenciaGruposChart', {
        type:'bar',
        data:{
            labels:GRUPOS_NOMBRES,
            datasets:[{label:'% Asistencia', data:GRUPOS_ASIST, backgroundColor:GRUPOS_ASIST.map(v=>v>=80?C.green:v>=60?C.blue:C.orange), borderRadius:5}]
        },
        options:{...baseOpts(), indexAxis:'y', scales:{x:{beginAtZero:true,max:100,ticks:{color:TICK,callback:v=>v+'%'},grid:{color:GRID}},y:{ticks:{color:TICK},grid:{color:GRID}}}}
    });
}

// Cursos
if (EPC_NOMBRES.length) {
    mkChart('estudCursoChart', {
        type:'bar',
        data:{labels:EPC_NOMBRES, datasets:[{label:'Estudiantes', data:EPC_VALORES, backgroundColor:C.blue, borderRadius:6}]},
        options:baseOpts()
    });
}
const eGL = Object.keys(ESTADO_GRUPOS).map(k=>k.charAt(0).toUpperCase()+k.slice(1));
const eGV = Object.values(ESTADO_GRUPOS);
mkChart('estadoGruposChart', {
    type:'doughnut',
    data:{labels:eGL.length?eGL:['Sin grupos'], datasets:[{data:eGV.length?eGV:[0], backgroundColor:[C.green,C.blue,C.orange,C.red], borderWidth:0}]},
    options:noScales()
});

// Actividad
mkChart('accesosChart', {
    type:'line',
    data:{labels:MESES, datasets:[{label:'Accesos', data:ACCESOS, borderColor:C.blue, backgroundColor:C.sBlue, fill:true, tension:0.4, borderWidth:2, pointRadius:4, pointBackgroundColor:C.blue}]},
    options:baseOpts()
});

const rL = Object.keys(ROLES).map(r=>r.charAt(0).toUpperCase()+r.slice(1));
const rV = Object.values(ROLES);
mkChart('rolesChart', {
    type:'doughnut',
    data:{labels:rL.length?rL:['Sin usuarios'], datasets:[{data:rV.length?rV:[0], backgroundColor:[C.orange,C.blue,C.green], borderWidth:0}]},
    options:noScales()
});

mkChart('nuevosChart', {
    type:'line',
    data:{labels:MESES, datasets:[{label:'Nuevos estudiantes', data:NUEVOS, borderColor:C.green, backgroundColor:C.sGreen, fill:true, tension:0.4, borderWidth:2, pointRadius:4, pointBackgroundColor:C.green}]},
    options:baseOpts()
});

// ── TABS ──────────────────────────────────────────────────────────────────
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(c=>c.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById(btn.dataset.tab).classList.add('active');
    });
});

function switchTab(id) {
    document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(c=>c.classList.remove('active'));
    const btn = document.querySelector(`[data-tab="${id}"]`);
    if (btn) btn.classList.add('active');
    const tab = document.getElementById(id);
    if (tab) tab.classList.add('active');
}

function changeYear(y) { window.location.href='?year='+y; }

// ── PDF HELPERS ───────────────────────────────────────────────────────────
function showOverlay(msg, sub) {
    document.getElementById('pdf-msg').textContent = msg || 'Generando reporte…';
    document.getElementById('pdf-sub').textContent = sub || 'Por favor espera';
    document.getElementById('pdf-overlay').classList.add('show');
}
function hideOverlay() { document.getElementById('pdf-overlay').classList.remove('show'); }

function pdfHeader(doc, titulo, color) {
    const W = doc.internal.pageSize.getWidth();
    doc.setFillColor(...color); doc.rect(0,0,W,20,'F');
    doc.setTextColor(255,255,255); doc.setFontSize(13); doc.setFont('helvetica','bold');
    doc.text(NOMBRE_ESCUELA, 14, 13);
    doc.setFontSize(9); doc.setFont('helvetica','normal');
    doc.text(titulo, W-14, 13, {align:'right'});
}

function pdfFooter(doc) {
    const W = doc.internal.pageSize.getWidth(), H = doc.internal.pageSize.getHeight();
    const n = doc.internal.getNumberOfPages();
    for (let p=1;p<=n;p++) {
        doc.setPage(p);
        doc.setDrawColor(51,65,85); doc.line(14,H-10,W-14,H-10);
        doc.setFontSize(7); doc.setTextColor(100,116,139); doc.setFont('helvetica','normal');
        doc.text(`Generado el ${new Date().toLocaleDateString('es-CO',{day:'2-digit',month:'long',year:'numeric'})} — ${NOMBRE_ESCUELA}`, 14, H-5);
        doc.text(`Página ${p} de ${n}`, W-14, H-5, {align:'right'});
    }
}

function kpiBox(doc, x, y, w, h, label, value, color) {
    doc.setFillColor(21,24,27); doc.roundedRect(x,y,w,h,3,3,'F');
    doc.setFillColor(...color); doc.roundedRect(x,y,3,h,1,1,'F');
    doc.setTextColor(148,163,184); doc.setFontSize(7); doc.setFont('helvetica','normal');
    doc.text(label, x+7, y+8);
    doc.setTextColor(248,250,252); doc.setFontSize(12); doc.setFont('helvetica','bold');
    doc.text(String(value), x+7, y+18);
}

function fmt(n) { return '$'+Number(n).toLocaleString('es-CO'); }

// ── REPORTES ESPECÍFICOS ──────────────────────────────────────────────────
async function descargarReporte(tipo) {
    showOverlay('Preparando reporte de ' + tipo + '…', 'Esto puede tomar unos segundos');
    await new Promise(r=>setTimeout(r,200));
    try {
        const { jsPDF } = window.jspdf;

        if (tipo === 'financiero') await reporteFinanciero(jsPDF);
        else if (tipo === 'academico') await reporteAcademico(jsPDF);
        else if (tipo === 'inscripciones') await reporteInscripciones(jsPDF);
        else if (tipo === 'cartera') await reporteCartera(jsPDF);
        else if (tipo === 'asistencia') await reporteAsistencia(jsPDF);
        else if (tipo === 'cursos') await reporteCursos(jsPDF);
    } catch(e) {
        console.error(e);
        alert('Error generando el reporte. Ver consola para detalles.');
    } finally {
        hideOverlay();
    }
}

// ─── REPORTE FINANCIERO ──────────────────────────────────────────────────
async function reporteFinanciero(jsPDF) {
    const doc = new jsPDF({orientation:'portrait',unit:'mm',format:'a4'});
    const W = doc.internal.pageSize.getWidth(), H = doc.internal.pageSize.getHeight();
    pdfHeader(doc, `Reporte Financiero — ${YEAR}`, [20,121,176]);

    // Portada KPIs
    const tasa = FINANCIERO.facturado > 0 ? Math.round(FINANCIERO.confirmados*100/FINANCIERO.facturado) : 0;
    doc.setFillColor(4,12,19); doc.rect(0,20,W,H-20,'F');

    const kpis = [
        {l:'Ingresos Confirmados', v:fmt(FINANCIERO.confirmados), c:[78,195,54]},
        {l:'Cartera Vencida',      v:fmt(FINANCIERO.vencidos),    c:[186,38,38]},
        {l:'Pendientes',           v:fmt(FINANCIERO.pendientes),  c:[233,233,62]},
        {l:'Total Facturado',      v:fmt(FINANCIERO.facturado),   c:[20,121,176]},
        {l:'Tasa de cobro',        v:tasa+'%',                    c:[139,92,246]},
        {l:'Pagos recibidos',      v:FINANCIERO.pagos_ok,         c:[20,184,166]},
    ];
    const bw = (W-28-10)/3, bh = 28;
    kpis.forEach((k,i) => {
        const col=i%3, row=Math.floor(i/3);
        kpiBox(doc, 14+col*(bw+5), 26+row*(bh+6), bw, bh, k.l, k.v, k.c);
    });

    // Gráfico canvas ingresos
    const canvas = document.getElementById('ingresosChart');
    if (canvas) {
        const img = canvas.toDataURL('image/png');
        doc.addImage(img,'PNG',14,98,W-28,65);
    }

    // Sección ingresos por curso
    if (ING_CURSO_LABELS.length) {
        doc.addPage();
        doc.setFillColor(4,12,19); doc.rect(0,0,W,H,'F');
        pdfHeader(doc, `Ingresos por Curso — ${YEAR}`, [20,121,176]);
        doc.autoTable({
            head:[['Curso','Pagado','Vencido','Total cobros','Cobros OK']],
            body: <?= json_encode(array_map(fn($r)=>[$r['curso'],'$'.number_format((float)$r['pagado'],0,',','.'),'$'.number_format((float)$r['vencido'],0,',','.'),$r['total_cobros'],$r['cobros_ok']], $ingresos_curso)) ?>,
            startY:28, styles:{fontSize:8,cellPadding:4,textColor:[248,250,252],fillColor:[21,24,27],lineColor:[51,65,85],lineWidth:0.3},
            headStyles:{fillColor:[20,121,176],textColor:255,fontStyle:'bold'},
            alternateRowStyles:{fillColor:[24,29,35]},
            margin:{left:14,right:14}
        });
    }

    // Deudores
    if (DEUDORES.length) {
        doc.addPage();
        doc.setFillColor(4,12,19); doc.rect(0,0,W,H,'F');
        pdfHeader(doc, `Estudiantes con Deuda — ${YEAR}`, [186,38,38]);
        doc.setFillColor(186,38,38); doc.setFillColor(186,38,38,0.1);
        doc.autoTable({
            head:[['Estudiante','Email','Pagos vencidos','Deuda total','Vencimiento antiguo']],
            body: DEUDORES.map(d=>[d.estudiante, d.email, d.pagos_vencidos+' pago(s)', fmt(d.deuda_total), d.vencimiento_mas_antiguo||'—']),
            startY:28, styles:{fontSize:8,cellPadding:4,textColor:[248,250,252],fillColor:[21,24,27],lineColor:[51,65,85],lineWidth:0.3},
            headStyles:{fillColor:[186,38,38],textColor:255,fontStyle:'bold'},
            alternateRowStyles:{fillColor:[24,29,35]},
            margin:{left:14,right:14}
        });
    }

    pdfFooter(doc);
    doc.save(`Reporte_Financiero_Amimbre_${YEAR}.pdf`);
}

// ─── REPORTE ACADÉMICO ───────────────────────────────────────────────────
async function reporteAcademico(jsPDF) {
    const doc = new jsPDF({orientation:'portrait',unit:'mm',format:'a4'});
    const W = doc.internal.pageSize.getWidth(), H = doc.internal.pageSize.getHeight();
    pdfHeader(doc, `Rendimiento Académico`, [20,121,176]);
    doc.setFillColor(4,12,19); doc.rect(0,20,W,H-20,'F');

    // Gráfico promedios
    const c1 = document.getElementById('promediosChart');
    if (c1) { const img=c1.toDataURL('image/png'); doc.addImage(img,'PNG',14,26,W-28,60); }

    // Gráfico asistencia global
    const c2 = document.getElementById('asistenciaGlobalChart');
    if (c2) { const img=c2.toDataURL('image/png'); doc.addImage(img,'PNG',14,93,80,60); }

    // Tabla promedios por curso
    if (CURSOS_NOMBRES.length) {
        doc.addPage();
        doc.setFillColor(4,12,19); doc.rect(0,0,W,H,'F');
        pdfHeader(doc, 'Promedios por Curso', [20,121,176]);
        const rows = <?= json_encode(array_map(fn($r)=>[$r['curso'],round((float)$r['promedio'],2).'/5.0',$r['total_evaluaciones'],round((float)$r['minimo'],2),round((float)$r['maximo'],2)], $promedios_cursos)) ?>;
        doc.autoTable({
            head:[['Curso','Promedio','Evaluaciones','Mínimo','Máximo']],
            body: rows,
            startY:28, styles:{fontSize:8,cellPadding:4,textColor:[248,250,252],fillColor:[21,24,27],lineColor:[51,65,85],lineWidth:0.3},
            headStyles:{fillColor:[20,121,176],textColor:255,fontStyle:'bold'},
            alternateRowStyles:{fillColor:[24,29,35]},
            margin:{left:14,right:14}
        });
    }

    // Tabla rendimiento estudiantes
    if (REND_ESTUDIANTES.length) {
        doc.addPage();
        doc.setFillColor(4,12,19); doc.rect(0,0,W,H,'F');
        pdfHeader(doc, 'Rendimiento Individual', [78,195,54]);
        doc.autoTable({
            head:[['Estudiante','Curso','Promedio','Evaluaciones','% Asistencia','Estado']],
            body: REND_ESTUDIANTES.map(r=>{
                const p=Number(r.promedio).toFixed(2);
                const aP=r.total_clases>0?Math.round(r.presencias*100/r.total_clases):0;
                return [r.estudiante, r.curso, p+'/5.0', r.evaluaciones, aP+'%', Number(p)>=3?'Aprobando':'En riesgo'];
            }),
            startY:28, styles:{fontSize:8,cellPadding:4,textColor:[248,250,252],fillColor:[21,24,27],lineColor:[51,65,85],lineWidth:0.3},
            headStyles:{fillColor:[78,195,54],textColor:[4,12,19],fontStyle:'bold'},
            alternateRowStyles:{fillColor:[24,29,35]},
            margin:{left:14,right:14}
        });
    }

    pdfFooter(doc);
    doc.save(`Reporte_Academico_Amimbre.pdf`);
}

// ─── REPORTE INSCRIPCIONES ───────────────────────────────────────────────
async function reporteInscripciones(jsPDF) {
    const doc = new jsPDF({orientation:'portrait',unit:'mm',format:'a4'});
    const W = doc.internal.pageSize.getWidth(), H = doc.internal.pageSize.getHeight();
    pdfHeader(doc, `Inscripciones y Matrículas — ${YEAR}`, [255,109,0]);
    doc.setFillColor(4,12,19); doc.rect(0,20,W,H-20,'F');

    const estados = [
        {l:'Aprobadas',      v:ESTADOS_PREINSC.aprobadas,  c:[78,195,54]},
        {l:'Pendientes',     v:ESTADOS_PREINSC.pendientes,  c:[233,233,62]},
        {l:'Contactadas',    v:ESTADOS_PREINSC.contactadas, c:[20,121,176]},
        {l:'Rechazadas',     v:ESTADOS_PREINSC.rechazadas,  c:[186,38,38]},
    ];
    const bw=(W-28-9)/4, bh=24;
    estados.forEach((k,i)=>kpiBox(doc,14+i*(bw+3),26,bw,bh,k.l,k.v,k.c));

    const c1=document.getElementById('inscripcionesChart');
    if(c1){const img=c1.toDataURL('image/png'); doc.addImage(img,'PNG',14,56,W-28,65);}

    if (CONV_PROGRAMA.length) {
        doc.addPage();
        doc.setFillColor(4,12,19); doc.rect(0,0,W,H,'F');
        pdfHeader(doc,'Conversión por Programa',[255,109,0]);
        doc.autoTable({
            head:[['Programa','Solicitudes','Convertidas','Tasa de conversión']],
            body: CONV_PROGRAMA.map(r=>[r.programa,r.solicitudes,r.convertidas,r.tasa+'%']),
            startY:28, styles:{fontSize:8,cellPadding:4,textColor:[248,250,252],fillColor:[21,24,27],lineColor:[51,65,85],lineWidth:0.3},
            headStyles:{fillColor:[255,109,0],textColor:255,fontStyle:'bold'},
            alternateRowStyles:{fillColor:[24,29,35]},
            margin:{left:14,right:14}
        });
    }

    pdfFooter(doc);
    doc.save(`Reporte_Inscripciones_Amimbre_${YEAR}.pdf`);
}

// ─── REPORTE CARTERA VENCIDA ─────────────────────────────────────────────
async function reporteCartera(jsPDF) {
    const doc = new jsPDF({orientation:'portrait',unit:'mm',format:'a4'});
    const W = doc.internal.pageSize.getWidth(), H = doc.internal.pageSize.getHeight();
    pdfHeader(doc,'Reporte de Cartera Vencida',[186,38,38]);
    doc.setFillColor(4,12,19); doc.rect(0,20,W,H-20,'F');

    doc.setTextColor(148,163,184); doc.setFontSize(9); doc.setFont('helvetica','normal');
    doc.text(`Generado el ${new Date().toLocaleDateString('es-CO',{day:'2-digit',month:'long',year:'numeric'})}`, 14, 30);

    const totalDeuda = DEUDORES.reduce((s,d)=>s+Number(d.deuda_total),0);
    kpiBox(doc,14,36,(W-28)/2-3,24,'Total cartera vencida',fmt(totalDeuda),[186,38,38]);
    kpiBox(doc,14+(W-28)/2+3,36,(W-28)/2-3,24,'Estudiantes en mora',DEUDORES.length+' estudiante(s)',[255,109,0]);

    if (DEUDORES.length) {
        doc.autoTable({
            head:[['#','Estudiante','Email','Pagos vencidos','Deuda total','Vencimiento más antiguo']],
            body: DEUDORES.map((d,i)=>[i+1,d.estudiante,d.email,d.pagos_vencidos+' pago(s)',fmt(d.deuda_total),d.vencimiento_mas_antiguo||'—']),
            startY:68, styles:{fontSize:8,cellPadding:4,textColor:[248,250,252],fillColor:[21,24,27],lineColor:[51,65,85],lineWidth:0.3},
            headStyles:{fillColor:[186,38,38],textColor:255,fontStyle:'bold'},
            alternateRowStyles:{fillColor:[24,29,35]},
            margin:{left:14,right:14}
        });
    } else {
        doc.setTextColor(78,195,54); doc.setFontSize(12); doc.setFont('helvetica','bold');
        doc.text('¡No hay cartera vencida registrada!', W/2, 90, {align:'center'});
    }

    pdfFooter(doc);
    doc.save(`Cartera_Vencida_Amimbre.pdf`);
}

// ─── REPORTE ASISTENCIA ──────────────────────────────────────────────────
async function reporteAsistencia(jsPDF) {
    const doc = new jsPDF({orientation:'portrait',unit:'mm',format:'a4'});
    const W = doc.internal.pageSize.getWidth(), H = doc.internal.pageSize.getHeight();
    pdfHeader(doc,'Control de Asistencia',[20,184,166]);
    doc.setFillColor(4,12,19); doc.rect(0,20,W,H-20,'F');

    const asistData = <?= json_encode($asistencia_global) ?>;
    const total = asistData.total || 1;
    const kpis=[
        {l:'Presentes',   v:asistData.presentes+' ('+Math.round(asistData.presentes*100/total)+'%)', c:[78,195,54]},
        {l:'Ausentes',    v:asistData.ausentes+' ('+Math.round(asistData.ausentes*100/total)+'%)',   c:[186,38,38]},
        {l:'Justificados',v:asistData.justificados,                                                   c:[20,121,176]},
        {l:'Tardanzas',   v:asistData.tardanzas,                                                      c:[233,233,62]},
    ];
    const bw=(W-28-9)/4, bh=24;
    kpis.forEach((k,i)=>kpiBox(doc,14+i*(bw+3),26,bw,bh,k.l,k.v,k.c));

    const c1=document.getElementById('asistenciaGruposChart');
    if(c1){const img=c1.toDataURL('image/png'); doc.addImage(img,'PNG',14,56,W-28,70);}

    if(ASIST_GRUPOS_TBL.length){
        doc.addPage();
        doc.setFillColor(4,12,19); doc.rect(0,0,W,H,'F');
        pdfHeader(doc,'Asistencia por Grupo',[20,184,166]);
        doc.autoTable({
            head:[['Grupo','Curso','Profesor','Presentes','Total clases','% Asistencia']],
            body: ASIST_GRUPOS_TBL.map(r=>[r.grupo,r.curso,r.profesor||'—',r.presentes,r.total,r.porcentaje+'%']),
            startY:28, styles:{fontSize:8,cellPadding:4,textColor:[248,250,252],fillColor:[21,24,27],lineColor:[51,65,85],lineWidth:0.3},
            headStyles:{fillColor:[20,184,166],textColor:[4,12,19],fontStyle:'bold'},
            alternateRowStyles:{fillColor:[24,29,35]},
            margin:{left:14,right:14}
        });
    }

    pdfFooter(doc);
    doc.save(`Control_Asistencia_Amimbre.pdf`);
}

// ─── REPORTE CURSOS ──────────────────────────────────────────────────────
async function reporteCursos(jsPDF) {
    const doc = new jsPDF({orientation:'portrait',unit:'mm',format:'a4'});
    const W = doc.internal.pageSize.getWidth(), H = doc.internal.pageSize.getHeight();
    pdfHeader(doc,'Cursos y Ocupación de Grupos',[233,233,62]);
    doc.setFillColor(4,12,19); doc.rect(0,20,W,H-20,'F');

    const c1=document.getElementById('estudCursoChart');
    if(c1){const img=c1.toDataURL('image/png'); doc.addImage(img,'PNG',14,26,W-28,65);}

    if(CURSOS_DETALLE.length){
        doc.addPage();
        doc.setFillColor(4,12,19); doc.rect(0,0,W,H,'F');
        pdfHeader(doc,'Detalle de Cursos',[233,233,62]);
        doc.autoTable({
            head:[['Curso','Nivel','Estado','Grupos','Estudiantes','Precio/mes','Ingreso proyectado']],
            body: CURSOS_DETALLE.map(r=>[r.curso,r.nivel,r.estado_curso,r.grupos,r.estudiantes,fmt(r.precio_mensual),fmt(r.precio_mensual*r.estudiantes)]),
            startY:28, styles:{fontSize:7.5,cellPadding:4,textColor:[248,250,252],fillColor:[21,24,27],lineColor:[51,65,85],lineWidth:0.3},
            headStyles:{fillColor:[160,160,0],textColor:[4,12,19],fontStyle:'bold'},
            alternateRowStyles:{fillColor:[24,29,35]},
            margin:{left:14,right:14}
        });
    }

    if(OCUPACION_GRUPOS.length){
        doc.addPage();
        doc.setFillColor(4,12,19); doc.rect(0,0,W,H,'F');
        pdfHeader(doc,'Ocupación de Grupos Activos',[233,233,62]);
        doc.autoTable({
            head:[['Grupo','Curso','Profesor','Cupo actual','Cupo máximo','Bitácoras','Ocupación']],
            body: OCUPACION_GRUPOS.map(r=>[r.nombre,r.curso,r.profesor||'—',r.cupo_actual,r.cupo_maximo,r.total_bitacoras,r.ocupacion_pct+'%']),
            startY:28, styles:{fontSize:8,cellPadding:4,textColor:[248,250,252],fillColor:[21,24,27],lineColor:[51,65,85],lineWidth:0.3},
            headStyles:{fillColor:[160,160,0],textColor:[4,12,19],fontStyle:'bold'},
            alternateRowStyles:{fillColor:[24,29,35]},
            margin:{left:14,right:14}
        });
    }

    pdfFooter(doc);
    doc.save(`Reporte_Cursos_Amimbre.pdf`);
}

// ─── PDF GENERAL ────────────────────────────────────────────────────────
async function exportarPDFGeneral() {
    showOverlay('Generando PDF general…','Capturando todas las secciones');
    await new Promise(r=>setTimeout(r,200));
    try {
        const {jsPDF} = window.jspdf;
        const doc = new jsPDF({orientation:'landscape',unit:'mm',format:'a4'});
        const W=doc.internal.pageSize.getWidth(), H=doc.internal.pageSize.getHeight();

        // Portada
        doc.setFillColor(4,12,19); doc.rect(0,0,W,H,'F');
        pdfHeader(doc,`Reporte General — ${YEAR}`,[20,121,176]);
        const kpisG=[
            {l:'Estudiantes activos',    v:<?= $total_estudiantes ?>,                                         c:[20,121,176]},
            {l:'Ingresos confirmados',   v:fmt(<?= (float)($financiero_anio['ingresos_confirmados']??0) ?>),  c:[78,195,54]},
            {l:'Cartera vencida',        v:fmt(<?= (float)($financiero_anio['ingresos_vencidos']??0) ?>),     c:[186,38,38]},
            {l:'Matrículas activas',     v:<?= $matriculas_activas ?>,                                        c:[255,109,0]},
            {l:'Grupos activos',         v:<?= $grupos_activos ?>,                                            c:[139,92,246]},
            {l:'Preinscripciones pend.', v:<?= $preinscripciones_pendientes ?>,                               c:[233,233,62]},
        ];
        const bw=(W-28-10)/3, bh=28;
        kpisG.forEach((k,i)=>kpiBox(doc,14+(i%3)*(bw+5),26+Math.floor(i/3)*(bh+6),bw,bh,k.l,k.v,k.c));

        // Tabs a capturar
        const tabs=[
            {id:'tab-inscripciones',titulo:'Inscripciones',  color:[255,109,0]},
            {id:'tab-financiero',   titulo:'Financiero',     color:[20,121,176]},
            {id:'tab-academico',    titulo:'Académico',      color:[78,195,54]},
            {id:'tab-cursos',       titulo:'Cursos',         color:[233,233,62]},
            {id:'tab-actividad',    titulo:'Actividad',      color:[139,92,246]},
        ];

        for (const t of tabs) {
            document.querySelectorAll('.tab-content').forEach(c=>c.classList.remove('active'));
            document.getElementById(t.id).classList.add('active');
            await new Promise(r=>setTimeout(r,350));
            const el = document.getElementById(t.id);
            const canvas = await html2canvas(el,{scale:1.2,useCORS:true,backgroundColor:'#15181b',logging:false});
            const img = canvas.toDataURL('image/jpeg',0.82);
            doc.addPage();
            doc.setFillColor(4,12,19); doc.rect(0,0,W,H,'F');
            doc.setFillColor(...t.color); doc.rect(0,0,W,14,'F');
            doc.setTextColor(255,255,255); doc.setFontSize(11); doc.setFont('helvetica','bold');
            doc.text(t.titulo, 14, 9);
            doc.addImage(img,'JPEG',14,17,W-28,H-22);
        }

        // Restaurar tab activo
        document.querySelectorAll('.tab-content').forEach(c=>c.classList.remove('active'));
        document.querySelector('.tab-btn').click();

        pdfFooter(doc);
        doc.save(`Reporte_General_Amimbre_${YEAR}.pdf`);
    } catch(e) {
        console.error(e);
        alert('Error al generar el PDF general.');
    } finally {
        hideOverlay();
    }
}
</script>
</body>
</html>