<?php

declare(strict_types=1);

namespace App\Core\Console;

final class Output
{
    private bool $colors;

    public function __construct()
    {
        $this->colors = function_exists('stream_isatty') && @stream_isatty(STDOUT);
        if (PHP_OS_FAMILY === 'Windows' && function_exists('sapi_windows_vt100_support')) {
            $this->colors = $this->colors && @sapi_windows_vt100_support(STDOUT, true);
        }
    }

    public function line(string $text = ''): void
    {
        fwrite(STDOUT, $text . PHP_EOL);
    }

    public function info(string $text): void
    {
        $this->line($this->color($text, '32'));
    }

    public function warn(string $text): void
    {
        $this->line($this->color($text, '33'));
    }

    public function error(string $text): void
    {
        fwrite(STDERR, $this->color($text, '31') . PHP_EOL);
    }

    public function muted(string $text): string
    {
        return $this->color($text, '90');
    }

    /**
     * @param list<string>       $headers
     * @param list<list<string>> $rows
     */
    public function table(array $headers, array $rows): void
    {
        $widths = array_map('mb_strlen', $headers);
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, mb_strlen($cell));
            }
        }

        $format = static fn (array $cells): string => implode('  ', array_map(
            static fn ($cell, $i) => $cell . str_repeat(' ', $widths[$i] - mb_strlen($cell)),
            $cells,
            array_keys($cells),
        ));

        $this->line($format($headers));
        $this->line(implode('  ', array_map(static fn (int $w) => str_repeat('-', $w), $widths)));
        foreach ($rows as $row) {
            $this->line($format($row));
        }
    }

    private function color(string $text, string $code): string
    {
        return $this->colors ? "\033[{$code}m{$text}\033[0m" : $text;
    }
}
