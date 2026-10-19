<?php

declare(strict_types=1);

namespace Haki\Tests\Fixtures\Types;

use Haki\Http\Request;

final class TipadoController
{
    private int $chamadas = 0;

    public function __construct(private readonly TipadoService $service)
    {
    }

    public function show(int $id, Request $request): array
    {
        $this->chamadas++;

        return ['id' => $id, 'nome' => $this->service->nome()];
    }
}
