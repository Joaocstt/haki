<?php

declare(strict_types=1);

namespace Haki\Middleware;

use Closure;
use Haki\Container\ContainerInterface;
use Haki\Exceptions\ContainerException;
use Haki\Http\Request;
use Haki\Http\Response;
use Haki\Http\ResponseConverter;

/**
 * Executa uma lista de middlewares em ordem, terminando no destino (o controller).
 */
final class Pipeline
{
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ResponseConverter $converter = new ResponseConverter(),
    ) {
    }

    /**
     * @param list<MiddlewareInterface|Closure|class-string> $middleware
     * @param Closure(Request): Response                      $destination
     */
    public function process(Request $request, array $middleware, Closure $destination): Response
    {
        $next = $destination;

        foreach (array_reverse($middleware) as $item) {
            $layer = $this->resolve($item);
            $inner = $next;
            $next = fn (Request $request): Response => $this->converter->convert($layer($request, $inner));
        }

        return $next($request);
    }

    /**
     * @return callable(Request, Closure): mixed
     */
    private function resolve(MiddlewareInterface|Closure|string $middleware): callable
    {
        if ($middleware instanceof Closure) {
            return $middleware;
        }

        if (is_string($middleware)) {
            $middleware = $this->container->get($middleware);
        }

        if (!$middleware instanceof MiddlewareInterface) {
            throw new ContainerException(
                'Middleware inválido: ' . get_debug_type($middleware) . '.',
                'Middlewares devem implementar Haki\Middleware\MiddlewareInterface ou ser uma closure fn (Request $r, callable $next).',
            );
        }

        return [$middleware, 'handle'];
    }
}
