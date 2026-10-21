<?php

declare(strict_types=1);

namespace Haki\Exceptions;

/**
 * 429: muitas tentativas de login em pouco tempo. O cliente deve esperar antes de tentar de novo.
 */
final class TooManyAttemptsException extends HttpException
{
    public function __construct(int $retryAfterSeconds)
    {
        parent::__construct(
            429,
            'Muitas tentativas de login. Tente novamente em alguns minutos.',
            ['Retry-After' => (string) $retryAfterSeconds],
            "Limite de tentativas atingido. A janela de {$retryAfterSeconds} segundos reinicia após a última falha contada.",
        );
    }
}
