<?php
declare(strict_types=1);

namespace Core;

/**
 * Request
 * -------
 * A thin, sane wrapper around PHP's raw superglobals — the fix for
 * another recurring set of PHP annoyances:
 *
 *   - $_POST / $_GET / $_REQUEST all behave slightly differently and
 *     none of them warn you when a key is simply missing; you either
 *     suppress notices everywhere or write isset() checks everywhere.
 *   - Plain HTML forms can only send GET or POST, so anything wanting
 *     PUT/PATCH/DELETE semantics needs a spoofed hidden field, which
 *     nothing reads for you automatically.
 *   - Detecting an AJAX/JSON request means remembering the exact
 *     header name every single time.
 *
 * USAGE
 * -----
 *   $request = Request::capture();
 *   $email   = $request->trimmed('email');
 *   $page    = (int) $request->query('page', '1');
 *   if ($request->isPost()) { ... }
 */
final class Request
{
    private array $query;
    private array $body;
    private array $server;
    private array $files;

    private function __construct(array $query, array $body, array $server, array $files)
    {
        $this->query = $query;
        $this->body = $body;
        $this->server = $server;
        $this->files = $files;
    }

    /** Build a Request from PHP's actual superglobals — normal runtime usage. */
    public static function capture(): self
    {
        return new self($_GET, $_POST, $_SERVER, $_FILES);
    }

    /** Build a Request from explicit arrays — invaluable for unit tests. */
    public static function fromArrays(array $query = [], array $body = [], array $server = [], array $files = []): self
    {
        return new self($query, $body, $server, $files);
    }

    // ---------------------------------------------------------------
    // Method / routing helpers
    // ---------------------------------------------------------------

    /**
     * The real HTTP method, honouring a spoofed "_method" field so a
     * plain HTML <form> (GET/POST only) can still signal
     * PUT/PATCH/DELETE to your own routing logic.
     */
    public function method(): string
    {
        $method = strtoupper((string) ($this->server['REQUEST_METHOD'] ?? 'GET'));

        if ($method === 'POST' && isset($this->body['_method'])) {
            $spoofed = strtoupper((string) $this->body['_method']);
            if (in_array($spoofed, ['PUT', 'PATCH', 'DELETE'], true)) {
                $method = $spoofed;
            }
        }

        return $method;
    }

    public function isMethod(string $method): bool
    {
        return $this->method() === strtoupper($method);
    }

    public function isGet(): bool
    {
        return $this->isMethod('GET');
    }

    public function isPost(): bool
    {
        return $this->isMethod('POST');
    }

    /** True for XMLHttpRequest-style requests (jQuery/fetch sending the standard header). */
    public function isAjax(): bool
    {
        return strtolower((string) ($this->server['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    }

    /** True if the client's Accept header explicitly wants JSON back. */
    public function wantsJson(): bool
    {
        return str_contains(strtolower((string) ($this->server['HTTP_ACCEPT'] ?? '')), 'application/json');
    }

    // ---------------------------------------------------------------
    // Input access
    // ---------------------------------------------------------------

    /** GET (query-string) parameters only. */
    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /** POST (body) parameters only. */
    public function post(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    /** POST first, falling back to GET — the common case for a form handler. */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    /** Same as input(), but trims strings — the single most common recurring form bug. */
    public function trimmed(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);
        return is_string($value) ? trim($value) : $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->body) || array_key_exists($key, $this->query);
    }

    /** True only if the key exists AND isn't an empty string — catches "submitted but left blank". */
    public function filled(string $key): bool
    {
        $value = $this->input($key);
        return $value !== null && $value !== '';
    }

    /** All GET + POST merged, POST taking precedence. */
    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    /** Only the given keys — handy for passing a clean, predictable array straight into a query. */
    public function only(array $keys): array
    {
        return array_intersect_key($this->all(), array_flip($keys));
    }

    /** Everything except the given keys — e.g. stripping _token/_method before further use. */
    public function except(array $keys): array
    {
        return array_diff_key($this->all(), array_flip($keys));
    }

    // ---------------------------------------------------------------
    // Files / server / headers
    // ---------------------------------------------------------------

    /**
     * Raw $_FILES entry for a single named file input, or null if the
     * field is absent or no file was actually chosen. Only handles
     * single-file inputs as-is — a multi-file array input
     * (name="photos[]") needs its own normalization pass before use.
     */
    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        return $file;
    }

    public function server(string $key, mixed $default = null): mixed
    {
        return $this->server[$key] ?? $default;
    }

    /** Case-insensitive request header lookup, e.g. header('Content-Type'). */
    public function header(string $name, mixed $default = null): mixed
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $this->server[$key] ?? $default;
    }

    /**
     * Best-effort real client IP. Only trusts the X-Forwarded-For
     * header when you explicitly ask it to — trusting it by default
     * on a server that isn't actually sitting behind a known,
     * configured proxy is a trivial IP-spoofing vector.
     */
    public function ip(bool $trustProxyHeader = false): string
    {
        if ($trustProxyHeader && !empty($this->server['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', (string) $this->server['HTTP_X_FORWARDED_FOR']);
            return trim($parts[0]);
        }
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }
}

/**
 * Response
 * --------
 * The output-side counterpart to Request — the fix for header() /
 * echo / exit calls scattered across a script, where the single most
 * common bug is a redirect header() call with no matching exit, after
 * which the rest of the script keeps running (and sometimes writing)
 * anyway.
 *
 * USAGE
 * -----
 *   Response::json(['ok' => true]);
 *   Response::redirect(Paths::url('admin/login.php'));
 *   Response::back(Paths::url('admin/bookings.php'));
 */
final class Response
{
    /** Send a JSON body with the correct header, then stop execution. */
    public static function json(mixed $data, int $status = 200): void
    {
        self::status($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Redirect and stop execution in one call, so it's impossible to
     * forget the exit that has to follow a Location header.
     */
    public static function redirect(string $url, int $status = 302): void
    {
        self::status($status);
        header('Location: ' . $url);
        exit;
    }

    /** Redirect back to the referring page, falling back to a known-good default. */
    public static function back(string $fallback): void
    {
        self::redirect($_SERVER['HTTP_REFERER'] ?? $fallback);
    }

    /** A plain text/HTML body with an explicit status — same "no forgotten exit" guarantee. */
    public static function send(string $body, int $status = 200, string $contentType = 'text/html; charset=utf-8'): void
    {
        self::status($status);
        header('Content-Type: ' . $contentType);
        echo $body;
        exit;
    }

    public static function status(int $code): void
    {
        http_response_code($code);
    }
}
