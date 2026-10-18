<?php

declare(strict_types=1);

namespace Haki\Console;

use Closure;
use Haki\Auth\Storage\SqliteTokenStore;
use Haki\Console\Commands\AuthCleanCommand;
use Haki\Console\Commands\AuthInstallCommand;
use Haki\Console\Commands\CommandInterface;
use Haki\Console\Commands\KeyGenerateCommand;
use Haki\Exceptions\HakiException;
use Haki\Haki;
use Haki\Support\Env;
use Throwable;

/**
 * CLI do Haki: php vendor/bin/haki <comando>
 */
final class Application
{
    /** @var array<string, Closure(): CommandInterface> */
    private array $commands = [];

    public function __construct(private readonly string $basePath, private readonly Output $output = new Output())
    {
        Env::load($basePath . DIRECTORY_SEPARATOR . '.env');

        $this->add('init', fn () => new InitCommand($this->basePath));
        $this->add('key:generate', fn () => new KeyGenerateCommand($this->basePath));
        $this->add('auth:install', fn () => new AuthInstallCommand($this->sqlite()));
        $this->add('auth:clean', fn () => new AuthCleanCommand($this->sqlite()));
    }

    /**
     * @param Closure(): CommandInterface $factory
     */
    public function add(string $name, Closure $factory): void
    {
        $this->commands[$name] = $factory;
    }

    /**
     * @param list<string> $argv argumentos do terminal (sem o nome do script)
     */
    public function run(array $argv): int
    {
        $name = $argv[0] ?? null;

        if ($name === null || in_array($name, ['help', '--help', '-h', 'list'], true)) {
            $this->help();

            return 0;
        }

        if (!isset($this->commands[$name])) {
            $this->output->error("Comando \"{$name}\" não existe.");
            $this->help();

            return 1;
        }

        try {
            return ($this->commands[$name])()->run(array_slice($argv, 1), $this->output);
        } catch (Throwable $e) {
            $this->output->error($e->getMessage());

            if ($e instanceof HakiException && $e->getHint() !== null) {
                $this->output->line('  ' . $e->getHint());
            }

            return 1;
        }
    }

    private function help(): void
    {
        $this->output->line($this->output->bold('Haki ' . Haki::VERSION));
        $this->output->line();
        $this->output->line('Uso: php vendor/bin/haki <comando> [opções]');
        $this->output->line();
        $this->output->line('Comandos:');

        foreach ($this->commands as $name => $factory) {
            $this->output->line('  ' . str_pad($name, 16) . $this->output->muted($factory()->description()));
        }
    }

    private function sqlite(): SqliteTokenStore
    {
        $path = (string) Env::get('JWT_DATABASE', 'storage/haki.sqlite');

        if (preg_match('#^([A-Za-z]:[\\\\/]|/)#', $path) !== 1) {
            $path = $this->basePath . DIRECTORY_SEPARATOR . $path;
        }

        return new SqliteTokenStore($path);
    }
}
