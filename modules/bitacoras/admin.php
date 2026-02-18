<?php
// Simulación de datos
$bitacoras = [
    [
        'id' => 1,
        'fecha' => '2023-10-26',
        'hora_inicio' => '10:00',
        'hora_fin' => '11:00',
        'ficha_curso' => 'C-001',
        'nombre_curso' => 'Introducción a PHP',
        'edad_curso' => 'Adultos',
        'temas_clase' => 'Variables, Tipos de datos',
        'objetivo_clase' => 'Entender los fundamentos de PHP',
        'tareas_pendientes' => 'Instalar XAMPP',
        'observaciones' => 'Buena participación general.'
    ],
    [
        'id' => 2,
        'fecha' => '2023-10-27',
        'hora_inicio' => '09:00',
        'hora_fin' => '10:30',
        'ficha_curso' => 'J-005',
        'nombre_curso' => 'Diseño Web Básico',
        'edad_curso' => 'Jóvenes',
        'temas_clase' => 'HTML Estructura, CSS Introducción',
        'objetivo_clase' => 'Crear una página HTML simple',
        'tareas_pendientes' => 'Elegir tema para proyecto',
        'observaciones' => 'Algunos alumnos necesitan refuerzo en CSS.'
    ]
];
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Bitácoras de Clase</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="shortcut icon" href="../../assets/img/3.png">

    <link rel="stylesheet" href="../../assets/css/colores.css">

    <link rel="stylesheet" href="../../assets/css/style-bitacoraAdmin.css">


</head>

<body>

    <!-- Sidebar compartido -->
    <?php require_once '../../includes/header.php'; ?>

    <!-- Contenido principal -->
    <main class="main-content">

        <div class="dashboard-header">
            <div class="dashboard-title">
                <h1 class="">Archivos Institucionales
                </h1>
                <p>Documentos de interés para la comunidad educativa</p>
            </div>
            <button class="btn-new-bitacora"><i class="fas fa-plus"></i> Nueva Bitácora</button>
        </div>

        <?php if (empty($bitacoras)): ?>
            <div class="alert alert-error">No hay bitácoras disponibles.</div>
        <?php else: ?>
            <div class="bitacoras-list">
                <?php foreach ($bitacoras as $bitacora): ?>
                    <div class="bitacora-card">
                        <h3 class="bitacora-card-title"><?php echo htmlspecialchars($bitacora['nombre_curso']); ?></h3>

                        <div class="bitacora-card-meta">
                            <span><i class="far fa-calendar-alt icon"></i> <?php echo $bitacora['fecha']; ?></span>
                            <span style="margin-left:15px"><i class="far fa-clock icon"></i> <?php echo $bitacora['hora_inicio']; ?></span>
                        </div>

                        <div class="bitacora-card-content">
                            <p><strong>Objetivo:</strong> <?php echo $bitacora['objetivo_clase']; ?></p>
                            <p><strong>Temas:</strong> <?php echo $bitacora['temas_clase']; ?></p>
                        </div>

                        <div class="bitacora-card-actions">
                            <a href="#" class="btn btn-view" title="Ver"><i class="fas fa-eye"></i></a>
                            <a href="#" class="btn btn-edit" title="Editar"><i class="fas fa-edit"></i></a>
                            <a href="#" class="btn btn-delete" title="Eliminar"><i class="fas fa-trash"></i></a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </main>
    <script src="../../assets/js/script-bitacoras.js"></script>


</body>

</html>