<?php

declare(strict_types=1);

/**
 * Recipe: render an XML sitemap with Cloude\Format::xml + Cloude\Http\Response.
 *
 * Drop this into a route handler. The framework deliberately ships no
 * `Sitemap` class — once you have Format::xml the rest is data shape, and
 * shape varies enough across projects that a class would just get in the way.
 *
 *   $router->get('/sitemap.xml', function (): void {
 *       require __DIR__ . '/recipes/sitemap.php';
 *   });
 */

use Cloude\Format;
use Cloude\Http\Response;

// 1. Gather URLs from your data sources. Replace with whatever you have:
//    repos, files on disk, a database — anything that yields strings.
$urls = [
    ['loc' => 'https://example.com/',         'lastmod' => '2026-05-07', 'priority' => '1.0'],
    ['loc' => 'https://example.com/articles', 'lastmod' => '2026-05-06', 'priority' => '0.8'],
    ['loc' => 'https://example.com/about',    'lastmod' => '2026-05-01', 'priority' => '0.5'],
];

// 2. Build the sitemap shape that Format::xml expects:
//    - Single top-level key becomes the root element.
//    - Keys starting with '@' become attributes.
//    - Indexed arrays repeat the element.
$tree = [
    'urlset' => [
        '@xmlns' => 'http://www.sitemaps.org/schemas/sitemap/0.9',
        'url'    => $urls,
    ],
];

// 3. Encode and respond.
Response::xml(Format::xml($tree, pretty: true));

/* Output:
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url>
    <loc>https://example.com/</loc>
    <lastmod>2026-05-07</lastmod>
    <priority>1.0</priority>
  </url>
  ...
</urlset>
*/

// ── Sitemap index (when you have >50k URLs and split across multiple files) ──
//
// $tree = [
//     'sitemapindex' => [
//         '@xmlns'  => 'http://www.sitemaps.org/schemas/sitemap/0.9',
//         'sitemap' => [
//             ['loc' => 'https://example.com/sitemap-pages.xml',    'lastmod' => '2026-05-07'],
//             ['loc' => 'https://example.com/sitemap-articles.xml', 'lastmod' => '2026-05-07'],
//         ],
//     ],
// ];
// Response::xml(Format::xml($tree, pretty: true));
