<?php

declare(strict_types=1);

namespace Haki\Auth\Middleware;

use Haki\Exceptions\AuthenticationException;
use Haki\Exceptions\AuthorizationException;
use Haki\Http\Request;
use Haki\Http\Response;
use Haki\Middleware\MiddlewareInterface;

/**
 * Exige que o usuário tenha um dos perfis informados. Aplicado por ->role().
 */
final class AuthorizeRole implements MiddlewareInterface
{
    /** @var list<string> */
    private readonly array $roles;

    public function __construct(string ...$roles)
    {
        $this->roles = array_values($roles);
    }

    public function handle(Request $request, callable $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            throw new AuthenticationException('A verificação de perfil exige uma rota com ->auth().');
        }

        if (!$user->hasRole(...$this->roles)) {
            throw new AuthorizationException(sprintf(
                'Esta rota exige o perfil %s, mas o usuário tem "%s".',
                implode(' ou ', array_map(static fn (string $r): string => '"' . $r . '"', $this->roles)),
                $user->role ?? 'nenhum',
            ));
        }

        return $next($request);
    }
}
