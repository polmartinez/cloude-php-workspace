<?php

declare(strict_types=1);

namespace Cloude\Storage;

/**
 * Raised when the referenced table doesn't exist (SQLSTATE 42S02 on
 * MySQL/SQLite, 42P01 on Postgres). Usually a migration that hasn't run.
 */
class TableNotFoundException extends StorageException {}
