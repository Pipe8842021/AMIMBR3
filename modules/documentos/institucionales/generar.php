<?php
/**
 * Generar vista imprimible / PDF de documentos institucionales
 */

require_once '../../../config/session.php';
require_once '../../../config/database.php';
require_once '../../../includes/auth_check.php';

$tipo   = $_GET['tipo'] ?? '';
$doc_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (empty($tipo) || $doc_id === 0) {
    header("Location: index.php?error=documento_no_encontrado"); exit;
}

try {
    $stmt = $pdo->prepare("SELECT id, nombre, rol FROM usuarios WHERE id = ? AND estado = 'activo'");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) { session_destroy(); header("Location: ../../../auth/login.php"); exit; }
} catch (PDOException $e) { die("Error del sistema"); }

$documento    = null;
$tiene_acceso = false;

try {
    if ($tipo === 'certificado') {
        $stmt = $pdo->prepare("
            SELECT cc.*, e.nombre AS estudiante_nombre, e.email AS estudiante_email,
                   c.nombre AS curso_nombre, g.nombre AS grupo_nombre,
                   u.nombre AS aprobado_por_nombre
            FROM calificaciones_certificados cc
            INNER JOIN usuarios e ON cc.estudiante_id = e.id
            INNER JOIN cursos   c ON cc.curso_id      = c.id
            INNER JOIN grupos   g ON cc.grupo_id      = g.id
            LEFT  JOIN usuarios u ON cc.aprobado_por  = u.id
            WHERE cc.id = ? AND cc.estado = 'aprobado'
        ");
        $stmt->execute([$doc_id]);
        $documento = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($documento) {
            $tiene_acceso = $user['rol'] === 'admin' || $user['rol'] === 'profesor'
                || ($user['rol'] === 'estudiante' && $documento['estudiante_id'] == $user['id']);
        }
    } elseif ($tipo === 'comunicado') {
        $stmt = $pdo->prepare("
            SELECT dc.*, u.nombre AS publicado_por_nombre
            FROM documentos_comunicados dc
            INNER JOIN usuarios u ON dc.publicado_por = u.id
            WHERE dc.id = ? AND dc.estado = 'activo'
        ");
        $stmt->execute([$doc_id]);
        $documento = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($documento) {
            $da = $documento['dirigido_a'] ?? 'todos';
            $tiene_acceso = $user['rol'] === 'admin' || $da === 'todos'
                || ($da === 'profesores'  && $user['rol'] === 'profesor')
                || ($da === 'estudiantes' && $user['rol'] === 'estudiante');
        }
    } elseif ($tipo === 'acta') {
        $stmt = $pdo->prepare("
            SELECT da.*, u.nombre AS creado_por_nombre
            FROM documentos_actas da
            INNER JOIN usuarios u ON da.creado_por = u.id
            WHERE da.id = ? AND da.estado = 'activo'
        ");
        $stmt->execute([$doc_id]);
        $documento = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($documento) {
            $vis = $documento['visibilidad'] ?? 'solo_admin';
            $tiene_acceso = $user['rol'] === 'admin'
                || ($user['rol'] === 'profesor'   && in_array($vis, ['admin_profesores', 'todos']))
                || ($user['rol'] === 'estudiante' && $vis === 'todos');
        }
    }

    if (!$documento)   { header("Location: index.php?error=documento_no_encontrado"); exit; }
    if (!$tiene_acceso){ header("Location: index.php?error=sin_permisos"); exit; }

} catch (PDOException $e) {
    error_log("Error generar.php: " . $e->getMessage());
    header("Location: index.php?error=error_sistema"); exit;
}

// ── Helpers ──────────────────────────────────────────────────────────────────
function fmt_fecha_larga($fecha) {
    if (empty($fecha)) return '—';
    $ts    = strtotime($fecha);
    $meses = ['','enero','febrero','marzo','abril','mayo','junio',
              'julio','agosto','septiembre','octubre','noviembre','diciembre'];
    return date('d', $ts) . ' de ' . $meses[(int)date('n', $ts)] . ' de ' . date('Y', $ts);
}
function fmt_fecha_corta(string $fecha): string { return empty($fecha) ? '—' : date('d/m/Y', strtotime($fecha)); }

// Logo embebido en base64
$logo_path = $_SERVER['DOCUMENT_ROOT'] . '/assets/img/3.png';
$logo_b64  = file_exists($logo_path) ? 'data:image/png;base64,' . base64_encode(file_get_contents($logo_path)) : '';

$is_landscape = ($tipo === 'certificado');
$page_title   = [
    'certificado' => 'Certificado – ' . ($documento['curso_nombre'] ?? '') . ' – ' . ($documento['estudiante_nombre'] ?? ''),
    'comunicado'  => 'Comunicado – '  . ($documento['titulo'] ?? ''),
    'acta'        => 'Acta – '        . ($documento['titulo'] ?? ''),
][$tipo] ?? 'Documento Institucional';
?>
<!DOCTYPE html>
<html lang="es" id="htmlRoot">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> – Amimbré</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,600;0,700;1,400&family=Poppins:wght@300;400;500;600&display=swap">
    <link rel="shortcut icon" href="../../../assets/img/3.png">
    <link rel="stylesheet" href="../../../assets/css/colores.css">
    <link rel="stylesheet" href="../../../assets/css/style-documentos-institucionales.css">
    <script>
        (function() {
            var t = localStorage.getItem('amimbre-theme');
            if (t === 'light') document.getElementById('htmlRoot').setAttribute('data-theme', 'light');
        })();
    </script>
<?php if ($is_landscape): ?>
    <style>@page { size: A4 landscape; margin: 0; }</style>
<?php else: ?>
    <style>@page { size: A4 portrait; margin: 0; }</style>
<?php endif; ?>
</head>
<body>

<!-- Barra de acción — solo pantalla, hereda el tema del sistema -->
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
        <button class="btn-close" onclick="window.close()">✕ Cerrar</button>
    </div>
</div>

<div class="doc-wrapper">
<div class="doc-page doc-<?php echo htmlspecialchars($tipo); ?>">

<?php if ($tipo === 'certificado'): ?>
<!-- ════════════════════════════════ CERTIFICADO ════════════════════════════ -->
<div class="cert-outer-border">
    <div class="cert-corner tl"></div><div class="cert-corner tr"></div>
    <div class="cert-corner bl"></div><div class="cert-corner br"></div>
    <?php if ($logo_b64): ?><img src="<?php echo $logo_b64; ?>" class="cert-watermark" alt=""><?php endif; ?>

    <div class="cert-inner-border">

        <div class="cert-header">
            <?php if ($logo_b64): ?><img src="<?php echo $logo_b64; ?>" class="cert-logo" alt="Amimbré"><?php endif; ?>
            <div>
                <div class="cert-school-name">Amimbré</div>
                <div class="cert-school-sub">Escuela de Música</div>
            </div>
            <?php if (!empty($documento['codigo_certificado'])): ?>
            <div class="cert-code-badge">
                <div class="cert-code-badge-label">Código</div>
                <div class="cert-code-badge-value"><?php echo htmlspecialchars($documento['codigo_certificado']); ?></div>
            </div>
            <?php endif; ?>
        </div>

        <div class="cert-body">
            <div class="cert-eyebrow">Escuela de Música Amimbré</div>
            <div class="cert-heading">Certificado de <em>Aprobación</em></div>

            <div class="cert-certifies">La Escuela de Música Amimbré certifica que el/la estudiante</div>
            <div class="cert-student-name"><?php echo htmlspecialchars($documento['estudiante_nombre']); ?></div>
            <div class="cert-completed">ha completado satisfactoriamente el programa de formación en</div>
            <div class="cert-course"><?php echo htmlspecialchars($documento['curso_nombre']); ?></div>

            <div class="cert-chips">
                <div class="cert-chip">
                    <div class="cert-chip-label">Grupo</div>
                    <div class="cert-chip-value"><?php echo htmlspecialchars($documento['grupo_nombre']); ?></div>
                </div>
                <div class="cert-chip">
                    <div class="cert-chip-label">Nivel aprobado</div>
                    <div class="cert-chip-value"><?php echo ucfirst($documento['nivel_aprobado']); ?></div>
                </div>
                <div class="cert-chip">
                    <div class="cert-chip-label">Calificación</div>
                    <div class="cert-chip-value green"><?php echo number_format((float)$documento['calificacion_final'], 1); ?> / 5.0</div>
                </div>
                <?php if (!empty($documento['fecha_inicio_curso']) && !empty($documento['fecha_fin_curso'])): ?>
                <div class="cert-chip">
                    <div class="cert-chip-label">Período</div>
                    <div class="cert-chip-value" style="font-size:12px;">
                        <?php echo fmt_fecha_corta($documento['fecha_inicio_curso']); ?> –
                        <?php echo fmt_fecha_corta($documento['fecha_fin_curso']); ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="cert-footer">
            <div class="cert-date">Bogotá D.C., <?php echo fmt_fecha_larga($documento['fecha_aprobacion']); ?></div>
            <div class="cert-sigs">
                <div class="cert-sig">
                    <div class="cert-sig-line"></div>
                    <div class="cert-sig-name"><?php echo htmlspecialchars($documento['aprobado_por_nombre'] ?? 'Director Académico'); ?></div>
                    <div class="cert-sig-role">Director Académico – Amimbré</div>
                </div>
                <div class="cert-sig">
                    <div class="cert-sig-line"></div>
                    <div class="cert-sig-name"><?php echo htmlspecialchars($documento['estudiante_nombre']); ?></div>
                    <div class="cert-sig-role">Estudiante</div>
                </div>
            </div>
        </div>

    </div>
</div><!-- cert-outer-border -->


<?php elseif ($tipo === 'comunicado'): ?>
<!-- ════════════════════════════════ COMUNICADO ════════════════════════════ -->

<div class="com-header">
    <?php if ($logo_b64): ?><img src="<?php echo $logo_b64; ?>" class="com-logo" alt="Amimbré"><?php endif; ?>
    <div>
        <div class="com-school-name">Amimbré</div>
        <div class="com-school-sub">Escuela de Música</div>
    </div>
    <div class="com-doc-type">
        <div class="com-doc-type-label">Documento</div>
        <div class="com-doc-type-title">Comunicado</div>
    </div>
</div>

<div class="com-meta">
    <div class="com-meta-item"><span class="com-meta-label">Fecha:</span><span class="com-meta-value"><?php echo fmt_fecha_larga($documento['fecha_publicacion']); ?></span></div>
    <div class="com-meta-item"><span class="com-meta-label">Para:</span><span class="com-meta-value"><?php echo ucfirst($documento['dirigido_a'] ?? 'Todos'); ?></span></div>
    <div class="com-meta-item"><span class="com-meta-label">Categoría:</span><span class="com-meta-value"><?php echo ucfirst($documento['categoria'] ?? 'General'); ?></span></div>
    <div class="com-priority <?php echo ($documento['prioridad'] ?? '') === 'urgente' ? 'urgente' : ''; ?>">
        <?php echo strtoupper($documento['prioridad'] ?? 'Normal'); ?>
    </div>
</div>

<div class="com-body">
    <div class="com-title"><?php echo htmlspecialchars($documento['titulo'] ?? 'Comunicado'); ?></div>
    <?php if (!empty($documento['descripcion'])): ?>
    <div class="com-content"><?php echo htmlspecialchars($documento['descripcion']); ?></div>
    <?php else: ?>
    <div class="com-content-empty">Sin contenido adicional.</div>
    <?php endif; ?>
</div>

<div class="com-footer">
    <div class="com-footer-text">
        Publicado por: <strong><?php echo htmlspecialchars($documento['publicado_por_nombre'] ?? '—'); ?></strong><br>
        <strong>Escuela de Música Amimbré</strong>
    </div>
    <?php if ($logo_b64): ?><img src="<?php echo $logo_b64; ?>" class="com-footer-logo" alt=""><?php endif; ?>
</div>


<?php elseif ($tipo === 'acta'): ?>
<!-- ════════════════════════════════ ACTA ══════════════════════════════════ -->

<div class="acta-header">
    <?php if ($logo_b64): ?><img src="<?php echo $logo_b64; ?>" class="acta-logo" alt="Amimbré"><?php endif; ?>
    <div>
        <div class="acta-school-name">Escuela de Música Amimbré</div>
        <div class="acta-school-sub">Formación musical de excelencia</div>
    </div>
    <div class="acta-doc-type">
        <div class="acta-doc-type-label">Documento oficial</div>
        <div class="acta-doc-type-title">Acta de Reunión</div>
    </div>
</div>

<div class="acta-title-bar">
    <div class="acta-title"><?php echo htmlspecialchars($documento['titulo'] ?? 'Acta de Reunión'); ?></div>
</div>

<div class="acta-body">
    <table class="acta-info-table">
        <tr><td class="label">Tipo de reunión</td><td class="value"><?php echo ucfirst(str_replace('_', ' ', $documento['tipo_reunion'] ?? '—')); ?></td></tr>
        <tr><td class="label">Fecha</td><td class="value"><?php echo fmt_fecha_larga($documento['fecha_reunion']); ?></td></tr>
        <tr><td class="label">Lugar</td><td class="value"><?php echo htmlspecialchars($documento['lugar'] ?? 'No especificado'); ?></td></tr>
        <tr><td class="label">Creada por</td><td class="value"><?php echo htmlspecialchars($documento['creado_por_nombre'] ?? '—'); ?></td></tr>
        <?php if (isset($documento['visibilidad'])): ?>
        <tr><td class="label">Visibilidad</td><td class="value"><?php
            $vm = ['solo_admin' => 'Solo Administradores', 'admin_profesores' => 'Admin y Profesores', 'todos' => 'Todos'];
            echo $vm[$documento['visibilidad']] ?? ucfirst($documento['visibilidad']);
        ?></td></tr>
        <?php endif; ?>
    </table>

    <?php if (!empty($documento['asistentes'])): ?>
    <div class="acta-section-title">Asistentes</div>
    <div class="acta-attendees"><?php echo htmlspecialchars($documento['asistentes']); ?></div>
    <?php endif; ?>

    <div class="acta-section-title">Desarrollo de la reunión</div>
    <?php if (!empty($documento['descripcion'])): ?>
    <div class="acta-content"><?php echo htmlspecialchars($documento['descripcion']); ?></div>
    <?php else: ?>
    <div class="no-content">Sin descripción registrada.</div>
    <?php endif; ?>

    <div class="acta-sigs">
        <div class="acta-sig">
            <div class="acta-sig-name"><?php echo htmlspecialchars($documento['creado_por_nombre'] ?? 'Director'); ?></div>
            <div class="acta-sig-role">Director Académico</div>
        </div>
        <div class="acta-sig">
            <div class="acta-sig-name">_______________________</div>
            <div class="acta-sig-role">Secretaría</div>
        </div>
        <div class="acta-sig">
            <div class="acta-sig-name">_______________________</div>
            <div class="acta-sig-role">Representante</div>
        </div>
    </div>
</div>

<div class="acta-footer">
    <div class="acta-footer-text">
        <strong>Escuela de Música Amimbré</strong><br>
        Acta N.° <?php echo str_pad($doc_id, 4, '0', STR_PAD_LEFT); ?> · <?php echo date('Y'); ?>
    </div>
    <?php if ($logo_b64): ?><img src="<?php echo $logo_b64; ?>" class="acta-footer-logo" alt=""><?php endif; ?>
</div>

<?php endif; ?>

</div><!-- doc-page -->
</div><!-- doc-wrapper -->

</body>
</html>
