<?php

declare(strict_types=1);

namespace Haki\Console\Commands;

use Closure;
use Haki\Console\Output;

/**
 * Cria a estrutura padrão de um projeto Haki, com uma rota e um controller de exemplo.
 *
 *     php vendor/bin/haki init
 */
final class InitCommand implements CommandInterface
{
    /** Pastas da estrutura recomendada (as vazias ganham um .gitkeep). */
    private const DIRECTORIES = [
        'app/Controllers',
        'app/Requests',
        'app/Services',
        'app/Repositories',
        'app/Contracts',
        'app/Models',
        'app/Middleware',
        'bootstrap',
        'routes',
        'public',
        'storage',
    ];

    /** Arquivo criado => stub usado. */
    private const FILES = [
        'app/Controllers/HomeController.php' => 'HomeController.php.stub',
        'bootstrap/app.php' => 'app.php.stub',
        'routes/api.php' => 'api.php.stub',
        'public/index.php' => 'index.php.stub',
        '.env' => 'env.stub',
        '.env.example' => 'env.stub',
        '.gitignore' => 'gitignore.stub',
    ];

    /** @var Closure(string): bool */
    private readonly Closure $dumpAutoload;

    /**
     * @param (Closure(string): bool)|null $dumpAutoload roda "composer dump-autoload" na pasta (injetável para testes)
     */
    public function __construct(private readonly string $basePath, ?Closure $dumpAutoload = null)
    {
        $this->dumpAutoload = $dumpAutoload ?? static function (string $path): bool {
            exec('composer dump-autoload --quiet --working-dir=' . escapeshellarg($path) . ' 2>&1', $output, $code);

            return $code === 0;
        };
    }

    public function name(): string
    {
        return 'init';
    }

    public function description(): string
    {
        return 'Cria a estrutura padrão do projeto (app, bootstrap, routes, public...); --force sobrescreve';
    }

    public function run(array $args, Output $output): int
    {
        $force = in_array('--force', $args, true);
        $output->line($output->bold('Criando a estrutura do projeto Haki...'));
        $output->line();

        foreach (self::DIRECTORIES as $directory) {
            $this->makeDirectory($directory);
        }

        foreach (self::FILES as $file => $stub) {
            $this->createFile($file, $stub, $force, $output);
        }

        foreach (self::DIRECTORIES as $directory) {
            $this->keepIfEmpty($directory);
        }

        $this->registerAutoload($output);
        $output->line();

        if (($this->dumpAutoload)($this->basePath)) {
            $output->success('Autoload atualizado.');
        } else {
            $output->info('Rode "composer dump-autoload" para o PHP encontrar as classes da pasta app/.');
        }

        $output->line();
        $output->line($output->bold('Pronto! Para ver funcionando:'));
        $output->line('  php -S localhost:8000 -t public');
        $output->line('  e acesse ' . $output->muted('http://localhost:8000'));

        return 0;
    }

    private function createFile(string $file, string $stub, bool $force, Output $output): void
    {
        $target = $this->path($file);

        if (is_file($target) && !$force) {
            $output->line('  ' . $output->muted('mantido  ' . $file . ' (já existe)'));

            return;
        }

        file_put_contents($target, (string) file_get_contents(__DIR__ . '/../stubs/' . $stub));
        $output->success('criado   ' . $file);
    }

    /**
     * Adiciona "App\\": "app/" ao autoload do composer.json, sem mexer no resto.
     */
    private function registerAutoload(Output $output): void
    {
        $file = $this->path('composer.json');

        // Decodifica como objetos (não arrays) para "{}" continuar sendo "{}" ao salvar.
        $data = is_file($file) ? json_decode((string) file_get_contents($file)) : new \stdClass();

        if (!$data instanceof \stdClass) {
            $output->error('Não consegui ler o composer.json. Adicione manualmente: "autoload": { "psr-4": { "App\\\\": "app/" } }');

            return;
        }

        $data->autoload ??= new \stdClass();
        $data->autoload->{'psr-4'} ??= new \stdClass();

        if (isset($data->autoload->{'psr-4'}->{'App\\'})) {
            $output->line('  ' . $output->muted('mantido  composer.json (autoload App\\ já configurado)'));

            return;
        }

        $data->autoload->{'psr-4'}->{'App\\'} = 'app/';

        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        $output->success('alterado composer.json (autoload App\\ → app/)');
    }

    private function makeDirectory(string $directory): void
    {
        $path = $this->path($directory);

        if (!is_dir($path)) {
            mkdir($path, 0775, true);
        }
    }

    private function keepIfEmpty(string $directory): void
    {
        $path = $this->path($directory);

        if (count(scandir($path) ?: []) === 2) {
            touch($path . DIRECTORY_SEPARATOR . '.gitkeep');
        }
    }

    private function path(string $relative): string
    {
        return $this->basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }
}
