<?php
/**
 * Nueva Matrícula
 * Permite al admin crear una matrícula manualmente para un estudiante existente
 */

require_once '../../../config/session.php';
require_once '../../../config/database.php';
require_once '../../../includes/auth_check.php';
require_role('admin');

try {
    $stmt = $pdo->prepare("SELECT id, nombre FROM usuarios WHERE id = ? AND estado = 'activo'");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user) { session_destroy(); header("Location: ../../../auth/login.php"); exit; }
} catch (PDOException $e) { die("Error del sistema."); }

// Manejar POST
$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $estudiante_id = (int)($_POST['estudiante_id'] ?? 0);
    $grupo_id      = (int)($_POST['grupo_id'] ?? 0) ?: null;
    $fecha_inicio  = $_POST['fecha_inicio'] ?? date('Y-m-d');
    $observaciones = trim($_POST['observaciones'] ?? '');

    if (!$estudiante_id) {
        $error = 'Debes seleccionar un estudiante.';
    } else {
        try {
            // Verificar que no tenga ya matrícula activa en ese grupo
            if ($grupo_id) {
                $stmt = $pdo->prepare("SELECT id FROM matriculas WHERE estudiante_id = ? AND grupo_id = ? AND estado = 'activa'");
                $stmt->execute([$estudiante_id, $grupo_id]);
                if ($stmt->fetch()) {
                    $error = 'Este estudiante ya tiene una matrícula activa en ese grupo.';
                }
            }

            if (!$error) {
                // Verificar cupo si hay grupo
                if ($grupo_id) {
                    $stmt = $pdo->prepare("SELECT cupo_actual, cupo_maximo FROM grupos WHERE id = ?");
                    $stmt->execute([$grupo_id]);
                    $g = $stmt->fetch();
                    if ($g && $g['cupo_actual'] >= $g['cupo_maximo']) {
                        $error = 'El grupo seleccionado no tiene cupo disponible.';
                    }
                }
            }

            if (!$error) {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    INSERT INTO matriculas (estudiante_id, grupo_id, fecha_matricula, fecha_inicio, estado, observaciones)
                    VALUES (?, ?, CURDATE(), ?, 'activa', ?)
                ");
                $stmt->execute([$estudiante_id, $grupo_id, $fecha_inicio ?: null, $observaciones ?: null]);
                $nueva_id = $pdo->lastInsertId();

                if ($grupo_id) {
                    $pdo->prepare("UPDATE grupos SET cupo_actual = cupo_actual + 1 WHERE id = ?")->execute([$grupo_id]);
                }

                $pdo->prepare("INSERT INTO logs_actividad (usuario_id, accion, detalles, ip_address) VALUES (?,?,?,?)")
                    ->execute([$_SESSION['user_id'], 'matricula_creada', "Nueva matrícula #$nueva_id — Estudiante ID $estudiante_id", $_SERVER['REMOTE_ADDR'] ?? null]);

                $pdo->commit();
                header("Location: detalle.php?id=$nueva_id&msg=" . urlencode('Matrícula creada correctamente') . "&type=success");
                exit;
            }

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log($e->getMessage());
            $error = 'Error del sistema al crear la matrícula.';
        }
    }
}

try {
    // Estudiantes sin matrícula activa
    $stmt = $pdo->query("
        SELECT u.id, u.nombre, u.email, u.documento
        FROM usuarios u
        WHERE u.rol = 'estudiante' AND u.estado = 'activo'
        ORDER BY u.nombre
    ");
    $estudiantes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Grupos disponibles
    $stmt = $pdo->query("
        SELECT g.id, g.nombre, g.horario, g.cupo_actual, g.cupo_maximo,
               c.nombre as curso_nombre, c.id as curso_id,
               u.nombre as profesor_nombre
        FROM grupos g
        INNER JOIN cursos c ON g.curso_id = c.id
        LEFT JOIN usuarios u ON g.profesor_id = u.id
        WHERE g.estado IN ('activo','planificado')
        AND g.cupo_actual < g.cupo_maximo
        ORDER BY c.nombre, g.nombre
    ");
    $grupos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Agrupar por curso
    $grupos_por_curso = [];
    foreach ($grupos as $g) {
        $grupos_por_curso[$g['curso_nombre']][] = $g;
    }
} catch (PDOException $e) {
    $estudiantes = [];
    $grupos_por_curso = [];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nueva Matrícula - Amimbré</title>
    <link rel="shortcut icon" href="../../../assets/img/3.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0" />
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="../../../assets/css/colores.css">
    <link rel="stylesheet" href="../../../assets/css/style-matriculas.css">
</head>
<body>
    <?php require_once '../../../includes/header.php'; ?>

    <main class="main-content">
        <div class="dashboard-header">
            <div class="dashboard-title">
                <a href="index.php" class="back-link">
                    <span class="material-symbols-rounded">arrow_back</span>
                    Matrículas
                </a>
                <h1>Nueva Matrícula</h1>
                <p>Registra manualmente una nueva matrícula para un estudiante</p>
            </div>
        </div>

        <?php if ($error): ?>
        <div class="alert alert-danger">
            <span class="material-symbols-rounded">error</span>
            <?php echo htmlspecialchars($error); ?>
        </div>
        <?php endif; ?>

        <div class="form-page-container">
            <div class="card">
                <div class="card-header">
                    <span class="material-symbols-rounded card-header-icon">person_add</span>
                    <h3>Datos de la matrícula</h3>
                </div>
                <div class="card-body">
                    <form method="POST" class="form-nueva-matricula" id="formNueva">
                        <div class="form-group">
                            <label class="form-label">Estudiante *</label>
                            <div class="select-search-wrap">
                                <input type="text" id="buscarEstudiante" class="form-control"
                                       placeholder="Buscar por nombre, email o documento..."
                                       autocomplete="off">
                                <select name="estudiante_id" id="selectEstudiante" class="form-control mt-8" required size="5" style="height:auto;">
                                    <option value="">— Busca y selecciona un estudiante —</option>
                                    <?php foreach($estudiantes as $e): ?>
                                        <option value="<?php echo $e['id']; ?>"
                                                data-texto="<?php echo strtolower($e['nombre'] . ' ' . $e['email'] . ' ' . $e['documento']); ?>">
                                            <?php echo htmlspecialchars($e['nombre']); ?> — <?php echo htmlspecialchars($e['email']); ?> (<?php echo htmlspecialchars($e['documento']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Grupo (opcional)</label>
                                <select name="grupo_id" class="form-control">
                                    <option value="">— Sin grupo asignado —</option>
                                    <?php foreach($grupos_por_curso as $curso_nombre => $gs): ?>
                                        <optgroup label="<?php echo htmlspecialchars($curso_nombre); ?>">
                                            <?php foreach($gs as $g): ?>
                                                <option value="<?php echo $g['id']; ?>">
                                                    <?php echo htmlspecialchars($g['nombre']); ?>
                                                    — <?php echo htmlspecialchars($g['horario']); ?>
                                                    (<?php echo $g['cupo_actual']; ?>/<?php echo $g['cupo_maximo']; ?> cupos)
                                                    · <?php echo htmlspecialchars($g['profesor_nombre'] ?? ''); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endforeach; ?>
                                </select>
                                <span class="form-hint">Puedes asignarlo después desde el detalle</span>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Fecha de inicio</label>
                                <input type="date" name="fecha_inicio" class="form-control"
                                       value="<?php echo date('Y-m-d'); ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Observaciones</label>
                            <textarea name="observaciones" class="form-control" rows="3"
                                      placeholder="Información adicional sobre la matrícula..."></textarea>
                        </div>

                        <div class="form-actions">
                            <a href="index.php" class="btn-secondary">Cancelar</a>
                            <button type="submit" class="btn-primary">
                                <span class="material-symbols-rounded">save</span>
                                Crear matrícula
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </main>

    <script>
        // Filtro de búsqueda en el select de estudiantes
        document.getElementById('buscarEstudiante').addEventListener('input', function() {
            const query = this.value.toLowerCase();
            const select = document.getElementById('selectEstudiante');
            const options = select.querySelectorAll('option');
            options.forEach(opt => {
                if (!opt.value) return;
                const texto = opt.dataset.texto || '';
                opt.style.display = texto.includes(query) ? '' : 'none';
            });
        });
    </script>
</body>
</html>