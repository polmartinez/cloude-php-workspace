<?php

declare(strict_types=1);

namespace Cloude\Tests\Mail;

use Cloude\Mail\Mailer;
use Cloude\Mail\Transport\MemoryTransport;
use Cloude\Mail\Transport\SendmailTransport;
use Cloude\Mail\Transport\SmtpTransport;
use Cloude\Testing\TestCase;

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

    public function testForgeWithNoArgsReadsEmailConfigKey(): void
    {
        $tmp = sys_get_temp_dir() . '/cloude-mail-cfg-' . bin2hex(random_bytes(4));
        @mkdir($tmp, 0755, true);
        file_put_contents($tmp . '/email.php', "<?php return [
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
            self::assertSame(['from' => 'auto@example.com'], $rp->getValue($mailer));
        } finally {
            @unlink($tmp . '/email.php');
            @rmdir($tmp);
            \Cloude\Config::reset();
        }
    }

    public function testForgeFallsBackToLegacyMailKey(): void
    {
        // Pre-v1.1 used 'mail' as the config key; keep reading it for
        // back-compat so existing projects don't need to rename their files.
        $tmp = sys_get_temp_dir() . '/cloude-mail-cfg-' . bin2hex(random_bytes(4));
        @mkdir($tmp, 0755, true);
        file_put_contents($tmp . '/mail.php', "<?php return [
            'transport' => 'sendmail',
            'from'      => 'legacy@example.com',
        ];");

        \Cloude\Config::reset();
        \Cloude\Config::setConfigPath($tmp);
        try {
            $mailer = Mailer::forge();
            self::assertInstanceOf(SendmailTransport::class, $mailer->transport());

            $rc = new \ReflectionClass($mailer);
            $rp = $rc->getProperty('defaults');
            self::assertSame(['from' => 'legacy@example.com'], $rp->getValue($mailer));
        } finally {
            @unlink($tmp . '/mail.php');
            @rmdir($tmp);
            \Cloude\Config::reset();
        }
    }

    public function testForgeEmailKeyWinsOverLegacyMailKey(): void
    {
        // When both exist, the new 'email' key takes precedence — gives
        // projects a clean migration path: drop in email.php, delete mail.php.
        $tmp = sys_get_temp_dir() . '/cloude-mail-cfg-' . bin2hex(random_bytes(4));
        @mkdir($tmp, 0755, true);
        file_put_contents($tmp . '/email.php', "<?php return [
            'transport' => 'sendmail',
            'from'      => 'new@example.com',
        ];");
        file_put_contents($tmp . '/mail.php', "<?php return [
            'transport' => 'sendmail',
            'from'      => 'old@example.com',
        ];");

        \Cloude\Config::reset();
        \Cloude\Config::setConfigPath($tmp);
        try {
            $mailer = Mailer::forge();
            $rc = new \ReflectionClass($mailer);
            $rp = $rc->getProperty('defaults');
            self::assertSame(['from' => 'new@example.com'], $rp->getValue($mailer));
        } finally {
            @unlink($tmp . '/email.php');
            @unlink($tmp . '/mail.php');
            @rmdir($tmp);
            \Cloude\Config::reset();
        }
    }

    public function testForgeWithNoArgsAndNoCloudeConfigEntryThrows(): void
    {
        \Cloude\Config::reset();
        \Cloude\Config::setConfigPath(sys_get_temp_dir());          // no email.php or mail.php there
        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage("'email' entry in Cloude\\Config");
            Mailer::forge();
        } finally {
            \Cloude\Config::reset();
        }
    }

    /**
     * Compatibility smoke: every config shape from the three transactional
     * providers (MailerSend, Mailgun, SendGrid) must build a working
     * SmtpTransport without complaint. We don't actually open sockets here
     * — the test verifies the factory accepts each shape and produces the
     * right adapter type. Real-network integration is out of scope.
     */
    #[\Cloude\Testing\DataProvider('providerConfigs')]
    public function testForgeAcceptsTransactionalProviderConfigs(string $name, array $config): void
    {
        $mailer = Mailer::forge($config);
        self::assertInstanceOf(
            SmtpTransport::class,
            $mailer->transport(),
            "provider '$name' didn't yield an SmtpTransport",
        );

        // Defaults flow through: when 'from' is in the config, send() picks it up.
        $captured = new MemoryTransport();
        $rc = new \ReflectionClass($mailer);
        $rp = $rc->getProperty('transport');
        $rp->setValue($mailer, $captured);

        $mailer->send([
            'to'      => 'audit@example.com',
            'subject' => 'compat smoke',
            'body'    => '',
        ]);
        self::assertSame(
            'noreply@example.com',
            $captured->sent()[0]['from'],
            "provider '$name' didn't carry the default 'from'",
        );
    }

    /**
     * @return iterable<string, array{string, array<string,mixed>}>
     */
    public static function providerConfigs(): iterable
    {
        yield 'mailersend' => ['mailersend', [
            'transport' => 'smtp',
            'host'      => 'smtp.mailersend.net',
            'port'      => 587,
            'user'      => 'MS_fake_user',
            'pass'      => 'fake_password',
            'tls'       => true,
            'from'      => 'noreply@example.com',
        ]];

        yield 'mailgun_us' => ['mailgun_us', [
            'transport' => 'smtp',
            'host'      => 'smtp.mailgun.org',
            'port'      => 587,
            'user'      => 'postmaster@mg.example.com',
            'pass'      => 'fake-mailgun-smtp-pass',
            'tls'       => true,
            'from'      => 'noreply@example.com',
        ]];

        yield 'mailgun_eu' => ['mailgun_eu', [
            'transport' => 'smtp',
            'host'      => 'smtp.eu.mailgun.org',                    // EU region
            'port'      => 587,
            'user'      => 'postmaster@mg.example.com',
            'pass'      => 'fake-mailgun-smtp-pass',
            'tls'       => true,
            'from'      => 'noreply@example.com',
        ]];

        yield 'sendgrid' => ['sendgrid', [
            'transport' => 'smtp',
            'host'      => 'smtp.sendgrid.net',
            'port'      => 587,
            'user'      => 'apikey',                                 // literal string
            'pass'      => 'SG.fake-api-key',
            'tls'       => true,
            'from'      => 'noreply@example.com',
        ]];

        // Port 465 / implicit TLS variant — all three providers accept
        // this shape too. STARTTLS is skipped because the socket is
        // already encrypted at connect time.
        yield 'sendgrid_465' => ['sendgrid_465', [
            'transport' => 'smtp',
            'host'      => 'ssl://smtp.sendgrid.net',
            'port'      => 465,
            'user'      => 'apikey',
            'pass'      => 'SG.fake-api-key',
            'tls'       => false,                                    // socket already TLS
            'from'      => 'noreply@example.com',
        ]];
    }
}
