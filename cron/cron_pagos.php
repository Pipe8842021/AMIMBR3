<?php

if (PHP_SAPI !== 'cli') {
    $token_esperado = 'CAMBIA_ESTE_TOKEN_SECRETO';
    if (($_GET['token'] ?? '') !== $token_esperado) {
        http_response_code(403); die("Acceso denegado.\n");
    }
}

require_once __DIR__ . '/../config/database.php';

$log = [];
$log[] = "=== Cron pagos — " . date('Y-m-d H:i:s') . " ===";

$meses_es = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',
             7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];

try {
    $n = $pdo->exec("UPDATE pagos SET estado='vencido' WHERE estado='pendiente' AND fecha_vencimiento < CURDATE()");
    $log[] = "Pagos marcados como vencidos: $n";

    $matriculas = $pdo->query("
        SELECT m.id AS matricula_id, m.estudiante_id, m.fecha_matricula,
               c.precio_mensual, c.nombre AS curso_nombre
        FROM matriculas m
        JOIN grupos g ON m.grupo_id  = g.id
        JOIN cursos c ON g.curso_id  = c.id
        WHERE m.estado = 'activa' AND m.grupo_id IS NOT NULL AND c.precio_mensual > 0
    ")->fetchAll(PDO::FETCH_ASSOC);

    $chk = $pdo->prepare("
        SELECT COUNT(*) FROM pagos
        WHERE matricula_id=? AND YEAR(fecha_vencimiento)=? AND MONTH(fecha_vencimiento)=? AND estado!='anulado'
    ");
    $ins = $pdo->prepare("
        INSERT INTO pagos (estudiante_id,matricula_id,monto,concepto,metodo_pago,estado,fecha_vencimiento,registrado_por)
        VALUES (?,?,?,?,'efectivo',?,?,1)
    ");

    $generados = 0; $omitidos = 0;
    $hoy = new DateTime();
    $lim_anio = (int)$hoy->format('Y');
    $lim_mes  = (int)$hoy->format('n');

    foreach ($matriculas as $m) {
        $fmat      = new DateTime($m['fecha_matricula']);
        $dia_base  = (int)$fmat->format('j');
        $iter_anio = (int)$fmat->format('Y');
        $iter_mes  = (int)$fmat->format('n');

        while ($iter_anio < $lim_anio || ($iter_anio === $lim_anio && $iter_mes <= $lim_mes)) {
            $chk->execute([$m['matricula_id'], $iter_anio, $iter_mes]);
            if ((int)$chk->fetchColumn() > 0) {
                $omitidos++;
            } else {
                $t       = (int)(new DateTime("$iter_anio-$iter_mes-01"))->format('t');
                $dia_ok  = min($dia_base, $t);
                $fecha_v = sprintf('%04d-%02d-%02d', $iter_anio, $iter_mes, $dia_ok);
                $estado  = ($fecha_v < date('Y-m-d')) ? 'vencido' : 'pendiente';
                $concepto = "Mensualidad {$meses_es[$iter_mes]} $iter_anio — {$m['curso_nombre']}";
                $ins->execute([$m['estudiante_id'], $m['matricula_id'], $m['precio_mensual'], $concepto, $estado, $fecha_v]);
                $generados++;
                $log[] = "  + Mat#{$m['matricula_id']} {$concepto} ({$estado}) → {$fecha_v}";
            }
            $iter_mes++;
            if ($iter_mes > 12) { $iter_mes = 1; $iter_anio++; }
        }
    }

    $log[] = "Generados: $generados | Omitidos: $omitidos";
    $log[] = "=== FIN OK ===\n";

} catch (PDOException $e) {
    $log[] = "ERROR: " . $e->getMessage();
    $log[] = "=== FIN CON ERROR ===\n";
}

$log_content = implode("\n", $log);
$log_file    = __DIR__ . '/log_cron_pagos.txt';
if (file_exists($log_file) && filesize($log_file) > 512000)
    rename($log_file, $log_file . '.' . date('Ymd') . '.bak');
file_put_contents($log_file, $log_content . "\n", FILE_APPEND | LOCK_EX);

if (PHP_SAPI === 'cli') echo $log_content . "\n";
else { header('Content-Type: text/plain'); echo $log_content . "\n"; }