<?php
/**
 * kylo_db.php — El Conector
 * Motor: Kylo Database (MySQL / MariaDB)
 * Arquitectura: PDO + Prepared Statements + Exception Mode
 *
 * All-Seeing Eye · Proyecto Seraphs
 */

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────
//  CONFIGURACIÓN — REEMPLAZA CON TUS CREDENCIALES KYLO
// ─────────────────────────────────────────────────────────────
define('KDB_HOST',    'localhost');          // Host de Kylo (ej. 127.0.0.1 o IP remota)
define('KDB_PORT',    '3307');               // Puerto MySQL/MariaDB
define('KDB_NAME',    'kylo_core');        // Nombre de la base de datos en Kylo
define('KDB_USER',    'root');    // Usuario de la DB
define('KDB_PASS',    '');   // Contraseña de la DB
// Tabla objetivo calificada
define('KDB_CHARSET', 'utf8mb4');
define('KDB_TABLE', 'kylo_core.security_logs');

// ─────────────────────────────────────────────────────────────
//  CLASE KyloDB
// ─────────────────────────────────────────────────────────────
class KyloDB
{
    /** @var PDO|null Instancia única (patrón Singleton) */
    private static ?PDO $instance = null;

    /**
     * Devuelve la conexión PDO activa.
     * Si no existe, la crea y la configura en modo ultra-seguro.
     */
    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;charset=%s',
                KDB_HOST,
                KDB_PORT,
                KDB_CHARSET
            );

            $opciones = [
                // Lanza PDOException en cualquier error (no silencios)
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                // Devuelve filas como arrays asociativos por defecto
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Evita emulación de prepared statements (seguridad extra) ➔ Ahora FORZAMOS emulación porque Kylo no tiene bin-protocol
                PDO::ATTR_EMULATE_PREPARES   => true,
            ];

            try {
                self::$instance = new PDO($dsn, KDB_USER, KDB_PASS, $opciones);
            } catch (PDOException $e) {
                // LOG interno sin exponer detalles al cliente
                error_log('[KyloDB] Fallo de conexión: ' . $e->getMessage());
                // Respuesta genérica al flujo de la aplicación
                http_response_code(503);
                die(json_encode(['error' => 'Database engine unavailable.', 'debug' => $e->getMessage()]));
            }
        }

        return self::$instance;
    }

    // ─────────────────────────────────────────────────────────
    //  MÉTODO PRINCIPAL: insertarTelemetria()
    // ─────────────────────────────────────────────────────────
    /**
     * Inserta una fila completa de telemetría en seraph_telemetry.
     *
     * @param array $datos {
     *   @type string  $discord_id        ID numérico de Discord (BIGINT como string)
     *   @type string  $discord_tag        Tag completo: "Usuario#1234"
     *   @type string  $roblox_username    Nombre de Roblox obtenido de Bloxlink
     *   @type string  $user_agent         User-Agent completo del navegador
     *   @type bool    $es_seraph          true si pasó la verificación de rol
     *   @type string  $servidor_destino   Ej: "Fistborn" o ID del servidor
     * }
     * @return string UUID del registro insertado
     * @throws PDOException si la inserción falla
     */
    public static function insertarTelemetria(array $datos): string
    {
        $log_id = random_int(1, 2147483647);
        $sql = "
            INSERT INTO " . KDB_TABLE . " (
                id,
                event_uuid,
                discord_id,
                username,
                event_type,
                details
            ) VALUES (
                :id,
                :event_uuid,
                :discord_id,
                :username,
                :event_type,
                :details
            )
        ";

        try {
            $pdo  = self::getConnection();
            $stmt = $pdo->prepare($sql);

            // ── Binding explícito con tipos para máxima seguridad ──
            $stmt->bindValue(':id',         $log_id,              PDO::PARAM_INT);
            $stmt->bindValue(':event_uuid', $datos['event_uuid'], PDO::PARAM_STR);
            $stmt->bindValue(':discord_id', (string)$datos['discord_id'], PDO::PARAM_STR); // BIGINT safe via string
            $stmt->bindValue(':username',   $datos['username'],   PDO::PARAM_STR);
            $stmt->bindValue(':event_type', $datos['event_type'], PDO::PARAM_STR);
            $stmt->bindValue(':details',    $datos['details'],    PDO::PARAM_STR);

            $stmt->execute();

            // LOG interno de confirmación
            error_log("[KyloDB] Telemetría insertada — UUID: {$datos['event_uuid']} | Tipo: {$datos['event_type']}");

            return $datos['event_uuid'];

        } catch (PDOException $e) {
            error_log('[KyloDB] Error de inserción: ' . $e->getMessage());
            throw $e; // Re-lanzar para manejo en discord_auth.php
        }
    }

    // ─────────────────────────────────────────────────────────
    //  HELPERS
    // ─────────────────────────────────────────────────────────

    /**
     * Genera un UUID v4 aleatorio puro en PHP.
     * No depende de extensiones externas.
     */
    public static function generarUUIDv4(): string
    {
        $bytes = random_bytes(16);

        // Setear versión 4 (bits 4-7 del byte 6 = 0100)
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        // Setear variante RFC 4122 (bits 6-7 del byte 8 = 10)
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * Recupera el enlace más reciente de la bóveda segura.
     * @return string|null El enlace o null si la bóveda está vacía.
     */
    public static function getSafeVaultLink(): ?string
    {
        try {
            $pdo = self::getConnection();
            $stmt = $pdo->query("SELECT ropro_link FROM kylo_core.safe_vault ORDER BY id DESC LIMIT 1");
            $resultado = $stmt->fetch();
            return $resultado['ropro_link'] ?? null;
        } catch (PDOException $e) {
            error_log('[KyloDB] Error al recuperar enlace: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Cierra la conexión activa.
     * Útil al final del ciclo de vida del script.
     */
    public static function cerrarConexion(): void
    {
        self::$instance = null;
    }
}
