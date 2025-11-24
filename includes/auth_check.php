<?php
/**
 * Verificación de Autenticación
 * Incluir este archivo en todas las páginas protegidas
 */

// Incluir configuración de sesión y base de datos
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';

// Verificar que el usuario esté autenticado
require_login();

// Función para verificar acceso por rol
function check_page_access($required_role) {
    if (!has_role($required_role)) {
        set_flash_message('No tienes permisos para acceder a esta página', 'error');
        header('Location: ../../modules/dashboard/index.php');
        exit;
    }
}

// Función para verificar acceso múltiple
function check_multi_role_access($allowed_roles) {
    if (!has_any_role($allowed_roles)) {
        set_flash_message('No tienes permisos para acceder a esta página', 'error');
        header('Location: ../../public/index.html');
        exit;
    }
}