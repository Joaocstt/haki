<?php

namespace Haki\Tests\Fixtures\Types;

// Erro de propósito: falta declare(strict_types=1).
final class SemStrictController
{
    public function index(): array
    {
        return [];
    }
}
