<?php

declare(strict_types=1);

namespace Haki\Tests\Fixtures\Elo;

use Elo\Modelo;

final class Setor extends Modelo
{
    protected array $temMuitos = [
        'usuarios' => Usuario::class,
    ];
}
