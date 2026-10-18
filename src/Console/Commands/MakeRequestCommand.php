<?php

declare(strict_types=1);

namespace Haki\Console\Commands;

use Haki\Console\Output;

/**
 * Cria uma classe de validação em app/Requests.
 *
 *     php vendor/bin/haki make:request CriarClienteRequest
 */
final class MakeRequestCommand implements CommandInterface
{
    public function __construct(private readonly string $basePath)
    {
    }

    public function name(): string
    {
        return 'make:request';
    }

    public function description(): string
    {
        return 'Cria uma classe de validação em app/Requests (ex.: make:request CriarClienteRequest)';
    }

    public function run(array $args, Output $output): int
    {
        $names = array_values(array_filter($args, static fn (string $arg): bool => !str_starts_with($arg, '--')));
        $class = $names[0] ?? '';

        if (preg_match('/^[A-Z][A-Za-z0-9]*$/', $class) !== 1) {
            $output->error('Informe o nome da classe, começando com letra maiúscula.');
            $output->line('  Exemplo: php vendor/bin/haki make:request CriarClienteRequest');

            return 1;
        }

        $directory = $this->basePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Requests';
        $file = $directory . DIRECTORY_SEPARATOR . $class . '.php';

        if (is_file($file) && !in_array('--force', $args, true)) {
            $output->error("app/Requests/{$class}.php já existe. Use --force para sobrescrever.");

            return 1;
        }

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $stub = (string) file_get_contents(__DIR__ . '/../stubs/FormRequest.php.stub');
        file_put_contents($file, str_replace('{{class}}', $class, $stub));

        $output->success("criado   app/Requests/{$class}.php");
        $output->line('  Use no controller: public function store(' . $class . ' $request)');

        return 0;
    }
}
