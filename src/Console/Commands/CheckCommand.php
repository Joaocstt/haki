<?php

declare(strict_types=1);

namespace Haki\Console\Commands;

use Closure;
use Haki\Console\Output;
use Haki\Exceptions\HakiException;
use Haki\Exceptions\TypeCheckException;
use Haki\Haki;
use Haki\Http\Request;
use Haki\Http\Response;
use Haki\Support\ClassFinder;
use Haki\Validation\FormRequest;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Confere se a aplicação consegue montar tudo: interfaces, controllers e suas dependências.
 *
 *     php vendor/bin/haki check
 *
 * Carrega bootstrap/app.php e cria cada controller usado nas rotas, como aconteceria numa requisição.
 */
final class CheckCommand implements CommandInterface
{
    private int $problems = 0;

    public function __construct(private readonly string $basePath)
    {
    }

    public function name(): string
    {
        return 'check';
    }

    public function description(): string
    {
        return 'Confere interfaces, controllers e dependências antes de publicar';
    }

    public function run(array $args, Output $output): int
    {
        $bootstrap = $this->basePath . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';

        if (!is_file($bootstrap)) {
            $output->error('bootstrap/app.php não encontrado. Rode o comando na raiz do projeto (ou crie a estrutura com: php vendor/bin/haki init).');

            return 1;
        }

        $app = require $bootstrap;

        if (!$app instanceof Haki) {
            $output->error('bootstrap/app.php precisa terminar com: return $app;');

            return 1;
        }

        $this->problems = 0;

        $output->line($output->bold('Interfaces'));
        $this->checkInterfaces($app, $output);

        $output->line();
        $output->line($output->bold('Rotas'));
        $this->checkRoutes($app, $output);

        if ($app->container()->typeChecker() !== null) {
            $output->line();
            $output->line($output->bold('Tipagem'));
            $this->checkTypes($app, $output);
        }

        $output->line();

        if ($this->problems === 0) {
            $output->success('Tudo certo.');

            return 0;
        }

        $output->error($this->problems === 1 ? '1 problema encontrado.' : "{$this->problems} problemas encontrados.");

        return 1;
    }

    private function checkInterfaces(Haki $app, Output $output): void
    {
        $container = $app->container();
        $interfaces = [];

        foreach ($container->discoveries() as $rule) {
            foreach (ClassFinder::inNamespace($rule['from']) as $class) {
                if (interface_exists($class)) {
                    $interfaces[] = $class;
                }
            }
        }

        if ($interfaces === []) {
            $output->line('  ' . $output->muted('nenhuma interface encontrada pelo discover()'));

            return;
        }

        foreach (array_unique($interfaces) as $interface) {
            if ($container->bound($interface)) {
                $output->success("{$interface} → bind no bootstrap");

                continue;
            }

            try {
                $implementation = $container->discoverFor($interface);
                $output->success("{$interface} → {$implementation}");
            } catch (Throwable $e) {
                $this->fail($output, $interface, $e);
            }
        }
    }

    private function checkRoutes(Haki $app, Output $output): void
    {
        $routes = $app->router()->routes();

        if ($routes === []) {
            $output->line('  ' . $output->muted('nenhuma rota registrada'));

            return;
        }

        foreach ($routes as $route) {
            $label = $route->getMethod() . ' ' . $route->getPath();
            $handler = $route->getHandler();

            if ($handler instanceof Closure) {
                $output->success("{$label} → função anônima");

                continue;
            }

            [$class, $method] = is_array($handler) ? $handler : [$handler, '__invoke'];

            try {
                $this->checkHandler($app, (string) $class, (string) $method);
                $output->success("{$label} → {$class}::{$method}");
            } catch (Throwable $e) {
                $this->fail($output, $label, $e);
            }
        }
    }

    /**
     * Confere a tipagem de todas as classes do projeto (ex.: app/), inclusive as que nenhuma rota usa.
     */
    private function checkTypes(Haki $app, Output $output): void
    {
        $checker = $app->container()->typeChecker();
        $classes = ClassFinder::inProject($this->basePath);
        $ok = 0;

        foreach ($classes as $class) {
            if (!class_exists($class) && !interface_exists($class) && !enum_exists($class) && !trait_exists($class)) {
                continue;
            }

            $problems = $checker?->checkClass($class) ?? [];

            if ($problems === []) {
                $ok++;

                continue;
            }

            $this->problems++;
            $output->error($class);

            foreach ($problems as $problem) {
                $output->line('    ' . $problem);
            }
        }

        $output->success($ok === 1 ? '1 classe tipada corretamente' : "{$ok} classes tipadas corretamente");
    }

    private function checkHandler(Haki $app, string $class, string $method): void
    {
        $container = $app->container();
        $controller = $container->get($class); // cria o controller e as dependências do construtor

        if (!method_exists($controller, $method)) {
            throw new HakiException("O método {$class}::{$method}() não existe.");
        }

        foreach ((new ReflectionMethod($controller, $method))->getParameters() as $param) {
            $type = $param->getType();

            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $name = $type->getName();

            if (is_a($name, Request::class, true) || $name === Response::class) {
                continue;
            }

            is_a($name, FormRequest::class, true) ? $container->build($name) : $container->get($name);
        }
    }

    private function fail(Output $output, string $label, Throwable $e): void
    {
        $this->problems++;
        $output->error("{$label}");

        // O detalhe da tipagem aparece uma vez só, na seção "Tipagem".
        if ($e instanceof TypeCheckException) {
            $output->line('    ' . $e->subject() . ' tem problemas de tipagem (detalhes em "Tipagem", abaixo).');

            return;
        }
        $output->line('    ' . $e->getMessage());

        if ($e instanceof HakiException && $e->getHint() !== null) {
            $output->line('    ' . $output->muted($e->getHint()));
        }
    }
}
