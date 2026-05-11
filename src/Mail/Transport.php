<?php

declare(strict_types=1);

namespace Cloude\Mail;

/**
 * Contract every mail transport implements.
 *
 * The `$email` array shape is the same across all transports:
 *
 *   [
 *     'to'       => string|list<string>,   // required
 *     'from'     => string,                // required
 *     'subject'  => string,                // required
 *     'body'     => string,                // required
 *     'html'     => bool,                  // default false (text/plain)
 *     'cc'       => list<string>,          // optional
 *     'bcc'      => list<string>,          // optional
 *     'reply_to' => string,                // optional
 *     'headers'  => array<string,string>,  // optional extra headers
 *   ]
 *
 * Validation lives in `Mailer`; transports trust the input.
 */
interface Transport
{
    /**
     * @param array<string,mixed> $email
     */
    public function send(array $email): bool;
}
