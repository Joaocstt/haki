<?php

declare(strict_types=1);

namespace Haki\Console\Commands;

use Haki\Auth\Storage\TokenStore;
use Haki\Console\Output;

/**
 * Remove tokens vencidos. Ideal para rodar em um cron diário.
 */
final class AuthCleanCommand implements CommandInterface
{
    public function __construct(private readonly TokenStore $store)
    {
    }

    public function name(): string
    {
        return 'auth:clean';
    }

    public function description(): string
    {
        return 'Remove refresh tokens e revogações vencidos';
    }

    public function run(array $args, Output $output): int
    {
        $removed = $this->store->purgeExpired(time());
        $output->success("{$removed} registro(s) vencido(s) removido(s).");

        return 0;
    }
}
