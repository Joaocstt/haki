<?php

declare(strict_types=1);

namespace Haki\Tests\Fixtures\Discover\Controllers;

use Haki\Tests\Fixtures\Discover\Contracts\ProdutoRepositoryInterface;

final class ProdutoController
{
    public function __construct(public readonly ProdutoRepositoryInterface $produtos)
    {
    }

    public function index(): array
    {
        return ['repositorio' => $this->produtos::class];
    }
}
