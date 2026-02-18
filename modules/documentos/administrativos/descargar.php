<?php
/**
 * Descargar Documento Administrativo
 */

// Iniciar buffer de salida para evitar problemas con headers
ob_start();

require_once '../../../config/session.php';
require_once '../../../config/database.php';
require_once '../../../includes/auth_check.php';

// Verificar que no sea estudiante
if ($_SESSION['user_rol'] === 'estudiante') {
    header("Location: ../../../modules/dashboard/");
    exit;
}

// Obtener ID del documento
$doc_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($doc_id === 0) {
    header("Location: index.php?error=documento_no_encontrado");
    exit;
}

try {
    // Obtener documento
    $stmt = $pdo->prepare("
        SELECT * FROM documentos_administrativos 
        WHERE id = ? AND estado = 'activo'
    ");
    $stmt->execute([$doc_id]);
    $documento = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$documento) {
        header("Location: index.php?error=documento_no_encontrado");
        exit;
    }
    
    // Verificar permisos de acceso
    $user_id = $_SESSION['user_id'];
    $user_role = $_SESSION['user_rol'];
    
    $tiene_acceso = false;
    
    if ($user_role === 'admin') {
        $tiene_acceso = true;
    } elseif ($user_role === 'profesor') {
        if ($documento['visibilidad'] === 'profesores' || 
            $documento['visibilidad'] === 'todos_excepto_estudiantes') {
            $tiene_acceso = true;
        } elseif ($documento['visibilidad'] === 'profesor_especifico' && 
                  $documento['profesor_especifico_id'] == $user_id) {
            $tiene_acceso = true;
        }
    }
    
    if (!$tiene_acceso) {
        header("Location: index.php?error=sin_permisos");
        exit;
    }
    
    // Registrar acceso
    try {
        $stmt = $pdo->prepare("
            INSERT INTO documentos_accesos (documento_id, usuario_id, accion, ip_address)
            VALUES (?, ?, 'descarga', ?)
        ");
        $stmt->execute([$doc_id, $user_id, $_SERVER['REMOTE_ADDR']]);
    } catch (PDOException $e) {
        error_log("Error al registrar acceso: " . $e->getMessage());
    }
    
    // Descargar archivo
    $archivo = $documento['ruta_archivo'];
    
    if (file_exists($archivo)) {
        // Limpiar cualquier salida previa y buffers
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // Determinar tipo MIME según extensión
        $extension = strtolower(pathinfo($archivo, PATHINFO_EXTENSION));
        $mime_types = [
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'csv' => 'text/csv',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif'
        ];
        
        $content_type = $mime_types[$extension] ?? 'application/octet-stream';
        
        // Headers para descarga
        header('Content-Type: ' . $content_type);
        header('Content-Disposition: attachment; filename="' . basename($documento['nombre_archivo']) . '"');
        header('Content-Length: ' . filesize($archivo));
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // Enviar archivo
        readfile($archivo);
        exit;
    } else {
        header("Location: index.php?error=archivo_no_encontrado");
        exit;
    }
    
} catch (PDOException $e) {
    error_log("Error al descargar documento: " . $e->getMessage());
    header("Location: index.php?error=error_descarga");
    exit;
}
?>