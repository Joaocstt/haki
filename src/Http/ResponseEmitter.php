<?php

declare(strict_types=1);

namespace Haki\Http;

use LogicException;
use WeakMap;

/**
 * Envia a Response ao cliente (status, cabeçalhos e corpo), garantindo envio único.
 */
final class ResponseEmitter
{
    /** @var WeakMap<Response, true> */
    private WeakMap $sent;

    public function __construct()
    {
        $this->sent = new WeakMap();
    }

    public function emit(Response $response): void
    {
        if (isset($this->sent[$response])) {
            throw new LogicException('Esta resposta já foi enviada.');
        }

        $this->sent[$response] = true;

        if (!headers_sent()) {
            http_response_code($response->getStatus());

            foreach ($response->getHeaders() as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }

        if ($response->getStatus() !== 204 && $response->getStatus() !== 304) {
            echo $response->getBody();
        }
    }
}
