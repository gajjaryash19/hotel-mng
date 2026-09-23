<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;

/**
 * Auth
 * ----
 * Session-based authentication with separate "guards" for distinct
 * user types — the fix for a very common hand-rolled-auth bug: admin
 * and public-user login state ending up under the same $_SESSION
 * keys, so logging into one silently logs you out of (or gets
 * confused with) the other.
 *
 * Each guard is fully independent: its own session key, its own
 * table, its own "currently logged in" state. Logging into 'admin'
 * has zero effect on the 'user' guard and vice versa — you can be
 * logged in as both simultaneously in the same browser, correctly.
 *
 * USAGE
 * -----
 *   // Define guards once — in bootstrap.php, right after Paths::init():
 *   Auth::configure([
 *       'admin' => ['table' => 'admins', 'id_column' => 'admin_id', 'password_column' => 'password_hash', 'active_column' => 'is_active'],
 *       'user'  => ['table' => 'users',  'id_column' => 'user_id',  'password_column' => 'password_hash', 'active_column' => 'is_active'],
 *   ]);
 *
 *   // Attempt a login (admin/login.php):
 *   if (Auth::attempt('admin', $email, $password)) {
 *       // Login successful
 *   } else {
 *       // Login failed
 *   }
 *
 *   // Gate a page — first line of every protected page:
 *   Auth::require('admin', Paths::url('admin/login.php'));
 *
 *   // Get the logged-in record:
 *   $admin = Auth::user('admin');
 */
final class Auth
{
    /** @var array<string, array> */
    private static array $guards = [];

    private function __construct()
    {
        // Static-only class.
    }

    /**
     * Register one or more guards. Call once, early (bootstrap.php),
     * before any other Auth:: method runs.
     *
     * Per-guard config:
     *   table            (required) the table holding this guard's accounts
     *   id_column        (required) its primary key column
     *   password_column  (required) the column holding the password_hash() value
     *   email_column     (optional, default 'email') the login-identifier column
     *   active_column    (optional, default null) a column that must be truthy to allow login
     */
    public static function configure(array $guards): void
    {
        foreach ($guards as $name => $config) {
            self::$guards[$name] = array_merge([
                'email_column'  => 'email',
                'active_column' => null,
            ], $config);
        }
    }

    private static function config(string $guard): array
    {
        if (!isset(self::$guards[$guard])) {
            throw new RuntimeException("Auth guard \"$guard\" is not configured — call Auth::configure() first, in bootstrap.php.");
        }
        return self::$guards[$guard];
    }

    private static function sessionKey(string $guard): string
    {
        return "auth_{$guard}_id";
    }

    /**
     * Verify credentials against the guard's table. On success,
     * regenerates the session ID (prevents session fixation) and
     * stores the record's ID under this guard's own session key.
     * Returns false for a wrong password, an unknown email, OR an
     * inactive account — deliberately the same false for all three,
     * so a login form never leaks which one of those it was.
     */
    public static function attempt(string $guard, string $email, string $password): bool
    {
        $config = self::config($guard);
        $pdo = Database::connection();

        $sql = sprintf(
            'SELECT * FROM %s WHERE %s = :email LIMIT 1',
            $config['table'],
            $config['email_column']
        );
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['email' => $email]);
        $record = $stmt->fetch();

        if ($record === false) {
            return false;
        }

        if (!password_verify($password, (string) $record[$config['password_column']])) {
            return false;
        }

        if ($config['active_column'] !== null && !(bool) $record[$config['active_column']]) {
            return false;
        }

        self::login($guard, (int) $record[$config['id_column']]);
        return true;
    }

    /**
     * Log a known ID in directly, bypassing a password check — useful
     * right after a fresh registration, where you already trust the
     * ID without re-verifying a password the user just typed.
     */
    public static function login(string $guard, int $id): void
    {
        self::config($guard); // validates the guard exists before touching the session
        session_regenerate_id(true);
        $_SESSION[self::sessionKey($guard)] = $id;
    }

    /**
     * Logs this guard out only. Other guards logged in on the same
     * session (e.g. a user account open in another tab) are
     * untouched — a full session_destroy() would wrongly kill both.
     */
    public static function logout(string $guard): void
    {
        self::config($guard);
        unset($_SESSION[self::sessionKey($guard)]);
        session_regenerate_id(true);
    }

    public static function check(string $guard): bool
    {
        self::config($guard);
        return isset($_SESSION[self::sessionKey($guard)]);
    }

    public static function id(string $guard): ?int
    {
        self::config($guard);
        return $_SESSION[self::sessionKey($guard)] ?? null;
    }

    /**
     * The logged-in record, fetched fresh from the database on every
     * call. Deliberately NOT cached in $_SESSION — caching it would
     * mean an admin's own profile-update page kept showing the old
     * name/email until they logged out and back in.
     */
    public static function user(string $guard): ?array
    {
        $id = self::id($guard);
        if ($id === null) {
            return null;
        }

        $config = self::config($guard);
        $pdo = Database::connection();
        $sql = sprintf('SELECT * FROM %s WHERE %s = :id LIMIT 1', $config['table'], $config['id_column']);
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
        $record = $stmt->fetch();

        return $record === false ? null : $record;
    }

    /**
     * Gate a page in one line: redirects to $redirectTo if this guard
     * isn't logged in, otherwise does nothing and lets the page carry
     * on rendering. Put this as literally the first line of every
     * protected page (after bootstrap.php is required).
     */
    public static function require(string $guard, string $redirectTo): void
    {
        if (!self::check($guard)) {
            Response::redirect($redirectTo);
        }
    }
}