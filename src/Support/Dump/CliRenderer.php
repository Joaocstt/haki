<?php

declare(strict_types=1);

namespace Haki\Support\Dump;

/**
 * Renderiza nós como uma árvore de texto, com cores ANSI opcionais.
 */
final class CliRenderer
{
    private const COLORS = [
        'muted' => '90',
        'logo' => '1;97;41',
        'null' => '90',
        'bool' => '35',
        'int' => '94',
        'float' => '94',
        'string' => '32',
        'array' => '33',
        'object' => '91',
        'enum' => '36',
        'closure' => '93',
        'resource' => '90',
        'recursion' => '1;31',
        'public' => '92',
        'protected' => '93',
        'private' => '91',
    ];

    private const VISIBILITY = ['public' => '+', 'protected' => '#', 'private' => '-'];

    public function __construct(private readonly bool $colors = true)
    {
    }

    /**
     * @param list<Node> $nodes
     */
    public function render(array $nodes, ?string $file, ?int $line): string
    {
        $location = $file !== null ? ' ' . $this->paint('muted', $this->relativePath($file) . ($line !== null ? ':' . $line : '')) : '';
        $output = $this->paint('logo', ' haki ') . $location . PHP_EOL;

        if ($nodes === []) {
            return $output . $this->paint('muted', 'nenhum valor informado') . PHP_EOL . PHP_EOL;
        }

        foreach ($nodes as $index => $node) {
            $prefix = count($nodes) > 1 ? $this->paint('muted', '#' . ($index + 1) . ' ') : '';
            $output .= $prefix . $this->summary($node) . PHP_EOL . $this->children($node, '');
        }

        return $output . PHP_EOL;
    }

    private function children(Node $node, string $indent): string
    {
        if (!$node->isContainer()) {
            return '';
        }

        $lines = [];

        foreach ($node->children as $child) {
            $lines[] = [$this->key($child) . ' ' . $this->summary($child->node), $child->node];
        }

        if ($node->hidden > 0) {
            $lines[] = [$this->paint('muted', '… e mais ' . $node->hidden . ' itens'), null];
        }

        if ($node->truncated) {
            $lines[] = [$this->paint('muted', '… profundidade máxima atingida'), null];
        }

        $output = '';
        $last = count($lines) - 1;

        foreach ($lines as $i => [$text, $child]) {
            $branch = $i === $last ? '└─ ' : '├─ ';
            $next = $indent . ($i === $last ? '   ' : '│  ');
            $output .= $this->paint('muted', $indent . $branch) . $text . PHP_EOL;

            if ($child !== null) {
                $output .= $this->children($child, $next);
            }
        }

        return $output;
    }

    private function key(Child $child): string
    {
        return match ($child->kind) {
            'index' => $this->paint('int', $child->key) . $this->paint('muted', ' =>'),
            'key' => $this->paint('string', '"' . $this->escape($child->key) . '"') . $this->paint('muted', ' =>'),
            default => $this->paint($child->kind, self::VISIBILITY[$child->kind]) . $child->key . $this->paint('muted', ':'),
        };
    }

    private function summary(Node $node): string
    {
        $type = fn (string $label): string => $this->paint($node->type, $label);

        return match ($node->type) {
            'null' => $type('null'),
            'bool', 'int', 'float' => $type($node->type . ' ' . $node->value),
            'string' => $node->tag === 'binário'
                ? $type('string ' . $node->value) . $this->paint('muted', ' (binário, ' . $node->meta . ')')
                : $type('string(' . $node->meta . ') "' . $this->escape($node->value) . '"'),
            'array' => $type('array(' . $node->meta . ')') . $this->paint('muted', ' ' . ($node->tag ?? 'vazio')),
            'object' => $type('object ' . $node->value) . $this->paint('muted', ' ' . $node->meta)
                . ($node->tag !== null ? ' ' . $this->paint('int', $node->tag) : ''),
            'enum' => $type('enum ' . $node->value) . ($node->meta !== null ? $this->paint('muted', ' = ' . $node->meta) : ''),
            'closure' => $type($node->value) . ($node->meta !== null ? $this->paint('muted', ' em ' . $node->meta) : ''),
            'resource' => $type('resource ' . $node->value) . ($node->meta !== null ? $this->paint('muted', ' ' . $node->meta) : ''),
            'recursion' => $type('*recursão*') . ' ' . $node->value . $this->paint('muted', ' ' . $node->meta),
            default => $type($node->type . ' ' . $node->value),
        };
    }

    private function escape(string $text): string
    {
        return addcslashes($text, "\0..\37\177\\\"");
    }

    private function relativePath(string $file): string
    {
        $cwd = getcwd();

        if ($cwd !== false && str_starts_with($file, $cwd . DIRECTORY_SEPARATOR)) {
            return substr($file, strlen($cwd) + 1);
        }

        return $file;
    }

    private function paint(string $color, string $text): string
    {
        if (!$this->colors) {
            return $text;
        }

        return "\033[" . self::COLORS[$color] . 'm' . $text . "\033[0m";
    }
}
