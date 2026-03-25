<?php
/**
 * cron_pagos.php — Amimbré
 *
 * Ejecutar mensualmente via cron (día 1 de cada mes a medianoche):
 *   0 0 1 * * php /ruta/AMIMBR3/cron/cron_pagos.php
 *
 * También puedes llamarlo manualmente desde el navegador si está protegido.
 *
 * Lo que hace:
 *  1. Marca como 'vencido' todos los pagos pendientes cuya fecha_vencimiento < HOY
 *  2. Genera el pago del mes actual para cada matrícula activa que no lo tenga aún
 *     El día de vencimiento = mismo día del mes de la fecha_matricula
 */

// Seguridad mínima: solo desde CLI o con token
if (PHP_SAPI !== 'cli') {
    $token_esperado = 'CAMBIA_ESTE_TOKEN_SECRETO';
    $token_recibido = $_GET['token'] ?? '';
    if ($token_recibido !== $token_esperado) {
        http_response_code(403);
        die("Acceso denegado.\n");
    }
}

require_once __DIR__ . '/../config/database.php';

$log = [];
$log[] = "=== Cron pagos — " . date('Y-m-d H:i:s') . " ===";

try {
    // ── 1. Marcar vencidos ───────────────────────────────────
    $stmt = $pdo->exec("
        UPDATE pagos
        SET estado = 'vencido'
        WHERE estado = 'pendiente'
          AND fecha_vencimiento < CURDATE()
    ");
    $log[] = "Pagos marcados como vencidos: $stmt";

    // ── 2. Generar pagos del mes actual ──────────────────────
    $meses_es = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio',
                 'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];

    // Obtener todas las matrículas activas con grupo y precio
    $matriculas = $pdo->query("
        SELECT
            m.id AS matricula_id,
            m.estudiante_id,
            m.fecha_matricula,
            c.precio_mensual,
            c.nombre AS curso_nombre
        FROM matriculas m
        JOIN grupos g ON m.grupo_id = g.id
        JOIN cursos c ON g.curso_id = c.id
        WHERE m.estado = 'activa'
          AND m.grupo_id IS NOT NULL
          AND c.precio_mensual > 0
    ")->fetchAll(PDO::FETCH_ASSOC);

    $generados  = 0;
    $omitidos   = 0;
    $hoy        = new DateTime();
    $anio_hoy   = (int)$hoy->format('Y');
    $mes_hoy    = (int)$hoy->format('n');

    $chk  = $pdo->prepare("SELECT COUNT(*) FROM pagos WHERE matricula_id=? AND YEAR(fecha_vencimiento)=? AND MONTH(fecha_vencimiento)=? AND estado!='anulado'");
    $ins  = $pdo->prepare("INSERT INTO pagos (estudiante_id,matricula_id,monto,concepto,metodo_pago,estado,fecha_vencimiento,registrado_por) VALUES (?,?,?,?,'efectivo','pendiente',?,1)");

    foreach ($matriculas as $m) {
        // Verificar si ya existe pago este mes
        $chk->execute([$m['matricula_id'], $anio_hoy, $mes_hoy]);
        if ((int)$chk->fetchColumn() > 0) { $omitidos++; continue; }

        // Calcular día de vencimiento
        $dia_base = (int)(new DateTime($m['fecha_matricula']))->format('j');
        $ultimo   = (int)$hoy->format('t'); // último día del mes actual
        $dia_ok   = min($dia_base, $ultimo);
        $fecha_v  = sprintf('%04d-%02d-%02d', $anio_hoy, $mes_hoy, $dia_ok);

        // Estado inicial: si la fecha ya pasó, nace vencido
        $estado_inicial = ($fecha_v < date('Y-m-d')) ? 'vencido' : 'pendiente';

        $concepto = "Mensualidad {$meses_es[$mes_hoy]} $anio_hoy — {$m['curso_nombre']}";

        // Re-usar el INSERT con estado dinámico
        $pdo->prepare("INSERT INTO pagos (estudiante_id,matricula_id,monto,concepto,metodo_pago,estado,fecha_vencimiento,registrado_por) VALUES (?,?,?,?,'efectivo',?,?,1)")
            ->execute([$m['estudiante_id'],$m['matricula_id'],$m['precio_mensual'],$concepto,$estado_inicial,$fecha_v]);

        $generados++;
        $log[] = "  + Matrícula #{$m['matricula_id']} — {$concepto} ({$estado_inicial}) → $fecha_v";
    }

    $log[] = "Pagos generados: $generados | Omitidos (ya existían): $omitidos";
    $log[] = "=== FIN OK ===\n";

} catch (PDOException $e) {
    $log[] = "ERROR: " . $e->getMessage();
    $log[] = "=== FIN CON ERROR ===\n";
}

// Escribir log
$log_content = implode("\n", $log);
$log_file    = __DIR__ . '/log_cron_pagos.txt';

// Rotar si el log supera 500KB
if (file_exists($log_file) && filesize($log_file) > 512000) {
    rename($log_file, $log_file . '.' . date('Ymd') . '.bak');
}
file_put_contents($log_file, $log_content . "\n", FILE_APPEND | LOCK_EX);

if (PHP_SAPI === 'cli') {
    echo $log_content . "\n";
} else {
    header('Content-Type: text/plain');
    echo $log_content . "\n";
}