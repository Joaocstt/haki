<?php

declare(strict_types=1);

namespace Haki\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Base de todas as exceções do Haki.
 *
 * A dica (hint) aparece apenas em modo de desenvolvimento e explica como resolver o problema.
 */
class HakiException extends RuntimeException
{
    public function __construct(
        string $message = '',
        private readonly ?string $hint = null,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getHint(): ?string
    {
        return $this->hint;
    }
}
