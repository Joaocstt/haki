<?php

declare(strict_types=1);

namespace Haki\Tests\Fixtures\Discover\Controllers;

use Haki\Tests\Fixtures\Discover\Contracts\ClienteRepositoryInterface;

final class ClienteController
{
    public function __construct(public readonly ClienteRepositoryInterface $clientes)
    {
    }

    public function index(): array
    {
        return [];
    }
}
