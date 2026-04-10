<?php
// Primero incluir la configuración de sesiones
require_once '../config/session.php';
// Luego incluir la base de datos
require_once '../config/database.php';

$login_status = '';   // 'success' | 'error' | ''
$login_msg    = '';

// Verificar si ya hay sesión activa (ANTES de procesar el POST)
if (is_logged_in()) {
    $rol = $_SESSION['user_rol'] ?? 'estudiante';
    switch ($rol) {
        case 'admin':     header("Location: ../modules/dashboard/admin.php");    break;
        case 'profesor':  header("Location: ../modules/dashboard/profesor.php"); break;
        case 'estudiante':header("Location: ../modules/dashboard/estudiante.php");break;
        default:          header("Location: ../modules/dashboard/index.php");
    }
    exit;
}

// Procesar el formulario si se envía
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email    = filter_var($_POST['email']    ?? '', FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);

    error_log("POST recibido en login.php — Email: $email");

    if (empty($email) || empty($password)) {

        $login_status = 'error';
        $login_msg    = 'Por favor, completa todos los campos antes de continuar.';
        error_log("Login fallido: campos vacíos");

    } else {
        try {
            $stmt = $pdo->prepare("SELECT id, nombre, email, password, rol, estado FROM usuarios WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user) {
                error_log("Usuario encontrado: {$user['nombre']} — Rol: {$user['rol']} — Estado: {$user['estado']}");

                if ($user['estado'] !== 'activo') {

                    $login_status = 'error';
                    $login_msg    = 'Tu cuenta está ' . htmlspecialchars($user['estado']) . '. Contacta al administrador.';
                    error_log("Login fallido: cuenta {$user['estado']}");

                } elseif (password_verify($password, $user['password'])) {

                    error_log("Login exitoso para: $email");

                    // Guardar sesión
                    $_SESSION['user_id']     = $user['id'];
                    $_SESSION['user_nombre'] = $user['nombre'];
                    $_SESSION['user_email']  = $user['email'];
                    $_SESSION['user_rol']    = $user['rol'];

                    // Cookie "Recordarme"
                    if ($remember) {
                        $token = bin2hex(random_bytes(32));
                        setcookie('remember_token', $token, time() + (86400 * 30), '/', '', false, true);
                        $stmt = $pdo->prepare("UPDATE usuarios SET remember_token = ? WHERE id = ?");
                        $stmt->execute([$token, $user['id']]);
                    }

                    // Log de acceso
                    try {
                        $stmt = $pdo->prepare("INSERT INTO logs_acceso (usuario_id, ip_address, user_agent) VALUES (?, ?, ?)");
                        $stmt->execute([
                            $user['id'],
                            $_SERVER['REMOTE_ADDR']     ?? 'unknown',
                            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                        ]);
                    } catch (PDOException $e) {
                        error_log("Error al registrar log de acceso: " . $e->getMessage());
                    }

                    // Estado de éxito para la modal
                    $nombre_corto = explode(' ', trim($user['nombre']))[0];
                    $login_status = 'success';
                    $login_msg    = "¡Hola, $nombre_corto! Tu sesión ha sido iniciada correctamente.";

                    // Determinar URL de destino según rol
                    switch ($user['rol']) {
                        case 'admin':     $redirect = '../modules/dashboard/admin.php';    break;
                        case 'profesor':  $redirect = '../modules/dashboard/profesor.php'; break;
                        case 'estudiante':$redirect = '../modules/dashboard/estudiante.php';break;
                        default:          $redirect = '../modules/dashboard/index.php';
                    }

                } else {

                    error_log("Login fallido: contraseña incorrecta para $email");

                    if (substr($user['password'], 0, 4) !== '$2y$' && substr($user['password'], 0, 4) !== '$2a$') {
                        $login_status = 'error';
                        $login_msg    = 'Error de configuración del servidor. Contacta al administrador (Error: HASH_FORMAT).';
                    } else {
                        $login_status = 'error';
                        $login_msg    = 'La contraseña ingresada no es correcta. Verifica e inténtalo de nuevo.';
                    }
                }

            } else {
                error_log("Login fallido: usuario no encontrado — $email");
                $login_status = 'error';
                $login_msg    = 'No encontramos una cuenta con ese correo electrónico.';
            }

        } catch (PDOException $e) {
            error_log("Error en login: " . $e->getMessage());
            $login_status = 'error';
            $login_msg    = 'Error en el sistema. Por favor, intenta más tarde.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión — Amimbré</title>
    <link rel="shortcut icon" href="../assets/img/3.png">
    <script>
        (function() {
            const theme = localStorage.getItem('amimbre-theme');
            if (theme === 'light') {
                document.documentElement.setAttribute('data-theme', 'light');
            }
        })();
    </script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
    <link rel="stylesheet" href="../assets/css/colores.css">
    <link rel="stylesheet" href="../assets/css/style-login.css">
</head>

<?php
/* Pasar estado al JS de forma segura mediante data-attributes en <body> */
$data_status = htmlspecialchars($login_status, ENT_QUOTES, 'UTF-8');
$data_msg    = htmlspecialchars($login_msg,    ENT_QUOTES, 'UTF-8');

/* Si el login fue exitoso, también pasar la URL de destino */
$data_redirect = '';
if ($login_status === 'success' && isset($redirect)) {
    $data_redirect = htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8');
}
?>
<body
    data-login-status="<?= $data_status ?>"
    data-login-msg="<?= $data_msg ?>"
    data-login-redirect="<?= $data_redirect ?>"
>
    <div class="container">

        <!-- ── LADO IZQUIERDO — 3D ───────────────────── -->
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

        <!-- ── LADO DERECHO — FORMULARIO ─────────────── -->
        <div class="right-side">
            <div class="form-container">

                <a href="../public/index.html" class="back-link">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                    </svg>
                    Volver al inicio
                </a>

                <div class="form-header">
                    <img src="../assets/img/3.png" alt="Amimbré" class="logo-img">
                    <h1>Bienvenido de nuevo</h1>
                    <p>Ingresa tus credenciales para acceder</p>
                </div>

                <form method="POST" action="" id="loginForm" novalidate>
                    <div class="form-group">
                        <label class="form-label" for="email">Correo electrónico</label>
                        <input class="form-input" type="email" id="email" name="email"
                               placeholder="tu@email.com" required
                               value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>">
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
                    ¿Primera vez aquí?
                    <a href="../public/pre-inscripcion.php" class="signup-link">Preinscríbete</a>
                </p>

            </div>
        </div>
    </div>

    <script src="../assets/js/script-login.js"></script>
</body>
</html>