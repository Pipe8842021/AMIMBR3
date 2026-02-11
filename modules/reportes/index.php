<?php
/**
 * Reportes - Análisis y estadísticas del sistema
 * Vista completa de reportes académicos, financieros e inscripciones
 */

// Incluir configuración de sesión y base de datos
require_once '../../config/session.php';
require_once '../../config/database.php';

// Verificar autenticación
require_once '../../includes/auth_check.php';

// Verificar que sea administrador
require_role('admin');

// Obtener datos del usuario actual
try {
    $stmt = $pdo->prepare("
        SELECT id, nombre, email, rol, estado, foto_perfil 
        FROM usuarios 
        WHERE id = ? AND estado = 'activo'
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        session_destroy();
        header("Location: ../../auth/login.php?error=usuario_no_encontrado");
        exit;
    }
} catch (PDOException $e) {
    error_log("Error al obtener datos de usuario: " . $e->getMessage());
    die("Error del sistema. Por favor, intenta más tarde.");
}

// Obtener año seleccionado (por defecto el año actual)
$year_selected = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

// Obtener estadísticas principales
try {
    // 1. Total de estudiantes
    $stmt = $pdo->query("
        SELECT COUNT(*) as total 
        FROM usuarios 
        WHERE rol = 'estudiante' AND estado = 'activo'
    ");
    $total_estudiantes = $stmt->fetch()['total'] ?? 0;
    
    // Estudiantes del año anterior
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total 
        FROM usuarios 
        WHERE rol = 'estudiante' 
        AND estado = 'activo'
        AND YEAR(fecha_registro) < ?
    ");
    $stmt->execute([$year_selected]);
    $estudiantes_anterior = $stmt->fetch()['total'] ?? 0;
    
    // Calcular cambio porcentual
    $cambio_estudiantes = $estudiantes_anterior > 0 
        ? round((($total_estudiantes - $estudiantes_anterior) / $estudiantes_anterior) * 100) 
        : 0;
    
    // 2. Matrículas activas
    $stmt = $pdo->query("
        SELECT COUNT(*) as total 
        FROM matriculas 
        WHERE estado = 'activa'
    ");
    $matriculas_activas = $stmt->fetch()['total'] ?? 0;
    
    // Matrículas del mes anterior
    $stmt = $pdo->query("
        SELECT COUNT(*) as total 
        FROM matriculas 
        WHERE estado = 'activa'
        AND fecha_matricula < DATE_SUB(NOW(), INTERVAL 1 MONTH)
    ");
    $matriculas_anterior = $stmt->fetch()['total'] ?? 0;
    
    $cambio_matriculas = $matriculas_anterior > 0 
        ? round((($matriculas_activas - $matriculas_anterior) / $matriculas_anterior) * 100) 
        : 0;
    
    // 3. Ingresos totales (simulado - ajustar según tus datos reales)
    $stmt = $pdo->query("
        SELECT SUM(monto) as total 
        FROM pagos 
        WHERE estado = 'pagado'
        AND YEAR(fecha_pago) = YEAR(CURDATE())
    ");
    $ingresos_totales = $stmt->fetch()['total'] ?? 0;
    
    // Ingresos del mes anterior
    $stmt = $pdo->query("
        SELECT SUM(monto) as total 
        FROM pagos 
        WHERE estado = 'pagado'
        AND fecha_pago < DATE_SUB(NOW(), INTERVAL 1 MONTH)
    ");
    $ingresos_anterior = $stmt->fetch()['total'] ?? 0;
    
    $cambio_ingresos = $ingresos_anterior > 0 
        ? round((($ingresos_totales - $ingresos_anterior) / $ingresos_anterior) * 100) 
        : 0;
    
    // 4. Preinscripciones pendientes
    $stmt = $pdo->query("
        SELECT COUNT(*) as total 
        FROM preinscripciones 
        WHERE estado = 'pendiente'
    ");
    $preinscripciones_pendientes = $stmt->fetch()['total'] ?? 0;
    
    // 5. Datos para gráfico de Inscripciones por Mes
    $stmt = $pdo->prepare("
        SELECT 
            MONTH(fecha_preinscripcion) as mes,
            COUNT(CASE WHEN estado = 'pendiente' THEN 1 END) as preinscripciones,
            COUNT(CASE WHEN estado = 'matriculado' THEN 1 END) as matriculas,
            COUNT(CASE WHEN estado = 'rechazado' THEN 1 END) as rechazadas
        FROM preinscripciones
        WHERE YEAR(fecha_preinscripcion) = ?
        GROUP BY MONTH(fecha_preinscripcion)
        ORDER BY mes
    ");
    $stmt->execute([$year_selected]);
    $inscripciones_mes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 6. Estados de preinscripciones
    $stmt = $pdo->query("
        SELECT 
            COUNT(CASE WHEN estado = 'pendiente' THEN 1 END) as pendientes,
            COUNT(CASE WHEN estado = 'matriculado' THEN 1 END) as aprobadas,
            COUNT(CASE WHEN estado = 'rechazado' THEN 1 END) as rechazadas
        FROM preinscripciones
    ");
    $estados_preinscripciones = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 7. Datos para gráfico de tendencia de crecimiento
    $stmt = $pdo->prepare("
        SELECT 
            DATE_FORMAT(fecha_registro, '%Y-%m') as mes,
            COUNT(*) as total
        FROM usuarios
        WHERE rol = 'estudiante' 
        AND estado = 'activo'
        AND YEAR(fecha_registro) = ?
        GROUP BY DATE_FORMAT(fecha_registro, '%Y-%m')
        ORDER BY mes
    ");
    $stmt->execute([$year_selected]);
    $tendencia_crecimiento = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error obteniendo estadísticas de reportes: " . $e->getMessage());
    // Valores por defecto en caso de error
    $total_estudiantes = 0;
    $cambio_estudiantes = 0;
    $matriculas_activas = 0;
    $cambio_matriculas = 0;
    $ingresos_totales = 0;
    $cambio_ingresos = 0;
    $preinscripciones_pendientes = 0;
    $inscripciones_mes = [];
    $estados_preinscripciones = ['pendientes' => 0, 'aprobadas' => 0, 'rechazadas' => 0];
    $tendencia_crecimiento = [];
}

// Preparar datos para JavaScript
$meses_labels = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
$inscripciones_data = [
    'preinscripciones' => array_fill(0, 12, 0),
    'matriculas' => array_fill(0, 12, 0),
    'rechazadas' => array_fill(0, 12, 0)
];

foreach ($inscripciones_mes as $data) {
    $mes_index = (int)$data['mes'] - 1;
    $inscripciones_data['preinscripciones'][$mes_index] = (int)$data['preinscripciones'];
    $inscripciones_data['matriculas'][$mes_index] = (int)$data['matriculas'];
    $inscripciones_data['rechazadas'][$mes_index] = (int)$data['rechazadas'];
}

// Preparar datos de tendencia de crecimiento
$tendencia_labels = [];
$tendencia_values = [];
$acumulado = 0;

foreach ($tendencia_crecimiento as $data) {
    $fecha = new DateTime($data['mes'] . '-01');
    $tendencia_labels[] = $fecha->format('M');
    $acumulado += (int)$data['total'];
    $tendencia_values[] = $acumulado;
}

// Completar meses faltantes para el gráfico
if (count($tendencia_labels) < 12) {
    for ($i = count($tendencia_labels); $i < 12; $i++) {
        $tendencia_labels[] = $meses_labels[$i];
        $tendencia_values[] = end($tendencia_values) ?: 0;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reportes - Amimbré</title>
    <link rel="shortcut icon" href="../../assets/img/3.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0" />
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="../../assets/css/colores.css">
    <link rel="stylesheet" href="../../assets/css/style-reportes.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body>
    <!-- Include Header/Sidebar -->
    <?php 
    if (file_exists('../../includes/header.php')) {
        require_once '../../includes/header.php'; 
    }
    ?>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Header -->
        <div class="reportes-header">
            <div class="header-left">
                <h1>Reportes</h1>
                <p>Análisis y estadísticas del sistema</p>
            </div>
            <div class="header-right">
                <select class="year-select" id="yearSelect" onchange="changeYear(this.value)">
                    <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                        <option value="<?php echo $y; ?>" <?php echo $y == $year_selected ? 'selected' : ''; ?>>
                            <?php echo $y; ?>
                        </option>
                    <?php endfor; ?>
                </select>
                <button class="btn-print" onclick="window.print()">
                    <span class="material-symbols-rounded">print</span>
                    Imprimir
                </button>
                <button class="btn-export" onclick="exportPDF()">
                    <span class="material-symbols-rounded">download</span>
                    Exportar PDF
                </button>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card blue">
                <div class="stat-header">
                    <span class="stat-title">Total Estudiantes</span>
                    <div class="stat-icon">
                        <span class="material-symbols-rounded">school</span>
                    </div>
                </div>
                <div class="stat-value"><?php echo number_format($total_estudiantes); ?></div>
                <div class="stat-change <?php echo $cambio_estudiantes >= 0 ? 'positive' : 'negative'; ?>">
                    <span class="material-symbols-rounded">
                        <?php echo $cambio_estudiantes >= 0 ? 'arrow_upward' : 'arrow_downward'; ?>
                    </span>
                    <?php echo abs($cambio_estudiantes); ?>% vs mes anterior
                </div>
            </div>

            <div class="stat-card green">
                <div class="stat-header">
                    <span class="stat-title">Matrículas Activas</span>
                    <div class="stat-icon">
                        <span class="material-symbols-rounded">how_to_reg</span>
                    </div>
                </div>
                <div class="stat-value"><?php echo number_format($matriculas_activas); ?></div>
                <div class="stat-change <?php echo $cambio_matriculas >= 0 ? 'positive' : 'negative'; ?>">
                    <span class="material-symbols-rounded">
                        <?php echo $cambio_matriculas >= 0 ? 'arrow_upward' : 'arrow_downward'; ?>
                    </span>
                    <?php echo abs($cambio_matriculas); ?>% vs mes anterior
                </div>
            </div>

            <div class="stat-card orange">
                <div class="stat-header">
                    <span class="stat-title">Ingresos Totales</span>
                    <div class="stat-icon">
                        <span class="material-symbols-rounded">attach_money</span>
                    </div>
                </div>
                <div class="stat-value">$<?php echo number_format($ingresos_totales, 0); ?></div>
                <div class="stat-change <?php echo $cambio_ingresos >= 0 ? 'positive' : 'negative'; ?>">
                    <span class="material-symbols-rounded">
                        <?php echo $cambio_ingresos >= 0 ? 'arrow_upward' : 'arrow_downward'; ?>
                    </span>
                    <?php echo abs($cambio_ingresos); ?>% vs mes anterior
                </div>
            </div>

            <div class="stat-card yellow">
                <div class="stat-header">
                    <span class="stat-title">Preinscripciones Pendientes</span>
                    <div class="stat-icon">
                        <span class="material-symbols-rounded">pending_actions</span>
                    </div>
                </div>
                <div class="stat-value"><?php echo number_format($preinscripciones_pendientes); ?></div>
                <div class="stat-change">
                    <span>Por revisar</span>
                </div>
            </div>
        </div>

        <!-- Tabs Navigation -->
        <div class="tabs-container">
            <div class="tabs-nav">
                <button class="tab-btn active" data-tab="inscripciones">
                    <span class="material-symbols-rounded">description</span>
                    Inscripciones
                </button>
                <button class="tab-btn" data-tab="finanzas">
                    <span class="material-symbols-rounded">payments</span>
                    Finanzas
                </button>
                <button class="tab-btn" data-tab="academico">
                    <span class="material-symbols-rounded">school</span>
                    Académico
                </button>
                <button class="tab-btn" data-tab="cursos">
                    <span class="material-symbols-rounded">menu_book</span>
                    Cursos
                </button>
            </div>

            <!-- Tab Content: Inscripciones -->
            <div class="tab-content active" id="inscripciones">
                <div class="charts-grid">
                    <div class="chart-card large">
                        <div class="chart-header">
                            <div>
                                <h3><span class="material-symbols-rounded">bar_chart</span> Inscripciones por Mes</h3>
                                <p>Comparativa de preinscripciones, matrículas y rechazadas</p>
                            </div>
                        </div>
                        <div class="chart-container">
                            <canvas id="inscripcionesChart"></canvas>
                        </div>
                    </div>

                    <div class="chart-card">
                        <div class="chart-header">
                            <div>
                                <h3><span class="material-symbols-rounded">trending_up</span> Tendencia de Crecimiento</h3>
                                <p>Evolución del número de estudiantes activos</p>
                            </div>
                        </div>
                        <div class="chart-container">
                            <canvas id="tendenciaChart"></canvas>
                        </div>
                    </div>
                </div>

                <div class="stats-cards-grid">
                    <div class="status-card success">
                        <div class="status-icon">
                            <span class="material-symbols-rounded">check_circle</span>
                        </div>
                        <div class="status-content">
                            <div class="status-value"><?php echo $estados_preinscripciones['aprobadas']; ?></div>
                            <div class="status-label">Aprobadas</div>
                            <div class="status-desc">0% del total</div>
                        </div>
                    </div>

                    <div class="status-card warning">
                        <div class="status-icon">
                            <span class="material-symbols-rounded">schedule</span>
                        </div>
                        <div class="status-content">
                            <div class="status-value"><?php echo $estados_preinscripciones['pendientes']; ?></div>
                            <div class="status-label">Pendientes</div>
                            <div class="status-desc">Por procesar</div>
                        </div>
                    </div>

                    <div class="status-card danger">
                        <div class="status-icon">
                            <span class="material-symbols-rounded">cancel</span>
                        </div>
                        <div class="status-content">
                            <div class="status-value"><?php echo $estados_preinscripciones['rechazadas']; ?></div>
                            <div class="status-label">Rechazadas</div>
                            <div class="status-desc">0% del total</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab Content: Finanzas -->
            <div class="tab-content" id="finanzas">
                <div class="charts-grid">
                    <div class="chart-card">
                        <div class="chart-header">
                            <div>
                                <h3><span class="material-symbols-rounded">pie_chart</span> Ingresos por Curso</h3>
                                <p>Distribución de ingresos por tipo de curso</p>
                            </div>
                        </div>
                        <div class="chart-container">
                            <canvas id="ingresosChart"></canvas>
                        </div>
                    </div>

                    <div class="chart-card">
                        <div class="chart-header">
                            <div>
                                <h3><span class="material-symbols-rounded">account_balance</span> Estado de Pagos</h3>
                                <p>Seguimiento de pagos pendientes y completados</p>
                            </div>
                        </div>
                        <div class="chart-container">
                            <canvas id="pagosChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab Content: Académico -->
            <div class="tab-content" id="academico">
                <div class="charts-grid">
                    <div class="chart-card">
                        <div class="chart-header">
                            <div>
                                <h3><span class="material-symbols-rounded">timeline</span> Rendimiento Académico</h3>
                                <p>Promedio de calificaciones por curso</p>
                            </div>
                        </div>
                        <div class="chart-container">
                            <canvas id="rendimientoChart"></canvas>
                        </div>
                    </div>

                    <div class="chart-card">
                        <div class="chart-header">
                            <div>
                                <h3><span class="material-symbols-rounded">analytics</span> Asistencia</h3>
                                <p>Porcentaje de asistencia por grupo</p>
                            </div>
                        </div>
                        <div class="chart-container">
                            <canvas id="asistenciaChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab Content: Cursos -->
            <div class="tab-content" id="cursos">
                <div class="charts-grid">
                    <div class="chart-card">
                        <div class="chart-header">
                            <div>
                                <h3><span class="material-symbols-rounded">groups</span> Estudiantes por Curso</h3>
                                <p>Distribución de estudiantes en cada curso</p>
                            </div>
                        </div>
                        <div class="chart-container">
                            <canvas id="estudiantesCursoChart"></canvas>
                        </div>
                    </div>

                    <div class="chart-card">
                        <div class="chart-header">
                            <div>
                                <h3><span class="material-symbols-rounded">event_available</span> Cursos Activos</h3>
                                <p>Estado actual de los cursos ofrecidos</p>
                            </div>
                        </div>
                        <div class="chart-container">
                            <canvas id="cursosActivosChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
        // Datos desde PHP
        const inscripcionesData = <?php echo json_encode($inscripciones_data); ?>;
        const mesesLabels = <?php echo json_encode($meses_labels); ?>;
        const tendenciaLabels = <?php echo json_encode($tendencia_labels); ?>;
        const tendenciaValues = <?php echo json_encode($tendencia_values); ?>;

        // Colores del tema
        const colors = {
            primaryBlue: '#1479b0',
            primaryGreen: '#4ec336',
            primaryOrange: '#ff6d00',
            primaryYellow: '#e9e93e',
            subtleBlue: '#1479b03a',
            subtleGreen: '#4ec33633',
            subtleOrange: '#ff6f003d',
            subtleYellow: '#e9e93e38'
        };

        // Configuración común para todos los gráficos
        const commonOptions = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        color: '#f8fafc',
                        padding: 15,
                        font: {
                            size: 12,
                            family: 'Poppins'
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        color: '#94a3b8'
                    },
                    grid: {
                        color: '#334155'
                    }
                },
                x: {
                    ticks: {
                        color: '#94a3b8'
                    },
                    grid: {
                        color: '#334155'
                    }
                }
            }
        };

        // Gráfico de Inscripciones por Mes
        const ctxInscripciones = document.getElementById('inscripcionesChart').getContext('2d');
        new Chart(ctxInscripciones, {
            type: 'bar',
            data: {
                labels: mesesLabels,
                datasets: [
                    {
                        label: 'Preinscripciones',
                        data: inscripcionesData.preinscripciones,
                        backgroundColor: colors.primaryBlue,
                        borderRadius: 6
                    },
                    {
                        label: 'Matrículas',
                        data: inscripcionesData.matriculas,
                        backgroundColor: colors.primaryGreen,
                        borderRadius: 6
                    },
                    {
                        label: 'Rechazadas',
                        data: inscripcionesData.rechazadas,
                        backgroundColor: colors.primaryOrange,
                        borderRadius: 6
                    }
                ]
            },
            options: commonOptions
        });

        // Gráfico de Tendencia de Crecimiento
        const ctxTendencia = document.getElementById('tendenciaChart').getContext('2d');
        new Chart(ctxTendencia, {
            type: 'line',
            data: {
                labels: tendenciaLabels,
                datasets: [{
                    label: 'Estudiantes Activos',
                    data: tendenciaValues,
                    borderColor: colors.primaryOrange,
                    backgroundColor: colors.subtleOrange,
                    fill: true,
                    tension: 0.4,
                    borderWidth: 2
                }]
            },
            options: commonOptions
        });

        // Gráfico de Ingresos por Curso
        const ctxIngresos = document.getElementById('ingresosChart').getContext('2d');
        new Chart(ctxIngresos, {
            type: 'doughnut',
            data: {
                labels: ['Guitarra', 'Piano', 'Viento', 'Vocal', 'Teoría'],
                datasets: [{
                    data: [30, 25, 20, 15, 10],
                    backgroundColor: [
                        colors.primaryBlue,
                        colors.primaryGreen,
                        colors.primaryOrange,
                        colors.primaryYellow,
                        '#8b5cf6'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            color: '#f8fafc',
                            padding: 15,
                            font: {
                                size: 12,
                                family: 'Poppins'
                            }
                        }
                    }
                }
            }
        });

        // Gráfico de Estado de Pagos
        const ctxPagos = document.getElementById('pagosChart').getContext('2d');
        new Chart(ctxPagos, {
            type: 'pie',
            data: {
                labels: ['Pagados', 'Pendientes', 'Vencidos'],
                datasets: [{
                    data: [60, 30, 10],
                    backgroundColor: [
                        colors.primaryGreen,
                        colors.primaryYellow,
                        colors.primaryOrange
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            color: '#f8fafc',
                            padding: 15,
                            font: {
                                size: 12,
                                family: 'Poppins'
                            }
                        }
                    }
                }
            }
        });

        // Gráfico de Rendimiento Académico
        const ctxRendimiento = document.getElementById('rendimientoChart').getContext('2d');
        new Chart(ctxRendimiento, {
            type: 'bar',
            data: {
                labels: ['Guitarra', 'Piano', 'Viento', 'Vocal', 'Teoría'],
                datasets: [{
                    label: 'Promedio',
                    data: [4.2, 4.5, 4.0, 4.3, 4.1],
                    backgroundColor: colors.primaryBlue,
                    borderRadius: 6
                }]
            },
            options: {
                ...commonOptions,
                scales: {
                    ...commonOptions.scales,
                    y: {
                        ...commonOptions.scales.y,
                        max: 5
                    }
                }
            }
        });

        // Gráfico de Asistencia
        const ctxAsistencia = document.getElementById('asistenciaChart').getContext('2d');
        new Chart(ctxAsistencia, {
            type: 'bar',
            data: {
                labels: ['Grupo A', 'Grupo B', 'Grupo C', 'Grupo D', 'Grupo E'],
                datasets: [{
                    label: 'Asistencia (%)',
                    data: [95, 88, 92, 85, 90],
                    backgroundColor: colors.primaryGreen,
                    borderRadius: 6
                }]
            },
            options: {
                ...commonOptions,
                scales: {
                    ...commonOptions.scales,
                    y: {
                        ...commonOptions.scales.y,
                        max: 100
                    }
                }
            }
        });

        // Gráfico de Estudiantes por Curso
        const ctxEstudiantesCurso = document.getElementById('estudiantesCursoChart').getContext('2d');
        new Chart(ctxEstudiantesCurso, {
            type: 'horizontalBar',
            data: {
                labels: ['Guitarra', 'Piano', 'Viento', 'Vocal', 'Teoría', 'Ensambles'],
                datasets: [{
                    label: 'Estudiantes',
                    data: [45, 38, 32, 28, 55, 25],
                    backgroundColor: colors.primaryBlue,
                    borderRadius: 6
                }]
            },
            options: commonOptions
        });

        // Gráfico de Cursos Activos
        const ctxCursosActivos = document.getElementById('cursosActivosChart').getContext('2d');
        new Chart(ctxCursosActivos, {
            type: 'doughnut',
            data: {
                labels: ['Activos', 'Finalizados', 'Planificados'],
                datasets: [{
                    data: [8, 3, 2],
                    backgroundColor: [
                        colors.primaryGreen,
                        colors.primaryOrange,
                        colors.primaryYellow
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            color: '#f8fafc',
                            padding: 15,
                            font: {
                                size: 12,
                                family: 'Poppins'
                            }
                        }
                    }
                }
            }
        });

        // Funcionalidad de tabs
        document.querySelectorAll('.tab-btn').forEach(button => {
            button.addEventListener('click', () => {
                // Remover clase active de todos
                document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
                
                // Agregar clase active al seleccionado
                button.classList.add('active');
                const tabId = button.getAttribute('data-tab');
                document.getElementById(tabId).classList.add('active');
            });
        });

        // Función para cambiar año
        function changeYear(year) {
            window.location.href = '?year=' + year;
        }

        // Función para exportar PDF
        function exportPDF() {
            alert('Funcionalidad de exportación a PDF en desarrollo');
        }
    </script>
</body>
</html>