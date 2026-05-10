<?php

declare(strict_types=1);

$scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
if (!defined('BASE_URL')) {
    define('BASE_URL', $scheme . '://' . $host);
}

if (!defined('ROOT_DIR')) {
    define('ROOT_DIR', dirname(__DIR__));
}

if (!defined('DATA_DIR')) {
    define('DATA_DIR', ROOT_DIR . '/data');
}

if (!defined('DEBUG')) {
    define('DEBUG', true);
}
