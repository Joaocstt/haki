<?php

declare(strict_types=1);

namespace App\Middleware;

use Haki\Http\Request;
use Haki\Http\Response;
use Haki\Middleware\MiddlewareInterface;

/**
 * Libera o front-end informado a chamar a API pelo navegador.
 */
final class Cors implements MiddlewareInterface
{
    public function __construct(private readonly string $origem = 'http://localhost:5173')
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $response = $request->method() === 'OPTIONS' ? new Response('', 204) : $next($request);

        return $response
            ->header('Access-Control-Allow-Origin', $this->origem)
            ->header('Access-Control-Allow-Headers', 'Authorization, Content-Type')
            ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
    }
}
