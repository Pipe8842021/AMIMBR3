<?php
/**
 * Descargar Documento Institucional
 */

// Iniciar buffer de salida
ob_start();

require_once '../../../config/session.php';
require_once '../../../config/database.php';
require_once '../../../includes/auth_check.php';

// Obtener tipo y ID
$tipo = $_GET['tipo'] ?? '';
$doc_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (empty($tipo) || $doc_id === 0) {
    header("Location: index.php?error=documento_no_encontrado");
    exit;
}

$archivo_ruta = '';
$archivo_nombre = '';
$tiene_acceso = false;

try {
    $user_id = $_SESSION['user_id'];
    $stmt = $pdo->prepare("SELECT rol FROM usuarios WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        header("Location: ../../../auth/login.php");
        exit;
    }
    
    if ($tipo === 'certificado') {
        // CERTIFICADO
        $stmt = $pdo->prepare("
            SELECT ruta_pdf, codigo_certificado, estudiante_id,
                   e.nombre as estudiante_nombre,
                   c.nombre as curso_nombre
            FROM calificaciones_certificados cc
            INNER JOIN usuarios e ON cc.estudiante_id = e.id
            INNER JOIN cursos c ON cc.curso_id = c.id
            WHERE cc.id = ? AND cc.estado = 'aprobado'
        ");
        $stmt->execute([$doc_id]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($doc) {
            $archivo_ruta = $doc['ruta_pdf'];
            
            // Log para debugging
            error_log("Intentando descargar certificado: " . $archivo_ruta);
            error_log("Archivo existe: " . (file_exists($archivo_ruta) ? 'SI' : 'NO'));
            
            // Generar nombre descriptivo si no existe
            $archivo_nombre = 'Certificado_' . str_replace(' ', '_', $doc['estudiante_nombre']) . '_' . str_replace(' ', '_', $doc['curso_nombre']) . '.pdf';
            
            // Verificar permisos
            if ($user['rol'] === 'admin') {
                $tiene_acceso = true;
            } elseif ($user['rol'] === 'estudiante' && $doc['estudiante_id'] == $user_id) {
                $tiene_acceso = true; // Solo su propio certificado
            } elseif ($user['rol'] === 'profesor') {
                $tiene_acceso = true;
            } else {
                $tiene_acceso = false;
            }
        } else {
            error_log("Certificado no encontrado en BD: ID=" . $doc_id);
        }
        
    } elseif ($tipo === 'comunicado') {
        // COMUNICADO
        $stmt = $pdo->prepare("SELECT ruta_archivo, nombre_archivo, dirigido_a FROM documentos_comunicados WHERE id = ? AND estado = 'activo'");
        $stmt->execute([$doc_id]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($doc) {
            $archivo_ruta = $doc['ruta_archivo'];
            $archivo_nombre = $doc['nombre_archivo'];
            
            // Verificar permisos
            $dirigido_a = $doc['dirigido_a'] ?? 'todos';
            if ($dirigido_a === 'todos' || $user['rol'] === 'admin') {
                $tiene_acceso = true;
            } elseif ($dirigido_a === 'profesores' && $user['rol'] === 'profesor') {
                $tiene_acceso = true;
            } elseif ($dirigido_a === 'estudiantes' && $user['rol'] === 'estudiante') {
                $tiene_acceso = true;
            }
        }
        
    } elseif ($tipo === 'acta') {
        // ACTA
        $stmt = $pdo->prepare("SELECT ruta_archivo, nombre_archivo, visibilidad FROM documentos_actas WHERE id = ? AND estado = 'activo'");
        $stmt->execute([$doc_id]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($doc) {
            $archivo_ruta = $doc['ruta_archivo'];
            $archivo_nombre = $doc['nombre_archivo'];
            
            // Verificar permisos
            $visibilidad = $doc['visibilidad'] ?? 'solo_admin';
            if ($user['rol'] === 'admin') {
                $tiene_acceso = true;
            } elseif ($user['rol'] === 'profesor' && in_array($visibilidad, ['admin_profesores', 'todos'])) {
                $tiene_acceso = true;
            } elseif ($user['rol'] === 'estudiante' && $visibilidad === 'todos') {
                $tiene_acceso = true;
            } else {
                $tiene_acceso = false;
            }
        }
    }
    
    if (!$tiene_acceso) {
        header("Location: index.php?error=sin_permisos");
        exit;
    }
    
    if (empty($archivo_ruta) || !file_exists($archivo_ruta)) {
        error_log("Archivo no encontrado - Ruta: " . $archivo_ruta);
        error_log("Tipo: " . $tipo . " - ID: " . $doc_id);
        
        // Redirigir con error específico
        header("Location: ver.php?tipo=$tipo&id=$doc_id&error=archivo_no_encontrado");
        exit;
    }
    
    // Verificar que el archivo no esté vacío
    if (filesize($archivo_ruta) === 0) {
        error_log("Archivo vacío - Ruta: " . $archivo_ruta);
        header("Location: ver.php?tipo=$tipo&id=$doc_id&error=archivo_vacio");
        exit;
    }
    
    // Limpiar todos los buffers de salida
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Determinar tipo MIME
    $extension = strtolower(pathinfo($archivo_ruta, PATHINFO_EXTENSION));
    $mime_types = [
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif'
    ];
    
    $content_type = $mime_types[$extension] ?? 'application/octet-stream';
    
    // Headers para descarga
    header('Content-Type: ' . $content_type);
    header('Content-Disposition: attachment; filename="' . basename($archivo_nombre) . '"');
    header('Content-Length: ' . filesize($archivo_ruta));
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Enviar archivo
    readfile($archivo_ruta);
    exit;
    
} catch (PDOException $e) {
    error_log("Error al descargar: " . $e->getMessage());
    header("Location: index.php?error=error_descarga");
    exit;
}
?>