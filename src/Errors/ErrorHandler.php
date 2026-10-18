<?php

declare(strict_types=1);

namespace Haki\Errors;

use Haki\Exceptions\HakiException;
use Haki\Exceptions\HttpException;
use Haki\Http\Response;
use Throwable;

/**
 * Converte exceções em respostas JSON no formato padrão:
 *
 *     { "erro": "Rota não encontrada", "status": 404 }
 *
 * Em desenvolvimento, inclui "detalhes" (exceção, arquivo, linha, dica e trace).
 * Em produção, nunca expõe detalhes internos.
 */
final class ErrorHandler
{
    public function __construct(private readonly bool $debug = false)
    {
    }

    public function render(Throwable $e): Response
    {
        $status = $e instanceof HttpException ? $e->getStatus() : 500;
        $message = $e instanceof HttpException ? $e->getMessage() : HttpException::defaultMessage(500);

        if ($status >= 500) {
            $this->log($e);
        }

        $payload = ['erro' => $message, 'status' => $status];

        $details = $this->debug ? $this->details($e, $status >= 500) : [];

        if ($details !== []) {
            $payload['detalhes'] = $details;
        }

        $response = (new Response())->json($payload, $status);

        if ($e instanceof HttpException) {
            foreach ($e->getHeaders() as $name => $value) {
                $response->header($name, $value);
            }
        }

        return $response;
    }

    /**
     * Erros 4xx mostram só a dica; erros 500 mostram também arquivo, linha e trace.
     *
     * @return array<string, mixed>
     */
    private function details(Throwable $e, bool $withTrace): array
    {
        $details = [];

        if ($e instanceof HakiException && $e->getHint() !== null) {
            $details['dica'] = $e->getHint();
        }

        if (!$withTrace) {
            return $details;
        }

        $details['excecao'] = $e::class;
        $details['mensagem'] = $e->getMessage();
        $details['arquivo'] = $e->getFile();
        $details['linha'] = $e->getLine();
        $details['trace'] = array_slice(explode("\n", $e->getTraceAsString()), 0, 15);

        return $details;
    }

    private function log(Throwable $e): void
    {
        error_log(sprintf('[haki] %s: %s em %s:%d', $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));
    }
}
