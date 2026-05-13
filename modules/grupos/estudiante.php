<!-- <?php
        require_once '../../config/session.php';
        require_once '../../config/database.php';
        require_once '../../includes/auth_check.php';
        require_role('estudiante');

        $uid = (int)$_SESSION['user_id'];

        try {
            // 1. Grupos matriculados con datos del curso y profesor
            $stmt = $pdo->prepare("
        SELECT
            g.*,
            c.nombre      AS curso_nombre,
            c.nivel       AS curso_nivel,
            u.nombre      AS profesor_nombre,
            m.id          AS matricula_id,
            m.fecha_matricula
        FROM matriculas m
        JOIN grupos   g ON m.grupo_id    = g.id
        JOIN cursos   c ON g.curso_id    = c.id
        LEFT JOIN usuarios u ON g.profesor_id = u.id
        WHERE m.estudiante_id = ? AND m.estado = 'activa'
        ORDER BY
            FIELD(g.estado, 'activo', 'planificado', 'finalizado', 'cancelado'),
            m.fecha_matricula DESC
    ");
            $stmt->execute([$uid]);
            $mis_grupos = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $ids_grupos = array_column($mis_grupos, 'id');
            $horarios_detallados = [];
            $asist_grupos = [];
            $total_clases_grupo = [];

            if (!empty($ids_grupos)) {
                $ph = implode(',', array_fill(0, count($ids_grupos), '?'));

                // 2. Obtener Horarios Detallados (Nueva estructura de tabla)
                $stmtH = $pdo->prepare("
            SELECT * FROM horarios 
            WHERE grupo_id IN ($ph) 
            ORDER BY FIELD(dia_semana, 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'), hora_inicio
        ");
                $stmtH->execute($ids_grupos);
                while ($h = $stmtH->fetch(PDO::FETCH_ASSOC)) {
                    $horarios_detallados[$h['grupo_id']][] = $h;
                }

                // 3. Asistencia del estudiante específico en estos grupos
                $stmtA = $pdo->prepare("
            SELECT
                b.grupo_id,
                COUNT(ba.id) AS total_registros,
                SUM(CASE WHEN ba.estado = 'presente' THEN 1 ELSE 0 END) AS presentes,
                SUM(CASE WHEN ba.estado = 'ausente' THEN 1 ELSE 0 END) AS ausentes,
                SUM(CASE WHEN ba.estado = 'justificado' THEN 1 ELSE 0 END) AS justificados,
                SUM(CASE WHEN ba.estado = 'tardanza' THEN 1 ELSE 0 END) AS tardanzas
            FROM bitacoras_asistencias ba
            JOIN bitacoras b ON ba.bitacora_id = b.id
            WHERE ba.estudiante_id = ? AND b.grupo_id IN ($ph)
            GROUP BY b.grupo_id
        ");
                $stmtA->execute(array_merge([$uid], $ids_grupos));
                foreach ($stmtA->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $asist_grupos[$r['grupo_id']] = $r;
                }

                // 4. Clases totales dictadas por grupo (bitácoras activas)
                $stmtB = $pdo->prepare("SELECT grupo_id, COUNT(*) as total FROM bitacoras WHERE grupo_id IN ($ph) AND estado = 'activo' GROUP BY grupo_id");
                $stmtB->execute($ids_grupos);
                $total_clases_grupo = $stmtB->fetchAll(PDO::FETCH_KEY_PAIR);
            }

            // Estadísticas globales del estudiante
            $total_matriculas = count($mis_grupos);
            $stmtGlobal = $pdo->prepare("
        SELECT COUNT(*) as total, SUM(CASE WHEN estado = 'presente' THEN 1 ELSE 0 END) as presentes 
        FROM bitacoras_asistencias WHERE estudiante_id = ?
    ");
            $stmtGlobal->execute([$uid]);
            $resGlobal = $stmtGlobal->fetch();
            $pct_asist_global = $resGlobal['total'] > 0 ? round(($resGlobal['presentes'] / $resGlobal['total']) * 100) : null;
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $mis_grupos = [];
        }

        // Helpers UI
        $estado_cfg = [
            'planificado' => ['cls' => 'badge-info',    'txt' => 'Planificado'],
            'activo'      => ['cls' => 'badge-success',  'txt' => 'Activo'],
            'finalizado'  => ['cls' => 'badge-warning',  'txt' => 'Finalizado'],
            'cancelado'   => ['cls' => 'badge-danger',   'txt' => 'Cancelado'],
        ];
        $dias_esp = ['mon' => 'Lun', 'tue' => 'Mar', 'wed' => 'Mié', 'thu' => 'Jue', 'fri' => 'Vie', 'sat' => 'Sáb', 'sun' => 'Dom'];

        date_default_timezone_set('America/Bogota');
        $fecha_hoy = date('d') . ' de ' . ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'][date('n')] . ' de ' . date('Y');
        ?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mi Panel Académico – Amimbré</title>
    <link rel="shortcut icon" href="../../assets/img/3.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,1,0">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="../../assets/css/colores.css">
    <link rel="stylesheet" href="../../assets/css/style-grupos.css">
    <script>
        (function() {
            const t = localStorage.getItem('amimbre-theme');
            if (t === 'light') document.documentElement.setAttribute('data-theme', 'light');
        })();
    </script>
</head>

<body>

    <?php require_once '../../includes/header.php'; ?>

    <main class="main-content">
        <div class="dashboard-header">
            <div class="header-left">
                <button class="btn-back" onclick="window.history.back()">
                    <span class="material-symbols-rounded">arrow_back</span>
                </button>
                <div class="dashboard-title">
                    <h1>Mi Información Académica</h1>
                    <p>Cursos, horarios y seguimiento de asistencia</p>
                </div>
            </div>
            <div class="date-display">
                <span class="material-symbols-rounded">calendar_today</span>
                <?php echo $fecha_hoy; ?>
            </div>
        </div>

        <div class="modulo-stats">
            <div class="modulo-stat-chip total">
                <span class="material-symbols-rounded">auto_stories</span>
                <div>
                    <span class="chip-value"><?php echo $total_matriculas; ?></span>
                    <span class="chip-label">Cursos inscritos</span>
                </div>
            </div>
            <div class="modulo-stat-chip activo">
                <span class="material-symbols-rounded">verified</span>
                <div>
                    <span class="chip-value"><?php echo $pct_asist_global !== null ? $pct_asist_global . '%' : '—'; ?></span>
                    <span class="chip-label">Asistencia Total</span>
                </div>
            </div>
            <div class="modulo-stat-chip planificado">
                <span class="material-symbols-rounded">event_note</span>
                <div>
                    <span class="chip-value"><?php echo array_sum($total_clases_grupo); ?></span>
                    <span class="chip-label">Clases tomadas</span>
                </div>
            </div>
        </div>

        <?php if (empty($mis_grupos)): ?>
            <div class="card">
                <div class="empty-state">
                    <span class="material-symbols-rounded">sentiment_dissatisfied</span>
                    <p>No tienes cursos matriculados actualmente.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="grupos-profesor-grid">
                <?php foreach ($mis_grupos as $g):
                    $est = $estado_cfg[$g['estado']] ?? $estado_cfg['planificado'];
                    $asist = $asist_grupos[$g['id']] ?? ['total_registros' => 0, 'presentes' => 0, 'ausentes' => 0, 'justificados' => 0, 'tardanzas' => 0];
                    $pct_e = $asist['total_registros'] > 0 ? round(($asist['presentes'] / $asist['total_registros']) * 100) : null;
                    $horarios = $horarios_detallados[$g['id']] ?? [];
                ?>
                    <div class="grupo-prof-card">
                        <div class="grupo-prof-header">
                            <div class="grupo-prof-badges">
                                <span class="badge badge-info"><?php echo ucfirst($g['curso_nivel']); ?></span>
                                <span class="badge <?php echo $est['cls']; ?>"><?php echo $est['txt']; ?></span>
                            </div>
                            <div class="grupo-prof-icon"><span class="material-symbols-rounded">library_books</span></div>
                        </div>

                        <div class="grupo-prof-nombre"><?php echo htmlspecialchars($g['curso_nombre']); ?></div>
                        <div class="grupo-prof-curso">Grupo: <?php echo htmlspecialchars($g['nombre']); ?></div>

                        <div class="grupo-est-profesor" style="display:flex; align-items:center; gap:8px; margin: 10px 0; font-size:0.9rem; color:var(--text-main);">
                            <span class="material-symbols-rounded" style="color:var(--primary-green)">person_pin</span>
                            <?php echo $g['profesor_nombre'] ? htmlspecialchars($g['profesor_nombre']) : 'Por asignar'; ?>
                        </div>

                        <div class="grupo-est-horarios" style="background:var(--dark-bg); padding:10px; border-radius:12px; margin-bottom:15px; border:1px solid var(--border-color);">
                            <?php if (empty($horarios)): ?>
                                <p style="font-size:0.75rem; color:var(--text-secondary); text-align:center;">Horario no definido</p>
                            <?php else: ?>
                                <?php foreach ($horarios as $h): ?>
                                    <div style="display:flex; justify-content:space-between; font-size:0.8rem; margin-bottom:4px;">
                                        <span style="font-weight:600; color:var(--primary-green)"><?php echo $dias_esp[$h['dia_semana']] ?? $h['dia_semana']; ?></span>
                                        <span style="color:var(--text-secondary)">
                                            <?php echo substr($h['hora_inicio'], 0, 5); ?> - <?php echo substr($h['hora_fin'], 0, 5); ?>
                                            <?php if ($h['aula']): ?> <small>(<?php echo htmlspecialchars($h['aula']); ?>)</small> <?php endif; ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <div class="grupo-prof-stats">
                            <div class="gps-item">
                                <span class="gps-value"><?php echo $pct_e !== null ? $pct_e . '%' : '—'; ?></span>
                                <span class="gps-label">Mi Asistencia</span>
                                <div class="group-bar">
                                    <div class="group-bar-fill <?php echo ($pct_e >= 80) ? 'bar-success' : 'bar-warning'; ?>" style="width:<?php echo $pct_e ?? 0; ?>%"></div>
                                </div>
                            </div>
                            <div class="gps-item">
                                <span class="gps-value"><?php echo $total_clases_grupo[$g['id']] ?? 0; ?></span>
                                <span class="gps-label">Sesiones</span>
                            </div>
                        </div>

                        <div class="asist-resumen-chips" style="display:flex; gap:6px; margin-top:12px;">
                            <span class="asist-chip-mini presente" title="Presentes"><span class="material-symbols-rounded">check_circle</span> <?php echo $asist['presentes']; ?></span>
                            <span class="asist-chip-mini ausente" title="Ausentes"><span class="material-symbols-rounded">cancel</span> <?php echo $asist['ausentes']; ?></span>
                            <?php if ($asist['tardanzas'] > 0): ?>
                                <span class="asist-chip-mini tardanza" title="Tardanzas"><span class="material-symbols-rounded">schedule</span> <?php echo $asist['tardanzas']; ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="grupo-prof-acciones">
                            <a href="ver.php?id=<?php echo $g['id']; ?>" class="gpa-btn primary">
                                <span class="material-symbols-rounded">info</span> Detalles del Curso
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>

    <style>
        .date-display {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text-secondary);
            font-size: 0.875rem;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            padding: 8px 16px;
            border-radius: 12px;
        }

        .asist-chip-mini {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            background: var(--dark-bg);
        }

        .asist-chip-mini.presente {
            color: var(--primary-green);
            border: 1px solid rgba(var(--primary-rgb), 0.2);
        }

        .asist-chip-mini.ausente {
            color: #ff5252;
            border: 1px solid rgba(255, 82, 82, 0.2);
        }

        .asist-chip-mini.tardanza {
            color: #ffd740;
            border: 1px solid rgba(255, 215, 64, 0.2);
        }

        .asist-chip-mini .material-symbols-rounded {
            font-size: 14px;
        }
    </style>
</body>

</html> -->