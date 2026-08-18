<?php

declare(strict_types=1);

namespace Cloude\Tests;

use Cloude\Markdown\Parser;
use Cloude\Testing\TestCase;

/**
 * Inline formatting — bold, italic, and the tricky combinations that
 * used to break the parser (italic-inside-bold specifically).
 */
final class MarkdownParserInlineTest extends TestCase
{
    public function testPlainBold(): void
    {
        self::assertStringContainsString('<strong>bold</strong>', Parser::toHtml('**bold**'));
        self::assertStringContainsString('<strong>bold</strong>', Parser::toHtml('__bold__'));
    }

    public function testPlainItalic(): void
    {
        self::assertStringContainsString('<em>italic</em>', Parser::toHtml('*italic*'));
        self::assertStringContainsString('<em>italic</em>', Parser::toHtml('_italic_'));
    }

    /**
     * Regression: italic inside bold used to break the whole thing.
     * `**Jabalí (*Sus scrofa*)**` was rendering as literal `**` around
     * `Jabalí (<em>Sus scrofa</em>)` because the bold regex excluded
     * every `*` from the interior.
     */
    public function testItalicInsideBold(): void
    {
        $html = Parser::toHtml('**Jabalí (*Sus scrofa*)**');
        self::assertStringContainsString(
            '<strong>Jabalí (<em>Sus scrofa</em>)</strong>',
            $html,
        );
        self::assertStringNotContainsString('**', $html, 'no literal ** should leak through');
    }

    public function testItalicInsideBoldUnderscoreVariant(): void
    {
        // Same case with underscores for both.
        $html = Parser::toHtml('__Jabalí (_Sus scrofa_)__');
        self::assertStringContainsString(
            '<strong>Jabalí (<em>Sus scrofa</em>)</strong>',
            $html,
        );
    }

    public function testItalicSurroundedByBoldSegments(): void
    {
        $html = Parser::toHtml('**start *middle* end**');
        self::assertStringContainsString(
            '<strong>start <em>middle</em> end</strong>',
            $html,
        );
    }

    public function testAdjacentBoldsDontMerge(): void
    {
        // Sanity — two separate **...** in the same line stay separate.
        $html = Parser::toHtml('**one** and **two**');
        self::assertStringContainsString('<strong>one</strong>', $html);
        self::assertStringContainsString('<strong>two</strong>', $html);
    }

    public function testBoldWithNoInteriorIsLeftAlone(): void
    {
        // `****` shouldn't match — the `+` requires at least one char.
        $html = Parser::toHtml('****');
        self::assertStringNotContainsString('<strong>', $html);
    }
}
