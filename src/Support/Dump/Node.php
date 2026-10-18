<?php

declare(strict_types=1);

namespace Haki\Support\Dump;

/**
 * Representação neutra de um valor inspecionado, usada pelos renderizadores.
 */
final class Node
{
    /**
     * @param string      $type      null, bool, int, float, string, array, object, enum, closure, resource, recursion
     * @param string      $value     texto principal (valor escalar, nome da classe etc.)
     * @param string|null $meta      informação extra (tamanho, id do objeto, arquivo:linha)
     * @param string|null $tag       rótulo curto (lista, associativo, data formatada)
     * @param list<Child> $children  filhos de arrays e objetos
     * @param int         $hidden    quantidade de filhos omitidos pelo limite de itens
     * @param bool        $truncated filhos não exibidos por atingir a profundidade máxima
     */
    public function __construct(
        public readonly string $type,
        public readonly string $value,
        public readonly ?string $meta = null,
        public readonly ?string $tag = null,
        public readonly array $children = [],
        public readonly int $hidden = 0,
        public readonly bool $truncated = false,
    ) {
    }

    public function isContainer(): bool
    {
        return $this->type === 'array' || $this->type === 'object';
    }

    public function hasChildren(): bool
    {
        return $this->children !== [] || $this->hidden > 0 || $this->truncated;
    }
}
