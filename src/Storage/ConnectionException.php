<?php

declare(strict_types=1);

namespace Cloude\Storage;

/**
 * Raised when PDO cannot reach the database — DSN typo, server down,
 * auth failure (SQLSTATE class 08, plus a few HY000 driver-level errors
 * that surface during connect).
 */
class ConnectionException extends StorageException {}
