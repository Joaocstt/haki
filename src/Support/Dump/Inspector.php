<?php

declare(strict_types=1);

namespace Haki\Support\Dump;

use BackedEnum;
use Closure;
use DateTimeInterface;
use ReflectionFunction;
use UnitEnum;

/**
 * Converte qualquer valor PHP em uma árvore de Node.
 */
final class Inspector
{
    /** @var array<int, true> ids dos objetos no caminho atual, para detectar recursão */
    private array $path = [];

    public function __construct(
        private readonly int $maxDepth = 10,
        private readonly int $maxItems = 500,
    ) {
    }

    public function inspect(mixed $value, int $depth = 0): Node
    {
        return match (true) {
            $value === null => new Node('null', 'null'),
            is_bool($value) => new Node('bool', $value ? 'true' : 'false'),
            is_int($value) => new Node('int', (string) $value),
            is_float($value) => new Node('float', $this->formatFloat($value)),
            is_string($value) => $this->inspectString($value),
            is_array($value) => $this->inspectArray($value, $depth),
            $value instanceof UnitEnum => $this->inspectEnum($value),
            $value instanceof Closure => $this->inspectClosure($value),
            is_object($value) => $this->inspectObject($value, $depth),
            is_resource($value) => new Node('resource', get_resource_type($value), '#' . (int) $value),
            default => new Node('resource', 'fechado'),
        };
    }

    private function formatFloat(float $value): string
    {
        if (is_nan($value)) {
            return 'NAN';
        }

        if (is_infinite($value)) {
            return $value > 0 ? 'INF' : '-INF';
        }

        $text = (string) $value;

        return preg_match('/[.eE]/', $text) === 1 ? $text : $text . '.0';
    }

    private function inspectString(string $value): Node
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            $preview = bin2hex(substr($value, 0, 64)) . (strlen($value) > 64 ? '…' : '');

            return new Node('string', '0x' . $preview, strlen($value) . ' bytes', 'binário');
        }

        $length = mb_strlen($value, 'UTF-8');

        return new Node('string', $value, (string) $length);
    }

    /**
     * @param array<mixed> $value
     */
    private function inspectArray(array $value, int $depth): Node
    {
        $count = count($value);
        $tag = $count === 0 ? null : (array_is_list($value) ? 'lista' : 'associativo');

        if ($count > 0 && $depth >= $this->maxDepth) {
            return new Node('array', 'array', (string) $count, $tag, truncated: true);
        }

        $children = [];

        foreach ($value as $key => $item) {
            if (count($children) >= $this->maxItems) {
                break;
            }

            $children[] = new Child(
                (string) $key,
                is_int($key) ? 'index' : 'key',
                $this->inspect($item, $depth + 1),
            );
        }

        return new Node('array', 'array', (string) $count, $tag, $children, $count - count($children));
    }

    private function inspectEnum(UnitEnum $value): Node
    {
        $meta = $value instanceof BackedEnum ? var_export($value->value, true) : null;

        return new Node('enum', $value::class . '::' . $value->name, $meta);
    }

    private function inspectClosure(Closure $value): Node
    {
        $reflection = new ReflectionFunction($value);
        $params = [];

        foreach ($reflection->getParameters() as $param) {
            $type = $param->getType();
            $text = ($type !== null ? $type . ' ' : '')
                . ($param->isVariadic() ? '...' : '')
                . '$' . $param->getName();

            if ($param->isDefaultValueAvailable()) {
                $text .= ' = …';
            }

            $params[] = $text;
        }

        $return = $reflection->getReturnType();
        $signature = 'Closure(' . implode(', ', $params) . ')'
            . ($return !== null ? ': ' . $return : '');

        $file = $reflection->getFileName();
        $meta = $file !== false ? basename($file) . ':' . $reflection->getStartLine() : null;

        return new Node('closure', $signature, $meta);
    }

    private function inspectObject(object $value, int $depth): Node
    {
        $id = spl_object_id($value);
        $class = $value::class;

        if (isset($this->path[$id])) {
            return new Node('recursion', $class, '#' . $id);
        }

        $tag = $value instanceof DateTimeInterface ? $value->format('Y-m-d H:i:s P') : null;
        $properties = $value instanceof DateTimeInterface ? [] : get_mangled_object_vars($value);

        if ($properties !== [] && $depth >= $this->maxDepth) {
            return new Node('object', $class, '#' . $id, $tag, truncated: true);
        }

        $this->path[$id] = true;
        $children = [];

        foreach ($properties as $mangled => $item) {
            if (count($children) >= $this->maxItems) {
                break;
            }

            [$name, $kind, $owner] = $this->parsePropertyName((string) $mangled, $class);
            $children[] = new Child($name, $kind, $this->inspect($item, $depth + 1), $owner);
        }

        unset($this->path[$id]);

        return new Node('object', $class, '#' . $id, $tag, $children, count($properties) - count($children));
    }

    /**
     * Interpreta o nome "mangled" do PHP: "\0*\0nome" (protegido), "\0Classe\0nome" (privado).
     *
     * @return array{string, string, string|null}
     */
    private function parsePropertyName(string $mangled, string $class): array
    {
        if (!str_starts_with($mangled, "\0")) {
            return [$mangled, 'public', null];
        }

        [, $scope, $name] = explode("\0", $mangled, 3);

        if ($scope === '*') {
            return [$name, 'protected', null];
        }

        return [$name, 'private', $scope !== $class ? $scope : null];
    }
}
