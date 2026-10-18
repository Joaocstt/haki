<?php

declare(strict_types=1);

namespace Haki\Container;

use Closure;
use Haki\Exceptions\ContainerException;
use Haki\Exceptions\HttpException;
use Haki\Http\Request;
use Haki\Http\Response;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Executa handlers (closures e métodos de controllers) injetando os parâmetros automaticamente:
 *
 *  - Request          → requisição atual
 *  - Response         → nova resposta
 *  - outras classes   → criadas pelo container (Auth, serviços, repositórios...)
 *  - $nome            → parâmetro da rota {nome}, convertido para int/float/bool/string
 */
final class Invoker
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @param callable|array{class-string|object, string}|class-string $handler
     */
    public function call(mixed $handler, Request $request): mixed
    {
        [$callable, $reflection, $label] = $this->normalize($handler);

        $args = array_map(
            fn (ReflectionParameter $param): mixed => $this->resolve($param, $request, $label),
            $reflection->getParameters(),
        );

        return $callable(...$args);
    }

    /**
     * @return array{callable, ReflectionFunctionAbstract, string}
     */
    private function normalize(mixed $handler): array
    {
        if ($handler instanceof Closure) {
            return [$handler, new ReflectionFunction($handler), 'closure da rota'];
        }

        if (is_string($handler) && class_exists($handler)) {
            $handler = [$handler, '__invoke'];
        }

        if (is_array($handler) && count($handler) === 2) {
            [$target, $method] = $handler;
            $class = is_object($target) ? $target::class : (string) $target;

            if (!method_exists($class, (string) $method)) {
                throw new ContainerException(
                    "O método {$class}::{$method}() não existe.",
                    "Crie o método public function {$method}() na classe {$class}.",
                );
            }

            $instance = is_object($target) ? $target : $this->container->get($class);
            $reflection = new ReflectionMethod($instance, (string) $method);

            if (!$reflection->isPublic()) {
                throw new ContainerException("O método {$class}::{$method}() precisa ser public.");
            }

            return [[$instance, $method], $reflection, "{$class}::{$method}"];
        }

        if (is_callable($handler)) {
            $closure = Closure::fromCallable($handler);

            return [$closure, new ReflectionFunction($closure), 'handler da rota'];
        }

        throw new ContainerException(
            'Handler de rota inválido.',
            'Use uma closure, [Controller::class, \'metodo\'] ou uma classe com __invoke().',
        );
    }

    private function resolve(ReflectionParameter $param, Request $request, string $label): mixed
    {
        $name = $param->getName();
        $type = $param->getType();
        $typeName = $type instanceof ReflectionNamedType ? $type->getName() : null;

        if ($typeName !== null && !$type->isBuiltin()) {
            if (is_a($typeName, Request::class, true)) {
                return $request;
            }

            if ($typeName === Response::class) {
                return new Response();
            }

            return $this->container->get($typeName);
        }

        $params = $request->params();

        if (array_key_exists($name, $params)) {
            return $this->cast($params[$name], $typeName, $param);
        }

        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }

        if ($param->allowsNull()) {
            return null;
        }

        throw new ContainerException(
            "Não sei como injetar \${$name} em {$label}.",
            "Use um tipo injetável (Request, Response, Auth ou uma classe), um parâmetro de rota {{$name}} "
            . 'ou dê um valor padrão ao parâmetro.',
        );
    }

    private function cast(string $value, ?string $type, ReflectionParameter $param): mixed
    {
        $invalid = static fn (): HttpException => new HttpException(
            404,
            hint: "O parâmetro {{$param->getName()}} = \"{$value}\" não é um {$type} válido.",
        );

        return match ($type) {
            'int' => preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : throw $invalid(),
            'float' => is_numeric($value) ? (float) $value : throw $invalid(),
            'bool' => match (strtolower($value)) {
                '1', 'true' => true,
                '0', 'false' => false,
                default => throw $invalid(),
            },
            default => $value,
        };
    }
}
