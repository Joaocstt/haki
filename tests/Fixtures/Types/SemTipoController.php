<?php

declare(strict_types=1);

namespace Haki\Tests\Fixtures\Types;

// Erros de propósito: parâmetro sem tipo, método sem retorno e propriedade sem tipo.
final class SemTipoController
{
    private $cache = [];

    public function show($id)
    {
        return ['id' => $id];
    }
}
