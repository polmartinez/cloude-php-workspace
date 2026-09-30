<?php

declare(strict_types=1);

namespace Cloude\Tests;

use Cloude\Markdown;
use Cloude\Markdown\Parser;
use Cloude\Testing\TestCase;

/**
 * The inline step holds every tag it writes as a token, so no later pattern
 * reads inside one; and safe mode (`Markdown::toSafeHtml`) renders text from
 * outside so that nothing in it becomes a tag, an attribute or a script
 * address.
 */
final class MarkdownParserSafeTest extends TestCase
{
    /** @return list<string> every tag of a rendered text */
    private static function tags(string $html): array
    {
        preg_match_all('/<[^>]*>/', $html, $m);
        return $m[0];
    }

    /**
     * Regression: an image whose alt text held a link was scanned again by the
     * link pattern, which wrote a second tag INSIDE the `alt` attribute and let
     * the link's address out of it as attributes (`onerror=…`).
     */
    public function testAnImageInsideALinksTextWritesNoAttribute(): void
    {
        foreach ([false, true] as $safe) {
            $html = Parser::toHtml('![a [y](/x.png)](z/onerror=alert(1)//)', $safe);
            self::assertSame(1, substr_count($html, '<img '), 'one image, written once');
            self::assertSame(0, preg_match('/<[^>]*\son\w+\s*=/i', $html), $html);
            self::assertStringNotContainsString('<a ', $html, 'no tag written inside the alt text');
        }
    }

    /** Regression: a code span inside an address came back, quotes and all, inside the `href`. */
    public function testACodeSpanInsideAnAddressDoesNotBreakTheAttribute(): void
    {
        foreach ([false, true] as $safe) {
            $html = Parser::toHtml('[x](https://a.test/`"onmouseover="alert(1)//`)', $safe);
            self::assertStringNotContainsString('<a ', $html);
            self::assertSame(0, preg_match('/<[^>]*\son\w+\s*=/i', $html), $html);
        }
    }

    public function testFormattingStillAppliesInsideAndAroundALink(): void
    {
        self::assertSame(
            '<p><strong>Read <a href="/manual" title="The manual">the <code>manual</code></a> first</strong> and <em>sign</em>.</p>' . "\n",
            Parser::toHtml('**Read [the `manual`](/manual "The manual") first** and _sign_.'),
        );
        self::assertSame(
            '<p><img src="/logo.png" alt="Logo &amp; name" title="Home"></p>' . "\n",
            Parser::toHtml('![Logo & name](/logo.png "Home")'),
        );
    }

    public function testTheDefaultModeStillPassesInlineHtml(): void
    {
        self::assertSame('<p>A <span class="x">tag</span> of ours.</p>' . "\n", Parser::toHtml('A <span class="x">tag</span> of ours.'));
        self::assertSame(Parser::toHtml('**b** <i>i</i>'), Markdown::toHtml('**b** <i>i</i>'));
    }

    public function testSafeModeTurnsRawHtmlIntoText(): void
    {
        $html = Markdown::toSafeHtml("Hi <script>alert(1)</script> <img src=x onerror=alert(1)> <!-- c -->\n\n<div onclick=\"x()\">block</div>");

        self::assertSame(['<p>', '</p>', '<p>', '</p>'], self::tags($html));
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringContainsString('&lt;div onclick=&quot;x()&quot;&gt;', $html);
    }

    public function testSafeModeWritesOnlyItsOwnTags(): void
    {
        $attacks = [
            '![a [y](/x.png)](z/onerror=alert(1)//)',
            '[x](https://a.test/`"onmouseover="alert(1)//`)',
            '[x" onmouseover="alert(1)](https://a.test)',
            '[x](https://a.test/"onmouseover="alert(1))',
            '[x](https://a.test "t" onmouseover="alert(1)")',
            '![a" onerror="alert(1)](https://a.test/i.png)',
            '[a [b](c "d" e)](f "g" onx="1")',
            "```js\" onclick=\"alert(1)\ncode\n```",
            '| a" onx="1 | b |' . "\n|---|---|\n| 1 | 2 |",
            '# Title " onx="1',
            "\x000\x00 [x](https://a.test) \x001\x00",
            '"><svg onload=alert(1)>',
        ];
        $own = '#^<(?:/?(?:p|h[1-6]|ul|ol|li|strong|em|code|pre|blockquote|table|thead|tbody|tr|th|td|a)|br|hr'
            . '|a href="[^"<>]*"(?: title="[^"<>]*")?|img src="[^"<>]*" alt="[^"<>]*"(?: title="[^"<>]*")?'
            . '|code class="language-\w+"|t[hd] style="text-align:(?:center|right)")>$#';
        foreach ($attacks as $attack) {
            foreach (self::tags(Markdown::toSafeHtml($attack)) as $tag) {
                self::assertMatchesRegularExpression($own, $tag, $attack);
            }
        }
    }

    public function testSafeModeLinksOnlyAddressesATextNeeds(): void
    {
        foreach (['/path', '#anchor', '?q=1', 'page.html', '//other.test/x', 'https://a.test/x?y=1&z=2', 'HTTP://A.TEST', 'mailto:hi@example.com', 'tel:+34600000000'] as $address) {
            self::assertStringContainsString('<a href="', Markdown::toSafeHtml('[link](' . $address . ')'), $address);
        }
        self::assertSame('<p><a href="https://a.test/x?y=1&amp;z=2">link</a></p>' . "\n", Markdown::toSafeHtml('[link](https://a.test/x?y=1&z=2)'));

        foreach (['javascript:alert(1)', 'JaVaScRiPt:alert(1)', "\x01javascript:alert(1)", 'vbscript:msgbox(1)', 'data:text/html;base64,PHNjcmlwdD4=', 'file:///etc/passwd', 'ftp://a.test/x'] as $address) {
            $html = Markdown::toSafeHtml('[link](' . $address . ')');
            self::assertStringNotContainsString('<a', $html, $address);
            self::assertStringContainsString('link', $html, 'the text stays');
        }
        // An entity someone types is text: the address is a relative one, and so the browser reads it.
        self::assertStringContainsString('<a href="javascript&amp;colon;x">', Markdown::toSafeHtml('[x](javascript&colon;x)'));
        // The default mode keeps any address: it is for content you wrote.
        self::assertStringContainsString('<a href="javascript:history.back">', Parser::toHtml('[x](javascript:history.back)'));
    }

    public function testSafeModeDrawsOnlyWebAndRelativeImages(): void
    {
        self::assertSame('<p><img src="https://a.test/i.png" alt="logo"></p>' . "\n", Markdown::toSafeHtml('![logo](https://a.test/i.png)'));
        self::assertSame('<p><img src="/i.png" alt="logo"></p>' . "\n", Markdown::toSafeHtml('![logo](/i.png)'));
        foreach (['javascript:alert(1)', 'data:image/svg+xml;base64,PHN2Zz4=', 'mailto:a@example.com'] as $src) {
            $html = Markdown::toSafeHtml('![logo](' . $src . ')');
            self::assertStringNotContainsString('<img', $html, $src);
            self::assertStringContainsString('logo', $html);
        }
    }

    public function testSafeModeKeepsTheBlockGrammar(): void
    {
        $md = "# Title\n\n- one\n- two\n\n1. first\n\n> quote **bold**\n\n| a | b |\n|:---:|---:|\n| 1 | 2 |\n\n```\n<b>code</b>\n```\n\n---\n\nline  \nbreak";
        self::assertSame(self::tags(Parser::toHtml($md)), self::tags(Markdown::toSafeHtml($md)));
    }

    public function testSafeModeIgnoresARegisteredParser(): void
    {
        Markdown::useParser(static fn (string $md): string => '<script>custom</script>' . $md);
        try {
            self::assertStringStartsWith('<script>custom', Markdown::toHtml('x'));
            self::assertSame("<p>x</p>\n", Markdown::toSafeHtml('x'));
        } finally {
            Markdown::useParser(null);
        }
    }
}
