<?php

declare(strict_types=1);

// BASE_URL — scheme + host. Auto-detected from the request, so it works
// the same under `php -S`, Apache, nginx + PHP-FPM, or any other SAPI.
$scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
if (!defined('BASE_URL')) {
    define('BASE_URL', $scheme . '://' . $host);
}

// Project root — one level above www/.
if (!defined('ROOT_DIR')) {
    define('ROOT_DIR', dirname(__DIR__));
}

// Where the JSON-per-contact files live. The repository creates files under
// $DATA_DIR/contacts/{slug}.json on write.
if (!defined('DATA_DIR')) {
    define('DATA_DIR', ROOT_DIR . '/data');
}

// Show errors locally, hide them in production.
if (!defined('DEBUG')) {
    define('DEBUG', true);
}
