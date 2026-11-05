<?php

declare(strict_types=1);

namespace Haki\Console\Commands;

use Haki\Auth\Storage\SqliteTokenStore;
use Haki\Auth\Storage\PdoTokenStore;
use Haki\Auth\Storage\TokenStore;
use Haki\Console\Output;

/**
 * Cria o banco SQLite de tokens explicitamente (útil em deploy).
 */
final class AuthInstallCommand implements CommandInterface
{
    public function __construct(private readonly TokenStore $store)
    {
    }

    public function name(): string
    {
        return 'auth:install';
    }

    public function description(): string
    {
        return 'Prepara o armazenamento de tokens';
    }

    public function run(array $args, Output $output): int
    {
        if ($this->store instanceof SqliteTokenStore) {
            $this->store->install();
            $output->success('Banco de tokens pronto em ' . $this->store->path());
        } elseif ($this->store instanceof PdoTokenStore) {
            $this->store->criarTabelas();
            $output->success('Tabelas de tokens prontas no banco configurado.');
        } else {
            throw new \LogicException('Armazenamento de tokens não suporta instalação automática.');
        }

        return 0;
    }
}
