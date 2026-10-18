<?php

declare(strict_types=1);

namespace Haki\Routing;

/**
 * Resultado da busca de rota: a rota encontrada e os parâmetros extraídos da URL.
 */
final class RouteMatch
{
    /**
     * @param array<string, string> $params
     */
    public function __construct(
        public readonly Route $route,
        public readonly array $params,
    ) {
    }
}
