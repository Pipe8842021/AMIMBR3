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

    <div class="calendar-container">
        <div class="calendar-header">
            <div class="calendar-nav">
                <button class="btn-nav"><i class="fas fa-chevron-left"></i></button>
                <h2>Febrero 2026</h2>
                <button class="btn-nav"><i class="fas fa-chevron-right"></i></button>
            </div>
            <button class="btn-new-event"><i class="fas fa-plus"></i> Nuevo Evento</button>
        </div>

        <div class="calendar-grid">
            <div class="day-name">Lun</div>
            <div class="day-name">Mar</div>
            <div class="day-name">Mié</div>
            <div class="day-name">Jue</div>
            <div class="day-name">Vie</div>
            <div class="day-name">Sáb</div>
            <div class="day-name">Dom</div>

            <div class="day empty"></div>
            <div class="day empty"></div>
            <div class="day empty"></div>
            <div class="day empty"></div>
            <div class="day">1</div>
            <div class="day">2</div>
            <div class="day">3</div>
            <div class="day">4</div>
            <div class="day">5</div>
            <div class="day current-day">6 <span class="dot"></span></div>
            <div class="day">7</div>
            <div class="day">8</div>
            <div class="day">9</div>
            <div class="day event-orange">10 <div class="event-tag">Examen Bajo</div>
            </div>
            <div class="day">11</div>
            <div class="day">12</div>
            <div class="day selected">13</div>
            <div class="day event-green">14 <div class="event-tag">Recital</div>
            </div>
            <div class="day">15</div>
        </div>
    </div>
</body>

</html>