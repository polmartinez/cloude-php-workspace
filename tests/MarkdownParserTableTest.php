<?php

declare(strict_types=1);

namespace Cloude\Tests;

use Cloude\Markdown\Parser;
use Cloude\Testing\TestCase;

final class MarkdownParserTableTest extends TestCase
{
    public function testRendersGitHubFlavouredTable(): void
    {
        $md = <<<MD
            | Type | Bore | Ammo |
            |---|---|---|
            | Shotgun | Smooth | Cartridge |
            | Rifle | Rifled | Bullet |
            MD;
        $html = Parser::toHtml($md);

        self::assertStringContainsString('<table>', $html);
        self::assertStringContainsString('<thead>', $html);
        self::assertStringContainsString('<th>Type</th>', $html);
        self::assertStringContainsString('<th>Bore</th>', $html);
        self::assertStringContainsString('<th>Ammo</th>', $html);
        self::assertStringContainsString('<tbody>', $html);
        self::assertStringContainsString('<td>Shotgun</td>', $html);
        self::assertStringContainsString('<td>Rifled</td>', $html);
        self::assertStringContainsString('<td>Bullet</td>', $html);
        self::assertStringContainsString('</table>', $html);
    }

    public function testAlignmentColons(): void
    {
        $md = <<<MD
            | Left | Center | Right |
            |:---|:---:|---:|
            | a | b | c |
            MD;
        $html = Parser::toHtml($md);

        // No alignment style on a "left" column (the HTML default).
        self::assertMatchesRegularExpression('/<th>Left<\/th>/', $html);
        // Center → text-align:center.
        self::assertStringContainsString('<th style="text-align:center">Center</th>', $html);
        // Right → text-align:right.
        self::assertStringContainsString('<th style="text-align:right">Right</th>', $html);
        // Body cells carry the same alignment.
        self::assertStringContainsString('<td style="text-align:center">b</td>', $html);
        self::assertStringContainsString('<td style="text-align:right">c</td>', $html);
    }

    public function testInlineMarkdownInsideCells(): void
    {
        $md = <<<MD
            | Name | Description |
            |---|---|
            | **Bold** | `inline code` |
            | [link](https://x.com) | *italic* |
            MD;
        $html = Parser::toHtml($md);

        self::assertStringContainsString('<td><strong>Bold</strong></td>', $html);
        self::assertStringContainsString('<td><code>inline code</code></td>', $html);
        self::assertStringContainsString('<td><a href="https://x.com">link</a></td>', $html);
        self::assertStringContainsString('<td><em>italic</em></td>', $html);
    }

    public function testRowsWithoutOuterPipes(): void
    {
        // GitHub flavour: leading/trailing | is optional.
        $md = <<<MD
            Type | Bore
            ---|---
            Shotgun | Smooth
            MD;
        $html = Parser::toHtml($md);

        self::assertStringContainsString('<th>Type</th>', $html);
        self::assertStringContainsString('<th>Bore</th>', $html);
        self::assertStringContainsString('<td>Shotgun</td>', $html);
        self::assertStringContainsString('<td>Smooth</td>', $html);
    }

    public function testWithoutSeparatorFallsBackToParagraph(): void
    {
        // No `---` separator → not a table, just a paragraph.
        $md = <<<MD
            | A | B |
            | x | y |
            MD;
        $html = Parser::toHtml($md);

        self::assertStringContainsString('<p>', $html);
        self::assertStringNotContainsString('<table>', $html);
    }

    public function testTableSurroundedByParagraphs(): void
    {
        $md = <<<MD
            Intro paragraph.

            | A | B |
            |---|---|
            | 1 | 2 |

            Outro paragraph.
            MD;
        $html = Parser::toHtml($md);

        self::assertStringContainsString('<p>Intro paragraph.</p>', $html);
        self::assertStringContainsString('<table>', $html);
        self::assertStringContainsString('<p>Outro paragraph.</p>', $html);
    }

    public function testParagraphTransitionsIntoTableWithoutBlankLine(): void
    {
        // Paragraph collector should release on table header + separator.
        $md = <<<MD
            Some intro text.
            | A | B |
            |---|---|
            | 1 | 2 |
            MD;
        $html = Parser::toHtml($md);

        self::assertStringContainsString('<p>Some intro text.</p>', $html);
        self::assertStringContainsString('<table>', $html);
    }
}
