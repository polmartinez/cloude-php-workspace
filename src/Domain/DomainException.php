<?php

declare(strict_types=1);

namespace Cloude\Domain;

/**
 * Marker class for exceptions raised by domain invariants.
 *
 * Throwing one of these from an aggregate root or value-object
 * constructor signals "the request would violate a business rule",
 * which is a different failure mode from "the system broke" — and
 * application layers usually want to translate them into 4xx /
 * user-friendly messages, not 5xx.
 *
 * Catch at the application boundary:
 *
 *   try {
 *       $borrowing->borrow($book, $member);
 *   } catch (\Cloude\Domain\DomainException $e) {
 *       Response::redirect('/?error=' . urlencode($e->getMessage()), 303);
 *       return;
 *   }
 *
 * Extends PHP's `\DomainException` so frameworks / libraries that
 * already catch the standard SPL hierarchy keep working.
 */
class DomainException extends \DomainException {}
