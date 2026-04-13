<?php
/**
 * Gestión de Usuarios – Desactivar / Activar
 * Solo lógica, sin HTML. Redirige siempre.
 */

require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/auth_check.php';

require_role('admin');

$id     = (int)($_GET['id']    ?? 0);
$action = trim($_GET['action'] ?? '');

if (!$id || !in_array($action, ['activar', 'desactivar'])) {
    header("Location: index.php");
    exit;
}

// No puede desactivarse a sí mismo
if ($id === (int)$_SESSION['user_id'] && $action === 'desactivar') {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'No puedes desactivar tu propia cuenta.'];
    header("Location: index.php");
    exit;
}

// Verificar que el usuario existe
try {
    $stmt = $pdo->prepare("SELECT id, nombre FROM usuarios WHERE id = ?");
    $stmt->execute([$id]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $target = null;
}

if (!$target) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Usuario no encontrado.'];
    header("Location: index.php");
    exit;
}

// Ejecutar cambio
$nuevo_estado = ($action === 'activar') ? 'activo' : 'inactivo';
$verbo        = ($action === 'activar') ? 'activado' : 'desactivado';

try {
    $pdo->prepare("UPDATE usuarios SET estado = ? WHERE id = ?")->execute([$nuevo_estado, $id]);
    $_SESSION['flash'] = [
        'type'    => 'success',
        'message' => 'Usuario «' . $target['nombre'] . '» ' . $verbo . ' correctamente.',
    ];
} catch (PDOException $e) {
    error_log($e->getMessage());
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'No se pudo actualizar el estado del usuario.'];
}

// Redirigir al origen
$referer = $_SERVER['HTTP_REFERER'] ?? '';
if (strpos($referer, 'editar.php') !== false) {
    header("Location: editar.php?id=$id");
} else {
    header("Location: index.php");
}
exit;