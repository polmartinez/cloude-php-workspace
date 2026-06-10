<?php

declare(strict_types=1);

/**
 * Framework-shipped defaults for `Cloude\Session`.
 *
 * Auto-loaded by `Cloude\Config`. The app's own `app/config/session.php`
 * deep-merges onto this base, so projects only declare what differs.
 *
 * Two groups of knobs:
 *
 *   - `cookie_*`  → forwarded to `session_set_cookie_params()` (controls
 *     what gets written into the `Set-Cookie` header for the session id).
 *   - `gc_maxlifetime` / `save_path` / `name` → applied via `ini_set()`
 *     before `session_start()` runs. These configure server-side storage.
 *
 * NOTE the asymmetry: `cookie_lifetime` is how long the BROWSER keeps
 * the cookie; `gc_maxlifetime` is how long the SERVER keeps the
 * `$_SESSION` data file. Set both — a long cookie lifetime is useless
 * if PHP garbage-collects the data after 24 minutes (the PHP default).
 *
 * Anything passed explicitly to `Session::start($options, $cookieParams)`
 * still wins over these — the config is only the default.
 */

return [
    // ── Cookie params (Set-Cookie header) ────────────────────────────────
    // 0 = session cookie (dies when the browser closes). Set a positive
    // number of seconds to make the cookie survive across browser restarts.
    'cookie_lifetime' => 0,
    'cookie_path'     => '/',
    'cookie_domain'   => '',

    // `null` → auto-detect (true when HTTPS is on). Override to a bool to
    // force the value regardless of the current request scheme.
    'cookie_secure'   => null,

    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',

    // ── Server-side storage (ini_set'd before session_start) ────────────
    // Maximum seconds PHP keeps an inactive session file before its
    // garbage collector deletes it. `null` → leave php.ini's value alone
    // (typically 1440 = 24 minutes). Pair with cookie_lifetime when you
    // want long-lived sessions.
    'gc_maxlifetime'  => null,

    // Override session name (the cookie's name). null → keep PHP's
    // default (`PHPSESSID`).
    'name'            => null,

    // ── Storage backend ─────────────────────────────────────────────────
    // `null` keeps php.ini's `session.save_handler` (typically 'files').
    // Set to one of:
    //   - 'files'      → on-disk; uses 'save_path' below.
    //   - 'redis'      → ext-redis; reads the 'redis' block below.
    //   - 'memcached'  → ext-memcached; reads the 'memcached' block below.
    'handler'         => null,

    // Where on disk PHP writes session files (when handler = 'files').
    // null → keep php.ini's. Useful in containerized setups where /tmp
    // is ephemeral or shared between containers.
    'save_path'       => null,

    // ── Redis backend (when handler = 'redis') ───────────────────────────
    // Maps to ext-redis's session.save_path URI:
    //   tcp://host:port?database=N&prefix=...&auth=...&timeout=...
    'redis' => [
        'host'     => '127.0.0.1',
        'port'     => 6379,
        'database' => 0,
        'password' => null,            // null → no AUTH
        'prefix'   => 'PHPSESSID:',
        'timeout'  => 2.0,             // connect timeout (seconds)
        // 'persistent_id' => 'session-pool',   // optional
    ],

    // ── Memcached backend (when handler = 'memcached') ──────────────────
    // List of [host, port] tuples; PHP joins them as
    // `host1:port1,host2:port2`.
    'memcached' => [
        'servers' => [
            ['127.0.0.1', 11211],
        ],
    ],
];
