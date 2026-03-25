<?php
/**
 * Grupos – Registro de Asistencia
 *
 * Flujo:
 *  1. El profesor llena el formulario (bitácora + asistencia por estudiante)
 *  2. Se inserta en `bitacoras`
 *  3. Se inserta en `bitacoras_asistencias` (fuente de verdad)
 *  4. Se sincroniza automáticamente a `asistencias` (usando matricula_id)
 */
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth_check.php';
require_any_role(['admin','profesor']);

$grupo_id = (int)($_GET['grupo'] ?? $_POST['grupo_id'] ?? 0);
$rol      = $_SESSION['user_rol'];
$uid      = (int)$_SESSION['user_id'];

if (!$grupo_id) { header("Location: index.php"); exit; }

$error   = null;
$success = null;

// ─── Guardar asistencia ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'guardar') {
    $titulo        = trim($_POST['titulo']        ?? '');
    $fecha_clase   = $_POST['fecha_clase']        ?? '';
    $hora_inicio   = $_POST['hora_inicio']        ?? '';
    $hora_fin      = $_POST['hora_fin']           ?? '';
    $temas         = trim($_POST['temas']         ?? '');
    $descripcion   = trim($_POST['descripcion']   ?? '');
    $observaciones = trim($_POST['observaciones'] ?? '');
    $compromisos   = trim($_POST['compromisos']   ?? '');
    $asistencias_post = $_POST['asistencia']      ?? [];   // [estudiante_id => estado]
    $observaciones_est= $_POST['obs_est']         ?? [];   // [estudiante_id => texto]

    if (!$titulo || !$fecha_clase || !$hora_inicio || !$hora_fin || !$temas) {
        $error = "Completa los campos obligatorios de la bitácora.";
    } elseif (empty($asistencias_post)) {
        $error = "Debe registrar la asistencia de al menos un estudiante.";
    } else {
        try {
            $pdo->beginTransaction();

            // 1. Obtener curso_id y verificar profesor
            $stmt = $pdo->prepare("SELECT curso_id, profesor_id FROM grupos WHERE id = ?");
            $stmt->execute([$grupo_id]);
            $g = $stmt->fetch(PDO::FETCH_ASSOC);

            $profesor_id = ($rol === 'profesor') ? $uid : (int)$g['profesor_id'];

            // 2. Insertar bitácora
            $stmt = $pdo->prepare("
                INSERT INTO bitacoras
                    (grupo_id, curso_id, profesor_id, titulo, fecha_clase,
                     hora_inicio, hora_fin, temas_tratados, descripcion_clase,
                     observaciones, compromisos_proxima_clase)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $grupo_id, $g['curso_id'], $profesor_id,
                $titulo, $fecha_clase, $hora_inicio, $hora_fin,
                $temas, $descripcion,
                $observaciones ?: null,
                $compromisos   ?: null
            ]);
            $bitacora_id = $pdo->lastInsertId();

            // Obtener matriculas del grupo para sync
            $stmt = $pdo->prepare("
                SELECT estudiante_id, id AS matricula_id
                FROM matriculas
                WHERE grupo_id = ? AND estado = 'activa'
            ");
            $stmt->execute([$grupo_id]);
            $matriculas_map = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
                $matriculas_map[$m['estudiante_id']] = $m['matricula_id'];
            }

            $stmt_ba = $pdo->prepare("
                INSERT INTO bitacoras_asistencias (bitacora_id, estudiante_id, estado, observacion)
                VALUES (?, ?, ?, ?)
            ");
            $stmt_as = $pdo->prepare("
                INSERT INTO asistencias (matricula_id, fecha, estado, observaciones, registrado_por)
                VALUES (?, ?, ?, ?, ?)
            ");

            foreach ($asistencias_post as $est_id => $estado_asi) {
                $est_id    = (int)$est_id;
                $obs_est   = trim($observaciones_est[$est_id] ?? '');
                $estados_v = ['presente','ausente','justificado','tardanza'];
                if (!in_array($estado_asi, $estados_v)) $estado_asi = 'ausente';

                // 3. bitacoras_asistencias
                $stmt_ba->execute([$bitacora_id, $est_id, $estado_asi, $obs_est ?: null]);

                // 4. Sincronizar a asistencias si hay matrícula
                if (isset($matriculas_map[$est_id])) {
                    $stmt_as->execute([
                        $matriculas_map[$est_id],
                        $fecha_clase,
                        $estado_asi,
                        $obs_est ?: null,
                        $uid
                    ]);
                }
            }

            $pdo->commit();

            // 5. Guardar evidencias fotográficas (fuera de la transacción principal)
            if (!empty($_FILES['evidencias']['name'][0])) {
                $upload_dir = '../../assets/uploads/bitacoras/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }

                $tipos_validos  = ['image/jpeg', 'image/png', 'image/webp'];
                $max_size       = 5 * 1024 * 1024; // 5MB
                $total_archivos = min(count($_FILES['evidencias']['name']), 5);

                $stmt_ev = $pdo->prepare("
                    INSERT INTO bitacoras_evidencias
                        (bitacora_id, nombre_archivo, ruta_archivo, descripcion, orden)
                    VALUES (?, ?, ?, ?, ?)
                ");

                for ($i = 0; $i < $total_archivos; $i++) {
                    $archivo  = $_FILES['evidencias'];
                    if ($archivo['error'][$i] !== UPLOAD_ERR_OK) continue;
                    if ($archivo['size'][$i]  > $max_size)       continue;
                    if (!in_array($archivo['type'][$i], $tipos_validos)) continue;

                    $ext          = pathinfo($archivo['name'][$i], PATHINFO_EXTENSION);
                    $nombre_unico = 'ev_' . $bitacora_id . '_' . $i . '_' . time() . '.' . strtolower($ext);
                    $ruta_destino = $upload_dir . $nombre_unico;
                    $ruta_db      = 'assets/uploads/bitacoras/' . $nombre_unico;
                    $descripcion_ev = trim($_POST['ev_desc'][$i] ?? '');

                    if (move_uploaded_file($archivo['tmp_name'][$i], $ruta_destino)) {
                        $stmt_ev->execute([
                            $bitacora_id,
                            $archivo['name'][$i],
                            $ruta_db,
                            $descripcion_ev ?: null,
                            $i
                        ]);
                    }
                }
            }

            $success = "Asistencia registrada correctamente. Bitácora <strong>#$bitacora_id</strong> creada.";

        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log($e->getMessage());
            $error = "Error al guardar la asistencia: " . $e->getMessage();
        }
    }
}

// ─── Cargar datos del grupo y estudiantes ────────────────────────────────────
try {
    $stmt = $pdo->prepare("
        SELECT g.*, c.nombre AS curso_nombre, c.nivel AS curso_nivel,
               u.nombre AS profesor_nombre
        FROM grupos g
        JOIN cursos c ON g.curso_id = c.id
        LEFT JOIN usuarios u ON g.profesor_id = u.id
        WHERE g.id = ?
    ");
    $stmt->execute([$grupo_id]);
    $grupo = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$grupo) { header("Location: index.php"); exit; }

    // Solo el profesor del grupo o el admin
    if ($rol === 'profesor' && $grupo['profesor_id'] != $uid) {
        header("Location: index.php"); exit;
    }

    // Estudiantes activos del grupo
    $stmt = $pdo->prepare("
        SELECT u.id, u.nombre, u.email, m.id AS matricula_id
        FROM matriculas m
        JOIN usuarios u ON m.estudiante_id = u.id
        WHERE m.grupo_id = ? AND m.estado = 'activa'
        ORDER BY u.nombre
    ");
    $stmt->execute([$grupo_id]);
    $estudiantes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Historial reciente de asistencia
    $stmt = $pdo->prepare("
        SELECT b.id, b.titulo, b.fecha_clase,
               COUNT(ba.id) AS total,
               SUM(CASE WHEN ba.estado='presente' THEN 1 ELSE 0 END) AS presentes
        FROM bitacoras b
        LEFT JOIN bitacoras_asistencias ba ON ba.bitacora_id = b.id
        WHERE b.grupo_id = ?
        GROUP BY b.id
        ORDER BY b.fecha_clase DESC
        LIMIT 5
    ");
    $stmt->execute([$grupo_id]);
    $historial = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log($e->getMessage());
    header("Location: index.php"); exit;
}

$nivel_cfg = ['basico'=>'badge-info','intermedio'=>'badge-warning','avanzado'=>'badge-danger'];

function iniciales_a($nombre) {
    return implode('', array_map(fn($p) => strtoupper($p[0]), array_slice(explode(' ', $nombre), 0, 2)));
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrar Asistencia – <?php echo htmlspecialchars($grupo['nombre']); ?></title>
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
            <h1>Registrar Asistencia</h1>
            <p><?php echo htmlspecialchars($grupo['nombre']); ?> · <?php echo htmlspecialchars($grupo['curso_nombre']); ?></p>
        </div>
        <a href="ver.php?id=<?php echo $grupo_id; ?>" class="btn-action back">
            <span class="material-symbols-rounded">arrow_back</span> Volver al grupo
        </a>
    </div>

    <?php if ($success): ?>
    <div class="alert alert-success">
        <span class="material-symbols-rounded">check_circle</span><?php echo $success; ?>
    </div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert alert-danger">
        <span class="material-symbols-rounded">error</span><?php echo $error; ?>
    </div>
    <?php endif; ?>

    <?php if (count($estudiantes) === 0): ?>
    <div class="card">
        <div class="empty-state">
            <span class="material-symbols-rounded">person_off</span>
            <p>No hay estudiantes matriculados en este grupo.</p>
            <a href="ver.php?id=<?php echo $grupo_id; ?>" style="color:var(--primary-green); font-size:0.85rem; margin-top:8px;">Volver al grupo</a>
        </div>
    </div>
    <?php else: ?>

    <form method="POST" id="form-asistencia" enctype="multipart/form-data">
        <input type="hidden" name="action"   value="guardar">
        <input type="hidden" name="grupo_id" value="<?php echo $grupo_id; ?>">

        <div class="asistencia-grid">

            <!-- Columna izquierda: datos de la bitácora -->
            <div class="card">
                <div class="form-card-header">
                    <span class="material-symbols-rounded">edit_note</span>
                    <div>
                        <h3>Datos de la Clase</h3>
                        <p>Esta información se guardará como bitácora</p>
                    </div>
                </div>

                <div class="modulo-form">
                    <div class="input-group">
                        <label>Título de la clase <span class="req">*</span></label>
                        <input type="text" name="titulo" required
                               placeholder="Ej: Introducción a los acordes">
                    </div>

                    <div class="form-row form-row--3">
                        <div class="input-group">
                            <label>Fecha <span class="req">*</span></label>
                            <input type="date" name="fecha_clase" required
                                   value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="input-group">
                            <label>Hora inicio <span class="req">*</span></label>
                            <input type="time" name="hora_inicio" required>
                        </div>
                        <div class="input-group">
                            <label>Hora fin <span class="req">*</span></label>
                            <input type="time" name="hora_fin" required>
                        </div>
                    </div>

                    <div class="input-group">
                        <label>Temas tratados <span class="req">*</span></label>
                        <textarea name="temas" required placeholder="Temas vistos en la clase..."></textarea>
                    </div>

                    <div class="input-group">
                        <label>Descripción de la clase</label>
                        <textarea name="descripcion" placeholder="Descripción general de la sesión..."></textarea>
                    </div>

                    <div class="input-group">
                        <label>Observaciones</label>
                        <input type="text" name="observaciones" placeholder="Observaciones generales (opcional)">
                    </div>

                    <div class="input-group">
                        <label>Compromisos / Tarea para la próxima clase</label>
                        <input type="text" name="compromisos" placeholder="Tareas o compromisos (opcional)">
                    </div>

                    <!-- Evidencias fotográficas -->
                    <div class="evidencias-section">
                        <div class="evidencias-header">
                            <span class="material-symbols-rounded">photo_camera</span>
                            <div>
                                <span class="evidencias-title">Evidencias fotográficas</span>
                                <span class="evidencias-sub">Máx. 5 imágenes · JPG, PNG, WEBP · 5MB c/u</span>
                            </div>
                        </div>

                        <div class="evidencias-dropzone" id="dropzone">
                            <span class="material-symbols-rounded">cloud_upload</span>
                            <p>Arrastra imágenes aquí o <strong>haz clic para seleccionar</strong></p>
                            <input type="file" name="evidencias[]" id="evidencias-input"
                                   accept="image/jpeg,image/png,image/webp"
                                   multiple style="display:none;">
                        </div>

                        <!-- Vista previa -->
                        <div class="evidencias-preview" id="evidencias-preview"></div>

                        <!-- Descripciones (se generan dinámicamente por JS) -->
                        <div id="evidencias-descripciones"></div>
                    </div>
                </div>
            </div>

            <!-- Columna derecha: lista de estudiantes con selector -->
            <div class="card">
                <div class="form-card-header">
                    <span class="material-symbols-rounded">fact_check</span>
                    <div>
                        <h3>Lista de Asistencia</h3>
                        <p><?php echo count($estudiantes); ?> estudiante<?php echo count($estudiantes) != 1 ? 's' : ''; ?></p>
                    </div>
                </div>

                <!-- Resumen dinámico -->
                <div class="asistencia-resumen">
                    <div class="asist-chip presente">
                        <span class="material-symbols-rounded">check_circle</span>
                        <span class="asist-count" id="cnt-presente">0</span> Presentes
                    </div>
                    <div class="asist-chip ausente">
                        <span class="material-symbols-rounded">cancel</span>
                        <span class="asist-count" id="cnt-ausente">0</span> Ausentes
                    </div>
                    <div class="asist-chip justificado">
                        <span class="material-symbols-rounded">description</span>
                        <span class="asist-count" id="cnt-justificado">0</span> Justif.
                    </div>
                    <div class="asist-chip tardanza">
                        <span class="material-symbols-rounded">schedule</span>
                        <span class="asist-count" id="cnt-tardanza">0</span> Tardanza
                    </div>
                </div>

                <!-- Acciones masivas -->
                <div style="display:flex; gap:8px; margin-bottom:16px; flex-wrap:wrap;">
                    <button type="button" class="btn-cancel" style="font-size:0.78rem; padding:6px 12px;"
                            onclick="marcarTodos('presente')">
                        <span class="material-symbols-rounded" style="font-size:14px;">done_all</span> Todos presentes
                    </button>
                    <button type="button" class="btn-cancel" style="font-size:0.78rem; padding:6px 12px;"
                            onclick="marcarTodos('ausente')">
                        <span class="material-symbols-rounded" style="font-size:14px;">remove_done</span> Todos ausentes
                    </button>
                </div>

                <!-- Lista de estudiantes -->
                <div style="display:flex; flex-direction:column; gap:10px;">
                    <?php foreach ($estudiantes as $est): ?>
                    <div class="asistencia-estudiante-row" data-id="<?php echo $est['id']; ?>">
                        <div class="est-avatar"><?php echo iniciales_a($est['nombre']); ?></div>
                        <div class="est-info">
                            <div class="est-nombre"><?php echo htmlspecialchars($est['nombre']); ?></div>
                            <input type="text" name="obs_est[<?php echo $est['id']; ?>]"
                                   class="obs-input" placeholder="Observación (opcional)...">
                        </div>

                        <!-- Hidden input del estado -->
                        <input type="hidden"
                               name="asistencia[<?php echo $est['id']; ?>]"
                               id="estado-<?php echo $est['id']; ?>"
                               value="ausente">

                        <!-- Botones visuales -->
                        <div class="asistencia-selector">
                            <button type="button" class="asist-btn" data-estado="presente" data-id="<?php echo $est['id']; ?>"
                                    title="Presente">
                                <span class="material-symbols-rounded">check_circle</span>
                            </button>
                            <button type="button" class="asist-btn" data-estado="ausente" data-id="<?php echo $est['id']; ?>"
                                    title="Ausente">
                                <span class="material-symbols-rounded">cancel</span>
                            </button>
                            <button type="button" class="asist-btn" data-estado="justificado" data-id="<?php echo $est['id']; ?>"
                                    title="Justificado">
                                <span class="material-symbols-rounded">description</span>
                            </button>
                            <button type="button" class="asist-btn" data-estado="tardanza" data-id="<?php echo $est['id']; ?>"
                                    title="Tardanza">
                                <span class="material-symbols-rounded">schedule</span>
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="form-actions" style="margin-top:20px;">
                    <a href="ver.php?id=<?php echo $grupo_id; ?>" class="btn-cancel">Cancelar</a>
                    <button type="submit" class="btn-submit">
                        <span class="material-symbols-rounded">save</span> Guardar asistencia
                    </button>
                </div>
            </div>

        </div><!-- /asistencia-grid -->

    </form>

    <!-- Historial reciente -->
    <?php if (count($historial) > 0): ?>
    <div class="card" style="margin-top:20px;">
        <div class="section-header">
            <div>
                <h3 class="section-title">Historial Reciente</h3>
                <p class="section-subtitle">Últimas 5 clases registradas</p>
            </div>
        </div>
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Título</th>
                        <th>Fecha</th>
                        <th>Registros</th>
                        <th>Presentes</th>
                        <th>% Asistencia</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($historial as $h):
                        $pct_h = $h['total'] > 0 ? round(($h['presentes'] / $h['total']) * 100) : 0;
                        $cls_h = $pct_h >= 75 ? 'positive' : 'negative';
                    ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($h['titulo']); ?></strong></td>
                        <td><?php echo date('d/m/Y', strtotime($h['fecha_clase'])); ?></td>
                        <td><?php echo $h['total']; ?></td>
                        <td><?php echo $h['presentes']; ?></td>
                        <td>
                            <span class="stat-change <?php echo $cls_h; ?>" style="font-weight:700;">
                                <?php echo $pct_h; ?>%
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; // fin if estudiantes ?>

</main>

<script>
// ── Selector de estado de asistencia ────────────────────────────────────────
document.querySelectorAll('.asist-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const id     = btn.dataset.id;
        const estado = btn.dataset.estado;

        // Limpiar activos de la misma fila
        document.querySelectorAll(`.asist-btn[data-id="${id}"]`).forEach(b => b.classList.remove('active'));
        btn.classList.add('active');

        // Actualizar input hidden
        document.getElementById(`estado-${id}`).value = estado;

        // Mostrar/ocultar observación
        const obs = document.querySelector(`.asistencia-estudiante-row[data-id="${id}"] .obs-input`);
        if (obs) obs.classList.toggle('visible', estado !== 'presente');

        actualizarContadores();
    });
});

// ── Marcar todos ─────────────────────────────────────────────────────────────
function marcarTodos(estado) {
    document.querySelectorAll('.asist-btn').forEach(btn => {
        if (btn.dataset.estado === estado) {
            btn.click();
        }
    });
}

// ── Contadores dinámicos ─────────────────────────────────────────────────────
function actualizarContadores() {
    const estados = ['presente','ausente','justificado','tardanza'];
    estados.forEach(e => {
        const n = document.querySelectorAll(`.asist-btn[data-estado="${e}"].active`).length;
        const el = document.getElementById(`cnt-${e}`);
        if (el) el.textContent = n;
    });
}

// Auto-ocultar alertas
setTimeout(() => {
    document.querySelectorAll('.alert').forEach(a => {
        a.style.transition = 'opacity 0.5s ease';
        a.style.opacity = '0';
        setTimeout(() => a.remove(), 500);
    });
}, 5000);

// Inicializar con todos en "ausente" por defecto al cargar
document.querySelectorAll('.asist-btn[data-estado="ausente"]').forEach(btn => {
    btn.classList.add('active');
});
actualizarContadores();

// ── Evidencias fotográficas ──────────────────────────────────────────────────
(function () {
    const dropzone   = document.getElementById('dropzone');
    const fileInput  = document.getElementById('evidencias-input');
    const preview    = document.getElementById('evidencias-preview');
    const descWrap   = document.getElementById('evidencias-descripciones');
    const MAX_FILES  = 5;
    let archivos     = [];  // DataTransfer para mantener la lista de File

    if (!dropzone) return;

    // Abrir selector al hacer clic en la zona
    dropzone.addEventListener('click', () => fileInput.click());

    // Drag & drop
    dropzone.addEventListener('dragover', e => { e.preventDefault(); dropzone.classList.add('drag-over'); });
    dropzone.addEventListener('dragleave', ()  => dropzone.classList.remove('drag-over'));
    dropzone.addEventListener('drop', e => {
        e.preventDefault();
        dropzone.classList.remove('drag-over');
        agregarArchivos(e.dataTransfer.files);
    });

    // Selección por input
    fileInput.addEventListener('change', () => {
        agregarArchivos(fileInput.files);
        fileInput.value = ''; // reset para permitir re-seleccionar el mismo archivo
    });

    function agregarArchivos(nuevos) {
        const tiposValidos = ['image/jpeg', 'image/png', 'image/webp'];
        for (const f of nuevos) {
            if (archivos.length >= MAX_FILES) break;
            if (!tiposValidos.includes(f.type)) continue;
            if (f.size > 5 * 1024 * 1024) continue;  // 5MB
            archivos.push(f);
        }
        renderizar();
        sincronizarInput();
    }

    function renderizar() {
        preview.innerHTML  = '';
        descWrap.innerHTML = '';

        archivos.forEach((f, i) => {
            // Thumb
            const thumb = document.createElement('div');
            thumb.className = 'ev-thumb';

            const img = document.createElement('img');
            img.src = URL.createObjectURL(f);
            img.onload = () => URL.revokeObjectURL(img.src);

            const orden = document.createElement('span');
            orden.className = 'ev-orden';
            orden.textContent = `#${i + 1}`;

            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'ev-remove';
            btn.title = 'Quitar';
            btn.innerHTML = '<span class="material-symbols-rounded">close</span>';
            btn.addEventListener('click', () => {
                archivos.splice(i, 1);
                renderizar();
                sincronizarInput();
            });

            thumb.appendChild(img);
            thumb.appendChild(orden);
            thumb.appendChild(btn);
            preview.appendChild(thumb);

            // Input descripción
            const group = document.createElement('div');
            group.className = 'ev-desc-group';
            const lbl = document.createElement('label');
            lbl.textContent = `Descripción imagen #${i + 1} (${f.name})`;
            const inp = document.createElement('input');
            inp.type = 'text';
            inp.name = `ev_desc[${i}]`;
            inp.placeholder = 'Descripción opcional de la imagen...';
            group.appendChild(lbl);
            group.appendChild(inp);
            descWrap.appendChild(group);
        });

        // Actualizar contador en la dropzone
        const hint = dropzone.querySelector('p');
        if (archivos.length > 0) {
            hint.innerHTML = `<strong>${archivos.length}/${MAX_FILES}</strong> imagen${archivos.length > 1 ? 'es' : ''} seleccionada${archivos.length > 1 ? 's' : ''}`;
        } else {
            hint.innerHTML = 'Arrastra imágenes aquí o <strong>haz clic para seleccionar</strong>';
        }
    }

    function sincronizarInput() {
        // Reconstruir el input file con la lista actual via DataTransfer
        const dt = new DataTransfer();
        archivos.forEach(f => dt.items.add(f));
        fileInput.files = dt.files;
    }
})();

</script>
</body>
</html>v