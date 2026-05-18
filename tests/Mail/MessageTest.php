<?php

declare(strict_types=1);

namespace Cloude\Tests\Mail;

use Cloude\Mail\Message;
use Cloude\Testing\TestCase;

final class MessageTest extends TestCase
{
    public function testMinimalMessageHasRequiredHeaders(): void
    {
        $msg = Message::build([
            'to'      => 'a@b.com',
            'from'    => 'sender@x.com',
            'subject' => 'Hello',
            'body'    => 'Hi there',
        ]);

        self::assertStringContainsString("To: a@b.com\r\n", $msg);
        self::assertStringContainsString("From: sender@x.com\r\n", $msg);
        self::assertStringContainsString("Subject: Hello\r\n", $msg);
        self::assertStringContainsString("MIME-Version: 1.0\r\n", $msg);
        self::assertStringContainsString("Content-Type: text/plain; charset=UTF-8\r\n", $msg);
        self::assertStringContainsString("\r\n\r\nHi there", $msg);                 // blank line then body
    }

    public function testHtmlFlagSwitchesContentType(): void
    {
        $msg = Message::build([
            'to' => 'a@b.com', 'from' => 'x@y.com', 'subject' => 'S', 'body' => '<p>Hi</p>',
            'html' => true,
        ]);
        self::assertStringContainsString('Content-Type: text/html; charset=UTF-8', $msg);
    }

    public function testMultipleRecipientsAreCommaJoined(): void
    {
        $msg = Message::build([
            'to' => ['a@x.com', 'b@x.com', 'c@x.com'],
            'from' => 's@x.com', 'subject' => 'S', 'body' => '',
        ]);
        self::assertStringContainsString('To: a@x.com, b@x.com, c@x.com', $msg);
    }

    public function testNonAsciiSubjectGetsRfc2047Encoded(): void
    {
        $msg = Message::build([
            'to' => 'a@x.com', 'from' => 's@x.com',
            'subject' => 'Política española',
            'body' => '',
        ]);
        // Body is base64'd within =?UTF-8?B?...?=
        self::assertMatchesRegularExpression(
            '/Subject: =\?UTF-8\?B\?[A-Za-z0-9+\/=]+\?=/',
            $msg,
        );
        // Round-trip: extract and decode → original
        if (preg_match('/Subject: =\?UTF-8\?B\?([^?]+)\?=/', $msg, $m)) {
            self::assertSame('Política española', base64_decode($m[1]));
        }
    }

    public function testCcAppearsInHeaders(): void
    {
        $msg = Message::build([
            'to' => 'a@x.com', 'from' => 's@x.com', 'subject' => 'S', 'body' => '',
            'cc' => ['cc1@x.com', 'cc2@x.com'],
        ]);
        self::assertStringContainsString('Cc: cc1@x.com, cc2@x.com', $msg);
    }

    public function testBccIsNotWrittenAsHeader(): void
    {
        // Bcc must be invisible to recipients; we ship it via the
        // envelope only. SendmailTransport will splice the header
        // back in to drive `sendmail -t`, but Message::build itself
        // omits it.
        $msg = Message::build([
            'to' => 'a@x.com', 'from' => 's@x.com', 'subject' => 'S', 'body' => '',
            'bcc' => ['secret@x.com'],
        ]);
        self::assertStringNotContainsString('Bcc:', $msg);
        self::assertStringNotContainsString('secret@x.com', $msg);
    }

    public function testReplyToHeader(): void
    {
        $msg = Message::build([
            'to' => 'a@x.com', 'from' => 's@x.com', 'subject' => 'S', 'body' => '',
            'reply_to' => 'support@x.com',
        ]);
        self::assertStringContainsString('Reply-To: support@x.com', $msg);
    }

    public function testCustomHeadersAreAppended(): void
    {
        $msg = Message::build([
            'to' => 'a@x.com', 'from' => 's@x.com', 'subject' => 'S', 'body' => '',
            'headers' => [
                'X-Mailer'   => 'cloude/0.27',
                'X-Priority' => '3',
            ],
        ]);
        self::assertStringContainsString('X-Mailer: cloude/0.27', $msg);
        self::assertStringContainsString('X-Priority: 3', $msg);
    }

    public function testEnvelopeRecipientsCollectsToAndCcAndBcc(): void
    {
        $rcpts = Message::envelopeRecipients([
            'to'  => ['a@x.com', 'b@x.com'],
            'cc'  => 'c@x.com',
            'bcc' => ['hidden@x.com'],
        ]);
        sort($rcpts);
        self::assertSame(['a@x.com', 'b@x.com', 'c@x.com', 'hidden@x.com'], $rcpts);
    }

    public function testEnvelopeRecipientsExtractsAddressFromDisplayName(): void
    {
        $rcpts = Message::envelopeRecipients([
            'to' => '"Ada Lovelace" <ada@example.com>',
        ]);
        self::assertSame(['ada@example.com'], $rcpts);
    }

    public function testExtractAddressHandlesBothShapes(): void
    {
        self::assertSame('a@b.com', Message::extractAddress('a@b.com'));
        self::assertSame('a@b.com', Message::extractAddress('Ada <a@b.com>'));
        self::assertSame('a@b.com', Message::extractAddress('  a@b.com  '));
    }

    public function testCrlfLineEndings(): void
    {
        $msg = Message::build([
            'to' => 'a@x.com', 'from' => 's@x.com', 'subject' => 'S', 'body' => 'body',
        ]);
        // Headers are separated by CRLF (mandatory for SMTP)
        self::assertStringContainsString("\r\n", $msg);
        // Bare LF is never used between headers
        self::assertSame(0, substr_count($msg, "\n") - substr_count($msg, "\r\n"));
    }
}
