<?php

declare(strict_types=1);

namespace Haki\Console\Commands;

use Haki\Console\Output;

interface CommandInterface
{
    /** Nome usado no terminal, ex.: "key:generate". */
    public function name(): string;

    public function description(): string;

    /**
     * @param list<string> $args argumentos e opções (--force, --rsa...)
     * @return int código de saída (0 = sucesso)
     */
    public function run(array $args, Output $output): int;
}
