<?php

declare(strict_types=1);

namespace Haki\Http;

/**
 * Converte o retorno de um controller em Response.
 *
 *  - Response            → como está
 *  - null                → 204 sem corpo
 *  - string              → texto puro
 *  - array, objeto, etc. → JSON
 */
final class ResponseConverter
{
    public function convert(mixed $result): Response
    {
        return match (true) {
            $result instanceof Response => $result,
            $result === null => new Response('', 204),
            is_string($result) => (new Response())->text($result),
            default => (new Response())->json($result),
        };
    }
}
