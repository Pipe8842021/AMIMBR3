<?php
/**
 * Generar vista imprimible / PDF de Bitácora de Clase
 */

require_once '../../../../config/session.php';
require_once '../../../../config/database.php';
require_once '../../../../includes/auth_check.php';

$bitacora_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($bitacora_id === 0) { header("Location: ../index.php?error=bitacora_no_encontrada"); exit; }

$uid = (int)$_SESSION['user_id'];
$rol = $_SESSION['user_rol'];

try {
    $stmt = $pdo->prepare("SELECT id, nombre, rol FROM usuarios WHERE id = ? AND estado = 'activo'");
    $stmt->execute([$uid]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) { session_destroy(); header("Location: ../../../../auth/login.php"); exit; }
} catch (PDOException $e) { die("Error del sistema"); }

try {
    $stmt = $pdo->prepare("
        SELECT b.*, c.nombre AS curso_nombre, g.nombre AS grupo_nombre,
               u.nombre AS profesor_nombre
        FROM bitacoras b
        INNER JOIN cursos  c ON b.curso_id   = c.id
        INNER JOIN grupos  g ON b.grupo_id   = g.id
        INNER JOIN usuarios u ON b.profesor_id = u.id
        WHERE b.id = ? AND b.estado = 'activo'
    ");
    $stmt->execute([$bitacora_id]);
    $bitacora = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$bitacora) { header("Location: ../index.php?error=bitacora_no_encontrada"); exit; }

    // Verificar acceso
    $tiene_acceso = false;
    if ($rol === 'admin') {
        $tiene_acceso = true;
    } elseif ($rol === 'profesor') {
        $tiene_acceso = ($bitacora['profesor_id'] == $uid);
    } else {
        // Estudiante: si está matriculado en el grupo
        $stmt2 = $pdo->prepare("SELECT id FROM matriculas WHERE estudiante_id = ? AND grupo_id = ? AND estado = 'activa'");
        $stmt2->execute([$uid, $bitacora['grupo_id']]);
        $tiene_acceso = (bool)$stmt2->fetch();
    }
    if (!$tiene_acceso) { header("Location: ../index.php?error=sin_permisos"); exit; }

    // Asistencias
    $stmt = $pdo->prepare("
        SELECT ba.estado, ba.observacion, u.nombre AS nombre
        FROM bitacoras_asistencias ba
        INNER JOIN usuarios u ON ba.estudiante_id = u.id
        WHERE ba.bitacora_id = ? ORDER BY u.nombre
    ");
    $stmt->execute([$bitacora_id]);
    $asistencias = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Evidencias (base64 embebidas)
    $stmt = $pdo->prepare("SELECT nombre_archivo, ruta_archivo, descripcion FROM bitacoras_evidencias WHERE bitacora_id = ? ORDER BY orden");
    $stmt->execute([$bitacora_id]);
    $evidencias_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $evidencias = [];
    foreach ($evidencias_raw as $ev) {
        $abs = __DIR__ . '/../../../../assets/uploads/bitacoras/evidencias/' . basename($ev['ruta_archivo']);
        $b64 = '';
        if (file_exists($abs)) {
            $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
            $mime = in_array($ext, ['jpg','jpeg']) ? 'image/jpeg' : ('image/'.($ext === 'png' ? 'png' : ($ext === 'gif' ? 'gif' : 'webp')));
            $b64 = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($abs));
        }
        $evidencias[] = ['descripcion' => $ev['descripcion'], 'b64' => $b64];
    }

} catch (PDOException $e) {
    error_log("Error generar bitácora: " . $e->getMessage());
    header("Location: ../index.php?error=error_sistema"); exit;
}

// Helpers
function fmt_bit_larga(string $fecha): string {
    if (empty($fecha)) return '—';
    $ts = strtotime($fecha);
    $dias  = ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'];
    $meses = ['','enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
    return $dias[date('w',$ts)] . ', ' . date('d',$ts) . ' de ' . $meses[(int)date('n',$ts)] . ' de ' . date('Y',$ts);
}
function fmt_bit_hora(string $hora): string {
    return empty($hora) ? '—' : date('h:i A', strtotime($hora));
}

$logo_path = $_SERVER['DOCUMENT_ROOT'] . '/assets/img/3.png';
$logo_b64  = file_exists($logo_path) ? 'data:image/png;base64,' . base64_encode(file_get_contents($logo_path)) : '';

$temas = array_filter(array_map('trim', explode(',', $bitacora['temas_tratados'] ?? '')));

$presentes  = count(array_filter($asistencias, fn($a) => $a['estado'] === 'presente'));
$ausentes   = count(array_filter($asistencias, fn($a) => $a['estado'] === 'ausente'));
$justific   = count(array_filter($asistencias, fn($a) => $a['estado'] === 'justificado'));
$tardanzas  = count(array_filter($asistencias, fn($a) => $a['estado'] === 'tardanza'));

$page_title = 'Bitácora – ' . $bitacora['titulo'];
?>
<!DOCTYPE html>
<html lang="es" id="htmlRoot">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> – Amimbré</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700&family=Poppins:wght@300;400;500;600&display=swap">
    <link rel="stylesheet" href="../../../../assets/css/colores.css">
    <link rel="stylesheet" href="../../../../assets/css/style-documentos-bitacora.css">
    <link rel="shortcut icon" href="../../../../assets/img/3.png">
    <style>@page { size: A4 portrait; margin: 0; }</style>
    <script>
        (function() {
            var t = localStorage.getItem('amimbre-theme');
            if (t === 'light') document.getElementById('htmlRoot').setAttribute('data-theme', 'light');
        })();
    </script>
</head>
<body>
<div class="action-bar">
    <div class="action-bar-info">
        <?php if ($logo_b64): ?>
        <img src="<?php echo $logo_b64; ?>" style="width:34px;height:34px;object-fit:contain;" alt="">
        <?php endif; ?>
        <div>
            <strong><?php echo htmlspecialchars($page_title); ?></strong><br>
            <span class="tip">En el diálogo de impresión, activa "Gráficos de fondo" para que aparezcan los colores.</span>
        </div>
    </div>
    <div class="action-bar-actions">
        <button class="btn-print" onclick="window.print()">🖨 Imprimir / Guardar PDF</button>
        <button class="btn-close"  onclick="window.close()">✕ Cerrar</button>
    </div>
</div>

<div class="doc-wrapper">
<div class="doc-page">

    <!-- Header -->
    <div class="bit-header">
        <?php if ($logo_b64): ?><img src="<?php echo $logo_b64; ?>" class="bit-logo" alt="Amimbré"><?php endif; ?>
        <div>
            <div class="bit-school-name">Escuela de Música Amimbré</div>
            <div class="bit-school-sub">Formación musical de excelencia</div>
        </div>
        <div class="bit-doc-type">
            <div class="bit-doc-type-label">Documento oficial</div>
            <div class="bit-doc-type-title">Bitácora de Clase</div>
        </div>
    </div>

    <!-- Título -->
    <div class="bit-title-bar">
        <div class="bit-title"><?php echo htmlspecialchars($bitacora['titulo']); ?></div>
    </div>

    <!-- Info grid -->
    <div class="bit-info-row">
        <div class="bit-info-cell">
            <div class="bit-info-label">Fecha</div>
            <div class="bit-info-value"><?php echo fmt_bit_larga($bitacora['fecha_clase']); ?></div>
        </div>
        <div class="bit-info-cell">
            <div class="bit-info-label">Horario</div>
            <div class="bit-info-value">
                <?php echo fmt_bit_hora($bitacora['hora_inicio']); ?> – <?php echo fmt_bit_hora($bitacora['hora_fin']); ?>
            </div>
        </div>
        <div class="bit-info-cell">
            <div class="bit-info-label">Profesor</div>
            <div class="bit-info-value"><?php echo htmlspecialchars($bitacora['profesor_nombre']); ?></div>
        </div>
        <div class="bit-info-cell">
            <div class="bit-info-label">Curso</div>
            <div class="bit-info-value"><?php echo htmlspecialchars($bitacora['curso_nombre']); ?></div>
        </div>
        <div class="bit-info-cell">
            <div class="bit-info-label">Grupo</div>
            <div class="bit-info-value"><?php echo htmlspecialchars($bitacora['grupo_nombre']); ?></div>
        </div>
        <div class="bit-info-cell">
            <div class="bit-info-label">N.° Bitácora</div>
            <div class="bit-info-value"><?php echo str_pad($bitacora_id, 4, '0', STR_PAD_LEFT); ?></div>
        </div>
    </div>

    <!-- Body -->
    <div class="bit-body">

        <!-- Temas -->
        <?php if (!empty($temas)): ?>
        <div class="bit-section">
            <div class="bit-section-title">Temas Tratados</div>
            <div class="bit-temas">
                <?php foreach ($temas as $t): ?>
                <span class="bit-tema"><?php echo htmlspecialchars($t); ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Descripción -->
        <?php if (!empty($bitacora['descripcion_clase'])): ?>
        <div class="bit-section">
            <div class="bit-section-title">Descripción de la Clase</div>
            <div class="bit-text"><?php echo htmlspecialchars($bitacora['descripcion_clase']); ?></div>
        </div>
        <?php endif; ?>

        <!-- Observaciones -->
        <?php if (!empty($bitacora['observaciones'])): ?>
        <div class="bit-section">
            <div class="bit-section-title">Observaciones</div>
            <div class="bit-text"><?php echo htmlspecialchars($bitacora['observaciones']); ?></div>
        </div>
        <?php endif; ?>

        <!-- Compromisos -->
        <?php if (!empty($bitacora['compromisos_proxima_clase'])): ?>
        <div class="bit-section">
            <div class="bit-section-title">Compromisos para la Próxima Clase</div>
            <div class="bit-text"><?php echo htmlspecialchars($bitacora['compromisos_proxima_clase']); ?></div>
        </div>
        <?php endif; ?>

        <!-- Asistencia -->
        <?php if (count($asistencias) > 0): ?>
        <div class="bit-section">
            <div class="bit-section-title">Registro de Asistencia</div>
            <div class="bit-asist-stats">
                <div class="bit-asist-stat presente">
                    <div class="bit-asist-num"><?php echo $presentes; ?></div>
                    <div class="bit-asist-lbl">Presentes</div>
                </div>
                <div class="bit-asist-stat ausente">
                    <div class="bit-asist-num"><?php echo $ausentes; ?></div>
                    <div class="bit-asist-lbl">Ausentes</div>
                </div>
                <div class="bit-asist-stat justificado">
                    <div class="bit-asist-num"><?php echo $justific; ?></div>
                    <div class="bit-asist-lbl">Justificados</div>
                </div>
                <div class="bit-asist-stat tardanza">
                    <div class="bit-asist-num"><?php echo $tardanzas; ?></div>
                    <div class="bit-asist-lbl">Tardanzas</div>
                </div>
            </div>
            <table class="bit-asist-table">
                <thead>
                    <tr><th>Estudiante</th><th>Estado</th><th>Observación</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($asistencias as $a): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($a['nombre']); ?></td>
                        <td><span class="bit-badge <?php echo $a['estado']; ?>"><?php echo ucfirst($a['estado']); ?></span></td>
                        <td style="color:#9ca3af;font-style:italic;"><?php echo htmlspecialchars($a['observacion'] ?? ''); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- Evidencias -->
        <?php $ev_con_img = array_filter($evidencias, fn($e) => !empty($e['b64'])); ?>
        <?php if (!empty($ev_con_img)): ?>
        <div class="bit-section">
            <div class="bit-section-title">Evidencias Fotográficas</div>
            <div class="bit-evidencias">
                <?php foreach ($ev_con_img as $ev): ?>
                <div class="bit-evidencia">
                    <img src="<?php echo $ev['b64']; ?>" alt="Evidencia">
                    <?php if (!empty($ev['descripcion'])): ?>
                    <div class="bit-evidencia-cap"><?php echo htmlspecialchars($ev['descripcion']); ?></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- bit-body -->

    <!-- Footer -->
    <div class="bit-footer">
        <div class="bit-footer-text">
            <strong>Escuela de Música Amimbré</strong><br>
            Bitácora N.° <?php echo str_pad($bitacora_id, 4, '0', STR_PAD_LEFT); ?> · <?php echo date('Y'); ?>
        </div>
        <?php if ($logo_b64): ?><img src="<?php echo $logo_b64; ?>" class="bit-footer-logo" alt=""><?php endif; ?>
    </div>

</div><!-- doc-page -->
</div><!-- doc-wrapper -->

<script>
(function () {
    function scaleDocPage() {
        var wrapper = document.querySelector('.doc-wrapper');
        var page    = document.querySelector('.doc-page');
        if (!wrapper || !page) return;
        page.style.transform    = '';
        page.style.marginBottom = '';
        var avail = wrapper.clientWidth - 32;
        var nat   = page.offsetWidth;
        if (nat > avail && avail > 0) {
            var s = avail / nat;
            page.style.transformOrigin = 'top center';
            page.style.transform       = 'scale(' + s + ')';
            page.style.marginBottom    = '-' + Math.round(page.offsetHeight * (1 - s)) + 'px';
        }
    }
    document.addEventListener('DOMContentLoaded', scaleDocPage);
    window.addEventListener('resize', scaleDocPage);
    window.addEventListener('beforeprint', function () {
        var p = document.querySelector('.doc-page');
        if (p) { p.style.transform = ''; p.style.marginBottom = ''; }
    });
    window.addEventListener('afterprint', scaleDocPage);
})();
</script>
</body>
</html>
