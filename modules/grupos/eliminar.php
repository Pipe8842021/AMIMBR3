<?php
/**
 * Grupos – Eliminar grupo (página de confirmación)
 */
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth_check.php';
require_role('admin');

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if (!$id) { header("Location: admin.php"); exit; }

$error = null;

// Ejecutar eliminación
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['confirmar'] ?? '') === 'si') {
    try {
        // Verificar matrículas activas
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM matriculas WHERE grupo_id = ? AND estado = 'activa'");
        $stmt->execute([$id]);
        if ((int)$stmt->fetchColumn() > 0) {
            $error = "No se puede eliminar: el grupo tiene matrículas activas. Cambia su estado a <strong>Cancelado</strong>.";
        } else {
            // Eliminar registros relacionados sin matrículas activas
            $pdo->prepare("DELETE FROM horarios WHERE grupo_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM grupos   WHERE id = ?")->execute([$id]);
            header("Location: admin.php?msg=eliminado");
            exit;
        }
    } catch (PDOException $e) {
        error_log($e->getMessage());
        $error = "Error al eliminar el grupo.";
    }
}

// Cargar datos del grupo
try {
    $stmt = $pdo->prepare("
        SELECT g.*, c.nombre AS curso_nombre, u.nombre AS profesor_nombre
        FROM grupos g
        JOIN cursos c ON g.curso_id = c.id
        LEFT JOIN usuarios u ON g.profesor_id = u.id
        WHERE g.id = ?
    ");
    $stmt->execute([$id]);
    $grupo = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$grupo) { header("Location: admin.php"); exit; }

    // Conteos de dependencias
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM matriculas WHERE grupo_id = ?");
    $stmt->execute([$id]); $total_matriculas = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM matriculas WHERE grupo_id = ? AND estado = 'activa'");
    $stmt->execute([$id]); $matriculas_activas = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM bitacoras WHERE grupo_id = ?");
    $stmt->execute([$id]); $total_bitacoras = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM horarios WHERE grupo_id = ?");
    $stmt->execute([$id]); $total_horarios = (int)$stmt->fetchColumn();

} catch (PDOException $e) {
    error_log($e->getMessage());
    header("Location: admin.php"); exit;
}

$nivel_cfg = [
    'basico'     => 'badge-info',
    'intermedio' => 'badge-warning',
    'avanzado'   => 'badge-danger',
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Eliminar Grupo – Amimbré</title>
    <link rel="shortcut icon" href="../../assets/img/3.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,1,0">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="../../assets/css/colores.css">
    <link rel="stylesheet" href="../../assets/css/style-grupos.css">
    <script>(function(){ const t=localStorage.getItem('amimbre-theme'); if(t==='light') document.documentElement.setAttribute('data-theme','light'); })();</script>
</head>
<body>
<?php require_once '../../includes/header.php'; ?>
<main class="main-content">

    <div class="dashboard-header">
        <div class="dashboard-title">
            <h1>Eliminar Grupo</h1>
            <p>Esta acción no se puede deshacer</p>
        </div>
        <a href="ver.php?id=<?php echo $id; ?>" class="btn-action back">
            <span class="material-symbols-rounded">arrow_back</span> Volver al grupo
        </a>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger">
        <span class="material-symbols-rounded">error</span><?php echo $error; ?>
    </div>
    <?php endif; ?>

    <div class="card eliminar-card">

        <div class="eliminar-icon">
            <span class="material-symbols-rounded">delete_forever</span>
        </div>

        <h2>¿Eliminar este grupo?</h2>
        <p class="sub">Estás a punto de eliminar permanentemente el siguiente grupo:</p>

        <!-- Datos del grupo -->
        <div class="eliminar-detalle">
            <div class="info-row">
                <span class="label">Nombre</span>
                <strong class="value"><?php echo htmlspecialchars($grupo['nombre']); ?></strong>
            </div>
            <div class="info-row">
                <span class="label">Curso</span>
                <span class="value"><?php echo htmlspecialchars($grupo['curso_nombre']); ?></span>
            </div>
            <div class="info-row">
                <span class="label">Profesor</span>
                <span class="value"><?php echo $grupo['profesor_nombre'] ? htmlspecialchars($grupo['profesor_nombre']) : 'Sin asignar'; ?></span>
            </div>
            <div class="info-row">
                <span class="label">Estado</span>
                <span class="value"><?php echo ucfirst($grupo['estado']); ?></span>
            </div>
            <div class="info-row">
                <span class="label">Cupos</span>
                <span class="value"><?php echo $grupo['cupo_actual']; ?>/<?php echo $grupo['cupo_maximo']; ?></span>
            </div>
        </div>

        <!-- Advertencia de dependencias -->
        <?php if ($total_matriculas > 0 || $total_bitacoras > 0): ?>
        <div class="eliminar-warning">
            <span class="material-symbols-rounded">warning</span>
            <div>
                Este grupo tiene registros asociados que también se verán afectados:
                <ul style="margin-top:6px; padding-left:16px;">
                    <?php if ($total_matriculas > 0): ?>
                    <li><?php echo $total_matriculas; ?> matrícula(s) en total
                        <?php if ($matriculas_activas > 0): ?>
                        — <strong><?php echo $matriculas_activas; ?> activa(s)</strong>
                        <?php endif; ?>
                    </li>
                    <?php endif; ?>
                    <?php if ($total_bitacoras > 0): ?>
                    <li><?php echo $total_bitacoras; ?> bitácora(s) registrada(s)</li>
                    <?php endif; ?>
                    <?php if ($total_horarios > 0): ?>
                    <li><?php echo $total_horarios; ?> horario(s) configurado(s)</li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($matriculas_activas > 0): ?>
        <!-- Bloqueado -->
        <div class="alert alert-danger" style="margin-bottom:20px;">
            <span class="material-symbols-rounded">block</span>
            No se puede eliminar un grupo con matrículas activas. Primero cambia el estado del grupo a <strong>Cancelado</strong> o retira a los estudiantes.
        </div>
        <div class="form-actions" style="justify-content:center; border:none; padding:0;">
            <a href="ver.php?id=<?php echo $id; ?>" class="btn-cancel">Volver al grupo</a>
            <a href="editar.php?id=<?php echo $id; ?>" class="btn-submit">
                <span class="material-symbols-rounded">edit</span> Editar estado
            </a>
        </div>

        <?php else: ?>
        <!-- Confirmación -->
        <form method="POST">
            <input type="hidden" name="id"        value="<?php echo $id; ?>">
            <input type="hidden" name="confirmar" value="si">
            <div class="form-actions" style="justify-content:center; border:none; padding:0;">
                <a href="ver.php?id=<?php echo $id; ?>" class="btn-cancel">Cancelar</a>
                <button type="submit" class="btn-submit danger">
                    <span class="material-symbols-rounded">delete_forever</span> Sí, eliminar grupo
                </button>
            </div>
        </form>
        <?php endif; ?>

    </div>

</main>
</body>
</html>