<?php

declare(strict_types=1);

namespace Haki\Console;

/**
 * Escrita no terminal com cores simples.
 */
final class Output
{
    /** @var resource */
    private $stream;

    private readonly bool $colors;

    /**
     * @param resource|null $stream
     */
    public function __construct($stream = null, ?bool $colors = null)
    {
        $this->stream = $stream ?? STDOUT;
        $this->colors = $colors ?? (stream_isatty($this->stream) && getenv('NO_COLOR') === false
            && (DIRECTORY_SEPARATOR !== '\\' || (function_exists('sapi_windows_vt100_support') && sapi_windows_vt100_support($this->stream, true))));
    }

    public function line(string $text = ''): void
    {
        fwrite($this->stream, $text . PHP_EOL);
    }

    public function success(string $text): void
    {
        $this->line($this->paint('32', '✔ ') . $text);
    }

    public function error(string $text): void
    {
        $this->line($this->paint('31', '✖ ') . $text);
    }

    public function info(string $text): void
    {
        $this->line($this->paint('36', '› ') . $text);
    }

    public function muted(string $text): string
    {
        return $this->paint('90', $text);
    }

    public function bold(string $text): string
    {
        return $this->paint('1', $text);
    }

    private function paint(string $code, string $text): string
    {
        return $this->colors ? "\033[{$code}m{$text}\033[0m" : $text;
    }
}
