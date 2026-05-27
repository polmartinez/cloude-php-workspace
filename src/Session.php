<?php

declare(strict_types=1);

namespace Cloude;

/**
 * Static façade over PHP's native session machinery. Three jobs:
 *
 *   1. **Hardened defaults** — `start()` applies the security flags
 *      every project re-discovers (httponly, samesite=Lax, secure when
 *      HTTPS is on) and lets you override per-call.
 *
 *   2. **Typed access** — `get` / `set` / `has` / `forget` / `all`
 *      instead of scattering `$_SESSION` reads through the codebase.
 *
 *   3. **Flash messages** — one-shot data that survives exactly one
 *      redirect, so form handlers can stash a `success` / `error`
 *      message for the next render. Implementation: two buckets
 *      (`_flash.current` readable now, `_flash.next` for what we set
 *      this request). `start()` promotes next → current and resets
 *      next, so a flash written in request N is readable only during
 *      request N+1.
 *
 *   4. **CSRF tokens** — `csrfToken()` mints a per-session token; the
 *      paired form/handler pattern calls `checkCsrf($posted)` (uses
 *      `hash_equals` for constant-time comparison).
 *
 * Lazy by default: the FIRST `get` / `set` / `has` / `forget` / `flash`
 * / `csrfToken` / `regenerate` call auto-starts the session with the
 * hardened defaults below. Routes that never touch session state
 * never pay any cost — no cookie, no header — so pure-JSON / MCP
 * handlers can ignore the class entirely.
 *
 * Call `Session::start([], $cookieParams)` explicitly BEFORE the first
 * `get`/`set` only when you need to override the cookie params
 * (different `samesite`, custom `domain`, longer `lifetime`, etc.).
 * `start()` is idempotent — first call wins, later calls are no-ops.
 *
 * ## Usage
 *
 *   // Most common: just use it. The session starts on first access.
 *   Session::set('user_id', 42);
 *
 *   // Or start explicitly to override cookie params:
 *   Session::start([], ['samesite' => 'Strict']);
 *   Session::set('user_id', 42);
 *
 *   if (Session::has('user_id')) {
 *       $userId = Session::get('user_id');
 *   }
 *
 *   Session::set('user_id', 42);
 *   Session::regenerate();                  // on login (security)
 *
 *   Session::flash('success', 'Saved.');
 *   $msg = Session::pullFlash('success');   // available on next request
 *
 *   // In a form view:
 *   <input type="hidden" name="_csrf" value="<?= Session::csrfToken() ?>">
 *
 *   // In the POST handler:
 *   if (!Session::checkCsrf((string) Input::post('_csrf'))) {
 *       throw new HttpException(419, 'CSRF token mismatch');
 *   }
 */
final class Session
{
    private const FLASH_KEY        = '_cloude_flash_now';
    private const FLASH_NEXT_KEY   = '_cloude_flash_next';
    private const CSRF_KEY         = '_cloude_csrf';

    /**
     * Start (or resume) the session with hardened defaults. Safe to
     * call multiple times — no-op once a session is already active.
     *
     * Default cookie params:
     *   - httponly = true        (no JS access)
     *   - samesite = 'Lax'       (modern XSS / CSRF default)
     *   - secure   = true        when HTTPS is on
     *
     * Override any of them via `$cookieParams`. Pass `$options` for
     * other `session_start()` options (name, save_path, ...).
     *
     * @param array<string, mixed> $options       Forwarded to session_start()
     * @param array<string, mixed> $cookieParams  Merged with defaults
     */
    public static function start(array $options = [], array $cookieParams = []): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        if (session_status() === PHP_SESSION_DISABLED) {
            throw new \RuntimeException('PHP sessions are disabled at the engine level');
        }
        if (headers_sent($file, $line)) {
            throw new \RuntimeException("Cannot start session — headers already sent at $file:$line");
        }

        $defaults = [
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on'),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
        session_set_cookie_params(array_replace($defaults, $cookieParams));

        session_start($options);

        self::promoteFlashBucket();
    }

    /** Returns the current session ID. */
    public static function id(): string
    {
        return (string) session_id();
    }

    /**
     * Rotate the session id (call after authentication to prevent
     * fixation attacks). Pass `false` to keep the old session's data
     * around at the old ID — handy for logout flows that want to
     * preserve breadcrumbs.
     */
    public static function regenerate(bool $deleteOld = true): void
    {
        self::ensureStarted();
        session_regenerate_id($deleteOld);
    }

    /** Destroy the session entirely: clear $_SESSION + invalidate the cookie. */
    public static function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies') && !headers_sent()) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                [
                    'expires'  => time() - 42000,
                    'path'     => $params['path'],
                    'domain'   => $params['domain'],
                    'secure'   => $params['secure'],
                    'httponly' => $params['httponly'],
                    'samesite' => $params['samesite'] ?? 'Lax',
                ],
            );
        }
        session_destroy();
    }

    // ── value access ──────────────────────────────────────────────────────

    public static function get(string $key, mixed $default = null): mixed
    {
        self::ensureStarted();
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        self::ensureStarted();
        $_SESSION[$key] = $value;
    }

    public static function has(string $key): bool
    {
        self::ensureStarted();
        return array_key_exists($key, $_SESSION);
    }

    public static function forget(string $key): void
    {
        self::ensureStarted();
        unset($_SESSION[$key]);
    }

    /**
     * Snapshot of every key (excluding the internal flash + CSRF
     * buckets that are framework concerns, not app data).
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        self::ensureStarted();
        $out = $_SESSION;
        unset($out[self::FLASH_KEY], $out[self::FLASH_NEXT_KEY], $out[self::CSRF_KEY]);
        return $out;
    }

    // ── flash ─────────────────────────────────────────────────────────────

    /**
     * Stash a value for the NEXT request. Available via `pullFlash()`
     * once exactly, then disappears.
     */
    public static function flash(string $key, mixed $value): void
    {
        self::ensureStarted();
        $_SESSION[self::FLASH_NEXT_KEY][$key] = $value;
    }

    /**
     * Read and consume a flashed value. Returns `$default` when nothing
     * was flashed under `$key`. The value is removed after the call.
     */
    public static function pullFlash(string $key, mixed $default = null): mixed
    {
        self::ensureStarted();
        if (!isset($_SESSION[self::FLASH_KEY][$key])) {
            return $default;
        }
        $value = $_SESSION[self::FLASH_KEY][$key];
        unset($_SESSION[self::FLASH_KEY][$key]);
        return $value;
    }

    public static function hasFlash(string $key): bool
    {
        self::ensureStarted();
        return isset($_SESSION[self::FLASH_KEY][$key]);
    }

    /**
     * Re-flash a previously-pulled value back into the bucket so it
     * survives one more request. Useful when a handler decides
     * mid-flight that the message should reach the *next* page rather
     * than the current one.
     */
    public static function reflash(string $key): void
    {
        self::ensureStarted();
        if (!isset($_SESSION[self::FLASH_KEY][$key])) {
            return;
        }
        $_SESSION[self::FLASH_NEXT_KEY][$key] = $_SESSION[self::FLASH_KEY][$key];
    }

    // ── CSRF ──────────────────────────────────────────────────────────────

    /**
     * Returns the per-session CSRF token, minting one on first call.
     * Same token reused across the session — rotating per-request is
     * over-engineered for the threat model the framework targets.
     */
    public static function csrfToken(): string
    {
        self::ensureStarted();
        if (!isset($_SESSION[self::CSRF_KEY])) {
            $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::CSRF_KEY];
    }

    /**
     * Constant-time compare against the session's CSRF token. Returns
     * false when no session is active, no token has been minted yet,
     * or the supplied value doesn't match.
     */
    public static function checkCsrf(string $token): bool
    {
        self::ensureStarted();
        $expected = $_SESSION[self::CSRF_KEY] ?? null;
        if (!is_string($expected) || $expected === '') {
            return false;
        }
        return hash_equals($expected, $token);
    }

    // ── internals ─────────────────────────────────────────────────────────

    /**
     * Move "next request's flashes" into the "current request" bucket
     * and clear the next slot. Called by `start()`. Idempotent within
     * a single request because we only call it once at session start.
     */
    private static function promoteFlashBucket(): void
    {
        $_SESSION[self::FLASH_KEY] = $_SESSION[self::FLASH_NEXT_KEY] ?? [];
        $_SESSION[self::FLASH_NEXT_KEY] = [];
    }

    /**
     * Auto-starts the session on first use with the hardened cookie
     * defaults declared in `start()` (`httponly`, `samesite=Lax`,
     * `secure` on HTTPS). Idempotent — no-op once a session is active,
     * so explicit `Session::start([], $cookieParams)` BEFORE the first
     * `get`/`set` still wins for cookie-param overrides.
     *
     * Routes that genuinely don't want a session simply don't call
     * `Session::*` — the session is never started, no cookie is
     * issued. The lazy path keeps the cost out of pure-JSON / MCP
     * handlers that never touch session state.
     */
    private static function ensureStarted(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            self::start();
        }
    }
}
