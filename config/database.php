<?php
/**
 * Configuración de Base de Datos - Amimbré
 * Sistema de Gestión de Escuela Musical
 */

// Configuración de la base de datos
define('DB_HOST', 'localhost');
define('DB_NAME', 'amimbre_db');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Configuración de la aplicación
define('APP_NAME', 'Amimbré');
define('APP_URL', 'http://localhost/AMIMBR3'); // Ajusta según tu carpeta
define('APP_VERSION', '1.0.0');

// Zona horaria
date_default_timezone_set('America/Bogota');

try {
    // Crear conexión PDO
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET
    ];
    
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    
} catch (PDOException $e) {
    // En producción, no mostrar detalles del error
    if (getenv('ENVIRONMENT') === 'production') {
        die('Error de conexión a la base de datos. Contacte al administrador.');
    } else {
        die('Error de conexión: ' . $e->getMessage());
    }
}

/**
 * Función helper para sanitizar entrada
 */
function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

/**
 * Función helper para validar email
 */
function validate_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

/**
 * Función helper para generar token seguro
 */
function generate_token($length = 32) {
    return bin2hex(random_bytes($length));
}

/**
 * Función para registrar logs
 */
function log_activity($pdo, $user_id, $action, $details = null) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO logs_actividad (usuario_id, accion, detalles, ip_address, user_agent, fecha)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $user_id,
            $action,
            $details,
            $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
        ]);
        
        return true;
    } catch (PDOException $e) {
        error_log("Error logging activity: " . $e->getMessage());
        return false;
    }
}

/**
 * Función para verificar permisos de usuario
 */
function check_permission($role, $required_role) {
    $roles_hierarchy = [
        'admin' => 4,
        'profesor' => 3,
        'estudiante' => 2,
        'visitante' => 1
    ];
    
    $user_level = $roles_hierarchy[$role] ?? 0;
    $required_level = $roles_hierarchy[$required_role] ?? 0;
    
    return $user_level >= $required_level;
}

return $pdo;