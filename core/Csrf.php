<?php

declare(strict_types=1);

namespace Core;

/**
 * Csrf
 * ----
 * Cross-Site Request Forgery token generation and verification — a
 * security control that's genuinely painful to retrofit, which is
 * exactly why it belongs in core/ from day one rather than being
 * bolted onto every form after the fact.
 *
 * WHAT IT ACTUALLY PREVENTS
 * --------------------------
 * Without this, a malicious page you never built can silently POST to
 * your app (e.g. <form action="yoursite.com/admin/rooms.php"> on a
 * page the admin happens to have open in another tab) and the browser
 * will attach the admin's real session cookie automatically. The
 * request looks completely legitimate to your server because the
 * cookie is real — the token is what proves the submission actually
 * came from a page YOUR app rendered, not from somewhere else riding
 * on the same logged-in session.
 *
 * USAGE
 * -----
 *   // Inside any <form method="post">:
 *   <?= Csrf::field() ?>
 *
 *   // First line of the handler that processes that POST:
 *   Csrf::verifyRequest($request);
 */
final class Csrf
{
    private const SESSION_KEY = 'csrf_token';

    private function __construct()
    {
        // Static-only class.
    }

    /**
     * The current token, generated on first use. The SAME token is
     * reused for the life of the session — NOT regenerated on every
     * page load. Regenerating per-load is a very common self-inflicted
     * bug: it breaks the back button and breaks having two of your
     * own forms open in different tabs, because whichever one you
     * submit second is now carrying a stale token.
     */
    public static function token(): string
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::SESSION_KEY];
    }

    /** Ready-to-echo hidden input for a <form>. Escaped, so it's safe to echo unescaped itself. */
    public static function field(): string
    {
        return '<input type="hidden" name="_token" value="'
            . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8')
            . '">';
    }

    /**
     * Timing-safe comparison against the submitted token. Always
     * hash_equals() here — never == or === — because a naive string
     * comparison returns as soon as it hits the first wrong
     * character, and the tiny timing difference that leaks is
     * measurable enough for an attacker to guess a token one
     * character at a time.
     */
    public static function verify(?string $submittedToken): bool
    {
        if ($submittedToken === null || $submittedToken === '') {
            return false;
        }
        $real = $_SESSION[self::SESSION_KEY] ?? null;
        return $real !== null && hash_equals($real, $submittedToken);
    }

    /**
     * Convenience wrapper for the common case: verify the "_token"
     * field on a Request and stop the request with HTTP 419 if it's
     * missing or wrong. Call this as the first line of every POST
     * handler, before touching the database.
     */
    public static function verifyRequest(Request $request): void
    {
        if (!self::verify((string) $request->post('_token'))) {
            Response::send(
                'Your session has expired or this form was submitted incorrectly. Please go back, refresh the page, and try again.',
                419
            );
        }
    }

    /**
     * Rotate to a brand-new token. Call this right after a successful
     * login or logout, so a token an attacker captured before the
     * authentication state changed can't be replayed after it.
     */
    public static function rotate(): void
    {
        $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
    }
}