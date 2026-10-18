<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Usuario;

/**
 * Os controllers e serviços dependem desta interface, não de um banco específico.
 * Trocar de array para MySQL é só registrar outra implementação no bootstrap.
 */
interface UsuarioRepositoryInterface
{
    /** @return list<Usuario> */
    public function todos(): array;

    public function buscarPorId(int $id): ?Usuario;

    public function buscarPorEmail(string $email): ?Usuario;
}
