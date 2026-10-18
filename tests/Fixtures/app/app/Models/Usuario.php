<?php

declare(strict_types=1);

namespace App\Models;

use JsonSerializable;

final class Usuario implements JsonSerializable
{
    public function __construct(
        public readonly int $id,
        public readonly string $nome,
        public readonly string $email,
        public readonly string $perfil,
        private readonly string $senhaHash,
    ) {
    }

    public function senhaConfere(string $senha): bool
    {
        return password_verify($senha, $this->senhaHash);
    }

    /**
     * A senha nunca sai na resposta.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return ['id' => $this->id, 'nome' => $this->nome, 'email' => $this->email, 'perfil' => $this->perfil];
    }
}
