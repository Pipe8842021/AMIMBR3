<?php
/**
 * Resolución dinámica de la ruta base de la aplicación.
 *
 * Permite que las URLs absolutas funcionen sin cambios tanto en XAMPP
 * (http://localhost/AMIMBR3/...) como en producción, donde la app se sirve
 * desde la raíz del dominio (https://amimbr3.onrender.com/...).
 *
 * BASE_URL es el prefijo de URL de la app, SIN barra final:
 *   - XAMPP en htdocs/AMIMBR3  ->  "/AMIMBR3"
 *   - Docker/Render en la raíz ->  ""
 */

if (!defined('BASE_URL')) {

    /**
     * Deriva el prefijo comparando la ruta física del script en ejecución con
     * su ruta de URL. Es el método más fiable: no depende de DOCUMENT_ROOT y
     * soporta alias de Apache y subcarpetas arbitrarias.
     */
    $detectar_base_url = static function (): string {
        // 1. Override explícito por variable de entorno (prioridad máxima).
        $env = getenv('BASE_URL');
        if ($env !== false && trim($env) !== '') {
            return '/' . trim(trim($env), '/');
        }

        $normalizar = static function (string $ruta): string {
            return str_replace('\\', '/', $ruta);
        };

        $raiz_app   = rtrim($normalizar(dirname(__DIR__)), '/');
        $script_fs  = $normalizar($_SERVER['SCRIPT_FILENAME'] ?? '');
        $script_url = $normalizar($_SERVER['SCRIPT_NAME'] ?? '');

        // 2. SCRIPT_FILENAME vs SCRIPT_NAME.
        if ($script_fs !== '' && $script_url !== '' && stripos($script_fs, $raiz_app) === 0) {
            $relativa = substr($script_fs, strlen($raiz_app)); // p.ej. /modules/cursos/index.php
            if ($relativa !== '' && str_ends_with($script_url, $relativa)) {
                return rtrim(substr($script_url, 0, -strlen($relativa)), '/');
            }
        }

        // 3. Respaldo: diferencia entre DOCUMENT_ROOT y la raíz de la app.
        $doc_root = rtrim($normalizar($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
        if ($doc_root !== '' && stripos($raiz_app, $doc_root) === 0) {
            return rtrim(substr($raiz_app, strlen($doc_root)), '/');
        }

        // 4. La app se sirve desde la raíz.
        return '';
    };

    define('BASE_URL', $detectar_base_url());
    unset($detectar_base_url);
}

if (!function_exists('url')) {
    /**
     * Construye una URL absoluta desde la raíz de la app.
     *
     *   url('/modules/cursos/index.php')  ->  /AMIMBR3/modules/cursos/index.php  (local)
     *                                     ->  /modules/cursos/index.php          (producción)
     *
     * Las URLs externas, anclas y esquemas especiales se devuelven intactos.
     */
    function url(string $ruta = '/'): string
    {
        if ($ruta === '') {
            return BASE_URL . '/';
        }

        // No tocar URLs que ya son absolutas o que no son rutas de la app.
        if (preg_match('#^([a-z][a-z0-9+.-]*:|//|\#|\?)#i', $ruta)) {
            return $ruta;
        }

        return BASE_URL . '/' . ltrim($ruta, '/');
    }
}

if (!function_exists('app_link')) {
    /**
     * Normaliza un enlace guardado en base de datos (p.ej. el campo
     * `notificaciones.enlace`) y lo convierte en una URL válida para el
     * entorno actual.
     *
     * Tolera registros heredados que se guardaron con el prefijo "/AMIMBR3".
     */
    function app_link(?string $enlace): string
    {
        $enlace = trim((string) $enlace);
        if ($enlace === '') {
            return '';
        }

        // URLs externas / anclas: se devuelven sin modificar.
        if (preg_match('#^([a-z][a-z0-9+.-]*:|//|\#)#i', $enlace)) {
            return $enlace;
        }

        // Quitar el prefijo heredado si viene guardado en el registro.
        if (stripos($enlace, '/AMIMBR3/') === 0) {
            $enlace = substr($enlace, strlen('/AMIMBR3'));
        }

        // Rutas relativas guardadas a mano: dejarlas tal cual.
        if ($enlace[0] !== '/') {
            return $enlace;
        }

        return url($enlace);
    }
}
