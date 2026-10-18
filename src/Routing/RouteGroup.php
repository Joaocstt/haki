<?php

declare(strict_types=1);

namespace Haki\Routing;

use Closure;
use Haki\Middleware\MiddlewareInterface;

/**
 * Grupo de rotas com prefixo comum. Auth, perfis e middlewares valem para todas as rotas internas.
 *
 *     $app->group('/admin', function (Haki $app) {
 *         $app->get('/usuarios', [AdminController::class, 'usuarios']);
 *     })->role('admin');
 */
final class RouteGroup
{
    /** @var list<Route> */
    private array $routes = [];

    public function __construct(private readonly string $prefix)
    {
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * @internal usado pelo Router
     */
    public function add(Route $route): void
    {
        $this->routes[] = $route;
    }

    public function auth(): self
    {
        foreach ($this->routes as $route) {
            $route->auth();
        }

        return $this;
    }

    public function role(string ...$roles): self
    {
        foreach ($this->routes as $route) {
            $route->role(...$roles);
        }

        return $this;
    }

    public function middleware(MiddlewareInterface|Closure|string ...$middleware): self
    {
        foreach ($this->routes as $route) {
            $route->prependGroupMiddleware(array_values($middleware));
        }

        return $this;
    }

    /**
     * @return list<Route>
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }
}
