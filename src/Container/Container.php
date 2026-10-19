<?php

declare(strict_types=1);

namespace Haki\Container;

use Closure;
use Haki\Exceptions\ContainerException;
use Haki\Exceptions\TypeCheckException;
use Haki\Support\ClassFinder;
use Haki\Support\TypeChecker;
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
 *     $app->container()->singleton(PDO::class, function () {
 *         return new PDO('sqlite:banco.sqlite');
 *     });
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

    /** @var list<array{from: string, to: string, prefix: string, suffix: string, patterns: list<string>}> */
    private array $discoveries = [];

    private ?TypeChecker $typeChecker = null;

    /**
     * Passa a exigir tipagem em toda classe do projeto que o container criar.
     * Normalmente ativado por $app->requireTypes().
     */
    public function requireTypes(TypeChecker $checker = new TypeChecker()): self
    {
        $this->typeChecker = $checker;

        return $this;
    }

    public function typeChecker(): ?TypeChecker
    {
        return $this->typeChecker;
    }

    /**
     * @throws TypeCheckException se a tipagem é exigida e a classe tem problemas
     */
    public function assertTyped(string $class): void
    {
        if ($this->typeChecker === null) {
            return;
        }

        $problems = $this->typeChecker->checkClass($class);

        if ($problems !== []) {
            throw new TypeCheckException($class, $problems);
        }
    }

    /**
     * Descobre sozinho a implementação de cada interface, pelo nome, sem precisar de bind().
     *
     *     $app->container()->discover('App\\Contracts', 'App\\Repositories');
     *     // ProdutoRepositoryInterface → ProdutoRepository ou PdoProdutoRepository
     *
     * Para outros padrões de nome:
     *
     *     $app->container()->discover(
     *         from: 'App\\Domain\\Contracts',
     *         to: 'App\\Infra',
     *         suffix: 'Contract',               // ProdutoRepositoryContract
     *         patterns: ['Mysql{name}'],        // → MysqlProdutoRepository
     *     );
     *
     * Um bind() explícito sempre tem prioridade. Se duas classes servirem, é erro (nunca escolhe sozinho).
     *
     * @param string       $from     namespace das interfaces
     * @param string       $to       namespace das implementações
     * @param string       $suffix   parte do nome da interface que é removida (ex.: "Interface")
     * @param string       $prefix   prefixo removido do nome da interface (ex.: "I" em IProdutoRepository)
     * @param list<string> $patterns nomes procurados; {name} é o nome da interface sem prefixo/sufixo
     */
    public function discover(
        string $from,
        string $to,
        string $suffix = 'Interface',
        string $prefix = '',
        array $patterns = ['{name}', 'Pdo{name}'],
    ): self {
        $this->discoveries[] = [
            'from' => trim($from, '\\'),
            'to' => trim($to, '\\'),
            'prefix' => $prefix,
            'suffix' => $suffix,
            'patterns' => array_values($patterns),
        ];

        return $this;
    }

    /**
     * Qual classe o discover() encontra para a interface. Null se nenhuma regra de discover() se aplica.
     *
     * @throws ContainerException quando a regra se aplica, mas nada (ou mais de uma classe) serve
     */
    public function discoverFor(string $interface): ?string
    {
        $interface = ltrim($interface, '\\');
        $searched = [];

        foreach ($this->discoveries as $rule) {
            if (!str_starts_with($interface, $rule['from'] . '\\')) {
                continue;
            }

            $relative = substr($interface, strlen($rule['from']) + 1);
            $position = strrpos($relative, '\\');
            $subNamespace = $position === false ? '' : substr($relative, 0, $position) . '\\';
            $short = $position === false ? $relative : substr($relative, $position + 1);

            if (($rule['prefix'] !== '' && !str_starts_with($short, $rule['prefix']))
                || ($rule['suffix'] !== '' && !str_ends_with($short, $rule['suffix']))) {
                continue;
            }

            $name = substr($short, strlen($rule['prefix']), strlen($short) - strlen($rule['prefix']) - strlen($rule['suffix']));

            if ($name === '') {
                continue;
            }

            $candidates = array_values(array_unique(array_map(
                static fn (string $pattern): string => $rule['to'] . '\\' . $subNamespace . str_replace('{name}', $name, $pattern),
                $rule['patterns'],
            )));
            $found = array_values(array_filter($candidates, static fn (string $class): bool => class_exists($class)));

            if (count($found) > 1) {
                throw new ContainerException(
                    "Mais de uma classe serve para {$interface}: " . implode(' e ', $found) . '.',
                    "Escolha uma com um bind explícito: \$app->container()->bind({$this->shortName($interface)}::class, {$this->shortName($found[0])}::class);",
                );
            }

            if (count($found) === 1) {
                if (!is_subclass_of($found[0], $interface)) {
                    throw new ContainerException(
                        "{$found[0]} foi encontrada para {$interface}, mas não implementa essa interface.",
                        "Adicione na classe: implements {$this->shortName($interface)}",
                    );
                }

                return $found[0];
            }

            array_push($searched, ...$candidates);
        }

        if ($searched === []) {
            return null;
        }

        $suggestion = null;

        foreach ($searched as $candidate) {
            $suggestion ??= ClassFinder::similar($candidate);
        }

        throw new ContainerException(
            "Nenhuma implementação encontrada para {$interface}. Procurei: " . implode(', ', $searched) . '.',
            ($suggestion !== null ? "Você quis dizer {$suggestion}? Renomeie a classe para seguir o padrão ou faça um bind. " : '')
            . 'Confira também se o namespace do arquivo está certo e rode composer dump-autoload.',
        );
    }

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

    /**
     * Se existe um bind(), singleton() ou instance() para o id.
     */
    public function bound(string $id): bool
    {
        return array_key_exists($id, $this->instances) || isset($this->factories[$id]);
    }

    /**
     * As regras registradas com discover().
     *
     * @return list<array{from: string, to: string, prefix: string, suffix: string, patterns: list<string>}>
     */
    public function discoveries(): array
    {
        return $this->discoveries;
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

        if (interface_exists($id)) {
            $implementation = $this->discoverFor($id);

            if ($implementation !== null) {
                $this->factories[$id] = fn (): object => $this->build($implementation);

                return $this->get($id);
            }
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
            if (interface_exists($class)) {
                throw new ContainerException(
                    "Não foi possível criar {$class}: a classe é uma interface.",
                    "Registre uma implementação: \$app->container()->bind({$this->shortName($class)}::class, SuaClasse::class);"
                    . ($this->discoveries === [] ? ' Ou use $app->container()->discover(...) para encontrar pelo nome.' : ''),
                );
            }

            $suggestion = ClassFinder::similar($class);

            throw new ContainerException(
                "Não foi possível criar {$class}: a classe não existe.",
                ($suggestion !== null ? "Você quis dizer {$suggestion}? " : '')
                . 'Confira o namespace, o nome da classe e rode composer dump-autoload.',
            );
        }

        if (isset($this->building[$class])) {
            throw new ContainerException(
                'Dependência circular detectada: ' . implode(' → ', array_keys($this->building)) . " → {$class}.",
                'Duas classes dependem uma da outra pelo construtor. Quebre o ciclo extraindo uma terceira classe.',
            );
        }

        $this->assertTyped($class);

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

    private function shortName(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
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
