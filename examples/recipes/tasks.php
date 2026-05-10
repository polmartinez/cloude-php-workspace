<?php

declare(strict_types=1);

/**
 * Recipe: a CLI task runner for a project.
 *
 * Drop a single entry-point script in `app/cli/tasks.php` (or wherever),
 * register tasks once, and invoke them from the shell:
 *
 *   php app/cli/tasks.php                          # list every task
 *   php app/cli/tasks.php help users:cleanup       # describe one task
 *   php app/cli/tasks.php users:cleanup --days=30  # run it
 *
 * Two ways to register:
 *   - `register($name, $callable, $description)` — single task by name.
 *   - `registerClass($prefix, $class)` — every public-static method of
 *     $class becomes a "$prefix:method-name" task. Method docblocks
 *     supply the description.
 */

use Cloude\Cli;
use Cloude\TaskRunner;

// ── A single inline task ─────────────────────────────────────────────────────

$runner = new TaskRunner();

$runner->register('ping', function (array $args): int {
    Cli::success('pong');
    return 0;
}, 'Connectivity check.');

// ── A class with several tasks ───────────────────────────────────────────────
//
// Method names are kebab-cased ("rebuildIndex" → "content:rebuild-index").
// The handler receives the parsed `$args` array — use Cli::option / flag /
// positional to read values.

class ContentTasks
{
    /** Rebuild the search index for $country (default es). */
    public static function rebuildIndex(array $args): int
    {
        $country = Cli::option($args, 'country', 'es');
        $dryRun  = Cli::flag($args, 'dry-run');

        Cli::info("rebuilding index for {$country}" . ($dryRun ? ' (dry run)' : ''));

        // ... actual work ...

        Cli::success('done');
        return 0;
    }

    /** Drop content older than N days (default 90). */
    public static function purgeOld(array $args): int
    {
        $days = (int) (Cli::option($args, 'days') ?? 90);
        if ($days < 1) {
            Cli::abort(2, 'days must be ≥ 1');
        }
        Cli::info("purging content older than {$days} days");
        // ...
        return 0;
    }
}

$runner->registerClass('content', ContentTasks::class);

// ── Dispatch ─────────────────────────────────────────────────────────────────

exit($runner->run($argv));
