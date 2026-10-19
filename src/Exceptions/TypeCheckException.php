<?php

declare(strict_types=1);

namespace Haki\Exceptions;

/**
 * Código sem tipagem, com $app->requireTypes() ativo.
 */
final class TypeCheckException extends HakiException
{
    /**
     * @param list<string> $problems
     */
    public function __construct(private readonly string $subject, private readonly array $problems)
    {
        $count = count($problems);

        parent::__construct(
            "Tipagem obrigatória: {$subject} tem " . ($count === 1 ? '1 problema' : "{$count} problemas") . '. ' . implode(' ', $problems),
            'Adicione os tipos indicados (ex.: function show(int $id): array). '
            . 'A exigência vem de $app->requireTypes() no bootstrap/app.php. Confira tudo de uma vez com: php vendor/bin/haki check',
        );
    }

    /**
     * @return list<string>
     */
    public function problems(): array
    {
        return $this->problems;
    }

    public function subject(): string
    {
        return $this->subject;
    }
}
