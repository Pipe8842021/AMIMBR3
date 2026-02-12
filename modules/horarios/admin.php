<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0" />
    <link rel="shortcut icon" href="../../assets/img/3.png">
    <link rel="stylesheet" href="../../assets/css/colores.css">
    <link rel="stylesheet" href="../../assets/css/style-bitacoraAdmin.css">

    <title>Document</title>
    <style>
        .icono-libro {
            color: #F19E39;
        }
    </style>

</head>

<body>
    <?php
    include_once '../../includes/header.php' ?>
    <main class="main-content">
        <div>
            <h1>Horarios y calendario</h1>
            <p>gestiona los horarios y eventos académicos</p>
        </div>

        <div class="bitacoras-list">
            <div class="bitacora-card" style="background-color: var(--card-bg);">
                <div>
                    <span class="material-symbols-rounded icono-libro">book</span>
                    <span>clases esta semana</span>
                    <h2>14</h2>
                </div>
                </li>
            </div>
            <div class="bitacora-card" style="background-color: var(--card-bg);">
                <div>
                    <span class="material-symbols-rounded icono-libro">book</span>
                    <span>Eventos especiales</span>
                    <h2>0</h2>
                </div>
            </div>
            <div class="bitacora-card" style="background-color: var(--card-bg);">
                <div>
                    <span class="material-symbols-rounded icono-libro">book</span>
                    <span>Proximo evento</span>
                    <h2>clase de bajo</h2>
                </div>
            </div>
    </main>

</body>

</html>