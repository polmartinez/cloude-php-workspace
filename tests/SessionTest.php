<?php

declare(strict_types=1);

namespace Cloude\Tests;

use Cloude\Session;
use Cloude\Testing\TestCase;

/**
 * Session tests exercise the static façade against PHP's real session
 * machinery (no fakes). A per-test temp save_path keeps cases isolated.
 * `set/get/has/forget/all`, flash semantics, CSRF round-trip, and
 * regenerate are covered.
 *
 * NB: `start()` requires that no headers have been sent and that the
 * session is not already active. The runner runs each test in the same
 * process, so each test must call `Session::destroy()` in tearDown (or
 * trigger it implicitly by relying on `destroy()` here).
 */
final class SessionTest extends TestCase
{
    private string $tmpSavePath;

    protected function setUp(): void
    {
        $this->tmpSavePath = sys_get_temp_dir() . '/cloude-session-' . bin2hex(random_bytes(4));
        mkdir($this->tmpSavePath, 0700, true);
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            @session_destroy();
        }
        // Cleanup files.
        foreach (glob($this->tmpSavePath . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpSavePath);
    }

    private function startCleanSession(): void
    {
        // Each test starts from a fresh session-id so previous test
        // data doesn't leak through the on-disk save_path.
        Session::start(['save_path' => $this->tmpSavePath, 'name' => 'CLOUDE_TEST']);
    }

    public function testStartIsIdempotent(): void
    {
        $this->startCleanSession();
        $id = Session::id();
        Session::start(['save_path' => $this->tmpSavePath]);   // no-op
        self::assertSame($id, Session::id());
    }

    public function testSetGetHasForgetAll(): void
    {
        $this->startCleanSession();
        Session::set('user_id', 42);
        Session::set('lang', 'es');

        self::assertSame(42, Session::get('user_id'));
        self::assertSame('es', Session::get('lang'));
        self::assertSame('default', Session::get('missing', 'default'));
        self::assertTrue(Session::has('user_id'));
        self::assertFalse(Session::has('missing'));

        Session::forget('user_id');
        self::assertFalse(Session::has('user_id'));

        self::assertSame(['lang' => 'es'], Session::all());
    }

    public function testAllExcludesInternalFrameworkBuckets(): void
    {
        $this->startCleanSession();
        Session::flash('msg', 'hi');
        Session::csrfToken();
        Session::set('user_id', 1);

        $all = Session::all();
        self::assertSame(['user_id' => 1], $all);
    }

    public function testFlashSurvivesExactlyOneRequest(): void
    {
        // Request N: flash a value.
        $this->startCleanSession();
        Session::flash('success', 'Saved!');
        // During N the value is in the "next" bucket — not pullable yet.
        self::assertNull(Session::pullFlash('success'));
        $id = Session::id();
        session_write_close();

        // Request N+1: same session, flash is now readable once.
        session_id($id);
        Session::start(['save_path' => $this->tmpSavePath, 'name' => 'CLOUDE_TEST']);
        self::assertTrue(Session::hasFlash('success'));
        self::assertSame('Saved!', Session::pullFlash('success'));
        self::assertFalse(Session::hasFlash('success'));   // consumed
        $id2 = Session::id();
        session_write_close();

        // Request N+2: flash is gone (un-pulled stuff also dies).
        session_id($id2);
        Session::start(['save_path' => $this->tmpSavePath, 'name' => 'CLOUDE_TEST']);
        self::assertFalse(Session::hasFlash('success'));
    }

    public function testReflashKeepsValueForOneMoreRequest(): void
    {
        // Set up a flash for next request.
        $this->startCleanSession();
        Session::flash('msg', 'persist me');
        $id = Session::id();
        session_write_close();

        // Next request: don't pull, just reflash → goes back into the
        // "next" bucket so we get one more shot.
        session_id($id);
        Session::start(['save_path' => $this->tmpSavePath, 'name' => 'CLOUDE_TEST']);
        Session::reflash('msg');
        $id2 = Session::id();
        session_write_close();

        // Request after that: still available.
        session_id($id2);
        Session::start(['save_path' => $this->tmpSavePath, 'name' => 'CLOUDE_TEST']);
        self::assertSame('persist me', Session::pullFlash('msg'));
    }

    public function testCsrfTokenIsMintedOnceAndConstantInSession(): void
    {
        $this->startCleanSession();
        $a = Session::csrfToken();
        $b = Session::csrfToken();
        self::assertSame($a, $b);
        self::assertSame(64, strlen($a));     // 32 bytes hex
    }

    public function testCheckCsrfMatchesAndRejectsMismatch(): void
    {
        $this->startCleanSession();
        $token = Session::csrfToken();
        self::assertTrue(Session::checkCsrf($token));
        self::assertFalse(Session::checkCsrf('wrong'));
        self::assertFalse(Session::checkCsrf(''));
    }

    public function testCheckCsrfFalseWhenNoTokenMintedYet(): void
    {
        $this->startCleanSession();
        self::assertFalse(Session::checkCsrf('anything'));
    }

    public function testRegenerateChangesSessionId(): void
    {
        $this->startCleanSession();
        Session::set('user_id', 1);
        $before = Session::id();

        Session::regenerate();
        $after = Session::id();

        self::assertNotSame($before, $after);
        self::assertSame(1, Session::get('user_id'));     // data preserved
    }

    public function testDestroyClearsEverything(): void
    {
        $this->startCleanSession();
        Session::set('user_id', 1);
        Session::destroy();

        self::assertSame(PHP_SESSION_NONE, session_status());
        self::assertSame([], $_SESSION);
    }

    public function testAccessWithoutStartedSessionThrows(): void
    {
        // No start() called.
        $this->expectException(\RuntimeException::class);
        Session::get('anything');
    }
}
