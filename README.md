# Amimbré (AMIMBR3)

Sistema web de gestión para una **escuela de música**: usuarios con roles, cursos, grupos, horarios, inscripciones, pagos, documentos, reportes y notificaciones.

Es mi **proyecto de grado del SENA** (Análisis y Desarrollo de Software), construido en equipo de 6 personas.

![PHP](https://img.shields.io/badge/PHP-8.2-777BB4?logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-PDO-4479A1?logo=mysql&logoColor=white)
![Docker](https://img.shields.io/badge/Docker-Render-2496ED?logo=docker&logoColor=white)

<!-- Agrega aquí 2 o 3 capturas: el dashboard, el listado de cursos y el horario.
     ![Dashboard](docs/img/dashboard.png) -->

## Qué hace

- **Usuarios y roles:** administrador, profesor, estudiante y visitante, con permisos por nivel.
- **Cursos, grupos y horarios:** organización académica de la escuela.
- **Inscripciones y pagos:** matrícula de estudiantes y tarea programada de pagos (`cron/cron_pagos.php`).
- **Documentos:** carga y consulta de archivos, con carpetas de subida propias.
- **Notificaciones:** avisos dentro del sistema.
- **Reportes y dashboard:** indicadores para la administración.
- **Ayuda y configuración:** guía de uso y ajustes del sistema.
- **Bitácora de actividad:** cada acción importante queda registrada con usuario, IP y navegador.

## Cómo está hecho

| Capa | Tecnología |
|---|---|
| Backend | PHP 8.2, PDO con consultas preparadas (`ATTR_EMULATE_PREPARES` desactivado) |
| Base de datos | MySQL con codificación `utf8mb4` |
| Frontend | HTML, CSS y JavaScript |
| Despliegue | Docker (`php:8.2-apache`) en Render |

Algunas decisiones:

- **Configuración por variables de entorno** (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `APP_URL`): ningún secreto vive en el código.
- **Errores distintos por entorno:** en producción el usuario ve un mensaje genérico y el detalle va al log.
- **Permisos por jerarquía de roles**, comprobados con una sola función reutilizable.

## Estructura

```
auth/       inicio de sesión y registro
config/     base de datos y sesión
includes/   utilidades compartidas
modules/    ayuda, configuracion, cursos, dashboard, documentos, grupos,
            horarios, inscripciones, notificaciones, reportes, usuarios
cron/       tareas programadas
public/     punto de entrada
assets/     CSS, JavaScript e imágenes
```

## Ejecutar en local (XAMPP)

1. Clona el repositorio en `C:\xampp\htdocs\AMIMBR3`.
2. Arranca Apache y MySQL.
3. Crea la base de datos `amimbre_db` e importa el esquema.
4. Abre `http://localhost/AMIMBR3`.

> **Pendiente:** el archivo del esquema de la base de datos todavía no está en el repositorio. Súbelo (por ejemplo como `database/schema.sql`) para que el paso 3 sea reproducible.

## Ejecutar con Docker

```bash
docker build -t amimbre .
docker run -p 8080:80 \
  -e DB_HOST=host -e DB_NAME=amimbre_db -e DB_USER=usuario -e DB_PASS=clave \
  amimbre
```

El `Dockerfile` instala las extensiones de MySQL, activa `mod_rewrite` y prepara las carpetas de subida de archivos. En Render, el puerto dinámico lo resuelve el `entrypoint.sh`.

## Equipo

Proyecto de grado desarrollado por un equipo de 6 integrantes. Felipe Toro: más de 170 commits, despliegue con Docker en Render y correcciones de producción.
