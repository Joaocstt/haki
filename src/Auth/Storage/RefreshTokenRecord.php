<?php

declare(strict_types=1);

namespace Haki\Auth\Storage;

/**
 * Refresh token armazenado. Apenas o hash SHA-256 é guardado, nunca o token puro.
 */
final class RefreshTokenRecord
{
    /**
     * @param array<string, mixed> $claims claims extras do login (role etc.), reaproveitados no refresh
     */
    public function __construct(
        public readonly string $hash,
        public readonly string $userId,
        public readonly string $familyId,
        public readonly array $claims,
        public readonly int $createdAt,
        public readonly int $expiresAt,
        public readonly ?int $usedAt = null,
        public readonly ?int $revokedAt = null,
    ) {
    }
}
