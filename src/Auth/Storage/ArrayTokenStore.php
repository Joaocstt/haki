<?php

declare(strict_types=1);

namespace Haki\Auth\Storage;

/**
 * Armazenamento em memória. Os dados somem ao fim da requisição: use apenas em testes.
 */
final class ArrayTokenStore implements TokenStore
{
    /** @var array<string, int> jti => expiresAt */
    private array $revoked = [];

    /** @var array<string, int> userId => revokedBefore */
    private array $users = [];

    /** @var array<string, RefreshTokenRecord> */
    private array $refresh = [];

    public function revokeAccessToken(string $jti, int $expiresAt): void
    {
        $this->revoked[$jti] = $expiresAt;
    }

    public function isAccessTokenRevoked(string $jti): bool
    {
        return isset($this->revoked[$jti]);
    }

    public function revokeUser(string $userId, int $before): void
    {
        $this->users[$userId] = max($before, $this->users[$userId] ?? 0);
    }

    public function userRevokedBefore(string $userId): ?int
    {
        return $this->users[$userId] ?? null;
    }

    public function saveRefreshToken(RefreshTokenRecord $record): void
    {
        $this->refresh[$record->hash] = $record;
    }

    public function findRefreshToken(string $hash): ?RefreshTokenRecord
    {
        return $this->refresh[$hash] ?? null;
    }

    public function consumeRefreshToken(string $hash, int $now): bool
    {
        $record = $this->refresh[$hash] ?? null;

        if ($record === null || $record->usedAt !== null || $record->revokedAt !== null) {
            return false;
        }

        $this->refresh[$hash] = $this->with($record, usedAt: $now);

        return true;
    }

    public function revokeFamily(string $familyId, int $now): void
    {
        foreach ($this->refresh as $hash => $record) {
            if ($record->familyId === $familyId && $record->revokedAt === null) {
                $this->refresh[$hash] = $this->with($record, revokedAt: $now);
            }
        }
    }

    public function purgeExpired(int $now): int
    {
        $before = count($this->revoked) + count($this->refresh);

        $this->revoked = array_filter($this->revoked, static fn (int $exp): bool => $exp > $now);
        $this->refresh = array_filter($this->refresh, static fn (RefreshTokenRecord $r): bool => $r->expiresAt > $now);

        return $before - count($this->revoked) - count($this->refresh);
    }

    private function with(RefreshTokenRecord $r, ?int $usedAt = null, ?int $revokedAt = null): RefreshTokenRecord
    {
        return new RefreshTokenRecord(
            $r->hash,
            $r->userId,
            $r->familyId,
            $r->claims,
            $r->createdAt,
            $r->expiresAt,
            $usedAt ?? $r->usedAt,
            $revokedAt ?? $r->revokedAt,
        );
    }
}
