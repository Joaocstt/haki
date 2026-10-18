<?php

declare(strict_types=1);

namespace Haki\Exceptions;

/**
 * 401: token ausente, inválido, expirado ou revogado.
 *
 * O cliente sempre recebe uma mensagem genérica; o motivo real vai na dica (só em desenvolvimento).
 */
final class AuthenticationException extends HttpException
{
    public function __construct(string $reason = 'Token não informado', string $message = 'Token inválido ou expirado')
    {
        parent::__construct(401, $message, ['WWW-Authenticate' => 'Bearer'], $reason);
    }
}
