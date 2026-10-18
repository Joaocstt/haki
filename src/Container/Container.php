<?php

declare(strict_types=1);

namespace Haki\Container;

use Closure;
use Haki\Exceptions\ContainerException;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Container de dependências com autowiring.
 *
 * Classes concretas são criadas automaticamente a partir do construtor.
 * Interfaces precisam de um bind:
 *
 *     $app->container()->bind(UsuarioRepository::class, PdoUsuarioRepository::class);
 *     $app->container()->singleton(PDO::class, fn () => new PDO('sqlite:banco.sqlite'));
 */
final class Container implements ContainerInterface
{
    /** @var array<string, Closure(self): mixed> */
    private array $factories = [];

    /** @var array<string, true> */
    private array $shared = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var array<string, true> classes em construção, para detectar ciclos */
    private array $building = [];

    /**
     * Registra como criar $id. $concrete pode ser uma closure ou o nome de uma classe.
     * Uma nova instância é criada a cada get().
     *
     * @param Closure(self): mixed|class-string|null $concrete
     */
    public function bind(string $id, Closure|string|null $concrete = null): self
    {
        $this->factories[$id] = $this->factory($id, $concrete);
        unset($this->shared[$id], $this->instances[$id]);

        return $this;
    }

    /**
     * Como bind(), mas a mesma instância é reutilizada.
     *
     * @param Closure(self): mixed|class-string|null $concrete
     */
    public function singleton(string $id, Closure|string|null $concrete = null): self
    {
        $this->bind($id, $concrete);
        $this->shared[$id] = true;

        return $this;
    }

    /**
     * Registra uma instância pronta.
     */
    public function instance(string $id, mixed $instance): self
    {
        $this->instances[$id] = $instance;

        return $this;
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->factories[$id]) || class_exists($id);
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (isset($this->factories[$id])) {
            $value = ($this->factories[$id])($this);

            if (isset($this->shared[$id])) {
                $this->instances[$id] = $value;
            }

            return $value;
        }

        return $this->build($id);
    }

    /**
     * Cria uma classe resolvendo as dependências do construtor.
     *
     * @template T of object
     * @param class-string<T>|string $class
     * @return T|object
     */
    public function build(string $class): object
    {
        if (!class_exists($class)) {
            $kind = interface_exists($class) ? 'é uma interface' : 'não existe';

            throw new ContainerException(
                "Não foi possível criar {$class}: a classe {$kind}.",
                interface_exists($class)
                    ? "Registre uma implementação: \$app->container()->bind({$class}::class, SuaClasse::class);"
                    : 'Confira o namespace, o nome da classe e o autoload do composer.json.',
            );
        }

        if (isset($this->building[$class])) {
            throw new ContainerException(
                'Dependência circular detectada: ' . implode(' → ', array_keys($this->building)) . " → {$class}.",
                'Duas classes dependem uma da outra pelo construtor. Quebre o ciclo extraindo uma terceira classe.',
            );
        }

        $reflection = new ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new ContainerException("Não foi possível criar {$class}: a classe não pode ser instanciada (abstrata ou construtor privado).");
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return new $class();
        }

        $this->building[$class] = true;

        try {
            $args = array_map(
                fn (ReflectionParameter $param): mixed => $this->resolveConstructorParameter($param, $class),
                $constructor->getParameters(),
            );

            return $reflection->newInstanceArgs($args);
        } catch (ReflectionException $e) {
            throw new ContainerException("Não foi possível criar {$class}: {$e->getMessage()}", previous: $e);
        } finally {
            unset($this->building[$class]);
        }
    }

    private function resolveConstructorParameter(ReflectionParameter $param, string $class): mixed
    {
        $type = $param->getType();

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            if ($this->has($type->getName()) || !$param->isDefaultValueAvailable()) {
                return $this->get($type->getName());
            }
        }

        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }

        if ($param->allowsNull()) {
            return null;
        }

        throw new ContainerException(
            "Não sei como injetar \${$param->getName()} no construtor de {$class}.",
            'Parâmetros sem classe (string, int, array...) precisam de valor padrão, '
            . "ou registre a classe com: \$app->container()->bind({$class}::class, fn () => new {$class}(...));",
        );
    }

    /**
     * @param Closure(self): mixed|class-string|null $concrete
     * @return Closure(self): mixed
     */
    private function factory(string $id, Closure|string|null $concrete): Closure
    {
        if ($concrete instanceof Closure) {
            return $concrete;
        }

        $class = $concrete ?? $id;

        return fn (): object => $this->build($class);
    }
}
