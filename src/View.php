<?php

declare(strict_types=1);

namespace Cloude;

/**
 * Plain PHP template rendering.
 * Variables from the $vars array are extracted into the local scope
 * before requiring the template file.
 */
class View
{
    private static string $basePath = '';

    /**
     * Sets a base directory for templates. When render() is called with a
     * relative path, it is resolved against this directory.
     */
    public static function setBasePath(string $path): void
    {
        self::$basePath = rtrim($path, '/');
    }

    /**
     * Renders a template file with the given variables.
     * If $templatePath is relative and a base path is configured, the base
     * path is prepended.
     */
    public static function render(string $templatePath, array $vars = []): void
    {
        if (self::$basePath !== '' && !str_starts_with($templatePath, '/')) {
            $templatePath = self::$basePath . '/' . $templatePath;
        }
        extract($vars);
        require $templatePath;
    }

    /**
     * Renders and captures the output instead of printing it.
     */
    public static function capture(string $templatePath, array $vars = []): string
    {
        ob_start();
        self::render($templatePath, $vars);
        return ob_get_clean() ?: '';
    }

    /**
     * HTML escape helper for templates: <?= View::e($text) ?>
     */
    public static function e(?string $text): string
    {
        return htmlspecialchars($text ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
