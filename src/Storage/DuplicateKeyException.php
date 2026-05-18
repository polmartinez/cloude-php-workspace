<?php

declare(strict_types=1);

namespace Cloude\Storage;

/**
 * Raised when an INSERT/UPDATE collides with a UNIQUE/PRIMARY-KEY index
 * (MySQL driver code 1062 under SQLSTATE 23000, Postgres SQLSTATE 23505).
 *
 * Catch this when you want to render a friendly "already registered"
 * message in a form handler.
 */
class DuplicateKeyException extends IntegrityConstraintException {}
