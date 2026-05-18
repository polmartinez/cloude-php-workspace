<?php

declare(strict_types=1);

namespace Cloude\Storage;

/**
 * Raised when a referenced column doesn't exist (SQLSTATE 42S22 on
 * MySQL/SQLite, 42703 on Postgres). Typo or stale schema.
 */
class ColumnNotFoundException extends StorageException {}
