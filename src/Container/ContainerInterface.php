<?php

declare(strict_types=1);

namespace Haki\Container;

/**
 * Contrato mínimo de container (mesmos métodos do PSR-11).
 */
interface ContainerInterface
{
    /**
     * @template T of object
     * @param class-string<T>|string $id
     * @return ($id is class-string<T> ? T : mixed)
     */
    public function get(string $id): mixed;

    public function has(string $id): bool;
}
