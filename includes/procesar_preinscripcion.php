<?php
require_once '../config/database.php';

header('Content-Type: application/json');

$response = [
    'success' => false,
    'message' => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Validar y sanitizar datos
    $nombre = sanitize_input($_POST['nombre'] ?? '');
    $email = sanitize_input($_POST['email'] ?? '');
    $telefono = sanitize_input($_POST['telefono'] ?? '');
    $documento = sanitize_input($_POST['documento'] ?? '');
    $curso = sanitize_input($_POST['curso'] ?? '');
    $mensaje = sanitize_input($_POST['mensaje'] ?? '');
    $terminos = isset($_POST['terminos']) ? 1 : 0;
    
    // Validaciones
    $errores = [];
    
    if (empty($nombre) || strlen($nombre) < 3) {
        $errores[] = 'El nombre debe tener al menos 3 caracteres';
    }
    
    if (!validate_email($email)) {
        $errores[] = 'El email no es válido';
    }
    
    if (empty($telefono) || !preg_match('/^[0-9]{7,15}$/', $telefono)) {
        $errores[] = 'El teléfono no es válido';
    }
    
    if (empty($documento) || !preg_match('/^[0-9]{6,15}$/', $documento)) {
        $errores[] = 'El documento no es válido';
    }
    
    if (empty($curso)) {
        $errores[] = 'Debe seleccionar un curso';
    }
    
    if (!$terminos) {
        $errores[] = 'Debe aceptar los términos y condiciones';
    }
    
    // Si hay errores, devolver
    if (!empty($errores)) {
        $response['message'] = implode('<br>', $errores);
        echo json_encode($response);
        exit;
    }
    
    try {
        // Verificar si ya existe una preinscripción con este email
        $stmt = $pdo->prepare("SELECT id FROM preinscripciones WHERE email = ? AND estado = 'pendiente'");
        $stmt->execute([$email]);
        
        if ($stmt->rowCount() > 0) {
            $response['message'] = 'Ya existe una preinscripción pendiente con este email. Te contactaremos pronto.';
            echo json_encode($response);
            exit;
        }
        
        // Insertar preinscripción
        $stmt = $pdo->prepare("
            INSERT INTO preinscripciones 
            (nombre, email, telefono, documento, curso_interes, mensaje, estado, ip_address) 
            VALUES (?, ?, ?, ?, ?, ?, 'pendiente', ?)
        ");
        
        $stmt->execute([
            $nombre,
            $email,
            $telefono,
            $documento,
            $curso,
            $mensaje,
            $_SERVER['REMOTE_ADDR']
        ]);
        
        $preinscripcion_id = $pdo->lastInsertId();
        
        // Enviar email de confirmación (opcional)
        enviar_email_confirmacion($email, $nombre, $curso);
        
        // Notificar administrador (opcional)
        notificar_admin_preinscripcion($pdo, $nombre, $curso);
        
        $response['success'] = true;
        $response['message'] = '¡Preinscripción exitosa! Te contactaremos pronto para continuar con el proceso.';
        
        // Log de actividad
        log_activity($pdo, null, 'preinscripcion_creada', "ID: $preinscripcion_id - Email: $email");
        
    } catch (PDOException $e) {
        error_log("Error en preinscripción: " . $e->getMessage());
        $response['message'] = 'Error al procesar la preinscripción. Por favor, intenta nuevamente.';
    }
    
} else {
    $response['message'] = 'Método no permitido';
}

echo json_encode($response);

/**
 * Función para enviar email de confirmación
 */
function enviar_email_confirmacion($email, $nombre, $curso) {
    // Aquí implementarías el envío de email
    // Puedes usar PHPMailer o similar
    
    $asunto = "Confirmación de Preinscripción - Amimbré";
    $mensaje = "
        <html>
        <body>
            <h2>¡Hola $nombre!</h2>
            <p>Gracias por tu interés en Amimbré.</p>
            <p>Hemos recibido tu preinscripción para el curso: <strong>$curso</strong></p>
            <p>Nos pondremos en contacto contigo en las próximas 48 horas para continuar con el proceso.</p>
            <br>
            <p>Saludos,<br>Equipo Amimbré</p>
        </body>
        </html>
    ";
    
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: noreply@amimbre.com" . "\r\n";
    
    // mail($email, $asunto, $mensaje, $headers);
    
    return true;
}

/**
 * Función para notificar al administrador
 */
function notificar_admin_preinscripcion($pdo, $nombre, $curso) {
    // Obtener emails de administradores
    $stmt = $pdo->query("SELECT email FROM usuarios WHERE rol = 'admin' AND estado = 'activo'");
    $admins = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($admins as $admin_email) {
        $asunto = "Nueva Preinscripción - Amimbré";
        $mensaje = "
            <html>
            <body>
                <h3>Nueva Preinscripción Recibida</h3>
                <p><strong>Estudiante:</strong> $nombre</p>
                <p><strong>Curso de interés:</strong> $curso</p>
                <p>Ingresa al sistema para ver los detalles completos.</p>
            </body>
            </html>
        ";
        
        $headers = "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
        $headers .= "From: sistema@amimbre.com" . "\r\n";
        
        // mail($admin_email, $asunto, $mensaje, $headers);
    }
    
    return true;
}