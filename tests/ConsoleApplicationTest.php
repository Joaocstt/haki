<?php

declare(strict_types=1);

namespace Haki\Tests;

use Haki\Console\Application;
use Haki\Console\Output;
use PHPUnit\Framework\TestCase;

/**
 * Garante que todos os comandos registrados na CLI podem ser criados.
 */
final class ConsoleApplicationTest extends TestCase
{
    public function testHelpListsEveryCommandWithoutErrors(): void
    {
        $stream = fopen('php://memory', 'w+');
        $app = new Application(sys_get_temp_dir(), new Output($stream, false));

        self::assertSame(0, $app->run([]));

        rewind($stream);
        $help = (string) stream_get_contents($stream);

        foreach (['init', 'make:request', 'key:generate', 'auth:install', 'auth:clean'] as $command) {
            self::assertStringContainsString($command, $help);
        }
    }

    public function testUnknownCommandFails(): void
    {
        $app = new Application(sys_get_temp_dir(), new Output(fopen('php://memory', 'w+'), false));

        self::assertSame(1, $app->run(['nao-existe']));
    }
}
