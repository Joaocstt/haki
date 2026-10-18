<?php

declare(strict_types=1);

namespace Haki\Routing;

use Closure;
use Haki\Exceptions\HakiException;
use Haki\Exceptions\MethodNotAllowedException;
use Haki\Exceptions\RouteNotFoundException;

/**
 * Registra rotas e encontra a rota de cada requisição.
 */
final class Router
{
    public const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    /** @var array<string, array<string, Route>> rotas sem parâmetros, por método e caminho */
    private array $static = [];

    /** @var array<string, list<Route>> rotas com parâmetros, por método */
    private array $dynamic = [];

    /** @var list<RouteGroup> grupos abertos no momento */
    private array $groups = [];

    /**
     * @param callable|array{class-string, string}|class-string $handler
     */
    public function add(string $method, string $path, mixed $handler): Route
    {
        $method = strtoupper($method);
        $path = $this->join(array_map(static fn (RouteGroup $g): string => $g->getPrefix(), $this->groups), $path);

        if ($this->exists($method, $path)) {
            throw new HakiException(
                "A rota {$method} {$path} foi registrada duas vezes.",
                'Remova a duplicada ou use outro caminho/método.',
            );
        }

        $route = new Route($method, $path, $handler);

        if ($route->isDynamic()) {
            $this->dynamic[$method][] = $route;
        } else {
            $this->static[$method][$path] = $route;
        }

        foreach ($this->groups as $group) {
            $group->add($route);
        }

        return $route;
    }

    /**
     * Abre um grupo: as rotas registradas dentro de $register recebem o prefixo.
     */
    public function group(string $prefix, Closure $register): RouteGroup
    {
        $group = new RouteGroup($this->join([], $prefix));
        $this->groups[] = $group;

        try {
            $register();
        } finally {
            array_pop($this->groups);
        }

        return $group;
    }

    /**
     * @throws RouteNotFoundException     caminho inexistente (404)
     * @throws MethodNotAllowedException  caminho existe com outro método (405)
     */
    public function match(string $method, string $path): RouteMatch
    {
        $method = strtoupper($method);
        $match = $this->find($method, $path) ?? ($method === 'HEAD' ? $this->find('GET', $path) : null);

        if ($match !== null) {
            return $match;
        }

        $allowed = array_values(array_filter(
            self::METHODS,
            fn (string $other): bool => $this->find($other, $path) !== null,
        ));

        if ($allowed !== []) {
            throw new MethodNotAllowedException($method, $path, $allowed);
        }

        throw new RouteNotFoundException($method, $path, $this->suggest($path));
    }

    /**
     * @return list<Route>
     */
    public function routes(): array
    {
        $routes = [];

        foreach ($this->static as $byPath) {
            array_push($routes, ...array_values($byPath));
        }

        foreach ($this->dynamic as $list) {
            array_push($routes, ...$list);
        }

        return $routes;
    }

    private function find(string $method, string $path): ?RouteMatch
    {
        if (isset($this->static[$method][$path])) {
            return new RouteMatch($this->static[$method][$path], []);
        }

        foreach ($this->dynamic[$method] ?? [] as $route) {
            $params = $route->matches($path);

            if ($params !== null) {
                return new RouteMatch($route, $params);
            }
        }

        return null;
    }

    private function exists(string $method, string $path): bool
    {
        if (isset($this->static[$method][$path])) {
            return true;
        }

        foreach ($this->dynamic[$method] ?? [] as $route) {
            if ($route->getPath() === $path) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rotas com caminho parecido, para a dica do erro 404.
     *
     * @return list<string>
     */
    private function suggest(string $path): array
    {
        $scored = [];
        $segments = explode('/', $path);

        foreach ($this->routes() as $route) {
            // Troca {param} pelo trecho correspondente da URL, para comparar só as partes fixas.
            $routeSegments = explode('/', $route->getPath());

            if (count($routeSegments) === count($segments)) {
                foreach ($routeSegments as $i => $segment) {
                    if (str_starts_with($segment, '{')) {
                        $routeSegments[$i] = $segments[$i];
                    }
                }
            }

            $distance = levenshtein($path, implode('/', $routeSegments));

            if ($distance <= max(3, intdiv(strlen($path), 4))) {
                $scored[$route->getMethod() . ' ' . $route->getPath()] = $distance;
            }
        }

        asort($scored);

        return array_slice(array_keys($scored), 0, 3);
    }

    /**
     * @param list<string> $prefixes
     */
    private function join(array $prefixes, string $path): string
    {
        $parts = array_filter(
            array_map(static fn (string $p): string => trim($p, '/'), [...$prefixes, $path]),
            static fn (string $p): bool => $p !== '',
        );

        return '/' . implode('/', $parts);
    }
}
