<?php
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth_check.php';

$mensaje = "";
$tipo_alerta = "";
$grupos = [];

// 1. PROCESAR CREACIÓN DE GRUPO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'crear') {

    // Recolectamos datos con el operador ?? para evitar warnings si falta algún campo
    $curso_id     = $_POST['curso_id'] ?? null;
    $profesor_id  = !empty($_POST['profesor_id']) ? $_POST['profesor_id'] : null;
    $nombre       = trim($_POST['nombre'] ?? '');
    $horario      = trim($_POST['horario'] ?? '');
    $aula         = trim($_POST['aula'] ?? '');
    $cupo_maximo  = (int)($_POST['cupo_maximo'] ?? 0);
    $fecha_inicio = $_POST['fecha_inicio'] ?? null;
    $fecha_fin    = $_POST['fecha_fin'] ?? null;
    $estado       = $_POST['estado'] ?? 'activo';

    if ($curso_id && $nombre) {
        try {
            // Preparamos la consulta según la estructura de tu tabla grupos
            $sql = "INSERT INTO grupos (curso_id, profesor_id, nombre, horario, aula, cupo_actual, cupo_maximo, fecha_inicio, fecha_fin, estado) 
                    VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $curso_id,
                $profesor_id,
                $nombre,
                $horario,
                $aula,
                $cupo_maximo,
                $fecha_inicio,
                $fecha_fin,
                $estado
            ]);

            $mensaje = "¡Grupo '$nombre' creado exitosamente!";
            $tipo_alerta = "success";
        } catch (PDOException $e) {
            $mensaje = "Error al guardar en la base de datos: " . $e->getMessage();
            $tipo_alerta = "error";
        }
    } else {
        $mensaje = "Faltan campos obligatorios (Curso y Nombre).";
        $tipo_alerta = "error";
    }
}

// 2. OBTENER DATOS PARA SELECTS Y TABLA
try {
    $cursos = $pdo->query("SELECT id, nombre FROM cursos WHERE estado = 'activo' ORDER BY nombre ASC")->fetchAll();
    $profesores = $pdo->query("SELECT id, nombre FROM usuarios WHERE rol = 'profesor' AND estado = 'activo' ORDER BY nombre ASC")->fetchAll();

    $query = "SELECT g.*, c.nombre as curso_nombre, u.nombre as profesor_nombre 
              FROM grupos g 
              JOIN cursos c ON g.curso_id = c.id 
              LEFT JOIN usuarios u ON g.profesor_id = u.id
              ORDER BY g.id DESC";
    $grupos = $pdo->query($query)->fetchAll();
} catch (PDOException $e) {
    $mensaje = "Error de consulta: " . $e->getMessage();
    $tipo_alerta = "error";
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Gestión de Grupos - Amimbré</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/colores.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background: var(--hover-bg);
            color: var(--text-primary);
        }

        .main-content {
            margin-left: 270px;
            padding: 30px;
            transition: 0.3s;
        }

        .grid-container {
            display: grid;
            grid-template-columns: 380px 1fr;
            gap: 20px;
        }

        .card {
            background: var(--dark-bg);
            border-radius: 12px;
            padding: 20px;
            border: 1px solid var(--border-color);
            height: fit-content;
        }

        .form-group {
            margin-bottom: 12px;
        }

        .form-group label {
            display: block;
            font-size: 0.8rem;
            color: var(--text-secondary);
            margin-bottom: 4px;
        }

        .input-field {
            width: 100%;
            padding: 8px 12px;
            background: var(--hover-bg);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            color: white;
            font-size: 0.9rem;
            border: 1px solid var(--border-color);
        }

        .row-flex {
            display: flex;
            gap: 10px;
        }

        .btn-save {
            width: 100%;
            padding: 12px;
            background: var(--primary-green);
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            margin-top: 10px;
            transition: 0.2s;
        }

        .btn-save:hover {
            opacity: 0.9;
        }

        .table-container {
            background: var(--dark-bg);
            border-radius: 12px;
            padding: 20px;
            border: 1px solid var(--border-color);
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            font-size: 0.85rem;
        }

        th {
            text-align: left;
            color: var(--text-secondary);
            padding: 12px;
            border-bottom: 2px solid var(--border-color);
        }

        td {
            padding: 12px;
            border-bottom: 1px solid var(--border-color);
        }

        .badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: bold;
        }

        .badge-active {
            background: rgba(76, 175, 80, 0.2);
            color: #4caf50;
        }

        .badge-inactive {
            background: rgba(255, 82, 82, 0.2);
            color: #ff5252;
        }

        .text-info {
            color: var(--primary-blue);
            font-size: 0.75rem;
        }

        @media (max-width: 1100px) {
            .main-content {
                margin-left: 0;
            }

            .grid-container {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <?php include_once '../../includes/header.php'; ?>

    <main class="main-content">
        <h1><i class="fas fa-users-class"></i> Configuración de Grupos</h1>

        <?php if ($mensaje): ?>
            <div style="padding:15px; margin: 20px 0; border-radius:8px; border:1px solid; <?php echo $tipo_alerta === 'success' ? 'background:rgba(76,175,80,0.1); color:var(--primary-green);' : 'background:rgba(255,82,82,0.1); color:#ff5252;'; ?>">
                <i class="fas <?php echo $tipo_alerta === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i> <?php echo $mensaje; ?>
            </div>
        <?php endif; ?>

        <div class="grid-container">
            <div class="card">
                <h3><i class="fas fa-plus-circle"></i> Crear Nuevo Grupo</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="crear">
                    <div class="form-group">
                        <label>Curso</label>
                        <select name="curso_id" class="input-field" required>
                            <option value="">Seleccione un curso</option>
                            <?php foreach ($cursos as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Nombre del Grupo</label>
                        <input type="text" name="nombre" class="input-field" placeholder="Ej: Grupo A" required>
                    </div>
                    <div class="form-group">
                        <label>Profesor Asignado</label>
                        <select name="profesor_id" class="input-field">
                            <option value="">Sin asignar</option>
                            <?php foreach ($profesores as $p): ?>
                                <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Horario</label>
                        <input type="text" name="horario" class="input-field" placeholder="Lunes 4pm" required>
                    </div>
                    <div class="row-flex">
                        <div class="form-group" style="flex:1">
                            <label>Aula</label>
                            <input type="text" name="aula" class="input-field">
                        </div>
                        <div class="form-group" style="flex:1">
                            <label>Cupo Máx.</label>
                            <input type="number" name="cupo_maximo" class="input-field" value="15" required>
                        </div>
                    </div>
                    <div class="row-flex">
                        <div class="form-group" style="flex:1">
                            <label>Inicio</label>
                            <input type="date" name="fecha_inicio" class="input-field" required>
                        </div>
                        <div class="form-group" style="flex:1">
                            <label>Fin</label>
                            <input type="date" name="fecha_fin" class="input-field" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Estado</label>
                        <select name="estado" class="input-field">
                            <option value="activo">Activo</option>
                            <option value="inactivo">Inactivo</option>
                        </select>
                    </div>
                    <button type="submit" class="btn-save">Registrar Grupo</button>
                </form>
            </div>

            <div class="table-container">
                <h3>Grupos Configurados</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Grupo / Curso</th>
                            <th>Profesor</th>
                            <th>Horario / Aula</th>
                            <th>Cupos</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($grupos)): ?>
                            <?php foreach ($grupos as $g): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($g['nombre']) ?></strong><br>
                                        <span class="text-info"><?= htmlspecialchars($g['curso_nombre']) ?></span>
                                    </td>
                                    <td><?= htmlspecialchars($g['profesor_nombre'] ?? 'No asignado') ?></td>
                                    <td>
                                        <?= htmlspecialchars($g['horario']) ?><br>
                                        <small><?= htmlspecialchars($g['aula']) ?></small>
                                    </td>
                                    <td><?= $g['cupo_actual'] ?> / <?= $g['cupo_maximo'] ?></td>
                                    <td>
                                        <span class="badge <?= $g['estado'] === 'activo' ? 'badge-active' : 'badge-inactive' ?>">
                                            <?= strtoupper($g['estado']) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" style="text-align:center;">No hay grupos registrados.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</body>

</html>