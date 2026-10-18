<?php

declare(strict_types=1);

namespace Haki\Auth\Storage;

/**
 * Onde o Haki guarda refresh tokens e revogações.
 *
 * Padrão: SqliteTokenStore. Para vários servidores, implemente esta interface com Redis, MySQL etc.
 */
interface TokenStore
{
    /** Marca um access token (jti) como revogado até a sua expiração. */
    public function revokeAccessToken(string $jti, int $expiresAt): void;

    public function isAccessTokenRevoked(string $jti): bool;

    /** Invalida todos os tokens do usuário emitidos antes de $before (timestamp). */
    public function revokeUser(string $userId, int $before): void;

    public function userRevokedBefore(string $userId): ?int;

    public function saveRefreshToken(RefreshTokenRecord $record): void;

    public function findRefreshToken(string $hash): ?RefreshTokenRecord;

    /**
     * Marca o refresh token como usado de forma atômica.
     * Retorna false se ele já tinha sido usado ou revogado (possível reuso).
     */
    public function consumeRefreshToken(string $hash, int $now): bool;

    /** Revoga todos os refresh tokens de uma família (uma sessão de login). */
    public function revokeFamily(string $familyId, int $now): void;

    /** Remove registros vencidos. Retorna quantos foram removidos. */
    public function purgeExpired(int $now): int;
}
