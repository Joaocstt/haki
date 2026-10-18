<?php

declare(strict_types=1);

namespace Haki\Exceptions;

final class RouteNotFoundException extends HttpException
{
    /**
     * @param list<string> $suggestions rotas parecidas, exibidas em desenvolvimento
     */
    public function __construct(string $method, string $path, array $suggestions = [])
    {
        $hint = $suggestions === []
            ? 'Confira se a rota foi registrada, por exemplo: $app->' . strtolower($method) . "('" . $path . "', ...)."
            : 'Rotas parecidas: ' . implode(', ', $suggestions) . '. Você quis dizer alguma delas?';

        parent::__construct(404, 'Rota não encontrada', hint: "A rota {$method} {$path} não existe. {$hint}");
    }
}
