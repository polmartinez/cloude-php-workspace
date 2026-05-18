<?php

declare(strict_types=1);

namespace Cloude\Storage;

/**
 * Raised for integrity-constraint violations other than duplicate keys
 * (SQLSTATE class 23). Foreign-key failures, NOT NULL violations, CHECK
 * constraint failures all land here.
 */
class IntegrityConstraintException extends StorageException {}
