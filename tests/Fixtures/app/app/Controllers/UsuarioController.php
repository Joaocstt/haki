<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Contracts\UsuarioRepositoryInterface;
use Haki\Exceptions\HttpException;

final class UsuarioController
{
    public function __construct(private readonly UsuarioRepositoryInterface $usuarios)
    {
    }

    public function index(): array
    {
        return ['usuarios' => $this->usuarios->todos()];
    }

    public function show(int $id): array
    {
        $usuario = $this->usuarios->buscarPorId($id)
            ?? throw new HttpException(404, 'Usuário não encontrado');

        return ['usuario' => $usuario];
    }
}
