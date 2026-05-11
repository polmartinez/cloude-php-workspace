<?php

declare(strict_types=1);

/**
 * Recipe: render Markdown content (frontmatter + body) to HTML.
 *
 * The framework ships an in-house parser (`Cloude\Markdown\Parser`) that
 * covers the editorial subset most content sites need: headings,
 * paragraphs, lists, code, blockquotes, horizontal rules, hard line
 * breaks, inline emphasis/code/links/images, and GFM-flavoured tables
 * with alignment.
 *
 * Two ways to use it:
 *
 *   - `Cloude\Markdown::toHtml($body)` — body-only rendering, no frontmatter.
 *   - `Cloude\Markdown::parse($content)` — extracts frontmatter ("---" YAML
 *     block at the top) into `meta`, renders the rest as HTML, plus
 *     paragraphs / description / noindex helpers.
 *
 * For Parsedown-specific features (footnotes, reference-style links,
 * definition lists) install `erusev/parsedown` and swap the parser at
 * boot: `Markdown::useParser(fn($md) => (new Parsedown())->text($md))`.
 */

use Cloude\Markdown;
use Cloude\Markdown\Parser;

// ── 1. Body-only rendering ──────────────────────────────────────────────────

$body = <<<MD
    # Tipos de armas

    Resumen comparativo de las cuatro categorías más habituales.

    | Tipo | Ánima | Munición | Licencia |
    |---|---|---|:---:|
    | Escopeta | Lisa | Cartucho de perdigones | E |
    | Carabina / rifle | Rayada | Cartucho metálico con bala | D |
    | Express | Rayada | Cartucho metálico con bala | D |
    | Aire comprimido | Variable | Diábolo | A o B según potencia |

    Más información en la **[guía completa](https://example.com/guia)**.
    MD;

$html = Parser::toHtml($body);
// Returns a <table>…</table> wrapped between the heading <h1> and the
// closing paragraph. Each cell is run through the inline parser so
// `**bold**`, `` `code` ``, `[link](url)` work inside.

// ── 2. Frontmatter + body together ──────────────────────────────────────────

$source = <<<MD
    ---
    title: Tipos de armas
    description: Resumen comparativo de las cuatro categorías
    lang: es
    ---

    # Tipos de armas

    | Tipo | Ánima |
    |---|---|
    | Escopeta | Lisa |
    | Rifle    | Rayada |
    MD;

$parsed = Markdown::parse($source);
// $parsed = [
//   'meta'        => ['title' => 'Tipos de armas', 'description' => '...', 'lang' => 'es'],
//   'html'        => '<h1>Tipos de armas</h1>\n<table>…</table>',
//   'raw'         => '# Tipos de armas\n\n| Tipo | Ánima |\n…',
//   'paragraphs'  => ['<h1>Tipos de armas</h1>', …],
//   'description' => 'Resumen comparativo de las cuatro categorías',
//   'noindex'     => false,
// ]

// ── 3. Serving the rendered HTML from a route ───────────────────────────────
//
// Typical pattern: a route reads a .md file via Cloude\Markdown\File::read()
// (transparent .md.gz support), parses it with Markdown::parse(), then
// renders a template that echoes the html field.
//
/*
    $router->get('/articulos/{slug}', function (array $p): void {
        $raw = \Cloude\Markdown\File::read(CONTENT_DIR . "/articulos/{$p['slug']}.md");
        if ($raw === '') {
            \Cloude\Http\Response::notFound();
            return;
        }
        $a = \Cloude\Markdown::parse($raw);
        \Cloude\View::render('articulo.html.php', [
            'title'       => $a['meta']['title'] ?? $p['slug'],
            'description' => $a['description'],
            'noindex'     => $a['noindex'],
            'html'        => $a['html'],
        ]);
    });
*/

// ── 4. Inspecting the table alignment columns ───────────────────────────────
//
// GFM separator colons translate to inline CSS:
//
//   |---|     →  <th>…</th>                              (left, default)
//   |:---|    →  <th>…</th>                              (left, explicit)
//   |:---:|   →  <th style="text-align:center">…</th>
//   |---:|    →  <th style="text-align:right">…</th>
//
// Body cells inherit the column's alignment.

// Quick smoke from PHP CLI:
echo Parser::toHtml(<<<MD
    | Left | Center | Right |
    |:---|:---:|---:|
    | a | b | c |
    MD);
//   <table>
//   <thead><tr><th>Left</th><th style="text-align:center">Center</th><th style="text-align:right">Right</th></tr></thead>
//   <tbody><tr><td>Left</td><td style="text-align:center">b</td><td style="text-align:right">c</td></tr></tbody>
//   </table>

// ── 5. When to swap to Parsedown ────────────────────────────────────────────
//
// If your content uses footnotes, reference-style links, or definition
// lists, install Parsedown and register it once at boot:
//
//   composer require erusev/parsedown
//   \Cloude\Markdown::useParser(static fn (string $md): string => (new \Parsedown())->text($md));
//
// `Markdown::parse()` still handles frontmatter; only the body parser changes.
