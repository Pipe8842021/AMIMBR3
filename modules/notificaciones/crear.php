<?php
/**
 * Crear Nueva Notificación
 * Formulario para crear notificaciones (solo admin)
 * Versión simplificada - Sin APIs externas
 */

require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth_check.php';

// Solo administradores pueden crear notificaciones
require_role('admin');

// Obtener datos del usuario actual
try {
    $stmt = $pdo->prepare("
        SELECT id, nombre, email, rol, estado, foto_perfil 
        FROM usuarios 
        WHERE id = ? AND estado = 'activo'
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        session_destroy();
        header("Location: ../../auth/login.php?error=usuario_no_encontrado");
        exit;
    }
} catch (PDOException $e) {
    error_log("Error al obtener datos de usuario: " . $e->getMessage());
    die("Error del sistema.");
}

// Obtener lista de usuarios para destinatarios
try {
    $stmt = $pdo->query("
        SELECT id, nombre, email, rol 
        FROM usuarios 
        WHERE estado = 'activo' 
        ORDER BY nombre ASC
    ");
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error al obtener usuarios: " . $e->getMessage());
    $usuarios = [];
}

// Procesar formulario
$errores = [];
$exito = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $destinatarios = isset($_POST['destinatarios']) ? $_POST['destinatarios'] : [];
    $enviar_todos = isset($_POST['enviar_todos']) ? true : false;
    $tipo = $_POST['tipo'] ?? '';
    $titulo = trim($_POST['titulo'] ?? '');
    $mensaje = trim($_POST['mensaje'] ?? '');
    $enlace = trim($_POST['enlace'] ?? '');
    $prioridad = $_POST['prioridad'] ?? 'normal';
    
    // Validaciones
    if (empty($tipo)) {
        $errores[] = "Debes seleccionar un tipo de notificación";
    }
    
    if (empty($titulo)) {
        $errores[] = "El título es obligatorio";
    }
    
    if (empty($mensaje)) {
        $errores[] = "El mensaje es obligatorio";
    }
    
    if (!$enviar_todos && empty($destinatarios)) {
        $errores[] = "Debes seleccionar al menos un destinatario o marcar 'Enviar a todos'";
    }
    
    if (empty($errores)) {
        try {
            $pdo->beginTransaction();
            
            // Si es "enviar a todos", obtener todos los usuarios
            if ($enviar_todos) {
                $stmt = $pdo->query("SELECT id FROM usuarios WHERE estado = 'activo'");
                $destinatarios = $stmt->fetchAll(PDO::FETCH_COLUMN);
            }
            
            // Insertar notificación para cada destinatario
            $stmt = $pdo->prepare("
                INSERT INTO notificaciones (
                    usuario_id, tipo, titulo, mensaje, enlace, 
                    prioridad, emisor, fecha_creacion
                ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $total_enviadas = 0;
            foreach ($destinatarios as $usuario_id) {
                $stmt->execute([
                    $usuario_id,
                    $tipo,
                    $titulo,
                    $mensaje,
                    $enlace ?: null,
                    $prioridad,
                    $user['nombre']
                ]);
                $total_enviadas++;
            }
            
            $pdo->commit();
            $exito = true;
            
            // Redirigir después de 2 segundos
            header("refresh:2;url=index.php");
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("Error al crear notificaciones: " . $e->getMessage());
            $errores[] = "Error al crear las notificaciones. Por favor, intenta de nuevo.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nueva Notificación - Amimbré</title>
    <link rel="shortcut icon" href="../../assets/img/3.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0" />
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="../../assets/css/colores.css">
    <link rel="stylesheet" href="../../assets/css/style-notificaciones.css">
    <script>
        (function() {
            const theme = localStorage.getItem('amimbre-theme');
            if (theme === 'light') {
                document.documentElement.setAttribute('data-theme', 'light');
            }
        })();
    </script>
    <style>
        .form-container {
            background: var(--dark-bg);
            border-radius: 12px;
            padding: 30px;
            border: 1px solid var(--border-color);
            max-width: 800px;
            margin: 0 auto;
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-group label {
            display: block;
            color: var(--text-primary);
            font-size: 0.95rem;
            font-weight: 500;
            margin-bottom: 10px;
        }

        .form-group label .required {
            color: #ef4444;
            margin-left: 4px;
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            background: var(--hover-bg);
            color: var(--text-primary);
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-blue);
            background: var(--card-bg);
        }

        textarea.form-control {
            min-height: 120px;
            resize: vertical;
            font-family: 'Poppins', sans-serif;
        }

        select.form-control {
            cursor: pointer;
        }

        .checkbox-list {
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 15px;
            background: var(--hover-bg);
        }

        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .checkbox-item:hover {
            background: var(--card-bg);
        }

        .checkbox-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .checkbox-item label {
            margin: 0 !important;
            cursor: pointer;
            flex: 1;
        }

        .user-role {
            font-size: 0.8rem;
            color: var(--text-secondary);
            margin-left: auto;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .alert-success {
            background: var(--subtle-green);
            color: var(--primary-green);
            border: 1px solid var(--primary-green);
        }

        .alert-danger {
            background: rgba(239, 68, 68, 0.2);
            color: #ef4444;
            border: 1px solid #ef4444;
        }

        .form-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 30px;
        }

        .btn-cancel {
            padding: 12px 24px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            color: var(--text-primary);
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }

        .btn-cancel:hover {
            border-color: var(--primary-blue);
        }

        .btn-submit {
            padding: 12px 24px;
            border-radius: 10px;
            border: none;
            background: var(--primary-orange);
            color: var(--text-primary);
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-submit:hover {
            background: var(--gradient-primary-orange);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .btn-submit:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .checkbox-all {
            padding: 12px;
            background: var(--card-bg);
            border-radius: 8px;
            margin-bottom: 10px;
            border: 1px solid var(--border-color);
        }

        .form-hint {
            font-size: 0.85rem;
            color: var(--text-secondary);
            margin-top: 5px;
        }
    </style>
</head>
<body>
    <?php 
    if (file_exists('../../includes/header.php')) {
        require_once '../../includes/header.php'; 
    }
    ?>

    <main class="main-content">
        <div class="notifications-header">
            <div class="header-content">
                <div class="header-left">
                    <button class="back-button" onclick="location.href='index.php'">
                        <span class="material-symbols-rounded">arrow_back</span>
                    </button>
                    <div class="header-title">
                        <h1>Nueva Notificación</h1>
                        <p>Enviar notificación a usuarios del sistema</p>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($exito): ?>
        <div class="alert alert-success">
            <span class="material-symbols-rounded">check_circle</span>
            <div>
                <strong>¡Notificaciones enviadas correctamente!</strong><br>
                Se enviaron <?php echo $total_enviadas ?? 0; ?> notificaciones. Redirigiendo...
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($errores)): ?>
        <div class="alert alert-danger">
            <span class="material-symbols-rounded">error</span>
            <div>
                <strong>Errores encontrados:</strong><br>
                <?php foreach ($errores as $error): ?>
                    • <?php echo htmlspecialchars($error); ?><br>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <form method="POST" class="form-container" id="formNotificacion">
            <div class="form-group">
                <label>
                    Tipo de Notificación<span class="required">*</span>
                </label>
                <select name="tipo" class="form-control" required>
                    <option value="">Seleccionar tipo...</option>
                    <option value="sistema" <?php echo (isset($_POST['tipo']) && $_POST['tipo'] === 'sistema') ? 'selected' : ''; ?>>Sistema</option>
                    <option value="preinscripcion" <?php echo (isset($_POST['tipo']) && $_POST['tipo'] === 'preinscripcion') ? 'selected' : ''; ?>>Preinscripción</option>
                    <option value="evento" <?php echo (isset($_POST['tipo']) && $_POST['tipo'] === 'evento') ? 'selected' : ''; ?>>Evento</option>
                    <option value="general" <?php echo (isset($_POST['tipo']) && $_POST['tipo'] === 'general') ? 'selected' : ''; ?>>General</option>
                </select>
                <div class="form-hint">Define el tipo de notificación para facilitar la organización</div>
            </div>

            <div class="form-group">
                <label>
                    Título<span class="required">*</span>
                </label>
                <input type="text" name="titulo" class="form-control" required 
                       placeholder="Ejemplo: Nueva actualización del sistema"
                       value="<?php echo htmlspecialchars($_POST['titulo'] ?? ''); ?>">
                <div class="form-hint">Título corto y descriptivo</div>
            </div>

            <div class="form-group">
                <label>
                    Mensaje<span class="required">*</span>
                </label>
                <textarea name="mensaje" class="form-control" required 
                          placeholder="Escribe el contenido de la notificación..."><?php echo htmlspecialchars($_POST['mensaje'] ?? ''); ?></textarea>
                <div class="form-hint">Mensaje detallado de la notificación</div>
            </div>

            <div class="form-group">
                <label>
                    Enlace (Opcional)
                </label>
                <input type="text" name="enlace" class="form-control" 
                       placeholder="/AMIMBR3/modules/..."
                       value="<?php echo htmlspecialchars($_POST['enlace'] ?? ''); ?>">
                <div class="form-hint">URL interna donde dirigir al usuario al hacer clic</div>
            </div>

            <div class="form-group">
                <label>
                    Prioridad<span class="required">*</span>
                </label>
                <select name="prioridad" class="form-control" required>
                    <option value="normal" <?php echo (!isset($_POST['prioridad']) || $_POST['prioridad'] === 'normal') ? 'selected' : ''; ?>>Normal</option>
                    <option value="alta" <?php echo (isset($_POST['prioridad']) && $_POST['prioridad'] === 'alta') ? 'selected' : ''; ?>>Alta</option>
                    <option value="baja" <?php echo (isset($_POST['prioridad']) && $_POST['prioridad'] === 'baja') ? 'selected' : ''; ?>>Baja</option>
                </select>
                <div class="form-hint">Las notificaciones de prioridad alta se destacan visualmente</div>
            </div>

            <div class="form-group">
                <label>
                    Destinatarios<span class="required">*</span>
                </label>
                <div class="checkbox-all">
                    <div class="checkbox-item">
                        <input type="checkbox" id="enviar_todos" name="enviar_todos" 
                               onchange="toggleDestinatarios(this)"
                               <?php echo isset($_POST['enviar_todos']) ? 'checked' : ''; ?>>
                        <label for="enviar_todos"><strong>Enviar a todos los usuarios</strong></label>
                    </div>
                </div>
                <div class="checkbox-list" id="listaDestinatarios">
                    <?php foreach ($usuarios as $usuario): ?>
                    <div class="checkbox-item">
                        <input type="checkbox" name="destinatarios[]" 
                               value="<?php echo $usuario['id']; ?>" 
                               id="user_<?php echo $usuario['id']; ?>"
                               <?php echo (isset($_POST['destinatarios']) && in_array($usuario['id'], $_POST['destinatarios'])) ? 'checked' : ''; ?>>
                        <label for="user_<?php echo $usuario['id']; ?>">
                            <?php echo htmlspecialchars($usuario['nombre']); ?>
                        </label>
                        <span class="user-role"><?php echo ucfirst($usuario['rol']); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="form-hint">Selecciona los usuarios que recibirán esta notificación</div>
            </div>

            <div class="form-actions">
                <a href="index.php" class="btn-cancel">Cancelar</a>
                <button type="submit" class="btn-submit" id="btnEnviar">
                    Enviar Notificación
                </button>
            </div>
        </form>
    </main>

    <script>
        function toggleDestinatarios(checkbox) {
            const lista = document.getElementById('listaDestinatarios');
            const checkboxes = lista.querySelectorAll('input[type="checkbox"]');
            
            if (checkbox.checked) {
                lista.style.opacity = '0.5';
                lista.style.pointerEvents = 'none';
                checkboxes.forEach(cb => cb.checked = false);
            } else {
                lista.style.opacity = '1';
                lista.style.pointerEvents = 'auto';
            }
        }

        // Inicializar estado si viene de un error
        window.addEventListener('load', function() {
            const enviarTodos = document.getElementById('enviar_todos');
            if (enviarTodos.checked) {
                toggleDestinatarios(enviarTodos);
            }
        });

        // Prevenir doble envío
        document.getElementById('formNotificacion').addEventListener('submit', function() {
            const btnEnviar = document.getElementById('btnEnviar');
            btnEnviar.disabled = true;
            btnEnviar.textContent = 'Enviando...';
        });
    </script>
</body>
</html>