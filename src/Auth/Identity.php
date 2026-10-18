<?php

declare(strict_types=1);

namespace Haki\Auth;

use JsonSerializable;

/**
 * Usuário autenticado, montado a partir dos claims de um token já validado.
 *
 * Representa apenas o que está no token: o Haki não consulta o seu banco de dados.
 */
final class Identity implements JsonSerializable
{
    /** Claims de controle, que não fazem parte dos "dados do usuário". */
    public const RESERVED = ['sub', 'iat', 'exp', 'jti', 'iss', 'fid', 'nbf', 'aud'];

    public readonly string $id;

    public readonly ?string $role;

    /**
     * @param array<string, mixed> $claims todos os claims validados
     */
    public function __construct(public readonly array $claims)
    {
        $this->id = (string) ($claims['sub'] ?? '');
        $this->role = isset($claims['role']) ? (string) $claims['role'] : null;
    }

    public function get(string $claim, mixed $default = null): mixed
    {
        return $this->claims[$claim] ?? $default;
    }

    /**
     * Verifica se o usuário tem um dos perfis informados.
     */
    public function hasRole(string ...$roles): bool
    {
        return $this->role !== null && in_array($this->role, $roles, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return ['id' => $this->id, 'role' => $this->role]
            + array_diff_key($this->claims, array_flip(self::RESERVED));
    }
}
