<?php

declare(strict_types=1);

namespace Haki\Middleware;

use Haki\Http\Request;
use Haki\Http\Response;

/**
 * Middleware: código que roda antes e/ou depois do controller.
 *
 *     final class MedirTempo implements MiddlewareInterface
 *     {
 *         public function handle(Request $request, callable $next): Response
 *         {
 *             $inicio = microtime(true);           // antes do controller
 *             $response = $next($request);         // chama o próximo (ou o controller)
 *             return $response->header('X-Tempo', (string) (microtime(true) - $inicio)); // depois
 *         }
 *     }
 *
 * Para interromper a requisição, retorne uma Response sem chamar $next.
 */
interface MiddlewareInterface
{
    /**
     * @param callable(Request): Response $next
     */
    public function handle(Request $request, callable $next): Response;
}
