<?php

declare(strict_types=1);

namespace Cloude\Tests\Mail;

use Cloude\Mail\Mailer;
use Cloude\Mail\Transport\MemoryTransport;
use Cloude\Mail\Transport\SendmailTransport;
use Cloude\Mail\Transport\SmtpTransport;
use PHPUnit\Framework\TestCase;

final class MailerTest extends TestCase
{
    public function testSendDispatchesToTransport(): void
    {
        $t = new MemoryTransport();
        $mailer = new Mailer($t);

        $mailer->send([
            'to' => 'a@x.com', 'from' => 's@x.com',
            'subject' => 'Hi', 'body' => 'Body',
        ]);

        self::assertCount(1, $t->sent());
        self::assertSame('Hi', $t->sent()[0]['subject']);
    }

    public function testDefaultsAreMergedFirstAndOverridable(): void
    {
        $t = new MemoryTransport();
        $mailer = new Mailer($t, ['from' => 'default@x.com', 'reply_to' => 'support@x.com']);

        // Caller doesn't supply 'from' — default kicks in
        $mailer->send([
            'to' => 'a@x.com', 'subject' => 'Hi', 'body' => 'Body',
        ]);
        self::assertSame('default@x.com', $t->sent()[0]['from']);
        self::assertSame('support@x.com', $t->sent()[0]['reply_to']);

        // Caller overrides default
        $mailer->send([
            'to' => 'a@x.com', 'from' => 'override@x.com',
            'subject' => 'Hi', 'body' => 'Body',
        ]);
        self::assertSame('override@x.com', $t->sent()[1]['from']);
    }

    public function testValidationRejectsMissingFields(): void
    {
        $mailer = new Mailer(new MemoryTransport());
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("missing required field 'subject'");
        $mailer->send(['to' => 'a@x.com', 'from' => 's@x.com', 'body' => 'no subject']);
    }

    public function testForgeDispatchesOnTransport(): void
    {
        $smtp = Mailer::forge([
            'transport' => 'smtp', 'host' => 'localhost', 'port' => 1025, 'tls' => false,
        ]);
        self::assertInstanceOf(SmtpTransport::class, $smtp->transport());

        $sendmail = Mailer::forge([
            'transport' => 'sendmail',
        ]);
        self::assertInstanceOf(SendmailTransport::class, $sendmail->transport());
    }

    public function testForgeRejectsUnknownTransport(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown mail transport 'snail'");
        Mailer::forge(['transport' => 'snail']);
    }

    public function testForgeRejectsSmtpWithoutHost(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("'smtp' transport needs 'host'");
        Mailer::forge(['transport' => 'smtp']);
    }

    public function testForgeCarriesDefaultsForFromAndReplyTo(): void
    {
        $t = new MemoryTransport();
        $mailer = Mailer::forge([
            'transport' => 'sendmail',
            'from'      => 'noreply@x.com',
            'reply_to'  => 'support@x.com',
        ]);
        // Replace transport so we can capture without actually invoking sendmail.
        $rc = new \ReflectionClass($mailer);
        $rp = $rc->getProperty('transport');
        $rp->setAccessible(true);
        $rp->setValue($mailer, $t);

        $mailer->send(['to' => 'a@x.com', 'subject' => 'Hi', 'body' => 'Body']);
        self::assertSame('noreply@x.com', $t->sent()[0]['from']);
        self::assertSame('support@x.com', $t->sent()[0]['reply_to']);
    }

    public function testMemoryTransportPreservesRenderedWireFormat(): void
    {
        $t = new MemoryTransport();
        $mailer = new Mailer($t);
        $mailer->send([
            'to' => 'a@x.com', 'from' => 's@x.com', 'subject' => 'Hi', 'body' => 'Body',
        ]);
        self::assertArrayHasKey('_rendered', $t->sent()[0]);
        self::assertStringContainsString("Subject: Hi\r\n", $t->sent()[0]['_rendered']);
    }

    public function testMemoryTransportResetClearsHistory(): void
    {
        $t = new MemoryTransport();
        $mailer = new Mailer($t);
        $mailer->send(['to' => 'a@x.com', 'from' => 's@x.com', 'subject' => 'A', 'body' => '']);
        $mailer->send(['to' => 'a@x.com', 'from' => 's@x.com', 'subject' => 'B', 'body' => '']);
        self::assertCount(2, $t->sent());
        $t->reset();
        self::assertSame([], $t->sent());
    }

    // ── shortcut constructors ────────────────────────────────────────────────

    public function testZeroArgConstructorFallsBackToSendmail(): void
    {
        $mailer = new Mailer();
        self::assertInstanceOf(SendmailTransport::class, $mailer->transport());
    }

    public function testForgeWithNoArgsReadsFromCloudeConfig(): void
    {
        $tmp = sys_get_temp_dir() . '/cloude-mail-cfg-' . bin2hex(random_bytes(4));
        @mkdir($tmp, 0755, true);
        file_put_contents($tmp . '/mail.php', "<?php return [
            'transport' => 'sendmail',
            'from'      => 'auto@example.com',
        ];");

        \Cloude\Config::reset();
        \Cloude\Config::setConfigPath($tmp);
        try {
            $mailer = Mailer::forge();
            self::assertInstanceOf(SendmailTransport::class, $mailer->transport());

            // Defaults from config flow through.
            $rc = new \ReflectionClass($mailer);
            $rp = $rc->getProperty('defaults');
            $rp->setAccessible(true);
            self::assertSame(['from' => 'auto@example.com'], $rp->getValue($mailer));
        } finally {
            @unlink($tmp . '/mail.php');
            @rmdir($tmp);
            \Cloude\Config::reset();
        }
    }

    public function testForgeWithNoArgsAndNoCloudeConfigEntryThrows(): void
    {
        \Cloude\Config::reset();
        \Cloude\Config::setConfigPath(sys_get_temp_dir());          // no mail.php there
        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage("'mail' entry in Cloude\\Config");
            Mailer::forge();
        } finally {
            \Cloude\Config::reset();
        }
    }
}
