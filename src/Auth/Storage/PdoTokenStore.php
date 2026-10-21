<?php

declare(strict_types=1);

namespace Haki\Auth\Storage;

use PDO;
use PDOException;
use PDOStatement;

/**
 * Armazenamento de tokens em qualquer banco que o PDO atenda (MySQL, PostgreSQL, SQL Server, SQLite).
 *
 * Use quando há mais de um servidor: todos apontam para o mesmo banco, então uma revogação
 * feita em um servidor vale em todos. As tabelas são criadas com criarTabelas(), uma vez,
 * no processo de deploy.
 *
 *     $app->auth(['storage' => new PdoTokenStore(new PDO('mysql:host=...;dbname=app', 'user', 'senha'))]);
 */
final class PdoTokenStore implements TokenStore
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Cria as tabelas do Haki. Pode ser chamado mais de uma vez: não apaga nada.
     */
    public function criarTabelas(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS haki_refresh_tokens (
                token_hash  VARCHAR(64) NOT NULL PRIMARY KEY,
                user_id     VARCHAR(191) NOT NULL,
                family_id   VARCHAR(64) NOT NULL,
                claims      TEXT NOT NULL,
                created_at  BIGINT NOT NULL,
                expires_at  BIGINT NOT NULL,
                used_at     BIGINT NULL,
                revoked_at  BIGINT NULL
            )',
        );

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS haki_revoked_tokens (
                jti         VARCHAR(64) NOT NULL PRIMARY KEY,
                expires_at  BIGINT NOT NULL
            )',
        );

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS haki_user_revocations (
                user_id         VARCHAR(191) NOT NULL PRIMARY KEY,
                revoked_before  BIGINT NOT NULL
            )',
        );

        // Índices: revogar uma família ou um usuário não deve varrer a tabela inteira.
        // Se o índice já existir, o banco recusa e seguimos em frente.
        foreach ([
            'CREATE INDEX haki_refresh_family ON haki_refresh_tokens (family_id)',
            'CREATE INDEX haki_refresh_user ON haki_refresh_tokens (user_id)',
        ] as $sql) {
            try {
                $this->pdo->exec($sql);
            } catch (PDOException) {
            }
        }
    }

    public function revokeAccessToken(string $jti, int $expiresAt): void
    {
        try {
            $this->run('INSERT INTO haki_revoked_tokens (jti, expires_at) VALUES (?, ?)', [$jti, $expiresAt]);
        } catch (PDOException) {
            // Já estava revogado (outro servidor gravou ao mesmo tempo).
        }
    }

    public function isAccessTokenRevoked(string $jti): bool
    {
        return $this->run('SELECT 1 FROM haki_revoked_tokens WHERE jti = ?', [$jti])->fetchColumn() !== false;
    }

    public function revokeUser(string $userId, int $before): void
    {
        try {
            $this->run('INSERT INTO haki_user_revocations (user_id, revoked_before) VALUES (?, ?)', [$userId, $before]);
        } catch (PDOException) {
            // Já existe um registro: fica sempre o maior valor, nunca volta atrás.
            $this->run(
                'UPDATE haki_user_revocations
                 SET revoked_before = CASE WHEN revoked_before > ? THEN revoked_before ELSE ? END
                 WHERE user_id = ?',
                [$before, $before, $userId],
            );
        }
    }

    public function userRevokedBefore(string $userId): ?int
    {
        $value = $this->run('SELECT revoked_before FROM haki_user_revocations WHERE user_id = ?', [$userId])->fetchColumn();

        return $value === false ? null : (int) $value;
    }

    public function saveRefreshToken(RefreshTokenRecord $record): void
    {
        $this->run(
            'INSERT INTO haki_refresh_tokens (token_hash, user_id, family_id, claims, created_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $record->hash,
                $record->userId,
                $record->familyId,
                json_encode($record->claims, JSON_THROW_ON_ERROR),
                $record->createdAt,
                $record->expiresAt,
            ],
        );
    }

    public function findRefreshToken(string $hash): ?RefreshTokenRecord
    {
        /** @var array<string, mixed>|false $row */
        $row = $this->run('SELECT * FROM haki_refresh_tokens WHERE token_hash = ?', [$hash])->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        $claims = json_decode((string) $row['claims'], true);

        return new RefreshTokenRecord(
            (string) $row['token_hash'],
            (string) $row['user_id'],
            (string) $row['family_id'],
            is_array($claims) ? $claims : [],
            (int) $row['created_at'],
            (int) $row['expires_at'],
            $row['used_at'] !== null ? (int) $row['used_at'] : null,
            $row['revoked_at'] !== null ? (int) $row['revoked_at'] : null,
        );
    }

    public function consumeRefreshToken(string $hash, int $now): bool
    {
        // Atômico no banco: só uma requisição consegue marcar o token como usado.
        $statement = $this->run(
            'UPDATE haki_refresh_tokens SET used_at = ?
             WHERE token_hash = ? AND used_at IS NULL AND revoked_at IS NULL',
            [$now, $hash],
        );

        return $statement->rowCount() === 1;
    }

    public function revokeFamily(string $familyId, int $now): void
    {
        $this->run(
            'UPDATE haki_refresh_tokens SET revoked_at = ? WHERE family_id = ? AND revoked_at IS NULL',
            [$now, $familyId],
        );
    }

    public function purgeExpired(int $now): int
    {
        $removed = $this->run('DELETE FROM haki_revoked_tokens WHERE expires_at <= ?', [$now])->rowCount();
        $removed += $this->run('DELETE FROM haki_refresh_tokens WHERE expires_at <= ?', [$now])->rowCount();

        return $removed;
    }

    /**
     * @param list<mixed> $params
     */
    private function run(string $sql, array $params = []): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement;
    }
}
