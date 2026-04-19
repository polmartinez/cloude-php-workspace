<?php

declare(strict_types=1);

// ============================================================
// CONFIGURATION
// ============================================================
// Copy this file to config.php and add your secrets.
// Do NOT commit the copy if it contains private keys.
// ============================================================

$scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

define('BASE_URL', $scheme . '://' . $host);

// Project root directory (one level above www/).
define('ROOT_DIR', dirname(__DIR__));

// Debug mode: show PHP errors on screen.
define('DEBUG', true);

// Example content directory, when the project serves .md files:
// define('CONTENT_DIR', ROOT_DIR . '/content');
