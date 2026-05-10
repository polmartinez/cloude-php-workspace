<?php

declare(strict_types=1);

/**
 * Recipe: emit Schema.org JSON-LD with Cloude\Format::json.
 *
 * No "schema builder" class needed — JSON-LD is just a PHP array with
 * specific keys. Build it inline; encode it once at the end. The handful
 * of types below cover ~90% of editorial sites: Article, BreadcrumbList,
 * FAQPage, Person, Organization.
 *
 * Embed the result inside an HTML page:
 *
 *   <script type="application/ld+json">
 *     <?= Cloude\Format::json($jsonld) ?>
 *   </script>
 *
 * For an LD-only endpoint (e.g. /article/foo.jsonld), just call
 * Cloude\Http\Response::json($jsonld) instead.
 */

use Cloude\Format;

// ── Article ──────────────────────────────────────────────────────────────────

$article = [
    '@context' => 'https://schema.org',
    '@type'    => 'Article',
    'headline' => 'Sample Article Title',
    'author'   => [
        '@type' => 'Person',
        'name'  => 'Jane Doe',
    ],
    'datePublished' => '2026-05-07',
    'dateModified'  => '2026-05-07',
    'image'         => 'https://example.com/article.jpg',
    'publisher' => [
        '@type' => 'Organization',
        'name'  => 'Example',
        'logo'  => [
            '@type' => 'ImageObject',
            'url'   => 'https://example.com/logo.png',
        ],
    ],
    'mainEntityOfPage' => [
        '@type' => 'WebPage',
        '@id'   => 'https://example.com/articles/sample',
    ],
];

// ── BreadcrumbList ───────────────────────────────────────────────────────────

$breadcrumbs = [
    '@context'        => 'https://schema.org',
    '@type'           => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home',     'item' => 'https://example.com/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Articles', 'item' => 'https://example.com/articles'],
        ['@type' => 'ListItem', 'position' => 3, 'name' => 'Sample',   'item' => 'https://example.com/articles/sample'],
    ],
];

// ── FAQPage ──────────────────────────────────────────────────────────────────

$faq = [
    '@context'   => 'https://schema.org',
    '@type'      => 'FAQPage',
    'mainEntity' => [
        [
            '@type'          => 'Question',
            'name'           => 'How do I install it?',
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text'  => 'Run composer require cloude/framework.',
            ],
        ],
        [
            '@type'          => 'Question',
            'name'           => 'Does it require a database?',
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text'  => 'No. The framework is database-agnostic.',
            ],
        ],
    ],
];

// ── Combining several blocks on one page ─────────────────────────────────────
//
// JSON-LD allows either one object per <script> tag, or a single document
// with @graph. The @graph form is shorter and easier to template.

$combined = [
    '@context' => 'https://schema.org',
    '@graph'   => [$article, $breadcrumbs, $faq],
];

// ── Output ───────────────────────────────────────────────────────────────────

echo Format::json($combined, pretty: true);
