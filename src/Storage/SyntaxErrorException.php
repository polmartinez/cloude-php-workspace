<?php

declare(strict_types=1);

namespace Cloude\Storage;

/**
 * Raised when the database parser rejects the SQL (SQLSTATE 42000 on
 * MySQL, 42601 on Postgres). Usually a builder bug or hand-written SQL
 * that doesn't match the active driver dialect.
 */
class SyntaxErrorException extends StorageException {}
