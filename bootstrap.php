<?php
declare(strict_types=1);

/**
 * bootstrap.php
 * -------------
 * Single entry point that wires the reusable core together. Every
 * page in the app should start with one of:
 *
 *   require_once __DIR__ . '/bootstrap.php';          // from project root
 *   require_once dirname(__DIR__) . '/bootstrap.php';  // from admin/ or user/
 *
 * Order matters below — each numbered step depends on the one before
 * it, so don't reorder without checking why.
 */

// ---------------------------------------------------------------
// 1. Error visibility.
// ---------------------------------------------------------------
// Flip this to false before any submission/demo/deployment — raw PHP
// errors printed to a browser are an information leak (they can show
// file paths, sometimes query fragments) and look unfinished to an
// examiner. Keep it true only while actively developing locally.
const APP_DEV_MODE = true;

if (APP_DEV_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

// ---------------------------------------------------------------
// 2. Timezone.
// ---------------------------------------------------------------
// PHP raises a warning on every single date/time call if this isn't
// set explicitly somewhere. Set it once, here, for the whole app.
date_default_timezone_set('Asia/Kolkata');

// ---------------------------------------------------------------
// 3. Core classes, in dependency order.
// ---------------------------------------------------------------
require_once __DIR__ . '/config/paths.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/Http.php';

use Core\Paths;

// ---------------------------------------------------------------
// 4. Resolve the project root ONCE, here.
// ---------------------------------------------------------------
// Every other file uses Paths:: after this instead of its own
// relative-path guesswork.
Paths::init(__DIR__);

// ---------------------------------------------------------------
// 5. Optional .env loading — no Composer required.
// ---------------------------------------------------------------
// Real environment variables (set on an actual host) always win;
// this only fills in gaps for local development. Copy .env.example
// to .env and edit it rather than hardcoding secrets in database.php.
$envFile = __DIR__ . '/.env';
if (is_file($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $value = trim($value, "\"'");
        if (!array_key_exists($key, $_ENV)) {
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }
}

// ---------------------------------------------------------------
// 6. Sessions.
// ---------------------------------------------------------------
// Every page needs a session for auth + CSRF + flash messages, so
// start it once here instead of scattering session_start() calls
// across the app and eventually hitting "headers already sent".
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}