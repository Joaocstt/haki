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

    /** @var array{0: int, 1: int}|null [máximo de requisições, janela em segundos] */
    private ?array $limite = null;

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
     * Limita quantas vezes cada IP pode chamar esta rota dentro de uma janela.
     * Passando do máximo, a resposta é 429 com Retry-After.
     *
     *     $app->post('/login', [AuthController::class, 'login'])->limite(5, 900);   // 5 a cada 15 minutos
     */
    public function limite(int $maximo, int $janela): self
    {
        if ($maximo < 1 || $janela < 1) {
            throw new \InvalidArgumentException('limite() precisa de máximo e janela maiores que zero.');
        }

        $this->limite = [$maximo, $janela];

        return $this;
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    public function getLimite(): ?array
    {
        return $this->limite;
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
