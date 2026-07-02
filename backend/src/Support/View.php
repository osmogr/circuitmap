<?php

declare(strict_types=1);

namespace CircuitMap\Support;

/**
 * Plain-PHP template renderer. There is no autoescaping safety net here
 * (unlike Twig), so every template MUST pass dynamic values through
 * htmlspecialchars() at the point of output.
 */
final class View
{
    private static string $templatesPath;

    public static function setTemplatesPath(string $path): void
    {
        self::$templatesPath = rtrim($path, '/');
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function render(string $template, array $data = []): string
    {
        $file = self::$templatesPath . '/' . ltrim($template, '/') . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("Template not found: {$template}");
        }

        extract($data, EXTR_SKIP);
        ob_start();
        include $file;
        return (string) ob_get_clean();
    }

    public static function escape(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
    }
}
