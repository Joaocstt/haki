<?php

declare(strict_types=1);

namespace Haki\Exceptions;

use Throwable;

/**
 * Exceção que vira uma resposta HTTP com o status e a mensagem informados.
 *
 *     throw new HttpException(404, 'Usuário não encontrado');
 */
class HttpException extends HakiException
{
    /**
     * @param array<string, string> $headers cabeçalhos adicionados à resposta de erro
     */
    public function __construct(
        private readonly int $status,
        string $message = '',
        private readonly array $headers = [],
        ?string $hint = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : self::defaultMessage($status), $hint, $status, $previous);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public static function defaultMessage(int $status): string
    {
        return match ($status) {
            400 => 'Requisição inválida',
            401 => 'Não autenticado',
            403 => 'Acesso negado',
            404 => 'Não encontrado',
            405 => 'Método não permitido',
            409 => 'Conflito',
            422 => 'Dados inválidos',
            429 => 'Muitas requisições',
            default => $status >= 500 ? 'Erro interno do servidor' : 'Erro na requisição',
        };
    }
}
