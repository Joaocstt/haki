<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\UsuarioRepositoryInterface;
use App\Models\Usuario;

/**
 * Repositório em memória, para o exemplo funcionar sem banco de dados.
 *
 * Usuários de teste (senha: segredo123):
 *   luffy@exemplo.com (admin)
 *   zoro@exemplo.com  (membro)
 */
final class ArrayUsuarioRepository implements UsuarioRepositoryInterface
{
    /** @var array<int, Usuario> */
    private array $usuarios;

    public function __construct()
    {
        $hash = password_hash('segredo123', PASSWORD_DEFAULT);

        $this->usuarios = [
            1 => new Usuario(1, 'Monkey D. Luffy', 'luffy@exemplo.com', 'admin', $hash),
            2 => new Usuario(2, 'Roronoa Zoro', 'zoro@exemplo.com', 'membro', $hash),
        ];
    }

    public function todos(): array
    {
        return array_values($this->usuarios);
    }

    public function buscarPorId(int $id): ?Usuario
    {
        return $this->usuarios[$id] ?? null;
    }

    public function buscarPorEmail(string $email): ?Usuario
    {
        foreach ($this->usuarios as $usuario) {
            if (strcasecmp($usuario->email, $email) === 0) {
                return $usuario;
            }
        }

        return null;
    }
}
