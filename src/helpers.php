<?php

declare(strict_types=1);

use Haki\Support\Dumper;

if (!function_exists('haki')) {
    /**
     * Exibe um ou mais valores de forma legível e devolve o valor recebido.
     *
     * No navegador, gera um bloco HTML com tipos coloridos e nós recolhíveis.
     * No terminal, gera uma árvore colorida.
     *
     * Retorna o próprio valor (ou um array, se receber vários), então pode ser
     * usado no meio de uma expressão: `$total = haki(calcular());`
     */
    function haki(mixed ...$values): mixed
    {
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0] ?? [];

        Dumper::dump($values, $caller['file'] ?? null, $caller['line'] ?? null);

        return count($values) === 1 ? $values[0] : $values;
    }
}
