<?php
/**
 * procesar_preinscripcion.php
 * Procesa y guarda la preinscripción con todos los campos del formulario.
 * Incluir desde pre-inscripcion.php cuando $_SERVER['REQUEST_METHOD'] === 'POST'
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

function clean(string $valor): string {
    return htmlspecialchars(strip_tags(trim($valor)), ENT_QUOTES, 'UTF-8');
}

function nullIfEmpty(?string $valor): ?string {
    $v = trim($valor ?? '');
    return $v === '' ? null : $v;
}

// ── Campos obligatorios ───────────────────────────────────────
$nombres_apellidos  = clean($_POST['nombres_apellidos']  ?? '');
$tipo_documento     = clean($_POST['tipo_documento']     ?? '');
$numero_documento   = clean($_POST['numero_documento']   ?? '');
$email              = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
$celular            = clean($_POST['celular']            ?? '');
$programa           = clean($_POST['programa']           ?? '');

// ── Programa / horario ────────────────────────────────────────
$taller       = nullIfEmpty($_POST['taller']       ?? '');
$fecha_inicio = nullIfEmpty($_POST['fecha_inicio'] ?? '');
$dia_clase    = nullIfEmpty($_POST['dia_clase']    ?? '');
$hora_clase   = nullIfEmpty($_POST['hora_clase']   ?? '');

// ── Datos personales del estudiante ──────────────────────────
$fecha_nacimiento  = nullIfEmpty($_POST['fecha_nacimiento']  ?? '');
$edad              = isset($_POST['edad']) && $_POST['edad'] !== '' ? (int)$_POST['edad'] : null;
$lugar_nacimiento  = nullIfEmpty($_POST['lugar_nacimiento']  ?? '');
$direccion         = nullIfEmpty($_POST['direccion']         ?? '');
$barrio            = nullIfEmpty($_POST['barrio']            ?? '');
$municipio         = nullIfEmpty($_POST['municipio']         ?? '');
$zona              = nullIfEmpty($_POST['zona']              ?? '');
$eps               = nullIfEmpty($_POST['eps']               ?? '');
$nivel_sisben      = nullIfEmpty($_POST['nivel_sisben']      ?? '');
$estrato           = isset($_POST['estrato']) && $_POST['estrato'] !== '' ? (int)$_POST['estrato'] : null;
$ocupacion         = nullIfEmpty($_POST['ocupacion']         ?? '');
$institucion_educativa = nullIfEmpty($_POST['institucion_educativa'] ?? '');

// ── Nivel educativo ───────────────────────────────────────────
$estudio_primaria      = isset($_POST['estudio_primaria'])      ? 1 : 0;
$estudio_secundaria    = isset($_POST['estudio_secundaria'])    ? 1 : 0;
$estudio_tecnico       = isset($_POST['estudio_tecnico'])       ? 1 : 0;
$estudio_tecnologico   = isset($_POST['estudio_tecnologico'])   ? 1 : 0;
$estudio_universitario = isset($_POST['estudio_universitario']) ? 1 : 0;
$estudio_otro          = nullIfEmpty($_POST['estudio_otro']     ?? '');

// ── Acudiente ─────────────────────────────────────────────────
$nombre_acudiente     = nullIfEmpty($_POST['nombre_acudiente']     ?? '');
$parentesco_acudiente = nullIfEmpty($_POST['parentesco_acudiente'] ?? '');
$telefono_acudiente   = nullIfEmpty($_POST['telefono_acudiente']   ?? '');
$email_acudiente      = nullIfEmpty(filter_var($_POST['email_acudiente'] ?? '', FILTER_SANITIZE_EMAIL));
$numero_recibo        = nullIfEmpty($_POST['numero_recibo']        ?? '');

// ── Autorización de imagen ────────────────────────────────────
$autoriza_imagen    = isset($_POST['autoriza_imagen'])   ? 1 : 0;
$firma_acudiente_cc = nullIfEmpty($_POST['firma_acudiente_cc'] ?? '');
$ti_estudiante      = nullIfEmpty($_POST['ti_estudiante']      ?? '');

// ── Observaciones ─────────────────────────────────────────────
$observaciones = nullIfEmpty($_POST['observaciones'] ?? '');

$ip_address = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;

// ── Validaciones del lado servidor ───────────────────────────
$errores = [];

if (empty($nombres_apellidos))                  $errores[] = 'El nombre es obligatorio.';
if (empty($tipo_documento))                     $errores[] = 'El tipo de documento es obligatorio.';
if (empty($numero_documento))                   $errores[] = 'El número de documento es obligatorio.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errores[] = 'El correo electrónico no es válido.';
if (empty($celular))                            $errores[] = 'El celular es obligatorio.';
if (empty($programa))                           $errores[] = 'El programa es obligatorio.';
if (empty($_POST['terminos']))                  $errores[] = 'Debes aceptar los términos.';

// Verificar duplicados
if (empty($errores)) {
    $check = $pdo->prepare("SELECT id FROM preinscripciones WHERE email = ? OR numero_documento = ? LIMIT 1");
    $check->execute([$email, $numero_documento]);
    if ($check->fetch()) {
        $errores[] = 'Ya existe una preinscripción con ese correo o documento.';
    }
}

if (empty($errores)) {
    try {
        $sql = "
            INSERT INTO preinscripciones (
                nombres_apellidos, tipo_documento, numero_documento,
                email, celular, programa, taller,
                fecha_inicio, dia_clase, hora_clase,
                fecha_nacimiento, edad, lugar_nacimiento,
                direccion, barrio, municipio, zona, eps,
                nivel_sisben, estrato, ocupacion,
                estudio_primaria, estudio_secundaria, estudio_tecnico,
                estudio_tecnologico, estudio_universitario, estudio_otro,
                institucion_educativa,
                nombre_acudiente, parentesco_acudiente,
                telefono_acudiente, email_acudiente, numero_recibo,
                autoriza_imagen, firma_acudiente_cc, ti_estudiante,
                observaciones, estado, ip_address
            ) VALUES (
                :nombres_apellidos, :tipo_documento, :numero_documento,
                :email, :celular, :programa, :taller,
                :fecha_inicio, :dia_clase, :hora_clase,
                :fecha_nacimiento, :edad, :lugar_nacimiento,
                :direccion, :barrio, :municipio, :zona, :eps,
                :nivel_sisben, :estrato, :ocupacion,
                :estudio_primaria, :estudio_secundaria, :estudio_tecnico,
                :estudio_tecnologico, :estudio_universitario, :estudio_otro,
                :institucion_educativa,
                :nombre_acudiente, :parentesco_acudiente,
                :telefono_acudiente, :email_acudiente, :numero_recibo,
                :autoriza_imagen, :firma_acudiente_cc, :ti_estudiante,
                :observaciones, 'pendiente', :ip_address
            )
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':nombres_apellidos'     => $nombres_apellidos,
            ':tipo_documento'        => $tipo_documento,
            ':numero_documento'      => $numero_documento,
            ':email'                 => $email,
            ':celular'               => $celular,
            ':programa'              => $programa,
            ':taller'                => $taller,
            ':fecha_inicio'          => $fecha_inicio,
            ':dia_clase'             => $dia_clase,
            ':hora_clase'            => $hora_clase,
            ':fecha_nacimiento'      => $fecha_nacimiento,
            ':edad'                  => $edad,
            ':lugar_nacimiento'      => $lugar_nacimiento,
            ':direccion'             => $direccion,
            ':barrio'                => $barrio,
            ':municipio'             => $municipio,
            ':zona'                  => $zona,
            ':eps'                   => $eps,
            ':nivel_sisben'          => $nivel_sisben,
            ':estrato'               => $estrato,
            ':ocupacion'             => $ocupacion,
            ':estudio_primaria'      => $estudio_primaria,
            ':estudio_secundaria'    => $estudio_secundaria,
            ':estudio_tecnico'       => $estudio_tecnico,
            ':estudio_tecnologico'   => $estudio_tecnologico,
            ':estudio_universitario' => $estudio_universitario,
            ':estudio_otro'          => $estudio_otro,
            ':institucion_educativa' => $institucion_educativa,
            ':nombre_acudiente'      => $nombre_acudiente,
            ':parentesco_acudiente'  => $parentesco_acudiente,
            ':telefono_acudiente'    => $telefono_acudiente,
            ':email_acudiente'       => $email_acudiente,
            ':numero_recibo'         => $numero_recibo,
            ':autoriza_imagen'       => $autoriza_imagen,
            ':firma_acudiente_cc'    => $firma_acudiente_cc,
            ':ti_estudiante'         => $ti_estudiante,
            ':observaciones'         => $observaciones,
            ':ip_address'            => $ip_address,
        ]);

        // Log de actividad
        $pdo->prepare("
            INSERT INTO logs_actividad (usuario_id, accion, detalles, ip_address)
            VALUES (NULL, 'preinscripcion_creada', :detalle, :ip)
        ")->execute([
            ':detalle' => 'ID: ' . $pdo->lastInsertId() . ' — ' . $nombres_apellidos . ' → ' . $programa,
            ':ip'      => $ip_address,
        ]);

        // Notificación al admin
        $pdo->prepare("
            INSERT INTO notificaciones (usuario_id, tipo, titulo, mensaje, enlace, prioridad, emisor)
            VALUES (1, 'preinscripcion', 'Nueva preinscripción recibida', :msg,
                    '/modules/inscripciones/prematriculas/index.php', 'alta', 'Sistema')
        ")->execute([
            ':msg' => 'Nueva preinscripción de ' . $nombres_apellidos . ' para ' . $programa . '.',
        ]);

        header('Location: pre-inscripcion.php?success=1');
        exit;

    } catch (PDOException $e) {
        error_log('[AMIMBRE] Error preinscripción: ' . $e->getMessage());
        header('Location: pre-inscripcion.php?error=1');
        exit;
    }
} else {
    header('Location: pre-inscripcion.php?error=1');
    exit;
}