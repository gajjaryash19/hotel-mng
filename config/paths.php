<?php
declare(strict_types=1);

namespace Core;

/**
 * Paths
 * -----
 * Centralised filesystem + URL resolution — the fix for two of PHP's
 * most persistent annoyances:
 *
 *   1. There is no built-in "project root". Relative paths like
 *      "../../config/x.php" break the instant a file moves one
 *      directory deeper, and getcwd() depends entirely on how/where
 *      the script was invoked (Apache vs CLI vs an include chain).
 *   2. There is no built-in "my own base URL". The app might live at
 *      the domain root or in a subfolder (typical on local XAMPP —
 *      http://localhost/project-name/), over http or https, and
 *      hardcoding any of that breaks the moment you deploy elsewhere.
 *
 * USAGE
 * -----
 *   // Once, as early as possible — normally in bootstrap.php:
 *   Paths::init(__DIR__);
 *
 *   // Anywhere after that, from any file, regardless of depth:
 *   Paths::base('config', 'database.php');   // filesystem path
 *   Paths::storage('uploads', 'photo.jpg');  // filesystem path
 *   Paths::url('admin/login.php');           // full URL
 *   Paths::asset('css/style.css');           // full URL under /assets
 *
 * Ship this file as-is into any PHP project. The only thing that ever
 * needs to change is the single Paths::init() call.
 */
final class Paths
{
    private static ?string $basePath = null;
    private static ?string $baseUrl = null;

    private function __construct()
    {
        // Static-only class — never instantiated.
    }

    /**
     * Must be called once, before any other Paths:: method — normally
     * Paths::init(__DIR__) from bootstrap.php sitting at the project
     * root. Pass $baseUrl explicitly only if auto-detection (based on
     * the current request) isn't appropriate, e.g. inside a CLI
     * script that still needs to build URLs.
     */
    public static function init(string $basePath, ?string $baseUrl = null): void
    {
        self::$basePath = rtrim(str_replace('\\', '/', $basePath), '/');
        self::$baseUrl  = $baseUrl !== null ? rtrim($baseUrl, '/') : self::detectBaseUrl();
    }

    private static function assertInitialized(): void
    {
        if (self::$basePath === null) {
            throw new \RuntimeException(
                'Paths::init() must be called once before Paths is used — do it first in bootstrap.php.'
            );
        }
    }

    /**
     * Join arbitrary segments onto the project root into an absolute
     * filesystem path. Handles slashes so you never hand-concatenate
     * "/" or "\" — or guess whether the previous string ended with
     * one — ever again.
     *
     *   Paths::base('config', 'database.php')
     *   → /var/www/project/config/database.php
     */
    public static function base(string ...$segments): string
    {
        self::assertInitialized();
        return self::join(self::$basePath, ...$segments);
    }

    public static function config(string ...$segments): string
    {
        return self::base('config', ...$segments);
    }

    public static function core(string ...$segments): string
    {
        return self::base('core', ...$segments);
    }

    /** Non-web-accessible storage — uploads, logs, cache. Keep this OUTSIDE any public web root. */
    public static function storage(string ...$segments): string
    {
        return self::base('storage', ...$segments);
    }

    public static function uploads(string ...$segments): string
    {
        return self::storage('uploads', ...$segments);
    }

    public static function logs(string ...$segments): string
    {
        return self::storage('logs', ...$segments);
    }

    /**
     * Build a full URL from a path relative to the app's base URL.
     *   Paths::url('admin/login.php') → http://localhost/project/admin/login.php
     */
    public static function url(string $path = ''): string
    {
        self::assertInitialized();
        $path = ltrim($path, '/');
        return $path === '' ? self::$baseUrl : self::$baseUrl . '/' . $path;
    }

    /** Shorthand for Paths::url('assets/...'). */
    public static function asset(string $path = ''): string
    {
        return self::url('assets/' . ltrim($path, '/'));
    }

    /**
     * Auto-detects scheme + host + subfolder so the app works
     * unmodified whether it's at the domain root or nested in a
     * subfolder, over http or https. Falls back to an empty string
     * under CLI (cron jobs, seed scripts), where there is no HTTP
     * request to inspect — url() then just returns bare paths.
     */
    private static function detectBaseUrl(): string
    {
        if (PHP_SAPI === 'cli') {
            return '';
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? null) == 443)
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        $scheme = $https ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');

        // dirname() of SCRIPT_NAME gives the URL subfolder the
        // CURRENTLY EXECUTING script sits in. That's exactly right
        // when Paths::init() is called once from bootstrap.php, which
        // itself always lives at the project root, so this stays
        // stable no matter which page included bootstrap.php.
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
        $basePath  = rtrim($scriptDir, '/');

        return $scheme . '://' . $host . $basePath;
    }

    private static function join(string ...$segments): string
    {
        $parts = [];
        foreach ($segments as $i => $seg) {
            $seg = str_replace('\\', '/', $seg);
            // Only the first segment (the base path) keeps its
            // leading slash / drive letter — every other segment is
            // trimmed on both sides so "config/" + "/database.php"
            // never produces a double slash.
            $parts[] = $i === 0 ? rtrim($seg, '/') : trim($seg, '/');
        }

        $joined = implode('/', array_filter($parts, static fn(string $p): bool => $p !== ''));

        return DIRECTORY_SEPARATOR === '\\' ? str_replace('/', '\\', $joined) : $joined;
    }
}
