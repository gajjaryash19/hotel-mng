<?php
declare(strict_types=1);

namespace Core;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Database
 * --------
 * A single, lazily-created PDO connection shared for the whole
 * request — the fix for two more recurring PHP annoyances:
 *
 *   1. Opening a fresh PDO connection in every file that needs one:
 *      slow, and a good way to end up with the credentials copy-
 *      pasted into fifteen different files instead of one.
 *   2. PDO's own defaults are wrong for almost every app: errors are
 *      silent by default, emulated prepares can defeat some of the
 *      protection prepared statements are meant to provide, and the
 *      default fetch mode returns both numeric AND associative keys
 *      for every single row.
 *
 * CONFIGURATION
 * -------------
 * Reads DB_HOST / DB_PORT / DB_NAME / DB_USER / DB_PASS / DB_CHARSET
 * from the environment first (see bootstrap.php's .env loader),
 * falling back to $defaults below when a variable isn't set. Edit
 * $defaults for local development only — real deployments should set
 * real environment variables and never need to touch this file again.
 *
 * USAGE
 * -----
 *   $pdo = Database::connection();
 *   $stmt = $pdo->prepare('SELECT * FROM users WHERE user_id = ?');
 *   $stmt->execute([$id]);
 *   $user = $stmt->fetch();
 *
 * Ship this file as-is into any PHP project.
 */
final class Database
{
    private static ?PDO $instance = null;

    /** Edit these for local development only — never commit real production credentials here. */
    private static array $defaults = [
        'driver'  => 'mysql',
        'host'    => '127.0.0.1',
        'port'    => '3306',
        'name'    => 'hotel_booking_system',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ];

    private function __construct()
    {
        // Static-only class — use Database::connection().
    }

    public static function connection(): PDO
    {
        if (self::$instance instanceof PDO) {
            return self::$instance;
        }

        $config = self::config();

        $dsn = sprintf(
            '%s:host=%s;port=%s;dbname=%s;charset=%s',
            $config['driver'],
            $config['host'],
            $config['port'],
            $config['name'],
            $config['charset']
        );

        try {
            self::$instance = new PDO($dsn, $config['user'], $config['pass'], [
                // Exceptions instead of silently-false return values
                // that get checked (or not) after every single query.
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                // Associative arrays only — removes the $row[0] vs
                // $row['name'] duplicate-key confusion for every row.
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Real server-side prepared statements, not PHP-side
                // emulation — correct type handling, and the full
                // SQL-injection protection prepared statements exist
                // to provide.
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false,
            ]);
        } catch (PDOException $e) {
            // Never let a raw PDOException reach the browser: its
            // message can include the DSN and, on some drivers, the
            // password. Log the real detail, surface a generic one.
            error_log('[Database] Connection failed: ' . $e->getMessage());
            throw new RuntimeException('Database connection failed. Check the server error log for details.');
        }

        return self::$instance;
    }

    /**
     * Reads DB_* environment variables when present, otherwise falls
     * back to self::$defaults — local dev stays friction-free (just
     * edit the defaults) while production stays safe (real
     * deployments set real environment variables and this file never
     * needs editing again).
     */
    private static function config(): array
    {
        return [
            'driver'  => self::env('DB_DRIVER',  self::$defaults['driver']),
            'host'    => self::env('DB_HOST',    self::$defaults['host']),
            'port'    => self::env('DB_PORT',    self::$defaults['port']),
            'name'    => self::env('DB_NAME',    self::$defaults['name']),
            'user'    => self::env('DB_USER',    self::$defaults['user']),
            'pass'    => self::env('DB_PASS',    self::$defaults['pass']),
            'charset' => self::env('DB_CHARSET', self::$defaults['charset']),
        ];
    }

    private static function env(string $key, string $default): string
    {
        $value = $_ENV[$key] ?? getenv($key);
        return ($value === false || $value === null || $value === '') ? $default : (string) $value;
    }

    /**
     * Force a fresh connection on the next call. Rarely needed in a
     * normal request, but useful in test suites/seed scripts that
     * need a clean connection between runs.
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
