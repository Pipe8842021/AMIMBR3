<?php

require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth_check.php';
require_any_role(['admin', 'profesor', 'estudiante']);

$rol           = $_SESSION['user_rol'] ?? '';
$user_id       = (int)($_SESSION['user_id'] ?? 0);
$es_admin      = ($rol === 'admin');
$es_profesor   = ($rol === 'profesor');
$es_estudiante = ($rol === 'estudiante');

$curso_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($curso_id <= 0) {
    header("Location: index.php?error=curso_no_encontrado");
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT
            c.*,
            COUNT(DISTINCT g.id) as total_grupos,
            COUNT(DISTINCT CASE WHEN m.estado = 'activa' THEN m.estudiante_id END) as estudiantes_matriculados
        FROM cursos c
        LEFT JOIN grupos g ON c.id = g.curso_id AND g.estado = 'activo'
        LEFT JOIN matriculas m ON g.id = m.grupo_id AND m.estado = 'activa'
        WHERE c.id = ?
        GROUP BY c.id
    ");
    $stmt->execute([$curso_id]);
    $curso = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$curso) {
        header("Location: index.php?error=curso_no_encontrado");
        exit;
    }

    if ($es_estudiante) {
        $stmt = $pdo->prepare("
            SELECT
                g.*,
                u.nombre as profesor_nombre,
                COUNT(DISTINCT m2.estudiante_id) as estudiantes_inscritos
            FROM grupos g
            INNER JOIN matriculas m ON g.id = m.grupo_id
                AND m.estudiante_id = ? AND m.estado = 'activa'
            LEFT JOIN usuarios u ON g.profesor_id = u.id
            LEFT JOIN matriculas m2 ON g.id = m2.grupo_id AND m2.estado = 'activa'
            WHERE g.curso_id = ?
            GROUP BY g.id
            ORDER BY g.nombre
        ");
        $stmt->execute([$user_id, $curso_id]);
    } elseif ($rol === 'profesor') {
        $stmt = $pdo->prepare("
            SELECT
                g.*,
                u.nombre as profesor_nombre,
                COUNT(DISTINCT m.estudiante_id) as estudiantes_inscritos
            FROM grupos g
            LEFT JOIN usuarios u ON g.profesor_id = u.id
            LEFT JOIN matriculas m ON g.id = m.grupo_id AND m.estado = 'activa'
            WHERE g.curso_id = ?
            AND g.profesor_id = ?
            GROUP BY g.id
            ORDER BY g.estado DESC, g.nombre
        ");
        $stmt->execute([$curso_id, $user_id]);
    } else {
        $stmt = $pdo->prepare("
            SELECT
                g.*,
                u.nombre as profesor_nombre,
                COUNT(DISTINCT m.estudiante_id) as estudiantes_inscritos
            FROM grupos g
            LEFT JOIN usuarios u ON g.profesor_id = u.id
            LEFT JOIN matriculas m ON g.id = m.grupo_id AND m.estado = 'activa'
            WHERE g.curso_id = ?
            GROUP BY g.id
            ORDER BY g.estado DESC, g.nombre
        ");
        $stmt->execute([$curso_id]);
    }
    $grupos = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Error al obtener curso: " . $e->getMessage());
    header("Location: index.php?error=error_sistema");
    exit;
}

if (!empty($_GET['ajax'])) {
    function get_imagen_curso_json(string $nombre, ?string $imagenBD): string {
        $rutaBase   = '../../assets/img/cursos/';
        $porDefecto = $rutaBase . 'musica-default.jpg';
        if (!empty($imagenBD)) {
            return strpos($imagenBD, '/') !== false ? $imagenBD : $rutaBase . $imagenBD;
        }
        $map = [
            'Piano'                       => 'piano.jpg',
            'Piano Clásico'               => 'piano.jpg',
            'Guitarra'                    => 'guitarra.jpg',
            'Guitarra Acústica'           => 'guitarra.jpg',
            'Canto y Técnica Vocal'       => 'canto.jpg',
            'Técnica Vocal y Canto'       => 'canto.jpg',
            'Violín'                      => 'violin.jpg',
            'Violín Clásico'              => 'violin.jpg',
            'Ensambles Musicales'         => 'ensambles.jpg',
            'Ensamble musical'            => 'ensambles.jpg',
            'Iniciación Musical Infantil' => 'iniciacion_infantil.jpg',
            'Instrumentos de Viento'      => 'viento.jpg',
            'Preparación Universitaria'   => 'preparacion_universitaria.jpg',
            'Teoría y Lenguaje Musical'   => 'teoria_lenguaje.jpg',
        ];
        return isset($map[$nombre]) ? $rutaBase . $map[$nombre] : $porDefecto;
    }

    $nivel_map = [
        'basico'     => ['texto' => 'Básico',     'clase' => 'nivel-basico'],
        'intermedio' => ['texto' => 'Intermedio', 'clase' => 'nivel-intermedio'],
        'avanzado'   => ['texto' => 'Avanzado',   'clase' => 'nivel-avanzado'],
    ];

    header('Content-Type: application/json');
    echo json_encode([
        'curso'         => $curso,
        'grupos'        => $grupos,
        'imagen_src'    => get_imagen_curso_json($curso['nombre'], $curso['imagen']),
        'nivel_badge'   => $nivel_map[$curso['nivel']] ?? ['texto' => 'Sin nivel', 'clase' => ''],
        'es_admin'      => $es_admin,
        'es_profesor'   => $es_profesor,
        'es_estudiante' => $es_estudiante,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

header("Location: index.php");
exit;
