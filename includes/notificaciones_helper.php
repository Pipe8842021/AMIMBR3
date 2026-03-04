<?php
/**
 * ============================================================
 * HELPER DE NOTIFICACIONES AUTOMÁTICAS - Amimbré
 * Archivo: includes/notificaciones_helper.php
 * ============================================================
 * Cómo usar:
 *   require_once '../../includes/notificaciones_helper.php';
 *   NotificacionesHelper::nuevaPreinscripcion($pdo, $nombre, $curso);
 * ============================================================
 */

class NotificacionesHelper {

    // ----------------------------------------------------------
    // MÉTODO BASE: Insertar una notificación para uno o varios usuarios
    // ----------------------------------------------------------

    /**
     * Crea una notificación para un usuario específico.
     *
     * @param PDO    $pdo
     * @param int    $usuario_id   ID del usuario que RECIBE la notificación
     * @param string $tipo         'sistema' | 'preinscripcion' | 'evento' | 'general'
     * @param string $titulo
     * @param string $mensaje
     * @param string $emisor       Nombre visible del emisor (ej: "Sistema", nombre del admin)
     * @param string $prioridad    'baja' | 'normal' | 'alta'
     * @param string|null $enlace  URL opcional hacia donde lleva la notificación
     */
    public static function crear(
        PDO $pdo,
        int $usuario_id,
        string $tipo,
        string $titulo,
        string $mensaje,
        string $emisor = 'Sistema',
        string $prioridad = 'normal',
        ?string $enlace = null
    ): bool {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO notificaciones
                    (usuario_id, tipo, titulo, mensaje, emisor, prioridad, enlace)
                VALUES
                    (:usuario_id, :tipo, :titulo, :mensaje, :emisor, :prioridad, :enlace)
            ");
            return $stmt->execute([
                ':usuario_id' => $usuario_id,
                ':tipo'       => $tipo,
                ':titulo'     => $titulo,
                ':mensaje'    => $mensaje,
                ':emisor'     => $emisor,
                ':prioridad'  => $prioridad,
                ':enlace'     => $enlace,
            ]);
        } catch (PDOException $e) {
            error_log("[NotificacionesHelper::crear] " . $e->getMessage());
            return false;
        }
    }

    /**
     * Crea la misma notificación para todos los usuarios de uno o varios roles.
     *
     * @param PDO    $pdo
     * @param array  $roles        Ej: ['admin'], ['admin','profesor']
     * @param string $tipo
     * @param string $titulo
     * @param string $mensaje
     * @param string $emisor
     * @param string $prioridad
     * @param string|null $enlace
     */
    public static function crearParaRoles(
        PDO $pdo,
        array $roles,
        string $tipo,
        string $titulo,
        string $mensaje,
        string $emisor = 'Sistema',
        string $prioridad = 'normal',
        ?string $enlace = null
    ): void {
        try {
            // Construir placeholders para IN (?,?,?)
            $placeholders = implode(',', array_fill(0, count($roles), '?'));
            $stmt = $pdo->prepare("
                SELECT id FROM usuarios
                WHERE rol IN ($placeholders) AND estado = 'activo'
            ");
            $stmt->execute($roles);
            $usuarios = $stmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($usuarios as $uid) {
                self::crear($pdo, $uid, $tipo, $titulo, $mensaje, $emisor, $prioridad, $enlace);
            }
        } catch (PDOException $e) {
            error_log("[NotificacionesHelper::crearParaRoles] " . $e->getMessage());
        }
    }

    // ----------------------------------------------------------
    // EVENTOS DE USUARIO
    // ----------------------------------------------------------

    /**
     * Notifica a los admins cuando se crea un usuario nuevo.
     *
     * @param PDO    $pdo
     * @param string $nombre_nuevo  Nombre del usuario recién creado
     * @param string $rol_nuevo     Rol asignado: 'estudiante' | 'profesor' | 'admin'
     * @param string $emisor        Nombre de quien realizó la acción
     */
    public static function usuarioCreado(
        PDO $pdo,
        string $nombre_nuevo,
        string $rol_nuevo,
        string $emisor = 'Sistema'
    ): void {
        $rolesMap = [
            'estudiante' => 'Estudiante',
            'profesor'   => 'Profesor',
            'admin'      => 'Administrador',
        ];
        $rol_texto = $rolesMap[$rol_nuevo] ?? ucfirst($rol_nuevo);

        self::crearParaRoles(
            $pdo,
            ['admin'],
            'sistema',
            "Nuevo usuario registrado",
            "Se ha registrado un nuevo $rol_texto: $nombre_nuevo.",
            $emisor,
            'normal',
            '/AMIMBR3/modules/usuarios/index.php'
        );
    }

    /**
     * Notifica a los admins cuando se edita un usuario.
     *
     * @param PDO    $pdo
     * @param string $nombre_usuario  Nombre del usuario editado
     * @param string $emisor
     */
    public static function usuarioEditado(
        PDO $pdo,
        string $nombre_usuario,
        string $emisor = 'Sistema'
    ): void {
        self::crearParaRoles(
            $pdo,
            ['admin'],
            'sistema',
            "Usuario modificado",
            "El usuario $nombre_usuario ha sido actualizado.",
            $emisor,
            'baja',
            '/AMIMBR3/modules/usuarios/index.php'
        );
    }

    /**
     * Notifica a los admins cuando se elimina un usuario.
     *
     * @param PDO    $pdo
     * @param string $nombre_usuario  Nombre del usuario eliminado
     * @param string $emisor
     */
    public static function usuarioEliminado(
        PDO $pdo,
        string $nombre_usuario,
        string $emisor = 'Sistema'
    ): void {
        self::crearParaRoles(
            $pdo,
            ['admin'],
            'sistema',
            "Usuario eliminado",
            "El usuario $nombre_usuario ha sido eliminado del sistema.",
            $emisor,
            'alta',
            '/AMIMBR3/modules/usuarios/index.php'
        );
    }

    // ----------------------------------------------------------
    // EVENTOS DE PREINSCRIPCIÓN
    // ----------------------------------------------------------

    /**
     * Notifica a los admins cuando llega una nueva preinscripción.
     *
     * @param PDO    $pdo
     * @param string $nombre_solicitante  Nombre de quien se preinscribió
     * @param string $curso               Nombre del curso de interés
     */
    public static function nuevaPreinscripcion(
        PDO $pdo,
        string $nombre_solicitante,
        string $curso
    ): void {
        self::crearParaRoles(
            $pdo,
            ['admin'],
            'preinscripcion',
            "Nueva preinscripción recibida",
            "Se recibió una preinscripción de $nombre_solicitante para el curso: $curso.",
            'Sistema',
            'alta',
            '/AMIMBR3/modules/inscripciones/prematriculas/index.php'
        );
    }

    /**
     * Notifica al alumno cuando su preinscripción cambia de estado.
     *
     * @param PDO    $pdo
     * @param int    $usuario_id   ID del estudiante que recibe la notificación
     * @param string $nuevo_estado 'contactado' | 'matriculado' | 'rechazado'
     */
    public static function estadoPreinscripcionCambiado(
        PDO $pdo,
        int $usuario_id,
        string $nuevo_estado
    ): void {
        $mensajes = [
            'contactado'  => ['título' => 'Tu preinscripción fue revisada',
                              'msg'    => 'Un asesor se pondrá en contacto contigo pronto.'],
            'matriculado' => ['título' => '¡Felicitaciones! Preinscripción aprobada',
                              'msg'    => 'Tu preinscripción ha sido aprobada. Ya puedes completar tu matrícula.'],
            'rechazado'   => ['título' => 'Preinscripción no aprobada',
                              'msg'    => 'Lo sentimos, tu preinscripción no fue aprobada en esta ocasión.'],
        ];

        if (!isset($mensajes[$nuevo_estado])) return;

        self::crear(
            $pdo,
            $usuario_id,
            'preinscripcion',
            $mensajes[$nuevo_estado]['título'],
            $mensajes[$nuevo_estado]['msg'],
            'Sistema',
            $nuevo_estado === 'matriculado' ? 'alta' : 'normal'
        );
    }

    // ----------------------------------------------------------
    // EVENTOS DE EVENTO / ACTIVIDAD
    // ----------------------------------------------------------

    /**
     * Notifica a todos los usuarios activos sobre un nuevo evento.
     *
     * @param PDO    $pdo
     * @param string $titulo_evento  Título del evento creado
     * @param string $descripcion    Breve descripción
     * @param string $emisor         Nombre del admin que lo creó
     * @param string|null $enlace
     */
    public static function nuevoEvento(
        PDO $pdo,
        string $titulo_evento,
        string $descripcion,
        string $emisor = 'Administrador',
        ?string $enlace = null
    ): void {
        self::crearParaRoles(
            $pdo,
            ['admin', 'profesor', 'estudiante'],
            'evento',
            "Nuevo evento: $titulo_evento",
            $descripcion,
            $emisor,
            'normal',
            $enlace
        );
    }

    /**
     * Notifica a todos los usuarios activos sobre un evento editado.
     *
     * @param PDO    $pdo
     * @param string $titulo_evento
     * @param string $emisor
     * @param string|null $enlace
     */
    public static function eventoEditado(
        PDO $pdo,
        string $titulo_evento,
        string $emisor = 'Administrador',
        ?string $enlace = null
    ): void {
        self::crearParaRoles(
            $pdo,
            ['admin', 'profesor', 'estudiante'],
            'evento',
            "Evento actualizado: $titulo_evento",
            "El evento \"$titulo_evento\" ha sido modificado. Revisa los detalles.",
            $emisor,
            'normal',
            $enlace
        );
    }

    // ----------------------------------------------------------
    // CONTADORES PARA EL MÓDULO DE NOTIFICACIONES
    // ----------------------------------------------------------

    /**
     * Devuelve las 4 estadísticas que muestra tu módulo actual.
     * Reemplaza las 4 consultas individuales en tu index.php.
     *
     * @param PDO $pdo
     * @param int $usuario_id
     * @return array ['total', 'sin_leer', 'preinscripciones', 'eventos']
     */
    public static function obtenerEstadisticas(PDO $pdo, int $usuario_id): array {
        try {
            $stmt = $pdo->prepare("
                SELECT
                    COUNT(*)                                              AS total,
                    SUM(leida = 0)                                        AS sin_leer,
                    SUM(tipo = 'preinscripcion' AND leida = 0)            AS preinscripciones,
                    SUM(tipo = 'evento'         AND leida = 0)            AS eventos
                FROM notificaciones
                WHERE usuario_id = ?
            ");
            $stmt->execute([$usuario_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return [
                'total'            => (int)($row['total']            ?? 0),
                'sin_leer'         => (int)($row['sin_leer']         ?? 0),
                'preinscripciones' => (int)($row['preinscripciones'] ?? 0),
                'eventos'          => (int)($row['eventos']          ?? 0),
            ];
        } catch (PDOException $e) {
            error_log("[NotificacionesHelper::obtenerEstadisticas] " . $e->getMessage());
            return ['total' => 0, 'sin_leer' => 0, 'preinscripciones' => 0, 'eventos' => 0];
        }
    }

    /**
     * Devuelve solo el número de notificaciones sin leer de un usuario.
     * Ideal para el badge del header/sidebar.
     *
     * @param PDO $pdo
     * @param int $usuario_id
     * @return int
     */
    public static function contarSinLeer(PDO $pdo, int $usuario_id): int {
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM notificaciones
                WHERE usuario_id = ? AND leida = 0
            ");
            $stmt->execute([$usuario_id]);
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("[NotificacionesHelper::contarSinLeer] " . $e->getMessage());
            return 0;
        }
    }
}