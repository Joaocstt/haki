<?php

declare(strict_types=1);

namespace Haki\Exceptions;

final class MethodNotAllowedException extends HttpException
{
    /**
     * @param list<string> $allowed métodos aceitos pelo caminho
     */
    public function __construct(string $method, string $path, array $allowed)
    {
        parent::__construct(
            405,
            'Método não permitido',
            ['Allow' => implode(', ', $allowed)],
            "O caminho {$path} existe, mas não aceita {$method}. Métodos aceitos: " . implode(', ', $allowed) . '.',
        );
    }
}
