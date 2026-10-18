<?php

declare(strict_types=1);

namespace Haki\Routing;

use Closure;
use Haki\Middleware\MiddlewareInterface;

/**
 * Uma rota registrada. Os métodos fluentes configuram autenticação, perfis e middlewares.
 *
 *     $app->get('/admin', [AdminController::class, 'index'])->auth()->role('admin');
 */
final class Route
{
    /** @var list<MiddlewareInterface|Closure|class-string> */
    private array $groupMiddleware = [];

    /** @var list<MiddlewareInterface|Closure|class-string> */
    private array $middleware = [];

    private bool $auth = false;

    /** @var list<list<string>> cada item é um requisito "um destes perfis"; todos precisam ser atendidos */
    private array $roles = [];

    private ?string $regex = null;

    /**
     * @param callable|array{class-string, string}|class-string $handler
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly mixed $handler,
    ) {
    }

    /**
     * Exige um token JWT válido.
     */
    public function auth(): self
    {
        $this->auth = true;

        return $this;
    }

    /**
     * Exige um dos perfis informados. Implica auth().
     */
    public function role(string ...$roles): self
    {
        $this->auth = true;
        $this->roles[] = array_values($roles);

        return $this;
    }

    /**
     * Middlewares executados apenas nesta rota.
     */
    public function middleware(MiddlewareInterface|Closure|string ...$middleware): self
    {
        array_push($this->middleware, ...$middleware);

        return $this;
    }

    /**
     * @internal usado por RouteGroup; middlewares de grupo rodam antes dos da rota
     * @param list<MiddlewareInterface|Closure|class-string> $middleware
     */
    public function prependGroupMiddleware(array $middleware): void
    {
        $this->groupMiddleware = array_merge($middleware, $this->groupMiddleware);
    }

    /**
     * Retorna os parâmetros da URL se o caminho corresponder; senão, null.
     *
     * @return array<string, string>|null
     */
    public function matches(string $path): ?array
    {
        if (!$this->isDynamic()) {
            return $path === $this->path ? [] : null;
        }

        if (preg_match($this->regex(), $path, $matches) !== 1) {
            return null;
        }

        return array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
    }

    public function isDynamic(): bool
    {
        return str_contains($this->path, '{');
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * @return callable|array{class-string, string}|class-string
     */
    public function getHandler(): mixed
    {
        return $this->handler;
    }

    /**
     * @return list<MiddlewareInterface|Closure|class-string>
     */
    public function getGroupMiddleware(): array
    {
        return $this->groupMiddleware;
    }

    /**
     * @return list<MiddlewareInterface|Closure|class-string>
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    public function requiresAuth(): bool
    {
        return $this->auth;
    }

    /**
     * @return list<list<string>>
     */
    public function getRoles(): array
    {
        return $this->roles;
    }

    private function regex(): string
    {
        return $this->regex ??= '#^' . preg_replace_callback(
            '/\\\\{([A-Za-z_][A-Za-z0-9_]*)\\\\}/',
            static fn (array $m): string => '(?P<' . $m[1] . '>[^/]+)',
            preg_quote($this->path, '#'),
        ) . '$#';
    }
}
