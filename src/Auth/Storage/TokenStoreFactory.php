<?php

declare(strict_types=1);

namespace Haki\Auth\Storage;

use Elo\Conexao;
use Haki\Support\Env;
use PDO;

/** Seleciona o armazenamento padrão sem abrir a conexão antes do primeiro uso. */
final class TokenStoreFactory
{
    public static function create(string $basePath, string $sqlitePath): TokenStore
    {
        if (strtolower((string) Env::get('DB_CONNECTION', '')) === 'mysql') {
            return new PdoTokenStore(static function () use ($basePath): PDO {
                Conexao::raizDoProjeto($basePath);
                return Conexao::obter();
            }, automatico: true);
        }

        return new SqliteTokenStore($sqlitePath);
    }
}
