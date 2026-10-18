<?php

declare(strict_types=1);

namespace Haki\Validation;

use Haki\Exceptions\HttpException;

/**
 * 422: dados inválidos. Vira a resposta:
 *
 *     { "erro": "Dados inválidos", "status": 422, "campos": { "email": ["..."] } }
 */
final class ValidationException extends HttpException
{
    /**
     * @param array<string, list<string>> $errors mensagens por campo ("endereco.cep", "tags.1"...)
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct(422, 'Dados inválidos');
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
