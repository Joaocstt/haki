<?php

declare(strict_types=1);

namespace Haki\Exceptions;

/**
 * 403: usuário autenticado, mas sem o perfil necessário.
 */
final class AuthorizationException extends HttpException
{
    public function __construct(string $reason = '')
    {
        parent::__construct(403, 'Acesso negado', hint: $reason !== '' ? $reason : null);
    }
}
