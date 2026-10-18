<?php

declare(strict_types=1);

namespace Haki\Auth\Storage;

use Haki\Exceptions\ConfigurationException;
use PDO;
use PDOException;

/**
 * Armazenamento padrão em SQLite.
 *
 * O arquivo e as tabelas são criados automaticamente no primeiro uso.
 * Atende um servidor; com vários servidores, use um TokenStore compartilhado.
 */
final class SqliteTokenStore implements TokenStore
{
    public const SCHEMA_VERSION = 1;

    private ?PDO $pdo = null;

    /**
     * @param string $path caminho do arquivo .sqlite (ou ":memory:")
     */
    public function __construct(private readonly string $path)
    {
    }

    /**
     * Cria o banco e as tabelas (também roda sozinho no primeiro uso).
     */
    public function install(): void
    {
        $this->pdo();
    }

    public function path(): string
    {
        return $this->path;
    }

    public function revokeAccessToken(string $jti, int $expiresAt): void
    {
        $this->run('INSERT OR REPLACE INTO haki_revoked_tokens (jti, expires_at) VALUES (?, ?)', [$jti, $expiresAt]);
    }

    public function isAccessTokenRevoked(string $jti): bool
    {
        return $this->run('SELECT 1 FROM haki_revoked_tokens WHERE jti = ?', [$jti])->fetchColumn() !== false;
    }

    public function revokeUser(string $userId, int $before): void
    {
        $this->run(
            'INSERT INTO haki_user_revocations (user_id, revoked_before) VALUES (?, ?)
             ON CONFLICT(user_id) DO UPDATE SET revoked_before = MAX(revoked_before, excluded.revoked_before)',
            [$userId, $before],
        );
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
        // Atômico: só uma requisição consegue marcar o token como usado.
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
    private function run(string $sql, array $params = []): \PDOStatement
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement;
    }

    private function pdo(): PDO
    {
        return $this->pdo ??= $this->connect();
    }

    private function connect(): PDO
    {
        if (!extension_loaded('pdo_sqlite')) {
            throw new ConfigurationException(
                'A extensão pdo_sqlite não está ativa, e ela é necessária para a autenticação JWT.',
                'Ative a linha "extension=pdo_sqlite" no seu php.ini (veja o caminho com: php --ini) '
                . 'ou informe outro armazenamento em $app->auth([\'storage\' => ...]).',
            );
        }

        if ($this->path !== ':memory:') {
            $this->ensureDirectory(dirname($this->path));
        }

        try {
            $pdo = new PDO('sqlite:' . $this->path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            $pdo->exec('PRAGMA busy_timeout = 5000');

            if ($this->path !== ':memory:') {
                $pdo->exec('PRAGMA journal_mode = WAL');
            }

            $this->migrate($pdo);
        } catch (PDOException $e) {
            throw new ConfigurationException(
                "Não foi possível abrir o banco SQLite em {$this->path}: {$e->getMessage()}",
                'Verifique se a pasta existe e se o PHP tem permissão de escrita nela.',
                previous: $e,
            );
        }

        return $pdo;
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new ConfigurationException(
                "Não foi possível criar a pasta {$directory} para o banco de tokens.",
                'Crie a pasta manualmente e dê permissão de escrita ao PHP.',
            );
        }

        if (!is_writable($directory)) {
            throw new ConfigurationException(
                "A pasta {$directory} não tem permissão de escrita.",
                'Dê permissão de escrita ao usuário do PHP (ex.: chmod 775 storage).',
            );
        }
    }

    private function migrate(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS haki_meta (key TEXT PRIMARY KEY, value TEXT)');

        $version = (int) $pdo->query("SELECT value FROM haki_meta WHERE key = 'schema_version'")->fetchColumn();

        if ($version >= self::SCHEMA_VERSION) {
            return;
        }

        $pdo->beginTransaction();

        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS haki_refresh_tokens (
                token_hash  TEXT PRIMARY KEY,
                user_id     TEXT NOT NULL,
                family_id   TEXT NOT NULL,
                claims      TEXT NOT NULL,
                created_at  INTEGER NOT NULL,
                expires_at  INTEGER NOT NULL,
                used_at     INTEGER NULL,
                revoked_at  INTEGER NULL
            );
            CREATE INDEX IF NOT EXISTS idx_haki_refresh_family ON haki_refresh_tokens (family_id);
            CREATE INDEX IF NOT EXISTS idx_haki_refresh_user ON haki_refresh_tokens (user_id);

            CREATE TABLE IF NOT EXISTS haki_revoked_tokens (
                jti         TEXT PRIMARY KEY,
                expires_at  INTEGER NOT NULL
            );

            CREATE TABLE IF NOT EXISTS haki_user_revocations (
                user_id         TEXT PRIMARY KEY,
                revoked_before  INTEGER NOT NULL
            );
            SQL);

        $pdo->prepare("INSERT OR REPLACE INTO haki_meta (key, value) VALUES ('schema_version', ?)")
            ->execute([(string) self::SCHEMA_VERSION]);

        $pdo->commit();
    }
}
