<?php

declare(strict_types=1);

/**
 * Framework-shipped defaults for the application's general config.
 *
 * Auto-loaded by `Cloude\Config` whenever a consumer app calls
 * `Config::configure(APPPATH . '/config')`. The app's own
 * `app/config/app.php` is deep-merged onto this base, so projects
 * only need to declare the keys that differ.
 *
 * Picked up automatically by:
 *   - `Bootstrap::run()`            → sets the PHP default timezone
 *   - `Config::timezone()`          → typed accessor
 *
 * Everything else here is read on demand via `Config::get('app.*')`.
 */

return [
    // PHP's default timezone. Applied via `date_default_timezone_set()`
    // in `Cloude\Bootstrap::run()`. Affects `Cloude\DateTime`, the mail
    // `Date:` header, every native `date()` / `time()` / `strtotime()`
    // call, and timestamp formatting throughout the app.
    //
    // UTC is the safe default for servers: timestamps are unambiguous
    // and DST changes never bite. Override per project in
    // `app/config/app.php`:
    //
    //   return ['timezone' => 'Europe/Madrid'];
    'timezone' => 'UTC',
];
