<?php

declare(strict_types=1);

namespace Haki\Auth\Middleware;

use Haki\Auth\Auth;
use Haki\Exceptions\AuthenticationException;
use Haki\Http\Request;
use Haki\Http\Response;
use Haki\Middleware\MiddlewareInterface;

/**
 * Exige um Bearer token válido e preenche $request->user(). Aplicado por ->auth().
 */
final class Authenticate implements MiddlewareInterface
{
    public function __construct(private readonly Auth $auth)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $token = $request->bearerToken();

        if ($token === null) {
            throw new AuthenticationException(
                'Envie o cabeçalho "Authorization: Bearer <token>".',
                'Token não informado',
            );
        }

        $request->setUser($this->auth->authenticate($token));

        return $next($request);
    }
}
