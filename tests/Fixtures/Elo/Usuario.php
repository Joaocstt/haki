<?php

declare(strict_types=1);

namespace Haki\Tests\Fixtures\Elo;

use Elo\Modelo;

final class Usuario extends Modelo
{
    protected array $ocultos = ['senha'];

    protected array $temUm = [
        'perfil' => Perfil::class,
    ];

    protected array $pertenceA = [
        'setor' => Setor::class,
    ];

    protected array $pertenceAMuitos = [
        'cargos' => Cargo::class,
    ];
}
