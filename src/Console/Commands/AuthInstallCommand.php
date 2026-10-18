<?php

declare(strict_types=1);

namespace Haki\Console\Commands;

use Haki\Auth\Storage\SqliteTokenStore;
use Haki\Console\Output;

/**
 * Cria o banco SQLite de tokens explicitamente (útil em deploy).
 */
final class AuthInstallCommand implements CommandInterface
{
    public function __construct(private readonly SqliteTokenStore $store)
    {
    }

    public function name(): string
    {
        return 'auth:install';
    }

    public function description(): string
    {
        return 'Cria o banco SQLite e as tabelas de tokens';
    }

    public function run(array $args, Output $output): int
    {
        $this->store->install();
        $output->success('Banco de tokens pronto em ' . $this->store->path());

        return 0;
    }
}
