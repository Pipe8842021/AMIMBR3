<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once '../includes/procesar_preinscripcion.php';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preinscripción — Escuela de Música Amimbré</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=DM+Serif+Display:ital@0;1&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="../assets/css/colores.css">
    <link rel="stylesheet" href="../assets/css/style-preInscripcion.css">
</head>
<body>

    <header>
        <div class="logo-section">
            <img src="../assets/img/1.jpg" alt="Logo Amimbré">
            <a href="index.html"><h1>Amimbré</h1></a>
        </div>
        <div class="header-actions">
            <a href="../auth/login.php" class="login-btn">Iniciar Sesión</a>
        </div>
    </header>

    <div class="hero-band">
        <div class="hero-content">
            <span class="hero-eyebrow">Escuela de Música Amimbré</span>
            <h2 class="hero-title">Hoja de Vida &amp; Preinscripción</h2>
            <p class="hero-sub">Completa el formulario con tus datos para reservar tu cupo. Los campos marcados con <span class="req-star">*</span> son obligatorios.</p>
        </div>
        <div class="hero-deco" aria-hidden="true">
            <span class="note">♩</span><span class="note">♪</span><span class="note">♫</span><span class="note">♬</span>
        </div>
    </div>

    <?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
        ¡Tu preinscripción fue enviada con éxito! Nos comunicaremos contigo pronto.
    </div>
    <?php elseif (isset($_GET['error'])): ?>
    <div class="alert alert-error">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        Ocurrió un error al procesar tu solicitud. Por favor intenta nuevamente.
    </div>
    <?php endif; ?>

    <div class="container">
        <form id="preregistrationForm" method="POST" action="pre-inscripcion.php" novalidate>

            <!-- ── 01 PROGRAMA ────────────────────────────────── -->
            <div class="form-section">
                <div class="section-header">
                    <span class="section-number">01</span>
                    <div>
                        <h3 class="section-title">Información del Programa</h3>
                        <p class="section-desc">Selecciona el programa y horario de tu preferencia</p>
                    </div>
                </div>
                <div class="fields-grid">
                    <div class="form-group">
                        <label for="programa">Programa <span class="req-star">*</span></label>
                        <select id="programa" name="programa" required>
                            <option value="">— Selecciona un programa —</option>
                            <option value="Iniciación Musical Infantil">Iniciación Musical Infantil</option>
                            <option value="Guitarra">Guitarra</option>
                            <option value="Piano">Piano</option>
                            <option value="Instrumentos de Viento">Instrumentos de Viento</option>
                            <option value="Técnica Vocal y Canto">Técnica Vocal y Canto</option>
                            <option value="Teoría y Lenguaje Musical">Teoría y Lenguaje Musical</option>
                            <option value="Ensambles Musicales">Ensambles Musicales</option>
                            <option value="Preparación Universitaria">Preparación Universitaria</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="taller">Taller específico</label>
                        <input type="text" id="taller" name="taller" placeholder="Ej: Guitarra clásica, Saxofón…">
                    </div>
                    <div class="form-group">
                        <label for="fecha_inicio">Fecha de inicio preferida</label>
                        <input type="date" id="fecha_inicio" name="fecha_inicio">
                    </div>
                    <div class="form-group">
                        <label for="dia_clase">Día(s) preferido(s)</label>
                        <input type="text" id="dia_clase" name="dia_clase" placeholder="Ej: Lunes y miércoles">
                    </div>
                    <div class="form-group">
                        <label for="hora_clase">Hora preferida</label>
                        <input type="time" id="hora_clase" name="hora_clase">
                    </div>
                </div>
            </div>

            <!-- ── 02 DATOS DEL ESTUDIANTE ─────────────────────── -->
            <div class="form-section">
                <div class="section-header">
                    <span class="section-number">02</span>
                    <div>
                        <h3 class="section-title">Datos del Estudiante</h3>
                        <p class="section-desc">Información personal del aspirante</p>
                    </div>
                </div>
                <div class="fields-grid">
                    <div class="form-group col-full">
                        <label for="nombres_apellidos">Nombres y apellidos completos <span class="req-star">*</span></label>
                        <input type="text" id="nombres_apellidos" name="nombres_apellidos" required placeholder="Tal como aparecen en el documento">
                    </div>
                    <div class="form-group">
                        <label for="tipo_documento">Tipo de documento <span class="req-star">*</span></label>
                        <select id="tipo_documento" name="tipo_documento" required>
                            <option value="">— Selecciona —</option>
                            <option value="TI">Tarjeta de Identidad (TI)</option>
                            <option value="CC">Cédula de Ciudadanía (CC)</option>
                            <option value="CE">Cédula de Extranjería (CE)</option>
                            <option value="PA">Pasaporte (PA)</option>
                            <option value="RC">Registro Civil (RC)</option>
                            <option value="OTRO">Otro</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="numero_documento">Número de documento <span class="req-star">*</span></label>
                        <input type="text" id="numero_documento" name="numero_documento" required placeholder="Sin puntos ni guiones">
                    </div>
                    <div class="form-group">
                        <label for="fecha_nacimiento">Fecha de nacimiento</label>
                        <input type="date" id="fecha_nacimiento" name="fecha_nacimiento">
                    </div>
                    <div class="form-group">
                        <label for="edad">Edad</label>
                        <input type="number" id="edad" name="edad" min="1" max="120" placeholder="Se calcula automáticamente">
                    </div>
                    <div class="form-group">
                        <label for="lugar_nacimiento">Lugar de nacimiento</label>
                        <input type="text" id="lugar_nacimiento" name="lugar_nacimiento" placeholder="Ciudad o municipio">
                    </div>
                    <div class="form-group">
                        <label for="email">Correo electrónico <span class="req-star">*</span></label>
                        <input type="email" id="email" name="email" required placeholder="correo@ejemplo.com">
                    </div>
                    <div class="form-group">
                        <label for="celular">Celular <span class="req-star">*</span></label>
                        <input type="tel" id="celular" name="celular" required placeholder="3XX XXX XXXX">
                    </div>
                    <div class="form-group">
                        <label for="direccion">Dirección</label>
                        <input type="text" id="direccion" name="direccion" placeholder="Calle / Carrera / Avenida…">
                    </div>
                    <div class="form-group">
                        <label for="barrio">Barrio</label>
                        <input type="text" id="barrio" name="barrio">
                    </div>
                    <div class="form-group">
                        <label for="municipio">Municipio</label>
                        <input type="text" id="municipio" name="municipio" placeholder="Ej: El Carmen de Viboral">
                    </div>
                    <div class="form-group">
                        <label for="zona">Zona</label>
                        <select id="zona" name="zona">
                            <option value="">— Selecciona —</option>
                            <option value="Urbana">Urbana</option>
                            <option value="Rural">Rural</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="eps">EPS</label>
                        <input type="text" id="eps" name="eps" placeholder="Nombre de la EPS">
                    </div>
                    <div class="form-group">
                        <label for="nivel_sisben">Nivel de SISBEN</label>
                        <input type="text" id="nivel_sisben" name="nivel_sisben" placeholder="Ej: A1, B2…">
                    </div>
                    <div class="form-group">
                        <label for="estrato">Estrato</label>
                        <select id="estrato" name="estrato">
                            <option value="">— Selecciona —</option>
                            <option value="1">1</option>
                            <option value="2">2</option>
                            <option value="3">3</option>
                            <option value="4">4</option>
                            <option value="5">5</option>
                            <option value="6">6</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="ocupacion">Ocupación</label>
                        <input type="text" id="ocupacion" name="ocupacion" placeholder="Ej: Estudiante, Empleado…">
                    </div>
                </div>

                <!-- Nivel educativo -->
                <div class="subsection">
                    <p class="subsection-title">Nivel educativo alcanzado</p>
                    <div class="checks-grid">
                        <label class="check-card">
                            <input type="checkbox" name="estudio_primaria" value="1">
                            <span class="check-box"></span>
                            <span class="check-label">Primaria</span>
                        </label>
                        <label class="check-card">
                            <input type="checkbox" name="estudio_secundaria" value="1">
                            <span class="check-box"></span>
                            <span class="check-label">Secundaria</span>
                        </label>
                        <label class="check-card">
                            <input type="checkbox" name="estudio_tecnico" value="1">
                            <span class="check-box"></span>
                            <span class="check-label">Técnico</span>
                        </label>
                        <label class="check-card">
                            <input type="checkbox" name="estudio_tecnologico" value="1">
                            <span class="check-box"></span>
                            <span class="check-label">Tecnológico</span>
                        </label>
                        <label class="check-card">
                            <input type="checkbox" name="estudio_universitario" value="1">
                            <span class="check-box"></span>
                            <span class="check-label">Universitario</span>
                        </label>
                    </div>
                    <div class="fields-grid" style="margin-top:14px;">
                        <div class="form-group">
                            <label for="estudio_otro">Otros estudios</label>
                            <input type="text" id="estudio_otro" name="estudio_otro" placeholder="Especifica si aplica">
                        </div>
                        <div class="form-group">
                            <label for="institucion_educativa">Institución educativa actual</label>
                            <input type="text" id="institucion_educativa" name="institucion_educativa" placeholder="Nombre del colegio, universidad…">
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── 03 ACUDIENTE ────────────────────────────────── -->
            <div class="form-section">
                <div class="section-header">
                    <span class="section-number">03</span>
                    <div>
                        <h3 class="section-title">Datos del Acudiente</h3>
                        <p class="section-desc">Requerido para menores de edad. Para adultos puede ser el mismo estudiante.</p>
                    </div>
                </div>
                <div class="fields-grid">
                    <div class="form-group col-span-2">
                        <label for="nombre_acudiente">Nombre completo del acudiente</label>
                        <input type="text" id="nombre_acudiente" name="nombre_acudiente">
                    </div>
                    <div class="form-group">
                        <label for="parentesco_acudiente">Parentesco</label>
                        <select id="parentesco_acudiente" name="parentesco_acudiente">
                            <option value="">— Selecciona —</option>
                            <option value="Padre">Padre</option>
                            <option value="Madre">Madre</option>
                            <option value="Abuelo/a">Abuelo/a</option>
                            <option value="Hermano/a">Hermano/a</option>
                            <option value="Tío/a">Tío/a</option>
                            <option value="Tutor legal">Tutor legal</option>
                            <option value="El mismo estudiante">El mismo estudiante</option>
                            <option value="Otro">Otro</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="telefono_acudiente">Teléfono del acudiente</label>
                        <input type="tel" id="telefono_acudiente" name="telefono_acudiente" placeholder="3XX XXX XXXX">
                    </div>
                    <div class="form-group">
                        <label for="email_acudiente">Correo electrónico del acudiente</label>
                        <input type="email" id="email_acudiente" name="email_acudiente" placeholder="correo@ejemplo.com">
                    </div>
                    <div class="form-group">
                        <label for="numero_recibo">N° del recibo de pago</label>
                        <input type="text" id="numero_recibo" name="numero_recibo" placeholder="Si ya realizaste el pago">
                    </div>
                </div>
            </div>

            <!-- ── 04 AUTORIZACIÓN DE IMAGEN ──────────────────── -->
            <div class="form-section section-legal">
                <div class="section-header">
                    <span class="section-number">04</span>
                    <div>
                        <h3 class="section-title">Autorización de Imagen</h3>
                        <p class="section-desc">Art. 288 Código Civil · Decreto 2820/1974 · Ley de Infancia y Adolescencia</p>
                    </div>
                </div>
                <div class="legal-text">
                    <p>Atendiendo al ejercicio de la Patria Potestad, establecido en el Código Civil Colombiano en su artículo 288, el artículo 24 del Decreto 2820 de 1974 y la Ley de Infancia y Adolescencia, La Escuela de Música Amimbré solicita la autorización escrita del padre/madre de familia o acudiente del (la) estudiante, integrante/estudiante de la Escuela de Música para que aparezca ante la cámara, para registro fotográfico y de videograbación con fines pedagógicos, promocionales y demás que se realizarán en las instalaciones y actividades de la Escuela de Música Amimbré.</p>
                    <p style="margin-top:10px;font-style:italic;">Los fines son netamente pedagógicos y promocionales sin lucro económico y en ningún momento será utilizado para objetivos distintos.</p>
                </div>
                <label class="check-card check-card--lg" style="margin-top:16px;">
                    <input type="checkbox" name="autoriza_imagen" value="1" id="autoriza_imagen">
                    <span class="check-box"></span>
                    <span class="check-label">Autorizo el registro fotográfico y de videograbación con fines pedagógicos y promocionales</span>
                </label>
                <div class="fields-grid" style="margin-top:20px;">
                    <div class="form-group">
                        <label for="firma_acudiente_cc">N° Cédula del acudiente firmante</label>
                        <input type="text" id="firma_acudiente_cc" name="firma_acudiente_cc" placeholder="Número de cédula">
                    </div>
                    <div class="form-group">
                        <label for="ti_estudiante">N° Tarjeta de Identidad del estudiante</label>
                        <input type="text" id="ti_estudiante" name="ti_estudiante" placeholder="Si aplica">
                    </div>
                </div>
            </div>

            <!-- ── 05 OBSERVACIONES + ENVÍO ───────────────────── -->
            <div class="form-section">
                <div class="section-header">
                    <span class="section-number">05</span>
                    <div>
                        <h3 class="section-title">Observaciones</h3>
                        <p class="section-desc">Cuéntanos algo adicional, consultas o expectativas</p>
                    </div>
                </div>
                <div class="form-group">
                    <label for="observaciones">Mensaje u observaciones (opcional)</label>
                    <textarea id="observaciones" name="observaciones" rows="4" placeholder="Cuéntanos sobre tus expectativas, experiencia previa o cualquier duda…"></textarea>
                </div>
                <label class="check-card check-card--lg" style="margin-top:16px;">
                    <input type="checkbox" id="terminos" name="terminos" required>
                    <span class="check-box"></span>
                    <span class="check-label">Acepto el tratamiento de mis datos personales conforme a la política de privacidad de la Escuela de Música Amimbré <span class="req-star">*</span></span>
                </label>
                <button type="submit" class="btn-submit">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                        <line x1="22" y1="2" x2="11" y2="13"/>
                        <polygon points="22 2 15 22 11 13 2 9 22 2"/>
                    </svg>
                    Enviar Preinscripción
                </button>
            </div>

        </form>

        <div class="form-footer">
            <div class="footer-contact">
                <span>📞 312 286 72 97</span>
                <span>📧 escuelademusicaamimbre@gmail.com</span>
                <span>📍 Carrera 33 N° 35-37, Sector la Alhambra — El Carmen de Viboral</span>
            </div>
        </div>
    </div>

    <script>
    document.getElementById('fecha_nacimiento').addEventListener('change', function () {
        const hoy = new Date();
        const nac = new Date(this.value);
        if (!isNaN(nac)) {
            let edad = hoy.getFullYear() - nac.getFullYear();
            const m = hoy.getMonth() - nac.getMonth();
            if (m < 0 || (m === 0 && hoy.getDate() < nac.getDate())) edad--;
            document.getElementById('edad').value = edad >= 0 ? edad : '';
        }
    });

    document.getElementById('preregistrationForm').addEventListener('submit', function (e) {
        const req = this.querySelectorAll('[required]');
        let valid = true;
        req.forEach(el => {
            el.classList.remove('input-error');
            const isEmpty = (el.type === 'checkbox' && !el.checked) ||
                            (el.tagName === 'SELECT' && !el.value) ||
                            (el.type !== 'checkbox' && el.tagName !== 'SELECT' && !el.value.trim());
            if (isEmpty) { el.classList.add('input-error'); valid = false; }
        });
        if (!valid) {
            e.preventDefault();
            document.querySelector('.input-error')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    });
    </script>
    <script src="../assets/js/script-preinscripcion.js"></script>
</body>
</html>