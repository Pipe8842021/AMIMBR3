/*Formulario de preinscripcion */
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preinscripción - Curso 2025</title>
    <link rel="stylesheet" href="../assets/css/style-preInscripcion.css">
    <link rel="stylesheet" href="../assets/css/colores.css">
</head>
<body>
    <header>
        <div class="logo-section">
            <img src="../assets/img/1.jpg" alt="BanaExport Logo">
            <a href="index.html"><h1>Amimbré</h1></a>
        </div>

        <div class="header-actions">
            <div class="theme-toggle"></div>
            <a href="../auth/login.php" class="login-btn">Iniciar Sesión</a>
        </div>
    </header>

    <div class="container">
        <div class="header">
            <h1>Preinscripción</h1>
            <p>Completa el formulario para reservar tu cupo</p>
        </div>

        <form id="preregistrationForm">
            <div class="form-group">
                <label for="nombre">Nombre completo <span class="required">*</span></label>
                <input type="text" id="nombre" name="nombre" required>
            </div>

            <div class="form-group">
                <label for="email">Correo electrónico <span class="required">*</span></label>
                <input type="email" id="email" name="email" required>
            </div>

            <div class="form-group">
                <label for="telefono">Teléfono <span class="required">*</span></label>
                <input type="tel" id="telefono" name="telefono" required>
            </div>

            <div class="form-group">
                <label for="documento">Documento de identidad <span class="required">*</span></label>
                <input type="text" id="documento" name="documento" required>
            </div>

            <div class="form-group">
                <label for="curso">Curso de interés <span class="required">*</span></label>
                <select id="curso" name="curso" required>
                    <option value="">Selecciona un curso</option>
                    <option value="iniciacion-infantil">Iniciación Infantil</option>
                    <option value="guitarra">Guitarra</option>
                    <option value="piano">Piano</option>
                    <option value="vientos">Vientos</option>
                    <option value="canto">Canto</option>
                    <option value="lenguaje-musical">Lenguaje musical</option>
                    <option value="ensambles">Ensambles musicales</option>
                    <option value="prep-universitaria">Preparación Universitaria</option>
                </select>
            </div>

            <div class="form-group">
                <label for="mensaje">Mensaje o consulta (opcional)</label>
                <textarea id="mensaje" name="mensaje" placeholder="Cuéntanos sobre tus expectativas o dudas..."></textarea>
            </div>

            <div class="checkbox-group">
                <input type="checkbox" id="terminos" name="terminos" required>
                <label for="terminos">Acepto los términos y condiciones <span class="required">*</span></label>
            </div>

            <button type="submit" class="btn">Enviar Preinscripción</button>
        </form>
    </div>

    <script src="../assets/js/script-preinscripcion.js"></script>
</body>
</html>