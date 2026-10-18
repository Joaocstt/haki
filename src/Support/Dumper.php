<?php

declare(strict_types=1);

namespace Haki\Support;

use Haki\Support\Dump\CliRenderer;
use Haki\Support\Dump\HtmlRenderer;
use Haki\Support\Dump\Inspector;
use Haki\Support\Dump\Node;

/**
 * Motor da função haki(): inspeciona os valores e escolhe a saída
 * (HTML no navegador, árvore colorida no terminal).
 */
final class Dumper
{
    private static bool $assetsSent = false;

    /**
     * @param list<mixed> $values
     */
    public static function dump(array $values, ?string $file = null, ?int $line = null): void
    {
        if (in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
            echo self::toText($values, $file, $line, self::terminalSupportsColors());

            return;
        }

        echo self::toHtml($values, $file, $line, !self::$assetsSent);
        self::$assetsSent = true;
    }

    /**
     * @param list<mixed> $values
     */
    public static function toHtml(array $values, ?string $file = null, ?int $line = null, bool $withAssets = true): string
    {
        return (new HtmlRenderer())->render(self::inspect($values), $file, $line, $withAssets);
    }

    /**
     * @param list<mixed> $values
     */
    public static function toText(array $values, ?string $file = null, ?int $line = null, bool $colors = false): string
    {
        return (new CliRenderer($colors))->render(self::inspect($values), $file, $line);
    }

    /**
     * @param list<mixed> $values
     * @return list<Node>
     */
    private static function inspect(array $values): array
    {
        return array_map(static fn (mixed $value): Node => (new Inspector())->inspect($value), array_values($values));
    }

    private static function terminalSupportsColors(): bool
    {
        if (getenv('NO_COLOR') !== false || !defined('STDOUT') || !stream_isatty(STDOUT)) {
            return false;
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            return function_exists('sapi_windows_vt100_support') && sapi_windows_vt100_support(STDOUT, true);
        }

        return true;
    }
}
