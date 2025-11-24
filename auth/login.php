<?php
// Primero incluir la configuración de sesiones
require_once '../config/session.php';
// Luego incluir la base de datos
require_once '../config/database.php';

$error_message = '';
$success_message = '';

// Procesar el formulario si se envía
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Debug
    error_log("POST recibido en login.php");
    
    $email = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);
    
    error_log("Email: $email");
    
    if (empty($email) || empty($password)) {
        $error_message = 'Por favor, completa todos los campos';
    } else {
        try {
            // Buscar usuario en la base de datos
            $stmt = $pdo->prepare("SELECT id, nombre, email, password, rol FROM usuarios WHERE email = ? AND estado = 'activo'");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            
            error_log("Usuario encontrado: " . ($user ? 'Sí' : 'No'));
            
            if ($user && password_verify($password, $user['password'])) {
                error_log("Login exitoso para: $email");
                
                // Login exitoso
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_nombre'] = $user['nombre'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_rol'] = $user['rol'];
                
                // Si marcó "recordarme", crear cookie
                if ($remember) {
                    $token = bin2hex(random_bytes(32));
                    setcookie('remember_token', $token, time() + (86400 * 30), '/'); // 30 días
                    
                    // Guardar token en BD
                    $stmt = $pdo->prepare("UPDATE usuarios SET remember_token = ? WHERE id = ?");
                    $stmt->execute([$token, $user['id']]);
                }
                
                // Registrar acceso
                $stmt = $pdo->prepare("INSERT INTO logs_acceso (usuario_id, ip_address, user_agent) VALUES (?, ?, ?)");
                $stmt->execute([
                    $user['id'],
                    $_SERVER['REMOTE_ADDR'],
                    $_SERVER['HTTP_USER_AGENT']
                ]);
                
                // Redirigir al dashboard (router)
                header('Location: ../modules/dashboard/index.php');
                exit;
            } else {
                error_log("Login fallido - Credenciales incorrectas");
                $error_message = 'Credenciales incorrectas';
            }
        } catch (PDOException $e) {
            error_log("Error en login: " . $e->getMessage());
            $error_message = 'Error en el sistema. Por favor, intenta más tarde';
        }
    }
}

// Verificar si ya hay sesión activa
if (is_logged_in()) {
    header("Location: ../modules/dashboard/index.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - Amimbré</title>
    <link rel="shortcut icon" href="../assets/img/3.png">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
    <link rel="stylesheet" href="../assets/css/style-login.css">
</head>
<body>
    <div class="container">
        <!-- Left Side - 3D Coin -->
        <div class="left-side">
            <div class="gradient-overlay-1"></div>
            <div class="gradient-overlay-2"></div>
            <div id="canvas-container">
                <div id="three-canvas"></div>
            </div>
            <div class="bottom-text">
                <h2>Amimbré</h2>
                <p>Formación Artística y Cultural</p>
            </div>
        </div>

        <!-- Right Side - Login Form -->
        <div class="right-side">
            <div class="form-container">
                <a href="../public/index.html" class="back-link">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                    Volver al inicio
                </a>

                <div class="form-header">
                    <img src="../assets/img/3.png" alt="Amimbré" class="logo-img">
                    <h1>Bienvenido de nuevo</h1>
                    <p>Ingresa tus credenciales para acceder</p>
                </div>

                <?php if ($error_message): ?>
                <div class="alert alert-error">
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
                <?php endif; ?>

                <?php if ($success_message): ?>
                <div class="alert alert-success">
                    <?php echo htmlspecialchars($success_message); ?>
                </div>
                <?php endif; ?>

                <form method="POST" action="" id="loginForm">
                    <div class="form-group">
                        <label class="form-label" for="email">Correo electrónico</label>
                        <input class="form-input" type="email" id="email" name="email" 
                               placeholder="tu@email.com" required 
                               value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                    </div>

                    <div class="form-group">
                        <div class="label-row">
                            <label class="form-label" for="password">Contraseña</label>
                            <a href="recuperar-password.php" class="forgot-link">¿Olvidaste tu contraseña?</a>
                        </div>
                        <input class="form-input" type="password" id="password" name="password" 
                               placeholder="••••••••" required>
                    </div>

                    <div class="checkbox-group">
                        <input type="checkbox" id="remember" name="remember" class="checkbox">
                        <label for="remember" class="checkbox-label">Recordarme</label>
                    </div>

                    <button type="submit" class="btn-primary">
                        Iniciar sesión
                    </button>
                </form>

                <p class="signup-text">
                    ¿Primera vez aquí? <a href="../public/pre-inscripcion.php" class="signup-link">Preinscríbete</a>
                </p>
            </div>
        </div>
    </div>

    <script src="../assets/js/script-login.js"></script>
    
    <script>
        // Debug: verificar si el formulario se está enviando
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('loginForm');
            if (form) {
                form.addEventListener('submit', function(e) {
                    console.log('Formulario enviado');
                    // No prevenir el envío por defecto
                });
            }
        });
    </script>
</body>
</html>