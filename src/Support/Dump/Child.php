<?php

declare(strict_types=1);

namespace Haki\Support\Dump;

/**
 * Um item de array ou propriedade de objeto.
 */
final class Child
{
    /**
     * @param string      $kind  index, key, public, protected, private
     * @param string|null $owner classe que declara uma propriedade privada herdada
     */
    public function __construct(
        public readonly string $key,
        public readonly string $kind,
        public readonly Node $node,
        public readonly ?string $owner = null,
    ) {
    }
}
